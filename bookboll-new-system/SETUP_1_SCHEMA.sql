-- === 20260506043819_create_bookboll_core_schema.sql ===
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

-- === 20260506045704_add_schedule_pricing_and_mgmt_tables.sql ===
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

-- === 20260506050504_extend_pricing_groups_timerange.sql ===
/*
  # Extend pricing_groups with time range and day selection

  1. Changes
    - Add `start_time` text column to pricing_groups (HH:mm)
    - Add `end_time` text column to pricing_groups (HH:mm)
    - Add `days` text column to pricing_groups (comma-separated day indexes e.g. "1,2,3,4,5")

  These allow a pricing group to represent a time-range rule across selected days,
  matching the Groups/Slot-Grid UI pattern.
*/

DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='pricing_groups' AND column_name='start_time') THEN
    ALTER TABLE pricing_groups ADD COLUMN start_time text NOT NULL DEFAULT '18:00';
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='pricing_groups' AND column_name='end_time') THEN
    ALTER TABLE pricing_groups ADD COLUMN end_time text NOT NULL DEFAULT '22:00';
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='pricing_groups' AND column_name='days') THEN
    ALTER TABLE pricing_groups ADD COLUMN days text NOT NULL DEFAULT '1,2,3,4,5';
  END IF;
END $$;

-- === 20260506055311_add_listing_payment_and_junctions.sql ===
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

-- === 20260506063321_add_classes_and_subscriptions.sql ===
/*
  # Classes & Subscriptions

  1. New Tables
    - `class_programs` - class/subscription package defined by a provider
      - `id` uuid
      - `provider_id` uuid (facility_providers)
      - `listing_id` uuid (listing used for the class bookings)
      - `name` text
      - `description` text
      - `sport_category` text
      - `coach_name` text
      - `monthly_price` numeric
      - `currency` text
      - `sessions_per_week` integer
      - `session_duration_minutes` integer
      - `day_of_week_mask` integer[] (0-6, Sun-Sat)
      - `session_time` time
      - `capacity` integer
      - `commission_percentage` numeric default 20
      - `status` text (active/inactive)
      - `photo_url` text
    - `class_subscriptions` - user subscription instance
      - `id` uuid
      - `program_id` uuid
      - `customer_id` uuid (auth.users)
      - `customer_name` text
      - `customer_email` text
      - `started_at` date
      - `next_billing_at` date
      - `status` text (active/paused/cancelled)
      - `monthly_amount` numeric
      - `provider_payout` numeric  (monthly_amount * 0.8)
      - `platform_fee` numeric     (monthly_amount * 0.2)
    - `class_sessions` - auto-generated bookings for subscription
      - `id` uuid
      - `subscription_id` uuid
      - `program_id` uuid
      - `listing_id` uuid
      - `session_date` date
      - `session_time` time
      - `duration_minutes` integer
      - `status` text (scheduled/completed/cancelled)
  2. Security
    - RLS enabled on all three tables with authenticated policies on ownership.
*/

CREATE TABLE IF NOT EXISTS class_programs (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  provider_id uuid REFERENCES facility_providers(id) ON DELETE CASCADE,
  listing_id uuid REFERENCES listings(id) ON DELETE SET NULL,
  name text NOT NULL,
  description text DEFAULT '',
  sport_category text DEFAULT '',
  coach_name text DEFAULT '',
  monthly_price numeric(12,2) NOT NULL DEFAULT 0,
  currency text DEFAULT 'AED',
  sessions_per_week integer DEFAULT 1,
  session_duration_minutes integer DEFAULT 60,
  day_of_week_mask integer[] DEFAULT ARRAY[1]::integer[],
  session_time time DEFAULT '18:00',
  capacity integer DEFAULT 12,
  commission_percentage numeric(5,2) DEFAULT 20,
  status text DEFAULT 'active',
  photo_url text DEFAULT '',
  created_at timestamptz DEFAULT now()
);

CREATE TABLE IF NOT EXISTS class_subscriptions (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  program_id uuid REFERENCES class_programs(id) ON DELETE CASCADE,
  customer_id uuid,
  customer_name text DEFAULT '',
  customer_email text DEFAULT '',
  started_at date DEFAULT CURRENT_DATE,
  next_billing_at date DEFAULT (CURRENT_DATE + INTERVAL '1 month'),
  status text DEFAULT 'active',
  monthly_amount numeric(12,2) DEFAULT 0,
  provider_payout numeric(12,2) DEFAULT 0,
  platform_fee numeric(12,2) DEFAULT 0,
  created_at timestamptz DEFAULT now()
);

CREATE TABLE IF NOT EXISTS class_sessions (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  subscription_id uuid REFERENCES class_subscriptions(id) ON DELETE CASCADE,
  program_id uuid REFERENCES class_programs(id) ON DELETE CASCADE,
  listing_id uuid REFERENCES listings(id) ON DELETE SET NULL,
  session_date date NOT NULL,
  session_time time NOT NULL,
  duration_minutes integer DEFAULT 60,
  status text DEFAULT 'scheduled',
  created_at timestamptz DEFAULT now()
);

ALTER TABLE class_programs ENABLE ROW LEVEL SECURITY;
ALTER TABLE class_subscriptions ENABLE ROW LEVEL SECURITY;
ALTER TABLE class_sessions ENABLE ROW LEVEL SECURITY;

