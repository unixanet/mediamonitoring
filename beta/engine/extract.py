"""Заголовок, дата и текст статьи со страницы: trafilatura для текста, свои правила для заголовка, даты и канонического адреса."""
import json
import re
from urllib.parse import urljoin

import trafilatura
from lxml import html as lhtml

from .util import clean_url, host_of, parse_date

ARTICLE_TYPES = {"NewsArticle", "Article", "BlogPosting", "ReportageNewsArticle", "AnalysisNewsArticle", "OpinionNewsArticle", "LiveBlogPosting"}


def _jsonld(doc) -> dict:
    def find(n):
        if isinstance(n, dict):
            t = n.get("@type")
            ts = set(t if isinstance(t, list) else [t]) if t else set()
            if ts & ARTICLE_TYPES:
                return n
            for v in n.values():
                r = find(v)
                if r:
                    return r
        elif isinstance(n, list):
            for v in n:
                r = find(v)
                if r:
                    return r
        return None
    for s in doc.xpath("//script[@type='application/ld+json']"):
        try:
            r = find(json.loads((s.text or "").strip()))
        except Exception:
            continue
        if r:
            return r
    return {}


def _meta(doc, names) -> str:
    for n in names:
        v = doc.xpath(f"//meta[@property='{n}' or @name='{n}']/@content")
        if v and v[0].strip():
            return v[0].strip()
    return ""


def _select_text(doc, css: str) -> str:
    try:
        els = doc.cssselect(css)
    except Exception:
        return ""
    if not els:
        return ""
    el = els[0]
    for br in el.iter("br"):
        br.tail = "\n" + (br.tail or "")
    paras = [re.sub(r"\s+", " ", p.text_content()).strip() for p in el.xpath(".//p|.//h2|.//h3|.//li")]
    paras = [p for p in paras if p]
    return "\n".join(paras) if paras else re.sub(r"\s+", " ", el.text_content()).strip()


def extract_article(html: str, url: str, cfg: dict = None, final_url: str = "") -> dict:
    """{title, text, ts, canonical, extractor}. cfg: sel_title, sel_body, tz."""
    cfg = cfg or {}
    out = {"title": "", "text": "", "ts": None, "canonical": "", "extractor": "trafilatura"}
    try:
        doc = lhtml.fromstring(html or "<html></html>")
    except Exception:
        return out
    ld = _jsonld(doc)

    # заголовок
    if cfg.get("sel_title"):
        try:
            els = doc.cssselect(cfg["sel_title"])
            out["title"] = re.sub(r"\s+", " ", els[0].text_content()).strip() if els else ""
        except Exception:
            pass
    out["title"] = out["title"] or str(ld.get("headline") or "").strip() or _meta(doc, ["og:title", "twitter:title"])
    if not out["title"]:
        h1 = doc.xpath("//h1")
        out["title"] = re.sub(r"\s+", " ", h1[0].text_content()).strip() if h1 else ""
    if not out["title"]:
        t = doc.xpath("//title")
        out["title"] = t[0].text_content().strip() if t else ""

    # дата публикации
    d0 = str(ld.get("datePublished") or "") or _meta(doc, ["article:published_time", "og:published_time", "datePublished", "pubdate", "date"])
    if not d0:
        t = doc.xpath("//time/@datetime")
        d0 = t[0] if t else ""
    out["ts"] = parse_date(d0, cfg.get("tz", "UTC"))

    # канонический адрес (только с того же сайта: чужой canonical не принимаем)
    can = doc.xpath("//link[@rel='canonical']/@href") or []
    c = urljoin(final_url or url, can[0].strip()) if can else ""
    if not c:
        c = _meta(doc, ["og:url"])
    if c and c.startswith("http") and host_of(c) == host_of(final_url or url):
        out["canonical"] = clean_url(c)

    # текст
    if cfg.get("sel_body"):
        out["text"] = _select_text(doc, cfg["sel_body"])
        out["extractor"] = "selector"
    else:
        try:
            out["text"] = (trafilatura.extract(html, url=url or None, favor_recall=True, include_comments=False, include_tables=False) or "").strip()
        except Exception:
            out["text"] = ""
        if not out["text"] and ld.get("articleBody"):
            out["text"] = re.sub(r"[ \t]+", " ", re.sub(r"<[^>]+>", " ", str(ld["articleBody"]))).strip()
            out["extractor"] = "json-ld"
    if out["ts"] is None and not cfg.get("sel_body"):
        try:
            md = trafilatura.extract_metadata(html, default_url=url or None)
            if md and md.date:
                out["ts"] = parse_date(md.date, cfg.get("tz", "UTC"))
        except Exception:
            pass
    return out
