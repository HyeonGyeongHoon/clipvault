#!/bin/bash
DOMAIN=clipvault.test
N=${1:-12}
CONTAINERS="cv-clip-api-1 cv-clip-api-2 cv-clip-api-3"

# VM의 /etc/hosts에 도메인이 없으면 127.0.0.1로 등록 (프록시가 같은 VM에서 80번 포트를 열고 있음)
grep -q "$DOMAIN" /etc/hosts || echo "127.0.0.1 $DOMAIN" | sudo tee -a /etc/hosts >/dev/null

# 요청 전 컨테이너별 누적 처리 건수 기록
declare -A before
for c in $CONTAINERS; do
  before[$c]=$(docker logs "$c" 2>&1 | grep -c "GET /api/clips/health")
done

# 프록시를 통해 N번 요청 (로그인 불필요한 상태 확인 API)
echo "[*] http://$DOMAIN/api/clips/health 로 $N 번 요청..."
for i in $(seq 1 "$N"); do curl -s "http://$DOMAIN/api/clips/health" -o /dev/null; done

# 요청 후 - 전 = 이번 분산 결과
echo "[*] 컨테이너별 처리 건수 (round-robin):"
for c in $CONTAINERS; do
  after=$(docker logs "$c" 2>&1 | grep -c "GET /api/clips/health")
  printf "  %-14s : %s\n" "$c" "$((after - ${before[$c]}))"
done
