-- Provider white-label site: simple content pages (About us / Contact us).
-- One jsonb blob on the OWNER's admin_users row — a page is "live" as soon as
-- it has content (no separate toggles, mirroring the content-driven modules).
--
-- Shape: { "about":   { "text": "..." },
--          "contact": { "phone": "", "whatsapp": "", "email": "",
--                        "address": "", "maps_url": "", "hours": "" } }
--
-- Idempotent — safe to re-run.
ALTER TABLE admin_users ADD COLUMN IF NOT EXISTS site_pages jsonb;
