-- ════════════════════════════════════════════════════════════════════════════
-- My Website: SEO meta + custom domain (roadmap #1 / #2)
-- ════════════════════════════════════════════════════════════════════════════
-- Per-provider white-label SEO controls + a custom-domain connection. The domain
-- "purchase" is a SIMULATED one-time fee in the app (no real payment is taken);
-- brand_domain_paid_at records when the provider confirmed the simulated $119 fee,
-- and brand_domain_status tracks DNS verification (null → pending → active).
-- ════════════════════════════════════════════════════════════════════════════

alter table admin_users
  add column if not exists brand_meta_title       text,
  add column if not exists brand_meta_description text,
  add column if not exists brand_custom_domain    text,
  add column if not exists brand_domain_status    text,        -- null | 'pending' | 'active'
  add column if not exists brand_domain_paid_at   timestamptz;

-- Marketplace-level SEO (platform mode) is stored in platform_settings (key/value),
-- which already exists — no schema change needed there.
