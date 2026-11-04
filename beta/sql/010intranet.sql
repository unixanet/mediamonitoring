-- 010intranet.sql: БД intranet (платформа v3). Полная структура и стартовые данные.
-- Запуск: mysql -u intranet -p intranet < 010intranet.sql  (БД и пользователь уже созданы)
-- Проект и сроки хранения: docs/PLATFORM_V3.md, раздел 3.9. MySQL 8.0+.
SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ---------------------------------------------------------------------------
-- Доступ
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS users (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email            VARCHAR(190) NOT NULL,
  username         VARCHAR(64)  NOT NULL,
  password_hash    VARCHAR(255) NOT NULL,
  first_name       VARCHAR(100) NOT NULL,
  last_name        VARCHAR(100) NOT NULL,
  company_name     VARCHAR(190) NULL,
  address          VARCHAR(255) NULL,
  phone            VARCHAR(32)  NOT NULL,
  language         ENUM('hy','ru','en') NOT NULL DEFAULT 'hy',
  timezone         VARCHAR(40)  NOT NULL DEFAULT 'Asia/Yerevan',
  avatar           VARCHAR(255) NULL,
  telegram_chat_id VARCHAR(32)  NULL,
  status           ENUM('active','suspended','deleted') NOT NULL DEFAULT 'active',
  is_legacy        TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'старый клиент: все инструменты, старая цена',
  legacy_id        INT UNSIGNED NULL COMMENT 'users.id в старой БД, для миграции',
  created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  last_login_at    DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_email (email),
  UNIQUE KEY uq_username (username),
  UNIQUE KEY uq_legacy (legacy_id),
  KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Временный пароль для сброса: клиент получает его на почту и входит с ним один раз.
CREATE TABLE IF NOT EXISTS password_resets (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id             BIGINT UNSIGNED NOT NULL,
  temp_password_hash  VARCHAR(255) NOT NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at          DATETIME NOT NULL,
  used_at             DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_user_active (user_id, expires_at),
  CONSTRAINT fk_pr_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ip          VARCHAR(45)  NOT NULL,
  identifier  VARCHAR(190) NOT NULL,
  success     TINYINT(1)   NOT NULL DEFAULT 0,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ip_time (ip, created_at),
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Тарифы, инструменты, права
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS plans (
  id             SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code           VARCHAR(32)  NOT NULL,
  name           VARCHAR(100) NOT NULL,
  price_amd      INT UNSIGNED NOT NULL DEFAULT 0,
  duration_days  SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  keyword_limit  INT UNSIGNED NOT NULL DEFAULT 0,
  user_limit     SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  ai_quota_milli BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'включённая квота AI в месяц, в тысячных драма',
  api_rpm        INT UNSIGNED NULL COMMENT 'лимит запросов API в минуту; NULL = без API',
  is_public      TINYINT(1)   NOT NULL DEFAULT 1,
  is_active      TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order     SMALLINT     NOT NULL DEFAULT 0,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tools (
  id          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code        VARCHAR(40)  NOT NULL,
  name        VARCHAR(100) NOT NULL,
  kind        ENUM('monitoring','analytics','ai','archive','digest') NOT NULL,
  status      ENUM('active','planned','hidden') NOT NULL DEFAULT 'active',
  url         VARCHAR(190) NOT NULL,
  sort_order  SMALLINT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS plan_tools (
  plan_id  SMALLINT UNSIGNED NOT NULL,
  tool_id  SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (plan_id, tool_id),
  CONSTRAINT fk_pt_plan FOREIGN KEY (plan_id) REFERENCES plans (id) ON DELETE CASCADE,
  CONSTRAINT fk_pt_tool FOREIGN KEY (tool_id) REFERENCES tools (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Персональные выдачи инструментов (вручную из админки)
CREATE TABLE IF NOT EXISTS user_tool_grants (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     BIGINT UNSIGNED NOT NULL,
  tool_id     SMALLINT UNSIGNED NOT NULL,
  starts_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at  DATETIME NULL,
  revoked_at  DATETIME NULL,
  granted_by  INT UNSIGNED NULL COMMENT 'admin id',
  reason      VARCHAR(255) NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_user_tool (user_id, tool_id),
  CONSTRAINT fk_utg_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_utg_tool FOREIGN KEY (tool_id) REFERENCES tools (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- История подписок: текущая это активная запись с максимальным expires_at
CREATE TABLE IF NOT EXISTS subscriptions (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     BIGINT UNSIGNED NOT NULL,
  plan_id     SMALLINT UNSIGNED NOT NULL,
  status      ENUM('pending','active','expired','cancelled') NOT NULL DEFAULT 'active',
  source      ENUM('purchase','trial','admin','legacy') NOT NULL DEFAULT 'purchase',
  starts_at   DATETIME NOT NULL,
  expires_at  DATETIME NOT NULL,
  order_id    BIGINT UNSIGNED NULL,
  created_by  INT UNSIGNED NULL COMMENT 'admin id',
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_user_status (user_id, status, expires_at),
  KEY idx_expiry (status, expires_at),
  CONSTRAINT fk_sub_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_sub_plan FOREIGN KEY (plan_id) REFERENCES plans (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Деньги: заказы, кошелёк
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS orders (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_number      VARCHAR(64)  NOT NULL,
  user_id           BIGINT UNSIGNED NULL,
  kind              ENUM('plan','topup','digest','addon') NOT NULL,
  amount_amd        INT UNSIGNED NOT NULL,
  status            ENUM('pending','paid','failed','cancelled','refunded') NOT NULL DEFAULT 'pending',
  gateway           VARCHAR(20)  NOT NULL DEFAULT 'ineco',
  gateway_order_id  VARCHAR(64)  NULL,
  plan_id           SMALLINT UNSIGNED NULL,
  months            TINYINT UNSIGNED NULL,
  meta              JSON NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  paid_at           DATETIME NULL,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_order_number (order_number),
  KEY idx_user_created (user_id, created_at),
  KEY idx_status_created (status, created_at),
  KEY idx_gateway_order (gateway_order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Сырые ответы банка: для разбора спорных платежей
CREATE TABLE IF NOT EXISTS payment_events (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id      BIGINT UNSIGNED NULL,
  order_number  VARCHAR(64) NOT NULL,
  source        ENUM('register','redirect','status_api','callback','admin') NOT NULL,
  http_code     SMALLINT NULL,
  payload       MEDIUMTEXT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_order (order_number),
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Суммы кошелька в тысячных долях драма (1 AMD = 1000), чтобы считать стоимость токенов без округления
CREATE TABLE IF NOT EXISTS wallets (
  user_id        BIGINT UNSIGNED NOT NULL,
  balance_milli  BIGINT NOT NULL DEFAULT 0,
  held_milli     BIGINT NOT NULL DEFAULT 0,
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  CONSTRAINT chk_wallet_nonneg CHECK (balance_milli >= 0 AND held_milli >= 0),
  CONSTRAINT fk_wallet_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Журнал проводок: только добавление, строки не правятся и не удаляются
CREATE TABLE IF NOT EXISTS wallet_ledger (
  id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id              BIGINT UNSIGNED NOT NULL,
  amount_milli         BIGINT NOT NULL COMMENT 'со знаком: пополнение +, списание -',
  balance_after_milli  BIGINT NOT NULL,
  type                 ENUM('topup','ai_usage','admin_adjust','refund','bonus') NOT NULL,
  ref_type             VARCHAR(20) NULL,
  ref_id               BIGINT UNSIGNED NULL,
  idempotency_key      VARCHAR(80) NOT NULL,
  note                 VARCHAR(255) NULL,
  created_by           INT UNSIGNED NULL COMMENT 'admin id, если вручную',
  created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_idem (idempotency_key),
  KEY idx_user (user_id, id),
  CONSTRAINT fk_ledger_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Резерв под дорогие операции: сначала holds, потом списание по факту
CREATE TABLE IF NOT EXISTS wallet_holds (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       BIGINT UNSIGNED NOT NULL,
  amount_milli  BIGINT UNSIGNED NOT NULL,
  status        ENUM('held','captured','released') NOT NULL DEFAULT 'held',
  purpose       VARCHAR(60) NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at    DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_user_status (user_id, status),
  KEY idx_expiry (status, expires_at),
  KEY idx_created (created_at),
  CONSTRAINT fk_hold_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Настройки отчётов
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS keywords (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id      BIGINT UNSIGNED NOT NULL,
  phrase       VARCHAR(255) NOT NULL,
  phrase_norm  VARCHAR(255) NOT NULL COMMENT 'нижний регистр, схлопнутые пробелы',
  status       ENUM('active','paused') NOT NULL DEFAULT 'active',
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_user_phrase (user_id, phrase_norm),
  KEY idx_status (status),
  CONSTRAINT fk_kw_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Одно правило: ключевое слово + канал + периодичность (заменяет report и report_telegram)
CREATE TABLE IF NOT EXISTS alert_rules (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id          BIGINT UNSIGNED NOT NULL,
  keyword_id       BIGINT UNSIGNED NOT NULL,
  channel          ENUM('email','telegram') NOT NULL,
  frequency        ENUM('instant','daily','weekly','monthly') NOT NULL,
  send_time        TIME NULL COMMENT 'локальное время отправки для daily/weekly/monthly',
  weekday          TINYINT UNSIGNED NULL COMMENT '1=понедельник ... 7=воскресенье, для weekly',
  monthday         TINYINT UNSIGNED NULL COMMENT '1..28, для monthly',
  sources          SET('media','telegram','facebook') NOT NULL DEFAULT 'media',
  is_enabled       TINYINT(1) NOT NULL DEFAULT 1,
  start_at         DATETIME NOT NULL COMMENT 'не отправлять материалы старше',
  next_run_at      DATETIME NULL,
  last_run_at      DATETIME NULL,
  last_article_id  BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'курсор по articles.id в БД краулера',
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_rule (keyword_id, channel, frequency),
  KEY idx_due (is_enabled, next_run_at),
  KEY idx_user (user_id),
  CONSTRAINT fk_rule_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_rule_kw FOREIGN KEY (keyword_id) REFERENCES keywords (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS crisis_rules (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id            BIGINT UNSIGNED NOT NULL,
  keyword_id         BIGINT UNSIGNED NOT NULL,
  source             ENUM('media','social') NOT NULL DEFAULT 'media',
  threshold_pct      INT UNSIGNED NOT NULL DEFAULT 200,
  is_enabled         TINYINT(1) NOT NULL DEFAULT 1,
  last_triggered_at  DATETIME NULL,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_crisis (keyword_id, source),
  KEY idx_user (user_id),
  CONSTRAINT fk_crisis_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_crisis_kw FOREIGN KEY (keyword_id) REFERENCES keywords (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Доставка (объёмные таблицы, партиции по месяцам; внешних ключей нет)
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS outbox (
  id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  user_id              BIGINT UNSIGNED NOT NULL,
  rule_id              BIGINT UNSIGNED NULL,
  kind                 ENUM('alert','crisis','report','system') NOT NULL DEFAULT 'alert',
  channel              ENUM('email','telegram') NOT NULL,
  frequency            ENUM('instant','daily','weekly','monthly') NULL,
  status               ENUM('queued','sending','sent','failed','skipped') NOT NULL DEFAULT 'queued',
  attempts             TINYINT UNSIGNED NOT NULL DEFAULT 0,
  scheduled_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  locked_until         DATETIME NULL,
  sent_at              DATETIME NULL,
  articles_count       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  provider_message_id  VARCHAR(100) NULL,
  error_code           VARCHAR(40)  NULL,
  error_message        VARCHAR(500) NULL,
  PRIMARY KEY (id, created_at),
  KEY idx_queue (status, scheduled_at),
  KEY idx_user (user_id, created_at),
  KEY idx_rule (rule_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
PARTITION BY RANGE (TO_DAYS(created_at)) (
  PARTITION p202611 VALUES LESS THAN (TO_DAYS('2026-12-01')),
  PARTITION p202612 VALUES LESS THAN (TO_DAYS('2027-01-01')),
  PARTITION p202701 VALUES LESS THAN (TO_DAYS('2027-02-01')),
  PARTITION p202702 VALUES LESS THAN (TO_DAYS('2027-03-01')),
  PARTITION pmax    VALUES LESS THAN MAXVALUE
);

CREATE TABLE IF NOT EXISTS outbox_items (
  outbox_id   BIGINT UNSIGNED NOT NULL,
  article_id  BIGINT UNSIGNED NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (outbox_id, article_id, created_at),
  KEY idx_article (article_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
PARTITION BY RANGE (TO_DAYS(created_at)) (
  PARTITION p202611 VALUES LESS THAN (TO_DAYS('2026-12-01')),
  PARTITION p202612 VALUES LESS THAN (TO_DAYS('2027-01-01')),
  PARTITION p202701 VALUES LESS THAN (TO_DAYS('2027-02-01')),
  PARTITION p202702 VALUES LESS THAN (TO_DAYS('2027-03-01')),
  PARTITION pmax    VALUES LESS THAN MAXVALUE
);

CREATE TABLE IF NOT EXISTS delivery_errors (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  outbox_id      BIGINT UNSIGNED NOT NULL,
  user_id        BIGINT UNSIGNED NOT NULL,
  channel        ENUM('email','telegram') NOT NULL,
  attempt        TINYINT UNSIGNED NOT NULL,
  error_code     VARCHAR(40)  NULL,
  error_message  VARCHAR(500) NULL,
  PRIMARY KEY (id, created_at),
  KEY idx_outbox (outbox_id),
  KEY idx_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
PARTITION BY RANGE (TO_DAYS(created_at)) (
  PARTITION p202611 VALUES LESS THAN (TO_DAYS('2026-12-01')),
  PARTITION p202612 VALUES LESS THAN (TO_DAYS('2027-01-01')),
  PARTITION p202701 VALUES LESS THAN (TO_DAYS('2027-02-01')),
  PARTITION p202702 VALUES LESS THAN (TO_DAYS('2027-03-01')),
  PARTITION pmax    VALUES LESS THAN MAXVALUE
);

-- Защита от дублей: правило + статья отправляются один раз. Без партиций, чистится пачками.
CREATE TABLE IF NOT EXISTS delivered (
  rule_id       BIGINT UNSIGNED NOT NULL,
  article_id    BIGINT UNSIGNED NOT NULL,
  delivered_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (rule_id, article_id),
  KEY idx_delivered_at (delivered_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Отчётность и аналитика
-- ---------------------------------------------------------------------------

-- Дневные итоги отправок; user_id = 0 это итог по всем клиентам
CREATE TABLE IF NOT EXISTS stats_daily (
  day       DATE NOT NULL,
  user_id   BIGINT UNSIGNED NOT NULL,
  channel   ENUM('email','telegram') NOT NULL,
  kind      ENUM('alert','crisis','report','system') NOT NULL,
  sent      INT UNSIGNED NOT NULL DEFAULT 0,
  failed    INT UNSIGNED NOT NULL DEFAULT 0,
  skipped   INT UNSIGNED NOT NULL DEFAULT 0,
  articles  INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (day, user_id, channel, kind),
  KEY idx_user_day (user_id, day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS keyword_stats_daily (
  day              DATE NOT NULL,
  keyword_id       BIGINT UNSIGNED NOT NULL,
  mentions_media   INT UNSIGNED NOT NULL DEFAULT 0,
  mentions_social  INT UNSIGNED NOT NULL DEFAULT 0,
  positive         INT UNSIGNED NOT NULL DEFAULT 0,
  neutral          INT UNSIGNED NOT NULL DEFAULT 0,
  negative         INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (day, keyword_id),
  KEY idx_keyword (keyword_id, day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Срезы трендов каждые 6 часов
CREATE TABLE IF NOT EXISTS trend_snapshots (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  calculated_at   DATETIME NOT NULL,
  rank_pos        SMALLINT UNSIGNED NOT NULL,
  phrase          VARCHAR(255) NOT NULL,
  current_count   INT UNSIGNED NOT NULL DEFAULT 0,
  baseline_count  INT UNSIGNED NOT NULL DEFAULT 0,
  growth_pct      INT NOT NULL DEFAULT 0,
  article_count   INT UNSIGNED NOT NULL DEFAULT 0,
  source_count    INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_calc (calculated_at, rank_pos),
  KEY idx_phrase (phrase, calculated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS export_jobs (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id      BIGINT UNSIGNED NOT NULL,
  tool_id      SMALLINT UNSIGNED NULL,
  format       ENUM('csv','xlsx','pdf') NOT NULL,
  params       JSON NULL,
  status       ENUM('queued','running','done','failed') NOT NULL DEFAULT 'queued',
  file_path    VARCHAR(255) NULL,
  row_count    INT UNSIGNED NULL,
  error        VARCHAR(255) NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at  DATETIME NULL,
  expires_at   DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_user (user_id, created_at),
  KEY idx_status (status, created_at),
  KEY idx_expires (expires_at),
  CONSTRAINT fk_exp_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- ИИ
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS ai_usage (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  user_id         BIGINT UNSIGNED NOT NULL,
  tool_code       VARCHAR(40) NOT NULL,
  task            VARCHAR(30) NOT NULL,
  model           VARCHAR(60) NOT NULL,
  input_tokens    INT UNSIGNED NOT NULL DEFAULT 0,
  cached_tokens   INT UNSIGNED NOT NULL DEFAULT 0,
  output_tokens   INT UNSIGNED NOT NULL DEFAULT 0,
  cost_milli      BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'наша стоимость',
  charged_milli   BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'списано с клиента',
  latency_ms      INT UNSIGNED NULL,
  status          ENUM('ok','error','cached') NOT NULL DEFAULT 'ok',
  PRIMARY KEY (id, created_at),
  KEY idx_user (user_id, created_at),
  KEY idx_tool (tool_code, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
PARTITION BY RANGE (TO_DAYS(created_at)) (
  PARTITION p202611 VALUES LESS THAN (TO_DAYS('2026-12-01')),
  PARTITION p202612 VALUES LESS THAN (TO_DAYS('2027-01-01')),
  PARTITION p202701 VALUES LESS THAN (TO_DAYS('2027-02-01')),
  PARTITION p202702 VALUES LESS THAN (TO_DAYS('2027-03-01')),
  PARTITION pmax    VALUES LESS THAN MAXVALUE
);

CREATE TABLE IF NOT EXISTS ai_cache (
  cache_key   CHAR(64) NOT NULL COMMENT 'sha256(задача + модель + текст)',
  task        VARCHAR(30) NOT NULL,
  model       VARCHAR(60) NOT NULL,
  result      MEDIUMTEXT NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at  DATETIME NOT NULL,
  PRIMARY KEY (cache_key),
  KEY idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Админка и эксплуатация
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS audit_log (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actor_type   ENUM('admin','user','system') NOT NULL,
  actor_id     BIGINT UNSIGNED NULL,
  action       VARCHAR(60) NOT NULL,
  target_type  VARCHAR(30) NULL,
  target_id    BIGINT UNSIGNED NULL,
  before_json  JSON NULL,
  after_json   JSON NULL,
  ip           VARCHAR(45) NULL,
  PRIMARY KEY (id, created_at),
  KEY idx_target (target_type, target_id, created_at),
  KEY idx_actor (actor_type, actor_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
PARTITION BY RANGE (TO_DAYS(created_at)) (
  PARTITION p202611 VALUES LESS THAN (TO_DAYS('2026-12-01')),
  PARTITION p202612 VALUES LESS THAN (TO_DAYS('2027-01-01')),
  PARTITION p202701 VALUES LESS THAN (TO_DAYS('2027-02-01')),
  PARTITION p202702 VALUES LESS THAN (TO_DAYS('2027-03-01')),
  PARTITION pmax    VALUES LESS THAN MAXVALUE
);

CREATE TABLE IF NOT EXISTS settings (
  k           VARCHAR(80) NOT NULL,
  v           TEXT NOT NULL,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (k)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS job_runs (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  job            VARCHAR(60) NOT NULL,
  started_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at    DATETIME NULL,
  status         ENUM('running','ok','error') NOT NULL DEFAULT 'running',
  message        VARCHAR(500) NULL,
  rows_affected  INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_job (job, started_at),
  KEY idx_started (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Политики хранения: читает процедура maint_run() (см. 011maintenance.sql)
CREATE TABLE IF NOT EXISTS retention_policies (
  table_name   VARCHAR(64) NOT NULL,
  kind         ENUM('partition','delete') NOT NULL,
  date_column  VARCHAR(40) NOT NULL,
  keep_days    INT UNSIGNED NOT NULL,
  batch_size   INT UNSIGNED NOT NULL DEFAULT 5000,
  PRIMARY KEY (table_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Стартовые данные
-- ---------------------------------------------------------------------------

INSERT INTO retention_policies (table_name, kind, date_column, keep_days, batch_size) VALUES
  ('outbox',              'partition', 'created_at',   90,   0),
  ('outbox_items',        'partition', 'created_at',   90,   0),
  ('delivery_errors',     'partition', 'created_at',   180,  0),
  ('ai_usage',            'partition', 'created_at',   396,  0),
  ('audit_log',           'partition', 'created_at',   730,  0),
  ('delivered',           'delete',    'delivered_at', 60,   5000),
  ('login_attempts',      'delete',    'created_at',   30,   5000),
  ('password_resets',     'delete',    'expires_at',   30,   5000),
  ('wallet_holds',        'delete',    'expires_at',   30,   5000),
  ('trend_snapshots',     'delete',    'calculated_at',30,   5000),
  ('export_jobs',         'delete',    'expires_at',   0,    1000),
  ('ai_cache',            'delete',    'expires_at',   0,    5000),
  ('job_runs',            'delete',    'started_at',   30,   5000),
  ('payment_events',      'delete',    'created_at',   1826, 5000),
  ('stats_daily',         'delete',    'day',          1095, 5000),
  ('keyword_stats_daily', 'delete',    'day',          730,  5000)
ON DUPLICATE KEY UPDATE kind = VALUES(kind), date_column = VALUES(date_column), keep_days = VALUES(keep_days), batch_size = VALUES(batch_size);

INSERT INTO tools (code, name, kind, status, url, sort_order) VALUES
  ('media',                 'Media monitoring',       'monitoring', 'active',  '/tools/media',                 10),
  ('facebook',              'Facebook monitoring',    'monitoring', 'active',  '/tools/facebook',              20),
  ('telegram',              'Telegram monitoring',    'monitoring', 'active',  '/tools/telegram',              30),
  ('youtube',               'YouTube monitoring',     'monitoring', 'active',  '/tools/youtube',               40),
  ('citations',             'Citations',              'analytics',  'active',  '/tools/citations',             50),
  ('comparison',            'Keyword comparison',     'analytics',  'active',  '/tools/comparison',            60),
  ('archive',               'Archive',                'archive',    'active',  '/tools/archive',               70),
  ('crisis-monitor',        'Crisis Monitor',         'ai',         'active',  '/tools/crisis-monitor',        80),
  ('branding-intelligence', 'Brand Intelligence',     'ai',         'active',  '/tools/branding-intelligence', 90),
  ('media-landscape',       'Media Landscape',        'ai',         'active',  '/tools/media-landscape',       100),
  ('trends',                'LIVE Trends',            'ai',         'active',  '/tools/trends',                110),
  ('first-source',          'First Source',           'ai',         'active',  '/tools/first-source',          120),
  ('zlm-profiles',          'Media Profiles',         'ai',         'active',  '/tools/zlm-profiles',          130),
  ('digest',                'Digest',                 'digest',     'planned', '/digest',                      140)
ON DUPLICATE KEY UPDATE name = VALUES(name), kind = VALUES(kind), url = VALUES(url), sort_order = VALUES(sort_order);

-- Тарифы для тестов и старых клиентов; цены новых тарифов обсуждаются отдельно
INSERT INTO plans (code, name, price_amd, duration_days, keyword_limit, user_limit, ai_quota_milli, api_rpm, is_public, sort_order) VALUES
  ('legacy', 'Legacy (all tools)', 0, 30, 1000, 10, 0, NULL, 0, 90),
  ('trial',  'Trial 7 days',       0, 7,  5,    1,  0, NULL, 0, 10)
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT IGNORE INTO plan_tools (plan_id, tool_id)
SELECT p.id, t.id FROM plans p JOIN tools t ON t.status = 'active' WHERE p.code IN ('legacy', 'trial');

INSERT INTO settings (k, v) VALUES
  ('outbox.max_attempts', '5'),
  ('outbox.batch_size', '50'),
  ('outbox.instant_interval_min', '5'),
  ('wallet.min_topup_amd', '1000')
ON DUPLICATE KEY UPDATE k = k;
