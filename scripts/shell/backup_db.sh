#!/bin/bash
# 資料庫備份（可放進 crontab）
#   0 2 * * * /path/to/AISLE/scripts/shell/backup_db.sh >> /path/to/AISLE/storage/logs/backup.log 2>&1
# 用法：scripts/shell/backup_db.sh [保留天數，預設 14]
set -e
. "$(dirname "$0")/_common.sh"

KEEP_DAYS="${1:-14}"
DIR="$AISLE_ROOT/storage/backups"
mkdir -p "$DIR"

MYCNF=$(make_mycnf)
trap 'rm -f "$MYCNF"' EXIT

FILE="$DIR/aisle_$(date +%Y%m%d_%H%M%S).sql.gz"
mysqldump --defaults-extra-file="$MYCNF" --single-transaction --routines --triggers "$(db_name)" | gzip > "$FILE"
chmod 600 "$FILE"
info "備份完成：$FILE ($(du -h "$FILE" | cut -f1))"

find "$DIR" -name 'aisle_*.sql.gz' -type f -mtime +"$KEEP_DAYS" -print -delete
