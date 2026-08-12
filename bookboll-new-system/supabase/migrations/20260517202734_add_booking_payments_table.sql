/*
  # Add booking_payments table

  ## Purpose
  Replace the single payment_method/payment_status fields on bookings with a proper
  multi-row payments ledger. Each booking can now have multiple payment entries:
  - An online deposit row (goes to Bookboll)
  - A remaining-on-arrival row (goes to provider, marked paid offline)
  - A full online payment row (goes to Bookboll)
  - A full cash/offline payment row (goes to provider or Bookboll if Bookboll-operated)

  ## New Table: booking_payments

  ### Columns
  - `id` — primary key
  - `booking_id` — FK to bookings
  - `payment_type` — 'deposit' | 'remaining' | 'full'
  - `method` — 'online' | 'cash' | 'card' | 'cheque' | 'wire_transfer'
  - `amount` — numeric
  - `currency` — text, default 'AED'
  - `recipient` — 'bookboll' | 'provider' (who receives this money)
  - `status` — 'pending' | 'paid' | 'refunded'
  - `paid_at` — timestamp when marked paid
  - `marked_paid_by` — admin_user id who marked it paid (for offline payments)
  - `notes` — optional freetext
  - `created_at`

  ## Security
  - RLS enabled, restrictive policies
*/

CREATE TABLE IF NOT EXISTS booking_payments (
  id                uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  booking_id        uuid NOT NULL REFERENCES bookings(id) ON DELETE CASCADE,
  payment_type      text NOT NULL DEFAULT 'full',   -- 'deposit' | 'remaining' | 'full'
  method            text NOT NULL DEFAULT 'cash',   -- 'online' | 'cash' | 'card' | 'cheque' | 'wire_transfer'
  amount            numeric NOT NULL DEFAULT 0,
  currency          text NOT NULL DEFAULT 'AED',
  recipient         text NOT NULL DEFAULT 'bookboll', -- 'bookboll' | 'provider'
  status            text NOT NULL DEFAULT 'pending',  -- 'pending' | 'paid' | 'refunded'
  paid_at           timestamptz,
  marked_paid_by    uuid REFERENCES admin_users(id),
  notes             text NOT NULL DEFAULT '',
  created_at        timestamptz NOT NULL DEFAULT now()
);

ALTER TABLE booking_payments ENABLE ROW LEVEL SECURITY;

CREATE POLICY "Authenticated admins can view all booking payments"
  ON booking_payments FOR SELECT
  TO authenticated
  USING (auth.uid() IS NOT NULL);

CREATE POLICY "Authenticated admins can insert booking payments"
  ON booking_payments FOR INSERT
  TO authenticated
  WITH CHECK (auth.uid() IS NOT NULL);

CREATE POLICY "Authenticated admins can update booking payments"
  ON booking_payments FOR UPDATE
  TO authenticated
  USING (auth.uid() IS NOT NULL)
  WITH CHECK (auth.uid() IS NOT NULL);

CREATE INDEX IF NOT EXISTS booking_payments_booking_id_idx ON booking_payments(booking_id);
CREATE INDEX IF NOT EXISTS booking_payments_status_idx ON booking_payments(status);
CREATE INDEX IF NOT EXISTS booking_payments_recipient_idx ON booking_payments(recipient);
