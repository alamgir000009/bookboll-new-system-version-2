/*
  # Provider Wallets

  1. New Tables
    - `provider_wallets` - one balance row per provider
      - `id` uuid
      - `provider_id` uuid (unique)
      - `available_balance` numeric  - funds ready to withdraw
      - `pending_balance` numeric    - funds awaiting clearance
      - `lifetime_earnings` numeric
      - `lifetime_withdrawn` numeric
      - `currency` text
    - `wallet_transactions` - ledger of all credit/debit movements
      - `id` uuid
      - `provider_id` uuid
      - `type` text  (booking_online, subscription_online, withdrawal, adjustment)
      - `direction` text (credit|debit)
      - `amount` numeric   (gross amount)
      - `provider_share` numeric  (net after platform commission)
      - `platform_fee` numeric
      - `source_id` uuid  (booking/subscription/withdrawal id - free form)
      - `source_label` text
      - `description` text
      - `status` text (completed|pending)
      - `created_at` timestamptz
    - `withdrawal_requests` - provider-initiated cashouts
      - `id` uuid
      - `provider_id` uuid
      - `amount` numeric
      - `currency` text
      - `payout_method` text (bank|wallet|card)
      - `payout_details` text
      - `status` text (pending|approved|rejected|paid)
      - `rejection_reason` text
      - `requested_at` timestamptz
      - `processed_at` timestamptz
      - `processed_by` text
  2. Security
    - RLS enabled, authenticated CRUD policies.
*/

CREATE TABLE IF NOT EXISTS provider_wallets (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  provider_id uuid UNIQUE REFERENCES facility_providers(id) ON DELETE CASCADE,
  available_balance numeric(14,2) DEFAULT 0,
  pending_balance numeric(14,2) DEFAULT 0,
  lifetime_earnings numeric(14,2) DEFAULT 0,
  lifetime_withdrawn numeric(14,2) DEFAULT 0,
  currency text DEFAULT 'AED',
  updated_at timestamptz DEFAULT now(),
  created_at timestamptz DEFAULT now()
);

CREATE TABLE IF NOT EXISTS wallet_transactions (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  provider_id uuid REFERENCES facility_providers(id) ON DELETE CASCADE,
  type text NOT NULL DEFAULT 'booking_online',
  direction text NOT NULL DEFAULT 'credit',
  amount numeric(14,2) NOT NULL DEFAULT 0,
  provider_share numeric(14,2) DEFAULT 0,
  platform_fee numeric(14,2) DEFAULT 0,
  source_id uuid,
  source_label text DEFAULT '',
  description text DEFAULT '',
  status text DEFAULT 'completed',
  currency text DEFAULT 'AED',
  created_at timestamptz DEFAULT now()
);

CREATE TABLE IF NOT EXISTS withdrawal_requests (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  provider_id uuid REFERENCES facility_providers(id) ON DELETE CASCADE,
  amount numeric(14,2) NOT NULL,
  currency text DEFAULT 'AED',
  payout_method text DEFAULT 'bank',
  payout_details text DEFAULT '',
  status text DEFAULT 'pending',
  rejection_reason text DEFAULT '',
  requested_at timestamptz DEFAULT now(),
  processed_at timestamptz,
  processed_by text DEFAULT ''
);

ALTER TABLE provider_wallets ENABLE ROW LEVEL SECURITY;
ALTER TABLE wallet_transactions ENABLE ROW LEVEL SECURITY;
ALTER TABLE withdrawal_requests ENABLE ROW LEVEL SECURITY;

CREATE POLICY "Authenticated read provider_wallets" ON provider_wallets FOR SELECT TO authenticated USING (true);
CREATE POLICY "Authenticated insert provider_wallets" ON provider_wallets FOR INSERT TO authenticated WITH CHECK (true);
CREATE POLICY "Authenticated update provider_wallets" ON provider_wallets FOR UPDATE TO authenticated USING (true) WITH CHECK (true);
CREATE POLICY "Authenticated delete provider_wallets" ON provider_wallets FOR DELETE TO authenticated USING (true);

CREATE POLICY "Authenticated read wallet_transactions" ON wallet_transactions FOR SELECT TO authenticated USING (true);
CREATE POLICY "Authenticated insert wallet_transactions" ON wallet_transactions FOR INSERT TO authenticated WITH CHECK (true);
CREATE POLICY "Authenticated update wallet_transactions" ON wallet_transactions FOR UPDATE TO authenticated USING (true) WITH CHECK (true);
CREATE POLICY "Authenticated delete wallet_transactions" ON wallet_transactions FOR DELETE TO authenticated USING (true);

CREATE POLICY "Authenticated read withdrawal_requests" ON withdrawal_requests FOR SELECT TO authenticated USING (true);
CREATE POLICY "Authenticated insert withdrawal_requests" ON withdrawal_requests FOR INSERT TO authenticated WITH CHECK (true);
CREATE POLICY "Authenticated update withdrawal_requests" ON withdrawal_requests FOR UPDATE TO authenticated USING (true) WITH CHECK (true);
CREATE POLICY "Authenticated delete withdrawal_requests" ON withdrawal_requests FOR DELETE TO authenticated USING (true);

CREATE INDEX IF NOT EXISTS idx_wallet_tx_provider ON wallet_transactions(provider_id);
CREATE INDEX IF NOT EXISTS idx_wallet_tx_created ON wallet_transactions(created_at DESC);
CREATE INDEX IF NOT EXISTS idx_withdrawals_provider ON withdrawal_requests(provider_id);
CREATE INDEX IF NOT EXISTS idx_withdrawals_status ON withdrawal_requests(status);
