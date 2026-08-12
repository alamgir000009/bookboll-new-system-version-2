/*
  # Seed fresh subscription payment records

  Adds realistic recent subscription charges for active subscriptions across
  both Cairo Football Arena and Dubai Sports City. Covers:
    - booking_payments rows (commission + earning, both 'paid')
    - wallet_transactions rows (mix of on_hold and completed)
    - provider_wallets balance updates

  Dates span the last 30 days so the data looks live.
*/

-- ── booking_payments: commission + earning pairs ──────────────────────────────

INSERT INTO booking_payments (booking_id, subscription_id, source_type, payment_type, method, amount, currency, recipient, status, paid_at, notes) VALUES

-- Cairo Football Arena — Cairo Football Fundamentals (300/mo, fee 60, payout 240)
(NULL, '715f23f0-858b-4e34-b73b-c2b1188687e5', 'subscription', 'subscription_commission', 'online', 60,  'AED', '@bookboll',          'paid', now() - interval '28 days', 'Monthly subscription charge'),
(NULL, '715f23f0-858b-4e34-b73b-c2b1188687e5', 'subscription', 'subscription_earning',    'online', 240, 'AED', '@mahmoud_hassan',     'paid', now() - interval '28 days', 'Monthly subscription charge'),

(NULL, 'c6525438-c88c-4aa6-ba73-275ffb07620d', 'subscription', 'subscription_commission', 'online', 60,  'AED', '@bookboll',          'paid', now() - interval '21 days', 'Monthly subscription charge'),
(NULL, 'c6525438-c88c-4aa6-ba73-275ffb07620d', 'subscription', 'subscription_earning',    'online', 240, 'AED', '@mahmoud_hassan',     'paid', now() - interval '21 days', 'Monthly subscription charge'),

(NULL, 'e4ee55fb-8697-4961-8043-f89ec24f52fa', 'subscription', 'subscription_commission', 'online', 60,  'AED', '@bookboll',          'paid', now() - interval '14 days', 'First charge after 14-day trial'),
(NULL, 'e4ee55fb-8697-4961-8043-f89ec24f52fa', 'subscription', 'subscription_earning',    'online', 240, 'AED', '@mahmoud_hassan',     'paid', now() - interval '14 days', 'First charge after 14-day trial'),

(NULL, '751d81e9-cc82-43be-bde8-f0f0bc06eae7', 'subscription', 'subscription_commission', 'online', 60,  'AED', '@bookboll',          'paid', now() - interval '5 days',  'Monthly subscription charge'),
(NULL, '751d81e9-cc82-43be-bde8-f0f0bc06eae7', 'subscription', 'subscription_earning',    'online', 240, 'AED', '@mahmoud_hassan',     'paid', now() - interval '5 days',  'Monthly subscription charge'),

-- Dubai Sports City — Elite Football Academy (650/mo, fee 130, payout 520)
(NULL, 'ab8ec89c-e4c2-476e-9aac-1516a2061a5b', 'subscription', 'subscription_commission', 'online', 130, 'AED', '@bookboll',          'paid', now() - interval '25 days', 'Monthly subscription charge'),
(NULL, 'ab8ec89c-e4c2-476e-9aac-1516a2061a5b', 'subscription', 'subscription_earning',    'online', 520, 'AED', '@ahmed_almansouri',   'paid', now() - interval '25 days', 'Monthly subscription charge'),

(NULL, '530b7358-f51d-41a6-af23-f7a20a91aaec', 'subscription', 'subscription_commission', 'online', 130, 'AED', '@bookboll',          'paid', now() - interval '18 days', 'Monthly subscription charge'),
(NULL, '530b7358-f51d-41a6-af23-f7a20a91aaec', 'subscription', 'subscription_earning',    'online', 520, 'AED', '@ahmed_almansouri',   'paid', now() - interval '18 days', 'Monthly subscription charge'),

(NULL, '910916c0-0299-4f3b-9813-edaab79fd85b', 'subscription', 'subscription_commission', 'online', 130, 'AED', '@bookboll',          'paid', now() - interval '10 days', 'First charge after 14-day trial'),
(NULL, '910916c0-0299-4f3b-9813-edaab79fd85b', 'subscription', 'subscription_earning',    'online', 520, 'AED', '@ahmed_almansouri',   'paid', now() - interval '10 days', 'First charge after 14-day trial'),

(NULL, '350af3d7-cb9e-4b48-8bd9-e1ab6a7465f6', 'subscription', 'subscription_commission', 'online', 130, 'AED', '@bookboll',          'paid', now() - interval '3 days',  'Monthly subscription charge'),
(NULL, '350af3d7-cb9e-4b48-8bd9-e1ab6a7465f6', 'subscription', 'subscription_earning',    'online', 520, 'AED', '@ahmed_almansouri',   'paid', now() - interval '3 days',  'Monthly subscription charge'),

