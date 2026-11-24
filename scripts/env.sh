#!/bin/bash
# 공통 설정: 저장소 루트의 .env 를 읽고, 없으면 기본값을 쓴다.
# 다른 스크립트에서 source 해서 사용한다.

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

if [ -f "$ROOT_DIR/.env" ]; then
  set -a
  # shellcheck disable=SC1091
  source "$ROOT_DIR/.env"
  set +a
fi

DOCKERHUB_ID=${DOCKERHUB_ID:-hgh040700}
TAG=${TAG:-1.0}
MYSQL_ROOT_PASSWORD=${MYSQL_ROOT_PASSWORD:-123456}
DB_NAME=${DB_NAME:-clipvault}
DB_USER=${DB_USER:-cvuser}
DB_PASS=${DB_PASS:-123456}
NETWORK=cv-network
