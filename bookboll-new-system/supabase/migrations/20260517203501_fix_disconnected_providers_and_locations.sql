/*
  # Fix disconnected providers and empty location

  Issues found in audit:
  1. Mahmoud Hassan (Cairo Football Arena) — no owned location
  2. Khalid Al Saud (Riyadh Padel Club) — no owned location
  3. "DSC Marina test test test" location — has no listings

  Fixes:
  - Assign Mahmoud as owner of Cairo Elite Courts location
  - Create a new Riyadh location for Khalid
  - Add listings + payment options to DSC Marina
  - Add bookings + payment rows for the new content
*/

-- ── Fix 1: Assign Mahmoud Hassan as owner of Cairo Elite Courts ────────────
UPDATE locations
SET owner_user_id = '8d7f528d-0fd7-48d5-9f1a-8f19dc80c392'  -- Mahmoud Hassan
WHERE id = 'b1000000-0000-0000-0000-000000000003';           -- Cairo Elite Courts — Maadi

-- ── Fix 2: Create Riyadh location for Khalid Al Saud ──────────────────────
INSERT INTO locations (id, name, city_id, address, phone, status, approval_status, owner_user_id)
VALUES (
  'b1000000-0000-0000-0000-000000000005',
  'Riyadh Padel Club — Al Olaya',
  '526658d4-3c0e-49f4-b5ab-e698c6407ef2',  -- Riyadh
  'Al Olaya District, Riyadh',
  '+966 11 333 4400',
  'active', 'approved',
  '21f623cf-de7d-44a7-a638-258c9c284173'   -- Khalid Al Saud
)
ON CONFLICT (id) DO NOTHING;

-- Listings for Riyadh Padel Club
INSERT INTO listings (id, location_id, name, sport_category, description, base_price, capacity, slot_duration, status, approval_status, listing_type)
VALUES
  ('c1000000-0000-0000-0000-000000000008',
   'b1000000-0000-0000-0000-000000000005',
   'Padel Court A', 'Padel', 'Indoor premium padel court',
   200, 4, 60, 'active', 'approved', 'court'),
  ('c1000000-0000-0000-0000-000000000009',
   'b1000000-0000-0000-0000-000000000005',
   'Padel Court B', 'Padel', 'Indoor premium padel court',
   200, 4, 60, 'active', 'approved', 'court')
ON CONFLICT (id) DO NOTHING;

-- Payment options for Riyadh listings
INSERT INTO listing_payment_options (listing_id, cash_on_arrival, deposit_enabled, deposit_amount, deposit_percentage, online_payment_enabled)
VALUES
  ('c1000000-0000-0000-0000-000000000008', false, true, 0, 20, false),
  ('c1000000-0000-0000-0000-000000000009', false, true, 0, 20, false)
ON CONFLICT (listing_id) DO NOTHING;

-- ── Fix 3: Add listings to "DSC Marina test test test" ─────────────────────
INSERT INTO listings (id, location_id, name, sport_category, description, base_price, capacity, slot_duration, status, approval_status, listing_type)
VALUES
  ('c1000000-0000-0000-0000-000000000010',
   '513305f5-b266-4a7c-b411-9d5369a2ca32',  -- DSC Marina
   'Marina Padel Court 1', 'Padel', 'Waterfront padel court with marina views',
   220, 4, 60, 'active', 'approved', 'court'),
  ('c1000000-0000-0000-0000-000000000011',
   '513305f5-b266-4a7c-b411-9d5369a2ca32',
   'Marina Football Turf', 'Football', '5-a-side synthetic turf at the marina',
   280, 10, 60, 'active', 'approved', 'court')
ON CONFLICT (id) DO NOTHING;

-- Payment options for DSC Marina listings (Ahmed's location → deposit model)
INSERT INTO listing_payment_options (listing_id, cash_on_arrival, deposit_enabled, deposit_amount, deposit_percentage, online_payment_enabled)
VALUES
  ('c1000000-0000-0000-0000-000000000010', false, true, 0, 20, false),
  ('c1000000-0000-0000-0000-000000000011', false, true, 0, 20, false)
ON CONFLICT (listing_id) DO NOTHING;

-- ── Sample bookings for new listings ───────────────────────────────────────

INSERT INTO bookings (id, customer_id, listing_id, location_id, booking_date, start_time, end_time, players, base_price, total, payment_method, payment_status, booking_status, origin, commission_rate, commission_amount)
VALUES
  -- DSC Marina — Padel
  ('e1000000-0000-0000-0000-000000000015',
   'bd7ddc47-8aab-40e0-a9ed-f7f9f2e146e6',  -- Sara Ahmed
   'c1000000-0000-0000-0000-000000000010',
   '513305f5-b266-4a7c-b411-9d5369a2ca32',
   '2026-05-15', '18:00', '19:00', 4, 220, 220,
   'cash', 'deposit_paid', 'confirmed', 'bookboll_app', 20, 44),

  -- Riyadh Padel Club — Court A
  ('e1000000-0000-0000-0000-000000000016',
   'd1000000-0000-0000-0000-000000000005',  -- Bilal
   'c1000000-0000-0000-0000-000000000008',
   'b1000000-0000-0000-0000-000000000005',
   '2026-05-14', '20:00', '21:00', 4, 200, 200,
   'cash', 'deposit_paid', 'confirmed', 'bookboll_app', 20, 40),

  -- Riyadh Padel Club — Court B
  ('e1000000-0000-0000-0000-000000000017',
   'd1000000-0000-0000-0000-000000000006',  -- Reem
   'c1000000-0000-0000-0000-000000000009',
   'b1000000-0000-0000-0000-000000000005',
   '2026-05-16', '19:00', '20:00', 4, 200, 200,
   'cash', 'pending', 'confirmed', 'bookboll_app', 20, 40)
ON CONFLICT (id) DO NOTHING;

-- booking_payments for new bookings
INSERT INTO booking_payments (booking_id, payment_type, method, amount, currency, recipient, status, paid_at)
VALUES
  ('e1000000-0000-0000-0000-000000000015', 'deposit',   'online',  44.00, 'AED', 'bookboll', 'paid',    '2026-05-14T17:00:00Z'),
  ('e1000000-0000-0000-0000-000000000015', 'remaining', 'cash',   176.00, 'AED', 'provider', 'pending', NULL),

  ('e1000000-0000-0000-0000-000000000016', 'deposit',   'online',  40.00, 'AED', 'bookboll', 'paid',    '2026-05-13T19:00:00Z'),
  ('e1000000-0000-0000-0000-000000000016', 'remaining', 'cash',   160.00, 'AED', 'provider', 'pending', NULL),

  ('e1000000-0000-0000-0000-000000000017', 'deposit',   'online',  40.00, 'AED', 'bookboll', 'paid',    '2026-05-15T18:00:00Z'),
  ('e1000000-0000-0000-0000-000000000017', 'remaining', 'cash',   160.00, 'AED', 'provider', 'pending', NULL)
ON CONFLICT DO NOTHING;
