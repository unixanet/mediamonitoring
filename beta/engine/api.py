"""Локальный программный интерфейс для PHP-админки (только 127.0.0.1)."""
import asyncio
import json
import logging

from aiohttp import web

from . import config
from .detect import detect
from .extract import extract_article
from .fetcher import classify
from .util import decode_body, fmt_local

log = logging.getLogger("api")


def make_app(engine) -> web.Application:
    routes = web.RouteTableDef()

    def jr(data, status=200):
        return web.Response(text=json.dumps(data, ensure_ascii=False, default=str), status=status, content_type="application/json")

    async def body(request) -> dict:
        try:
            return await request.json()
        except Exception:
            return {}

    @routes.get("/health")
    async def health(request):
        return jr(await engine.health())

    detect_gate = asyncio.Semaphore(3)

    @routes.post("/detect")
    async def detect_ep(request):
        d = await body(request)
        method = d.get("method") if d.get("method") in ("auto", "http", "browser") else "auto"
        # не больше 3 проверок одновременно и не дольше 40 секунд: форма добавления источника не должна забивать сервер
        try:
            async with detect_gate:
                return jr(await asyncio.wait_for(detect(engine, d.get("input", ""), method, "bot" if d.get("profile") == "bot" else "browser", d.get("tz") or "Asia/Yerevan"), 40))
        except asyncio.TimeoutError:
            return jr({"ok": False, "error": "The site did not answer in time. Try again or choose the real browser method in More settings.", "can_try_browser": True, "candidates": []})

    @routes.post("/source/{id}/fetch")
    async def fetch_now(request):
        """Fetch now / Start / первый забег: опросить источник и сразу открыть ожидающие страницы."""
        sid = int(request.match_info["id"])
        src = await engine.db.one("SELECT * FROM sources WHERE id = %s", (sid,))
        if not src:
            return jr({"ok": False, "error": "Source not found."}, 404)
        res = await engine.process_source(src, force=True)
        if res["ok"] and src.get("fetch_full_text"):
            res["ft"] = await engine.process_pending(sid, 20)
        return jr(res)

    @routes.post("/source/{id}/retry")
    async def retry(request):
        sid = int(request.match_info["id"])
        n = await engine.reset_source(sid)
        return jr({"ok": True, "reset": n, "ft": await engine.process_pending(sid, 20)})

    @routes.post("/article/{id}/retry")
    async def retry_article(request):
        return jr(await engine.retry_article(int(request.match_info["id"])))

    @routes.post("/extract-test")
    async def extract_test(request):
        d = await body(request)
        url = (d.get("url") or "").strip()
        if not url.startswith(("http://", "https://")):
            return jr({"ok": False, "error": "Enter a valid article URL"})
        src = {"id": 0, "fetch_method": d.get("method") or "auto", "fetch_profile": "bot" if d.get("profile") == "bot" else "browser", "proxy_url": d.get("proxy") or None, "state": None}
        r = await engine.fetcher.fetch(url, src, "page", force=True)
        f = classify(r)
        if f:
            return jr({"ok": False, "error": f[1]})
        tz = d.get("tz") or "Asia/Yerevan"
        ex = extract_article(decode_body(r.body, r.ctype), url, {"sel_title": d.get("sel_title") or "", "sel_body": d.get("sel_body") or "", "tz": tz}, r.final_url)
        return jr({"ok": bool(ex["text"]), "error": "No article text found on the page", "title": ex["title"] or "(no title found)",
                   "date": fmt_local(ex["ts"], tz) if ex["ts"] else "date not found (download time is used)", "length": len(ex["text"]),
                   "text": ex["text"][:900] + ("…" if len(ex["text"]) > 900 else ""), "extractor": ex["extractor"], "original_url": ex["canonical"] or r.final_url or url})

    app = web.Application(client_max_size=8 * 1024 * 1024)
    app.add_routes(routes)
    return app
