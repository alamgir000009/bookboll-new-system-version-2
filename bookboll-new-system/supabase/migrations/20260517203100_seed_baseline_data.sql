-- Baseline seed: original data that existed in the old database before migrations were tracked.
-- These entities are referenced by hardcoded UUIDs in later migrations (20260517203315,
-- 20260517203501, 20260517213442, 20260521020408, etc.) and must exist first.

-- ── Admin users (original providers + super admin) ────────────────────────────
INSERT INTO admin_users (id, full_name, business_name, email, role, status)
VALUES
  ('72fa6328-0c20-45e2-8eda-e40ff6f36c3a', 'Bookboll Admin',    NULL,                   'admin@bookboll.com',   'super_admin',    'active'),
  ('5ea6f1c5-0d55-4d50-a9a5-24003caadb52', 'Ahmed Al Mansouri', 'Dubai Sports City',    'ahmed@dsc.ae',         'provider_admin', 'active'),
  ('8d7f528d-0fd7-48d5-9f1a-8f19dc80c392', 'Mahmoud Hassan',    'Cairo Football Arena', 'mahmoud@cfa.eg',       'provider_admin', 'active'),
  ('21f623cf-de7d-44a7-a638-258c9c284173', 'Khalid Al Saud',    'Riyadh Padel Club',    'khalid@rpc.sa',        'provider_admin', 'active')
ON CONFLICT (id) DO NOTHING;

-- ── Original customers ────────────────────────────────────────────────────────
INSERT INTO customers (id, full_name, email, phone)
VALUES
  ('bd7ddc47-8aab-40e0-a9ed-f7f9f2e146e6', 'Sara Ahmed',    'sara@mail.com',  '+971 50 999 1111'),
  ('a663e10f-b6ee-4941-8b1f-3000d4541d6e', 'Omar Al Farsi', 'omar@mail.com',  '+971 55 999 2222'),
  ('34bdd0ff-a5e5-49ea-a75d-f729a2006779', 'Layla Al Amri', 'layla@mail.com', '+971 56 999 3333')
ON CONFLICT (id) DO NOTHING;

-- ── DSC locations (Dubai Sports City — main + marina) ─────────────────────────
INSERT INTO locations (id, name, city_id, address, phone, status, approval_status, owner_user_id)
VALUES
  ('41919b8c-770e-4b96-81be-05cc07261687',
   'Dubai Sports City — Main Courts',
   'cba3f62e-4be0-483a-b8dc-42760c3cc153',
   'Dubai Sports City, Mohamed Bin Zayed City, Dubai',
   '+971 4 555 0001',
   'active', 'approved',
   '5ea6f1c5-0d55-4d50-a9a5-24003caadb52'),

  ('513305f5-b266-4a7c-b411-9d5369a2ca32',
   'DSC Marina',
   'cba3f62e-4be0-483a-b8dc-42760c3cc153',
   'Dubai Marina Sports Complex, Dubai',
   '+971 4 555 0002',
   'active', 'approved',
   '5ea6f1c5-0d55-4d50-a9a5-24003caadb52')
ON CONFLICT (id) DO NOTHING;

-- ── DSC listings (original 3 courts) ─────────────────────────────────────────
INSERT INTO listings (id, location_id, name, sport_category, description, base_price, capacity, slot_duration, status, approval_status, listing_type)
VALUES
  ('fb02df14-d7c8-436e-99d0-f933b0c60255',
   '41919b8c-770e-4b96-81be-05cc07261687',
   'Football Turf #12', 'Football', '5-a-side floodlit synthetic turf',
   250, 10, 90, 'active', 'approved', 'court'),

  ('7f085de3-fe71-48e1-bb67-f54f5112edf6',
   '41919b8c-770e-4b96-81be-05cc07261687',
   'Tennis Court A', 'Tennis', 'Hard court with professional lighting',
   150, 4, 60, 'active', 'approved', 'court'),

  ('ad583fad-6fd3-454b-9d13-6ab1a0c8e3f1',
   '41919b8c-770e-4b96-81be-05cc07261687',
   'Padel Court 1', 'Padel', 'Glass-walled indoor padel court',
   200, 4, 60, 'active', 'approved', 'court')
