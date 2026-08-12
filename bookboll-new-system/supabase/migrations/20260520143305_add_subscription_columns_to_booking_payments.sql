/*
  # Extend booking_payments for Subscription Split Transactions

  ## Summary
  Adds two nullable columns to the existing booking_payments table so that
  subscription payments (after a 14-day free trial converts) can be recorded
  alongside facility-booking payments without any structural changes to the
  existing rows or UI.

  ## Changes to booking_payments

  ### New Columns
  - `subscription_id` (uuid, nullable) — FK to class_subscriptions. NULL for all
    existing booking rows. Set for subscription payment rows.
  - `source_type` (text, default 'booking') — discriminator: 'booking' | 'subscription'.
    Existing rows automatically get 'booking' via the DEFAULT; new subscription rows
    are inserted with 'subscription'.

  ## Design Notes
  - Each subscription payment (after free-trial conversion) produces TWO rows:
      1. Bookboll commission row  — recipient='@bookboll',  payment_type='subscription_commission'
      2. Provider earnings row    — recipient='@<username>', payment_type='subscription_earning',
                                   status starts as 'on_hold', changes to 'available_for_payout'
                                   when the wallet hold period elapses.
  - The new status value 'available_for_payout' is intentionally not a DB-level ENUM so
    it can be added without a migration; it is enforced at the application layer.

  ## New Index
  - idx_booking_payments_subscription_id — speeds up lookups by subscription_id.
*/

DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_name = 'booking_payments' AND column_name = 'subscription_id'
  ) THEN
    ALTER TABLE booking_payments ADD COLUMN subscription_id uuid REFERENCES class_subscriptions(id) ON DELETE SET NULL;
  END IF;
END $$;

DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_name = 'booking_payments' AND column_name = 'source_type'
  ) THEN
    ALTER TABLE booking_payments ADD COLUMN source_type text NOT NULL DEFAULT 'booking';
  END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_booking_payments_subscription_id ON booking_payments(subscription_id);
CREATE INDEX IF NOT EXISTS idx_booking_payments_source_type ON booking_payments(source_type);
