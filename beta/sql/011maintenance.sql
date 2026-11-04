-- 011maintenance.sql: процедуры обслуживания БД intranet (партиции, дневные итоги, очистка по срокам).
-- Запуск: mysql -u intranet -p intranet < 011maintenance.sql
-- Ежедневно по cron:  mysql -u intranet -p intranet -e "CALL maint_run()"
-- Сроки хранения лежат в таблице retention_policies (010intranet.sql).
SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS maint_add_partitions;
DROP PROCEDURE IF EXISTS maint_drop_partitions;
DROP PROCEDURE IF EXISTS maint_rollup_stats;
DROP PROCEDURE IF EXISTS maint_delete_old;
DROP PROCEDURE IF EXISTS maint_run;

DELIMITER //

-- Создаёт месячные партиции на months_ahead месяцев вперёд (разбивая pmax)
CREATE PROCEDURE maint_add_partitions(IN p_table VARCHAR(64), IN p_months_ahead INT)
BEGIN
  DECLARE i INT DEFAULT 0;
  DECLARE m_start DATE;
  DECLARE m_end DATE;
  DECLARE p_name VARCHAR(16);
  DECLARE n INT;

  WHILE i <= p_months_ahead DO
    SET m_start = DATE_ADD(DATE_FORMAT(UTC_DATE(), '%Y-%m-01'), INTERVAL i MONTH);
    SET m_end   = DATE_ADD(m_start, INTERVAL 1 MONTH);
    SET p_name  = CONCAT('p', DATE_FORMAT(m_start, '%Y%m'));

    SELECT COUNT(*) INTO n FROM information_schema.PARTITIONS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND PARTITION_NAME = p_name;

    IF n = 0 THEN
      SET @sql = CONCAT('ALTER TABLE `', p_table, '` REORGANIZE PARTITION pmax INTO (',
                        'PARTITION ', p_name, ' VALUES LESS THAN (TO_DAYS(''', m_end, ''')), ',
                        'PARTITION pmax VALUES LESS THAN MAXVALUE)');
      PREPARE st FROM @sql;
      EXECUTE st;
      DEALLOCATE PREPARE st;
    END IF;
    SET i = i + 1;
  END WHILE;
END//

-- Удаляет партиции, целиком старше keep_days
CREATE PROCEDURE maint_drop_partitions(IN p_table VARCHAR(64), IN p_keep_days INT, OUT p_dropped INT)
BEGIN
  DECLARE cutoff INT;
  SET cutoff = TO_DAYS(DATE_SUB(UTC_DATE(), INTERVAL p_keep_days DAY));
  SET p_dropped = 0;
  SET SESSION group_concat_max_len = 8192;

  SELECT GROUP_CONCAT(PARTITION_NAME ORDER BY PARTITION_ORDINAL_POSITION), COUNT(*)
    INTO @drop_list, p_dropped
    FROM information_schema.PARTITIONS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table
     AND PARTITION_NAME IS NOT NULL AND PARTITION_NAME <> 'pmax'
     AND CAST(PARTITION_DESCRIPTION AS UNSIGNED) <= cutoff;

  IF p_dropped > 0 THEN
    SET @sql = CONCAT('ALTER TABLE `', p_table, '` DROP PARTITION ', @drop_list);
    PREPARE st FROM @sql;
    EXECUTE st;
    DEALLOCATE PREPARE st;
  END IF;
END//

-- Сворачивает отправки за день p_day (UTC) в stats_daily
CREATE PROCEDURE maint_rollup_stats(IN p_day DATE)
BEGIN
  DELETE FROM stats_daily WHERE day = p_day;

  INSERT INTO stats_daily (day, user_id, channel, kind, sent, failed, skipped, articles)
  SELECT p_day, user_id, channel, kind,
         SUM(status = 'sent'), SUM(status = 'failed'), SUM(status = 'skipped'),
         COALESCE(SUM(CASE WHEN status = 'sent' THEN articles_count END), 0)
    FROM outbox
   WHERE created_at >= p_day AND created_at < DATE_ADD(p_day, INTERVAL 1 DAY)
   GROUP BY user_id, channel, kind;

  INSERT INTO stats_daily (day, user_id, channel, kind, sent, failed, skipped, articles)
  SELECT p_day, 0, channel, kind, SUM(sent), SUM(failed), SUM(skipped), SUM(articles)
    FROM stats_daily
   WHERE day = p_day AND user_id <> 0
   GROUP BY channel, kind;
END//

-- Удаляет старые строки пачками, возвращает число удалённых
CREATE PROCEDURE maint_delete_old(IN p_table VARCHAR(64), IN p_column VARCHAR(40), IN p_keep_days INT, IN p_batch INT, OUT p_deleted INT)
BEGIN
  DECLARE loops INT DEFAULT 0;
  DECLARE affected INT DEFAULT 1;
  SET p_deleted = 0;

  SET @sql = CONCAT('DELETE FROM `', p_table, '` WHERE `', p_column, '` < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ',
                    p_keep_days, ' DAY) LIMIT ', p_batch);
  PREPARE st FROM @sql;
  WHILE affected > 0 AND loops < 200 DO
    EXECUTE st;
    SET affected = ROW_COUNT();
    SET p_deleted = p_deleted + affected;
    SET loops = loops + 1;
  END WHILE;
  DEALLOCATE PREPARE st;
END//

-- Ежедневная задача: партиции, итоги, очистка. Результат пишется в job_runs.
CREATE PROCEDURE maint_run()
BEGIN
  DECLARE done INT DEFAULT 0;
  DECLARE t_name VARCHAR(64);
  DECLARE t_kind VARCHAR(16);
  DECLARE t_col VARCHAR(40);
  DECLARE t_keep INT;
  DECLARE t_batch INT;
  DECLARE n INT;
  DECLARE total INT DEFAULT 0;
  DECLARE run_id BIGINT;
  DECLARE cur CURSOR FOR SELECT table_name, kind, date_column, keep_days, batch_size FROM retention_policies ORDER BY table_name;
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = 1;
  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    GET DIAGNOSTICS CONDITION 1 @err_msg = MESSAGE_TEXT;
    UPDATE job_runs SET status = 'error', finished_at = UTC_TIMESTAMP(), message = LEFT(@err_msg, 500), rows_affected = total
     WHERE id = run_id;
  END;

  SET SESSION time_zone = '+00:00';
  INSERT INTO job_runs (job, started_at) VALUES ('maint_run', UTC_TIMESTAMP());
  SET run_id = LAST_INSERT_ID();

  -- 1. партиции вперёд на 3 месяца
  OPEN cur;
  part_loop: LOOP
    FETCH cur INTO t_name, t_kind, t_col, t_keep, t_batch;
    IF done = 1 THEN LEAVE part_loop; END IF;
    IF t_kind = 'partition' THEN CALL maint_add_partitions(t_name, 3); END IF;
  END LOOP;
  CLOSE cur;

  -- 2. итоги за позавчера и вчера (вчерашние строки ещё могут дополняться повторами)
  CALL maint_rollup_stats(DATE_SUB(UTC_DATE(), INTERVAL 2 DAY));
  CALL maint_rollup_stats(DATE_SUB(UTC_DATE(), INTERVAL 1 DAY));

  -- 3. очистка по срокам
  SET done = 0;
  OPEN cur;
  clean_loop: LOOP
    FETCH cur INTO t_name, t_kind, t_col, t_keep, t_batch;
    IF done = 1 THEN LEAVE clean_loop; END IF;
    IF t_kind = 'partition' THEN
      CALL maint_drop_partitions(t_name, t_keep, n);
    ELSE
      CALL maint_delete_old(t_name, t_col, t_keep, t_batch, n);
    END IF;
    SET total = total + n;
  END LOOP;
  CLOSE cur;

  UPDATE job_runs SET status = 'ok', finished_at = UTC_TIMESTAMP(), message = 'done', rows_affected = total WHERE id = run_id;
END//

DELIMITER ;
