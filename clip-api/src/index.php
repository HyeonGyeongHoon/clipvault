<?php
/**
 * index.php : cv-clip-api 의 진입점(라우터)
 *
 * Apache 의 FallbackResource 설정 때문에 /api/clips, /api/secrets/... 로 오는 모든 요청이
 * 이 파일 하나로 들어온다. 여기서 경로와 HTTP 메서드를 보고 알맞은 함수를 호출한다.
 *
 * 응답 형식 (모든 응답 공통)
 *   {"status": "success|error", "worker": "<컨테이너 호스트명>", "session_verified": true|false, "data": {...}}
 *   worker 값으로 어느 컨테이너가 요청을 처리했는지 화면 하단 상태 바에 표시한다.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/redis_session.php';
require_once __DIR__ . '/clips.php';
require_once __DIR__ . '/secrets.php';

header('Content-Type: application/json; charset=utf-8');

// 이번 요청에서 로그인 확인(Redis 조회)에 성공했는지 기록한다. 응답의 session_verified 에 쓰인다
$GLOBALS['session_verified'] = false;

/** 성공 응답을 출력하고 종료한다. */
function respond(array $data = [], int $http = 200): void
{
    http_response_code($http);
    echo json_encode([
        'status'           => 'success',
        'worker'           => gethostname(),                 // 컨테이너 호스트명(--hostname 으로 지정한 값)
        'session_verified' => $GLOBALS['session_verified'],
        'data'             => (object)$data,                 // 빈 배열도 {} 로 나가게 객체로 변환
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/** 오류 응답을 출력하고 종료한다. */
function respond_error(int $http, string $message): void
{
    http_response_code($http);
    echo json_encode([
        'status'           => 'error',
        'worker'           => gethostname(),
        'session_verified' => $GLOBALS['session_verified'],
        'message'          => $message,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/** 로그인이 필요한 API 에서 호출한다. 로그인 상태면 사용자 정보를, 아니면 401 로 종료한다. */
function require_login(): array
{
    $user = current_user();           // redis_session.php : 쿠키 -> Redis 조회
    if ($user === null) {
        respond_error(401, '로그인이 필요합니다.');
    }
    $GLOBALS['session_verified'] = true;
    return $user;
}

try {
    // 요청 정보 읽기 : HTTP 메서드, 경로(쿼리스트링 제외), JSON 본문
    $method = $_SERVER['REQUEST_METHOD'];
    $path   = rtrim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
    $body   = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) {
        $body = [];                   // 본문이 없거나 JSON 이 아니면 빈 배열로 처리
    }

    // ---- 라우팅 ----------------------------------------------------------
    if ($path === '/api/clips/health' && $method === 'GET') {
        respond(['service' => 'cv-clip-api']);                  // 상태 확인용 (로그인 불필요)
    }

    if ($path === '/api/clips' && $method === 'GET') {
        list_clips(require_login());
    }
    if ($path === '/api/clips' && $method === 'POST') {
        create_clip(require_login(), $body);
    }
    // DELETE /api/clips/3 처럼 끝에 숫자 id 가 붙은 경우
    if ($method === 'DELETE' && preg_match('#^/api/clips/(\d+)$#', $path, $m)) {
        delete_clip(require_login(), (int)$m[1]);
    }

    if ($path === '/api/secrets' && $method === 'POST') {
        issue_secret(require_login(), $body);                   // PIN 발급은 로그인 필수
    }
    if ($path === '/api/secrets/redeem' && $method === 'POST') {
        redeem_secret($body);                                   // PIN 열람은 로그인 불필요
    }

    // 위 어느 것에도 해당하지 않으면 404
    respond_error(404, '존재하지 않는 API 입니다.');

} catch (Throwable $e) {
    // DB/Redis 연결 실패 등 예상하지 못한 오류. 내부 정보는 숨기고 로그에만 남긴다
    error_log($e->getMessage());
    respond_error(500, '서버 오류가 발생했습니다.');
}
