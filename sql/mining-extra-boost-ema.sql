-- POAdo Mining v3
-- EXTRA BOOST WITH EMA$
-- Run on wems_db

CREATE TABLE IF NOT EXISTS poado_mining_boost_requests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  request_ref VARCHAR(64) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  wallet VARCHAR(128) NOT NULL,
  tier_code VARCHAR(32) NOT NULL DEFAULT 'free',
  tier_label VARCHAR(64) NOT NULL DEFAULT 'Free Miner',
  available_onchain_ema DECIMAL(24,8) NOT NULL DEFAULT 0.00000000,
  tier_min_ema DECIMAL(24,8) NOT NULL DEFAULT 0.00000000,
  boostable_ema DECIMAL(24,8) NOT NULL DEFAULT 0.00000000,
  selected_ema DECIMAL(24,8) NOT NULL DEFAULT 0.00000000,
  extra_steps INT NOT NULL DEFAULT 0,
  multiplier_add DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
  daily_cap_add DECIMAL(24,8) NOT NULL DEFAULT 0.00000000,
  verified_available_onchain_ema DECIMAL(24,8) DEFAULT NULL,
  status VARCHAR(24) NOT NULL DEFAULT 'prepared',
  verify_message VARCHAR(255) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT UTC_TIMESTAMP(),
  confirmed_at DATETIME DEFAULT NULL,
  updated_at DATETIME NOT NULL DEFAULT UTC_TIMESTAMP(),
  PRIMARY KEY (id),
  UNIQUE KEY uq_boost_request_ref (request_ref),
  KEY idx_boost_user_wallet (user_id, wallet),
  KEY idx_boost_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE poado_miner_profiles
  ADD COLUMN IF NOT EXISTS boost_selected_ema DECIMAL(24,8) NOT NULL DEFAULT 0.00000000 AFTER multiplier,
  ADD COLUMN IF NOT EXISTS boost_extra_steps INT NOT NULL DEFAULT 0 AFTER boost_selected_ema,
  ADD COLUMN IF NOT EXISTS boost_multiplier_add DECIMAL(12,4) NOT NULL DEFAULT 0.0000 AFTER boost_extra_steps,
  ADD COLUMN IF NOT EXISTS boost_daily_cap_add DECIMAL(24,8) NOT NULL DEFAULT 0.00000000 AFTER boost_multiplier_add,
  ADD COLUMN IF NOT EXISTS boost_status VARCHAR(24) NOT NULL DEFAULT 'idle' AFTER boost_daily_cap_add,
  ADD COLUMN IF NOT EXISTS boost_verified_at DATETIME DEFAULT NULL AFTER boost_status,
  ADD COLUMN IF NOT EXISTS boost_ref VARCHAR(64) DEFAULT NULL AFTER boost_verified_at;

CREATE INDEX IF NOT EXISTS idx_miner_boost_status ON poado_miner_profiles (boost_status);
CREATE INDEX IF NOT EXISTS idx_miner_boost_ref ON poado_miner_profiles (boost_ref);

-- Optional sanity reset:
-- UPDATE poado_miner_profiles
-- SET boost_selected_ema = 0,
--     boost_extra_steps = 0,
--     boost_multiplier_add = 0,
--     boost_daily_cap_add = 0,
--     boost_status = 'idle',
--     boost_verified_at = NULL,
--     boost_ref = NULL;
