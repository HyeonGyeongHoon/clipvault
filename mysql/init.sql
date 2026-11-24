-- ClipVault 초기화 스크립트 (HeidiSQL 쿼리 탭에서 실행)
-- 계정(cvuser)은 docker run 의 MYSQL_USER 옵션으로 이미 생성되어 있으므로 여기서 만들지 않는다.

-- 데이터베이스가 없으면 생성 (docker run 의 MYSQL_DATABASE 로 이미 있으면 그냥 넘어간다)
CREATE DATABASE IF NOT EXISTS clipvault CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE clipvault;

-- 1. 회원 테이블: 로그인 계정과 bcrypt 해시 비밀번호 저장
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 2. 클립보드 테이블: 로그인 사용자가 저장한 텍스트 (회원 삭제 시 함께 삭제)
CREATE TABLE IF NOT EXISTS clips (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    content TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 3. 1회 열람 파기 테이블: 6자리 PIN, 열람 즉시 secret_content 를 NULL 로 비우고 BURNED 로 변경
CREATE TABLE IF NOT EXISTS secret_drops (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sender_id INT NOT NULL,
    pin_code CHAR(6) NOT NULL UNIQUE,
    secret_content TEXT NULL,
    status ENUM('ACTIVE', 'BURNED') NOT NULL DEFAULT 'ACTIVE',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    burned_at TIMESTAMP NULL DEFAULT NULL,
    FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