-- Dubai Sports City — Padel Beginners Course (500/mo, fee 100, payout 400)
(NULL, '6646df0f-e3af-4b9e-8645-487bb8ede688', 'subscription', 'subscription_commission', 'online', 100, 'AED', '@bookboll',          'paid', now() - interval '22 days', 'First charge after 14-day trial'),
(NULL, '6646df0f-e3af-4b9e-8645-487bb8ede688', 'subscription', 'subscription_earning',    'online', 400, 'AED', '@ahmed_almansouri',   'paid', now() - interval '22 days', 'First charge after 14-day trial'),

(NULL, 'd65408cd-6f41-4a23-949e-89b6cc6c3164', 'subscription', 'subscription_commission', 'online', 100, 'AED', '@bookboll',          'paid', now() - interval '6 days',  'Monthly subscription charge'),
(NULL, 'd65408cd-6f41-4a23-949e-89b6cc6c3164', 'subscription', 'subscription_earning',    'online', 400, 'AED', '@ahmed_almansouri',   'paid', now() - interval '6 days',  'Monthly subscription charge'),

-- Dubai Sports City — Advanced Padel Clinic (900/mo, fee 180, payout 720)
(NULL, '2b95465d-bf2e-4723-98e3-c8ff3831d99a', 'subscription', 'subscription_commission', 'online', 180, 'AED', '@bookboll',          'paid', now() - interval '15 days', 'First charge after 14-day trial'),
(NULL, '2b95465d-bf2e-4723-98e3-c8ff3831d99a', 'subscription', 'subscription_earning',    'online', 720, 'AED', '@ahmed_almansouri',   'paid', now() - interval '15 days', 'First charge after 14-day trial'),

(NULL, '70551ab6-b780-4403-9ccd-eb8be0bbe029', 'subscription', 'subscription_commission', 'online', 180, 'AED', '@bookboll',          'paid', now() - interval '4 days',  'Monthly subscription charge'),
(NULL, '70551ab6-b780-4403-9ccd-eb8be0bbe029', 'subscription', 'subscription_earning',    'online', 720, 'AED', '@ahmed_almansouri',   'paid', now() - interval '4 days',  'Monthly subscription charge'),

-- Dubai Sports City — Junior Football Stars (450/mo, fee 90, payout 360)
(NULL, 'cdbf9051-5214-415d-a1cf-10ce06f6f85d', 'subscription', 'subscription_commission', 'online', 90,  'AED', '@bookboll',          'paid', now() - interval '12 days', 'Monthly subscription charge'),
(NULL, 'cdbf9051-5214-415d-a1cf-10ce06f6f85d', 'subscription', 'subscription_earning',    'online', 360, 'AED', '@ahmed_almansouri',   'paid', now() - interval '12 days', 'Monthly subscription charge'),

(NULL, '6a6bc907-23b2-40c8-be7a-fd32539ab7f4', 'subscription', 'subscription_commission', 'online', 90,  'AED', '@bookboll',          'paid', now() - interval '2 days',  'Monthly subscription charge'),
(NULL, '6a6bc907-23b2-40c8-be7a-fd32539ab7f4', 'subscription', 'subscription_earning',    'online', 360, 'AED', '@ahmed_almansouri',   'paid', now() - interval '2 days',  'Monthly subscription charge');


-- ── wallet_transactions ───────────────────────────────────────────────────────

INSERT INTO wallet_transactions (owner_user_id, type, direction, amount, provider_share, platform_fee, source_id, source_label, description, status, currency, available_on, hold_days_snapshot, released_at, created_at) VALUES

-- Cairo Football Arena (owner: 8d7f528d-0fd7-48d5-9f1a-8f19dc80c392)
('8d7f528d-0fd7-48d5-9f1a-8f19dc80c392', 'subscription_online', 'credit', 300, 240, 60, '715f23f0-858b-4e34-b73b-c2b1188687e5', 'Subscription · Amr Saleh',     'Monthly subscription charge',     'completed', 'AED', (now() - interval '21 days')::date, 7, now() - interval '21 days', now() - interval '28 days'),
('8d7f528d-0fd7-48d5-9f1a-8f19dc80c392', 'subscription_online', 'credit', 300, 240, 60, 'c6525438-c88c-4aa6-ba73-275ffb07620d', 'Subscription · Karim Hassan',   'Monthly subscription charge',     'completed', 'AED', (now() - interval '14 days')::date, 7, now() - interval '14 days', now() - interval '21 days'),
('8d7f528d-0fd7-48d5-9f1a-8f19dc80c392', 'subscription_online', 'credit', 300, 240, 60, 'e4ee55fb-8697-4961-8043-f89ec24f52fa', 'Subscription · Youssef Nabil',  'First charge after 14-day trial', 'completed', 'AED', (now() - interval '7 days')::date,  7, now() - interval '7 days',  now() - interval '14 days'),
('8d7f528d-0fd7-48d5-9f1a-8f19dc80c392', 'subscription_online', 'credit', 300, 240, 60, '751d81e9-cc82-43be-bde8-f0f0bc06eae7', 'Subscription · Mostafa Ibrahim','Monthly subscription charge',     'on_hold',   'AED', (now() + interval '2 days')::date,  7, NULL,                         now() - interval '5 days'),

