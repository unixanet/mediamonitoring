"""python3 -m engine run | detect <адрес> [auto|http|browser] [--json] | check"""
import asyncio
import json
import logging
import sys

from . import config


def setup_logging():
    logging.basicConfig(level=getattr(logging, str(config.CFG.get("log_level", "INFO")).upper(), logging.INFO),
                        format="%(asctime)s %(levelname)s %(name)s: %(message)s", datefmt="%Y-%m-%d %H:%M:%S")


async def cmd_detect(args):
    from .core import Engine
    from .detect import detect
    as_json = "--json" in args
    args = [a for a in args if a != "--json"]
    if not args:
        print("Usage: python3 -m engine detect <address> [auto|http|browser] [--json]")
        return 2
    method = args[1] if len(args) > 1 and args[1] in ("auto", "http", "browser") else "auto"
    e = Engine()
    await e.start()
    try:
        r = await detect(e, args[0], method, "browser", "Asia/Yerevan")
    finally:
        await e.stop()
    if as_json:
        print(json.dumps(r, ensure_ascii=False, indent=2, default=str))
        return 0
    print(f"Address: {args[0]}   method: {method}   fetched via: {r.get('via')}   {r.get('ms', 0) / 1000:.1f} s")
    for n in r.get("notes", []):
        print("  note:", n)
    if r.get("error"):
        print("\nRESULT: nothing to use.", r["error"])
        return 1
    for i, c in enumerate(r["candidates"], 1):
        print(f"\n{i}. [{c['kind']}] {c['label']}: {c['count']} found" + ("   <= recommended" if c.get("recommended") else ""))
        print("   address:", c["url"])
        if c.get("config"):
            print("   rule:   ", json.dumps(c["config"], ensure_ascii=False))
        if c.get("note"):
            print("   note:   ", c["note"])
        for it in c["items"][:10]:
            title = it["title"] or "(open the page to read the title)"
            print("   - %-18s %s  %s%s" % ((it.get("date") or "")[:18], title[:90], it["url"].split("://", 1)[-1][:70], f"  [{it['chars']} chars]" if it.get("chars") is not None else ""))
    print()
    return 0


async def cmd_check():
    """Самопроверка: библиотеки, БД, браузер."""
    ok = True
    for m in ("aiohttp", "aiomysql", "curl_cffi", "feedparser", "dateutil", "cssselect", "lxml", "trafilatura", "playwright"):
        try:
            __import__(m)
            print("ok     ", m)
        except Exception as ex:
            ok = False
            print("MISSING", m, ex)
    from .core import Engine
    e = Engine()
    try:
        await e.start()
        print("ok      database;", "browser:", "ready" if e.fetcher.browser.ready else "NOT ready (Playwright/Chromium)")
        await e.stop()
    except Exception as ex:
        ok = False
        print("FAILED  database or browser:", ex)
    return 0 if ok else 1


def main():
    setup_logging()
    cmd = sys.argv[1] if len(sys.argv) > 1 else "run"
    if cmd == "run":
        from .main import run_service
        asyncio.run(run_service())
    elif cmd == "detect":
        sys.exit(asyncio.run(cmd_detect(sys.argv[2:])))
    elif cmd == "check":
        sys.exit(asyncio.run(cmd_check()))
    else:
        print(__doc__)
        sys.exit(2)


main()
