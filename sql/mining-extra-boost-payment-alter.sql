ALTER TABLE poado_mining_boost_requests
  ADD COLUMN IF NOT EXISTS expected_token VARCHAR(16) NOT NULL DEFAULT 'EMA' AFTER daily_cap_add,
  ADD COLUMN IF NOT EXISTS treasury_address VARCHAR(128) DEFAULT NULL AFTER expected_token,
  ADD COLUMN IF NOT EXISTS ema_master VARCHAR(128) DEFAULT NULL AFTER treasury_address,
  ADD COLUMN IF NOT EXISTS payment_ref VARCHAR(64) DEFAULT NULL AFTER ema_master,
  ADD COLUMN IF NOT EXISTS expected_amount_ema DECIMAL(24,8) NOT NULL DEFAULT 0.00000000 AFTER payment_ref,
  ADD COLUMN IF NOT EXISTS expected_amount_units VARCHAR(64) DEFAULT NULL AFTER expected_amount_ema,
  ADD COLUMN IF NOT EXISTS payment_status VARCHAR(24) NOT NULL DEFAULT 'payment_pending' AFTER expected_amount_units,
  ADD COLUMN IF NOT EXISTS deeplink_url TEXT DEFAULT NULL AFTER payment_status,
  ADD COLUMN IF NOT EXISTS qr_data_uri LONGTEXT DEFAULT NULL AFTER deeplink_url,
  ADD COLUMN IF NOT EXISTS tx_hash VARCHAR(255) DEFAULT NULL AFTER qr_data_uri,
  ADD COLUMN IF NOT EXISTS verify_source VARCHAR(64) DEFAULT NULL AFTER tx_hash,
  ADD COLUMN IF NOT EXISTS confirmations INT NOT NULL DEFAULT 0 AFTER verify_source,
  ADD COLUMN IF NOT EXISTS paid_at DATETIME DEFAULT NULL AFTER confirmations,
  ADD COLUMN IF NOT EXISTS expires_at DATETIME DEFAULT NULL AFTER paid_at;

CREATE INDEX IF NOT EXISTS idx_boost_payment_status ON poado_mining_boost_requests (payment_status);
CREATE INDEX IF NOT EXISTS idx_boost_payment_ref ON poado_mining_boost_requests (payment_ref);
CREATE INDEX IF NOT EXISTS idx_boost_tx_hash ON poado_mining_boost_requests (tx_hash);
