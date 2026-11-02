#!/usr/bin/env python3
"""Сервис настоящего браузера для краулера (Playwright + Chromium).

Слушает только 127.0.0.1. Краулер (PHP) обращается так:
    GET http://127.0.0.1:8765/get?url=<url>&timeout=50
и получает в ответ тело целевой страницы с её HTTP-статусом и Content-Type, как будто запросил сам.
Служебные заголовки ответа: X-Final-Url, X-Fetched-By: browser, cf-mitigated: challenge (если проверка не пройдена),
X-Browser-Error (если сам браузер упал или не дождался).

Браузер запускается с видимым окном внутри Xvfb (xvfb-run), с постоянным профилем (cookies сохраняются:
после прохождения проверки Cloudflare следующие запросы идут быстро), языком hy-AM и поясом Asia/Yerevan.
Проверку Cloudflare ("Just a moment...") сервис ждёт до ~30 секунд и при необходимости кликает по чекбоксу Turnstile.
"""
import asyncio
import base64
import hashlib
import os
import random
import re
import time

from urllib.parse import urlparse
from aiohttp import web
from playwright.async_api import async_playwright

try:
    import trafilatura
except Exception:       # необязательная зависимость: без неё /extract отвечает 501, краулер использует свой разбор
    trafilatura = None

PORT = int(os.environ.get("MM_BROWSER_PORT", "8765"))
PROFILE = os.environ.get("MM_BROWSER_PROFILE", "/var/lib/mmbrowser/profile")
MAX_PAGES = int(os.environ.get("MM_BROWSER_PAGES", "3"))
HEADLESS = os.environ.get("MM_BROWSER_HEADLESS", "0") == "1"
EXE = os.environ.get("MM_BROWSER_EXE") or None                       # путь к chrome, если не стандартный
RECYCLE_AFTER = int(os.environ.get("MM_BROWSER_RECYCLE", "400"))   # пересоздаём контекст, чтобы не копилась память
MAX_BODY = 20 * 1024 * 1024
CHALLENGE_TITLES = ("just a moment", "attention required", "checking your browser", "один момент", "access denied")

STEALTH = """
Object.defineProperty(navigator, 'webdriver', {get: () => undefined});
Object.defineProperty(navigator, 'languages', {get: () => ['hy-AM', 'hy', 'en-US', 'en']});
window.chrome = window.chrome || {runtime: {}};
"""


def to_utf8(raw: bytes, ctype: str) -> bytes:
    """Сырые байты ответа в чистый UTF-8: кодировка из заголовка, BOM или XML-декларации, иначе UTF-8."""
    enc = None
    m = re.search(r"charset=([\w-]+)", ctype or "", re.I)
    if m:
        enc = m.group(1)
    elif raw[:3] == b"\xef\xbb\xbf":
        enc = "utf-8-sig"
    else:
        m = re.match(rb"\s*<\?xml[^>]*encoding=[\"']([\w-]+)[\"']", raw[:200], re.I)
        if m:
            enc = m.group(1).decode("ascii", "ignore")
    try:
        text = raw.decode(enc or "utf-8", errors="replace")
    except LookupError:
        text = raw.decode("utf-8", errors="replace")
    text = re.sub(r"^(\s*<\?xml[^>]*encoding=[\"'])[\w-]+([\"'])", r"\1UTF-8\2", text, count=1, flags=re.I)
    return text.encode("utf-8")


def with_utf8(ctype: str) -> str:
    base = re.sub(r";\s*charset=[^;]*", "", ctype or "text/html").strip()
    return base + "; charset=utf-8"