ON CONFLICT (id) DO NOTHING;

-- ── Original DSC bookings (referenced in booking_payments by 20260517203315) ──
INSERT INTO bookings (id, customer_id, listing_id, location_id, booking_date, start_time, end_time, players, base_price, total, payment_method, payment_status, booking_status, origin, commission_rate, commission_amount)
VALUES
  ('525cecf8-c7fa-4289-9678-d619f7f355d5',
   'bd7ddc47-8aab-40e0-a9ed-f7f9f2e146e6',
   'fb02df14-d7c8-436e-99d0-f933b0c60255',
   '41919b8c-770e-4b96-81be-05cc07261687',
   '2026-05-06', '18:00', '19:30', 10, 250, 250,
   'cash', 'paid', 'completed', 'bookboll_app', 20, 50),

  ('759e5736-c9d3-42be-ad42-e075013b796f',
   'a663e10f-b6ee-4941-8b1f-3000d4541d6e',
   '7f085de3-fe71-48e1-bb67-f54f5112edf6',
   '41919b8c-770e-4b96-81be-05cc07261687',
   '2026-05-07', '08:00', '09:00', 2, 150, 150,
   'cash', 'deposit_paid', 'confirmed', 'bookboll_web', 20, 30),

  ('7b1ec398-87aa-4760-a828-2cd60025525d',
   '34bdd0ff-a5e5-49ea-a75d-f729a2006779',
   'ad583fad-6fd3-454b-9d13-6ab1a0c8e3f1',
   '41919b8c-770e-4b96-81be-05cc07261687',
   '2026-05-07', '20:00', '21:00', 4, 200, 200,
   'cash', 'deposit_paid', 'confirmed', 'bookboll_app', 20, 40)
ON CONFLICT (id) DO NOTHING;

-- ── Class programs (referenced by class_subscriptions in 20260520144207+) ────
-- program_id for FK: class_subscriptions.program_id → class_programs.id
INSERT INTO class_programs (id, name, sport_category, monthly_price, commission_percentage, status)
VALUES
  ('f1000000-0000-0000-0000-000000000001', 'Cairo Football Fundamentals', 'Football', 300,  20, 'active'),
  ('f1000000-0000-0000-0000-000000000002', 'Elite Football Academy',      'Football', 650,  20, 'active'),
  ('f1000000-0000-0000-0000-000000000003', 'Padel Beginners',             'Padel',    500,  20, 'active'),
  ('f1000000-0000-0000-0000-000000000004', 'Advanced Padel Clinic',       'Padel',    900,  20, 'active'),
  ('f1000000-0000-0000-0000-000000000005', 'Junior Football Stars',       'Football', 450,  20, 'active')
ON CONFLICT (id) DO NOTHING;