CREATE POLICY "Authenticated can view class_programs"
  ON class_programs FOR SELECT TO authenticated USING (true);
CREATE POLICY "Authenticated can insert class_programs"
  ON class_programs FOR INSERT TO authenticated WITH CHECK (true);
CREATE POLICY "Authenticated can update class_programs"
  ON class_programs FOR UPDATE TO authenticated USING (true) WITH CHECK (true);
CREATE POLICY "Authenticated can delete class_programs"
  ON class_programs FOR DELETE TO authenticated USING (true);

CREATE POLICY "Authenticated can view class_subscriptions"
  ON class_subscriptions FOR SELECT TO authenticated USING (true);
CREATE POLICY "Authenticated can insert class_subscriptions"
  ON class_subscriptions FOR INSERT TO authenticated WITH CHECK (true);
CREATE POLICY "Authenticated can update class_subscriptions"
  ON class_subscriptions FOR UPDATE TO authenticated USING (true) WITH CHECK (true);
CREATE POLICY "Authenticated can delete class_subscriptions"
  ON class_subscriptions FOR DELETE TO authenticated USING (true);

CREATE POLICY "Authenticated can view class_sessions"
  ON class_sessions FOR SELECT TO authenticated USING (true);
CREATE POLICY "Authenticated can insert class_sessions"
  ON class_sessions FOR INSERT TO authenticated WITH CHECK (true);
CREATE POLICY "Authenticated can update class_sessions"
  ON class_sessions FOR UPDATE TO authenticated USING (true) WITH CHECK (true);
CREATE POLICY "Authenticated can delete class_sessions"
  ON class_sessions FOR DELETE TO authenticated USING (true);

CREATE INDEX IF NOT EXISTS idx_class_programs_provider ON class_programs(provider_id);
CREATE INDEX IF NOT EXISTS idx_class_subscriptions_program ON class_subscriptions(program_id);
CREATE INDEX IF NOT EXISTS idx_class_sessions_sub ON class_sessions(subscription_id);
CREATE INDEX IF NOT EXISTS idx_class_sessions_date ON class_sessions(session_date);

-- === 20260506063915_add_provider_wallets.sql ===
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

-- === 20260511104957_add_sort_order_to_locations_and_listings.sql ===
/*
  # Add sort_order to locations and listings

  Adds a sort_order integer column to both tables so the admin can
  manually control the display order. Existing rows get their order
  seeded from created_at so nothing jumps around after the migration.
*/

DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_name = 'locations' AND column_name = 'sort_order'
  ) THEN
    ALTER TABLE locations ADD COLUMN sort_order integer DEFAULT 0;
  END IF;
END $$;

DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_name = 'listings' AND column_name = 'sort_order'
  ) THEN
    ALTER TABLE listings ADD COLUMN sort_order integer DEFAULT 0;
  END IF;
END $$;

-- Seed sort_order from created_at order per-location for listings, and globally for locations
WITH ranked AS (
  SELECT id, ROW_NUMBER() OVER (ORDER BY created_at ASC) - 1 AS rn FROM locations
)
UPDATE locations SET sort_order = ranked.rn FROM ranked WHERE locations.id = ranked.id;

WITH ranked AS (
  SELECT id, location_id, ROW_NUMBER() OVER (PARTITION BY location_id ORDER BY created_at ASC) - 1 AS rn FROM listings
)
UPDATE listings SET sort_order = ranked.rn FROM ranked WHERE listings.id = ranked.id;

-- === 20260511111929_add_eligibility_and_snapshots.sql ===
/*
  # Add eligibility control, admin notes, and change snapshots

  ## Summary
  Adds the ability for admins to pause locations/listings with a reason note,
  tracks a snapshot of field values before any edit so the Approvals page can
  show a before/after diff, and ensures every save resets approval_status to
  'pending' for re-review.

  ## New Columns

  ### locations
  - `eligibility_status` (text, default 'eligible') — 'eligible' | 'paused'
  - `admin_note` (text) — reason written by admin when pausing
  - `previous_snapshot` (jsonb) — snapshot of row values before the latest edit

  ### listings
  - `eligibility_status` (text, default 'eligible') — 'eligible' | 'paused'
  - `admin_note` (text) — reason written by admin when pausing
  - `previous_snapshot` (jsonb) — snapshot of row values before the latest edit

  ## Notes
  - existing rows default to 'eligible' and null note/snapshot
  - approval_status logic (pending/approved/rejected) unchanged; we layer on top
*/

DO $$
BEGIN
  -- locations
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='locations' AND column_name='eligibility_status') THEN
    ALTER TABLE locations ADD COLUMN eligibility_status text NOT NULL DEFAULT 'eligible';
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='locations' AND column_name='admin_note') THEN
    ALTER TABLE locations ADD COLUMN admin_note text NOT NULL DEFAULT '';
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='locations' AND column_name='previous_snapshot') THEN
    ALTER TABLE locations ADD COLUMN previous_snapshot jsonb;
  END IF;

  -- listings
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='listings' AND column_name='eligibility_status') THEN
    ALTER TABLE listings ADD COLUMN eligibility_status text NOT NULL DEFAULT 'eligible';
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='listings' AND column_name='admin_note') THEN
    ALTER TABLE listings ADD COLUMN admin_note text NOT NULL DEFAULT '';
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='listings' AND column_name='previous_snapshot') THEN
    ALTER TABLE listings ADD COLUMN previous_snapshot jsonb;
  END IF;
