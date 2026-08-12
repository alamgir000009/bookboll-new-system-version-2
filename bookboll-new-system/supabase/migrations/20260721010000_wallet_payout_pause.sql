/*
  # Pause an approved wallet payout method

  Super admin can temporarily PAUSE a provider wallet's payout method after it was
  approved (the Bookboll-Wallet manual method requires approval; Stripe activates on
  connect). Paused = the method keeps its approved setup_status ('active') and all
  balances, but the wallet no longer counts as ready/usable:
    · venue full-online payment gates close (bookings wallets)
    · membership-program creation gates close (subscription wallets)
  Resuming just flips the flag back — no re-approval needed.

  Kept as a SEPARATE boolean (not a new setup_status value) so the existing
  status lifecycle (pending / pending_review / active / rejected) and every
  .eq('setup_status','active') query stay intact.

  Idempotent — safe to re-run.
*/

ALTER TABLE provider_wallets ADD COLUMN IF NOT EXISTS payout_paused boolean NOT NULL DEFAULT false;
ALTER TABLE provider_wallets ADD COLUMN IF NOT EXISTS payout_paused_at timestamptz;
ALTER TABLE provider_wallets ADD COLUMN IF NOT EXISTS payout_pause_reason text;