-- ── Class subscriptions (referenced as subscription_id in booking_payments) ───
INSERT INTO class_subscriptions (id, program_id, customer_name, customer_email, status, monthly_amount, provider_payout, platform_fee)
VALUES
  -- Cairo Football Fundamentals (CFA) — AED 300 → 240 payout / 60 fee
  ('715f23f0-858b-4e34-b73b-c2b1188687e5', 'f1000000-0000-0000-0000-000000000001', 'Amr Saleh',      'amr@mail.com',     'active', 300, 240, 60),
  ('c6525438-c88c-4aa6-ba73-275ffb07620d', 'f1000000-0000-0000-0000-000000000001', 'Karim Hassan',   'karim@mail.com',   'active', 300, 240, 60),
  ('e4ee55fb-8697-4961-8043-f89ec24f52fa', 'f1000000-0000-0000-0000-000000000001', 'Youssef Nabil',  'youssef@mail.com', 'active', 300, 240, 60),
  ('751d81e9-cc82-43be-bde8-f0f0bc06eae7', 'f1000000-0000-0000-0000-000000000001', 'Mostafa Ibrahim','mostafa@mail.com', 'active', 300, 240, 60),
  -- Elite Football Academy (DSC) — AED 650 → 520 payout / 130 fee
  ('ab8ec89c-e4c2-476e-9aac-1516a2061a5b', 'f1000000-0000-0000-0000-000000000002', 'Daniel Kim',     'daniel@mail.com',  'active', 650, 520, 130),
  ('530b7358-f51d-41a6-af23-f7a20a91aaec', 'f1000000-0000-0000-0000-000000000002', 'Faisal Al Zahrani','faisal@mail.com','active', 650, 520, 130),
  ('910916c0-0299-4f3b-9813-edaab79fd85b', 'f1000000-0000-0000-0000-000000000002', 'Liam Torres',    'liam@mail.com',    'active', 650, 520, 130),
  ('350af3d7-cb9e-4b48-8bd9-e1ab6a7465f6', 'f1000000-0000-0000-0000-000000000002', 'Ryan Murphy',    'ryan@mail.com',    'active', 650, 520, 130),
  ('99518009-a469-4277-a6c0-0e3d9a13225f', 'f1000000-0000-0000-0000-000000000002', 'Omar Al Rashid', 'omar.r@mail.com',  'active', 650, 520, 130),
  -- Padel Beginners (DSC) — AED 500 → 400 payout / 100 fee
  ('d65408cd-6f41-4a23-949e-89b6cc6c3164', 'f1000000-0000-0000-0000-000000000003', 'Sofia Andrade',  'sofia@mail.com',   'active', 500, 400, 100),
  ('1a0f80c6-a3e7-462a-895b-7346c0d96d4a', 'f1000000-0000-0000-0000-000000000003', 'Marcus Johansson','marcus@mail.com', 'active', 500, 400, 100),
  ('6646df0f-e3af-4b9e-8645-487bb8ede688', 'f1000000-0000-0000-0000-000000000003', 'Aisha Al Farsi', 'aisha.f@mail.com', 'active', 500, 400, 100),
  -- Advanced Padel Clinic (DSC) — AED 900 → 720 payout / 180 fee
  ('2b95465d-bf2e-4723-98e3-c8ff3831d99a', 'f1000000-0000-0000-0000-000000000004', 'Layla Al Amri',  'layla.a@mail.com', 'active', 900, 720, 180),
  ('70551ab6-b780-4403-9ccd-eb8be0bbe029', 'f1000000-0000-0000-0000-000000000004', 'Pierre Dubois',  'pierre@mail.com',  'active', 900, 720, 180),
  -- Junior Football Stars (DSC) — AED 450 → 360 payout / 90 fee
  ('cdbf9051-5214-415d-a1cf-10ce06f6f85d', 'f1000000-0000-0000-0000-000000000005', 'Fatima Al Shamsi','fatima.s@mail.com','active',450, 360, 90),
  ('6a6bc907-23b2-40c8-be7a-fd32539ab7f4', 'f1000000-0000-0000-0000-000000000005', 'Jack Williams',  'jack@mail.com',    'active', 450, 360, 90)
ON CONFLICT (id) DO NOTHING;

-- ── Provider wallets (needed for wallet_transaction backfill in 20260521015833) ─
-- owner_user_id has no UNIQUE constraint (added via ALTER TABLE), so use WHERE NOT EXISTS
INSERT INTO provider_wallets (owner_user_id, available_balance, pending_balance, lifetime_earnings, currency)
SELECT '5ea6f1c5-0d55-4d50-a9a5-24003caadb52', 0, 0, 0, 'AED'
WHERE NOT EXISTS (SELECT 1 FROM provider_wallets WHERE owner_user_id = '5ea6f1c5-0d55-4d50-a9a5-24003caadb52');

INSERT INTO provider_wallets (owner_user_id, available_balance, pending_balance, lifetime_earnings, currency)
SELECT '8d7f528d-0fd7-48d5-9f1a-8f19dc80c392', 0, 0, 0, 'AED'
WHERE NOT EXISTS (SELECT 1 FROM provider_wallets WHERE owner_user_id = '8d7f528d-0fd7-48d5-9f1a-8f19dc80c392');
