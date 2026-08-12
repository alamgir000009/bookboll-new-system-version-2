/*
  # Wallet hold period (Stripe-style payout delay)

  ## Summary
  Subscription credits now land in the wallet as `on_hold` for a configurable
  number of days before becoming available to withdraw. Each provider wallet
  carries its own `hold_days`. Each transaction records the exact date the
  funds become available plus a snapshot of the hold policy at the moment of
  credit.

  ## New Columns

  ### provider_wallets
  - `hold_days` (int, default 7) — rolling hold period for subscription credits

  ### wallet_transactions
  - `available_on` (date) — the date funds move from pending to available
  - `hold_days_snapshot` (int) — hold policy captured at credit time
  - `released_at` (timestamptz) — filled when an on-hold credit is released

  ## Status values
  `wallet_transactions.status` now also supports 'on_hold' and 'reversed'.

  ## Notes
  Existing rows default to hold_days=7, and existing transactions are left
  as `completed` — only new subscription credits use the hold lifecycle.
*/

DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='provider_wallets' AND column_name='hold_days') THEN
    ALTER TABLE provider_wallets ADD COLUMN hold_days integer NOT NULL DEFAULT 7;
  END IF;

  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='wallet_transactions' AND column_name='available_on') THEN
    ALTER TABLE wallet_transactions ADD COLUMN available_on date;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='wallet_transactions' AND column_name='hold_days_snapshot') THEN
    ALTER TABLE wallet_transactions ADD COLUMN hold_days_snapshot integer;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='wallet_transactions' AND column_name='released_at') THEN
    ALTER TABLE wallet_transactions ADD COLUMN released_at timestamptz;
  END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_wallet_tx_status_avail ON wallet_transactions(status, available_on);
