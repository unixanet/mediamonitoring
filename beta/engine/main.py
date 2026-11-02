"""Запуск движка: опрос источников, воркеры страниц, обслуживание и API в одном процессе (asyncio)."""
import asyncio
import logging
import os
import signal
import socket

from aiohttp import web

from . import config
from .api import make_app
from .core import Engine

log = logging.getLogger("main")
POLL_TYPES = "'rss','sitemap','listing','telegram','facebook'"


def overloaded() -> "str|None":
    try:
        if os.getloadavg()[0] > 5.0:
            return f"server load {os.getloadavg()[0]:.1f}"
    except OSError:
        pass
    return None


async def poll_loop(e: Engine):
    tasks = set()
    while True:
        try:
            why = overloaded()
            if why:
                log.warning("paused: %s", why)
                await asyncio.sleep(5)
                continue
            due = await e.db.all(f"SELECT * FROM sources WHERE is_active = 1 AND type IN ({POLL_TYPES}) AND (next_check_at IS NULL OR next_check_at <= NOW()) ORDER BY next_check_at LIMIT 60")
            if not due:
                await asyncio.sleep(1)
                continue
            ids = [s["id"] for s in due]
            await e.db.run("UPDATE sources SET next_check_at = NOW() + INTERVAL 90 SECOND WHERE id IN (" + ",".join(["%s"] * len(ids)) + ")", ids)   # занять
            for s in due:
                t = asyncio.create_task(e.process_source(s))
                tasks.add(t)
                t.add_done_callback(tasks.discard)
            await asyncio.sleep(1)
        except Exception:
            log.exception("poll loop")
            await asyncio.sleep(5)


async def page_loop(e: Engine):
    while True:
        try:
            if overloaded():
                await asyncio.sleep(5)
                continue
            jobs = await e.collect_jobs(30)
            if not jobs:
                await asyncio.sleep(2)
                continue
            t0 = asyncio.get_event_loop().time()
            st = await e.run_jobs(jobs)
            log.info("pages: %s complete, %s failed, %s gave up, %s from lists (%.1f s)", st["full"], st["failed"], st["partial"], st["inserted"], asyncio.get_event_loop().time() - t0)
        except Exception:
            log.exception("page loop")
            await asyncio.sleep(5)


def sd_notify(msg: str) -> None:
    """Сообщить systemd, что движок жив (watchdog). Без systemd ничего не делает."""
    addr = os.environ.get("NOTIFY_SOCKET")
    if not addr:
        return
    try:
        with socket.socket(socket.AF_UNIX, socket.SOCK_DGRAM) as sk:
            sk.connect("\0" + addr[1:] if addr[0] == "@" else addr)
            sk.sendall(msg.encode())
    except OSError:
        pass


async def maint_loop(e: Engine):
    last = {"stats": 0, "journal": 0, "purge": 0}
    loop = asyncio.get_event_loop()
    while True:
        try:
            await e.heartbeat()
            sd_notify("WATCHDOG=1")          # пульс записан в БД: движок и БД живы. Нет пульса 2 минуты: systemd перезапустит движок
            now = loop.time()
            if now - last["journal"] >= 60:
                last["journal"] = now
                await e.journal_pending()
            if now - last["stats"] >= 600:
                last["stats"] = now
                await e.source_stats()
            if now - last["purge"] >= 3600:
                last["purge"] = now
                await e.purge()
        except Exception:
            log.exception("maintenance")
        await asyncio.sleep(5)


async def run_service():
    e = Engine()
    await e.start()
    runner = web.AppRunner(make_app(e))
    await runner.setup()
    await web.TCPSite(runner, "127.0.0.1", config.API_PORT).start()
    log.info("engine started, api on 127.0.0.1:%s", config.API_PORT)
    tasks = [asyncio.create_task(f(e)) for f in (poll_loop, page_loop, maint_loop)]
    stop = asyncio.Event()
    loop = asyncio.get_running_loop()
    for sig in (signal.SIGTERM, signal.SIGINT):
        loop.add_signal_handler(sig, stop.set)
    await stop.wait()
    for t in tasks:
        t.cancel()
    await runner.cleanup()
    await e.stop()
