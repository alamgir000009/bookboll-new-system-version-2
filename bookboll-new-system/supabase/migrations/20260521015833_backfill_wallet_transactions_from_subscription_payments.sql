/*
  # Backfill wallet_transactions from existing subscription payments

  The wallet_transactions table was empty because subscription payments were
  seeded directly into booking_payments without going through the
  recordSubscriptionPayment() helper. This migration creates the missing
  wallet_transactions rows and sets correct provider_wallets balances.

  Changes:
  1. Insert one wallet_transaction (completed) per subscription_earning row
     in booking_payments, joining to get gross, platform_fee, owner_user_id,
     customer name, and program name.
  2. Ensure a provider_wallet row exists for each affected owner.
  3. Update provider_wallets.available_balance and lifetime_earnings to
     reflect the sum of all completed subscription earnings.
*/

-- 1. Insert missing wallet_transactions (completed, already past hold period)
INSERT INTO wallet_transactions (
  owner_user_id,
  type,
  direction,
  amount,
  provider_share,
  platform_fee,
  source_id,
  source_label,
  description,
  status,
  currency,
  available_on,
  hold_days_snapshot,
  released_at,
  created_at
)
SELECT
  cp.owner_user_id,
  'subscription_online',
  'credit',
  (bp_e.amount::numeric + bp_c.amount::numeric),
  bp_e.amount::numeric,
  bp_c.amount::numeric,
  bp_e.subscription_id,
  'Subscription · ' || COALESCE(cs.customer_name, cs.customer_email, 'customer'),
  bp_e.notes,
  'completed',
  bp_e.currency,
  (bp_e.paid_at::date),
  7,
  bp_e.paid_at,
  bp_e.paid_at
FROM booking_payments bp_e
JOIN booking_payments bp_c
  ON bp_c.subscription_id = bp_e.subscription_id
  AND bp_c.source_type = 'subscription'
  AND bp_c.payment_type = 'subscription_commission'
JOIN class_subscriptions cs ON cs.id = bp_e.subscription_id
JOIN class_programs cp ON cp.id = cs.program_id
WHERE bp_e.source_type = 'subscription'
  AND bp_e.payment_type = 'subscription_earning'
  AND NOT EXISTS (
    SELECT 1 FROM wallet_transactions wt
    WHERE wt.source_id = bp_e.subscription_id
  );

-- 2. Ensure provider_wallet rows exist for all affected owners
INSERT INTO provider_wallets (owner_user_id)
SELECT DISTINCT cp.owner_user_id
FROM booking_payments bp_e
JOIN class_subscriptions cs ON cs.id = bp_e.subscription_id
JOIN class_programs cp ON cp.id = cs.program_id
WHERE bp_e.source_type = 'subscription'
  AND bp_e.payment_type = 'subscription_earning'
ON CONFLICT DO NOTHING;

-- 3. Update available_balance and lifetime_earnings per provider
UPDATE provider_wallets pw
SET
  available_balance = totals.total_share,
  lifetime_earnings = totals.total_share,
  pending_balance = 0,
  updated_at = now()
FROM (
  SELECT
    cp.owner_user_id,
    SUM(bp_e.amount::numeric) AS total_share
  FROM booking_payments bp_e
  JOIN class_subscriptions cs ON cs.id = bp_e.subscription_id
  JOIN class_programs cp ON cp.id = cs.program_id
  WHERE bp_e.source_type = 'subscription'
    AND bp_e.payment_type = 'subscription_earning'
  GROUP BY cp.owner_user_id
) totals
WHERE pw.owner_user_id = totals.owner_user_id;
