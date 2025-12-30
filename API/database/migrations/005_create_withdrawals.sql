-- GELO - Cria tabelas de pedidos de retirada (withdrawals) e itens

CREATE DATABASE IF NOT EXISTS gelo
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE gelo;

CREATE TABLE IF NOT EXISTS withdrawal_orders (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  status ENUM('requested','delivered') NOT NULL DEFAULT 'requested',
  comment TEXT NULL,
  total_items INT UNSIGNED NOT NULL DEFAULT 0,
  total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  delivered_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_withdrawal_orders_user (user_id),
  KEY idx_withdrawal_orders_status (status),
  CONSTRAINT fk_withdrawal_orders_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_withdrawal_orders_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS withdrawal_order_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  product_title VARCHAR(160) NOT NULL,
  unit_price DECIMAL(10,2) NOT NULL,
  quantity INT UNSIGNED NOT NULL,
  line_total DECIMAL(10,2) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_withdrawal_items_order (order_id),
  KEY idx_withdrawal_items_product (product_id),
  CONSTRAINT fk_withdrawal_items_order FOREIGN KEY (order_id) REFERENCES withdrawal_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_withdrawal_items_product FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

