/*
  # Default platform commission → 15% worldwide

  The platform default commission is now 15% for BOTH facility bookings and membership
  programs (was 20%). Code fallbacks were updated in the same change (booking.js,
  approvalState.js PLATFORM_COMMISSION, Countries.vue, data.js).

  · New-column default drops to 15.
  · Existing rows still at the old 20% default are moved to 15. Rows the super admin
    customized to any other value are left untouched.

  Idempotent — safe to re-run.
*/

ALTER TABLE commission_rates ALTER COLUMN rate_percent SET DEFAULT 15;

UPDATE commission_rates SET rate_percent = 15, updated_at = now()
  WHERE rate_percent = 20;
