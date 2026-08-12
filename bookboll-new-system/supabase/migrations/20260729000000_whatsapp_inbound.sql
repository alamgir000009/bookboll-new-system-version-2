-- Inbound WhatsApp events (incoming messages + delivery/read statuses) captured by the
-- whatsapp-webhook Edge Function. The function writes with the service role (bypasses RLS);
-- the dashboard reads them (app is anon-key + app-layer scoping like the rest of the demo).
CREATE TABLE IF NOT EXISTS whatsapp_inbound (
  id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  kind            text NOT NULL,                 -- 'message' | 'status'
  wa_message_id   text,                          -- WhatsApp message id (wamid…)
  from_number     text,                          -- sender wa_id (message) / recipient_id (status)
  from_name       text,                          -- sender profile name, when provided
  phone_number_id text,                          -- the business number that received it
  message_type    text,                          -- text | image | button | interactive | …
  body            text,                          -- best-effort plain text
  status          text,                          -- sent | delivered | read | failed (status rows)
  raw             jsonb NOT NULL,                -- full "changes" payload for anything not parsed
  received_at     timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_whatsapp_inbound_received ON whatsapp_inbound (received_at DESC);
CREATE INDEX IF NOT EXISTS idx_whatsapp_inbound_from     ON whatsapp_inbound (from_number);

ALTER TABLE whatsapp_inbound ENABLE ROW LEVEL SECURITY;

-- Read access for the dashboard (matches this app's anon-key model). Writes only ever
-- happen via the Edge Function's service-role key, which bypasses RLS — so there is no
-- insert policy for anon/authenticated on purpose.
DROP POLICY IF EXISTS whatsapp_inbound_read ON whatsapp_inbound;
CREATE POLICY whatsapp_inbound_read ON whatsapp_inbound FOR SELECT USING (true);
