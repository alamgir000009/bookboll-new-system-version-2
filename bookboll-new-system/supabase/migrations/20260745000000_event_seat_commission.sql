-- Events per-seat commission — configurable per country (like facility/program
-- commissions) with a platform-wide default of 10%.
--
--   commission_rates.listing_type gains 'event': the % Bookboll takes on a
--   hosted event's per-seat / per-team-entry price in that country.
--   Fallback chain in the app: country event rate → platform_settings
--   'global_commission_event' → 10.
--
-- Idempotent — safe to re-run.

ALTER TABLE commission_rates DROP CONSTRAINT IF EXISTS commission_rates_listing_type_check;
ALTER TABLE commission_rates ADD CONSTRAINT commission_rates_listing_type_check
  CHECK (listing_type IN ('facility', 'program', 'event'));

INSERT INTO platform_settings (key, value) VALUES ('global_commission_event', '10')
ON CONFLICT (key) DO NOTHING;
