"""Обслуживание: пульс, частота публикаций и «молчащие» источники, журнал изменений, очистка, метрики здоровья."""
import asyncio
import logging
import os
import time

from . import config
from .util import fmt_span

log = logging.getLogger("maint")


class MaintMixin:
    async def heartbeat(self):
        await self.db.run(
            "INSERT INTO crawler_status (id, role, heartbeat_at, started_at, pid, jobs_done) VALUES (1, 'engine', NOW(), FROM_UNIXTIME(%s), %s, %s) "
            "ON DUPLICATE KEY UPDATE role = 'engine', heartbeat_at = NOW(), pid = VALUES(pid), jobs_done = VALUES(jobs_done)",
            (int(self.started), os.getpid(), self.jobs_done))

    async def source_stats(self):
        """Раз в 10 минут: статей за 7 дней, авто-интервал, «молчащие» источники."""
        await self.db.run("UPDATE sources s SET articles_7d = (SELECT COUNT(*) FROM articles a WHERE a.source_id = s.id AND a.published_at >= NOW() - INTERVAL 7 DAY) WHERE s.is_active = 1")
        for s in await self.db.all("SELECT id, check_interval_sec, interval_mode, articles_7d, last_article_at, silent_since FROM sources WHERE is_active = 1"):
            sid = s["id"]
            gap = int(7 * 86400 / s["articles_7d"]) if s["articles_7d"] >= 5 else None          # типичная пауза между статьями, с
            auto = max(int(s["check_interval_sec"]), min(900, round(gap / 4))) if (s["interval_mode"] == "auto" and gap) else None
            await self.db.run("UPDATE sources SET auto_interval_sec = %s WHERE id = %s", (auto, sid))
            last = s["last_article_at"]
            silent_for = int(time.time() - last.replace(tzinfo=__import__("datetime").timezone.utc).timestamp()) if last else 0
            is_silent = bool(gap and last and silent_for > max(6 * gap, 10800))
            if is_silent and not s["silent_since"]:
                await self.db.run("UPDATE sources SET silent_since = NOW() WHERE id = %s", (sid,))
                await self.log(sid, "warning", "silent_source", None, 0, 0,
                               f"No new articles for {fmt_span(silent_for)}. Usually about one every {fmt_span(gap)}. The feed answers normally, so the site may have stopped publishing or changed its feed.")
            elif not is_silent and s["silent_since"]:
                await self.db.run("UPDATE sources SET silent_since = NULL WHERE id = %s", (sid,))
                await self.log(sid, "info", "source_recovered", None, 0, 0, "New articles are arriving again.")

    async def journal_pending(self):
        """Раз в минуту: статьи, слишком долго ждущие полный текст, уходят в журнал как есть."""
        rows = await self.db.all("SELECT id FROM articles WHERE content_status = 'pending' AND journal_at IS NULL AND fetched_at < NOW() - INTERVAL %s SECOND LIMIT 1000", (config.PENDING_WAIT,))
        if rows:
            ids = [r["id"] for r in rows]
            await self.db.run("UPDATE articles SET journal_at = NOW() WHERE id IN (" + ",".join(["%s"] * len(ids)) + ")", ids)
            await self.journal_add(ids)

    async def purge(self):
        """Раз в час: служебные таблицы."""
        for tbl, days, extra in (("crawl_log", 30, ""), ("url_queue", 30, " AND status <> 'pending'"), ("article_changes", config.JOURNAL_DAYS, "")):
            while True:
                n, _ = await self.db.run(f"DELETE FROM {tbl} WHERE created_at < NOW() - INTERVAL {days} DAY{extra} LIMIT 5000")
                if n < 5000:
                    break
        max_id = await self.db.val("SELECT MAX(id) FROM articles WHERE fetched_at < NOW() - INTERVAL %s DAY", (config.RAW_DAYS,))
        if max_id:
            while True:
                n, _ = await self.db.run("DELETE FROM article_raw WHERE article_id <= %s LIMIT 5000", (max_id,))
                if n < 5000:
                    break

    async def health(self) -> dict:
        b = self.fetcher.browser
        return {"ok": True, "engine": "python", "uptime_s": int(time.time() - self.started), "jobs_done": self.jobs_done,
                "browser": {"ready": b.ready, "requests": b.requests, "challenges": b.challenges, "solved": b.solved, "errors": b.errors}}
