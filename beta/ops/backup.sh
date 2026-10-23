#!/bin/bash
# Ежедневный бэкап БД админки и краулера (хранится 14 дней). Запуск: cron, см. DNA.md
DIR=/var/backups/mediamonitoring
D=$(date +%F_%H%M)
mkdir -p "$DIR"
mysqldump -unebuchadnezzar -p'x,Tn}k<g<?OtViYY' --single-transaction --quick nebuchadnezzar 2>/dev/null | gzip > "$DIR/admin_$D.sql.gz"
# сырой RSS (article_raw) и служебные слоты в бэкап не берём: пересоздаются
mysqldump -ucrawler -p'u]~6.!KW23gG1`4-' --single-transaction --quick --ignore-table=crawler.article_raw --ignore-table=crawler.host_slots crawler 2>/dev/null | gzip > "$DIR/crawler_$D.sql.gz"
# архив годов меняется редко: по воскресеньям
if [ "$(date +%u)" = "7" ]; then
  mysqldump -ucrawler -p'u]~6.!KW23gG1`4-' --single-transaction --quick crawler_archive 2>/dev/null | gzip > "$DIR/archive_$D.sql.gz"
fi
find "$DIR" -name '*.sql.gz' -mtime +14 -delete
# проверка: файлы не пустые
for f in "$DIR"/admin_$D.sql.gz "$DIR"/crawler_$D.sql.gz; do
  [ "$(stat -c %s "$f")" -gt 200 ] || echo "BACKUP PROBLEM: $f is too small" >&2
done
