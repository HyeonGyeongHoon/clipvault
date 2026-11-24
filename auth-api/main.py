"""
cv-auth-api : ClipVault 인증 서버 (Python / FastAPI)

역할
  - 회원가입 : 아이디 중복 확인 -> bcrypt 로 비밀번호 해시 -> MySQL users 테이블에 저장
  - 로그인   : 비밀번호 검증 -> 랜덤 세션 토큰 생성 -> Redis 에 세션 저장 -> SESSION_ID 쿠키 발급
  - 로그아웃 : Redis 의 세션 삭제 -> 쿠키 만료
  - 내 정보  : 쿠키의 토큰으로 Redis 세션 조회

세션 공유의 핵심
  이 서버가 Redis 에 만든 "sess:<토큰>" 키를 PHP 서버(cv-clip-api)가 같은 규칙으로 조회한다.
  두 서버는 서로 직접 호출하지 않고, Redis 하나만 공유해서 로그인 상태를 판단한다.
"""

import json          # 세션 값을 JSON 문자열로 저장/복원하기 위해 사용
import os            # 환경변수(os.environ)를 읽기 위해 사용
import secrets       # 예측 불가능한 안전한 랜덤 토큰을 만들기 위해 사용
import socket        # 컨테이너 호스트명(gethostname)을 응답에 담기 위해 사용
from typing import Optional

import bcrypt        # 비밀번호 해시/검증
import pymysql       # MySQL 접속 드라이버
import redis         # Redis 접속 클라이언트
from fastapi import Cookie, FastAPI, HTTPException, Request, Response
from fastapi.responses import JSONResponse
from pydantic import BaseModel, Field

# ---------------------------------------------------------------------------
# 1. 설정값 : 호스트명 등을 코드에 하드코딩하지 않고 docker run 의 -e 옵션으로 받는다
# ---------------------------------------------------------------------------
REDIS_HOST = os.environ.get("REDIS_HOST", "cv-redis")
REDIS_PORT = int(os.environ.get("REDIS_PORT", "6379"))
DB_HOST = os.environ.get("DB_HOST", "cv-mysql")
DB_PORT = int(os.environ.get("DB_PORT", "3306"))
DB_NAME = os.environ.get("DB_NAME", "clipvault")
DB_USER = os.environ.get("DB_USER", "cvuser")
DB_PASS = os.environ.get("DB_PASS", "")

SESSION_COOKIE = "SESSION_ID"   # 브라우저에 발급할 쿠키 이름 (PHP 서버도 같은 이름을 읽는다)
SESSION_PREFIX = "sess:"        # Redis 키 접두사 -> 최종 키: sess:<토큰>
SESSION_TTL = 86400             # 세션 유효 시간(초) = 24시간. 지나면 Redis 가 자동 삭제한다
WORKER = socket.gethostname()   # 응답을 처리한 컨테이너 이름 (화면 하단 상태 바에 표시)

# FastAPI 앱 객체. 프록시(NPM)가 /api/auth 경로를 이 서버로 넘겨주므로 모든 경로에 이 접두사를 붙인다
app = FastAPI(title="cv-auth-api")

# Redis 연결. decode_responses=True 이면 bytes 대신 문자열(str)로 값을 돌려준다
rds = redis.Redis(host=REDIS_HOST, port=REDIS_PORT, decode_responses=True)


# ---------------------------------------------------------------------------
# 2. 공통 함수
# ---------------------------------------------------------------------------
def get_db():
    """요청마다 MySQL 연결을 새로 만들어 돌려준다 (요청이 끝나면 호출한 쪽에서 close)."""
    return pymysql.connect(
        host=DB_HOST,
        port=DB_PORT,
        user=DB_USER,
        password=DB_PASS,
        database=DB_NAME,
        charset="utf8mb4",                      # 한글이 깨지지 않도록 utf8mb4 사용
        cursorclass=pymysql.cursors.DictCursor,  # 조회 결과를 {컬럼명: 값} 딕셔너리로 받는다
        autocommit=True,                         # INSERT 후 별도 commit 호출이 필요 없게 한다
    )


def ok(data=None, **extra):
    """성공 응답의 공통 형식. worker 필드로 어느 컨테이너가 처리했는지 알려 준다."""
    body = {"status": "success", "worker": WORKER, "data": data if data is not None else {}}
    body.update(extra)
    return body


@app.exception_handler(HTTPException)
async def http_error_handler(request: Request, exc: HTTPException):
    """에러 응답도 프론트가 처리하기 쉽도록 같은 형식(status/worker/message)으로 통일한다."""
    return JSONResponse(
        status_code=exc.status_code,
        content={"status": "error", "worker": WORKER, "message": exc.detail},
    )


def read_session(token: Optional[str]):
    """쿠키의 토큰으로 Redis 에서 세션을 조회한다. 없거나 만료됐으면 None."""
    if not token:
        return None
    raw = rds.get(SESSION_PREFIX + token)   # 예: GET sess:AbC123...
    return json.loads(raw) if raw else None


