-- Premium white-label branding: a provider on the PREMIUM plan may hide the
-- prominent "Powered by Bookboll" badge from their site (a small clickable
-- footer credit always remains). Free sites always show the badge.
ALTER TABLE admin_users ADD COLUMN IF NOT EXISTS brand_whitelabel boolean NOT NULL DEFAULT false;
