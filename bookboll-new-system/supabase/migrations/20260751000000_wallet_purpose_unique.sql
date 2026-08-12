/*
  # Fix wallet uniqueness for the purpose split

  20260624 (wallet lifecycle) created a partial unique index allowing only ONE
  current wallet per (owner, country). 20260714 split wallets by PURPOSE
  (subscription vs bookings) but never updated that index — so creating a
  bookings wallet in a country where a subscription wallet already exists fails
  with: duplicate key value violates unique constraint
  "provider_wallets_one_current_per_country".

  Replace it with: one current wallet per (owner, country, PURPOSE).
  Idempotent.
*/

DROP INDEX IF EXISTS provider_wallets_one_current_per_country;
ALTER TABLE provider_wallets DROP CONSTRAINT IF EXISTS provider_wallets_one_current_per_country;

CREATE UNIQUE INDEX IF NOT EXISTS provider_wallets_one_current_per_purpose
  ON provider_wallets(owner_user_id, country_id, purpose) WHERE is_current;
