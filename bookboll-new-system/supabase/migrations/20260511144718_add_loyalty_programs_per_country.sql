/*
  # Loyalty Programs per Country

  ## Overview
  Replaces the static single loyalty config with per-country loyalty programs,
  each with its own earn/redeem rules and tier definitions.

  ## New Tables

  ### loyalty_programs
  One program per country. Super admins manage these.
  - id (uuid, pk)
  - country_id (uuid, FK → countries) — unique, one program per country
  - name (text) — e.g. "Bookboll Rewards UAE"
  - earn_rate (numeric) — points earned per 1 unit of currency spent
  - redeem_rate (numeric) — how many points = 1 unit of currency discount
  - redeem_value (numeric) — currency units per redeem_rate points
  - min_redeem (int) — minimum points needed to redeem
  - max_redeem_pct (int) — max % of booking value redeemable with points
  - expiry_months (int) — months before points expire (0 = no expiry)
  - is_active (bool)
  - created_at / updated_at

  ### loyalty_tiers
  Tiers belong to a loyalty_program.
  - id (uuid, pk)
  - program_id (uuid, FK → loyalty_programs)
  - name (text) — Bronze, Silver, Gold, Platinum
  - color (text) — hex colour for badge text
  - bg_color (text) — hex colour for badge background
  - threshold (int) — cumulative points required to reach tier
  - multiplier (numeric) — points earn multiplier for this tier
  - sort_order (int)
  - created_at

  ## Security
  - RLS enabled on both tables
  - All authenticated users can read programs/tiers
  - Only super admins (role = 'super_admin' in admin_users) can write
  - admin_users.id is the auth user id
*/

CREATE TABLE IF NOT EXISTS loyalty_programs (
  id             uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  country_id     uuid NOT NULL REFERENCES countries(id) ON DELETE CASCADE,
  name           text NOT NULL DEFAULT 'Bookboll Rewards',
  earn_rate      numeric NOT NULL DEFAULT 1,
  redeem_rate    numeric NOT NULL DEFAULT 100,
  redeem_value   numeric NOT NULL DEFAULT 5,
  min_redeem     int NOT NULL DEFAULT 100,
  max_redeem_pct int NOT NULL DEFAULT 20,
  expiry_months  int NOT NULL DEFAULT 12,
  is_active      boolean NOT NULL DEFAULT true,
  created_at     timestamptz NOT NULL DEFAULT now(),
  updated_at     timestamptz NOT NULL DEFAULT now(),
  CONSTRAINT loyalty_programs_country_unique UNIQUE (country_id)
);

CREATE TABLE IF NOT EXISTS loyalty_tiers (
  id         uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  program_id uuid NOT NULL REFERENCES loyalty_programs(id) ON DELETE CASCADE,
  name       text NOT NULL,
  color      text NOT NULL DEFAULT '#92400e',
  bg_color   text NOT NULL DEFAULT '#fef3c7',
  threshold  int NOT NULL DEFAULT 0,
  multiplier numeric NOT NULL DEFAULT 1,
  sort_order int NOT NULL DEFAULT 0,
  created_at timestamptz NOT NULL DEFAULT now()
);

ALTER TABLE loyalty_programs ENABLE ROW LEVEL SECURITY;
ALTER TABLE loyalty_tiers ENABLE ROW LEVEL SECURITY;

-- All authenticated users can read
CREATE POLICY "Authenticated users can read loyalty programs"
  ON loyalty_programs FOR SELECT
  TO authenticated
  USING (true);

CREATE POLICY "Super admins can insert loyalty programs"
  ON loyalty_programs FOR INSERT
  TO authenticated
  WITH CHECK (
    EXISTS (
      SELECT 1 FROM admin_users
      WHERE admin_users.id = auth.uid()
      AND admin_users.role = 'super_admin'
    )
  );

CREATE POLICY "Super admins can update loyalty programs"
  ON loyalty_programs FOR UPDATE
  TO authenticated
  USING (
    EXISTS (
      SELECT 1 FROM admin_users
      WHERE admin_users.id = auth.uid()
      AND admin_users.role = 'super_admin'
    )
  )
  WITH CHECK (
    EXISTS (
      SELECT 1 FROM admin_users
      WHERE admin_users.id = auth.uid()
      AND admin_users.role = 'super_admin'
    )
  );

CREATE POLICY "Super admins can delete loyalty programs"
  ON loyalty_programs FOR DELETE
  TO authenticated
  USING (
    EXISTS (
      SELECT 1 FROM admin_users
      WHERE admin_users.id = auth.uid()
      AND admin_users.role = 'super_admin'
    )
  );

CREATE POLICY "Authenticated users can read loyalty tiers"
  ON loyalty_tiers FOR SELECT
  TO authenticated
  USING (true);

CREATE POLICY "Super admins can insert loyalty tiers"
  ON loyalty_tiers FOR INSERT
  TO authenticated
  WITH CHECK (
    EXISTS (
      SELECT 1 FROM admin_users
      WHERE admin_users.id = auth.uid()
      AND admin_users.role = 'super_admin'
    )
  );

CREATE POLICY "Super admins can update loyalty tiers"
  ON loyalty_tiers FOR UPDATE
  TO authenticated
  USING (
    EXISTS (
      SELECT 1 FROM admin_users
      WHERE admin_users.id = auth.uid()
      AND admin_users.role = 'super_admin'
    )
  )
  WITH CHECK (
    EXISTS (
      SELECT 1 FROM admin_users
      WHERE admin_users.id = auth.uid()
      AND admin_users.role = 'super_admin'
    )
  );

CREATE POLICY "Super admins can delete loyalty tiers"
  ON loyalty_tiers FOR DELETE
  TO authenticated
  USING (
    EXISTS (
      SELECT 1 FROM admin_users
      WHERE admin_users.id = auth.uid()
      AND admin_users.role = 'super_admin'
    )
  );
