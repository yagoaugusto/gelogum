-- GELO - Migração inicial

CREATE DATABASE IF NOT EXISTS gelo
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE gelo;

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  phone VARCHAR(20) NOT NULL,
  birthday DATE NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('master','admin','user') NOT NULL DEFAULT 'user',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_users_phone (phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO users (name, phone, password_hash, role)
VALUES (
  'Yago',
  '98991668283',
  '$2y$10$U.V34eNRi6agQNbwmuUkwueerrNLyzu3SWTcSrf1y4G51/bvPoA46',
  'master'
)
ON DUPLICATE KEY UPDATE
  name = 'Yago',
  password_hash = '$2y$10$U.V34eNRi6agQNbwmuUkwueerrNLyzu3SWTcSrf1y4G51/bvPoA46',
  role = 'master';
