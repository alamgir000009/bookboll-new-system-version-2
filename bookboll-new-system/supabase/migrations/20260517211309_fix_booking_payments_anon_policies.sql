/*
  # Fix booking_payments RLS to match the rest of the app

  All other tables in this project use anon-accessible policies (consistent with
  the admin dashboard pattern where no auth session is required). The booking_payments
  table was blocking all reads/writes because it only allowed authenticated users.
  Adding anon policies to match the pattern used by bookings, listings, customers, etc.
*/

CREATE POLICY "anon_select_booking_payments"
  ON booking_payments FOR SELECT
  TO anon
  USING (true);

CREATE POLICY "anon_insert_booking_payments"
  ON booking_payments FOR INSERT
  TO anon
  WITH CHECK (true);

CREATE POLICY "anon_update_booking_payments"
  ON booking_payments FOR UPDATE
  TO anon
  USING (true)
  WITH CHECK (true);
