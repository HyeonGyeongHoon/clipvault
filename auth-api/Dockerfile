# 베이스 이미지: 가벼운 Python 3.11 (x86_64/amd64 환경에서 빌드하면 amd64 이미지가 된다)
FROM python:3.11-slim

# 컨테이너 안의 작업 디렉터리
WORKDIR /app

# 의존성 파일을 먼저 복사해서 설치한다 (소스가 바뀌어도 이 레이어는 캐시를 재사용)
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

# 소스코드를 이미지 내부에 포함 (과제 요건: 바인딩 마운트가 아니라 COPY로 포함)
COPY main.py .

# uvicorn(ASGI 서버)으로 FastAPI 앱 실행. 0.0.0.0 으로 열어야 다른 컨테이너가 접근할 수 있다
CMD ["uvicorn", "main:app", "--host", "0.0.0.0", "--port", "8000"]