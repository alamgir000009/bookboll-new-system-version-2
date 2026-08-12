/*
  # Comprehensive Dummy Data Seed

  Seeds realistic data across the full platform to make every page meaningful:

  1. New provider admins (Doha, Abu Dhabi, Cairo expansion)
  2. New locations — 1-2 per provider, spread across cities
  3. 1 Bookboll-operated location (no owner_user_id) in Dubai
  4. Listings for each new location with payment options
  5. Payment options for all existing listings (were missing)
  6. More customers (10 total)
  7. ~20 bookings spread across providers, dates, origins
  8. booking_payments rows for all bookings (backfill existing 3 + new ones)
     - deposit+remaining split for provider listings
     - full online for Bookboll-operated
     - full cash for manual admin bookings
*/

-- ─── NEW PROVIDER ADMINS ───────────────────────────────────────────────────

INSERT INTO admin_users (id, full_name, business_name, role, email)
VALUES
  ('a1000000-0000-0000-0000-000000000001', 'Fatima Al Rashidi',  'Al Wasl Sports Hub',     'provider_admin', 'fatima@alwasl.ae'),
  ('a1000000-0000-0000-0000-000000000002', 'Yusuf Al Farsi',     'Doha Arena',             'provider_admin', 'yusuf@dohaarena.qa'),
  ('a1000000-0000-0000-0000-000000000003', 'Nadia Kamel',        'Cairo Elite Courts',     'provider_admin', 'nadia@cairoelite.eg')
ON CONFLICT (id) DO NOTHING;

-- ─── NEW LOCATIONS ─────────────────────────────────────────────────────────
-- Dubai (cba3f62e), Abu Dhabi (9aefa213), Doha (97064dd0), Cairo (d128a349)

INSERT INTO locations (id, name, city_id, address, phone, status, approval_status, owner_user_id)
VALUES
  -- Fatima — Abu Dhabi
  ('b1000000-0000-0000-0000-000000000001',
   'Al Wasl Sports Hub — Abu Dhabi',
   '9aefa213-8cc9-4d7d-a2e7-2d16d05a0501',
   'Corniche Road, Abu Dhabi',
   '+971 2 555 1100',
   'active', 'approved',
   'a1000000-0000-0000-0000-000000000001'),

  -- Yusuf — Doha
  ('b1000000-0000-0000-0000-000000000002',
   'Doha Arena — West Bay',
   '97064dd0-b0a7-4c81-be7e-488bb821a975',
   'West Bay Sports District, Doha',
   '+974 4444 2200',
   'active', 'approved',
   'a1000000-0000-0000-0000-000000000002'),

  -- Nadia — Cairo
  ('b1000000-0000-0000-0000-000000000003',
   'Cairo Elite Courts — Maadi',
   'd128a349-5b01-4b48-8bad-b4c8d5bc475a',
   'Maadi Sports Club, Cairo',
   '+20 2 2525 3300',
   'active', 'approved',
   'a1000000-0000-0000-0000-000000000003'),

  -- Bookboll-operated (no owner_user_id) — Dubai
  ('b1000000-0000-0000-0000-000000000004',
   'Bookboll @ Business Bay',
   'cba3f62e-4be0-483a-b8dc-42760c3cc153',
   'Business Bay Boulevard, Dubai',
   '+971 4 888 9900',
   'active', 'approved',
   NULL)
ON CONFLICT (id) DO NOTHING;

-- ─── LISTINGS ──────────────────────────────────────────────────────────────