class Browser:
    def __init__(self):
        self.pw = None
        self.ctx = None
        self.proxy_ctx = {}       # прокси -> отдельный контекст (свои cookie и профиль)
        self.sem = asyncio.Semaphore(MAX_PAGES)
        self.lock = asyncio.Lock()
        self.started = time.time()
        self.requests = 0
        self.since_recycle = 0
        self.challenges = 0
        self.solved = 0
        self.errors = 0
        self.warm = {}     # host -> время последнего «захода на главную»

    async def start(self):
        self.pw = await async_playwright().start()
        await self._launch()

    async def ctx_for(self, proxy):
        if not proxy:
            return self.ctx
        if proxy not in self.proxy_ctx:
            u = urlparse(proxy)
            conf = {"server": f"{u.scheme}://{u.hostname}:{u.port}"}
            if u.username:
                conf["username"] = u.username
                conf["password"] = u.password or ""
            args = ["--disable-blink-features=AutomationControlled", "--no-default-browser-check", "--disable-dev-shm-usage", "--disable-gpu", "--proxy-bypass-list=<-loopback>"]
            if os.geteuid() == 0:
                args.append("--no-sandbox")
            d = f"{PROFILE}-proxy-" + hashlib.sha1(proxy.encode()).hexdigest()[:10]
            os.makedirs(d, exist_ok=True)
            c = await self.pw.chromium.launch_persistent_context(
                d, headless=HEADLESS, args=args, executable_path=EXE, locale="hy-AM", timezone_id="Asia/Yerevan",
                viewport={"width": 1366, "height": 768}, proxy=conf,
                extra_http_headers={"Accept-Language": "hy-AM,hy;q=0.9,en-US;q=0.8,en;q=0.7"})
            await c.add_init_script(STEALTH)
            self.proxy_ctx[proxy] = c
        return self.proxy_ctx[proxy]

    async def _launch(self):
        args = ["--disable-blink-features=AutomationControlled", "--no-default-browser-check", "--disable-dev-shm-usage", "--disable-gpu"]
        if os.geteuid() == 0:
            args.append("--no-sandbox")
        os.makedirs(PROFILE, exist_ok=True)
        self.ctx = await self.pw.chromium.launch_persistent_context(
            PROFILE, headless=HEADLESS, args=args, executable_path=EXE, locale="hy-AM", timezone_id="Asia/Yerevan",
            viewport={"width": 1366, "height": 768}, accept_downloads=False,
            extra_http_headers={"Accept-Language": "hy-AM,hy;q=0.9,en-US;q=0.8,en;q=0.7"},
        )
        await self.ctx.add_init_script(STEALTH)

    async def recycle_if_needed(self):
        if self.since_recycle < RECYCLE_AFTER:
            return
        async with self.lock:
            if self.since_recycle < RECYCLE_AFTER:
                return
            # ждём, пока все страницы закроются (держим все слоты)
            for _ in range(MAX_PAGES):
                await self.sem.acquire()
            try:
                await self.ctx.close()
                await self._launch()
                self.since_recycle = 0
            finally:
                for _ in range(MAX_PAGES):
                    self.sem.release()

    async def is_challenge(self, page, resp):
        try:
            title = (await page.title() or "").lower()
        except Exception:
            title = ""
        status = resp.status if resp else 0
        hdr = (resp.headers if resp else {}) or {}
        if hdr.get("cf-mitigated", "").lower() == "challenge":
            return True
        if any(t in title for t in CHALLENGE_TITLES) and status in (200, 202, 403, 429, 503):
            return True
        return False

    async def raw_bytes(self, page, url):
        """Точные байты ответа (Chromium для текстовых типов отдаёт уже расшифрованный текст, из-за чего ломается кодировка)."""
        try:
            b64 = await page.evaluate(
                """async (u) => { const r = await fetch(u, {credentials: 'include', cache: 'no-store'});
                   const a = new Uint8Array(await r.arrayBuffer()); let s = ''; const k = 0x8000;
                   for (let i = 0; i < a.length; i += k) s += String.fromCharCode.apply(null, a.subarray(i, i + k));
                   return btoa(s); }""", url)
            return base64.b64decode(b64)
        except Exception:
            return None

    async def human_click_turnstile(self, page):
        try:
            frame_el = await page.query_selector('iframe[src*="challenges.cloudflare.com"]')
            if not frame_el:
                return
            box = await frame_el.bounding_box()
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

    async def solve_challenge(self, page, deadline):
        clicked = False
        started = time.time()
        while time.time() < deadline:
            await asyncio.sleep(1.0)
            try:
                title = (await page.title() or "").lower()
            except Exception:
                title = ""      # страница перезагружается после прохождения проверки
                continue
            if not any(t in title for t in CHALLENGE_TITLES):
                return True
            if not clicked and time.time() - started > 6:
                clicked = True
                await self.human_click_turnstile(page)
        return False

    async def warm_up(self, page, url, deadline):
        """Как живой посетитель: сначала главная страница сайта, пауза, лёгкая прокрутка; потом нужный адрес."""
        from urllib.parse import urlparse
        u = urlparse(url)
        host = u.netloc
        if not host or time.time() - self.warm.get(host, 0) < 1200 or (u.path in ("", "/") and not u.query):
            return None
        self.warm[host] = time.time()
        origin = f"{u.scheme}://{host}/"
        try:
            await page.goto(origin, wait_until="domcontentloaded", timeout=max(8000, int((deadline - time.time()) * 400)))
            if await self.is_challenge(page, None):
                await self.solve_challenge(page, min(deadline - 10, time.time() + 25))
            await asyncio.sleep(1.0 + random.random() * 1.5)
            await page.mouse.move(300 + random.random() * 400, 200 + random.random() * 200, steps=10)
            await page.mouse.wheel(0, 400 + random.random() * 500)
            await asyncio.sleep(0.6 + random.random())
            return origin
        except Exception:
            return None

    async def fetch(self, url, timeout, proxy=None):
        await self.recycle_if_needed()
        async with self.sem:
            self.requests += 1
            self.since_recycle += 1
            deadline = time.time() + timeout
            page = await (await self.ctx_for(proxy)).new_page()
            try:
                await asyncio.sleep(0.2 + random.random() * 0.6)          # человеческая пауза
                ref = await self.warm_up(page, url, deadline)
                resp = await page.goto(url, wait_until="domcontentloaded", timeout=max(8000, int((deadline - time.time()) * 1000)), referer=ref)
                challenged = await self.is_challenge(page, resp)
                if challenged:
                    self.challenges += 1
                    if await self.solve_challenge(page, deadline - 3):
                        self.solved += 1
                        resp = await page.goto(url, wait_until="domcontentloaded", timeout=max(5000, int((deadline - time.time()) * 1000)))
                        challenged = await self.is_challenge(page, resp)
                headers = resp.headers if resp else {}
                ctype = headers.get("content-type", "text/html")
                low = ctype.lower()
                body = b""
                if resp is not None:
                    if "html" not in low and ("xml" in low or "json" in low or low.startswith("text/")):
                        raw = None if challenged else await self.raw_bytes(page, url)
                        body = to_utf8(raw, ctype) if raw is not None else await resp.body()
                        ctype = with_utf8(ctype)
                    elif "html" in low:
                        body = await resp.body()          # Playwright уже отдаёт HTML как UTF-8
                        ctype = with_utf8(ctype)
                    else:
                        body = await resp.body()
                if len(body) > MAX_BODY:
                    body = body[:MAX_BODY]
                return {
                    "status": resp.status if resp else 502,
                    "ctype": ctype,
                    "body": body,
                    "final": page.url,
                    "challenge": challenged,
                }
            finally:
                await page.close()


