-- WhatsApp channel (Phase A+B): outbound send log + phone lookup index.
--
-- whatsapp_outbound — one row per message the send-whatsapp Edge Function dispatches
-- (marketing campaigns, test sends, and later inbox replies / bot messages). The function
-- writes with the service role; the dashboard reads it (send history, campaign results,
-- and — via the webhook's status events — delivery/read receipts).
CREATE TABLE IF NOT EXISTS whatsapp_outbound (
  id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  to_number     text NOT NULL,                  -- digits-only wa number (e.g. 97450318233)
  customer_id   uuid,                           -- matched customer, when known
  campaign_id   uuid,                           -- marketing_campaigns.id for bulk sends
  kind          text NOT NULL DEFAULT 'template', -- 'template' | 'text' | 'interactive'
  template_name text,
  body          text,                           -- rendered text / template body preview
  wa_message_id text,                           -- Meta message id (wamid…) for status sync
  status        text NOT NULL DEFAULT 'sent',   -- sent | failed | delivered | read
  error         text,                           -- Graph API error message when failed
  created_at    timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_wa_outbound_created  ON whatsapp_outbound (created_at DESC);
CREATE INDEX IF NOT EXISTS idx_wa_outbound_to       ON whatsapp_outbound (to_number);
CREATE INDEX IF NOT EXISTS idx_wa_outbound_campaign ON whatsapp_outbound (campaign_id);
CREATE INDEX IF NOT EXISTS idx_wa_outbound_wamid    ON whatsapp_outbound (wa_message_id);

ALTER TABLE whatsapp_outbound ENABLE ROW LEVEL SECURITY;

-- Dashboard reads; writes happen only via the Edge Function's service role (no client
-- insert policy on purpose — same model as whatsapp_inbound).
DROP POLICY IF EXISTS whatsapp_outbound_read ON whatsapp_outbound;
CREATE POLICY whatsapp_outbound_read ON whatsapp_outbound FOR SELECT USING (true);

-- Customers are email-first today; the WhatsApp bot + inbox match inbound senders by
-- phone, so give that lookup an index.
CREATE INDEX IF NOT EXISTS idx_customers_phone ON customers (phone);