INSERT INTO listings (id, location_id, name, sport_category, description, base_price, capacity, slot_duration, status, approval_status, listing_type)
VALUES
  -- Al Wasl Abu Dhabi
  ('c1000000-0000-0000-0000-000000000001',
   'b1000000-0000-0000-0000-000000000001',
   'Padel Court Blue', 'Padel', 'Outdoor padel court with floodlights',
   180, 4, 60, 'active', 'approved', 'court'),
  ('c1000000-0000-0000-0000-000000000002',
   'b1000000-0000-0000-0000-000000000001',
   'Football Turf A', 'Football', '7-a-side synthetic turf pitch',
   300, 14, 90, 'active', 'approved', 'court'),

  -- Doha Arena
  ('c1000000-0000-0000-0000-000000000003',
   'b1000000-0000-0000-0000-000000000002',
   'Padel Court 01', 'Padel', 'Indoor air-conditioned padel',
   220, 4, 60, 'active', 'approved', 'court'),
  ('c1000000-0000-0000-0000-000000000004',
   'b1000000-0000-0000-0000-000000000002',
   'Tennis Court Main', 'Tennis', 'Hard court, 2 courts available',
   160, 4, 60, 'active', 'approved', 'court'),

  -- Cairo Elite Courts
  ('c1000000-0000-0000-0000-000000000005',
   'b1000000-0000-0000-0000-000000000003',
   'Football Ground 1', 'Football', '5-a-side turf',
   120, 10, 60, 'active', 'approved', 'court'),

  -- Bookboll-operated
  ('c1000000-0000-0000-0000-000000000006',
   'b1000000-0000-0000-0000-000000000004',
   'Bookboll Padel Suite', 'Padel', 'Premium indoor padel operated by Bookboll',
   250, 4, 60, 'active', 'approved', 'court'),
  ('c1000000-0000-0000-0000-000000000007',
   'b1000000-0000-0000-0000-000000000004',
   'Bookboll Tennis Court', 'Tennis', 'Premium tennis operated by Bookboll',
   200, 4, 60, 'active', 'approved', 'court')
ON CONFLICT (id) DO NOTHING;

-- ─── LISTING PAYMENT OPTIONS ───────────────────────────────────────────────
-- Existing listings (were missing) + new ones
-- Provider listings: deposit_enabled, no cash, no online
-- Bookboll-operated: online_payment_enabled

INSERT INTO listing_payment_options (listing_id, cash_on_arrival, deposit_enabled, deposit_amount, deposit_percentage, online_payment_enabled)
VALUES
  -- Existing DSC listings (provider-owned → deposit only)
  ('fb02df14-d7c8-436e-99d0-f933b0c60255', false, true, 0, 20, false),
  ('7f085de3-fe71-48e1-bb67-f54f5112edf6', false, true, 0, 20, false),
  ('ad583fad-6fd3-454b-9d13-6ab1a0c8e3f1', false, true, 0, 20, false),
  -- New provider listings (deposit only)
  ('c1000000-0000-0000-0000-000000000001', false, true, 0, 20, false),
  ('c1000000-0000-0000-0000-000000000002', false, true, 0, 20, false),
  ('c1000000-0000-0000-0000-000000000003', false, true, 0, 20, false),
  ('c1000000-0000-0000-0000-000000000004', false, true, 0, 20, false),
  ('c1000000-0000-0000-0000-000000000005', false, true, 0, 20, false),
  -- Bookboll-operated listings: full online payment
  ('c1000000-0000-0000-0000-000000000006', false, false, 0, 0, true),
  ('c1000000-0000-0000-0000-000000000007', false, false, 0, 0, true)
ON CONFLICT (listing_id) DO NOTHING;

-- ─── MORE CUSTOMERS ────────────────────────────────────────────────────────

INSERT INTO customers (id, full_name, email, phone)
VALUES
  ('d1000000-0000-0000-0000-000000000001', 'Mohammed Al Amin',   'mohammed@mail.com', '+971 50 111 2233'),
  ('d1000000-0000-0000-0000-000000000002', 'Aisha Khalil',       'aisha@mail.com',    '+971 55 222 3344'),
  ('d1000000-0000-0000-0000-000000000003', 'Tariq Nasser',       'tariq@mail.com',    '+974 33 444 5566'),
  ('d1000000-0000-0000-0000-000000000004', 'Hana El Sayed',      'hana@mail.com',     '+20 100 555 6677'),
  ('d1000000-0000-0000-0000-000000000005', 'Bilal Mahmoud',      'bilal@mail.com',    '+966 50 666 7788'),
  ('d1000000-0000-0000-0000-000000000006', 'Reem Al Muhairi',    'reem@mail.com',     '+971 56 777 8899'),
  ('d1000000-0000-0000-0000-000000000007', 'Samer Diab',         'samer@mail.com',    '+961 70 888 9900')
ON CONFLICT (id) DO NOTHING;

-- ─── BOOKINGS ─────────────────────────────────────────────────────────────
-- Mix of: deposit_paid (most), paid (full), pending (few)
-- Origins: bookboll_app, bookboll_web, manual_admin

