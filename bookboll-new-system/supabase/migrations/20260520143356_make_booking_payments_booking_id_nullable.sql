/*
  # Make booking_payments.booking_id nullable

  ## Reason
  Subscription payment rows have no booking_id — they reference a class_subscription
  instead. Making booking_id nullable allows both booking-originated and
  subscription-originated rows to coexist in the same table without a dummy FK.

  ## Change
  - `booking_id` altered from NOT NULL to nullable
  - The FK constraint and ON DELETE CASCADE are preserved
*/

ALTER TABLE booking_payments ALTER COLUMN booking_id DROP NOT NULL;
