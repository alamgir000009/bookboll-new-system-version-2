/*
  # Full online payment now requires a set-up wallet

  Full online payment splits the charge (Bookboll commission + provider payout via
  the wallet), so a provider listing may only offer it once the provider's wallet
  is active. This corrects pre-change data: many provider listings had online
  payment enabled without any wallet set up.

  Rules:
    - PROVIDER listings (locations.owner_user_id IS NOT NULL) whose owner has no
      active wallet (provider_wallets.setup_status <> 'active') → online OFF.
    - BOOKBOLL-owned listings (owner_user_id IS NULL) are exempt — left untouched.
    - After turning online off, keep at least one payment method on (enable the
      deposit method where nothing else remains).

  Idempotent: safe to re-run.
*/

-- 1. Turn off online payment for provider listings without an active wallet.
UPDATE listing_payment_options o
SET online_payment_enabled = false
FROM listings l
JOIN locations loc ON loc.id = l.location_id
LEFT JOIN provider_wallets w ON w.owner_user_id = loc.owner_user_id
WHERE o.listing_id = l.id
  AND loc.owner_user_id IS NOT NULL
  AND o.online_payment_enabled = true
  AND COALESCE(w.setup_status, '') <> 'active';

-- 2. Keep at least one payment method on: where online is now off and neither
--    deposit nor cash-on-arrival is enabled, fall back to the deposit method.
UPDATE listing_payment_options o
SET deposit_enabled = true
FROM listings l
JOIN locations loc ON loc.id = l.location_id
WHERE o.listing_id = l.id
  AND loc.owner_user_id IS NOT NULL
  AND COALESCE(o.online_payment_enabled, false) = false
  AND COALESCE(o.deposit_enabled, false) = false
  AND COALESCE(o.cash_on_arrival, false) = false;
