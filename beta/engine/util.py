"""Мелкие общие функции: время, адреса, хеши, текст, даты."""
import hashlib
import re
import time
from datetime import datetime, timezone
from html import unescape
from urllib.parse import parse_qsl, urlencode, urlparse
from zoneinfo import ZoneInfo

from dateutil import parser as dparser
from lxml import html as lhtml


def utcnow() -> datetime:
    return datetime.now(timezone.utc).replace(tzinfo=None)


def ts_to_db(ts) -> "str|None":
    return datetime.fromtimestamp(ts, timezone.utc).strftime("%Y-%m-%d %H:%M:%S") if ts else None


def db_to_ts(v) -> "int|None":
    if not v:
        return None
    if isinstance(v, datetime):
        return int(v.replace(tzinfo=timezone.utc).timestamp())
    return int(datetime.strptime(str(v)[:19], "%Y-%m-%d %H:%M:%S").replace(tzinfo=timezone.utc).timestamp())


def md5hex(s: str) -> str:
    return hashlib.md5(s.encode("utf-8", "replace")).hexdigest()


_TRACK = re.compile(r"^(utm_|fbclid$|gclid$|yclid$|mc_|ref$|_ga)", re.I)


def norm_url(u: str) -> str:
    """Адрес для сравнения: без схемы, www, якоря, меток отслеживания и завершающего слеша."""
    u = (u or "").strip()
    if not u:
        return ""
    p = urlparse(u)
    if not p.netloc:
        return u.lower()
    q = sorted((k, v) for k, v in parse_qsl(p.query, keep_blank_values=True) if not _TRACK.match(k))
    host = re.sub(r"^www\.", "", p.netloc.lower())
    path = p.path.rstrip("/") or "/"
    return host + path + ("?" + urlencode(q) if q else "")


def clean_url(u: str) -> str:
    """Тот же адрес, но читаемый: схема сохраняется, метки отслеживания и якорь убираются."""
    p = urlparse((u or "").strip())
    if not p.netloc:
        return (u or "").strip()
    q = [(k, v) for k, v in parse_qsl(p.query, keep_blank_values=True) if not _TRACK.match(k)]
    return p._replace(query=urlencode(q), fragment="").geturl()


def host_of(u: str) -> str:
    return re.sub(r"^www\.", "", (urlparse(u).hostname or "").lower())


_SOCIAL = {"t.me", "telegram.me", "facebook.com", "m.facebook.com", "fb.com", "instagram.com", "twitter.com", "x.com", "youtube.com"}


def site_key(u: str) -> str:
    """Что считать одним «сайтом» для дубликатов: домен без www; в соцсетях ещё и канал или страница."""
    p = urlparse(u or "")
    h = re.sub(r"^www\.", "", (p.hostname or "").lower())
    if h in _SOCIAL:
        seg = next((x for x in p.path.split("/") if x and x != "s"), "")
        return h + "/" + seg.lower()
    return h


def dup_keys(url: str, title: str) -> "tuple[str, str]":
    """(hex хеша сайта, hex хеша заголовка). Одинаковая пара в articles = та же статья на том же сайте, в какой бы день ни вышла."""
    return md5hex(site_key(url)), md5hex(norm_text(title))


def same_content(a: str, b: str) -> bool:
    """Тот же текст? Равные после нормализации, либо более короткий (анонс из ленты) целиком входит в более длинный.
    Пустой текст (только заголовок) считается совпадающим: при одинаковом заголовке на сайте это та же статья."""
    na, nb = norm_text(a), norm_text(b)
    if not na or not nb or na == nb:
        return True
    short, long_ = (na, nb) if len(na) <= len(nb) else (nb, na)
    return len(short) >= 30 and short[:300] in long_


def norm_text(s: str) -> str:
    return re.sub(r"[\W_]+", "", (s or "").lower(), flags=re.UNICODE)


