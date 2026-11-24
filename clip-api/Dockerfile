# 베이스 이미지: PHP 8.2 + Apache (x86_64/amd64 환경에서 빌드하면 amd64 이미지가 된다)
FROM php:8.2-apache

# PDO(MySQL 접속)와 phpredis(Redis 접속) 확장을 설치한다 (빌드 시 인터넷 연결 필요)
RUN docker-php-ext-install pdo pdo_mysql \
 && pecl install redis \
 && docker-php-ext-enable redis

# 모든 요청을 index.php 로 보내는 Apache 설정을 넣고 활성화한다
COPY apache.conf /etc/apache2/conf-available/cv-api.conf
RUN a2enconf cv-api

# 소스코드를 이미지 내부에 포함 (과제 요건: 바인딩 마운트가 아니라 COPY로 포함)
COPY src/ /var/www/html/
