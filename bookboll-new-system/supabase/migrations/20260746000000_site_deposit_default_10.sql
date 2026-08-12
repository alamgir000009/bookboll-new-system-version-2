-- Own-website ("My Website") deposit default: 10% (was 20%).
-- Bookings from a provider's own site carry no Bookboll fee, so the deposit
-- there is just the online portion — 10% by default. Providers can still set
-- any percentage per listing. Idempotent.

ALTER TABLE listing_payment_options ALTER COLUMN site_deposit_percentage SET DEFAULT 10;

-- Existing rows still sitting on the old 20% default are moved to 10.
-- (Providers who deliberately chose a value other than 20 are untouched.)
UPDATE listing_payment_options SET site_deposit_percentage = 10 WHERE site_deposit_percentage = 20;
