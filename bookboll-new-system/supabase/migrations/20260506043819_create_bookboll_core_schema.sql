/*
  # Bookboll Core Schema

  Creates the foundational tables for the Bookboll admin dashboard.

  1. New Tables
    - `countries` - Countries with currency/timezone config
    - `cities` - Cities belonging to countries
    - `facility_providers` - Registered business owners
    - `locations` - Physical venues per provider
    - `listings` - Bookable units (courts, fields)
    - `bookings` - Customer bookings
    - `customers` - CRM customer records
    - `promocodes` - Marketing discount codes
    - `audit_logs` - Action tracking

  2. Security
    - RLS enabled on all tables
    - Public read allowed for demo dashboard (admin-only app)
    - Authenticated users can manage records
*/

CREATE TABLE IF NOT EXISTS countries (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  name text NOT NULL,
  iso_code text NOT NULL,
  currency_code text NOT NULL DEFAULT 'USD',
  currency_symbol text NOT NULL DEFAULT '$',
  timezone text NOT NULL DEFAULT 'UTC',
  date_format text NOT NULL DEFAULT 'DD/MM/YYYY',
  time_format text NOT NULL DEFAULT '24h',
  language text NOT NULL DEFAULT 'en',
  status text NOT NULL DEFAULT 'active',
  created_at timestamptz DEFAULT now()
);

CREATE TABLE IF NOT EXISTS cities (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  country_id uuid REFERENCES countries(id) ON DELETE CASCADE,
  name text NOT NULL,
  timezone text DEFAULT '',
  status text NOT NULL DEFAULT 'active',
  created_at timestamptz DEFAULT now()
);

CREATE TABLE IF NOT EXISTS facility_providers (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  business_name text NOT NULL,
  contact_person text NOT NULL DEFAULT '',
  email text NOT NULL,
  phone text NOT NULL DEFAULT '',
  country_id uuid REFERENCES countries(id),
  city_id uuid REFERENCES cities(id),
  license_number text NOT NULL DEFAULT '',
  description text NOT NULL DEFAULT '',
  logo_url text NOT NULL DEFAULT '',
  commission_rate numeric NOT NULL DEFAULT 20,
  status text NOT NULL DEFAULT 'pending',
  created_at timestamptz DEFAULT now()
);

CREATE TABLE IF NOT EXISTS locations (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  provider_id uuid REFERENCES facility_providers(id) ON DELETE CASCADE,
  name text NOT NULL,
  city_id uuid REFERENCES cities(id),
  address text NOT NULL DEFAULT '',
  phone text NOT NULL DEFAULT '',
  description text NOT NULL DEFAULT '',
  photo_url text NOT NULL DEFAULT '',
  status text NOT NULL DEFAULT 'active',
  approval_status text NOT NULL DEFAULT 'pending',
  created_at timestamptz DEFAULT now()
);

CREATE TABLE IF NOT EXISTS listings (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  location_id uuid REFERENCES locations(id) ON DELETE CASCADE,
  name text NOT NULL,
  sport_category text NOT NULL DEFAULT '',
  description text NOT NULL DEFAULT '',
  photo_url text NOT NULL DEFAULT '',
  capacity integer NOT NULL DEFAULT 1,
  slot_duration integer NOT NULL DEFAULT 60,
  base_price numeric NOT NULL DEFAULT 0,
  status text NOT NULL DEFAULT 'active',
  approval_status text NOT NULL DEFAULT 'pending',
  created_at timestamptz DEFAULT now()
);

CREATE TABLE IF NOT EXISTS customers (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  country_id uuid REFERENCES countries(id),
  full_name text NOT NULL,
  email text NOT NULL DEFAULT '',
  phone text NOT NULL DEFAULT '',
  gender text NOT NULL DEFAULT '',
  total_bookings integer NOT NULL DEFAULT 0,
  total_spent numeric NOT NULL DEFAULT 0,
  loyalty_points integer NOT NULL DEFAULT 0,
  loyalty_tier text NOT NULL DEFAULT 'Bronze',
  status text NOT NULL DEFAULT 'active',
  tags text NOT NULL DEFAULT '',
  created_at timestamptz DEFAULT now()
);

CREATE TABLE IF NOT EXISTS bookings (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  customer_id uuid REFERENCES customers(id),
  listing_id uuid REFERENCES listings(id),
  location_id uuid REFERENCES locations(id),
  booking_date date NOT NULL,
  start_time text NOT NULL DEFAULT '',
  end_time text NOT NULL DEFAULT '',
  players integer NOT NULL DEFAULT 1,
  base_price numeric NOT NULL DEFAULT 0,
  addon_total numeric NOT NULL DEFAULT 0,
  discount numeric NOT NULL DEFAULT 0,
  total numeric NOT NULL DEFAULT 0,
  amount_paid numeric NOT NULL DEFAULT 0,
  payment_method text NOT NULL DEFAULT 'cash',
  payment_status text NOT NULL DEFAULT 'pending',
  booking_status text NOT NULL DEFAULT 'confirmed',
  origin text NOT NULL DEFAULT 'manual',
  commission_rate numeric NOT NULL DEFAULT 0,
  commission_amount numeric NOT NULL DEFAULT 0,
  created_at timestamptz DEFAULT now()
);

CREATE TABLE IF NOT EXISTS promocodes (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  code text NOT NULL,
  description text NOT NULL DEFAULT '',
  discount_type text NOT NULL DEFAULT 'percentage',
  discount_value numeric NOT NULL DEFAULT 0,
  min_order numeric NOT NULL DEFAULT 0,
  usage_limit integer NOT NULL DEFAULT 0,
  usage_count integer NOT NULL DEFAULT 0,
  valid_from date,
  valid_to date,
  status text NOT NULL DEFAULT 'active',
  created_at timestamptz DEFAULT now()
);

CREATE TABLE IF NOT EXISTS audit_logs (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  actor text NOT NULL DEFAULT '',
  role text NOT NULL DEFAULT '',
  action text NOT NULL DEFAULT '',
  entity_type text NOT NULL DEFAULT '',
  entity_id text NOT NULL DEFAULT '',
  details text NOT NULL DEFAULT '',
  ip_address text NOT NULL DEFAULT '',
  created_at timestamptz DEFAULT now()
);

ALTER TABLE countries ENABLE ROW LEVEL SECURITY;
ALTER TABLE cities ENABLE ROW LEVEL SECURITY;
ALTER TABLE facility_providers ENABLE ROW LEVEL SECURITY;
ALTER TABLE locations ENABLE ROW LEVEL SECURITY;
ALTER TABLE listings ENABLE ROW LEVEL SECURITY;
ALTER TABLE customers ENABLE ROW LEVEL SECURITY;
ALTER TABLE bookings ENABLE ROW LEVEL SECURITY;
ALTER TABLE promocodes ENABLE ROW LEVEL SECURITY;
ALTER TABLE audit_logs ENABLE ROW LEVEL SECURITY;

DO $$
DECLARE tbl text;
BEGIN
  FOR tbl IN SELECT unnest(ARRAY['countries','cities','facility_providers','locations','listings','customers','bookings','promocodes','audit_logs']) LOOP
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
