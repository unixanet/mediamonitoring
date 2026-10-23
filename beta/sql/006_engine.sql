-- 006_engine.sql: БД crawler. Движок: раздельные воркеры, здоровье, авто-интервал, часовой пояс, журнал изменений для Manticore.
-- Фото не храним: колонки с картинками удаляются.
SET NAMES utf8mb4;

ALTER TABLE articles DROP COLUMN image_url, DROP COLUMN image_path;
ALTER TABLE sources DROP COLUMN download_mode;

ALTER TABLE sources
  ADD COLUMN feed_timezone VARCHAR(40) NOT NULL DEFAULT 'Asia/Yerevan' AFTER fetch_full_text,      -- пояс для дат без указания зоны
  ADD COLUMN interval_mode ENUM('fixed','auto') NOT NULL DEFAULT 'fixed' AFTER check_interval_sec, -- auto: интервал подстраивается под частоту публикаций
  ADD COLUMN auto_interval_sec SMALLINT UNSIGNED NULL AFTER interval_mode,
  ADD COLUMN articles_7d INT UNSIGNED NOT NULL DEFAULT 0 AFTER unfinished_count,                   -- статей за 7 дней (считает демон)
  ADD COLUMN silent_since DATETIME NULL AFTER last_article_at;                                     -- источник «замолчал» с этого момента

ALTER TABLE articles
  ADD COLUMN revision SMALLINT UNSIGNED NOT NULL DEFAULT 0,      -- сколько раз статью правили после публикации
  ADD COLUMN updated_at DATETIME NULL,
  ADD COLUMN journal_at DATETIME NULL,                           -- когда статья впервые попала в журнал изменений
  ADD COLUMN claimed_by VARCHAR(16) NULL,                        -- воркер, взявший статью на дозагрузку
  ADD KEY idx_fetched (fetched_at);

ALTER TABLE url_queue ADD COLUMN claimed_by VARCHAR(16) NULL;

-- Журнал изменений: по нему Manticore догоняет БД (новые, дозагруженные, исправленные, удалённые статьи)
CREATE TABLE IF NOT EXISTS article_changes (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  article_id BIGINT UNSIGNED NOT NULL,
  op         ENUM('upsert','delete') NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Вежливость к сайтам: один «слот» запроса на хост (общий для всех воркеров)
CREATE TABLE IF NOT EXISTS host_slots (
  slot_key VARCHAR(190) NOT NULL,
  next_at  DATETIME(3) NOT NULL,
  PRIMARY KEY (slot_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Несколько процессов: опрос лент (poller) и воркеры полного текста
ALTER TABLE crawler_status
  ADD COLUMN role VARCHAR(20) NOT NULL DEFAULT 'poller' AFTER id,
  ADD COLUMN jobs_done BIGINT UNSIGNED NOT NULL DEFAULT 0;
