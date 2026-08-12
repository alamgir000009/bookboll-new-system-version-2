/*
  Withdrawal lifecycle — give payouts a real, human status flow instead of a single
  one-click settlement.

    pending    — provider submitted; money moved available -> pending_balance (escrow)
    approved   — admin verified & committed to pay; bank transfer in progress
                 (money STAYS in pending_balance; we just record approved_at)
    paid       — admin confirmed the transfer; pending_balance -> lifetime_withdrawn
                 (+ a 'withdrawal' debit in wallet_transactions)
    rejected   — admin declined; pending_balance refunded -> available_balance
    cancelled  — provider cancelled while still 'pending'; refunded -> available_balance

  approved_at / cancelled_at let the UI show "Approved on…", an ETA, and "Cancelled on…".
  The app writes these best-effort and falls back gracefully if the columns are absent,
  so nothing breaks before this migration is applied.
*/

ALTER TABLE withdrawal_requests ADD COLUMN IF NOT EXISTS approved_at  timestamptz;
ALTER TABLE withdrawal_requests ADD COLUMN IF NOT EXISTS cancelled_at timestamptz;
-- `notes` was referenced by the provider's "Request Withdrawal" form but never existed
-- on the table, so every submit silently failed (the balance moved, no row was created).
ALTER TABLE withdrawal_requests ADD COLUMN IF NOT EXISTS notes        text;

CREATE INDEX IF NOT EXISTS idx_withdrawal_requests_status ON withdrawal_requests (status);

-- Recover any funds stranded in pending_balance by the old bug: recompute each wallet's
-- pending_balance from its actual open (pending/approved) requests and return the
-- difference to available_balance. Safe to re-run; only touches drifted wallets.
UPDATE provider_wallets w
SET available_balance = w.available_balance + w.pending_balance - COALESCE((
      SELECT SUM(r.amount) FROM withdrawal_requests r
      WHERE r.owner_user_id = w.owner_user_id AND r.status IN ('pending','approved')), 0),
    pending_balance = COALESCE((
      SELECT SUM(r.amount) FROM withdrawal_requests r
      WHERE r.owner_user_id = w.owner_user_id AND r.status IN ('pending','approved')), 0),
    updated_at = now()
WHERE w.pending_balance <> COALESCE((
      SELECT SUM(r.amount) FROM withdrawal_requests r
      WHERE r.owner_user_id = w.owner_user_id AND r.status IN ('pending','approved')), 0);
