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
