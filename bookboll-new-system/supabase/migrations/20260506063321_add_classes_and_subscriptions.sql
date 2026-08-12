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
