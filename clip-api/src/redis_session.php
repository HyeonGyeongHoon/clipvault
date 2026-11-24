<?php
/**
 * redis_session.php : Redis 세션 검증 공통 모듈
 *
 * 로그인은 cv-auth-api(Python)가 처리하고, 이 서버(PHP)는 로그인 여부만 확인한다.
 * 두 서버는 서로 호출하지 않고 Redis 하나를 공유한다. 이것이 서버 간 세션 공유의 핵심이다.
 *
 * 규칙 (cv-auth-api 와 반드시 동일해야 한다)
 *   - 쿠키 이름 : SESSION_ID
 *   - Redis 키  : sess:<토큰>
 *   - Redis 값  : {"user_id": 1, "username": "..."}  (JSON 문자열)
 */

/** Redis 연결을 만들어 돌려준다. 같은 요청 안에서는 재사용한다. */
function redis_client(): Redis
{
    static $redis = null;
    if ($redis !== null) {
        return $redis;
    }

    $host = getenv('REDIS_HOST') ?: 'cv-redis';
    $port = (int)(getenv('REDIS_PORT') ?: 6379);

    $redis = new Redis();
    // connect(호스트, 포트, 타임아웃초): 컨테이너 이름(cv-redis)이 곧 호스트명이다
    $redis->connect($host, $port, 2.0);

    return $redis;
}

/**
 * 현재 요청의 로그인 사용자를 돌려준다. 로그인하지 않았으면 null.
 * 반환 예: ['user_id' => 1, 'username' => 'tester']
 */
function current_user(): ?array
{
    // 브라우저가 보낸 쿠키에서 세션 토큰을 꺼낸다
    $token = $_COOKIE['SESSION_ID'] ?? '';
    if ($token === '') {
        return null;
    }

    // Redis 에서 "sess:<토큰>" 키를 조회한다. 키가 없으면(만료/로그아웃) false 가 돌아온다
    $raw = redis_client()->get('sess:' . $token);
    if ($raw === false) {
        return null;
    }

    // JSON 문자열을 배열로 복원한다
    $session = json_decode($raw, true);
    return is_array($session) ? $session : null;
}