END $$;

-- === 20260511115011_add_program_approval_and_trial.sql ===
/*
  # Add approval, eligibility, trial agreement to class_programs and trial lifecycle to class_subscriptions

  ## Summary
  Brings class_programs into the same approval/edit/pause flow used by locations
  and listings, and adds fields to support the mandatory 14-day free trial for
  subscribers. Also introduces a provider_trial_agreements audit table to record
  each time a provider accepts the trial terms.

  ## New Columns

  ### class_programs
  - `approval_status` (text, default 'approved') — 'pending' | 'approved'
  - `eligibility_status` (text, default 'eligible') — 'eligible' | 'paused'
  - `admin_note` (text) — reason written by admin when pausing
  - `previous_snapshot` (jsonb) — snapshot of row values before the latest edit
  - `free_trial_days` (int, default 14)
  - `free_trial_enabled` (bool, default true)
  - `trial_terms_accepted_at` (timestamptz)
  - `trial_terms_version` (text)

  ### class_subscriptions
  - `trial_ends_at` (date)
  - `trial_status` (text, default 'in_trial') — 'in_trial' | 'converted' | 'cancelled_in_trial'
  - `card_on_file_id` (text)
  - `first_charge_at` (timestamptz)
  - `cancelled_during_trial_at` (timestamptz)

  ## New Tables
  - `provider_trial_agreements` — audit of every provider acceptance of trial terms

  ## Security
  - RLS enabled on provider_trial_agreements with authenticated-only access
*/

DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='class_programs' AND column_name='approval_status') THEN
    ALTER TABLE class_programs ADD COLUMN approval_status text NOT NULL DEFAULT 'approved';
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='class_programs' AND column_name='eligibility_status') THEN
    ALTER TABLE class_programs ADD COLUMN eligibility_status text NOT NULL DEFAULT 'eligible';
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='class_programs' AND column_name='admin_note') THEN
    ALTER TABLE class_programs ADD COLUMN admin_note text NOT NULL DEFAULT '';
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='class_programs' AND column_name='previous_snapshot') THEN
    ALTER TABLE class_programs ADD COLUMN previous_snapshot jsonb;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='class_programs' AND column_name='free_trial_days') THEN
    ALTER TABLE class_programs ADD COLUMN free_trial_days integer NOT NULL DEFAULT 14;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='class_programs' AND column_name='free_trial_enabled') THEN
    ALTER TABLE class_programs ADD COLUMN free_trial_enabled boolean NOT NULL DEFAULT true;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='class_programs' AND column_name='trial_terms_accepted_at') THEN
    ALTER TABLE class_programs ADD COLUMN trial_terms_accepted_at timestamptz;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='class_programs' AND column_name='trial_terms_version') THEN
    ALTER TABLE class_programs ADD COLUMN trial_terms_version text DEFAULT '';
  END IF;

  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='class_subscriptions' AND column_name='trial_ends_at') THEN
    ALTER TABLE class_subscriptions ADD COLUMN trial_ends_at date;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='class_subscriptions' AND column_name='trial_status') THEN
    ALTER TABLE class_subscriptions ADD COLUMN trial_status text NOT NULL DEFAULT 'in_trial';
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='class_subscriptions' AND column_name='card_on_file_id') THEN
    ALTER TABLE class_subscriptions ADD COLUMN card_on_file_id text DEFAULT '';
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='class_subscriptions' AND column_name='first_charge_at') THEN
    ALTER TABLE class_subscriptions ADD COLUMN first_charge_at timestamptz;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='class_subscriptions' AND column_name='cancelled_during_trial_at') THEN
    ALTER TABLE class_subscriptions ADD COLUMN cancelled_during_trial_at timestamptz;
  END IF;
END $$;

CREATE TABLE IF NOT EXISTS provider_trial_agreements (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  program_id uuid REFERENCES class_programs(id) ON DELETE CASCADE,
  provider_id uuid REFERENCES facility_providers(id) ON DELETE CASCADE,
  accepted_at timestamptz NOT NULL DEFAULT now(),
  terms_version text NOT NULL DEFAULT '2026-05-11-v1',
  commission_at_acceptance numeric(5,2) NOT NULL DEFAULT 20,
  trial_days_at_acceptance integer NOT NULL DEFAULT 14,
  accepted_by uuid
);

ALTER TABLE provider_trial_agreements ENABLE ROW LEVEL SECURITY;

DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_policies WHERE tablename='provider_trial_agreements' AND policyname='auth can read agreements') THEN
    CREATE POLICY "auth can read agreements" ON provider_trial_agreements FOR SELECT TO authenticated USING (true);
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_policies WHERE tablename='provider_trial_agreements' AND policyname='auth can insert agreements') THEN
    CREATE POLICY "auth can insert agreements" ON provider_trial_agreements FOR INSERT TO authenticated WITH CHECK (true);
  END IF;
END $$;

