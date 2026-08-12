/*
  # Add schedule, pricing, and management tables

  1. New Tables
    - `location_hours` - Weekly operating hours for each location (day 0-6, open/close)
    - `listing_hours` - Weekly availability for each listing (day 0-6, open/close, inherit flag)
    - `pricing_groups` - Pricing group definitions (Pattern A), e.g. Peak/Off-Peak with price
    - `pricing_cells` - Per slot pricing; references group (Pattern A) or direct price (Pattern B)
    - `payment_accounts` - Bank accounts per provider for payouts
    - `addons` - Add-on items (e.g., water, bibs) per provider/location
    - `resources` - Bookable resources (staff, equipment)
    - `special_days` - Holidays / special pricing dates
    - `admin_users` - Admin / staff users with roles
    - `payouts` - Scheduled and completed provider payouts

  2. Security
    - RLS enabled on all tables
    - Permissive anon+auth policies for demo admin dashboard
*/

CREATE TABLE IF NOT EXISTS location_hours (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  location_id uuid REFERENCES locations(id) ON DELETE CASCADE,
  day_of_week smallint NOT NULL,
  is_open boolean NOT NULL DEFAULT true,
  open_time text NOT NULL DEFAULT '09:00',
  close_time text NOT NULL DEFAULT '23:00',
  created_at timestamptz DEFAULT now()
);

CREATE TABLE IF NOT EXISTS listing_hours (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  listing_id uuid REFERENCES listings(id) ON DELETE CASCADE,
  day_of_week smallint NOT NULL,
  is_open boolean NOT NULL DEFAULT true,
  inherit_from_location boolean NOT NULL DEFAULT true,
  open_time text NOT NULL DEFAULT '09:00',
  close_time text NOT NULL DEFAULT '23:00',
  created_at timestamptz DEFAULT now()
);

CREATE TABLE IF NOT EXISTS pricing_groups (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  listing_id uuid REFERENCES listings(id) ON DELETE CASCADE,
  name text NOT NULL DEFAULT '',
  color text NOT NULL DEFAULT '#0f766e',
  price numeric NOT NULL DEFAULT 0,
  sort_order integer NOT NULL DEFAULT 0,
  created_at timestamptz DEFAULT now()
);

CREATE TABLE IF NOT EXISTS pricing_cells (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  listing_id uuid REFERENCES listings(id) ON DELETE CASCADE,
  day_of_week smallint NOT NULL,
  slot_time text NOT NULL,
  pricing_group_id uuid REFERENCES pricing_groups(id) ON DELETE SET NULL,
  direct_price numeric,
  created_at timestamptz DEFAULT now()
);

CREATE TABLE IF NOT EXISTS payment_accounts (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  provider_id uuid REFERENCES facility_providers(id) ON DELETE CASCADE,
  account_holder text NOT NULL DEFAULT '',
  bank_name text NOT NULL DEFAULT '',
  iban text NOT NULL DEFAULT '',
  swift text NOT NULL DEFAULT '',
  currency text NOT NULL DEFAULT 'AED',
  is_primary boolean NOT NULL DEFAULT false,
  verified boolean NOT NULL DEFAULT false,
  created_at timestamptz DEFAULT now()
);

CREATE TABLE IF NOT EXISTS addons (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  provider_id uuid REFERENCES facility_providers(id) ON DELETE CASCADE,
  location_id uuid REFERENCES locations(id) ON DELETE CASCADE,
  name text NOT NULL DEFAULT '',
  description text NOT NULL DEFAULT '',
  price numeric NOT NULL DEFAULT 0,
  stock integer NOT NULL DEFAULT 0,
  category text NOT NULL DEFAULT '',
  status text NOT NULL DEFAULT 'active',
  created_at timestamptz DEFAULT now()
);

CREATE TABLE IF NOT EXISTS resources (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  location_id uuid REFERENCES locations(id) ON DELETE CASCADE,
  name text NOT NULL DEFAULT '',
  type text NOT NULL DEFAULT 'equipment',
  quantity integer NOT NULL DEFAULT 1,
  hourly_rate numeric NOT NULL DEFAULT 0,
  status text NOT NULL DEFAULT 'active',
  created_at timestamptz DEFAULT now()
);

