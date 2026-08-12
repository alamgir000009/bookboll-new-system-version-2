/*
  # Fix Nadia Kamel location ownership

  Cairo Elite Courts was reassigned to Mahmoud Hassan, but it was created for Nadia Kamel
  (Cairo Elite Courts business). Mahmoud Hassan's business is "Cairo Football Arena" and
  needs its own separate location. This migration:
  - Gives Cairo Elite Courts back to Nadia Kamel
  - Creates a new Cairo Football Arena location for Mahmoud Hassan with listings
*/

-- Restore Cairo Elite Courts to Nadia Kamel
UPDATE locations
SET owner_user_id = 'a1000000-0000-0000-0000-000000000003'  -- Nadia Kamel
WHERE id = 'b1000000-0000-0000-0000-000000000003';          -- Cairo Elite Courts — Maadi

-- Create Cairo Football Arena location for Mahmoud Hassan
INSERT INTO locations (id, name, city_id, address, phone, status, approval_status, owner_user_id)
VALUES (
  'b1000000-0000-0000-0000-000000000006',
  'Cairo Football Arena — Heliopolis',
  'd128a349-5b01-4b48-8bad-b4c8d5bc475a',  -- Cairo
  'Heliopolis District, Cairo',
  '+20 2 2411 7700',
  'active', 'approved',
  '8d7f528d-0fd7-48d5-9f1a-8f19dc80c392'  -- Mahmoud Hassan
)
ON CONFLICT (id) DO NOTHING;

-- Listings for Cairo Football Arena
INSERT INTO listings (id, location_id, name, sport_category, description, base_price, capacity, slot_duration, status, approval_status, listing_type)
VALUES
  ('c1000000-0000-0000-0000-000000000012',
   'b1000000-0000-0000-0000-000000000006',
   'Stadium Turf A', 'Football', 'Full-size 7-a-side turf',
   150, 14, 90, 'active', 'approved', 'court'),
  ('c1000000-0000-0000-0000-000000000013',
   'b1000000-0000-0000-0000-000000000006',
   'Stadium Turf B', 'Football', 'Full-size 7-a-side turf',
   150, 14, 90, 'active', 'approved', 'court')
ON CONFLICT (id) DO NOTHING;

-- Payment options
INSERT INTO listing_payment_options (listing_id, cash_on_arrival, deposit_enabled, deposit_amount, deposit_percentage, online_payment_enabled)
VALUES
  ('c1000000-0000-0000-0000-000000000012', false, true, 0, 20, false),
  ('c1000000-0000-0000-0000-000000000013', false, true, 0, 20, false)
ON CONFLICT (listing_id) DO NOTHING;

-- Sample bookings for Cairo Football Arena
INSERT INTO bookings (id, customer_id, listing_id, location_id, booking_date, start_time, end_time, players, base_price, total, payment_method, payment_status, booking_status, origin, commission_rate, commission_amount)
VALUES
  ('e1000000-0000-0000-0000-000000000018',
   'd1000000-0000-0000-0000-000000000007',  -- Samer Diab
   'c1000000-0000-0000-0000-000000000012',
   'b1000000-0000-0000-0000-000000000006',
   '2026-05-15', '16:00', '17:30', 14, 150, 150,
   'cash', 'deposit_paid', 'confirmed', 'bookboll_app', 20, 30),
  ('e1000000-0000-0000-0000-000000000019',
   'd1000000-0000-0000-0000-000000000001',  -- Mohammed
   'c1000000-0000-0000-0000-000000000013',
   'b1000000-0000-0000-0000-000000000006',
   '2026-05-16', '18:00', '19:30', 14, 150, 150,
   'cash', 'pending', 'confirmed', 'bookboll_web', 20, 30)
ON CONFLICT (id) DO NOTHING;

-- Payment rows for Cairo Football Arena bookings
INSERT INTO booking_payments (booking_id, payment_type, method, amount, currency, recipient, status, paid_at)
VALUES
  ('e1000000-0000-0000-0000-000000000018', 'deposit',   'online',  30.00, 'AED', 'bookboll', 'paid',    '2026-05-14T15:00:00Z'),
  ('e1000000-0000-0000-0000-000000000018', 'remaining', 'cash',   120.00, 'AED', 'provider', 'pending', NULL),
  ('e1000000-0000-0000-0000-000000000019', 'deposit',   'online',  30.00, 'AED', 'bookboll', 'paid',    '2026-05-15T17:00:00Z'),
  ('e1000000-0000-0000-0000-000000000019', 'remaining', 'cash',   120.00, 'AED', 'provider', 'pending', NULL)
ON CONFLICT DO NOTHING;
