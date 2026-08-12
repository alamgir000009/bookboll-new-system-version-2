/*
  # Split provider wallets by PURPOSE — bookings vs subscription

  A provider now has a separate wallet per purpose in each country:
    • subscription — gates membership programs; allows Stripe + Bookboll Wallet payout.
    • bookings     — gates venue full-online payment; Stripe only (Bookboll Wallet is beta,
                     shown disabled, so venue online isn't enabled via the easy route).

  Existing rows BACKFILL to 'subscription' (the NOT NULL DEFAULT), so memberships keep working;
  bookings wallets are created fresh when a provider sets up a bookings payout method. Wallets
  are keyed (owner_user_id, country_id, purpose) × is_current (current/legacy lifecycle unchanged).
  Idempotent.
*/

ALTER TABLE provider_wallets ADD COLUMN IF NOT EXISTS purpose text NOT NULL DEFAULT 'subscription';
CREATE INDEX IF NOT EXISTS idx_provider_wallets_owner_purpose ON provider_wallets(owner_user_id, country_id, purpose);
