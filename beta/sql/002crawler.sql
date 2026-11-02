-- 002crawler.sql: БД crawler. Полная структура (все прежние черновики свёрнуты сюда). Все даты в UTC.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS categories (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name       VARCHAR(120) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sources (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name               VARCHAR(190) NOT NULL,
  category_id        INT UNSIGNED NULL,
  type               ENUM('rss','sitemap','telegram','facebook') NOT NULL DEFAULT 'rss',
  feed_url           VARCHAR(1024) NOT NULL,                       -- адрес ленты / sitemap / https://t.me/s/<username>
  feed_url_hash      CHAR(32) NOT NULL,                            -- md5(feed_url), уникальность
  fetch_method       ENUM('auto','http','browser') NOT NULL DEFAULT 'auto', -- auto: обычный запрос, при блокировке настоящий браузер
  fetch_profile      ENUM('browser','bot') NOT NULL DEFAULT 'browser',      -- заголовки обычного запроса
  fetch_full_text    TINYINT(1) NOT NULL DEFAULT 0,                -- открывать страницы статей за полным текстом
  feed_timezone      VARCHAR(40) NOT NULL DEFAULT 'Asia/Yerevan',  -- пояс для дат без указания зоны
  dedup_by           ENUM('url','guid','title','title_date','content') NOT NULL DEFAULT 'url',
  min_published_at   DATETIME NULL,                                -- не скачивать статьи старше; NULL = всё в потоке
  check_interval_sec SMALLINT UNSIGNED NOT NULL DEFAULT 120,
  interval_mode      ENUM('fixed','auto') NOT NULL DEFAULT 'fixed',
  auto_interval_sec  SMALLINT UNSIGNED NULL,
  is_active          TINYINT(1) NOT NULL DEFAULT 1,
  -- состояние опроса (пишет краулер)
  etag               VARCHAR(255) NULL,
  last_modified      VARCHAR(64)  NULL,
  next_check_at      DATETIME NULL,
  last_checked_at    DATETIME NULL,
  last_success_at    DATETIME NULL,
  last_http_code     SMALLINT UNSIGNED NULL,
  last_response_ms   INT UNSIGNED NULL,
  last_error         VARCHAR(500) NULL,
  consecutive_errors INT UNSIGNED NOT NULL DEFAULT 0,
  -- счётчики (денормализация, список источников читается мгновенно)
  articles_count     INT UNSIGNED NOT NULL DEFAULT 0,              -- за всё время, при архивации не обнуляется
  unfinished_count   INT UNSIGNED NOT NULL DEFAULT 0,              -- статьи без полного текста
  articles_7d        INT UNSIGNED NOT NULL DEFAULT 0,
  last_article_at    DATETIME NULL,
  silent_since       DATETIME NULL,
  extra              JSON NULL,                                    -- настройки (sitemap, селекторы, история Telegram)
  state              JSON NULL,                                    -- служебное состояние краулера (sitemap, browser_until)
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_name (name),
  UNIQUE KEY uq_feed_url_hash (feed_url_hash),
  KEY idx_due (is_active, next_check_at),
  KEY idx_category (category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Статьи текущего года. Без FULLTEXT: поиск делает Manticore. Только текст и даты, фото не храним.
CREATE TABLE IF NOT EXISTS articles (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  source_id    INT UNSIGNED NOT NULL,
  dedup_hash   BINARY(16) NOT NULL,
  url          VARCHAR(2048) NOT NULL,        -- ОРИГИНАЛЬНАЯ ссылка на статью (для отчётов и кнопки Open original)
  title        VARCHAR(1000) NOT NULL,
  content      MEDIUMTEXT NOT NULL,
  published_at DATETIME NOT NULL,
  fetched_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  content_hash BINARY(16) NOT NULL,           -- md5(title+content), для обнаружения правок
  content_status ENUM('full','pending','partial','title_only') NOT NULL DEFAULT 'full',
  fetch_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  next_retry_at  DATETIME NULL,
  fail_reason    VARCHAR(255) NULL,
  revision       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at     DATETIME NULL,
  journal_at     DATETIME NULL,
  claimed_by     VARCHAR(16) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_source_dedup (source_id, dedup_hash),            -- один источник не скачает статью дважды
  KEY idx_source_published (source_id, published_at),
  KEY idx_published (published_at),
  KEY idx_fetched (fetched_at),
  KEY idx_retry (content_status, next_retry_at),
  KEY idx_source_status (source_id, content_status)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Сырой RSS-элемент (gzip) для перепарсинга, хранится 30 дней
CREATE TABLE IF NOT EXISTS article_raw (
  article_id BIGINT UNSIGNED NOT NULL,
  raw        MEDIUMBLOB NOT NULL,
  PRIMARY KEY (article_id)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Очередь страниц статей, найденных в sitemap (30 дней)
CREATE TABLE IF NOT EXISTS url_queue (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  source_id   INT UNSIGNED NOT NULL,
  url_hash    BINARY(16) NOT NULL,
  url         VARCHAR(2048) NOT NULL,
  lastmod     DATETIME NULL,
  status      ENUM('pending','done','skipped','failed') NOT NULL DEFAULT 'pending',
  attempts    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  next_try_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_error  VARCHAR(255) NULL,
  claimed_by  VARCHAR(16) NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_source_url (source_id, url_hash),
  KEY idx_pending (status, next_try_at),
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Журнал краулера (30 дней, чистит демон)
CREATE TABLE IF NOT EXISTS crawl_log (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  source_id    INT UNSIGNED NOT NULL,
  level        ENUM('error','warning','info') NOT NULL,
  event        VARCHAR(32) NOT NULL,
  http_code    SMALLINT UNSIGNED NULL,
  duration_ms  INT UNSIGNED NULL,
  new_articles INT UNSIGNED NOT NULL DEFAULT 0,
  message      VARCHAR(500) NOT NULL,
  details      TEXT NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_created (created_at),
  KEY idx_source_created (source_id, created_at),
  KEY idx_level_created (level, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Журнал изменений: по нему Manticore догоняет БД (новые, дозагруженные, исправленные, удалённые статьи)
CREATE TABLE IF NOT EXISTS article_changes (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  article_id BIGINT UNSIGNED NOT NULL,
  op         ENUM('upsert','delete') NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Вежливость к сайтам: один «слот» запроса на хост (общий для всех процессов)
CREATE TABLE IF NOT EXISTS host_slots (
  slot_key VARCHAR(190) NOT NULL,
  next_at  DATETIME(3) NOT NULL,
  PRIMARY KEY (slot_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Пульс процессов (poller, воркеры полного текста)
CREATE TABLE IF NOT EXISTS crawler_status (
  id            TINYINT UNSIGNED NOT NULL,
  role          VARCHAR(20) NOT NULL DEFAULT 'poller',
  heartbeat_at  DATETIME NOT NULL,
  started_at    DATETIME NOT NULL,
  pid           INT UNSIGNED NULL,
  last_cycle_ms INT UNSIGNED NULL,
  jobs_done     BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