# ---------------------------------------------------------------------------
# 3. 요청 본문 형식 (pydantic 이 타입과 길이를 자동 검증한다)
# ---------------------------------------------------------------------------
class Credentials(BaseModel):
    username: str = Field(min_length=3, max_length=50)   # DB 컬럼(VARCHAR(50))과 맞춘다
    password: str = Field(min_length=4, max_length=100)


def password_bytes(password: str) -> bytes:
    """bcrypt 는 비밀번호를 72바이트까지만 처리하므로 초과하면 거부한다 (한글은 글자당 3바이트)."""
    data = password.encode("utf-8")
    if len(data) > 72:
        raise HTTPException(status_code=400, detail="비밀번호는 72바이트(영문 72자) 이하여야 합니다.")
    return data


# ---------------------------------------------------------------------------
# 4. API
# ---------------------------------------------------------------------------
@app.get("/api/auth/health")
def health():
    """살아 있는지 확인하는 용도 (테스트 curl 에서 사용)."""
    return ok({"service": "cv-auth-api"})


@app.post("/api/auth/register")
def register(body: Credentials):
    """회원가입 : bcrypt 해시로 비밀번호를 저장한다 (평문은 저장하지 않는다)."""
    pw_hash = bcrypt.hashpw(password_bytes(body.password), bcrypt.gensalt()).decode("utf-8")

    conn = get_db()
    try:
        with conn.cursor() as cur:
            # username 은 UNIQUE 컬럼이므로 중복이면 IntegrityError 가 발생한다
            cur.execute(
                "INSERT INTO users (username, password_hash) VALUES (%s, %s)",
                (body.username, pw_hash),   # %s 자리표시자를 쓰면 SQL 인젝션이 방지된다
            )
            user_id = cur.lastrowid
    except pymysql.err.IntegrityError:
        raise HTTPException(status_code=409, detail="이미 사용 중인 아이디입니다.")
    finally:
        conn.close()

    return ok({"user_id": user_id, "username": body.username})


@app.post("/api/auth/login")
def login(body: Credentials, response: Response):
    """로그인 : 비밀번호 검증 후 Redis 에 세션을 저장하고 SESSION_ID 쿠키를 발급한다."""
    conn = get_db()
    try:
        with conn.cursor() as cur:
            cur.execute(
                "SELECT id, username, password_hash FROM users WHERE username = %s",
                (body.username,),
            )
            user = cur.fetchone()
    finally:
        conn.close()

    # 아이디가 없거나 비밀번호가 틀려도 같은 메시지를 돌려준다 (어느 쪽이 틀렸는지 알려 주지 않는다)
    if user is None or not bcrypt.checkpw(
        password_bytes(body.password), user["password_hash"].encode("utf-8")
    ):
        raise HTTPException(status_code=401, detail="아이디 또는 비밀번호가 올바르지 않습니다.")

    # 추측할 수 없는 랜덤 토큰을 만든다 (약 43자의 URL 안전 문자열)
    token = secrets.token_urlsafe(32)

    # Redis 에 세션 저장 : 키 = sess:<토큰>, 값 = 사용자 정보(JSON), ex = 만료 시간(초)
    # 이 키 규칙을 PHP 서버(cv-clip-api)가 똑같이 사용해서 로그인 여부를 확인한다
    session_value = json.dumps({"user_id": user["id"], "username": user["username"]})
    rds.set(SESSION_PREFIX + token, session_value, ex=SESSION_TTL)

    # 브라우저에 쿠키 발급
    #   httponly : 자바스크립트로 쿠키를 읽을 수 없게 해서 탈취(XSS)를 막는다
    #   samesite : 다른 사이트에서 오는 요청에는 쿠키를 붙이지 않는다
    #   path=/   : 모든 경로(/api/clips 등)의 요청에 쿠키가 함께 전달된다
    response.set_cookie(
        key=SESSION_COOKIE,
        value=token,
        max_age=SESSION_TTL,
        httponly=True,
        samesite="lax",
        path="/",
    )
    return ok({"user_id": user["id"], "username": user["username"]})


@app.post("/api/auth/logout")
def logout(response: Response, session_id: Optional[str] = Cookie(default=None, alias=SESSION_COOKIE)):
    """로그아웃 : Redis 의 세션을 삭제하고 쿠키를 만료시킨다."""
    if session_id:
        rds.delete(SESSION_PREFIX + session_id)   # 삭제하면 PHP 서버도 즉시 로그아웃 상태로 본다
    response.delete_cookie(key=SESSION_COOKIE, path="/")
    return ok({"logged_out": True})


@app.get("/api/auth/me")
def me(session_id: Optional[str] = Cookie(default=None, alias=SESSION_COOKIE)):
    """현재 로그인한 사용자 정보. 화면 하단 상태 바와 로그인 유지 확인에 사용한다."""
    session = read_session(session_id)
    if session is None:
        raise HTTPException(status_code=401, detail="로그인이 필요합니다.")
    return ok(session, session_verified=True)
