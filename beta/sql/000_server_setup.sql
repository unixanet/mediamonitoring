-- 000_server_setup.sql: запускать от root (mysql < файл). Создаёт БД и пользователей.
CREATE DATABASE IF NOT EXISTS nebuchadnezzar  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS crawler         CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS crawler_archive CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER IF NOT EXISTS 'nebuchadnezzar'@'localhost' IDENTIFIED BY 'x,Tn}k<g<?OtViYY';
CREATE USER IF NOT EXISTS 'crawler'@'localhost' IDENTIFIED BY 'u]~6.!KW23gG1`4-';
GRANT ALL PRIVILEGES ON nebuchadnezzar.*  TO 'nebuchadnezzar'@'localhost';
GRANT ALL PRIVILEGES ON crawler.*         TO 'crawler'@'localhost';
GRANT ALL PRIVILEGES ON crawler_archive.* TO 'crawler'@'localhost';
FLUSH PRIVILEGES;
