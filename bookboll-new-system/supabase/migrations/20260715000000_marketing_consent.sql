/*
  # Marketing consent + campaigns

  Customers explicitly opt in (at signup, editable in their profile) to receive marketing on
  each channel — email / SMS / WhatsApp. ONLY opted-in customers are emailable from the admin
  Customers page. Every customer gets a stable `unsubscribe_token` so any marketing email can
  carry a one-click unsubscribe link that flips the relevant consent off (anti-spam / deliverability).

  `marketing_campaigns` records each send (who/what/how-many) for an audit trail + in-dashboard
  preview of the exact compliant HTML (disclaimer + unsubscribe button). No real delivery yet.

  All columns/tables use IF NOT EXISTS so this is safe to re-run. Existing customers default to
  NOT opted in (false) — consent must be affirmatively given.
*/

-- ── Customer consent flags + unsubscribe token ───────────────────────────────
ALTER TABLE customers ADD COLUMN IF NOT EXISTS marketing_email    boolean NOT NULL DEFAULT false;
ALTER TABLE customers ADD COLUMN IF NOT EXISTS marketing_sms      boolean NOT NULL DEFAULT false;
ALTER TABLE customers ADD COLUMN IF NOT EXISTS marketing_whatsapp boolean NOT NULL DEFAULT false;
ALTER TABLE customers ADD COLUMN IF NOT EXISTS marketing_updated_at timestamptz;
ALTER TABLE customers ADD COLUMN IF NOT EXISTS unsubscribe_token  uuid NOT NULL DEFAULT gen_random_uuid();

CREATE UNIQUE INDEX IF NOT EXISTS idx_customers_unsubscribe_token ON customers(unsubscribe_token);

-- ── Sent marketing campaigns (audit + preview) ───────────────────────────────
CREATE TABLE IF NOT EXISTS marketing_campaigns (
  id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  owner_user_id   uuid REFERENCES admin_users(id) ON DELETE SET NULL,   -- provider scope (null = Bookboll/platform)
  created_by      uuid REFERENCES admin_users(id) ON DELETE SET NULL,   -- the admin who sent it
  sender_label    text NOT NULL DEFAULT 'Bookboll',                     -- "Bookboll" or the provider's business name
  channel         text NOT NULL DEFAULT 'email',                        -- email | sms | whatsapp
  audience        text,                                                 -- human note, e.g. "opted-in email · 42 selected"
  subject         text,
  body            text,
  html            text,                                                 -- rendered compliant HTML (disclaimer + unsubscribe)
  recipient_count integer NOT NULL DEFAULT 0,
  created_at      timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_marketing_campaigns_owner ON marketing_campaigns(owner_user_id, created_at DESC);

ALTER TABLE marketing_campaigns ENABLE ROW LEVEL SECURITY;
DO $$ BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_policies WHERE tablename='marketing_campaigns' AND policyname='marketing_campaigns_auth_all') THEN
    CREATE POLICY marketing_campaigns_auth_all ON marketing_campaigns FOR ALL TO authenticated USING (true) WITH CHECK (true);
  END IF;
END $$;
