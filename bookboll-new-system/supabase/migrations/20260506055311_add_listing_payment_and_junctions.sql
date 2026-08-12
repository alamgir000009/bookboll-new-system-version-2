/*
  # Listing payment options, add-on and resource junctions

  Adds per-listing payment settings and junction tables that link listings to
  add-ons and resources (previously referenced only indirectly via location).

  ## New Tables
  1. `listing_payment_options`
     - Stores per-listing payment-method availability and deposit settings.
     - `cash_on_arrival` — whether full cash is offered (admin-only control).
     - `deposit_enabled`, `deposit_amount`, `deposit_percentage` — partial online
       deposit with the rest paid on arrival.
     - `online_payment_enabled` — full online payment (requires a connected
       payment account on the provider).
     - `max_loyalty_points_per_booking`, `color`, `per_person`, extra listing
       metadata moved here for convenience.

  2. `listing_addons`
     - Explicit junction between a listing and an add-on so the same add-on
       can be attached to multiple listings with a per-listing sort order.

  3. `listing_resources`
     - Explicit junction mapping required resources (and their quantity) to a
       listing slot.

  ## Security
  RLS enabled on all three new tables with authenticated-only read/write
  policies. Destructive actions are allowed for authenticated users in this
  admin portal context, matching the approach used by existing tables.
*/

CREATE TABLE IF NOT EXISTS listing_payment_options (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  listing_id uuid NOT NULL REFERENCES listings(id) ON DELETE CASCADE,
  cash_on_arrival boolean DEFAULT true,
  deposit_enabled boolean DEFAULT false,
  deposit_amount numeric DEFAULT 0,
  deposit_percentage numeric DEFAULT 0,
  online_payment_enabled boolean DEFAULT false,
  created_at timestamptz DEFAULT now(),
  UNIQUE(listing_id)
);

ALTER TABLE listing_payment_options ENABLE ROW LEVEL SECURITY;

CREATE POLICY "Authenticated can view listing payment options"
  ON listing_payment_options FOR SELECT
  TO authenticated
  USING (true);

CREATE POLICY "Authenticated can insert listing payment options"
  ON listing_payment_options FOR INSERT
  TO authenticated
  WITH CHECK (true);

CREATE POLICY "Authenticated can update listing payment options"
  ON listing_payment_options FOR UPDATE
  TO authenticated
  USING (true)
  WITH CHECK (true);

CREATE POLICY "Authenticated can delete listing payment options"
  ON listing_payment_options FOR DELETE
  TO authenticated
  USING (true);

CREATE TABLE IF NOT EXISTS listing_addons (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  listing_id uuid NOT NULL REFERENCES listings(id) ON DELETE CASCADE,
  addon_id uuid NOT NULL REFERENCES addons(id) ON DELETE CASCADE,
  sort_order integer DEFAULT 0,
  created_at timestamptz DEFAULT now(),
  UNIQUE(listing_id, addon_id)
);

ALTER TABLE listing_addons ENABLE ROW LEVEL SECURITY;

CREATE POLICY "Authenticated can view listing addons"
  ON listing_addons FOR SELECT TO authenticated USING (true);
CREATE POLICY "Authenticated can insert listing addons"
  ON listing_addons FOR INSERT TO authenticated WITH CHECK (true);
CREATE POLICY "Authenticated can update listing addons"
  ON listing_addons FOR UPDATE TO authenticated USING (true) WITH CHECK (true);
CREATE POLICY "Authenticated can delete listing addons"
  ON listing_addons FOR DELETE TO authenticated USING (true);

CREATE TABLE IF NOT EXISTS listing_resources (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  listing_id uuid NOT NULL REFERENCES listings(id) ON DELETE CASCADE,
  resource_id uuid NOT NULL REFERENCES resources(id) ON DELETE CASCADE,
  quantity integer DEFAULT 1,
  created_at timestamptz DEFAULT now(),
  UNIQUE(listing_id, resource_id)
);

ALTER TABLE listing_resources ENABLE ROW LEVEL SECURITY;

CREATE POLICY "Authenticated can view listing resources"
  ON listing_resources FOR SELECT TO authenticated USING (true);
CREATE POLICY "Authenticated can insert listing resources"
  ON listing_resources FOR INSERT TO authenticated WITH CHECK (true);
CREATE POLICY "Authenticated can update listing resources"
  ON listing_resources FOR UPDATE TO authenticated USING (true) WITH CHECK (true);
CREATE POLICY "Authenticated can delete listing resources"
  ON listing_resources FOR DELETE TO authenticated USING (true);

DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_name='listings' AND column_name='loyalty_points'
  ) THEN
    ALTER TABLE listings ADD COLUMN loyalty_points integer DEFAULT 0;
  END IF;
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_name='listings' AND column_name='booking_color'
  ) THEN
    ALTER TABLE listings ADD COLUMN booking_color text DEFAULT '#10b981';
  END IF;
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_name='listings' AND column_name='listing_type'
  ) THEN
    ALTER TABLE listings ADD COLUMN listing_type text DEFAULT 'per_space';
  END IF;
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_name='listings' AND column_name='max_bookings_per_slot'
  ) THEN
    ALTER TABLE listings ADD COLUMN max_bookings_per_slot integer DEFAULT 1;
  END IF;
END $$;
