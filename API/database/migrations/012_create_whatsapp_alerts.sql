-- GELO - Alertas WhatsApp (UltraMsg)

CREATE DATABASE IF NOT EXISTS gelo
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE gelo;

CREATE TABLE IF NOT EXISTS whatsapp_alert_recipients (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(80) NOT NULL,
  phone VARCHAR(32) NOT NULL,
  receive_order_alerts TINYINT(1) NOT NULL DEFAULT 0,
  receive_daily_summary TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_whatsapp_alert_recipients_active (is_active),
  KEY idx_whatsapp_alert_recipients_phone (phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS whatsapp_message_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  message_type VARCHAR(40) NOT NULL,
  order_id BIGINT UNSIGNED NULL,
  target_user_id BIGINT UNSIGNED NULL,
  recipient_phone VARCHAR(32) NOT NULL,
  body TEXT NOT NULL,
  api_response TEXT NULL,
  is_success TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_whatsapp_message_logs_created (created_at),
  KEY idx_whatsapp_message_logs_type (message_type),
  KEY idx_whatsapp_message_logs_order (order_id),
  KEY idx_whatsapp_message_logs_user (target_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS whatsapp_daily_summary_runs (
  run_date DATE NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (run_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Permissão de gerenciamento
SET @gelo_admin_group_id := (SELECT id FROM permission_groups WHERE name = 'Administrador' LIMIT 1);
INSERT IGNORE INTO permission_group_permissions (group_id, permission_key)
VALUES (@gelo_admin_group_id, 'whatsapp_alerts.manage');
