"""Ядро движка: опрос источников (RSS, sitemap, список на странице, Telegram), очередь страниц, полный текст, журнал изменений."""
import asyncio
import gzip
import json
import logging
import random
import re
import time
import traceback

from . import config
from .db import DB
from .extract import extract_article
from .feeds import parse_feed, parse_sitemap
from .fetcher import Fetcher, Resp, classify, is_block
from .listing import extract_links
from .robots import Robots
from .telegram import parse_page as tg_parse_page
from .maint import MaintMixin
from .pages import PagesMixin
from .util import db_to_ts, decode_body, dedup_key, md5hex, norm_url, time_hint, ts_to_db

log = logging.getLogger("core")
MAINT_SQL_RAW_DAYS = config.RAW_DAYS


def jload(v) -> dict:
    if isinstance(v, dict):
        return v
    try:
        return json.loads(v) if v else {}
    except Exception:
        return {}


def interval_of(src: dict) -> int:
    if (src.get("interval_mode") or "fixed") == "auto" and src.get("auto_interval_sec"):
        return int(src["auto_interval_sec"])
    return int(src.get("check_interval_sec") or 120)


def new_res() -> dict:
    return {"ok": False, "http": 0, "ms": 0, "new": 0, "feed": 0, "old": 0, "dup": 0, "edited": 0, "queued": 0, "error": "", "event": ""}


def ft_delay(attempt: int) -> int:
    return {1: 120, 2: 600, 3: 3600, 4: 21600}.get(attempt, 86400)


