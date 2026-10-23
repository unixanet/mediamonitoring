-- 007_crawler_runtime.sql: БД crawler. Журнал краулера, статус демона, дата старта источника необязательна.
SET NAMES utf8mb4;

-- NULL = скачивать всё, что есть в потоке
ALTER TABLE sources MODIFY min_published_at DATETIME NULL;

-- Журнал: ошибки, предупреждения и успешные забеги с новыми статьями. Храним 30 дней (чистит демон).
CREATE TABLE IF NOT EXISTS crawl_log (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  source_id    INT UNSIGNED NOT NULL,
  level        ENUM('error','warning','info') NOT NULL,
  event        VARCHAR(32) NOT NULL,     -- http_error, timeout, connection, ssl, parse_error, empty_feed, fetched
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

-- Пульс демона (одна строка), по нему админка показывает «Crawler running / offline»
CREATE TABLE IF NOT EXISTS crawler_status (
  id            TINYINT UNSIGNED NOT NULL,
  heartbeat_at  DATETIME NOT NULL,
  started_at    DATETIME NOT NULL,
  pid           INT UNSIGNED NULL,
  last_cycle_ms INT UNSIGNED NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