-- === 20260511120901_add_wallet_hold_period.sql ===
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

-- === 20260511123336_backfill_provider_admin_users.sql ===
/*
  # Backfill provider admin users for orphan businesses

  1. Purpose
    - Every `facility_providers` row should have at least one linked `admin_users` row
      whose `role = 'provider_admin'` and `provider_id` references that business.
    - Historically businesses could be created without a user, producing orphans.

  2. What this migration does
    - Finds every `facility_providers` row that currently has NO linked `admin_users`
    - Inserts one `admin_users` row per orphan business with:
      - full_name = contact_person (fallback: business_name)
      - email     = provider.email
      - role      = 'provider_admin'
      - provider_id = the business's id
      - country_id  = provider.country_id
      - status      = 'active'

  3. Safety notes
    - The INSERT is gated by a NOT EXISTS check, so re-running the migration is a no-op.
    - No data is deleted or modified in existing `admin_users` rows.
    - Emails that collide with existing users are skipped to avoid unique-constraint errors.
*/

-- Backfill provider users (only runs if provider_id column still exists — i.e. first-time run)
DO $$ BEGIN
  IF EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_name='admin_users' AND column_name='provider_id'
  ) AND EXISTS (
    SELECT 1 FROM information_schema.tables WHERE table_name='facility_providers'
  ) THEN
    INSERT INTO admin_users (full_name, email, role, provider_id, country_id, status)
    SELECT
      COALESCE(NULLIF(TRIM(fp.contact_person), ''), fp.business_name) AS full_name,
      fp.email,
      'provider_admin' AS role,
      fp.id AS provider_id,
      fp.country_id,
      'active' AS status
    FROM facility_providers fp
    WHERE NOT EXISTS (
      SELECT 1 FROM admin_users au WHERE au.provider_id = fp.id
    )
    AND fp.email IS NOT NULL
    AND fp.email <> ''
    AND NOT EXISTS (
      SELECT 1 FROM admin_users au2 WHERE lower(au2.email) = lower(fp.email)
    );
  END IF;
END $$;

-- === 20260511124146_flatten_provider_into_admin_users.sql ===
/*
  # Flatten facility_providers into admin_users

  1. Purpose
    - Eliminate the `facility_providers` table. The provider_admin user IS the business.
    - Every row that previously linked to `facility_providers(id)` now links to the
      provider_admin user's row in `admin_users(id)` via `owner_user_id`.

  2. admin_users changes
    - New columns (all nullable, relevant only for provider_admin users):
      - business_name      text
      - commission_rate    numeric(5,2) default 20
      - license_number     text
      - description        text
      - logo_url           text
      - business_status    text default 'approved'  (pending|approved|rejected)
      - phone              text
      - city_id            uuid references cities(id)
      - owner_user_id      uuid references admin_users(id) on delete set null
        (for employees: points to their provider_admin owner)

  3. Per-table changes (locations, addons, payment_accounts, payouts, provider_wallets,
     wallet_transactions, withdrawal_requests, class_programs, program_approvals,
     provider_trial_agreements)
    - Add `owner_user_id uuid references admin_users(id)`
    - Backfill from provider_id -> provider_admin.id mapping
    - Drop `provider_id` column

  4. Data backfill
    - Copy facility_providers fields onto the matching provider_admin user
    - Set owner_user_id on every employee user to their business's provider_admin.id
    - For every linked table, set owner_user_id using the same mapping

  5. Final
    - Drop `facility_providers` table.

  6. Security
    - RLS policies on the affected tables continue to rely on owner_user_id =
      auth.uid(). Since this app currently uses no auth.uid session in policies,
      existing policies remain unchanged; new column is additive.
*/

-- 1. Extend admin_users ------------------------------------------------------
DO $$ BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='admin_users' AND column_name='business_name') THEN
    ALTER TABLE admin_users ADD COLUMN business_name text;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='admin_users' AND column_name='commission_rate') THEN
    ALTER TABLE admin_users ADD COLUMN commission_rate numeric(5,2) DEFAULT 20;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='admin_users' AND column_name='license_number') THEN
    ALTER TABLE admin_users ADD COLUMN license_number text;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='admin_users' AND column_name='description') THEN
    ALTER TABLE admin_users ADD COLUMN description text;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='admin_users' AND column_name='logo_url') THEN
    ALTER TABLE admin_users ADD COLUMN logo_url text;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='admin_users' AND column_name='business_status') THEN
    ALTER TABLE admin_users ADD COLUMN business_status text DEFAULT 'approved';
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='admin_users' AND column_name='phone') THEN
    ALTER TABLE admin_users ADD COLUMN phone text;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='admin_users' AND column_name='city_id') THEN
    ALTER TABLE admin_users ADD COLUMN city_id uuid REFERENCES cities(id) ON DELETE SET NULL;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='admin_users' AND column_name='owner_user_id') THEN
    ALTER TABLE admin_users ADD COLUMN owner_user_id uuid REFERENCES admin_users(id) ON DELETE SET NULL;
  END IF;
END $$;

