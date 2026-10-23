-- 007_telegram_facebook.sql: БД crawler. Новые типы источников: telegram (публичные каналы через t.me/s/<username>)
-- и facebook (публичные страницы и группы через RSS-сервис, например rss.app).
ALTER TABLE sources MODIFY type ENUM('rss','sitemap','html','social','telegram','facebook') NOT NULL DEFAULT 'rss';
