"""Скачивание страниц: обычный запрос с отпечатком Chrome (curl_cffi), а при блокировке настоящий браузер (Playwright).

Режимы источника (sources.fetch_method):
  http     только обычный запрос (быстро);
  browser  всегда настоящий браузер;
  auto     обычный запрос; если сайт блокирует, источник на 6 часов переходит на браузер.
"""
import asyncio
import base64
import hashlib
import json
import logging
import os
import random
import re
import time
from dataclasses import dataclass, field
from urllib.parse import urlparse

from curl_cffi import requests as creq

from . import config
from .util import html_to_text

log = logging.getLogger("fetcher")

MAX_BODY = 10 * 1024 * 1024
FEED_ACCEPT = "application/rss+xml, application/atom+xml, application/xml;q=0.9, text/xml;q=0.9, text/html;q=0.8, */*;q=0.7"
PAGE_ACCEPT = "text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8"
LANG = "hy-AM,hy;q=0.9,en-US;q=0.8,en;q=0.7"
CHALLENGE_TITLES = ("just a moment", "attention required", "checking your browser", "один момент", "access denied")
STEALTH = """
Object.defineProperty(navigator, 'webdriver', {get: () => undefined});
Object.defineProperty(navigator, 'languages', {get: () => ['hy-AM', 'hy', 'en-US', 'en']});
window.chrome = window.chrome || {runtime: {}};
"""


@dataclass
class Resp:
    url: str
    status: int = 0
    headers: dict = field(default_factory=dict)       # ключи в нижнем регистре
    body: bytes = b""
    final_url: str = ""
    via: str = "direct"                               # direct | browser
    ms: int = 0
    error: str = ""                                   # текст ошибки соединения
    errno: int = 0                                    # код curl
    cf: bool = False                                  # Cloudflare сообщил о проверке (cf-mitigated)
    browser_error: str = ""

    @property
    def ctype(self) -> str:
        return self.headers.get("content-type", "")

    def snippet(self, n: int = 300) -> str:
        try:
            return html_to_text(self.body[:20000].decode("utf-8", "replace"))[:n]
        except Exception:
            return ""


def classify(r: Resp) -> "tuple|None":
    """None если запрос удался (в том числе 304), иначе (event, message, details)."""
    details = f"URL: {r.url}\nFinal URL: {r.final_url or r.url}\nHTTP: {r.status}\nContent-Type: {r.ctype}\nDuration: {r.ms} ms\nFetched by: {r.via}"
    if r.via == "browser" and r.browser_error and not r.status:
        if "ECONNREFUSED" in r.browser_error or "not started" in r.browser_error:
            return ("browser_down", "The browser is not running or did not answer. Check the engine service and Playwright installation.", details)
        return ("browser_error", "Browser: " + r.browser_error, details)
    if r.error:
        if r.errno == 28 or "timed out" in r.error.lower():
            return ("timeout", "Timeout: the site did not answer in time. " + r.error, details)
        if r.errno in (6, 7, 52, 56):
            return ("connection", r.error, details)
        if r.errno in (35, 51, 58, 59, 60, 77):
            return ("ssl", r.error, details)
        return ("curl_error", r.error, details)
    if r.status == 304 or 0 < r.status < 400:
        return None
    snippet = r.snippet()
    details += "\n\nResponse start:\n" + snippet
    if r.status in (403, 429, 503) and (r.cf or b"challenges.cloudflare.com" in r.body[:200000] or "just a moment" in snippet.lower()):
        if r.via == "browser":
            return ("cloudflare_block", f"HTTP {r.status}: the Cloudflare challenge was not passed even by the real browser. Ask the site to allowlist the server IP, or use a proxy.", details)
        return ("cloudflare_block", f"HTTP {r.status}: blocked by a Cloudflare challenge. Plain requests cannot pass it: set 'How to fetch' to Auto or Real browser.", details)
    hint = {403: " (blocked by the site)", 429: " (too many requests)", 404: " (not found)", 503: " (site unavailable)", 444: " (connection dropped by the site's protection)"}.get(r.status, "")
    return ("http_error", f"HTTP {r.status}{hint}", details)


def is_block(f: "tuple|None", r: Resp) -> bool:
    """Похоже ли на блокировку, а не на обычную ошибку."""
    if not f:
        return False
    if f[0] == "cloudflare_block":
        return True
    if f[0] == "http_error" and r.status in (403, 429, 444, 503):
        return True
    return r.errno in (52, 56)


