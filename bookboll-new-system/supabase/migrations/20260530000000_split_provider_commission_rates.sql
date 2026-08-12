-- Split provider commission into two separate rates:
--   commission_rate_facility — applied to court/space booking payments
--   commission_rate_program  — applied to academy class / subscription payments
--
-- NULL = inherit the country's default from commission_rates table
-- (set by Super Admin in Countries & Cities page)
-- Explicit value = provider-level override set by Super Admin

ALTER TABLE admin_users
  ADD COLUMN IF NOT EXISTS commission_rate_facility NUMERIC
    CHECK (commission_rate_facility IS NULL OR (commission_rate_facility >= 0 AND commission_rate_facility <= 100)),
  ADD COLUMN IF NOT EXISTS commission_rate_program NUMERIC
    CHECK (commission_rate_program IS NULL OR (commission_rate_program >= 0 AND commission_rate_program <= 100));

-- Backfill from the old single-rate column where it exists
UPDATE admin_users
SET
  commission_rate_facility = commission_rate,
  commission_rate_program  = commission_rate
WHERE role = 'provider_admin'
  AND commission_rate IS NOT NULL
  AND commission_rate_facility IS NULL;
