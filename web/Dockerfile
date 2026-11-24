# 베이스 이미지: 가벼운 Nginx. 정적 파일(HTML/CSS/JS)만 서빙한다
FROM nginx:alpine

# 화면 파일 3개를 Nginx 기본 웹 루트에 복사해 이미지 내부에 포함 (바인딩 마운트가 아니라 COPY)
COPY index.html style.css app.js /usr/share/nginx/html/
