-- GELO - Pagamentos de pedidos de retirada (múltiplos)

CREATE DATABASE IF NOT EXISTS gelo
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE gelo;

CREATE TABLE IF NOT EXISTS withdrawal_payments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id BIGINT UNSIGNED NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  method ENUM('pix','cash','debit','credit') NOT NULL DEFAULT 'pix',
  paid_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  note VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_withdrawal_payments_order (order_id),
  KEY idx_withdrawal_payments_method (method),
  KEY idx_withdrawal_payments_paid_at (paid_at),
  CONSTRAINT fk_withdrawal_payments_order FOREIGN KEY (order_id) REFERENCES withdrawal_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_withdrawal_payments_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
