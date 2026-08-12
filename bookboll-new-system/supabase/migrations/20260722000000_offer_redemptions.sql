/*
  # Membership intro-offer anti-abuse ledger

  Records every FREE-TRIAL redemption so a customer can't take a provider's intro offer,
  cancel, and take it again — or retake it with a fresh account. Scope: ONE intro trial per
  customer per PROVIDER (business), matched by account email OR card fingerprint (a SHA-256
  of the card number — the PAN itself is never stored; in production this comes from the
  PSP, e.g. Stripe's card fingerprint).

  Enforcement (lib/offerGuard.js + customer data.js enrollInProgram): a repeat customer can
  still subscribe — at standard pricing with no trial ("join at full price", never blocked).
  "N months off" discount plans are NOT gated: that discount is the plan's ongoing price
  (built into every billing cycle), so cancel/rejoin gains nothing — only trials are
  exploitable and only trials are recorded.

  Cancelling a subscription never clears its redemption row.

  Idempotent — safe to re-run.
*/

CREATE TABLE IF NOT EXISTS offer_redemptions (
  id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  customer_email   text NOT NULL DEFAULT '',                                  -- normalized lower-case
  card_fingerprint text,                                                       -- sha256 of PAN (nullable — admin-granted)
  owner_user_id    uuid REFERENCES admin_users(id) ON DELETE CASCADE,          -- the provider (scope)
  program_id       uuid,
  subscription_id  uuid,
  offer_type       text NOT NULL DEFAULT 'trial',                              -- 'trial' (discounts not gated)
  source           text NOT NULL DEFAULT 'customer_web',                       -- customer_web | admin
  redeemed_at      timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_offer_redemptions_owner_email ON offer_redemptions(owner_user_id, customer_email);
CREATE INDEX IF NOT EXISTS idx_offer_redemptions_owner_card  ON offer_redemptions(owner_user_id, card_fingerprint);

ALTER TABLE offer_redemptions ENABLE ROW LEVEL SECURITY;
DO $$ BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_policies WHERE tablename = 'offer_redemptions' AND policyname = 'offer_redemptions_all') THEN
    -- App-layer enforcement (demo-grade, same pattern as the other customer-writable tables).
    CREATE POLICY offer_redemptions_all ON offer_redemptions FOR ALL TO anon, authenticated USING (true) WITH CHECK (true);
  END IF;
END $$;
