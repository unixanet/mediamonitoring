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
import os
import random
import time

from aiohttp import web
from playwright.async_api import async_playwright

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


class Browser:
    def __init__(self):
        self.pw = None
        self.ctx = None
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

    async def fetch(self, url, timeout):
        await self.recycle_if_needed()
        async with self.sem:
            self.requests += 1
            self.since_recycle += 1
            deadline = time.time() + timeout
            page = await self.ctx.new_page()
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
                body = await resp.body() if resp else b""
                if len(body) > MAX_BODY:
                    body = body[:MAX_BODY]
                headers = resp.headers if resp else {}
                return {
                    "status": resp.status if resp else 502,
                    "ctype": headers.get("content-type", "text/html; charset=utf-8"),
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
    try:
        r = await asyncio.wait_for(B.fetch(url, timeout), timeout + 15)
    except Exception as e:      # таймаут, падение страницы, DNS и т.п.
        B.errors += 1
        msg = (str(e).splitlines() or ["browser error"])[0][:240]
        return web.Response(status=504, text=msg, headers={"X-Browser-Error": msg})
    h = {"Content-Type": r["ctype"], "X-Final-Url": r["final"], "X-Fetched-By": "browser"}
    if r["challenge"]:
        h["cf-mitigated"] = "challenge"
    return web.Response(status=r["status"], body=r["body"], headers=h)


async def handle_health(request):
    return web.json_response({
        "ok": True, "uptime_s": int(time.time() - B.started), "requests": B.requests, "challenges": B.challenges,
        "challenges_solved": B.solved, "errors": B.errors, "pages_open": len(B.ctx.pages) if B.ctx else 0, "max_pages": MAX_PAGES,
    })


async def on_start(app):
    await B.start()


async def on_stop(app):
    if B.ctx:
        await B.ctx.close()
    if B.pw:
        await B.pw.stop()


def main():
    app = web.Application()
    app.add_routes([web.get("/get", handle_get), web.get("/health", handle_health)])
    app.on_startup.append(on_start)
    app.on_cleanup.append(on_stop)
    web.run_app(app, host="127.0.0.1", port=PORT, print=lambda *_: None)


if __name__ == "__main__":
    main()
