# Письмо издателю: просьба внести наш сервер в белый список

Использовать, когда сайт за Cloudflare (или похожей защитой) блокирует наш краулер. Подставьте IP сервера и контакт.

## English

Subject: Request to allow the Media Monitoring crawler (MediaMonitoringBot)

Hello,

We operate a media monitoring platform (mediamonitoring.am) used by government bodies, businesses and embassies. We read your public news feed, sitemap and article pages so that our clients can find mentions of their topics. Every item links back to your original article.

Your site currently shows our crawler a Cloudflare challenge. Could you please let our server through?

- Server IP address: [SERVER IP]
- User-Agent: Mozilla/5.0 (compatible; MediaMonitoringBot/1.0; +https://beta.mediamonitoring.am/bot.php)
- Information page about the crawler: https://beta.mediamonitoring.am/bot.php

In Cloudflare this is a custom WAF rule, expression `ip.src eq [SERVER IP]`, action Skip (skip the remaining custom rules, rate limiting, managed rules and Bot Fight Mode). Details: https://developers.cloudflare.com/waf/custom-rules/use-cases/allow-traffic-from-ips-in-allowlist/

What we do: we honour robots.txt, send at most one request per second to your host, use conditional requests for feeds, and never log in or bypass paywalls. If our crawler causes any load or you want it to stop, reply to this message and we will remove your site immediately.

Thank you,
[NAME], [POSITION]
Media Monitoring, [CONTACT E-MAIL]

## Русский

Тема: Просьба пропустить краулер Media Monitoring (MediaMonitoringBot)

Здравствуйте!

Мы эксплуатируем платформу медиамониторинга (mediamonitoring.am), которой пользуются госструктуры, бизнес и посольства. Мы читаем вашу открытую ленту новостей, карту сайта и страницы статей, чтобы клиенты находили упоминания своих тем. Каждая запись ссылается на вашу оригинальную статью.

Сейчас ваш сайт показывает нашему краулеру проверку Cloudflare. Просим пропускать наш сервер:

- IP сервера: [SERVER IP]
- User-Agent: Mozilla/5.0 (compatible; MediaMonitoringBot/1.0; +https://beta.mediamonitoring.am/bot.php)
- Страница о краулере: https://beta.mediamonitoring.am/bot.php

В Cloudflare это пользовательское правило WAF с выражением `ip.src eq [SERVER IP]` и действием Skip (пропустить остальные правила, лимиты, управляемые правила и Bot Fight Mode).

Что мы делаем: соблюдаем robots.txt, шлём не больше одного запроса в секунду на ваш хост, используем условные запросы для лент, не авторизуемся и не обходим платный доступ. Если краулер создаёт нагрузку или вы хотите его остановить, ответьте на это письмо, и мы сразу уберём ваш сайт.

С уважением,
[ИМЯ], [ДОЛЖНОСТЬ]
Media Monitoring, [E-MAIL]

## Заявка на статус Verified Bot (Cloudflare)

Статус даёт белый список на всех сайтах Cloudflare. Условия: прозрачный бот с публичным описанием (наш `/bot.php`), список IP и проверка по обратному DNS или подпись запросов (Web Bot Auth, RFC 9421). Заявка подаётся в панели Cloudflare (раздел Bots, заявка в каталог ботов). Перед заявкой: заполнить `bot_contact` и `bot_ips` в `config.php`, для источников выбрать профиль «Honest bot».
