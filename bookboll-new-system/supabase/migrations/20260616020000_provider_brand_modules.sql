/*
  Provider white-label — section visibility
  -----------------------------------------
  Lets a provider choose which modules show on their white-label storefront:
  venue bookings, events, and/or memberships. Defaults to all on, so existing
  branded sites are unchanged. Read best-effort (missing column = shown).

  Idempotent (safe to re-run). Pairs with 20260616010000_provider_branding.sql.
*/

ALTER TABLE admin_users ADD COLUMN IF NOT EXISTS brand_show_venues   boolean DEFAULT true;
ALTER TABLE admin_users ADD COLUMN IF NOT EXISTS brand_show_events   boolean DEFAULT true;
ALTER TABLE admin_users ADD COLUMN IF NOT EXISTS brand_show_programs boolean DEFAULT true;
