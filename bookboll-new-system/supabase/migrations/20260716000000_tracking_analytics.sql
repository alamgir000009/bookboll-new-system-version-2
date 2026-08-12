/*
  # Analytics & marketing tracking (Meta Pixel / GA4 / GTM)

  Providers connect their OWN analytics/pixels so they fire on THEIR white-label storefront
  (<slug>.bookboll.com) only — never on the Bookboll marketplace or any other provider's site.
  The super admin connects the marketplace's own pixels (apex bookboll.com), stored in
  platform_settings. Scoping is guaranteed by where the tags are injected: the provider config
  loads only when their brand is the active storefront; the platform config only on the apex.

  Fields (all optional, default empty):
    tracking_meta_pixel  – Meta (Facebook) Pixel ID           (numeric)
    tracking_ga4         – Google Analytics 4 Measurement ID  (G-XXXXXXX)
    tracking_gtm         – Google Tag Manager container ID     (GTM-XXXXXX)
    tracking_head        – optional raw <head> code (advanced; the owner's own site only)

  Idempotent (IF NOT EXISTS / ON CONFLICT DO NOTHING) — safe to re-run.
*/

-- ── Per-provider tracking (their own storefront) ──────────────────────────────
ALTER TABLE admin_users ADD COLUMN IF NOT EXISTS tracking_meta_pixel text;
ALTER TABLE admin_users ADD COLUMN IF NOT EXISTS tracking_ga4        text;
ALTER TABLE admin_users ADD COLUMN IF NOT EXISTS tracking_gtm        text;
ALTER TABLE admin_users ADD COLUMN IF NOT EXISTS tracking_head       text;

-- ── Marketplace tracking (the main Bookboll apex site) ────────────────────────
-- platform_settings is a (key text PK, value text, updated_at) store. Seed empty rows so the
-- keys are discoverable; the super admin's Tracking page upserts real values.
INSERT INTO platform_settings (key, value, updated_at) VALUES
  ('tracking_meta_pixel', '', now()),
  ('tracking_ga4',        '', now()),
  ('tracking_gtm',        '', now()),
  ('tracking_head',       '', now())
ON CONFLICT (key) DO NOTHING;
