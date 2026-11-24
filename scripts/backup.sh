#!/bin/bash
# DB 를 SQL 파일로 백업한다. (기본 파일명: backup/clipvault_YYYYMMDD_HHMMSS.sql)
set -e
source "$(dirname "$0")/env.sh"

mkdir -p "$ROOT_DIR/backup"
OUT=${1:-"$ROOT_DIR/backup/clipvault_$(date +%Y%m%d_%H%M%S).sql"}

docker exec cv-mysql mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" \
  --databases "$DB_NAME" --single-transaction > "$OUT"

echo "[*] 백업 완료: $OUT"
