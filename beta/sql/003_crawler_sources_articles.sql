-- 003_crawler_sources_articles.sql: БД crawler. Все даты в UTC.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS sources (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name               VARCHAR(190) NOT NULL,
  type               ENUM('rss','html','social') NOT NULL DEFAULT 'rss',
  feed_url           VARCHAR(1024) NOT NULL,
  feed_url_hash      CHAR(32) NOT NULL,                       -- md5(feed_url), уникальность
  download_mode      ENUM('text','text_photo') NOT NULL DEFAULT 'text',
  dedup_by           ENUM('url','guid') NOT NULL DEFAULT 'url', -- чем определять дубль внутри источника
  min_published_at   DATETIME NOT NULL,                        -- не скачивать статьи старше
  check_interval_sec SMALLINT UNSIGNED NOT NULL DEFAULT 120,
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
  -- счётчики (денормализация, чтобы список источников читался мгновенно)
  articles_count     INT UNSIGNED NOT NULL DEFAULT 0,          -- за всё время, при архивации не обнуляется
  last_article_at    DATETIME NULL,                            -- когда скачана последняя статья
  extra              JSON NULL,                                -- доп. настройки источника (расширяемо)
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_name (name),
  UNIQUE KEY uq_feed_url_hash (feed_url_hash),
  KEY idx_due (is_active, next_check_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Статьи текущего года. Без FULLTEXT: поиск делает Manticore.
CREATE TABLE IF NOT EXISTS articles (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  source_id    INT UNSIGNED NOT NULL,
  dedup_hash   BINARY(16) NOT NULL,          -- md5(нормализованный URL или guid)
  url          VARCHAR(2048) NOT NULL,
  title        VARCHAR(1000) NOT NULL,
  content      MEDIUMTEXT NOT NULL,          -- очищенный текст (strip_tags, схлопнутые пробелы)
  image_url    VARCHAR(2048) NULL,           -- только если source.download_mode = text_photo
  image_path   VARCHAR(255) NULL,
  published_at DATETIME NOT NULL,
  fetched_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  content_hash BINARY(16) NOT NULL,          -- md5(title+content), для обнаружения правок
  PRIMARY KEY (id),
  UNIQUE KEY uq_source_dedup (source_id, dedup_hash),  -- один источник не скачает статью дважды
  KEY idx_source_published (source_id, published_at),
  KEY idx_published (published_at)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Сырой RSS-элемент (gzip) для перепарсинга; отдельно, чтобы articles оставалась лёгкой.
CREATE TABLE IF NOT EXISTS article_raw (
  article_id BIGINT UNSIGNED NOT NULL,
  raw        MEDIUMBLOB NOT NULL,
  PRIMARY KEY (article_id)
) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
