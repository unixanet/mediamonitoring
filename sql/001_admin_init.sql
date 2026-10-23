-- 001_admin_init.sql: структура БД админки beta.mediamonitoring.am
-- БД: nebuchadnezzar (utf8mb4)
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS admin_users (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username      VARCHAR(64)  NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role          ENUM('owner','admin','viewer') NOT NULL DEFAULT 'admin',
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login_at DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ip         VARCHAR(45)  NOT NULL,
  username   VARCHAR(64)  NOT NULL,
  success    TINYINT(1)   NOT NULL DEFAULT 0,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ip_time (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Первый пользователь: admin / Beta#2026Admin (сменить после входа)
INSERT INTO admin_users (username, password_hash, role)
VALUES ('admin', '$2y$10$/lRN.siFN191DmTIcLQple5ryAMFlIxYqaq7rmeE43ZJKBv2RPDxi', 'owner')
ON DUPLICATE KEY UPDATE username = username;
