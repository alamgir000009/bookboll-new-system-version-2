-- ── Provider payout details on admin_users ───────────────────────────────────
-- Stores bank / payout account information for each provider_admin user so that
-- withdrawal requests can be processed without requiring re-entry every time.
--
-- payout_bank_country   : ISO-2 country code of the receiving bank (e.g. 'AE', 'US')
-- payout_bank_name      : Name of the bank  (e.g. 'Emirates NBD')
-- payout_account_holder : Legal name on the account
-- payout_iban           : IBAN (used in AE, SA, QA, EU, GB, etc.)
-- payout_account_number : Account number (used when IBAN is not applicable)
-- payout_routing_number : US ABA routing number (US only)
-- payout_swift          : SWIFT/BIC code (optional, for international wires)
-- ─────────────────────────────────────────────────────────────────────────────

ALTER TABLE admin_users
  ADD COLUMN IF NOT EXISTS payout_bank_country     TEXT,
  ADD COLUMN IF NOT EXISTS payout_bank_name        TEXT,
  ADD COLUMN IF NOT EXISTS payout_account_holder   TEXT,
  ADD COLUMN IF NOT EXISTS payout_iban             TEXT,
  ADD COLUMN IF NOT EXISTS payout_account_number   TEXT,
  ADD COLUMN IF NOT EXISTS payout_routing_number   TEXT,
  ADD COLUMN IF NOT EXISTS payout_swift            TEXT;