INSERT INTO bookings (id, customer_id, listing_id, location_id, booking_date, start_time, end_time, players, base_price, total, payment_method, payment_status, booking_status, origin, commission_rate, commission_amount)
VALUES
  -- ── DSC (Ahmed, Dubai) ─────────────────────────────────────────────────
  ('e1000000-0000-0000-0000-000000000001',
   'd1000000-0000-0000-0000-000000000001',
   'fb02df14-d7c8-436e-99d0-f933b0c60255',   -- Football Turf #12
   '41919b8c-770e-4b96-81be-05cc07261687',
   '2026-05-10', '18:00', '19:30', 10, 250, 250,
   'cash', 'deposit_paid', 'confirmed', 'bookboll_app', 20, 50),

  ('e1000000-0000-0000-0000-000000000002',
   'd1000000-0000-0000-0000-000000000002',
   '7f085de3-fe71-48e1-bb67-f54f5112edf6',   -- Tennis Court A
   '41919b8c-770e-4b96-81be-05cc07261687',
   '2026-05-11', '08:00', '09:00', 2, 150, 150,
   'cash', 'paid', 'completed', 'bookboll_web', 20, 30),

  ('e1000000-0000-0000-0000-000000000003',
   'd1000000-0000-0000-0000-000000000003',
   'ad583fad-6fd3-454b-9d13-6ab1a0c8e3f1',   -- Padel Court 1
   '41919b8c-770e-4b96-81be-05cc07261687',
   '2026-05-12', '20:00', '21:00', 4, 200, 200,
   'cash', 'pending', 'confirmed', 'bookboll_app', 20, 40),

  -- ── Al Wasl Abu Dhabi (Fatima) ─────────────────────────────────────────
  ('e1000000-0000-0000-0000-000000000004',
   'd1000000-0000-0000-0000-000000000004',
   'c1000000-0000-0000-0000-000000000001',   -- Padel Court Blue
   'b1000000-0000-0000-0000-000000000001',
   '2026-05-12', '10:00', '11:00', 4, 180, 180,
   'cash', 'deposit_paid', 'confirmed', 'bookboll_app', 20, 36),

  ('e1000000-0000-0000-0000-000000000005',
   'd1000000-0000-0000-0000-000000000005',
   'c1000000-0000-0000-0000-000000000002',   -- Football Turf A
   'b1000000-0000-0000-0000-000000000001',
   '2026-05-13', '17:00', '18:30', 14, 300, 300,
   'cash', 'paid', 'completed', 'bookboll_web', 20, 60),

  ('e1000000-0000-0000-0000-000000000006',
   'd1000000-0000-0000-0000-000000000006',
   'c1000000-0000-0000-0000-000000000001',   -- Padel Court Blue
   'b1000000-0000-0000-0000-000000000001',
   '2026-05-14', '19:00', '20:00', 4, 180, 180,
   'cash', 'deposit_paid', 'confirmed', 'bookboll_app', 20, 36),

  -- ── Doha Arena (Yusuf) ────────────────────────────────────────────────
  ('e1000000-0000-0000-0000-000000000007',
   'd1000000-0000-0000-0000-000000000001',
   'c1000000-0000-0000-0000-000000000003',   -- Padel Court 01
   'b1000000-0000-0000-0000-000000000002',
   '2026-05-11', '16:00', '17:00', 4, 220, 220,
   'cash', 'deposit_paid', 'confirmed', 'bookboll_app', 20, 44),

  ('e1000000-0000-0000-0000-000000000008',
   'd1000000-0000-0000-0000-000000000007',
   'c1000000-0000-0000-0000-000000000004',   -- Tennis Court Main
   'b1000000-0000-0000-0000-000000000002',
   '2026-05-14', '09:00', '10:00', 2, 160, 160,
   'cash', 'paid', 'completed', 'bookboll_web', 20, 32),

  ('e1000000-0000-0000-0000-000000000009',
   'd1000000-0000-0000-0000-000000000002',
   'c1000000-0000-0000-0000-000000000003',   -- Padel Court 01
   'b1000000-0000-0000-0000-000000000002',
   '2026-05-15', '20:00', '21:00', 4, 220, 220,
   'cash', 'pending', 'confirmed', 'bookboll_app', 20, 44),

  -- ── Cairo Elite (Nadia) ────────────────────────────────────────────────
  ('e1000000-0000-0000-0000-000000000010',
   'd1000000-0000-0000-0000-000000000003',
   'c1000000-0000-0000-0000-000000000005',   -- Football Ground 1
   'b1000000-0000-0000-0000-000000000003',
   '2026-05-10', '15:00', '16:00', 10, 120, 120,
   'cash', 'deposit_paid', 'confirmed', 'bookboll_app', 20, 24),

  ('e1000000-0000-0000-0000-000000000011',
   'd1000000-0000-0000-0000-000000000004',
   'c1000000-0000-0000-0000-000000000005',   -- Football Ground 1
   'b1000000-0000-0000-0000-000000000003',
   '2026-05-13', '18:00', '19:00', 10, 120, 120,
   'cash', 'paid', 'completed', 'bookboll_web', 20, 24),

  -- ── Bookboll-operated (full online, all to Bookboll) ─────────────────
  ('e1000000-0000-0000-0000-000000000012',
   'd1000000-0000-0000-0000-000000000005',
   'c1000000-0000-0000-0000-000000000006',   -- Bookboll Padel Suite
   'b1000000-0000-0000-0000-000000000004',
   '2026-05-12', '12:00', '13:00', 4, 250, 250,
   'online', 'paid', 'completed', 'bookboll_app', 100, 250),

  ('e1000000-0000-0000-0000-000000000013',
   'd1000000-0000-0000-0000-000000000006',
   'c1000000-0000-0000-0000-000000000007',   -- Bookboll Tennis Court
   'b1000000-0000-0000-0000-000000000004',
   '2026-05-13', '10:00', '11:00', 2, 200, 200,
   'online', 'paid', 'completed', 'bookboll_web', 100, 200),

  ('e1000000-0000-0000-0000-000000000014',
   'd1000000-0000-0000-0000-000000000007',
   'c1000000-0000-0000-0000-000000000006',   -- Bookboll Padel Suite
   'b1000000-0000-0000-0000-000000000004',
   '2026-05-16', '18:00', '19:00', 4, 250, 250,
   'online', 'pending', 'confirmed', 'bookboll_app', 100, 250)
