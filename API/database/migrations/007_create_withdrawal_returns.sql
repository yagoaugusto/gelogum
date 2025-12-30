-- GELO - Devoluções de pedidos de retirada

CREATE DATABASE IF NOT EXISTS gelo
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE gelo;

CREATE TABLE IF NOT EXISTS withdrawal_returns (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id BIGINT UNSIGNED NOT NULL,
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  reason TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_withdrawal_returns_order (order_id),
  CONSTRAINT fk_withdrawal_returns_order FOREIGN KEY (order_id) REFERENCES withdrawal_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_withdrawal_returns_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS withdrawal_return_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  return_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  product_title VARCHAR(160) NOT NULL,
  unit_price DECIMAL(10,2) NOT NULL,
  quantity INT UNSIGNED NOT NULL,
  line_total DECIMAL(10,2) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_withdrawal_return_items_return (return_id),
  KEY idx_withdrawal_return_items_product (product_id),
  CONSTRAINT fk_withdrawal_return_items_return FOREIGN KEY (return_id) REFERENCES withdrawal_returns(id) ON DELETE CASCADE,
  CONSTRAINT fk_withdrawal_return_items_product FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

