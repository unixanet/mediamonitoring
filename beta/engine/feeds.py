"""RSS/Atom через feedparser (терпимый к кривому XML) и карты сайта (sitemap)."""
import gzip
import json
import time

import feedparser
from lxml import etree as ET

from .util import html_to_text, parse_date

NEWS_NS = "http://www.google.com/schemas/sitemap-news/0.9"


def parse_feed(body: bytes, tz: str = "UTC") -> "list|None":
    """Список статей ленты или None, если это не лента."""
    d = feedparser.parse(body)
    if not d.version:
        return None
    items = []
    for e in d.entries:
        link = (e.get("link") or "").strip()
        raw_date = e.get("published") or e.get("updated") or e.get("created") or ""
        ts = parse_date(raw_date, tz)
        if not ts and e.get("published_parsed"):
            import calendar
            ts = calendar.timegm(e.published_parsed)
        html = ""
        if e.get("content"):
            html = e["content"][0].get("value", "")
        if not html:
            html = e.get("summary", "") or e.get("description", "")
        try:
            raw = json.dumps(dict(e), default=str, ensure_ascii=False)[:30000]
        except Exception:
            raw = ""
        items.append({
            "title": html_to_text(e.get("title", "")),
            "url": link,
            "guid": (e.get("id") or "").strip() or link,
            "ts": ts or int(time.time()),
            "content": html_to_text(html),
            "raw": raw,
        })
    return items


def _local(tag) -> str:
    return tag.rsplit("}", 1)[-1] if isinstance(tag, str) else ""


def _child_text(el, name: str, ns: str = None) -> str:
    for c in el:
        if _local(c.tag) == name and (ns is None or c.tag.startswith("{" + ns + "}")):
            return (c.text or "").strip()
    return ""


def parse_sitemap(body: bytes) -> tuple:
    """(kind, entries): kind = index | urlset | None. entries: {loc, ts, title, pub}."""
    if body[:2] == b"\x1f\x8b":
        try:
            body = gzip.decompress(body)
        except Exception:
            pass
    try:
        root = ET.fromstring(body.lstrip(b"\xef\xbb\xbf \t\r\n"), ET.XMLParser(recover=True, huge_tree=True, resolve_entities=False))
    except Exception:
        return None, []
    if root is None:
        return None, []
    kind = {"sitemapindex": "index", "urlset": "urlset"}.get(_local(root.tag))
    if not kind:
        return None, []
    out = []
    for el in root:
        if _local(el.tag) not in ("sitemap", "url"):
            continue
        e = {"loc": _child_text(el, "loc"), "ts": parse_date(_child_text(el, "lastmod"), "UTC"), "title": "", "pub": None}
        for c in el:
            if _local(c.tag) == "news" and c.tag.startswith("{" + NEWS_NS + "}"):        # Google News sitemap: заголовок и дата публикации
                e["title"] = _child_text(c, "title", NEWS_NS)
                e["pub"] = parse_date(_child_text(c, "publication_date", NEWS_NS), "UTC")
        out.append(e)
    return kind, out
