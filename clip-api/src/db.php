<?php
/**
 * db.php : MySQL(PDO) 연결 생성
 *
 * 접속 정보는 코드에 적지 않고 docker run 의 -e 옵션(환경변수)으로 받는다.
 *   DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS
 */

function db(): PDO
{
    // static 변수: 한 번 만든 연결을 같은 요청 안에서 재사용한다
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    // getenv() 로 환경변수를 읽고, 없으면 오른쪽의 기본값을 쓴다
    $host = getenv('DB_HOST') ?: 'cv-mysql';
    $port = getenv('DB_PORT') ?: '3306';
    $name = getenv('DB_NAME') ?: 'clipvault';
    $user = getenv('DB_USER') ?: 'cvuser';
    $pass = getenv('DB_PASS') ?: '';

    // DSN: 접속 대상 문자열. charset=utf8mb4 로 한글이 깨지지 않게 한다
    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,  // 오류가 나면 예외를 던진다
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,        // 조회 결과를 [컬럼명 => 값] 배열로 받는다
        PDO::ATTR_EMULATE_PREPARES   => false,                   // 진짜 prepared statement 사용 (SQL 인젝션 방지)
    ]);

    return $pdo;
}
