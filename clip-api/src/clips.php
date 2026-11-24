<?php
/**
 * clips.php : 내 클립보드 기능 (로그인 필수)
 *
 * 모든 쿼리에 "user_id = 로그인한 사용자" 조건을 걸어서 다른 사람의 클립은 보거나 지울 수 없다.
 */

/** GET /api/clips : 내 클립 목록 (최신순) */
function list_clips(array $user): void
{
    $stmt = db()->prepare(
        'SELECT id, content, created_at FROM clips WHERE user_id = ? ORDER BY created_at DESC, id DESC'
    );
    $stmt->execute([$user['user_id']]);   // ? 자리에 값이 안전하게 바인딩된다
    respond(['clips' => $stmt->fetchAll()]);
}

/** POST /api/clips : 클립 저장. 본문: {"content": "저장할 텍스트"} */
function create_clip(array $user, array $body): void
{
    $content = trim((string)($body['content'] ?? ''));

    if ($content === '') {
        respond_error(400, '내용을 입력해 주세요.');
    }
    if (mb_strlen($content) > 10000) {   // 너무 긴 텍스트는 거부 (mb_strlen: 한글을 글자 수로 센다)
        respond_error(400, '내용은 10000자 이하여야 합니다.');
    }

    $stmt = db()->prepare('INSERT INTO clips (user_id, content) VALUES (?, ?)');
    $stmt->execute([$user['user_id'], $content]);

    respond(['id' => (int)db()->lastInsertId()], 201);
}

/** DELETE /api/clips/{id} : 내 클립 삭제 */
function delete_clip(array $user, int $id): void
{
    // id 와 user_id 를 함께 조건으로 걸어, 남의 클립은 삭제되지 않게 한다
    $stmt = db()->prepare('DELETE FROM clips WHERE id = ? AND user_id = ?');
    $stmt->execute([$id, $user['user_id']]);

    if ($stmt->rowCount() === 0) {      // 지워진 행이 없으면 없는 클립이거나 내 것이 아님
        respond_error(404, '클립을 찾을 수 없습니다.');
    }
    respond(['deleted' => $id]);
}
