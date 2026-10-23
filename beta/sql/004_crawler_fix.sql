-- 004_crawler_fix.sql: БД crawler. Выравнивает структуру на серверах, где таблицы созданы по старым черновикам.
-- Безопасно запускать повторно.
ALTER TABLE sources
  MODIFY min_published_at DATETIME NULL,
  MODIFY dedup_by ENUM('url','guid','title','title_date','content') NOT NULL DEFAULT 'url';
