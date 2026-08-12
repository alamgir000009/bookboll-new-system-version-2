/*
  # Per-placement payment options — provider's OWN website

  A listing can run on the Bookboll marketplace AND the provider's own white-label
  site at once. The marketplace keeps the existing columns (fixed Bookboll commission,
  provider-constrained). These new columns hold the provider-customizable options for
  their OWN site, where all revenue is the provider's:

  - site_deposit_enabled    : offer "deposit + balance on arrival"
  - site_deposit_percentage : the deposit %, provider-set
  - site_online_enabled     : offer full online payment (only usable once the provider
                              has a connected payout method — enforced in app/UI)
  - site_cash_enabled       : offer "pay later" (full cash on arrival)

  Routing (handled in app, no schema impact):
    • deposit + payout connected → deposit credited to the provider's wallet
    • deposit + NO payout        → deposit goes to Bookboll as a platform fee
                                   (we can't pay them out), balance is cash on arrival
    • full online                → 100% to the provider's wallet (0% Bookboll cut)
    • pay later (cash)           → all collected on arrival by the provider

  Additive with safe defaults (deposit-only), so existing rows/code keep working.
*/

ALTER TABLE listing_payment_options ADD COLUMN IF NOT EXISTS site_deposit_enabled    boolean NOT NULL DEFAULT true;
ALTER TABLE listing_payment_options ADD COLUMN IF NOT EXISTS site_deposit_percentage numeric NOT NULL DEFAULT 20;
ALTER TABLE listing_payment_options ADD COLUMN IF NOT EXISTS site_online_enabled     boolean NOT NULL DEFAULT false;
ALTER TABLE listing_payment_options ADD COLUMN IF NOT EXISTS site_cash_enabled       boolean NOT NULL DEFAULT false;
