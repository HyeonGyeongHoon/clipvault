#!/bin/bash
# 컨테이너와 네트워크를 지운다. 볼륨(데이터, NPM 설정)은 남긴다.
#   ./scripts/stop.sh            컨테이너 + 네트워크 삭제
#   ./scripts/stop.sh --purge    볼륨까지 삭제 (DB 데이터와 프록시 설정이 모두 사라짐)
source "$(dirname "$0")/env.sh"

docker rm -f cv-proxy cv-web cv-clip-api-1 cv-clip-api-2 cv-clip-api-3 cv-auth-api cv-redis cv-mysql 2>/dev/null
docker network rm "$NETWORK" 2>/dev/null

if [ "$1" = "--purge" ]; then
  read -r -p "볼륨을 삭제하면 DB 데이터가 복구되지 않습니다. 계속할까요? [y/N] " ans
  [ "$ans" = "y" ] && docker volume rm cv-mysql-data cv-npm-data cv-npm-letsencrypt
fi

docker ps -a
docker volume ls