B = Browser()


async def handle_get(request):
    url = request.query.get("url", "")
    if not url.startswith(("http://", "https://")):
        return web.Response(status=400, text="url must be http(s)", headers={"X-Browser-Error": "bad url"})
    timeout = max(10, min(90, int(request.query.get("timeout", "50"))))
    proxy = request.query.get("proxy") or None
    if proxy and not re.match(r"^https?://", proxy):
        return web.Response(status=400, text="bad proxy", headers={"X-Browser-Error": "bad proxy"})
    try:
        r = await asyncio.wait_for(B.fetch(url, timeout, proxy), timeout + 15)
    except Exception as e:      # таймаут, падение страницы, DNS и т.п.
        B.errors += 1
        msg = (str(e).splitlines() or ["browser error"])[0][:240]
        return web.Response(status=504, text=msg, headers={"X-Browser-Error": msg})
    h = {"Content-Type": r["ctype"], "X-Final-Url": r["final"], "X-Fetched-By": "browser"}
    if r["challenge"]:
        h["cf-mitigated"] = "challenge"
    return web.Response(status=r["status"], body=r["body"], headers=h)


async def handle_extract(request):
    """POST {html, url}: текст статьи через trafilatura (лучшие результаты на бенчмарках среди открытых экстракторов)."""
    if trafilatura is None:
        return web.json_response({"ok": False, "error": "trafilatura is not installed"}, status=501)
    try:
        data = await request.json()
        html = data.get("html") or ""
        url = data.get("url") or None
    except Exception:
        return web.json_response({"ok": False, "error": "bad request"}, status=400)

    def work():
        out = trafilatura.extract(html, url=url, include_comments=False, include_tables=False, favor_recall=True, output_format="json", with_metadata=True)
        return out

    try:
        import json as _json
        raw = await asyncio.wait_for(asyncio.to_thread(work), 20)
        j = _json.loads(raw) if raw else {}
    except Exception as e:
        return web.json_response({"ok": False, "error": str(e)[:200]}, status=500)
    return web.json_response({"ok": bool(j.get("text")), "text": j.get("text", ""), "title": j.get("title", ""), "date": j.get("date", "")})


async def handle_health(request):
    return web.json_response({
        "ok": True, "uptime_s": int(time.time() - B.started), "requests": B.requests, "challenges": B.challenges,
        "challenges_solved": B.solved, "errors": B.errors, "trafilatura": trafilatura is not None, "pages_open": len(B.ctx.pages) if B.ctx else 0, "max_pages": MAX_PAGES,
    })


async def on_start(app):
    await B.start()


async def on_stop(app):
    if B.ctx:
        await B.ctx.close()
    if B.pw:
        await B.pw.stop()


def main():
    app = web.Application(client_max_size=8 * 1024 * 1024)
    app.add_routes([web.get("/get", handle_get), web.post("/extract", handle_extract), web.get("/health", handle_health)])
    app.on_startup.append(on_start)
    app.on_cleanup.append(on_stop)
    web.run_app(app, host="127.0.0.1", port=PORT, print=lambda *_: None)


if __name__ == "__main__":
    main()
