/*
  # Multi-country provider wallets

  Until now a provider had exactly ONE wallet (one row in provider_wallets keyed by
  owner_user_id, single currency from the provider's country), which is why providers
  were locked to a single country. To let a provider operate in multiple countries we
  make the wallet PER COUNTRY: one provider_wallets row per (owner_user_id, country_id),
  each with its own currency, bank/payout account, balances, hold period and withdrawals.

  A sellable item's country comes from its location (location -> city -> country); the
  matching country wallet receives the provider's share of full-online bookings and
  subscription charges.

  Changes:
    - provider_wallets: + country_id, + per-wallet payout columns (mirror admin_users.payout_*),
      UNIQUE(owner_user_id, country_id). Backfill country_id = owner's home country and copy
      the owner's admin_users.payout_* into the (home) wallet so existing providers are intact.
    - wallet_transactions + withdrawal_requests: + country_id, backfilled from the owner's
      home country, so ledgers/balances/withdrawals are scoped per country/currency.

  Idempotent: safe to re-run.
*/

-- 1. provider_wallets: country + per-wallet payout account ---------------------
ALTER TABLE provider_wallets ADD COLUMN IF NOT EXISTS country_id            uuid REFERENCES countries(id);
ALTER TABLE provider_wallets ADD COLUMN IF NOT EXISTS payout_bank_country   text;
ALTER TABLE provider_wallets ADD COLUMN IF NOT EXISTS payout_bank_name      text;
ALTER TABLE provider_wallets ADD COLUMN IF NOT EXISTS payout_account_holder text;
ALTER TABLE provider_wallets ADD COLUMN IF NOT EXISTS payout_iban           text;
ALTER TABLE provider_wallets ADD COLUMN IF NOT EXISTS payout_account_number text;
ALTER TABLE provider_wallets ADD COLUMN IF NOT EXISTS payout_routing_number text;
ALTER TABLE provider_wallets ADD COLUMN IF NOT EXISTS payout_swift          text;
ALTER TABLE provider_wallets ADD COLUMN IF NOT EXISTS payout_account_type   text;

-- Backfill each existing wallet to the owner's home country and copy bank details.
UPDATE provider_wallets w
SET country_id            = COALESCE(w.country_id, u.country_id),
    payout_bank_country   = COALESCE(w.payout_bank_country,   u.payout_bank_country),
    payout_bank_name      = COALESCE(w.payout_bank_name,      u.payout_bank_name),
    payout_account_holder = COALESCE(w.payout_account_holder, u.payout_account_holder),
    payout_iban           = COALESCE(w.payout_iban,           u.payout_iban),
    payout_account_number = COALESCE(w.payout_account_number, u.payout_account_number),
    payout_routing_number = COALESCE(w.payout_routing_number, u.payout_routing_number),
    payout_swift          = COALESCE(w.payout_swift,          u.payout_swift)
FROM admin_users u
WHERE u.id = w.owner_user_id;

-- Fill currency from the home country where it's still missing.
UPDATE provider_wallets w
SET currency = c.currency_code
FROM countries c
WHERE w.country_id = c.id
  AND (w.currency IS NULL OR w.currency = '');

-- One wallet per (owner, country). (No prior unique on owner_user_id — the original
-- provider_id UNIQUE was dropped when provider_id was removed.)
DO $$ BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM pg_constraint WHERE conname = 'provider_wallets_owner_country_uniq'
  ) THEN
    ALTER TABLE provider_wallets
      ADD CONSTRAINT provider_wallets_owner_country_uniq UNIQUE (owner_user_id, country_id);
  END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_provider_wallets_owner_country
  ON provider_wallets(owner_user_id, country_id);

-- 2. Scope the ledger + withdrawals per country -------------------------------
ALTER TABLE wallet_transactions ADD COLUMN IF NOT EXISTS country_id uuid REFERENCES countries(id);
ALTER TABLE withdrawal_requests ADD COLUMN IF NOT EXISTS country_id uuid REFERENCES countries(id);

-- Existing rows all belong to the owner's (only) home-country wallet today.
UPDATE wallet_transactions t
SET country_id = u.country_id
FROM admin_users u
WHERE u.id = t.owner_user_id AND t.country_id IS NULL;

UPDATE withdrawal_requests r
SET country_id = u.country_id
FROM admin_users u
WHERE u.id = r.owner_user_id AND r.country_id IS NULL;

CREATE INDEX IF NOT EXISTS idx_wallet_tx_country ON wallet_transactions(country_id);
CREATE INDEX IF NOT EXISTS idx_withdrawals_country ON withdrawal_requests(country_id);
