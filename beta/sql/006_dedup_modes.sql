-- 006_dedup_modes.sql: БД crawler. Новые способы определения дубля внутри источника.
-- url        нормализованный URL статьи (без UTM и якорей)
-- guid       GUID из RSS
-- title      нормализованный заголовок (регистр, пробелы, пунктуация)
-- title_date заголовок + день публикации (один заголовок в разные дни не дубль)
-- content    хеш очищенного текста
ALTER TABLE sources MODIFY dedup_by ENUM('url','guid','title','title_date','content') NOT NULL DEFAULT 'url';
