-- GELO - Adiciona método (tipo) ao pagamento de retirada

USE gelo;

SET @gelo_has_method := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'withdrawal_payments'
    AND COLUMN_NAME = 'method'
);

SET @gelo_sql := IF(
  @gelo_has_method = 0,
  "ALTER TABLE withdrawal_payments ADD COLUMN method ENUM('pix','cash','debit','credit') NOT NULL DEFAULT 'pix' AFTER amount",
  "SELECT 1"
);
PREPARE stmt FROM @gelo_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @gelo_has_method_idx := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'withdrawal_payments'
    AND INDEX_NAME = 'idx_withdrawal_payments_method'
);

SET @gelo_sql := IF(
  @gelo_has_method_idx = 0,
  "ALTER TABLE withdrawal_payments ADD KEY idx_withdrawal_payments_method (method)",
  "SELECT 1"
);
PREPARE stmt FROM @gelo_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Best-effort: tenta inferir o método a partir da observação existente
UPDATE withdrawal_payments
SET method = CASE
  WHEN note IS NULL OR TRIM(note) = '' THEN method
  WHEN UPPER(note) LIKE '%DINHEIRO%' OR UPPER(note) LIKE '%CASH%' THEN 'cash'
  WHEN UPPER(note) LIKE '%DEBIT%' OR UPPER(note) LIKE '%DEBITO%' OR UPPER(note) LIKE '%DÉBIT%' THEN 'debit'
  WHEN UPPER(note) LIKE '%CREDIT%' OR UPPER(note) LIKE '%CREDITO%' OR UPPER(note) LIKE '%CRÉDIT%' THEN 'credit'
  WHEN UPPER(note) LIKE '%PIX%' THEN 'pix'
  ELSE method
END
WHERE method = 'pix';
