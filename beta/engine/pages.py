"""Страницы статей: полный текст для статей из ленты, обработка очереди (sitemap и списки), повторы, заголовок при закрытой странице."""
import asyncio
import logging
import secrets
import time

from . import config
from .extract import extract_article
from .fetcher import classify
from .util import clean_url, db_to_ts, decode_body, dedup_key, host_of, md5hex, ts_to_db

log = logging.getLogger("pages")


def jload(v):
    import json
    if isinstance(v, dict):
        return v
    try:
        return json.loads(v) if v else {}
    except Exception:
        return {}


def ft_delay(attempt: int) -> int:
    return {1: 120, 2: 600, 3: 3600, 4: 21600}.get(attempt, 86400)


class PagesMixin:
    # ---------- выбор задач ----------
    async def collect_jobs(self, limit: int = 30, source_id: int = 0, per_host_cap: int = 6) -> list:
        """Забрать задачи атомарно (UPDATE ... LIMIT). source_id: ручной запуск для одного источника, игнорируя расписание."""
        tok = secrets.token_hex(6)
        wa = f"source_id = {int(source_id)}" if source_id else "next_retry_at <= NOW()"
        wq = f"source_id = {int(source_id)}" if source_id else "next_try_at <= NOW()"
        oa = "id" if source_id else "next_retry_at"
        oq = "id" if source_id else "next_try_at"
        await self.db.run(f"UPDATE articles SET claimed_by = %s, next_retry_at = NOW() + INTERVAL 120 SECOND WHERE content_status = 'pending' AND {wa} ORDER BY {oa} LIMIT {int(limit)}", (tok,))
        await self.db.run(f"UPDATE url_queue SET claimed_by = %s, next_try_at = NOW() + INTERVAL 120 SECOND WHERE status = 'pending' AND {wq} ORDER BY {oq} LIMIT {int(limit)}", (tok,))
        rows = [dict(r, kind="article") for r in await self.db.all(
            "SELECT id, source_id, url, title, content, fetch_attempts FROM articles WHERE content_status = 'pending' AND claimed_by = %s", (tok,))]
        rows += [dict(r, kind="queue") for r in await self.db.all(
            "SELECT id, source_id, url, lastmod, title_hint, published_hint, attempts FROM url_queue WHERE status = 'pending' AND claimed_by = %s", (tok,))]
        ids = sorted({r["source_id"] for r in rows})
        srcs = {}
        if ids:
            for s in await self.db.all("SELECT * FROM sources WHERE id IN (" + ",".join(["%s"] * len(ids)) + ")", ids):
                srcs[s["id"]] = s
        jobs, per_host, release = [], {}, {"articles": [], "url_queue": []}
        for row in rows:
            src = srcs.get(row["source_id"])
            tbl = "articles" if row["kind"] == "article" else "url_queue"
            host = host_of(row["url"])
            if not src or not src["is_active"]:
                release[tbl].append((row["id"], "1 HOUR"))           # источник остановлен: не крутим задачу каждую секунду
            elif not src["fetch_full_text"] and row["kind"] == "article":
                await self.db.run("UPDATE articles SET content_status='full', next_retry_at=NULL WHERE id=%s", (row["id"],))     # опцию выключили: остаётся текст ленты
                await self.db.run("UPDATE sources SET unfinished_count = GREATEST(unfinished_count - 1, 0) WHERE id=%s", (src["id"],))
                await self.journal_touch(row["id"])
            else:
                per_host[host] = per_host.get(host, 0) + 1
                if per_host[host] > per_host_cap and not source_id:
                    release[tbl].append((row["id"], "0 SECOND"))      # слишком много задач одного сайта за цикл
                else:
                    jobs.append(dict(row, src=src))
        for tbl, lst in release.items():
            col = "next_retry_at" if tbl == "articles" else "next_try_at"
            for rid, d in lst:
                await self.db.run(f"UPDATE {tbl} SET claimed_by = NULL, {col} = NOW() + INTERVAL {d} WHERE id = %s", (rid,))
        return jobs

    async def run_jobs(self, jobs: list) -> dict:
        st = {"full": 0, "failed": 0, "partial": 0, "inserted": 0, "last_error": ""}

        async def one(job):
            async with self.sem_pages:
                try:
                    await self.run_job(job, st)
                except Exception as e:
                    log.exception("job failed")
                    st["last_error"] = str(e)
                self.jobs_done += 1
        await asyncio.gather(*(one(j) for j in jobs))
        return st

    async def process_pending(self, source_id: int, limit: int = 20) -> dict:
        """Ручной запуск (Fetch now, Retry): открыть ожидающие страницы источника."""
        return await self.run_jobs(await self.collect_jobs(limit, source_id))

    # ---------- одна страница ----------
    async def run_job(self, job: dict, st: dict):
        src, url, kind = job["src"], job["url"], job["kind"]
        if src.get("respect_robots", 1):                                    # уважаем robots.txt (RFC 9309)
            ok, why = await self.robots.allowed(src, url)
            if not ok:
                if "retrying later" in why:
                    tbl, col = ("articles", "next_retry_at") if kind == "article" else ("url_queue", "next_try_at")
                    await self.db.run(f"UPDATE {tbl} SET {col} = NOW() + INTERVAL 15 MINUTE, claimed_by = NULL WHERE id = %s", (job["id"],))
                    return
                return await self.robots_block(job, why, st)
        r = await self.fetcher.fetch(url, src, "page", force=True)
        f = classify(r)
        ex, event, reason = None, "", ""
        if f:
            event, reason = f[0], f[1]
        else:
            cfg = dict(jload(src.get("extra")), tz=src.get("feed_timezone") or "UTC")
            ex = await asyncio.to_thread(extract_article, decode_body(r.body, r.ctype), url, cfg, r.final_url or url)
            n = len(ex["text"])
            if n == 0:
                event, reason = "fulltext_failed", "No article text found on the page"
            elif kind == "queue" and n < config.FT_MIN_LEN:
                event, reason = "fulltext_failed", f"Extracted text too short ({n} chars)"
        if reason:
            st["last_error"] = reason
        # оригинальный адрес: canonical с того же сайта, иначе адрес после перенаправлений
        orig = url
        if ex is not None:
            orig = ex["canonical"] or (clean_url(r.final_url) if r.final_url and r.final_url.startswith("http") else url)
        sid, http = src["id"], r.status or None
        if kind == "article":
            return await self.job_article(job, src, ex, reason, event, orig, http, st)
        return await self.job_queue(job, src, ex, reason, event, orig, http, st)

    async def job_article(self, job, src, ex, reason, event, orig, http, st):
        aid, sid, url = job["id"], src["id"], job["url"]
        att = int(job["fetch_attempts"]) + 1
        if not reason:
            have = (job["content"] or "").strip()
            text = ex["text"] if len(ex["text"]) > len(have) else job["content"]       # со страницы взяли больше, чем было в ленте
            ch = md5hex(job["title"] + "\n" + text)
            await self.db.run(
                "UPDATE articles SET content=%s, content_hash=UNHEX(%s), content_status='full', fail_reason=NULL, next_retry_at=NULL, fetch_attempts=%s, "
                "feed_link = IF(%s <> url, COALESCE(feed_link, url), feed_link), url = %s WHERE id = %s", (text, ch, att, orig, orig, aid))
            await self.db.run("UPDATE sources SET unfinished_count = GREATEST(unfinished_count - 1, 0) WHERE id = %s", (sid,))
            await self.journal_touch(aid)
            st["full"] += 1
            return
        short = reason[:255]
        if att >= config.FT_MAX_ATTEMPTS:
            status = "title_only" if not (job["content"] or "").strip() else "partial"
            await self.db.run("UPDATE articles SET content_status=%s, fetch_attempts=%s, next_retry_at=NULL, fail_reason=%s WHERE id=%s", (status, att, short, aid))
            await self.log(sid, "error", "fulltext_gaveup", http, 0, 0, f"Gave up after {att} attempts, saved as {status.replace('_', ' ')}: {reason}", f"URL: {url}")
            await self.journal_touch(aid)
            st["partial"] += 1
        else:
            await self.db.run("UPDATE articles SET fetch_attempts=%s, next_retry_at = NOW() + INTERVAL %s SECOND, fail_reason=%s WHERE id=%s", (att, ft_delay(att), short, aid))
            if att == 1:
                await self.log(sid, "error" if event == "cloudflare_block" else "warning", event, http, 0, 0, reason, f"URL: {url}")
            st["failed"] += 1

    async def job_queue(self, job, src, ex, reason, event, orig, http, st):
        qid, sid, url = job["id"], src["id"], job["url"]
        att = int(job["attempts"]) + 1
        hint = (job.get("title_hint") or "").strip()
        page_title = (ex or {}).get("title", "")
        if ex is None or not page_title:
            reason = reason or "No title found on the page"
            if hint:
                # страница не отдалась, но заголовок известен: сохраняем статью сразу, дальше её дозагружает обычный повтор
                await self.save_hint_article(job, src, "pending", reason, 1)
                st["failed"] += 1
                await self.log(sid, "error" if event == "cloudflare_block" else "warning", event or "fulltext_failed", http, 0, 0, reason + " (saved with the title from the list)", f"URL: {url}")
                return
            short = reason[:255]
            if att >= config.FT_MAX_ATTEMPTS:
                await self.db.run("UPDATE url_queue SET status='failed', attempts=%s, last_error=%s WHERE id=%s", (att, short, qid))
                await self.log(sid, "error", "fulltext_gaveup", http, 0, 0, f"Gave up after {att} attempts, article not saved: {reason}", f"URL: {url}")
            else:
                await self.db.run("UPDATE url_queue SET attempts=%s, last_error=%s, next_try_at = NOW() + INTERVAL %s SECOND WHERE id=%s", (att, short, ft_delay(att), qid))
                if att == 1:
                    await self.log(sid, "error" if event == "cloudflare_block" else "warning", event or "fulltext_failed", http, 0, 0, reason, f"URL: {url}")
            st["failed"] += 1
            return
        lm, ph = db_to_ts(job.get("lastmod")), db_to_ts(job.get("published_hint"))
        ts = ex["ts"] or ph or lm or int(time.time())
        min_ts = db_to_ts(src.get("min_published_at"))
        if min_ts is not None and ts < min_ts:
            await self.db.run("UPDATE url_queue SET status='skipped', attempts=%s WHERE id=%s", (att, qid))
            return
        # хеш для проверки дублей считаем по адресу из очереди (стабильно), а в articles.url кладём оригинальный адрес
        item = {"url": url, "guid": url, "title": page_title, "ts": ts, "content": ex["text"]}
        dh = md5hex(dedup_key(src.get("dedup_by") or "url", item))
        ok = not reason
        n, newid = await self.db.run(
            "INSERT IGNORE INTO articles (source_id, dedup_hash, url, feed_link, title, content, published_at, content_hash, content_status, fetch_attempts, next_retry_at, fail_reason, journal_at) "
            "VALUES (%s, UNHEX(%s), %s, %s, %s, %s, %s, UNHEX(%s), %s, %s, %s, %s, %s)",
            (sid, dh, orig[:2048], url[:2048] if orig != url else None, page_title[:1000], ex["text"][:500000], ts_to_db(ts), md5hex(page_title + "\n" + ex["text"]),
             "full" if ok else "pending", 0 if ok else 1, None if ok else ts_to_db(time.time() + ft_delay(1)), None if ok else reason[:255], ts_to_db(time.time()) if ok else None))
        inserted = n == 1
        if inserted and ok:
            await self.journal_add([newid])
        why_skip = None
        if not inserted:                     # не молчим: причина видна в очереди и в журнале
            ex_row = await self.db.one("SELECT id FROM articles WHERE source_id = %s AND dedup_hash = UNHEX(%s)", (sid, dh))
            why_skip = f"Already saved as article #{ex_row['id']}" if ex_row else "The database rejected the article row"
            if not ex_row:
                await self.log(sid, "error", "insert_rejected", http, 0, 0, why_skip, f"URL: {url}\nTitle: {page_title[:200]}\nPublished: {ts_to_db(ts)}\nText length: {len(ex['text'])}")
        await self.db.run("UPDATE url_queue SET status=%s, attempts=%s, last_error=%s WHERE id=%s", ("done" if inserted else "skipped", att, why_skip, qid))
        if inserted:
            await self.db.run("UPDATE sources SET articles_count = articles_count + 1, unfinished_count = unfinished_count + %s, last_article_at = NOW() WHERE id = %s", (0 if ok else 1, sid))
            st["inserted"] += 1
            st["full" if ok else "failed"] += 1
            if not ok:
                await self.log(sid, "warning", event, http, 0, 0, reason, f"URL: {url}")

    # ---------- закрытая страница и robots ----------
    async def save_hint_article(self, job, src, status, reason, attempts) -> bool:
        """Сохранить статью по заголовку из карты или списка, когда страница недоступна."""
        sid, qid = src["id"], job["id"]
        title = (job.get("title_hint") or "").strip()[:1000]
        lm, ph = db_to_ts(job.get("lastmod")), db_to_ts(job.get("published_hint"))
        ts = ph or lm or int(time.time())
        min_ts = db_to_ts(src.get("min_published_at"))
        if min_ts is not None and ts < min_ts:
            await self.db.run("UPDATE url_queue SET status='skipped' WHERE id=%s", (qid,))
            return False
        url = job["url"][:2048]
        dh = md5hex(dedup_key(src.get("dedup_by") or "url", {"url": url, "guid": url, "title": title, "ts": ts, "content": ""}))
        pending = status == "pending"
        n, newid = await self.db.run(
            "INSERT IGNORE INTO articles (source_id, dedup_hash, url, title, content, published_at, content_hash, content_status, fetch_attempts, next_retry_at, fail_reason, journal_at) "
            "VALUES (%s, UNHEX(%s), %s, %s, '', %s, UNHEX(%s), %s, %s, %s, %s, %s)",
            (sid, dh, url, title, ts_to_db(ts), md5hex(title + "\n"), status, attempts, ts_to_db(time.time() + ft_delay(1)) if pending else None, reason[:255], None if pending else ts_to_db(time.time())))
        inserted = n == 1
        await self.db.run("UPDATE url_queue SET status=%s, attempts = attempts + 1, last_error=%s WHERE id=%s", ("done" if inserted else "skipped", reason[:255] or None, qid))
        if inserted:
            await self.db.run("UPDATE sources SET articles_count = articles_count + 1, unfinished_count = unfinished_count + 1, last_article_at = NOW() WHERE id = %s", (sid,))
            if not pending:
                await self.journal_add([newid])
        return inserted

    async def robots_block(self, job, why, st):
        """Страница закрыта в robots.txt: не открываем. С известным заголовком статья сохраняется как title_only."""
        src, sid = job["src"], job["src"]["id"]
        st["failed"] += 1
        st["last_error"] = why
        if job["kind"] == "article":
            status = "title_only" if not (job["content"] or "").strip() else "partial"
            await self.db.run("UPDATE articles SET content_status=%s, next_retry_at=NULL, fail_reason=%s WHERE id=%s", (status, why[:255], job["id"]))
            await self.journal_touch(job["id"])
        elif (job.get("title_hint") or "").strip():
            await self.save_hint_article(job, src, "title_only", why, 1)
        else:
            await self.db.run("UPDATE url_queue SET status='failed', last_error=%s WHERE id=%s", (why[:255], job["id"]))
        await self.log(sid, "warning", "robots_disallowed", None, 0, 0, why, f"URL: {job['url']}")

    # ---------- ручные действия из админки ----------
    async def reset_source(self, source_id: int) -> int:
        """Retry incomplete: неполные статьи источника снова в очередь."""
        n, _ = await self.db.run(
            "UPDATE articles SET content_status='pending', fetch_attempts=0, next_retry_at=NOW(), fail_reason=NULL WHERE source_id=%s AND content_status IN ('partial','title_only') LIMIT 20000", (source_id,))
        m, _ = await self.db.run("UPDATE url_queue SET status='pending', attempts=0, next_try_at=NOW(), last_error=NULL WHERE source_id=%s AND status='failed'", (source_id,))
        return max(0, n) + max(0, m)

    async def retry_article(self, article_id: int) -> dict:
        a = await self.db.one("SELECT source_id, content_status FROM articles WHERE id = %s", (article_id,))
        if not a:
            return {"ok": False, "error": "Article not found."}
        if a["content_status"] == "full":
            return {"ok": True, "already_full": True, "full": 1}
        await self.db.run("UPDATE articles SET content_status='pending', fetch_attempts=0, next_retry_at=NOW(), fail_reason=NULL WHERE id=%s", (article_id,))
        jobs = [j for j in await self.collect_jobs(1000, a["source_id"]) if j["kind"] == "article" and j["id"] == article_id]
        return dict(await self.run_jobs(jobs), ok=True)
