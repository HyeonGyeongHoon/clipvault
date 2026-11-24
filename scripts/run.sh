#!/bin/bash
# 컨테이너 8개를 의존 순서대로 실행한다. (Docker Compose 미사용, docker run 만 사용)
# 순서: 네트워크 → MySQL → Redis → 인증 서버 → 클립 서버 3대 → 프론트 → 프록시
set -e
source "$(dirname "$0")/env.sh"

APP_ENV=(-e REDIS_HOST=cv-redis -e REDIS_PORT=6379
         -e DB_HOST=cv-mysql -e DB_PORT=3306
         -e DB_NAME="$DB_NAME" -e DB_USER="$DB_USER" -e DB_PASS="$DB_PASS")

# 1. 공통 네트워크 (이미 있으면 건너뜀)
docker network inspect "$NETWORK" >/dev/null 2>&1 || docker network create "$NETWORK"

# 2. DB : 네임드 볼륨 cv-mysql-data 에 데이터 보관 (컨테이너를 지워도 데이터 유지)
docker run -d --name cv-mysql --network "$NETWORK" --restart=always \
  -p 33306:3306 -v cv-mysql-data:/var/lib/mysql \
  -e MYSQL_ROOT_PASSWORD="$MYSQL_ROOT_PASSWORD" \
  -e MYSQL_DATABASE="$DB_NAME" -e MYSQL_USER="$DB_USER" -e MYSQL_PASSWORD="$DB_PASS" \
  mysql:8.0 --character-set-server=utf8mb4 --collation-server=utf8mb4_unicode_ci \
  --default-authentication-plugin=mysql_native_password

# 3. 세션 공유 서버 (호스트 포트 미공개)
docker run -d --name cv-redis --network "$NETWORK" --restart=always redis:7

# 4. 인증 서버 (Python FastAPI)
docker run -d --name cv-auth-api --hostname cv-auth-api --network "$NETWORK" --restart=always \
  "${APP_ENV[@]}" "$DOCKERHUB_ID/cv-auth-api:$TAG"

# 5. 클립 서버 (PHP) 3대 : 같은 별칭 cv-clip-api 를 공유해 프록시에서 라운드로빈 분산
for n in 1 2 3; do
  docker run -d --name "cv-clip-api-$n" --hostname "cv-clip-api-$n" \
    --network "$NETWORK" --network-alias cv-clip-api --restart=always \
    "${APP_ENV[@]}" "$DOCKERHUB_ID/cv-clip-api:$TAG"
done

# 6. 프론트 (Nginx 정적 호스팅)
docker run -d --name cv-web --network "$NETWORK" --restart=always "$DOCKERHUB_ID/cv-web:$TAG"

# 7. 역방향 프록시 (Nginx Proxy Manager, 설정은 :81 관리 화면에서)
docker run -d --name cv-proxy --network "$NETWORK" --restart=always \
  -p 80:80 -p 81:81 -v cv-npm-data:/data -v cv-npm-letsencrypt:/etc/letsencrypt \
  jc21/nginx-proxy-manager:latest

docker ps --format "table {{.Names}}\t{{.Image}}\t{{.Status}}\t{{.Ports}}"
echo "[*] cv-mysql 은 약 30초, cv-proxy 는 약 1분 뒤에 준비됩니다."
