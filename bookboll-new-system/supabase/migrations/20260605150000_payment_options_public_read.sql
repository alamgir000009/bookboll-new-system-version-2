/*
  # Public read for listing payment options + sensible defaults

  1. The customer storefront uses the anon key, but listing_payment_options only had a
     SELECT policy for `authenticated` — so the booking page couldn't read which methods
     a listing offers (it fell back to the venue-ownership default). Add a read-only anon
     SELECT policy. Anon still CANNOT insert/update/delete payment options.

  2. Seed a default payment row for any listing that doesn't have one yet, based on venue
     ownership (Bookboll-owned → online; provider → deposit + remaining on arrival).
     Uses WHERE NOT EXISTS, so listings already configured by an admin are left untouched.

  Safe to re-run.
*/

DROP POLICY IF EXISTS "Public can view listing payment options" ON listing_payment_options;
CREATE POLICY "Public can view listing payment options"
  ON listing_payment_options FOR SELECT TO anon USING (true);

INSERT INTO listing_payment_options
  (listing_id, cash_on_arrival, deposit_enabled, deposit_amount, deposit_percentage, online_payment_enabled)
SELECT l.id,
       false,
       (loc.owner_user_id IS NOT NULL),   -- deposit ON for provider venues
       0,
       20,
       (loc.owner_user_id IS NULL)        -- online ON for Bookboll-owned venues
FROM listings l
JOIN locations loc ON loc.id = l.location_id
WHERE NOT EXISTS (SELECT 1 FROM listing_payment_options o WHERE o.listing_id = l.id);