class BrowserPool:
    """Настоящий Chrome под Xvfb: постоянный профиль (cookie проходят проверку один раз), «прогрев» сайта, ожидание проверки Cloudflare."""

    def __init__(self):
        self.pw = None
        self.ctx = None
        self.proxy_ctx = {}
        self.sem = asyncio.Semaphore(int(config.BROWSER.get("pages", 3)))
        self.warm = {}
        self.ready = False
        self.requests = 0
        self.challenges = 0
        self.solved = 0
        self.errors = 0

    async def start(self):
        try:
            from playwright.async_api import async_playwright
        except Exception as e:                      # Playwright не установлен: движок работает без браузера
            log.warning("Playwright is not available: %s", e)
            return
        try:
            self.pw = await async_playwright().start()
            self.ctx = await self._launch(None)
            self.ready = True
            log.info("browser started")
        except Exception as e:
            log.error("browser failed to start: %s", e)

    async def stop(self):
        for c in [self.ctx, *self.proxy_ctx.values()]:
            try:
                if c:
                    await c.close()
            except Exception:
                pass
        if self.pw:
            await self.pw.stop()

    async def _launch(self, proxy):
        b = config.BROWSER
        d = b.get("profile") or "/tmp/mmengine-profile"
        args = ["--disable-blink-features=AutomationControlled", "--no-default-browser-check", "--disable-dev-shm-usage", "--disable-gpu"]
        if os.geteuid() == 0:
            args.append("--no-sandbox")
        kw = {}
        if proxy:
            u = urlparse(proxy)
            conf = {"server": f"{u.scheme}://{u.hostname}:{u.port}"}
            if u.username:
                conf.update(username=u.username, password=u.password or "")
            kw["proxy"] = conf
            args.append("--proxy-bypass-list=<-loopback>")
            d = d + "-proxy-" + hashlib.sha1(proxy.encode()).hexdigest()[:10]
        os.makedirs(d, exist_ok=True)
        c = await self.pw.chromium.launch_persistent_context(
            d, headless=bool(b.get("headless", False)), args=args, executable_path=b.get("executable") or None,
            locale="hy-AM", timezone_id="Asia/Yerevan", viewport={"width": 1366, "height": 768},
            extra_http_headers={"Accept-Language": LANG}, **kw)
        await c.add_init_script(STEALTH)
        return c

    async def _ctx_for(self, proxy):
        if not proxy:
            return self.ctx
        if proxy not in self.proxy_ctx:
            self.proxy_ctx[proxy] = await self._launch(proxy)
        return self.proxy_ctx[proxy]

    async def _is_challenge(self, page, status, headers) -> bool:
        if (headers or {}).get("cf-mitigated", "").lower() == "challenge":
            return True
        try:
            title = (await page.title() or "").lower()
        except Exception:
            title = ""
        return any(t in title for t in CHALLENGE_TITLES) and status in (200, 202, 403, 429, 503)

    async def _click_turnstile(self, page):
        try:
            el = await page.query_selector('iframe[src*="challenges.cloudflare.com"]')
            box = await el.bounding_box() if el else None
            if not box:
                return
            x, y = box["x"] + 28 + random.random() * 6, box["y"] + box["height"] / 2 + random.random() * 4
            await page.mouse.move(x - 120, y - 60, steps=12)
            await asyncio.sleep(0.2 + random.random() * 0.3)
            await page.mouse.move(x, y, steps=18)
            await asyncio.sleep(0.15 + random.random() * 0.2)
            await page.mouse.click(x, y)
        except Exception:
            pass

    async def _solve(self, page, deadline) -> bool:
        clicked = False
        started = time.time()
        while time.time() < deadline:
            await asyncio.sleep(1.0)
            try:
                title = (await page.title() or "").lower()
            except Exception:
                continue                                # страница перезагружается после прохождения проверки
            if not any(t in title for t in CHALLENGE_TITLES):
                return True
            if not clicked and time.time() - started > 6:
                clicked = True
                await self._click_turnstile(page)
        return False

    async def _warm_up(self, page, url, deadline):
        """Как живой посетитель: главная сайта, пауза, прокрутка; потом нужный адрес с реферером."""
        u = urlparse(url)
        if not u.netloc or time.time() - self.warm.get(u.netloc, 0) < 1200 or (u.path in ("", "/") and not u.query):
            return None
        self.warm[u.netloc] = time.time()
        origin = f"{u.scheme}://{u.netloc}/"
        try:
            resp = await page.goto(origin, wait_until="domcontentloaded", timeout=max(8000, int((deadline - time.time()) * 400)))
            if await self._is_challenge(page, resp.status if resp else 0, resp.headers if resp else {}):
                await self._solve(page, min(deadline - 10, time.time() + 25))
            await asyncio.sleep(1.0 + random.random() * 1.5)
            await page.mouse.move(300 + random.random() * 400, 200 + random.random() * 200, steps=10)
            await page.mouse.wheel(0, 400 + random.random() * 500)
            await asyncio.sleep(0.6 + random.random())
            return origin
        except Exception:
            return None

    async def _raw_bytes(self, page, url):
        """Точные байты ответа: Chromium для текстовых типов отдаёт уже расшифрованный текст, и кодировка ломается."""
        try:
            b64 = await page.evaluate(
                """async (u) => { const r = await fetch(u, {credentials: 'include', cache: 'no-store'});
                   const a = new Uint8Array(await r.arrayBuffer()); let s = ''; const k = 0x8000;
                   for (let i = 0; i < a.length; i += k) s += String.fromCharCode.apply(null, a.subarray(i, i + k));
                   return btoa(s); }""", url)
            return base64.b64decode(b64)
        except Exception:
            return None

    @staticmethod
    def _to_utf8(raw: bytes, ctype: str) -> bytes:
        enc = None
        m = re.search(r"charset=([\w-]+)", ctype or "", re.I)
        if m:
            enc = m.group(1)
        elif raw[:3] == b"\xef\xbb\xbf":
            enc = "utf-8-sig"
        else:
            m2 = re.match(rb"\s*<\?xml[^>]*encoding=[\"']([\w-]+)[\"']", raw[:200], re.I)
            if m2:
                enc = m2.group(1).decode("ascii", "ignore")
        try:
            text = raw.decode(enc or "utf-8", errors="replace")
        except LookupError:
            text = raw.decode("utf-8", errors="replace")
        text = re.sub(r"^(\s*<\?xml[^>]*encoding=[\"'])[\w-]+([\"'])", r"\1UTF-8\2", text, count=1, flags=re.I)
        return text.encode("utf-8")

    async def fetch(self, url: str, proxy=None, timeout: int = 50) -> Resp:
        r = Resp(url=url, via="browser")
        if not self.ready:
            r.browser_error = "browser not started"
            return r
        t0 = time.time()
        async with self.sem:
            self.requests += 1
            deadline = time.time() + timeout
            try:
                page = await (await self._ctx_for(proxy)).new_page()
            except Exception as e:
                r.browser_error = str(e).splitlines()[0][:240]
                return r
            try:
                await asyncio.sleep(0.2 + random.random() * 0.6)
                ref = await self._warm_up(page, url, deadline)
                resp = await page.goto(url, wait_until="domcontentloaded", timeout=max(8000, int((deadline - time.time()) * 1000)), referer=ref)
                headers = dict(resp.headers) if resp else {}
                challenged = await self._is_challenge(page, resp.status if resp else 0, headers)
                if challenged:
                    self.challenges += 1
                    if await self._solve(page, deadline - 3):
                        self.solved += 1
                        resp = await page.goto(url, wait_until="domcontentloaded", timeout=max(5000, int((deadline - time.time()) * 1000)))
                        headers = dict(resp.headers) if resp else {}
                        challenged = await self._is_challenge(page, resp.status if resp else 0, headers)
                ctype = headers.get("content-type", "text/html")
                low = ctype.lower()
                body = b""
                if resp is not None:
                    if "html" not in low and ("xml" in low or "json" in low or low.startswith("text/")):
                        raw = None if challenged else await self._raw_bytes(page, url)
                        body = self._to_utf8(raw, ctype) if raw is not None else await resp.body()
                    else:
                        body = await resp.body()
                    if "html" in low or "xml" in low or "json" in low or low.startswith("text/"):
                        ctype = re.sub(r";\s*charset=[^;]*", "", ctype).strip() + "; charset=utf-8"
                headers["content-type"] = ctype
                if challenged:
                    headers["cf-mitigated"] = "challenge"
                r.status = resp.status if resp else 502
                r.headers = {k.lower(): v for k, v in headers.items()}
                r.body = body[:MAX_BODY]
                r.final_url = page.url
                r.cf = bool(challenged)
            except Exception as e:
                self.errors += 1
                r.browser_error = (str(e).splitlines() or ["browser error"])[0][:240]
            finally:
                r.ms = int((time.time() - t0) * 1000)
                try:
                    await page.close()
                except Exception:
                    pass
        return r


