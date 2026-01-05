-- GELO - Pagamentos (lote) por usuário com compensação por pedidos

CREATE DATABASE IF NOT EXISTS gelo
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE gelo;

CREATE TABLE IF NOT EXISTS user_payments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  method ENUM('pix','cash','debit','credit') NOT NULL DEFAULT 'pix',
  paid_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  note VARCHAR(255) NULL,
  open_before DECIMAL(10,2) NOT NULL DEFAULT 0,
  open_after DECIMAL(10,2) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_user_payments_user (user_id),
  KEY idx_user_payments_paid_at (paid_at),
  CONSTRAINT fk_user_payments_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_payments_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_payment_allocations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_payment_id BIGINT UNSIGNED NOT NULL,
  order_id BIGINT UNSIGNED NOT NULL,
  withdrawal_payment_id BIGINT UNSIGNED NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  open_before DECIMAL(10,2) NOT NULL DEFAULT 0,
  open_after DECIMAL(10,2) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_user_payment_allocations_payment (user_payment_id),
  KEY idx_user_payment_allocations_order (order_id),
  UNIQUE KEY uq_user_payment_allocations_withdrawal_payment (withdrawal_payment_id),
  CONSTRAINT fk_user_payment_allocations_payment FOREIGN KEY (user_payment_id) REFERENCES user_payments(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_payment_allocations_order FOREIGN KEY (order_id) REFERENCES withdrawal_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_payment_allocations_withdrawal_payment FOREIGN KEY (withdrawal_payment_id) REFERENCES withdrawal_payments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
