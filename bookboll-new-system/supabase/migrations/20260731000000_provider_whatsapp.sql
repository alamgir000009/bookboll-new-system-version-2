-- Per-provider WhatsApp sender (like admin_users.smtp_config): each provider connects
-- their OWN WhatsApp Business number + token to market their own customers. Bookboll's
-- own number stays in platform_settings key 'whatsapp_config' (+ the WHATSAPP_ACCESS_TOKEN
-- function secret) for its marketing and the future booking funnel.
--
-- Shape: { enabled, phone_number_id, waba_id, display_number, access_token, verified_at }
-- NOTE: the access_token lives in the jsonb (demo-grade, exactly like smtp_config.password).
-- In production a provider's token would be vault-stored server-side and never returned to
-- the browser — here it follows the same simulated-integration pattern as the rest of the app.
ALTER TABLE admin_users
  ADD COLUMN IF NOT EXISTS whatsapp_config jsonb;

-- Scope the outbound send log by sender so a provider sees only their own sends
-- (null owner = Bookboll's own number).
ALTER TABLE whatsapp_outbound
  ADD COLUMN IF NOT EXISTS owner_user_id uuid;
CREATE INDEX IF NOT EXISTS idx_wa_outbound_owner ON whatsapp_outbound (owner_user_id);
