/*
  Provider white-label branding
  -----------------------------
  Adds per-provider branding to `admin_users` so a provider can run a
  white-labeled copy of the customer storefront on their own subdomain
  (e.g. mybusiness.bookboll.com) showing only their own venues, listings,
  membership programs and events.

  Brand settings live on the OWNER's row (role = 'provider_admin'); employee
  rows inherit via owner_user_id and don't carry their own brand.

  Idempotent (safe to re-run). All columns are optional — the app reads them
  best-effort and falls back to the default Bookboll look when absent.
*/

ALTER TABLE admin_users ADD COLUMN IF NOT EXISTS brand_enabled  boolean DEFAULT false;
ALTER TABLE admin_users ADD COLUMN IF NOT EXISTS brand_subdomain text;
ALTER TABLE admin_users ADD COLUMN IF NOT EXISTS brand_name     text;
ALTER TABLE admin_users ADD COLUMN IF NOT EXISTS brand_tagline  text;
ALTER TABLE admin_users ADD COLUMN IF NOT EXISTS brand_logo_url text;
ALTER TABLE admin_users ADD COLUMN IF NOT EXISTS brand_primary  text;   -- hex, e.g. #0f766e
ALTER TABLE admin_users ADD COLUMN IF NOT EXISTS brand_accent   text;   -- hex, e.g. #14b8a6

-- A subdomain must be unique across providers (case-insensitive), but many rows
-- legitimately have none — so a partial unique index on the lower-cased value.
CREATE UNIQUE INDEX IF NOT EXISTS idx_admin_users_brand_subdomain
  ON admin_users (lower(brand_subdomain))
  WHERE brand_subdomain IS NOT NULL AND brand_subdomain <> '';

-- Seed each provider owner's subdomain from their existing @username so the
-- white-label link works out of the box once they enable it.
UPDATE admin_users
   SET brand_subdomain = lower(username)
 WHERE role = 'provider_admin'
   AND (brand_subdomain IS NULL OR brand_subdomain = '')
   AND username IS NOT NULL AND username <> '';