-- 2. Backfill admin_users business fields from facility_providers ------------
-- (skipped if provider_id or facility_providers already dropped — re-run safe)
DO $$ BEGIN
  IF EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_name='admin_users' AND column_name='provider_id'
  ) AND EXISTS (
    SELECT 1 FROM information_schema.tables WHERE table_name='facility_providers'
  ) THEN
    UPDATE admin_users au
    SET business_name    = fp.business_name,
        commission_rate  = fp.commission_rate,
        license_number   = fp.license_number,
        description      = fp.description,
        logo_url         = fp.logo_url,
        business_status  = fp.status,
        phone            = fp.phone,
        city_id          = fp.city_id
    FROM facility_providers fp
    WHERE au.provider_id = fp.id AND au.role = 'provider_admin';

    -- Set owner_user_id on employees
    UPDATE admin_users emp
    SET owner_user_id = owner.id
    FROM admin_users owner
    WHERE emp.provider_id = owner.provider_id
      AND owner.role = 'provider_admin'
      AND emp.role IN ('facility_admin','senior_employee','junior_employee','guard','staff')
      AND emp.provider_id IS NOT NULL;
  END IF;
END $$;

-- 3. Add owner_user_id to all related tables & backfill ----------------------
DO $$
DECLARE
  t text;
  tbls text[] := ARRAY['locations','addons','payment_accounts','payouts',
                       'provider_wallets','wallet_transactions','withdrawal_requests',
                       'class_programs','program_approvals','provider_trial_agreements'];
BEGIN
  FOREACH t IN ARRAY tbls LOOP
    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_name=t) THEN
      IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name=t AND column_name='owner_user_id') THEN
        EXECUTE format('ALTER TABLE %I ADD COLUMN owner_user_id uuid REFERENCES admin_users(id) ON DELETE CASCADE', t);
      END IF;
      -- Only backfill via provider_id if that column still exists on both tables
      IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='admin_users' AND column_name='provider_id')
         AND EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name=t AND column_name='provider_id') THEN
        EXECUTE format('UPDATE %I tgt SET owner_user_id = owner.id FROM admin_users owner WHERE owner.role = ''provider_admin'' AND owner.provider_id = tgt.provider_id AND tgt.owner_user_id IS NULL', t);
      END IF;
    END IF;
  END LOOP;
END $$;

-- 4. Drop old provider_id columns -------------------------------------------
DO $$
DECLARE
  t text;
  tbls text[] := ARRAY['locations','addons','payment_accounts','payouts',
                       'provider_wallets','wallet_transactions','withdrawal_requests',
                       'class_programs','program_approvals','provider_trial_agreements',
                       'admin_users'];
BEGIN
  FOREACH t IN ARRAY tbls LOOP
    IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name=t AND column_name='provider_id') THEN
      EXECUTE format('ALTER TABLE %I DROP COLUMN provider_id', t);
    END IF;
  END LOOP;
END $$;

-- 5. Drop facility_providers -------------------------------------------------
DROP TABLE IF EXISTS facility_providers CASCADE;

-- 6. Helpful indexes ---------------------------------------------------------
CREATE INDEX IF NOT EXISTS idx_admin_users_owner_user_id ON admin_users(owner_user_id);
CREATE INDEX IF NOT EXISTS idx_locations_owner_user_id ON locations(owner_user_id);
CREATE INDEX IF NOT EXISTS idx_payment_accounts_owner_user_id ON payment_accounts(owner_user_id);

-- === 20260511131227_add_location_to_class_programs.sql ===
/*
  # Add location_id to class_programs

  1. Changes
    - Add `location_id` column to `class_programs` referencing `locations(id)`
    - Backfill `location_id` from `listings.location_id` where listing_id is set
    - Make `listing_id` optional (kept for backwards reference; programs belong to a location, not a listing)

  2. Notes
    - Programs are Academy offerings attached to a location, not a specific court listing.
*/

DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_name='class_programs' AND column_name='location_id'
  ) THEN
    ALTER TABLE class_programs ADD COLUMN location_id uuid REFERENCES locations(id) ON DELETE SET NULL;
  END IF;
END $$;

UPDATE class_programs cp
SET location_id = l.location_id
FROM listings l
WHERE cp.listing_id = l.id AND cp.location_id IS NULL;

CREATE INDEX IF NOT EXISTS idx_class_programs_location_id ON class_programs(location_id);

-- === 20260511131328_add_images_to_class_programs.sql ===
/*
  # Add images jsonb column to class_programs

  1. Changes
    - Add `images` jsonb column (default empty array) for multi-image support on programs.
  2. Notes
    - Existing `photo_url` column is preserved for backwards compatibility.
*/

DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_name='class_programs' AND column_name='images'
  ) THEN
    ALTER TABLE class_programs ADD COLUMN images jsonb NOT NULL DEFAULT '[]'::jsonb;
  END IF;
END $$;

-- === 20260511141828_add_geo_coords_and_location_overrides.sql ===
/*
  # Add geographic coordinates and location-level overrides

  1. Changes to `cities`
     - `latitude`  numeric(10,7) — city center lat for reverse-geocode matching
     - `longitude` numeric(10,7) — city center lng

  2. Changes to `locations`
     - `latitude`         numeric(10,7) — exact venue lat (from geo-detection or manual)
     - `longitude`        numeric(10,7) — exact venue lng
     - `timezone_override` text          — nullable; overrides city/country timezone when set
     - `currency_override` text          — nullable; overrides country currency when set

  3. Notes
     - All new columns are nullable so existing rows are unaffected.
     - No RLS changes needed; existing policies on both tables remain.
*/

