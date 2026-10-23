-- 003_fulltext_sitemap.sql: БД crawler. Полный текст статей (статусы, повторы), тип источника Sitemap, очередь URL.
SET NAMES utf8mb4;

ALTER TABLE sources
  MODIFY type ENUM('rss','sitemap','html','social') NOT NULL DEFAULT 'rss',
  ADD COLUMN fetch_profile   ENUM('browser','bot') NOT NULL DEFAULT 'browser' AFTER download_mode,  -- заголовки «как браузер» или честный бот
  ADD COLUMN fetch_full_text TINYINT(1) NOT NULL DEFAULT 1 AFTER fetch_profile,                      -- открывать страницу статьи за полным текстом
  ADD COLUMN unfinished_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER articles_count,                  -- статьи без полного текста
  ADD COLUMN state JSON NULL AFTER extra;                                                            -- служебное состояние краулера (sitemap)

-- full = полный текст; pending = ждёт/повторяется; partial = только заголовок + анонс; title_only = только заголовок
ALTER TABLE articles
  ADD COLUMN content_status ENUM('full','pending','partial','title_only') NOT NULL DEFAULT 'full' AFTER content_hash,
  ADD COLUMN fetch_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER content_status,
  ADD COLUMN next_retry_at DATETIME NULL AFTER fetch_attempts,
  ADD COLUMN fail_reason VARCHAR(255) NULL AFTER next_retry_at,
  ADD KEY idx_retry (content_status, next_retry_at),
  ADD KEY idx_source_status (source_id, content_status);

-- Очередь страниц статей, найденных в sitemap (хранится 30 дней)
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
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_source_url (source_id, url_hash),
  KEY idx_pending (status, next_try_at),
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
