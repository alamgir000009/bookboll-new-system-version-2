/*
  # Wallet setup method (wallet-first onboarding)

  Providers must now set up HOW they get paid before they can create their first
  membership program. This adds the per-wallet setup state used to gate that:

    - setup_method        which payout rail the provider chose
                          (null | 'stripe_connect' | 'paypal_hyperwallet' | 'bookboll')
    - setup_status        null  → not set up yet (gate closed)
                          'pending' → external method chosen, onboarding not finished
                                      (Stripe Connect / PayPal Hyperwallet) — gate stays closed
                          'active'  → wallet ready, program creation unlocked
    - setup_completed_at  when the wallet became active

  Idempotent: safe to re-run.
*/

ALTER TABLE provider_wallets ADD COLUMN IF NOT EXISTS setup_method       text;
ALTER TABLE provider_wallets ADD COLUMN IF NOT EXISTS setup_status       text;
ALTER TABLE provider_wallets ADD COLUMN IF NOT EXISTS setup_completed_at timestamptz;

-- Backfill: any existing wallet whose owner already saved payout bank details is
-- treated as already set up on Bookboll's own wallet, so current providers are
-- NOT suddenly locked out of their programs by the new gate.
UPDATE provider_wallets w
SET setup_method       = 'bookboll',
    setup_status       = 'active',
    setup_completed_at = COALESCE(w.setup_completed_at, now())
FROM admin_users u
WHERE u.id = w.owner_user_id
  AND w.setup_status IS NULL
  AND (
    COALESCE(u.payout_bank_name, '')      <> '' OR
    COALESCE(u.payout_iban, '')           <> '' OR
    COALESCE(u.payout_account_number, '') <> ''
  );