class Fetcher:
    def __init__(self, db=None):
        self.db = db
        self.session = None
        self.browser = BrowserPool()
        self._lock = asyncio.Lock()
        self._next = {}

    async def start(self):
        self.session = creq.AsyncSession()
        await self.browser.start()

    async def stop(self):
        if self.session:
            await self.session.close()
        await self.browser.stop()

    async def _slot(self, kind: str, host: str):
        """Вежливость: не чаще 4 запросов в секунду к одному хосту для лент и 1 в секунду для страниц."""
        gap = 0.25 if kind == "feed" else 1.0
        async with self._lock:
            now = time.monotonic()
            t = max(now, self._next.get((kind, host), 0))
            self._next[(kind, host)] = t + gap
        if t > now:
            await asyncio.sleep(t - now)

    async def _direct(self, url: str, src: dict, kind: str, etag=None, lastmod=None, timeout: int = 20) -> Resp:
        r = Resp(url=url)
        headers = {"Accept": FEED_ACCEPT if kind == "feed" else PAGE_ACCEPT, "Accept-Language": LANG}
        if etag:
            headers["If-None-Match"] = etag
        if lastmod:
            headers["If-Modified-Since"] = lastmod
        kw = {"headers": headers, "timeout": timeout, "allow_redirects": True, "max_redirects": 5}
        if (src.get("fetch_profile") or "browser") == "browser":
            kw["impersonate"] = "chrome"             # TLS и HTTP/2 как у настоящего Chrome
        else:
            headers["User-Agent"] = config.BOT_UA
        if src.get("proxy_url"):
            kw["proxy"] = src["proxy_url"]
        t0 = time.time()
        try:
            resp = await self.session.get(url, **kw)
            r.status = resp.status_code
            r.headers = {k.lower(): v for k, v in resp.headers.items()}
            r.body = resp.content[:MAX_BODY]
            r.final_url = str(resp.url)
            r.cf = r.headers.get("cf-mitigated", "").lower() == "challenge"
        except Exception as e:
            r.error = str(e).splitlines()[0][:300] if str(e) else e.__class__.__name__
            r.errno = int(getattr(e, "code", 0) or 0)
            if not r.errno and "timed out" in r.error.lower():
                r.errno = 28
        r.ms = int((time.time() - t0) * 1000)
        return r

    @staticmethod
    def browser_active(src: dict) -> bool:
        m = src.get("fetch_method") or "auto"
        if m == "browser":
            return True
        if m != "auto":
            return False
        try:
            st = json.loads(src.get("state") or "{}") if isinstance(src.get("state"), (str, bytes)) else (src.get("state") or {})
        except Exception:
            st = {}
        return int(st.get("browser_until", 0)) > time.time()

    async def mark_browser(self, src: dict):
        """Сайт блокирует обычные запросы: следующие 6 часов этот источник ходит через браузер."""
        until = int(time.time()) + 6 * 3600
        try:
            st = json.loads(src.get("state") or "{}") if isinstance(src.get("state"), (str, bytes)) else dict(src.get("state") or {})
        except Exception:
            st = {}
        st["browser_until"] = until
        src["state"] = json.dumps(st)
        if self.db and src.get("id"):
            await self.db.run("UPDATE sources SET state = JSON_SET(COALESCE(state, '{}'), '$.browser_until', %s) WHERE id = %s", (until, src["id"]))

    async def fetch(self, url: str, src: dict, kind: str = "feed", force: bool = False, etag=None, lastmod=None, timeout=None) -> Resp:
        """Скачать адрес способом источника. Возвращает Resp (разбор неудачи: classify(resp))."""
        host = (urlparse(url).hostname or "").lower()
        await self._slot(kind, host)
        method = src.get("fetch_method") or "auto"
        cond = {} if force else {"etag": etag, "lastmod": lastmod}
        if not self.browser_active(src):
            r = await self._direct(url, src, kind, timeout=timeout or 20, **cond)
            if method != "auto" or not is_block(classify(r), r):
                return r
            await self.mark_browser(src)
            log.info("blocked, switching %s to the real browser", host)
        return await self.browser.fetch(url, src.get("proxy_url"), timeout=int(src.get("browser_timeout") or 50))
