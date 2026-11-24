#!/bin/bash
# SQL 백업 파일로 DB 를 복원한다.
#   ./scripts/restore.sh backup/clipvault_xxx.sql   백업 데이터 복원
#   ./scripts/restore.sh mysql/init.sql             빈 스키마만 생성
set -e
source "$(dirname "$0")/env.sh"

FILE=${1:?사용법: ./scripts/restore.sh <sql 파일>}

# MySQL 이 접속을 받을 때까지 기다린다 (새 볼륨이면 첫 초기화에 30초 이상 걸림)
until docker exec cv-mysql mysqladmin ping -uroot -p"$MYSQL_ROOT_PASSWORD" --silent 2>/dev/null; do
  echo "[*] MySQL 준비 대기 중..."; sleep 3
done

docker exec -i cv-mysql mysql -uroot -p"$MYSQL_ROOT_PASSWORD" < "$FILE"
echo "[*] 복원 완료: $FILE"
