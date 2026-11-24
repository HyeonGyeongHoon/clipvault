<?php
/**
 * secrets.php : 1회 열람 후 파기(One-Time Secret Drop)
 *
 *  - issue_secret  : 로그인한 사용자가 텍스트를 올리면 6자리 PIN 을 발급한다
 *  - redeem_secret : 로그인 없이 PIN 으로 1회만 조회할 수 있고, 조회 즉시 DB 의 원문이 삭제된다
 */

/** POST /api/secrets : PIN 발급. 본문: {"content": "공유할 텍스트"} (로그인 필수) */
function issue_secret(array $user, array $body): void
{
    $content = trim((string)($body['content'] ?? ''));
    if ($content === '') {
        respond_error(400, '공유할 내용을 입력해 주세요.');
    }
    if (mb_strlen($content) > 10000) {
        respond_error(400, '내용은 10000자 이하여야 합니다.');
    }

    $stmt = db()->prepare(
        'INSERT INTO secret_drops (sender_id, pin_code, secret_content) VALUES (?, ?, ?)'
    );

    // PIN 은 UNIQUE 컬럼이라 우연히 겹치면 INSERT 가 실패한다. 겹치면 새 PIN 으로 최대 10번 다시 시도한다
    for ($try = 0; $try < 10; $try++) {
        // random_int: 암호학적으로 안전한 난수. 0~999999 를 6자리로 맞춘다 (예: 42 -> "000042")
        $pin = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        try {
            $stmt->execute([$user['user_id'], $pin, $content]);
            respond(['pin' => $pin], 201);
        } catch (PDOException $e) {
            // SQLSTATE 23000 = 무결성 제약 위반(PIN 중복). 그 외 오류는 그대로 던진다
            if ($e->getCode() !== '23000') {
                throw $e;
            }
        }
    }
    respond_error(503, 'PIN 발급에 실패했습니다. 잠시 후 다시 시도해 주세요.');
}

/**
 * POST /api/secrets/redeem : 1회 열람. 본문: {"pin": "123456"} (로그인 불필요)
 *
 * 동시에 두 사람이 같은 PIN 을 조회해도 한 명만 성공하도록
 * 트랜잭션 + 행 잠금(SELECT ... FOR UPDATE)을 사용한다.
 */
function redeem_secret(array $body): void
{
    $pin = (string)($body['pin'] ?? '');
    if (!preg_match('/^\d{6}$/', $pin)) {     // 숫자 6자리가 아니면 DB 조회 없이 거부
        respond_error(400, 'PIN 은 숫자 6자리입니다.');
    }

    $pdo = db();
    $pdo->beginTransaction();                  // 1) 트랜잭션 시작
    try {
        // 2) 아직 열람되지 않은 행을 잠그고(FOR UPDATE) 조회한다.
        //    다른 요청은 이 트랜잭션이 끝날 때까지 같은 행을 읽지 못하고 기다린다
        $stmt = $pdo->prepare(
            "SELECT id, secret_content FROM secret_drops
             WHERE pin_code = ? AND status = 'ACTIVE' FOR UPDATE"
        );
        $stmt->execute([$pin]);
        $row = $stmt->fetch();

        if ($row === false) {                  // 없거나 이미 열람됨(BURNED)
            $pdo->rollBack();
            respond_error(404, '유효하지 않거나 이미 열람된 PIN 입니다.');
        }

        // 3) 원문을 변수에 확보한 직후, 같은 트랜잭션 안에서 DB 의 원문을 NULL 로 파기한다
        $secret = $row['secret_content'];
        $burn = $pdo->prepare(
            "UPDATE secret_drops
             SET secret_content = NULL, status = 'BURNED', burned_at = NOW()
             WHERE id = ?"
        );
        $burn->execute([$row['id']]);

        $pdo->commit();                        // 4) 확정: 이 시점부터 DB 에는 원문이 없다
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();                  // 오류가 나면 되돌려서 원문이 사라지지 않게 한다
        }
        throw $e;
    }

    // 5) 원문은 이 응답으로 단 한 번만 전달된다
    respond(['secret' => $secret]);
}
