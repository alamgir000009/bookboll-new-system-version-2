/*
  # Fix subscription earning payment status

  All subscription_earning rows in booking_payments were incorrectly stored
  with status 'on_hold' or 'available_for_payout'. These are customer-facing
  payment records and should reflect that the payment was received (paid).

  The payout hold lifecycle is tracked separately in wallet_transactions and
  is unaffected by this change.

  Changes:
  - Update booking_payments where source_type = 'subscription'
    AND payment_type = 'subscription_earning'
    AND status IN ('on_hold', 'available_for_payout')
    SET status = 'paid'
*/

UPDATE booking_payments
SET status = 'paid'
WHERE source_type = 'subscription'
  AND payment_type = 'subscription_earning'
  AND status IN ('on_hold', 'available_for_payout');
