"""Определение способа скачивания по адресу: RSS, sitemap (через robots.txt), список ссылок на странице, Telegram."""
import json
import re
import time
from urllib.parse import urljoin, urlparse

from lxml import html as lhtml

from .extract import extract_article
from .feeds import parse_feed, parse_sitemap
from .fetcher import classify, is_block
from .listing import detect_listing
from .telegram import looks_like_telegram, parse_page as tg_parse_page, username as tg_username
from .util import decode_body, fmt_local

COMMON_FEEDS = ["/rss", "/rss.xml", "/feed", "/feed/", "/atom.xml"]
COMMON_MAPS = ["/sitemap_index.xml", "/sitemap.xml", "/news-sitemap.xml", "/sitemap-news.xml"]


def _items_feed(parsed, tz, limit=12):
    parsed = sorted(parsed, key=lambda i: i["ts"] or 0, reverse=True)[:limit]
    return [{"title": i["title"], "url": i["url"], "date": fmt_local(i["ts"], tz), "chars": len(i["content"])} for i in parsed]


async def detect(engine, raw: str, method: str = "auto", profile: str = "browser", tz: str = "Asia/Yerevan") -> dict:
    t0 = time.time()
    left = lambda: 80 - (time.time() - t0)
    src = {"id": 0, "type": "rss", "fetch_method": method, "fetch_profile": profile, "feed_timezone": tz, "state": None, "respect_robots": 0}
    raw = (raw or "").strip()
    cands, notes = [], []

    def finish(extra=None):
        order = {"rss": 3, "sitemap": 2, "listing": 1, "telegram": 4}
        cands.sort(key=lambda c: (-order[c["kind"]], -c["count"]))
        if cands:
            cands[0]["recommended"] = True
        out = {"candidates": cands, "notes": notes, "via": "browser" if engine.fetcher.browser_active(src) else "direct", "ms": int((time.time() - t0) * 1000)}
        if not cands:
            out["error"] = "Nothing usable was found at this address: no feed, no sitemap and no list of article links."
        out.update(extra or {})
        return out

    if looks_like_telegram(raw):
        u = "https://t.me/s/" + tg_username(raw)
        r = await engine.fetcher.fetch(u, src, "page", force=True)
        f = classify(r)
        if f:
            return finish({"error": f[1], "event": f[0]})
        items, _ = tg_parse_page(decode_body(r.body, r.ctype))
        if not items:
            return finish({"error": f"No posts found. The channel must be public with web preview enabled: {u} must show posts in a browser."})
        cands.append({"kind": "telegram", "label": "Telegram channel", "url": u, "count": len(items), "items": _items_feed(items, tz), "config": {}})
        return finish()
    if re.search(r"(facebook|fb)\.com", raw, re.I):
        return finish({"error": "Facebook cannot be read directly: it requires login and blocks servers. Paste the address of a feed for the page if you have one."})

    url = raw if re.match(r"^https?://", raw, re.I) else "https://" + raw
    p = urlparse(url)
    if not p.netloc or "." not in p.netloc and "localhost" not in p.netloc and not re.match(r"^[\d.:]+$", p.netloc):
        return finish({"error": "Enter a valid address, for example news.am"})
    origin = f"{p.scheme}://{p.netloc}"
    r = await engine.fetcher.fetch(url, src, "page", force=True)
    f = classify(r)
    if f:
        return finish({"error": f[1], "event": f[0], "can_try_browser": method != "browser" and is_block(f, r)})
    body_head = r.body[:300].lstrip(b"\xef\xbb\xbf \t\r\n").lower()
    is_xml = body_head.startswith((b"<?xml", b"<rss", b"<feed", b"<rdf", b"<urlset", b"<sitemapindex"))

    async def sitemap_cand(mu, body):
        kind, entries = parse_sitemap(body)
        if not kind:
            return None
        note = ""
        if kind == "index":
            entries.sort(key=lambda e: e["ts"] or 0, reverse=True)
            if not entries or not entries[0]["loc"]:
                return None
            note = f"{len(entries)} sitemaps in the index; reading the newest: {entries[0]['loc']}"
            r2 = await engine.fetcher.fetch(entries[0]["loc"], src, "feed", force=True)
            if classify(r2):
                return None
            k2, e2 = parse_sitemap(r2.body)
            if k2 != "urlset":
                return None
            entries = e2
        entries.sort(key=lambda e: e["pub"] or e["ts"] or 0, reverse=True)
        items = []
        for i, e in enumerate(entries[:8]):
            d0 = e["pub"] or e["ts"]
            row = {"title": e["title"], "url": e["loc"], "date": fmt_local(d0, tz), "chars": None}
            if i < 2 and left() > 12:                    # у первых двух открываем страницу: показать, что заголовок и текст извлекаются
                s2 = dict(src)
                if s2["fetch_method"] == "auto" and not engine.fetcher.browser_active(src):
                    s2["fetch_method"] = "http"          # образцы страниц: без долгого ожидания проверки
                s2["browser_timeout"] = 15
                pr = await engine.fetcher.fetch(e["loc"], s2, "page", force=True)
                pf = classify(pr)
                if not pf:
                    ex = extract_article(decode_body(pr.body, pr.ctype), e["loc"], {"tz": tz}, pr.final_url)
                    row["title"] = row["title"] or ex["title"]
                    row["chars"] = len(ex["text"])
                else:
                    row["title"] = row["title"] or f"(page not available: {pf[1]})"
            items.append(row)
        if any(e["title"] for e in entries):
            note = (note + " News sitemap: headline and date come from the map.").strip()
        return {"kind": "sitemap", "label": "Sitemap", "url": mu, "count": len(entries), "items": items, "config": {}, "note": note}

    def feed_cand(fu, parsed):
        return {"kind": "rss", "label": "RSS feed", "url": fu, "count": len(parsed), "items": _items_feed(parsed, tz), "config": {},
                "avgchars": int(sum(len(i["content"]) for i in parsed) / max(1, len(parsed)))}

    if is_xml:
        parsed = parse_feed(r.body, tz)
        if parsed:
            cands.append(feed_cand(url, parsed))
        c = await sitemap_cand(url, r.body)
        if c:
            cands.append(c)
        return finish()

    html = decode_body(r.body, r.ctype)
    # 1) список ссылок на самой странице
    lst = detect_listing(html, r.final_url or url)
    if lst:
        cands.append({"kind": "listing", "label": "Page list", "url": url, "count": len(lst["items"]),
                      "items": [{"title": i["title"], "url": i["url"], "date": i["time"], "chars": None} for i in lst["items"][:12]],
                      "config": {"list_container": lst["rule"]["container"], "list_pattern": lst["rule"]["pattern"]},
                      "note": "Links found on this page" + (f" inside {lst['rule']['container']}" if lst["rule"]["container"] else "") + ". Each article page is opened for the full text."})
    # 2) RSS: ссылки в коде страницы, затем типичные адреса
    try:
        doc = lhtml.fromstring(html)
        tries = [urljoin(url, h) for h in doc.xpath("//link[@type='application/rss+xml' or @type='application/atom+xml']/@href")]
    except Exception:
        tries = []
    tries += [origin + x for x in COMMON_FEEDS]
    seen = set()
    for fu in tries:
        if fu in seen or left() < 25 or len(seen) >= 6:
            continue
        seen.add(fu)
        fr = await engine.fetcher.fetch(fu, src, "feed", force=True)
        if classify(fr):
            continue
        parsed = parse_feed(fr.body, tz)
        if parsed:
            cands.append(feed_cand(fu, parsed))
            break
    # 3) sitemap: robots.txt, затем типичные адреса
    maps = []
    rr = await engine.fetcher.fetch(origin + "/robots.txt", src, "page", force=True)
    if not classify(rr):
        found = re.findall(r"(?im)^\s*sitemap:\s*(\S+)", decode_body(rr.body, rr.ctype))
        maps += found
        notes.append(f"robots.txt lists {len(found)} sitemap(s)." if found else "robots.txt has no sitemap lines.")
    else:
        notes.append(f"robots.txt is not available ({classify(rr)[1]}).")
    maps += [origin + x for x in COMMON_MAPS]
    seen_m = set()
    for mu in maps:
        if mu in seen_m or left() < 20 or len(seen_m) >= 4:
            continue
        seen_m.add(mu)
        mr = await engine.fetcher.fetch(mu, src, "feed", force=True)
        if classify(mr):
            continue
        c = await sitemap_cand(mu, mr.body)
        if c:
            cands.append(c)
            break
    return finish()
