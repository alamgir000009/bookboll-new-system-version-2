/*
  # Bookboll for Business — Premium plan

  Providers now have a FREE plan and a PREMIUM plan (simulated monthly subscription).
  Premium unlocks: storefront blog posts, marketing emails, data exports, promocodes,
  custom home banners, and the embed/widget codes (see src/lib/premium.js for the
  canonical feature list + gating). Platform roles (super admin, country admin,
  platform staff) are never gated.

  plan: 'free' (default) | 'premium' — read from the business OWNER's row, so a
  premium business's whole team inherits it.

  Idempotent — safe to re-run.
*/

ALTER TABLE admin_users ADD COLUMN IF NOT EXISTS plan text NOT NULL DEFAULT 'free';
ALTER TABLE admin_users ADD COLUMN IF NOT EXISTS premium_since timestamptz;
