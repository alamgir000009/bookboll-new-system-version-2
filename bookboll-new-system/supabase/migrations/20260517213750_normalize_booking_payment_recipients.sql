/*
  # Normalize booking_payments.recipient to @username format

  - 'bookboll' → '@bookboll'
  - 'provider'  → derive @username from the booking → listing → location → owner chain
*/

-- Fix bare 'bookboll' entries
UPDATE booking_payments SET recipient = '@bookboll' WHERE recipient = 'bookboll';

-- Fix 'provider' entries: walk booking → listing → location → admin_users to get @username
UPDATE booking_payments bp
SET recipient = '@' || au.username
FROM bookings b
JOIN listings li ON li.id = b.listing_id
JOIN locations loc ON loc.id = li.location_id
JOIN admin_users au ON au.id = loc.owner_user_id
WHERE bp.booking_id = b.id
  AND bp.recipient = 'provider'
  AND au.username IS NOT NULL;

-- Any remaining 'provider' rows without a resolvable username — leave as-is
-- (they will fall through to providerNameForPayment() in the UI)
