/*
  # admin_notifications — a real notification store for the dashboard bell

  Until now the dashboard bell was fully DERIVED from domain tables, which made
  admin DECISIONS invisible to providers:
    • listing/program APPROVED left no trace (no approved_at column, no bell branch)
    • "request changes" sets eligibility_status='paused' (not 'rejected'), which
      the bell never matched
    • payout-method (KYC) approve/reject never bumped updated_at, so the derived
      wallet item never fired

  This table stores decision events addressed to a business owner; the bell
  (src/lib/bellFeed.js) merges these rows with its derived feed. Writers:
  src/lib/adminNotify.js (called from the Approvals page decisions). Read state
  stays client-side (localStorage last-seen), same as the rest of the bell.

  Permissive policies mirror the core-schema convention (anon-key client app).
  Idempotent.
*/

CREATE TABLE IF NOT EXISTS admin_notifications (
  id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  user_id uuid NOT NULL,                 -- target business owner (admin_users.id)
  kind text NOT NULL DEFAULT 'info',     -- approval | wallet | booking | member | info
  tone text NOT NULL DEFAULT 'info',     -- ok | info | warn
  title text NOT NULL,
  sub text DEFAULT '',
  link text DEFAULT '',
  created_at timestamptz DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_admin_notifications_user
  ON admin_notifications(user_id, created_at DESC);

ALTER TABLE admin_notifications ENABLE ROW LEVEL SECURITY;

DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_policies WHERE tablename = 'admin_notifications' AND policyname = 'anon_select_admin_notifications') THEN
    CREATE POLICY "anon_select_admin_notifications" ON admin_notifications FOR SELECT TO anon, authenticated USING (true);
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_policies WHERE tablename = 'admin_notifications' AND policyname = 'anon_insert_admin_notifications') THEN
    CREATE POLICY "anon_insert_admin_notifications" ON admin_notifications FOR INSERT TO anon, authenticated WITH CHECK (true);
  END IF;
END $$;
