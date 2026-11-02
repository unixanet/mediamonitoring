"""Настройки движка: engine/config.json (путь можно переопределить переменной MM_ENGINE_CONFIG)."""
import json
import os

_path = os.environ.get("MM_ENGINE_CONFIG") or os.path.join(os.path.dirname(os.path.abspath(__file__)), "config.json")
with open(_path, encoding="utf-8") as _f:
    CFG = json.load(_f)

DB = CFG["db"]
API_PORT = int(CFG.get("api_port", 8765))
BROWSER = CFG.get("browser", {})
POLL_CONCURRENCY = int(CFG.get("poll_concurrency", 20))
PAGE_CONCURRENCY = int(CFG.get("page_concurrency", 8))

BOT_UA = "Mozilla/5.0 (compatible; MediaMonitoringBot/1.0; +https://beta.mediamonitoring.am/bot.php)"
ROBOTS_TOKEN = "mediamonitoringbot"
FT_MAX_ATTEMPTS = 5          # попыток открыть страницу статьи
FT_MIN_LEN = 30              # страница из карты или списка короче этого считается пустой
RAW_DAYS = 30                # сырой RSS хранится 30 дней
JOURNAL_DAYS = 30
PENDING_WAIT = 600           # через 10 минут без полного текста статья всё равно уходит в журнал