CREATE TABLE IF NOT EXISTS special_days (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  country_id uuid REFERENCES countries(id) ON DELETE CASCADE,
  location_id uuid REFERENCES locations(id) ON DELETE CASCADE,
  name text NOT NULL DEFAULT '',
  date date NOT NULL,
  type text NOT NULL DEFAULT 'holiday',
  price_multiplier numeric NOT NULL DEFAULT 1,
  is_closed boolean NOT NULL DEFAULT false,
  created_at timestamptz DEFAULT now()
);

CREATE TABLE IF NOT EXISTS admin_users (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  full_name text NOT NULL DEFAULT '',
  email text NOT NULL DEFAULT '',
  role text NOT NULL DEFAULT 'staff',
  provider_id uuid REFERENCES facility_providers(id) ON DELETE SET NULL,
  country_id uuid REFERENCES countries(id) ON DELETE SET NULL,
  location_id uuid REFERENCES locations(id) ON DELETE SET NULL,
  status text NOT NULL DEFAULT 'active',
  last_login timestamptz,
  created_at timestamptz DEFAULT now()
);

CREATE TABLE IF NOT EXISTS payouts (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  provider_id uuid REFERENCES facility_providers(id) ON DELETE CASCADE,
  period_start date NOT NULL,
  period_end date NOT NULL,
  gross_amount numeric NOT NULL DEFAULT 0,
  commission_amount numeric NOT NULL DEFAULT 0,
  net_amount numeric NOT NULL DEFAULT 0,
  currency text NOT NULL DEFAULT 'AED',
  status text NOT NULL DEFAULT 'pending',
  scheduled_date date,
  paid_date date,
  reference text NOT NULL DEFAULT '',
  created_at timestamptz DEFAULT now()
);

ALTER TABLE location_hours ENABLE ROW LEVEL SECURITY;
ALTER TABLE listing_hours ENABLE ROW LEVEL SECURITY;
ALTER TABLE pricing_groups ENABLE ROW LEVEL SECURITY;
ALTER TABLE pricing_cells ENABLE ROW LEVEL SECURITY;
ALTER TABLE payment_accounts ENABLE ROW LEVEL SECURITY;
ALTER TABLE addons ENABLE ROW LEVEL SECURITY;
ALTER TABLE resources ENABLE ROW LEVEL SECURITY;
ALTER TABLE special_days ENABLE ROW LEVEL SECURITY;
ALTER TABLE admin_users ENABLE ROW LEVEL SECURITY;
ALTER TABLE payouts ENABLE ROW LEVEL SECURITY;

DO $$
DECLARE tbl text;
BEGIN
  FOR tbl IN SELECT unnest(ARRAY['location_hours','listing_hours','pricing_groups','pricing_cells','payment_accounts','addons','resources','special_days','admin_users','payouts']) LOOP
    EXECUTE format('CREATE POLICY "anon_select_%1$s" ON %1$s FOR SELECT TO anon USING (true)', tbl);
    EXECUTE format('CREATE POLICY "anon_insert_%1$s" ON %1$s FOR INSERT TO anon WITH CHECK (true)', tbl);
    EXECUTE format('CREATE POLICY "anon_update_%1$s" ON %1$s FOR UPDATE TO anon USING (true) WITH CHECK (true)', tbl);
    EXECUTE format('CREATE POLICY "anon_delete_%1$s" ON %1$s FOR DELETE TO anon USING (true)', tbl);
    EXECUTE format('CREATE POLICY "auth_select_%1$s" ON %1$s FOR SELECT TO authenticated USING (true)', tbl);
    EXECUTE format('CREATE POLICY "auth_insert_%1$s" ON %1$s FOR INSERT TO authenticated WITH CHECK (true)', tbl);
    EXECUTE format('CREATE POLICY "auth_update_%1$s" ON %1$s FOR UPDATE TO authenticated USING (true) WITH CHECK (true)', tbl);
    EXECUTE format('CREATE POLICY "auth_delete_%1$s" ON %1$s FOR DELETE TO authenticated USING (true)', tbl);
  END LOOP;
END $$;
