"""Список ссылок на странице (таймлайн): автоопределение блока и шаблона ссылок, извлечение ссылок по правилу."""
import re
from urllib.parse import urljoin, urlparse

from lxml import html as lhtml

from .util import host_of


def parse_html(html: str):
    try:
        return lhtml.fromstring(html or "<html></html>")
    except Exception:
        return lhtml.fromstring("<html></html>")


def anchor_text(a) -> str:
    """Текст ссылки без вложенных <time>, скриптов и стилей."""
    parts = a.xpath(".//text()[not(ancestor::time) and not(ancestor::script) and not(ancestor::style) and not(ancestor::svg)]")
    return re.sub(r"\s+", " ", "".join(parts)).strip()


def _seg(s: str) -> str:
    if re.search(r"\d{3,}", s):
        return "N"
    if len(s) > 22 or s.count("-") >= 2 or re.search(r"%[0-9A-Fa-f]{2}", s):
        return "S"
    return "W"


def shape(url: str) -> str:
    path = (urlparse(url).path or "").strip("/")
    return "/".join(_seg(s) for s in path.split("/")) if path else ""


def shape_regex(sh: str) -> str:
    m = {"N": r"\d[\w.-]*", "S": r"[^/]+", "W": r"[^/]+"}
    return "^/" + "/".join(m[t] for t in sh.split("/")) + "/?$"


def selector_of(el) -> str:
    """Короткий CSS-селектор элемента: tag#id или tag.class1.class2."""
    tag = el.tag if isinstance(el.tag, str) else "div"
    i = el.get("id") or ""
    if i and re.fullmatch(r"[\w-]+", i) and not re.search(r"\d{4,}", i):
        return f"{tag}#{i}"
    cls = [c for c in (el.get("class") or "").split() if re.fullmatch(r"[A-Za-z][\w-]*", c) and not re.search(r"\d{3,}", c)]
    return tag + ("." + ".".join(cls[:2]) if cls else "")


def extract_links(html: str, page_url: str, rule: dict) -> list:
    """Ссылки по правилу {container, pattern} → [{url, title, time}]."""
    doc = parse_html(html)
    anchors = []
    cont = (rule.get("container") or "").strip()
    if cont:
        try:
            for el in doc.cssselect(cont):
                anchors.extend(el.xpath(".//a[@href]"))
        except Exception:
            anchors = []
    if not anchors:
        anchors = doc.xpath("//a[@href]")           # контейнер не найден: ищем по всей странице
    try:
        rx = re.compile(rule.get("pattern") or ".")
    except re.error:
        rx = re.compile(".")
    host = host_of(page_url)
    seen, out = set(), []
    for a in anchors:
        u = urljoin(page_url, (a.get("href") or "").strip())
        if not u.startswith("http") or host_of(u) != host:
            continue
        path = urlparse(u).path or "/"
        if not rx.search(path):
            continue
        key = host + path
        title = anchor_text(a)
        if key in seen or len(title) < 8:
            continue
        seen.add(key)
        t = a.xpath(".//time")
        out.append({"url": u, "title": title[:500], "time": (t[0].text_content().strip() if t else "")})
    return out


def detect_listing(html: str, page_url: str) -> "dict|None":
    """Найти на странице повторяющиеся ссылки на статьи. Возвращает {rule, items} или None."""
    doc = parse_html(html)
    for bad in doc.xpath("//script|//style|//noscript|//nav|//footer"):
        bad.getparent().remove(bad) if bad.getparent() is not None else None
    host = host_of(page_url)
    groups = {}
    for a in doc.xpath("//a[@href]"):
        u = urljoin(page_url, (a.get("href") or "").strip())
        if not u.startswith("http") or host_of(u) != host:
            continue
        title = anchor_text(a)
        if not 15 <= len(title) <= 400:
            continue
        sh = shape(u)
        if sh in ("", "W"):
            continue                                  # главная и разделы
        key = host + (urlparse(u).path or "")
        groups.setdefault(sh, {})[key] = (a, u, title)
    best, best_score = None, 0
    for sh, g in groups.items():
        n = len(g)
        if n < 6:
            continue
        avg = sum(len(v[2]) for v in g.values()) / n
        score = n * min(avg, 80) * (1.4 if "N" in sh else 1.0)
        if score > best_score:
            best, best_score = (sh, g), score
    if not best:
        return None
    sh, g = best
    nodes = [v[0] for v in g.values()]
    need = -(-len(nodes) * 3 // 4)                    # не меньше 75% ссылок внутри контейнера
    container = ""
    mid = nodes[len(nodes) // 2]
    for anc in mid.iterancestors():
        if anc.tag in ("body", "html"):
            break
        inside = sum(1 for n in nodes if anc in n.iterancestors())
        if inside < need:
            continue
        sel = selector_of(anc)
        if "." in sel or "#" in sel:
            container = sel
            break
    rule = {"container": container, "pattern": shape_regex(sh)}
    items = extract_links(html, page_url, rule)
    if len(items) < 6:
        rule["container"] = ""
        items = extract_links(html, page_url, rule)
    return {"rule": rule, "items": items} if len(items) >= 6 else None
