-- 004_crawler_categories.sql: БД crawler. Категории источников.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS categories (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name       VARCHAR(120) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE sources
  ADD COLUMN category_id INT UNSIGNED NULL AFTER name,
  ADD KEY idx_category (category_id);
