-- Per-country loyalty balances for customers.
--
-- Loyalty programs are configured per country (different earn rates, tier
-- thresholds, redemption rules), so a customer accumulates points separately
-- in each country they book in. We replace the single customers.loyalty_points
-- / loyalty_tier with a per-country ledger:
--
--   points          = current spendable balance (goes down on redemption)
--   lifetime_points = cumulative points ever earned in that country
--                     (only goes up) — this is what determines the TIER, so
--                     redeeming points never demotes a customer's tier.
--
-- Tier itself is DERIVED at read time from lifetime_points vs. that country's
-- loyalty_program tier thresholds, so it is never stored stale.

CREATE TABLE IF NOT EXISTS customer_loyalty (
  id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  customer_id     uuid NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
  country_id      uuid NOT NULL REFERENCES countries(id) ON DELETE CASCADE,
  points          integer NOT NULL DEFAULT 0,
  lifetime_points integer NOT NULL DEFAULT 0,
  created_at      timestamptz DEFAULT now(),
  updated_at      timestamptz DEFAULT now(),
  CONSTRAINT customer_loyalty_customer_country_uniq UNIQUE (customer_id, country_id)
);

CREATE INDEX IF NOT EXISTS customer_loyalty_customer_idx ON customer_loyalty (customer_id);
CREATE INDEX IF NOT EXISTS customer_loyalty_country_idx  ON customer_loyalty (country_id);

-- Match the app's anon-key access model used by the other tables.
ALTER TABLE customer_loyalty ENABLE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS customer_loyalty_all ON customer_loyalty;
CREATE POLICY customer_loyalty_all ON customer_loyalty
  FOR ALL TO anon, authenticated USING (true) WITH CHECK (true);

-- Seed: carry any existing single-country points onto the customer's home
-- country (no-op here since current data is all 0 / null country, but safe).
INSERT INTO customer_loyalty (customer_id, country_id, points, lifetime_points)
SELECT c.id, c.country_id, COALESCE(c.loyalty_points, 0), COALESCE(c.loyalty_points, 0)
  FROM customers c
 WHERE c.country_id IS NOT NULL
   AND COALESCE(c.loyalty_points, 0) > 0
ON CONFLICT (customer_id, country_id) DO NOTHING;