-- Dubai Sports City (owner: 5ea6f1c5-0d55-4d50-a9a5-24003caadb52)
('5ea6f1c5-0d55-4d50-a9a5-24003caadb52', 'subscription_online', 'credit', 650, 520, 130, 'ab8ec89c-e4c2-476e-9aac-1516a2061a5b', 'Subscription · Daniel Kim',     'Monthly subscription charge',     'completed', 'AED', (now() - interval '18 days')::date, 7, now() - interval '18 days', now() - interval '25 days'),
('5ea6f1c5-0d55-4d50-a9a5-24003caadb52', 'subscription_online', 'credit', 650, 520, 130, '530b7358-f51d-41a6-af23-f7a20a91aaec', 'Subscription · Faisal Al Zahrani','Monthly subscription charge',   'completed', 'AED', (now() - interval '11 days')::date, 7, now() - interval '11 days', now() - interval '18 days'),
('5ea6f1c5-0d55-4d50-a9a5-24003caadb52', 'subscription_online', 'credit', 650, 520, 130, '910916c0-0299-4f3b-9813-edaab79fd85b', 'Subscription · Liam Torres',    'First charge after 14-day trial', 'completed', 'AED', (now() - interval '3 days')::date,  7, now() - interval '3 days',  now() - interval '10 days'),
('5ea6f1c5-0d55-4d50-a9a5-24003caadb52', 'subscription_online', 'credit', 650, 520, 130, '350af3d7-cb9e-4b48-8bd9-e1ab6a7465f6', 'Subscription · Ryan Murphy',    'Monthly subscription charge',     'on_hold',   'AED', (now() + interval '4 days')::date,  7, NULL,                         now() - interval '3 days'),
('5ea6f1c5-0d55-4d50-a9a5-24003caadb52', 'subscription_online', 'credit', 500, 400, 100, '6646df0f-e3af-4b9e-8645-487bb8ede688', 'Subscription · Aisha Al Farsi', 'First charge after 14-day trial', 'completed', 'AED', (now() - interval '15 days')::date, 7, now() - interval '15 days', now() - interval '22 days'),
('5ea6f1c5-0d55-4d50-a9a5-24003caadb52', 'subscription_online', 'credit', 500, 400, 100, 'd65408cd-6f41-4a23-949e-89b6cc6c3164', 'Subscription · Sofia Andrade',  'Monthly subscription charge',     'on_hold',   'AED', (now() + interval '1 day')::date,   7, NULL,                         now() - interval '6 days'),
('5ea6f1c5-0d55-4d50-a9a5-24003caadb52', 'subscription_online', 'credit', 900, 720, 180, '2b95465d-bf2e-4723-98e3-c8ff3831d99a', 'Subscription · Layla Al Amri',  'First charge after 14-day trial', 'completed', 'AED', (now() - interval '8 days')::date,  7, now() - interval '8 days',  now() - interval '15 days'),
('5ea6f1c5-0d55-4d50-a9a5-24003caadb52', 'subscription_online', 'credit', 900, 720, 180, '70551ab6-b780-4403-9ccd-eb8be0bbe029', 'Subscription · Pierre Dubois',  'Monthly subscription charge',     'on_hold',   'AED', (now() + interval '3 days')::date,  7, NULL,                         now() - interval '4 days'),
('5ea6f1c5-0d55-4d50-a9a5-24003caadb52', 'subscription_online', 'credit', 450, 360, 90,  'cdbf9051-5214-415d-a1cf-10ce06f6f85d', 'Subscription · Fatima Al Shamsi','Monthly subscription charge',    'completed', 'AED', (now() - interval '5 days')::date,  7, now() - interval '5 days',  now() - interval '12 days'),
('5ea6f1c5-0d55-4d50-a9a5-24003caadb52', 'subscription_online', 'credit', 450, 360, 90,  '6a6bc907-23b2-40c8-be7a-fd32539ab7f4', 'Subscription · Jack Williams',  'Monthly subscription charge',     'on_hold',   'AED', (now() + interval '5 days')::date,  7, NULL,                         now() - interval '2 days');


-- ── provider_wallets: recompute balances from scratch ────────────────────────

-- Cairo Football Arena: completed = 240*3 = 720, on_hold = 240*1 = 240
UPDATE provider_wallets
SET
  available_balance = 720,
  pending_balance   = 240,
  lifetime_earnings = 960,
  updated_at        = now()
WHERE owner_user_id = '8d7f528d-0fd7-48d5-9f1a-8f19dc80c392';

-- Dubai Sports City: completed = 520*3+400+720+360 = 3040, on_hold = 520+400+720+360 = 2000
UPDATE provider_wallets
SET
  available_balance = 3040,
  pending_balance   = 2000,
  lifetime_earnings = 5040,
  updated_at        = now()
WHERE owner_user_id = '5ea6f1c5-0d55-4d50-a9a5-24003caadb52';
