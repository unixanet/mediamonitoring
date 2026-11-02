-- Дубликаты: одна и та же статья на ОДНОМ сайте (тот же заголовок И тот же текст) хранится один раз, в любой день.
-- Тот же заголовок с другим текстом это другая статья. Сравнение текста делает движок (engine/core.py, find_dup).
-- На разных сайтах одинаковые статьи хранятся все, это нужно для подсчёта упоминаний в СМИ.
-- host_hash: md5 от сайта (домен без www; для t.me и facebook ещё и канал или страница).
-- title_hash: md5 от заголовка без знаков препинания и регистра.
-- Старые строки остаются с NULL (в уникальном ключе NULL не конфликтуют).
ALTER TABLE articles
  ADD COLUMN host_hash BINARY(16) NULL AFTER dedup_hash,
  ADD COLUMN title_hash BINARY(16) NULL AFTER host_hash,
  ADD KEY idx_host_title (host_hash, title_hash);