ON CONFLICT (id) DO NOTHING;

-- ─── BOOKING PAYMENTS ──────────────────────────────────────────────────────
-- Backfill existing 3 bookings + all 14 new bookings
-- Logic:
--   deposit+remaining → provider listings (20% online to bookboll, 80% remaining to provider)
--   full online       → Bookboll-operated (100% online to bookboll)
--   full cash pending → provider listings with manual origin

INSERT INTO booking_payments (booking_id, payment_type, method, amount, currency, recipient, status, paid_at)
VALUES

  -- ── Existing booking 525c (Football Turf #12, paid, DSC provider) ─────
  ('525cecf8-c7fa-4289-9678-d619f7f355d5', 'deposit',   'online', 50.00, 'AED', 'bookboll', 'paid', '2026-05-06T10:00:00Z'),
  ('525cecf8-c7fa-4289-9678-d619f7f355d5', 'remaining', 'cash',  200.00, 'AED', 'provider', 'paid', '2026-05-06T18:00:00Z'),

  -- ── Existing booking 759e (Tennis Court A, pending, DSC provider) ─────
  ('759e5736-c9d3-42be-ad42-e075013b796f', 'deposit',   'online', 30.00, 'AED', 'bookboll', 'paid', '2026-05-06T11:00:00Z'),
  ('759e5736-c9d3-42be-ad42-e075013b796f', 'remaining', 'cash',  120.00, 'AED', 'provider', 'pending', NULL),

  -- ── Existing booking 7b1e (Padel Court 1, deposit_paid, DSC provider)
  ('7b1ec398-87aa-4760-a828-2cd60025525d', 'deposit',   'online', 40.00, 'AED', 'bookboll', 'paid', '2026-05-07T09:00:00Z'),
  ('7b1ec398-87aa-4760-a828-2cd60025525d', 'remaining', 'cash',  160.00, 'AED', 'provider', 'pending', NULL),

  -- ── New bookings — DSC (provider, deposit model) ──────────────────────
  ('e1000000-0000-0000-0000-000000000001', 'deposit',   'online',  50.00, 'AED', 'bookboll', 'paid',    '2026-05-09T14:00:00Z'),
  ('e1000000-0000-0000-0000-000000000001', 'remaining', 'cash',   200.00, 'AED', 'provider', 'pending', NULL),

  ('e1000000-0000-0000-0000-000000000002', 'deposit',   'online',  30.00, 'AED', 'bookboll', 'paid',    '2026-05-10T07:00:00Z'),
  ('e1000000-0000-0000-0000-000000000002', 'remaining', 'cash',   120.00, 'AED', 'provider', 'paid',    '2026-05-11T08:00:00Z'),

  ('e1000000-0000-0000-0000-000000000003', 'deposit',   'online',  40.00, 'AED', 'bookboll', 'paid',    '2026-05-11T19:00:00Z'),
  ('e1000000-0000-0000-0000-000000000003', 'remaining', 'cash',   160.00, 'AED', 'provider', 'pending', NULL),

  -- ── Al Wasl Abu Dhabi ─────────────────────────────────────────────────
  ('e1000000-0000-0000-0000-000000000004', 'deposit',   'online',  36.00, 'AED', 'bookboll', 'paid',    '2026-05-11T09:00:00Z'),
  ('e1000000-0000-0000-0000-000000000004', 'remaining', 'cash',   144.00, 'AED', 'provider', 'pending', NULL),

  ('e1000000-0000-0000-0000-000000000005', 'deposit',   'online',  60.00, 'AED', 'bookboll', 'paid',    '2026-05-12T16:00:00Z'),
  ('e1000000-0000-0000-0000-000000000005', 'remaining', 'card',   240.00, 'AED', 'provider', 'paid',    '2026-05-13T17:00:00Z'),

  ('e1000000-0000-0000-0000-000000000006', 'deposit',   'online',  36.00, 'AED', 'bookboll', 'paid',    '2026-05-13T18:00:00Z'),
  ('e1000000-0000-0000-0000-000000000006', 'remaining', 'cash',   144.00, 'AED', 'provider', 'pending', NULL),

  -- ── Doha Arena ────────────────────────────────────────────────────────
  ('e1000000-0000-0000-0000-000000000007', 'deposit',   'online',  44.00, 'AED', 'bookboll', 'paid',    '2026-05-10T15:00:00Z'),
  ('e1000000-0000-0000-0000-000000000007', 'remaining', 'cash',   176.00, 'AED', 'provider', 'pending', NULL),

  ('e1000000-0000-0000-0000-000000000008', 'deposit',   'online',  32.00, 'AED', 'bookboll', 'paid',    '2026-05-13T08:00:00Z'),
  ('e1000000-0000-0000-0000-000000000008', 'remaining', 'cash',   128.00, 'AED', 'provider', 'paid',    '2026-05-14T09:00:00Z'),

  ('e1000000-0000-0000-0000-000000000009', 'deposit',   'online',  44.00, 'AED', 'bookboll', 'paid',    '2026-05-14T19:00:00Z'),
  ('e1000000-0000-0000-0000-000000000009', 'remaining', 'cash',   176.00, 'AED', 'provider', 'pending', NULL),

  -- ── Cairo Elite ────────────────────────────────────────────────────────
  ('e1000000-0000-0000-0000-000000000010', 'deposit',   'online',  24.00, 'AED', 'bookboll', 'paid',    '2026-05-09T14:00:00Z'),
  ('e1000000-0000-0000-0000-000000000010', 'remaining', 'cash',    96.00, 'AED', 'provider', 'pending', NULL),

  ('e1000000-0000-0000-0000-000000000011', 'deposit',   'online',  24.00, 'AED', 'bookboll', 'paid',    '2026-05-12T17:00:00Z'),
  ('e1000000-0000-0000-0000-000000000011', 'remaining', 'cash',    96.00, 'AED', 'provider', 'paid',    '2026-05-13T18:00:00Z'),

  -- ── Bookboll-operated (full online, 100% to Bookboll) ─────────────────
  ('e1000000-0000-0000-0000-000000000012', 'full', 'online', 250.00, 'AED', 'bookboll', 'paid',    '2026-05-12T11:30:00Z'),
  ('e1000000-0000-0000-0000-000000000013', 'full', 'online', 200.00, 'AED', 'bookboll', 'paid',    '2026-05-13T09:30:00Z'),
  ('e1000000-0000-0000-0000-000000000014', 'full', 'online', 250.00, 'AED', 'bookboll', 'pending', NULL)

ON CONFLICT DO NOTHING;
