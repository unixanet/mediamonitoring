"""Публичные каналы Telegram через веб-превью https://t.me/s/<username>."""
import re

from lxml import html as lhtml

from .util import parse_date

TOK = "contains(concat(' ',normalize-space(@class),' '),' %s ')"


def username(v: str) -> "str|None":
    v = (v or "").strip()
    v = re.sub(r"^(https?://)?(www\.)?t\.me/(s/)?", "", v, flags=re.I)
    v = re.sub(r"[/?].*$", "", v).lstrip("@")
    return v if re.fullmatch(r"[A-Za-z][A-Za-z0-9_]{4,31}", v) else None


def looks_like_telegram(v: str) -> bool:
    v = (v or "").strip()
    return bool(username(v)) and (v.startswith("@") or "t.me/" in v.lower())


def parse_page(html: str) -> tuple:
    """(посты, наименьший id на странице). Посты без текста пропускаются; цитаты-ответы в текст не попадают."""
    doc = lhtml.fromstring(html or "<html></html>")
    items, min_id = [], 0
    for m in doc.xpath(f"//div[@data-post and {TOK % 'tgme_widget_message'}]"):
        chan, _, pid = m.get("data-post", "").partition("/")
        if not pid.isdigit():
            continue
        min_id = min(min_id, int(pid)) if min_id else int(pid)
        nodes = m.xpath(f".//div[{TOK % 'tgme_widget_message_text'} and not(ancestor::a[{TOK % 'tgme_widget_message_reply'}])]")
        if not nodes:
            continue
        node = nodes[0]
        for br in node.iter("br"):
            br.tail = "\n" + (br.tail or "")
        lines = [re.sub(r"[ \t ]+", " ", ln).strip() for ln in node.text_content().split("\n")]
        text = re.sub(r"\n{3,}", "\n\n", "\n".join(lines)).strip()
        if not text:
            continue
        dt = m.xpath(f".//a[{TOK % 'tgme_widget_message_date'}]/time/@datetime")
        first = text.split("\n", 1)[0].strip()
        items.append({
            "title": first if len(first) <= 140 else first[:139].rstrip() + "…",
            "url": f"https://t.me/{chan}/{pid}", "guid": f"{chan}/{pid}",
            "ts": parse_date(dt[0], "UTC") if dt else None, "content": text,
            "raw": lhtml.tostring(m, encoding="unicode")[:30000],
        })
    return items, min_id