DO $$ BEGIN
  -- cities lat/lng
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='cities' AND column_name='latitude') THEN
    ALTER TABLE cities ADD COLUMN latitude numeric(10,7);
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='cities' AND column_name='longitude') THEN
    ALTER TABLE cities ADD COLUMN longitude numeric(10,7);
  END IF;

  -- locations lat/lng + overrides
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='locations' AND column_name='latitude') THEN
    ALTER TABLE locations ADD COLUMN latitude numeric(10,7);
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='locations' AND column_name='longitude') THEN
    ALTER TABLE locations ADD COLUMN longitude numeric(10,7);
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='locations' AND column_name='timezone_override') THEN
    ALTER TABLE locations ADD COLUMN timezone_override text;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='locations' AND column_name='currency_override') THEN
    ALTER TABLE locations ADD COLUMN currency_override text;
  END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_cities_coords ON cities(latitude, longitude);
CREATE INDEX IF NOT EXISTS idx_locations_coords ON locations(latitude, longitude);

-- === 20260511142730_add_unique_constraints_countries_cities.sql ===
/*
  # Add unique constraints to countries and cities

  - countries.iso_code must be unique (prevents duplicate country entries)
  - cities.(country_id, name) must be unique (prevents duplicate city per country)
*/

ALTER TABLE countries ADD CONSTRAINT countries_iso_code_key UNIQUE (iso_code);
ALTER TABLE cities    ADD CONSTRAINT cities_country_id_name_key UNIQUE (country_id, name);

-- === 20260511144718_add_loyalty_programs_per_country.sql ===
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

-- === 20260517202734_add_booking_payments_table.sql ===
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

-- === 20260517211309_fix_booking_payments_anon_policies.sql ===
/*
  # Fix booking_payments RLS to match the rest of the app

  All other tables in this project use anon-accessible policies (consistent with
  the admin dashboard pattern where no auth session is required). The booking_payments
  table was blocking all reads/writes because it only allowed authenticated users.
  Adding anon policies to match the pattern used by bookings, listings, customers, etc.
*/

CREATE POLICY "anon_select_booking_payments"
  ON booking_payments FOR SELECT
  TO anon
  USING (true);

CREATE POLICY "anon_insert_booking_payments"
  ON booking_payments FOR INSERT
  TO anon
  WITH CHECK (true);

CREATE POLICY "anon_update_booking_payments"
  ON booking_payments FOR UPDATE
  TO anon
  USING (true)
  WITH CHECK (true);

-- === 20260517211624_add_username_to_admin_users.sql ===
/*
  # Add username to admin_users

  - Adds a unique `username` text column
  - Backfills existing users with sensible lowercase usernames derived from their names/emails
*/

ALTER TABLE admin_users ADD COLUMN IF NOT EXISTS username text;

-- Backfill existing users
UPDATE admin_users SET username = 'superadmin'    WHERE email = 'admin@bookboll.com';
UPDATE admin_users SET username = 'ahmed.mansouri' WHERE email = 'ahmed@dsc.ae';
UPDATE admin_users SET username = 'mahmoud.hassan' WHERE email = 'mahmoud@cfa.eg';
UPDATE admin_users SET username = 'khalid.saud'    WHERE email = 'khalid@rpc.sa';
UPDATE admin_users SET username = 'fatima.rashidi' WHERE email = 'fatima@alwasl.ae';
UPDATE admin_users SET username = 'yusuf.farsi'    WHERE email = 'yusuf@dohaarena.qa';
UPDATE admin_users SET username = 'nadia.kamel'    WHERE email = 'nadia@cairoelite.eg';

ALTER TABLE admin_users ADD CONSTRAINT admin_users_username_unique UNIQUE (username);

-- === 20260518132216_add_program_duration_and_dates.sql ===
/*
  # Add program duration and optional start/end dates to class_programs

  1. New columns on class_programs
     - `program_type`   text DEFAULT 'ongoing' — 'ongoing' | 'fixed_duration'
     - `duration_weeks` integer nullable        — only used when type = fixed_duration
     - `start_date`     date nullable           — optional program start date
     - `end_date`       date nullable           — optional program end date (auto-filled from duration_weeks + start_date if left blank)
*/

DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'class_programs' AND column_name = 'program_type') THEN
    ALTER TABLE class_programs ADD COLUMN program_type text NOT NULL DEFAULT 'ongoing';
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'class_programs' AND column_name = 'duration_weeks') THEN
    ALTER TABLE class_programs ADD COLUMN duration_weeks integer;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'class_programs' AND column_name = 'start_date') THEN
    ALTER TABLE class_programs ADD COLUMN start_date date;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'class_programs' AND column_name = 'end_date') THEN
    ALTER TABLE class_programs ADD COLUMN end_date date;
  END IF;
END $$;

-- === 20260518133620_add_billing_interval_to_class_programs.sql ===
/*
  # Add billing_interval to class_programs

  New column:
    - `billing_interval` text NOT NULL DEFAULT 'monthly'
      Allowed values: 'weekly' | 'biweekly' | 'monthly' | 'quarterly' | 'yearly'

  Also renames the column alias in the app — the DB column stays `monthly_price`
  but represents the "price per billing interval" going forward.
*/

DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_name = 'class_programs' AND column_name = 'billing_interval'
  ) THEN
    ALTER TABLE class_programs ADD COLUMN billing_interval text NOT NULL DEFAULT 'monthly';
  END IF;
END $$;

-- === 20260518134232_add_listing_ids_array_to_class_programs.sql ===
/*
  # Add listing_ids array to class_programs

  Adds a `listing_ids` jsonb column that stores an array of listing UUIDs.
  The existing `listing_id` column is kept intact for backwards compatibility.
  New code will write to `listing_ids`; `listing_id` will hold the first element
  of that array (or null) for any legacy queries.
*/

DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_name = 'class_programs' AND column_name = 'listing_ids'
  ) THEN
    ALTER TABLE class_programs ADD COLUMN listing_ids jsonb NOT NULL DEFAULT '[]'::jsonb;
  END IF;
END $$;

-- === 20260518135558_add_program_id_and_notes_to_bookings.sql ===
/*
  # Add program_id and notes to bookings

  1. Changes
    - `bookings.program_id` (uuid, nullable, FK to class_programs) — links auto-generated
      slot blocks back to the program that created them; null for regular bookings
    - `bookings.notes` (text, default '') — free-text label; used to store the program name
      on program-block rows so they display meaningfully in the calendar and bookings table

  2. Index
    - Index on program_id for fast lookup when bulk-cancelling a program's future blocks

  3. No destructive changes — both columns are additive and nullable/defaulted
*/

DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_name = 'bookings' AND column_name = 'program_id'
  ) THEN
    ALTER TABLE bookings ADD COLUMN program_id uuid REFERENCES class_programs(id) ON DELETE SET NULL;
  END IF;
END $$;

DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_name = 'bookings' AND column_name = 'notes'
  ) THEN
    ALTER TABLE bookings ADD COLUMN notes text NOT NULL DEFAULT '';
  END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_bookings_program_id ON bookings(program_id);

-- === 20260518152018_add_anon_policies_classes_tables.sql ===
/*
  # Add anon RLS policies for classes, sessions, subscriptions, and trial agreements

  The app uses the anon key without an auth session (admin tool pattern).
  All four tables currently only allow `authenticated` role, causing silent
  insert/update/delete failures. This mirrors the pattern already used on
  booking_payments (anon_insert_booking_payments etc.).

  Tables updated:
  - class_programs
  - class_sessions
  - class_subscriptions
  - provider_trial_agreements
*/

-- class_programs
CREATE POLICY "anon_select_class_programs"
  ON class_programs FOR SELECT TO anon USING (true);

CREATE POLICY "anon_insert_class_programs"
  ON class_programs FOR INSERT TO anon WITH CHECK (true);

CREATE POLICY "anon_update_class_programs"
  ON class_programs FOR UPDATE TO anon USING (true) WITH CHECK (true);

CREATE POLICY "anon_delete_class_programs"
  ON class_programs FOR DELETE TO anon USING (true);

-- class_sessions
CREATE POLICY "anon_select_class_sessions"
  ON class_sessions FOR SELECT TO anon USING (true);

CREATE POLICY "anon_insert_class_sessions"
  ON class_sessions FOR INSERT TO anon WITH CHECK (true);

CREATE POLICY "anon_update_class_sessions"
  ON class_sessions FOR UPDATE TO anon USING (true) WITH CHECK (true);

CREATE POLICY "anon_delete_class_sessions"
  ON class_sessions FOR DELETE TO anon USING (true);

-- class_subscriptions
CREATE POLICY "anon_select_class_subscriptions"
  ON class_subscriptions FOR SELECT TO anon USING (true);

CREATE POLICY "anon_insert_class_subscriptions"
  ON class_subscriptions FOR INSERT TO anon WITH CHECK (true);

CREATE POLICY "anon_update_class_subscriptions"
  ON class_subscriptions FOR UPDATE TO anon USING (true) WITH CHECK (true);

CREATE POLICY "anon_delete_class_subscriptions"
  ON class_subscriptions FOR DELETE TO anon USING (true);

-- provider_trial_agreements
CREATE POLICY "anon_select_provider_trial_agreements"
  ON provider_trial_agreements FOR SELECT TO anon USING (true);

CREATE POLICY "anon_insert_provider_trial_agreements"
  ON provider_trial_agreements FOR INSERT TO anon WITH CHECK (true);

-- === 20260520143305_add_subscription_columns_to_booking_payments.sql ===
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

-- === 20260520143356_make_booking_payments_booking_id_nullable.sql ===
/*
  # Make booking_payments.booking_id nullable

  ## Reason
  Subscription payment rows have no booking_id — they reference a class_subscription
  instead. Making booking_id nullable allows both booking-originated and
  subscription-originated rows to coexist in the same table without a dummy FK.

  ## Change
  - `booking_id` altered from NOT NULL to nullable
  - The FK constraint and ON DELETE CASCADE are preserved
*/

ALTER TABLE booking_payments ALTER COLUMN booking_id DROP NOT NULL;

