"""robots.txt по RFC 9309: группы по User-agent, самое длинное совпадение, * и $. Кэш в таблице robots_cache."""
import re
import time
from urllib.parse import urlparse

from . import config
from .fetcher import Fetcher, classify


def rules(body: str) -> list:
    """Правила для нашего агента: [(allow, шаблон)]."""
    groups = []
    cur = None
    last_ua = False
    for line in re.split(r"\r\n|\r|\n", body or ""):
        line = re.sub(r"#.*$", "", line).strip()
        if not line or ":" not in line:
            continue
        k, v = (x.strip() for x in line.split(":", 1))
        k = k.lower()
        if k == "user-agent":
            if not last_ua:
                groups.append({"agents": [], "rules": []})
                cur = groups[-1]
            cur["agents"].append(v.lower())
            last_ua = True
        elif k in ("allow", "disallow") and cur is not None:
            last_ua = False
            if v:
                cur["rules"].append((k == "allow", v))
        else:
            last_ua = False
    pick, best = None, -1
    for g in groups:
        for a in g["agents"]:
            score = 0 if a == "*" else (len(a) if (config.ROBOTS_TOKEN in a or a in config.ROBOTS_TOKEN) else -1)
            if score > best:
                best, pick = score, g["rules"]
    return pick or []


def _match(pattern: str, path: str) -> bool:
    rx = ""
    for part in re.split(r"(\*|\$$)", pattern):
        rx += ".*" if part == "*" else ("$" if part == "$" else re.escape(part))
    return re.match(rx, path) is not None


def path_allowed(rs: list, path: str) -> bool:
    best, allowed = -1, True
    for allow, pat in rs:
        if not _match(pat, path):
            continue
        if len(pat) > best or (len(pat) == best and allow):
            best, allowed = len(pat), allow
    return allowed


class Robots:
    def __init__(self, db, fetcher: Fetcher):
        self.db = db
        self.fetcher = fetcher
        self.mem = {}      # host -> (expires, status, body)

    async def allowed(self, src: dict, url: str) -> tuple:
        """(разрешено?, причина). Если у источника выключено уважение robots.txt, всегда разрешено."""
        if not src.get("respect_robots", 1):
            return True, ""
        p = urlparse(url)
        host = (p.hostname or "").lower()
        if not host:
            return True, ""
        path = (p.path or "/") + ("?" + p.query if p.query else "")
        ent = self.mem.get(host)
        if not ent or ent[0] < time.time():
            row = await self.db.one("SELECT status, body, expires_at < NOW() AS stale FROM robots_cache WHERE host = %s", (host,))
            if row and not row["stale"]:
                ent = (time.time() + 600, row["status"], row["body"])
            else:
                origin = f"{p.scheme}://{p.netloc}"
                r = await self.fetcher.fetch(origin + "/robots.txt", src, "page", force=True)
                if r.error or r.status >= 500:
                    status, body, ttl = "error", None, 900          # RFC 9309: при ошибке сервера считаем всё закрытым, повтор через 15 минут
                elif r.status >= 400 or r.status == 0:
                    status, body, ttl = "none", None, 86400         # 4xx: файла нет, ограничений нет
                else:
                    status, body, ttl = "ok", r.body[:500000].decode("utf-8", "replace"), 86400
                await self.db.run(
                    "INSERT INTO robots_cache (host, status, body, expires_at) VALUES (%s,%s,%s, NOW() + INTERVAL %s SECOND) "
                    "ON DUPLICATE KEY UPDATE status = VALUES(status), body = VALUES(body), fetched_at = NOW(), expires_at = VALUES(expires_at)",
                    (host, status, body, ttl))
                ent = (time.time() + min(ttl, 600), status, body)
            self.mem[host] = ent
        _, status, body = ent
        if status == "error":
            return False, f"robots.txt of {host} could not be read (server error); retrying later"
        if status == "none":
            return True, ""
        return (True, "") if path_allowed(rules(body or ""), path) else (False, f"Disallowed by robots.txt of {host}")