def html_to_text(html: str) -> str:
    """HTML в обычный текст: теги убраны, пробелы схлопнуты."""
    if not html:
        return ""
    html = re.sub(r"(?is)<(script|style)[^>]*>.*?</\1>", " ", html)
    try:
        el = lhtml.fromstring("<div>" + html + "</div>")
        text = el.text_content()
    except Exception:
        text = re.sub(r"<[^>]+>", " ", html)
    return re.sub(r"\s+", " ", unescape(text)).strip()


def decode_body(body: bytes, ctype: str = "") -> str:
    """Байты ответа в строку: кодировка из заголовка, BOM, meta, иначе UTF-8."""
    enc = None
    m = re.search(r"charset=([\w-]+)", ctype or "", re.I)
    if m:
        enc = m.group(1)
    elif body[:3] == b"\xef\xbb\xbf":
        enc = "utf-8-sig"
    else:
        m = re.search(rb"<meta[^>]+charset=[\"']?([\w-]+)", body[:4096], re.I) or re.match(rb"\s*<\?xml[^>]*encoding=[\"']([\w-]+)", body[:200], re.I)
        if m:
            enc = m.group(1).decode("ascii", "ignore")
    try:
        return body.decode(enc or "utf-8", errors="replace")
    except LookupError:
        return body.decode("utf-8", errors="replace")


def parse_date(s: str, tz: str = "UTC") -> "int|None":
    """Строка даты в unix. Если в строке нет часового пояса, считаем её в поясе источника. Дата из будущего это ошибка пояса: берём «сейчас»."""
    s = (s or "").strip()
    if not s:
        return None
    try:
        dt = dparser.parse(s)
    except (ValueError, OverflowError, TypeError):
        return None
    if dt.tzinfo is None:
        try:
            dt = dt.replace(tzinfo=ZoneInfo(tz))
        except Exception:
            dt = dt.replace(tzinfo=timezone.utc)
    ts = int(dt.timestamp())
    now = int(time.time())
    return now if ts > now + 900 else ts


def time_hint(text: str, tz: str = "Asia/Yerevan") -> "int|None":
    """Время из списка на странице: «Այսօր - 23:38» (сегодня), «Երեկ - 10:05» (вчера) или обычная дата."""
    text = (text or "").strip()
    if not text:
        return None
    m = re.search(r"(\d{1,2}):(\d{2})", text)
    if m:
        now = datetime.now(ZoneInfo(tz))
        low = text.lower()
        if "Այսօր" in text or "today" in low or "Сегодня" in text:
            return min(int(now.replace(hour=int(m.group(1)), minute=int(m.group(2)), second=0, microsecond=0).timestamp()), int(time.time()))
        if "Երեկ" in text or "yesterday" in low or "Вчера" in text:
            d = now.replace(hour=int(m.group(1)), minute=int(m.group(2)), second=0, microsecond=0)
            return int(d.timestamp()) - 86400
    return parse_date(text, tz)


def dedup_key(mode: str, item: dict) -> str:
    """Из чего считаем «одна и та же статья» внутри источника."""
    if mode == "guid":
        return item.get("guid") or norm_url(item.get("url", ""))
    if mode == "title":
        return norm_text(item.get("title", ""))
    if mode == "title_date":
        return norm_text(item.get("title", "")) + "|" + time.strftime("%Y-%m-%d", time.gmtime(item.get("ts") or 0))
    if mode == "content":
        return norm_text(item.get("content", ""))
    return norm_url(item.get("url") or item.get("guid") or "")


def fmt_local(ts, tz: str = "Asia/Yerevan") -> str:
    if not ts:
        return ""
    return datetime.fromtimestamp(ts, ZoneInfo(tz)).strftime("%d %b %Y, %H:%M")


def fmt_span(s: int) -> str:
    if s < 3600:
        return f"{max(1, s // 60)} min"
    if s < 172800:
        return f"{s / 3600:.1f} h"
    return f"{s / 86400:.1f} days"
