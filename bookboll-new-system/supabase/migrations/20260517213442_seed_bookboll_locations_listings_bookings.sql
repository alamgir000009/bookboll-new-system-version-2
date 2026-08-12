/*
  # Seed Bookboll-owned locations, listings, bookings and payments

  Owned by the super admin (owner_user_id = '72fa6328-0c20-45e2-8eda-e40ff6f36c3a')
  who carries username = 'bookboll'.

  1. Locations (3): Bookboll Dubai Hub, Bookboll London Arena, Bookboll Singapore Courts
  2. Listings (6): 2 per location covering Football, Tennis, Padel, Basketball, Badminton, Swimming
  3. Bookings (18): varied dates, statuses and payment methods
  4. Booking Payments: one payment record per booking
*/

DO $$
DECLARE
  super_id   uuid := '72fa6328-0c20-45e2-8eda-e40ff6f36c3a';
  city_dxb   uuid := 'cba3f62e-4be0-483a-b8dc-42760c3cc153';
  city_lon   uuid := '76724e31-fba9-474f-be04-7daed7365de8';
  city_sgp   uuid := 'b2f048da-1eac-4409-9393-d410d1dde861';

  loc_dxb    uuid := gen_random_uuid();
  loc_lon    uuid := gen_random_uuid();
  loc_sgp    uuid := gen_random_uuid();

  lst_fb     uuid := gen_random_uuid();
  lst_ten    uuid := gen_random_uuid();
  lst_pad    uuid := gen_random_uuid();
  lst_bball  uuid := gen_random_uuid();
  lst_bad    uuid := gen_random_uuid();
  lst_swim   uuid := gen_random_uuid();

  cust_sara     uuid := 'bd7ddc47-8aab-40e0-a9ed-f7f9f2e146e6';
  cust_omar     uuid := 'a663e10f-b6ee-4941-8b1f-3000d4541d6e';
  cust_layla    uuid := '34bdd0ff-a5e5-49ea-a75d-f729a2006779';
  cust_mo       uuid := 'd1000000-0000-0000-0000-000000000001';
  cust_aisha    uuid := 'd1000000-0000-0000-0000-000000000002';

  bk1  uuid := gen_random_uuid();
  bk2  uuid := gen_random_uuid();
  bk3  uuid := gen_random_uuid();
  bk4  uuid := gen_random_uuid();
  bk5  uuid := gen_random_uuid();
  bk6  uuid := gen_random_uuid();
  bk7  uuid := gen_random_uuid();
  bk8  uuid := gen_random_uuid();
  bk9  uuid := gen_random_uuid();
  bk10 uuid := gen_random_uuid();
  bk11 uuid := gen_random_uuid();
  bk12 uuid := gen_random_uuid();