class Engine(PagesMixin, MaintMixin):
    def __init__(self):
        self.db = DB()
        self.fetcher = Fetcher(self.db)
        self.robots = Robots(self.db, self.fetcher)
        self.sem_sources = asyncio.Semaphore(config.POLL_CONCURRENCY)
        self.sem_pages = asyncio.Semaphore(config.PAGE_CONCURRENCY)
        self.started = time.time()
        self.jobs_done = 0

    async def start(self):
        await self.db.init()
        await self.fetcher.start()

    async def stop(self):
        await self.fetcher.stop()
        await self.db.close()

    # ---------- журнал и изменения ----------
    async def log(self, sid, level, event, http=None, ms=0, new=0, msg="", details=""):
        await self.db.run(
            "INSERT INTO crawl_log (source_id, level, event, http_code, duration_ms, new_articles, message, details) VALUES (%s,%s,%s,%s,%s,%s,%s,%s)",
            (sid, level, event, http or None, ms, new, (msg or "")[:500], (details or "")[:4000]))

    async def journal_add(self, ids, op="upsert"):
        ids = sorted({int(i) for i in ids})
        for i in range(0, len(ids), 500):
            chunk = ids[i:i + 500]
            await self.db.run("INSERT INTO article_changes (article_id, op) VALUES " + ",".join(["(%s, %s)"] * len(chunk)), [x for a in chunk for x in (a, op)])

    async def journal_touch(self, aid):
        await self.db.run("UPDATE articles SET journal_at = COALESCE(journal_at, NOW()) WHERE id = %s", (aid,))
        await self.journal_add([aid])

    # ---------- состояние источника ----------
    async def fail(self, src, res, event, msg, details=""):
        errors = int(src.get("consecutive_errors") or 0) + 1
        delay = min(1800, interval_of(src) * (2 ** min(errors, 5)))
        await self.db.run(
            "UPDATE sources SET last_checked_at=NOW(), last_http_code=%s, last_response_ms=%s, last_error=%s, consecutive_errors=consecutive_errors+1, "
            "next_check_at = NOW() + INTERVAL %s SECOND WHERE id=%s", (res.get("http") or None, res.get("ms", 0), msg[:500], delay, src["id"]))
        await self.log(src["id"], "error", event, res.get("http"), res.get("ms", 0), 0, msg, details)
        res.update(ok=False, error=msg, event=event)
        return res

    async def succeed(self, src, res, new=0, unfinished=0, etag=None, lastmod=None, state=None):
        iv = interval_of(src)
        delay = iv + random.randint(0, max(1, int(iv * 0.1)))
        sql = ("UPDATE sources SET last_checked_at=NOW(), last_success_at=NOW(), last_http_code=%s, last_response_ms=%s, last_error=NULL, consecutive_errors=0, "
               "etag=COALESCE(%s, etag), last_modified=COALESCE(%s, last_modified), next_check_at = NOW() + INTERVAL %s SECOND, "
               "articles_count = articles_count + %s, unfinished_count = unfinished_count + %s, last_article_at = IF(%s > 0, NOW(), last_article_at)")
        args = [res.get("http") or None, res.get("ms", 0), etag, lastmod, delay, new, unfinished, new]
        if state is not None:
            sql += ", state = %s"
            args.append(json.dumps(state))
        await self.db.run(sql + " WHERE id = %s", args + [src["id"]])
        res.update(ok=True)
        return res

    # ---------- опрос источника ----------
    async def process_source(self, src: dict, force: bool = False) -> dict:
        res = new_res()
        async with self.sem_sources:
            try:
                t = src["type"]
                if t == "sitemap":
                    return await self.proc_sitemap(src, force)
                if t == "listing":
                    return await self.proc_listing(src, force)
                return await self.proc_feed(src, force)
            except Exception as e:
                log.exception("source %s failed", src.get("id"))
                return await self.fail(src, res, "engine_error", f"Engine error: {e}", traceback.format_exc()[-1500:])

    async def proc_feed(self, src, force):
        res = new_res()
        r = await self.fetcher.fetch(src["feed_url"], src, "feed", force=force, etag=src.get("etag"), lastmod=src.get("last_modified"))
        res.update(http=r.status, ms=r.ms)
        f = classify(r)
        if f:
            return await self.fail(src, res, *f)
        tz = src.get("feed_timezone") or "UTC"
        if r.status == 304:
            return await self.succeed(src, res)
        if src["type"] == "telegram":
            items = await self.telegram_items(src, r)
        else:
            items = await asyncio.to_thread(parse_feed, r.body, tz)
        if items is None:
            return await self.fail(src, res, "parse_error", "Response is not a valid RSS/Atom feed", f"URL: {src['feed_url']}\n\nResponse start:\n{r.snippet()}")
        res["feed"] = len(items)
        st = await self.store_items(src, items, res)
        await self.succeed(src, res, new=st["new"], unfinished=st["unfinished"], etag=r.headers.get("etag"), lastmod=r.headers.get("last-modified"))
        if not items:
            await self.log(src["id"], "warning", "empty_feed", r.status, r.ms, 0,
                           ("No posts found. The channel must be public with web preview enabled: https://t.me/s/<username> must show posts in a browser. Posts with only photos or video are skipped."
                            if src["type"] == "telegram" else "The feed is valid but contains no items."), f"URL: {src['feed_url']}")
        elif st["new"] > 0:
            await self.log(src["id"], "info", "fetched", r.status, r.ms, st["new"],
                           f"{st['new']} new articles (feed: {len(items)} items, {res['dup']} already stored, {res['old']} older than start date)", f"URL: {src['feed_url']}\nFetched by: {r.via}")
        return res

    async def telegram_items(self, src, r: Resp) -> list:
        items, min_id = tg_parse_page(decode_body(r.body, r.ctype))
        pages = int(jload(src.get("extra")).get("history_pages", 1))
        min_ts = db_to_ts(src.get("min_published_at"))
        if int(src.get("articles_count") or 0) == 0 and pages > 1 and min_id:
            t0 = time.time()
            for _ in range(1, pages):
                if min_id <= 1 or time.time() - t0 > 40:
                    break
                if min_ts and items and min(i["ts"] or 0 for i in items) < min_ts:
                    break
                p = await self.fetcher.fetch(f"{src['feed_url']}?before={min_id}", src, "page", force=True)
                if classify(p) or p.status != 200:
                    break
                more, mid = tg_parse_page(decode_body(p.body, p.ctype))
                if not mid or mid >= min_id:
                    break
                items += more
                min_id = mid
        return items

    # ---------- сохранение статей ленты ----------
    async def store_items(self, src, items, res) -> dict:
        sid = src["id"]
        st = {"new": 0, "unfinished": 0}
        min_ts = db_to_ts(src.get("min_published_at"))
        need_full = bool(src.get("fetch_full_text"))
        prepared = []
        cfg = jload(src.get("extra"))
        for i in items:
            u = i.get("url") or ""
            if (cfg.get("url_include") and not self._match(u, cfg["url_include"])) or (cfg.get("url_exclude") and self._match(u, cfg["url_exclude"])):
                continue
            key = dedup_key(src.get("dedup_by") or "url", i)
            if not key or not i["title"]:
                continue
            i["dh"] = md5hex(key)
            prepared.append(i)
        existing = {}
        recent = [i for i in prepared if (i["ts"] or 0) >= time.time() - 3 * 86400]
        if recent:
            rows = await self.db.all(
                "SELECT id, HEX(dedup_hash) h, LOWER(HEX(content_hash)) ch, title FROM articles WHERE source_id = %s AND dedup_hash IN (" + ",".join(["UNHEX(%s)"] * len(recent)) + ")",
                [sid] + [i["dh"] for i in recent])
            existing = {r["h"].lower(): r for r in rows}
        journal = []
        for i in prepared:
            if min_ts is not None and (i["ts"] or 0) < min_ts:
                res["old"] += 1
                continue
            title, content = i["title"][:1000], i["content"][:500000]
            ch = md5hex(title + "\n" + content)
            ex = existing.get(i["dh"])
            if ex:                                    # уже есть: проверяем правку
                changed = (ex["title"] != title) if need_full else (ex["ch"] != ch)
                if changed:
                    if need_full:
                        await self.db.run("UPDATE articles SET title=%s, revision = revision + 1, updated_at = NOW() WHERE id=%s", (title, ex["id"]))
                    else:
                        await self.db.run("UPDATE articles SET title=%s, content=%s, content_hash=UNHEX(%s), revision = revision + 1, updated_at = NOW() WHERE id=%s", (title, content, ch, ex["id"]))
                    journal.append(ex["id"])
                    res["edited"] += 1
                res["dup"] += 1
                continue
            url = (i["url"] or "")[:2048]
            nf = need_full and url != ""
            now = ts_to_db(time.time())
            n, aid = await self.db.run(
                "INSERT IGNORE INTO articles (source_id, dedup_hash, url, title, content, published_at, content_hash, content_status, next_retry_at, journal_at) "
                "VALUES (%s, UNHEX(%s), %s, %s, %s, %s, UNHEX(%s), %s, %s, %s)",
                (sid, i["dh"], url, title, content, ts_to_db(i["ts"]), ch, "pending" if nf else "full", now if nf else None, None if nf else now))
            if n == 1:
                st["new"] += 1
                st["unfinished"] += 1 if nf else 0
                if not nf:
                    journal.append(aid)
                if i.get("raw"):
                    await self.db.run("INSERT IGNORE INTO article_raw (article_id, raw) VALUES (%s, UNHEX(%s))", (aid, gzip.compress(i["raw"].encode("utf-8"), 6).hex()))
            else:
                res["dup"] += 1
        if journal:
            await self.journal_add(journal)
        res["new"] = st["new"]
        return st

    # ---------- очередь страниц ----------
    async def queue_insert(self, sid, entries) -> int:
        new = 0
        for i in range(0, len(entries), 200):
            chunk = entries[i:i + 200]
            args = []
            for e in chunk:
                args += [sid, md5hex(norm_url(e["loc"])), e["loc"][:2048], ts_to_db(e.get("ts")),
                         (e.get("title") or None) and e["title"][:1000], ts_to_db(e.get("pub"))]
            n, _ = await self.db.run(
                "INSERT IGNORE INTO url_queue (source_id, url_hash, url, lastmod, title_hint, published_hint, next_try_at) VALUES "
                + ",".join(["(%s, UNHEX(%s), %s, %s, %s, %s, NOW())"] * len(chunk)), args)
            new += max(0, n)
        return new

    # ---------- sitemap ----------
    async def proc_sitemap(self, src, force):
        res = new_res()
        cfg, state = jload(src.get("extra")), jload(src.get("state"))
        ctx = {"queued": 0, "feed": 0, "old": 0, "ms": 0, "http": 0, "maps_ok": 0, "first": not state.get("max_ts"),
               "max_ts": int(state.get("max_ts") or 0), "new_max": int(state.get("max_ts") or 0), "min_ts": db_to_ts(src.get("min_published_at"))}
        urls = [src["feed_url"]] + [u for u in (cfg.get("urls_extra") or []) if u]
        state.setdefault("cur", {})
        state.setdefault("last_probe", {})
        failure = None
        for i, u in enumerate(urls):
            cur = state["cur"].get(str(i)) or u
            failure = await self.sm_scan(src, cfg, cur, force, ctx, state)
            if failure:
                break
            prev = state.setdefault("prev", {}).get(str(i))        # прошлая карта: ещё сутки досматриваем её хвост
            if prev and prev[0] != cur and time.time() - prev[1] < 86400:
                await self.sm_scan(src, cfg, prev[0], force, ctx, state)
            m = re.search(r"(\d+)(\.xml(?:\.gz)?)$", cur, re.I)
            # карта с номером в имени (post-sitemap89.xml): когда она заполнится, сайт создаст следующую. Ищем 90, 91... сами.
            if cfg.get("probe_next", True) and m and (force or time.time() - int(state["last_probe"].get(str(i), 0)) >= 600):
                state["last_probe"][str(i)] = int(time.time())
                for _ in range(3):
                    nxt = re.sub(r"(\d+)(\.xml(?:\.gz)?)$", lambda mm: str(int(mm.group(1)) + 1) + mm.group(2), cur, flags=re.I)
                    before, saved = ctx["maps_ok"], ctx["http"]
                    ctx_probe = await self.sm_scan(src, cfg, nxt, True, ctx, state)
                    if ctx_probe is None and ctx["maps_ok"] > before:
                        state["prev"][str(i)] = [cur, int(time.time())]
                        state["cur"][str(i)] = cur = nxt
                        await self.log(src["id"], "info", "sitemap_next", ctx["http"], 0, 0, f"New sitemap found, switching to {nxt}")
                    else:
                        ctx["http"] = saved
                        break
        res.update(http=ctx["http"], ms=ctx["ms"], feed=ctx["feed"], old=ctx["old"], queued=ctx["queued"])
        if failure:
            await self.db.run("UPDATE sources SET state = %s WHERE id = %s", (json.dumps(state), src["id"]))
            return await self.fail(src, res, *failure)
        state["max_ts"] = max(ctx["max_ts"], ctx["new_max"])
        await self.succeed(src, res, state=state)
        if ctx["queued"] > 0:
            await self.log(src["id"], "info", "sitemap_queued", res["http"], res["ms"], 0,
                           f"{ctx['queued']} new article URLs found in sitemap ({ctx['feed']} entries checked, {ctx['old']} older than start date)")
        return res

    @staticmethod
    def _num(u):
        m = re.search(r"(\d+)(?:\.xml(?:\.gz)?)?/?$", u.split("?")[0], re.I)
        return int(m.group(1)) if m else 0

    async def sm_scan(self, src, cfg, url, force, ctx, state, depth=0):
        """Одна карта: индекс → самые свежие дочерние; urlset → новые ссылки в очередь. None = ок, иначе (event, message, details)."""
        key = md5hex(url)
        cached = state.setdefault("etag", {}).get(key) or [None, None]
        r = await self.fetcher.fetch(url, src, "feed", force=force, etag=cached[0], lastmod=cached[1])
        ctx["ms"] += r.ms
        ctx["http"] = r.status
        f = classify(r)
        if f:
            return f
        if r.status == 304:
            ctx["maps_ok"] += 1
            return None
        if r.headers.get("etag") or r.headers.get("last-modified"):
            state["etag"][key] = [r.headers.get("etag"), r.headers.get("last-modified")]
        kind, entries = await asyncio.to_thread(parse_sitemap, r.body)
        if not kind:
            return ("parse_error", "This address is not a sitemap.", f"URL: {url}\n\nResponse start:\n{r.snippet()}")
        min_ts = ctx["min_ts"]
        if kind == "index":
            if depth >= 2:
                return None
            entries.sort(key=lambda e: (e["ts"] or 0, self._num(e["loc"])), reverse=True)    # свежее по lastmod, а без него по номеру в имени
            taken = 0
            for e in entries:
                if cfg.get("map_include") and not self._match(e["loc"], cfg["map_include"]):
                    continue
                if min_ts and e["ts"] and e["ts"] < min_ts:
                    continue
                if taken >= int(cfg.get("maps_limit", 2)):
                    break
                taken += 1
                f2 = await self.sm_scan(src, cfg, e["loc"], force, ctx, state, depth + 1)
                if f2:
                    return f2
            return None
        ctx["maps_ok"] += 1
        fresh = []
        for e in entries:
            if not e["loc"]:
                continue
            if cfg.get("url_include") and not self._match(e["loc"], cfg["url_include"]):
                continue
            if cfg.get("url_exclude") and self._match(e["loc"], cfg["url_exclude"]):
                continue
            ts = e["pub"] or e["ts"]
            if min_ts and ts and ts < min_ts:
                ctx["old"] += 1
                continue
            if not ctx["first"] and e["ts"] and e["ts"] < ctx["max_ts"] - 86400:
                continue
            fresh.append(e)
            if e["ts"] and e["ts"] > ctx["new_max"]:
                ctx["new_max"] = e["ts"]
        ctx["feed"] += len(entries)
        if ctx["first"]:                              # первый запуск: не заливаем архив, только самые свежие
            fresh.sort(key=lambda e: e["ts"] or 0, reverse=True)
            fresh = fresh[:300]
        ctx["queued"] += await self.queue_insert(src["id"], fresh)
        return None

    @staticmethod
    def _match(url: str, lst: str) -> bool:
        return any(n and n.lower() in url.lower() for n in re.split(r"[\s,]+", lst or ""))

    # ---------- список ссылок на странице ----------
    async def proc_listing(self, src, force):
        res = new_res()
        cfg = jload(src.get("extra"))
        url = src["feed_url"]
        if src.get("respect_robots", 1):
            ok, why = await self.robots.allowed(src, url)
            if not ok:
                return await self.fail(src, res, "robots_disallowed", why, f"URL: {url}")
        r = await self.fetcher.fetch(url, src, "page", force=True)
        res.update(http=r.status, ms=r.ms)
        f = classify(r)
        if f:
            return await self.fail(src, res, *f)
        items = await asyncio.to_thread(extract_links, decode_body(r.body, r.ctype), r.final_url or url, {"container": cfg.get("list_container", ""), "pattern": cfg.get("list_pattern", ".")})
        if not items:
            return await self.fail(src, res, "listing_empty", "No article links found with the saved rule. The page layout may have changed: open the source and run the detection again.",
                                   f"URL: {url}\nRule: {json.dumps(cfg, ensure_ascii=False)}")
        res["feed"] = len(items)
        tz = src.get("feed_timezone") or "Asia/Yerevan"
        min_ts = db_to_ts(src.get("min_published_at"))
        entries = []
        for i in items[:300]:
            if (cfg.get("url_include") and not self._match(i["url"], cfg["url_include"])) or (cfg.get("url_exclude") and self._match(i["url"], cfg["url_exclude"])):
                continue
            pub = time_hint(i["time"], tz)
            if min_ts and pub and pub < min_ts:
                res["old"] += 1
                continue
            entries.append({"loc": i["url"], "ts": pub, "title": i["title"], "pub": pub})
        res["queued"] = await self.queue_insert(src["id"], entries)
        await self.succeed(src, res)
        if res["queued"] > 0:
            await self.log(src["id"], "info", "listing_queued", res["http"], res["ms"], 0, f"{res['queued']} new article links found on the page ({res['feed']} links in total)")
        return res
