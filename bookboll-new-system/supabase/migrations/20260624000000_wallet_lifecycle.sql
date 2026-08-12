/*
  # Wallet lifecycle: multiple wallets per country (current + legacy) + binding

  Switching a country's payout gateway must NOT migrate live subscriptions across rails.
  So a country can now have SEVERAL provider_wallets over time but exactly ONE "current"
  wallet. Each subscription is BOUND to the wallet it was created under and keeps paying
  into it for life; switching the gateway creates a NEW current wallet and demotes the old
  one to "legacy" (still paying out its existing subscriptions until they end).

  Changes:
    - provider_wallets: + is_current, + status ('current'|'legacy'|'closed'); replace the
      one-wallet-per-country UNIQUE with a PARTIAL unique (only one CURRENT per country).
    - class_subscriptions: + wallet_id (bound wallet) + country_id; backfill to the current
      wallet for the program's (owner, country).
    - wallet_transactions + withdrawal_requests: + wallet_id (country_id alone is now
      ambiguous); backfill from the owner's current wallet for that country.

  Idempotent: safe to re-run. Requires 20260623000000_multi_country_wallets first.
*/

-- 1. provider_wallets: current/legacy lifecycle ------------------------------
ALTER TABLE provider_wallets ADD COLUMN IF NOT EXISTS is_current boolean NOT NULL DEFAULT true;
ALTER TABLE provider_wallets ADD COLUMN IF NOT EXISTS status     text    NOT NULL DEFAULT 'current';

-- Replace the strict one-per-country uniqueness with "only one CURRENT per country".
ALTER TABLE provider_wallets DROP CONSTRAINT IF EXISTS provider_wallets_owner_country_uniq;
CREATE UNIQUE INDEX IF NOT EXISTS provider_wallets_one_current_per_country
  ON provider_wallets(owner_user_id, country_id) WHERE is_current;

-- 2. class_subscriptions: bind each subscription to a wallet -----------------
ALTER TABLE class_subscriptions ADD COLUMN IF NOT EXISTS country_id uuid REFERENCES countries(id);
ALTER TABLE class_subscriptions ADD COLUMN IF NOT EXISTS wallet_id  uuid REFERENCES provider_wallets(id);

-- Country = program → location → city → country.
UPDATE class_subscriptions s
SET country_id = ci.country_id
FROM class_programs p
JOIN locations l ON l.id = p.location_id
JOIN cities ci  ON ci.id = l.city_id
WHERE s.program_id = p.id AND s.country_id IS NULL;

-- Bind to the owner's CURRENT wallet for that country.
UPDATE class_subscriptions s
SET wallet_id = w.id
FROM class_programs p, provider_wallets w
WHERE s.program_id = p.id
  AND w.owner_user_id = p.owner_user_id
  AND w.country_id    = s.country_id
  AND w.is_current    = true
  AND s.wallet_id IS NULL;

-- 3. Stamp the ledger + withdrawals with their wallet -----------------------
ALTER TABLE wallet_transactions ADD COLUMN IF NOT EXISTS wallet_id uuid REFERENCES provider_wallets(id);
ALTER TABLE withdrawal_requests ADD COLUMN IF NOT EXISTS wallet_id uuid REFERENCES provider_wallets(id);

UPDATE wallet_transactions t
SET wallet_id = w.id
FROM provider_wallets w
WHERE w.owner_user_id = t.owner_user_id AND w.country_id = t.country_id AND w.is_current = true
  AND t.wallet_id IS NULL;

UPDATE withdrawal_requests r
SET wallet_id = w.id
FROM provider_wallets w
WHERE w.owner_user_id = r.owner_user_id AND w.country_id = r.country_id AND w.is_current = true
  AND r.wallet_id IS NULL;

CREATE INDEX IF NOT EXISTS idx_class_subscriptions_wallet ON class_subscriptions(wallet_id);
CREATE INDEX IF NOT EXISTS idx_wallet_tx_wallet ON wallet_transactions(wallet_id);
CREATE INDEX IF NOT EXISTS idx_withdrawals_wallet ON withdrawal_requests(wallet_id);
