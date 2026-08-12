-- Automated notification templates (Email / SMS / WhatsApp) per provider.
-- null = inherit the marketplace defaults (platform_settings key 'notification_templates',
-- which needs no DDL — that table already stores key/value rows).
-- Shape: { remind_before_days, trial_ending_days,
--          events: { [event]: { client|provider: { email:{on,subject,body}, sms:{on,body}, whatsapp:{on,body} } } } }

ALTER TABLE admin_users
  ADD COLUMN IF NOT EXISTS notification_templates jsonb;
