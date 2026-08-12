-- Provider's own email-sender (SMTP) identity, so their automated notification emails
-- and marketing emails come from their own professional address instead of Bookboll's
-- shared sender.  null = not connected (fall back to Bookboll for automated; marketing is
-- blocked until this is set).
--
-- Shape: { enabled, from_email, from_name, host, port, encryption, username, password,
--          use_for_automated, verified_at }
-- NOTE: in production the password would be stored server-side / in a secrets vault and
-- never returned to the browser. In this demo it lives in the jsonb like the rest of the
-- simulated integrations.
ALTER TABLE admin_users
  ADD COLUMN IF NOT EXISTS smtp_config jsonb;
