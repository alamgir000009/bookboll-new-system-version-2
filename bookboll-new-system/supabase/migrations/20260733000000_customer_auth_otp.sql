-- Customer sign-up upgrade: Google (demo) + "complete your account" (first/last name, phone)
-- + phone OTP verification over WhatsApp (or SMS).
--
-- 1) customers: split-name + verification/provenance fields (all optional / defaulted so existing
--    rows and the current email-first flow keep working).
ALTER TABLE customers ADD COLUMN IF NOT EXISTS first_name text;
ALTER TABLE customers ADD COLUMN IF NOT EXISTS last_name  text;
ALTER TABLE customers ADD COLUMN IF NOT EXISTS phone_verified boolean NOT NULL DEFAULT false;
ALTER TABLE customers ADD COLUMN IF NOT EXISTS auth_provider  text    NOT NULL DEFAULT 'email';  -- email | google

-- 2) otp_codes — short-lived one-time codes for phone verification. Written + verified ONLY by
--    the `otp` Edge Function (service role); never read by the anon client, so the code can't be
--    scraped. Demo-grade: code stored in plaintext (production should store a hash).
CREATE TABLE IF NOT EXISTS otp_codes (
  id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  identifier  text NOT NULL,                          -- normalized phone digits (country code + number)
  channel     text NOT NULL DEFAULT 'whatsapp',       -- whatsapp | sms
  code        text NOT NULL,                          -- 6-digit
  purpose     text NOT NULL DEFAULT 'customer_signup',
  attempts    integer NOT NULL DEFAULT 0,             -- wrong-guess counter (locks after a few)
  consumed    boolean NOT NULL DEFAULT false,         -- true once verified (single use)
  expires_at  timestamptz NOT NULL,
  created_at  timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_otp_identifier ON otp_codes (identifier, created_at DESC);

-- Service-role only: RLS on, no policy → the anon key cannot read/write codes.
ALTER TABLE otp_codes ENABLE ROW LEVEL SECURITY;
