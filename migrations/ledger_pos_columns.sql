-- ============================================================
-- DI PARMA | Ledger POS — إضافة أعمدة جديدة
-- شغّل هذا الملف مرة واحدة فقط
-- ============================================================

-- جدول dp_transactions
ALTER TABLE dp_transactions
  ADD COLUMN IF NOT EXISTS input_mode   VARCHAR(20)  DEFAULT 'manual'  AFTER security_mode,
  ADD COLUMN IF NOT EXISTS orig_ref     VARCHAR(100) DEFAULT NULL       AFTER input_mode,
  ADD COLUMN IF NOT EXISTS card_last4   VARCHAR(4)   DEFAULT NULL       AFTER orig_ref,
  ADD COLUMN IF NOT EXISTS notes        TEXT         DEFAULT NULL       AFTER card_last4,
  ADD COLUMN IF NOT EXISTS ledger_txid  VARCHAR(100) DEFAULT NULL       AFTER notes,
  ADD COLUMN IF NOT EXISTS ledger_transferred TINYINT(1) DEFAULT 0     AFTER ledger_txid,
  ADD COLUMN IF NOT EXISTS ledger_amount DECIMAL(14,6) DEFAULT NULL     AFTER ledger_transferred,
  ADD COLUMN IF NOT EXISTS updated_at   DATETIME     DEFAULT NULL       AFTER created_at;

-- جدول طابور التحويلات
CREATE TABLE IF NOT EXISTS ledger_transfer_queue (
  id             INT UNSIGNED   AUTO_INCREMENT PRIMARY KEY,
  reference      VARCHAR(100)   NOT NULL,
  ledger_address VARCHAR(100)   NOT NULL,
  usdt_amount    DECIMAL(14,6)  NOT NULL,
  currency_orig  VARCHAR(10)    NOT NULL DEFAULT 'USD',
  status         ENUM('queued','processing','done','failed') DEFAULT 'queued',
  txid           VARCHAR(120)   DEFAULT NULL,
  error_msg      TEXT           DEFAULT NULL,
  created_at     DATETIME       NOT NULL,
  processed_at   DATETIME       DEFAULT NULL,
  INDEX idx_status (status),
  INDEX idx_reference (reference)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
