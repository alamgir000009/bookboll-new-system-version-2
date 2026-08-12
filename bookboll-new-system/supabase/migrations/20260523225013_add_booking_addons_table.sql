/*
  # Add booking_addons table

  ## Summary
  Stores add-on line items attached to individual bookings, enabling
  per-booking add-on tracking with quantity, unit price, and subtotal.

  ## New Tables
  - `booking_addons`
    - `id` (uuid, PK)
    - `booking_id` (uuid, FK → bookings, cascade delete)
    - `addon_id` (uuid, FK → addons)
    - `quantity` (integer, min 1)
    - `unit_price` (numeric, price at time of booking)
    - `subtotal` (numeric, quantity × unit_price)
    - `created_at` (timestamptz)

  ## Security
  - RLS enabled
  - anon select policy (matches existing booking_payments pattern for admin portal)
  - anon insert policy for admin portal usage
*/

CREATE TABLE IF NOT EXISTS booking_addons (
  id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  booking_id  uuid NOT NULL REFERENCES bookings(id) ON DELETE CASCADE,
  addon_id    uuid NOT NULL REFERENCES addons(id),
  quantity    integer NOT NULL DEFAULT 1 CHECK (quantity > 0),
  unit_price  numeric NOT NULL DEFAULT 0,
  subtotal    numeric NOT NULL DEFAULT 0,
  created_at  timestamptz DEFAULT now()
);

ALTER TABLE booking_addons ENABLE ROW LEVEL SECURITY;

CREATE POLICY "Anon can read booking_addons"
  ON booking_addons FOR SELECT
  TO anon
  USING (true);

CREATE POLICY "Anon can insert booking_addons"
  ON booking_addons FOR INSERT
  TO anon
  WITH CHECK (true);

CREATE INDEX IF NOT EXISTS booking_addons_booking_id_idx ON booking_addons(booking_id);
CREATE INDEX IF NOT EXISTS booking_addons_addon_id_idx   ON booking_addons(addon_id);