-- === 20260521014519_fix_subscription_earning_payment_status.sql ===
/*
  # Fix subscription earning payment status

  All subscription_earning rows in booking_payments were incorrectly stored
  with status 'on_hold' or 'available_for_payout'. These are customer-facing
  payment records and should reflect that the payment was received (paid).

  The payout hold lifecycle is tracked separately in wallet_transactions and
  is unaffected by this change.

  Changes:
  - Update booking_payments where source_type = 'subscription'
    AND payment_type = 'subscription_earning'
    AND status IN ('on_hold', 'available_for_payout')
    SET status = 'paid'
*/

UPDATE booking_payments
SET status = 'paid'
WHERE source_type = 'subscription'
  AND payment_type = 'subscription_earning'
  AND status IN ('on_hold', 'available_for_payout');

-- === 20260523225013_add_booking_addons_table.sql ===
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

-- === 20260525_customer_blocks.sql ===
-- Customer blocks table
-- Supports granular blocking: platform-wide, per-location, per-listing, per-program

CREATE TABLE IF NOT EXISTS customer_blocks (
  id          UUID        PRIMARY KEY DEFAULT gen_random_uuid(),
  customer_id UUID        NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
  block_type  TEXT        NOT NULL CHECK (block_type IN ('platform', 'location', 'listing', 'program')),
  target_id   UUID,                          -- NULL for platform blocks
  reason      TEXT        NOT NULL DEFAULT '',
  created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Prevent duplicate blocks (same customer + same scope + same target)
CREATE UNIQUE INDEX IF NOT EXISTS customer_blocks_unique
  ON customer_blocks (customer_id, block_type, COALESCE(target_id, '00000000-0000-0000-0000-000000000000'::UUID));

CREATE INDEX IF NOT EXISTS customer_blocks_customer_idx ON customer_blocks (customer_id);

-- Open RLS policy for anon key (matches existing table policies in this project)
ALTER TABLE customer_blocks ENABLE ROW LEVEL SECURITY;
CREATE POLICY "Allow all operations" ON customer_blocks FOR ALL USING (true) WITH CHECK (true);

-- === 20260528_special_days_listing_and_schedule.sql ===
-- Extend special_days table
-- listing_id: NULL = applies to all listings at the location
--             non-NULL = applies to a specific listing only
--             For custom schedules (type = 'custom'), listing_id should always be set
-- open_time / close_time: for custom schedules that override the day's hours
-- custom_price: for custom schedules that override the listing's base price

ALTER TABLE special_days
  ADD COLUMN IF NOT EXISTS listing_id   UUID REFERENCES listings(id) ON DELETE CASCADE,
  ADD COLUMN IF NOT EXISTS open_time    TEXT,
  ADD COLUMN IF NOT EXISTS close_time   TEXT,
  ADD COLUMN IF NOT EXISTS custom_price NUMERIC;

-- Index for quick lookups by listing
CREATE INDEX IF NOT EXISTS special_days_listing_idx ON special_days (listing_id);

-- === 20260528_listings_buffer_time.sql ===
-- Add buffer_time column to listings
-- buffer_time: gap in minutes between consecutive bookings (0 = no buffer)

ALTER TABLE listings
  ADD COLUMN IF NOT EXISTS buffer_time INTEGER NOT NULL DEFAULT 0;

-- === 20260528_commission_rates_and_policies.sql ===
-- ── Commission rates per country & type ─────────────────────────
-- Stores default commission rates set by Super Admin per country.
-- listing_type: 'facility' (court/space rentals) | 'program' (academy classes)
-- Per-listing overrides are stored directly on the listings table.

CREATE TABLE IF NOT EXISTS commission_rates (
  id           UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  country_id   UUID NOT NULL REFERENCES countries(id) ON DELETE CASCADE,
  listing_type TEXT NOT NULL CHECK (listing_type IN ('facility', 'program')),
  rate_percent NUMERIC NOT NULL DEFAULT 20 CHECK (rate_percent >= 0 AND rate_percent <= 100),
  updated_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
  UNIQUE (country_id, listing_type)
);

CREATE INDEX IF NOT EXISTS commission_rates_country_idx ON commission_rates (country_id);

-- ── Per-listing commission override ──────────────────────────────
-- NULL = inherit from country default (commission_rates table)
ALTER TABLE listings
  ADD COLUMN IF NOT EXISTS commission_rate NUMERIC CHECK (commission_rate IS NULL OR (commission_rate >= 0 AND commission_rate <= 100));

-- ── Platform-level settings (key/value store) ────────────────────
-- Stores global defaults and configurable policy values.
CREATE TABLE IF NOT EXISTS platform_settings (
  key        TEXT PRIMARY KEY,
  value      TEXT NOT NULL,
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Seed defaults (safe to re-run)
INSERT INTO platform_settings (key, value) VALUES
  ('global_commission_facility',  '20'),
  ('global_commission_program',   '20'),
  ('cancellation_hours',          '48'),
  ('noshow_policy',               'Customer forfeits deposit. Provider receives nothing. Provider may ban the customer from the dashboard.'),
  ('trial_cancel_policy',         'Customer must cancel before the trial period ends. First billing charge applies if not cancelled in time.')
ON CONFLICT (key) DO NOTHING;

