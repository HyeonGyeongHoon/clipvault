#!/bin/bash
# 직접 만든 이미지 3개를 빌드한다. (Docker Hub 이미지를 쓰면 생략 가능)
#   ./scripts/build.sh          빌드만
#   ./scripts/build.sh --push   빌드 후 Docker Hub 에 push
# Apple Silicon(ARM) 에서 빌드할 때는 amd64 로 맞추기 위해 --platform 을 지정한다.
set -e
source "$(dirname "$0")/env.sh"

for svc in auth-api clip-api web; do
  echo "[*] build $DOCKERHUB_ID/cv-$svc:$TAG"
  docker build --platform linux/amd64 -t "$DOCKERHUB_ID/cv-$svc:$TAG" "$ROOT_DIR/$svc"
done

if [ "$1" = "--push" ]; then
  for svc in auth-api clip-api web; do
    docker push "$DOCKERHUB_ID/cv-$svc:$TAG"
  done
fi

docker image inspect "$DOCKERHUB_ID/cv-web:$TAG" --format 'architecture: {{.Architecture}}'