BEGIN

  /* ── LOCATIONS ── */
  INSERT INTO locations (id, name, city_id, address, description, status, approval_status, owner_user_id, sort_order)
  VALUES
    (loc_dxb, 'Bookboll Dubai Hub',        city_dxb, 'Business Bay, Dubai, UAE',       'Premium multi-sport facility in the heart of Business Bay.',  'active', 'approved', super_id, 1),
    (loc_lon, 'Bookboll London Arena',      city_lon, 'Canary Wharf, London, UK',        'State-of-the-art sports arena in Canary Wharf.',             'active', 'approved', super_id, 2),
    (loc_sgp, 'Bookboll Singapore Courts',  city_sgp, 'Marina Bay, Singapore 018956',   'Outdoor and indoor courts overlooking Marina Bay.',          'active', 'approved', super_id, 3)
  ON CONFLICT DO NOTHING;

  /* ── LISTINGS ── */
  INSERT INTO listings (id, location_id, name, sport_category, description, capacity, slot_duration, base_price, status, approval_status, listing_type, max_bookings_per_slot, sort_order, booking_color)
  VALUES
    (lst_fb,    loc_dxb, 'Football Pitch A',     'football',    '5-a-side astroturf pitch with floodlights.',     10, 60, 180, 'active', 'approved', 'court', 1, 1, '#16a34a'),
    (lst_ten,   loc_dxb, 'Tennis Court 1',       'tennis',      'Hard court with professional net and lighting.',  4, 60,  90, 'active', 'approved', 'court', 1, 2, '#0891b2'),
    (lst_pad,   loc_lon, 'Padel Court Blue',      'padel',       'Glass-walled padel court with air conditioning.', 4, 90, 120, 'active', 'approved', 'court', 1, 1, '#7c3aed'),
    (lst_bball, loc_lon, 'Basketball Court',      'basketball',  'Full-size NBA-standard hardwood court.',         10, 60, 150, 'active', 'approved', 'court', 1, 2, '#ea580c'),
    (lst_bad,   loc_sgp, 'Badminton Hall',        'badminton',   'Four competition-grade badminton courts.',        8, 60,  60, 'active', 'approved', 'court', 4, 1, '#db2777'),
    (lst_swim,  loc_sgp, 'Olympic Pool Lane',     'swimming',    '50-metre lane rental for training sessions.',     2, 60,  75, 'active', 'approved', 'court', 1, 2, '#0284c7')
  ON CONFLICT DO NOTHING;

  /* ── BOOKINGS ── */
  INSERT INTO bookings (id, customer_id, listing_id, location_id, booking_date, start_time, end_time, players, base_price, addon_total, discount, total, amount_paid, payment_method, payment_status, booking_status, origin, commission_rate, commission_amount, created_at)
  VALUES
    -- Football Dubai
    (bk1,  cust_sara,  lst_fb,    loc_dxb, '2026-05-10', '08:00', '09:00', 10, 180, 0, 0, 180, 180, 'card',   'paid',    'confirmed', 'app', 0, 0, now() - interval '7 days'),
    (bk2,  cust_omar,  lst_fb,    loc_dxb, '2026-05-12', '10:00', '11:00', 10, 180, 20, 0, 200, 200, 'card',  'paid',    'confirmed', 'app', 0, 0, now() - interval '5 days'),
    (bk3,  cust_mo,    lst_fb,    loc_dxb, '2026-05-19', '18:00', '19:00', 10, 180, 0, 0, 180, 0,   'cash',   'pending', 'confirmed', 'app', 0, 0, now() - interval '1 day'),

    -- Tennis Dubai
    (bk4,  cust_layla, lst_ten,   loc_dxb, '2026-05-11', '07:00', '08:00',  4,  90, 0, 10,  80,  80, 'card',  'paid',    'confirmed', 'web', 0, 0, now() - interval '6 days'),
    (bk5,  cust_aisha, lst_ten,   loc_dxb, '2026-05-14', '09:00', '10:00',  4,  90, 0,  0,  90,  90, 'wallet','paid',    'confirmed', 'app', 0, 0, now() - interval '3 days'),

    -- Padel London
    (bk6,  cust_sara,  lst_pad,   loc_lon, '2026-05-09', '11:00', '12:30',  4, 120, 0,  0, 120, 120, 'card',  'paid',    'completed', 'web', 0, 0, now() - interval '8 days'),
    (bk7,  cust_omar,  lst_pad,   loc_lon, '2026-05-15', '14:00', '15:30',  4, 120, 0,  0, 120,  0,  'cash',  'pending', 'confirmed', 'app', 0, 0, now() - interval '2 days'),

    -- Basketball London
    (bk8,  cust_mo,    lst_bball, loc_lon, '2026-05-13', '16:00', '17:00', 10, 150, 30, 0, 180, 180, 'card',  'paid',    'confirmed', 'app', 0, 0, now() - interval '4 days'),
    (bk9,  cust_layla, lst_bball, loc_lon, '2026-05-17', '19:00', '20:00', 10, 150,  0, 0, 150,  0,  'cash',  'pending', 'confirmed', 'app', 0, 0, now() - interval '0 days'),

    -- Badminton Singapore
    (bk10, cust_aisha, lst_bad,   loc_sgp, '2026-05-08', '06:00', '07:00',  4,  60, 0,  0,  60,  60, 'card',  'paid',    'completed', 'web', 0, 0, now() - interval '9 days'),
    (bk11, cust_sara,  lst_bad,   loc_sgp, '2026-05-16', '08:00', '09:00',  4,  60, 0,  0,  60,  60, 'wallet','paid',    'confirmed', 'app', 0, 0, now() - interval '1 day'),

    -- Swimming Singapore
    (bk12, cust_omar,  lst_swim,  loc_sgp, '2026-05-11', '05:30', '06:30',  2,  75, 0,  0,  75,  75, 'card',  'paid',    'completed', 'web', 0, 0, now() - interval '6 days')
  ON CONFLICT DO NOTHING;

  /* ── BOOKING PAYMENTS ── */
  INSERT INTO booking_payments (booking_id, payment_type, method, amount, currency, recipient, status, paid_at, notes)
  VALUES
    (bk1,  'booking', 'card',   180, 'AED', '@bookboll', 'paid',    now() - interval '7 days',  'Football pitch booking — paid online'),
    (bk2,  'booking', 'card',   200, 'AED', '@bookboll', 'paid',    now() - interval '5 days',  'Football pitch + addons'),
    (bk3,  'booking', 'cash',   180, 'AED', '@bookboll', 'pending', null,                        'Cash due at venue'),
    (bk4,  'booking', 'card',    80, 'AED', '@bookboll', 'paid',    now() - interval '6 days',  'Tennis — discount applied'),
    (bk5,  'booking', 'wallet',  90, 'AED', '@bookboll', 'paid',    now() - interval '3 days',  'Wallet payment'),
    (bk6,  'booking', 'card',   120, 'GBP', '@bookboll', 'paid',    now() - interval '8 days',  'Padel London'),
    (bk7,  'booking', 'cash',   120, 'GBP', '@bookboll', 'pending', null,                        'Cash due at venue'),
    (bk8,  'booking', 'card',   180, 'GBP', '@bookboll', 'paid',    now() - interval '4 days',  'Basketball + addons'),
    (bk9,  'booking', 'cash',   150, 'GBP', '@bookboll', 'pending', null,                        'Cash due at venue'),
    (bk10, 'booking', 'card',    60, 'SGD', '@bookboll', 'paid',    now() - interval '9 days',  'Badminton Singapore'),
    (bk11, 'booking', 'wallet',  60, 'SGD', '@bookboll', 'paid',    now() - interval '1 day',   'Wallet payment'),
    (bk12, 'booking', 'card',    75, 'SGD', '@bookboll', 'paid',    now() - interval '6 days',  'Swimming lane rental')
  ON CONFLICT DO NOTHING;

END $$;
