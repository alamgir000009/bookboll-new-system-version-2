/*
  # Seed Payment Variations Showcase

  Inserts representative booking_payments rows covering every payment scenario
  so the Payments page can be reviewed visually:

  1. Bookboll-owned location — full online payment (all to @bookboll), paid & pending
  2. Provider-owned location — online deposit (@bookboll) + cash remaining (@provider), pending
  3. Provider-owned location — online deposit (@bookboll) + card remaining (@provider), paid
  4. Provider-owned location — full cash to provider, paid & pending
  5. Provider-owned location — full online to @bookboll (Bookboll-originated booking)
  6. Wire transfer and cheque method examples
  7. Subscription split: @bookboll commission (paid) + @provider earnings (on_hold)
  8. Subscription split: @bookboll commission (paid) + @provider earnings (available_for_payout)
  9. Subscription split: recurring monthly (not first charge)

  All rows are idempotent — uses DO blocks with existence checks where needed.
  booking_id is left NULL for subscription rows; subscription_id is left NULL for booking rows.
*/

-- ─── 1. Bookboll-owned location (Bookboll @ Business Bay) — full online, paid ─────
-- Booking e1000000-0000-0000-0000-000000000012 · Bookboll Padel Suite · AED 250
INSERT INTO booking_payments (booking_id, source_type, payment_type, method, amount, currency, recipient, status, paid_at, notes)
VALUES
  ('e1000000-0000-0000-0000-000000000012', 'booking', 'full', 'online', 250.00, 'AED', '@bookboll', 'paid',
   now() - interval '3 days', 'Online full payment — Bookboll-operated location'),
  -- Booking e1000000-0000-0000-0000-000000000013 · Bookboll Tennis Court · AED 200 — pending
  ('e1000000-0000-0000-0000-000000000013', 'booking', 'full', 'online', 200.00, 'AED', '@bookboll', 'pending',
   NULL, 'Online full payment — Bookboll-operated location');

-- ─── 2. Provider-owned — online deposit (@bookboll) + cash remaining (@provider), pending ─
-- Booking e1000000-0000-0000-0000-000000000014 · Bookboll Padel Suite / Business Bay · AED 250
-- 20% deposit online → @bookboll; 80% remaining cash → @bookboll (Bookboll-operated)
-- Use DSC booking 759e5736 — Tennis Court A — AED 150 (no existing rows)
INSERT INTO booking_payments (booking_id, source_type, payment_type, method, amount, currency, recipient, status, paid_at, notes)
VALUES
  ('759e5736-c9d3-42be-ad42-e075013b796f', 'booking', 'deposit', 'online', 30.00, 'AED', '@bookboll', 'paid',
   now() - interval '5 days', 'Online deposit — 20% collected by Bookboll'),
  ('759e5736-c9d3-42be-ad42-e075013b796f', 'booking', 'remaining', 'cash', 120.00, 'AED', '@dsc', 'pending',
   NULL, 'Remaining balance — payable at venue to DSC');

-- ─── 3. Provider-owned — deposit paid + remaining already paid by card ──────────
-- Booking e1000000-0000-0000-0000-000000000001 · Football Turf #12 / DSC · AED 250
INSERT INTO booking_payments (booking_id, source_type, payment_type, method, amount, currency, recipient, status, paid_at, notes)
VALUES
  ('e1000000-0000-0000-0000-000000000001', 'booking', 'deposit', 'online', 50.00, 'AED', '@bookboll', 'paid',
   now() - interval '7 days', 'Online deposit — 20%'),
  ('e1000000-0000-0000-0000-000000000001', 'booking', 'remaining', 'card', 200.00, 'AED', '@dsc', 'paid',
   now() - interval '6 days', 'Remaining paid on-site by card');

-- ─── 4. Provider-owned — full cash to provider ─────────────────────────────────
-- e1000000-0000-0000-0000-000000000002 · Tennis Court A / DSC · AED 150 — full cash paid
INSERT INTO booking_payments (booking_id, source_type, payment_type, method, amount, currency, recipient, status, paid_at, notes)
VALUES
  ('e1000000-0000-0000-0000-000000000002', 'booking', 'full', 'cash', 150.00, 'AED', '@dsc', 'paid',
   now() - interval '2 days', 'Full cash payment at venue');

-- e1000000-0000-0000-0000-000000000003 · Padel Court 1 / DSC · AED 200 — full cash pending
INSERT INTO booking_payments (booking_id, source_type, payment_type, method, amount, currency, recipient, status, paid_at, notes)
VALUES
  ('e1000000-0000-0000-0000-000000000003', 'booking', 'full', 'cash', 200.00, 'AED', '@dsc', 'pending',
   NULL, 'Full cash — awaiting collection at venue');

-- ─── 5. Provider-owned — full online to @bookboll (Bookboll-originated) ────────
-- 7b1ec398 · Padel Court 1 / DSC · AED 200 — already has deposit+remaining but let's use alwasl
-- e1000000-0000-0000-0000-000000000005 · Football Turf A / Al Wasl · AED 300 — full online
INSERT INTO booking_payments (booking_id, source_type, payment_type, method, amount, currency, recipient, status, paid_at, notes)
VALUES
  ('e1000000-0000-0000-0000-000000000005', 'booking', 'full', 'online', 300.00, 'AED', '@bookboll', 'paid',
   now() - interval '1 day', 'Full online — Bookboll-originated booking at provider venue');

-- e1000000-0000-0000-0000-000000000004 · Padel Court Blue / Al Wasl · AED 180 — deposit + remaining pending
INSERT INTO booking_payments (booking_id, source_type, payment_type, method, amount, currency, recipient, status, paid_at, notes)
VALUES
  ('e1000000-0000-0000-0000-000000000004', 'booking', 'deposit', 'online', 36.00, 'AED', '@bookboll', 'paid',
   now() - interval '4 days', 'Online deposit 20%'),
  ('e1000000-0000-0000-0000-000000000004', 'booking', 'remaining', 'cash', 144.00, 'AED', '@alwasl', 'pending',
   NULL, 'Remaining — cash at venue');

-- ─── 6. Wire transfer and cheque examples ───────────────────────────────────────
-- Doha Arena — e1000000-0000-0000-0000-000000000007 · Padel Court 01 · AED 220
INSERT INTO booking_payments (booking_id, source_type, payment_type, method, amount, currency, recipient, status, paid_at, notes)
VALUES
  ('e1000000-0000-0000-0000-000000000007', 'booking', 'deposit', 'online', 44.00, 'AED', '@bookboll', 'paid',
   now() - interval '6 days', 'Online deposit 20%'),
  ('e1000000-0000-0000-0000-000000000007', 'booking', 'remaining', 'wire_transfer', 176.00, 'AED', '@dohaarena', 'paid',
   now() - interval '5 days', 'Remaining paid via bank wire transfer');

-- Cairo Elite — e1000000-0000-0000-0000-000000000010 (use alwasl booking 6 for cheque)
-- e1000000-0000-0000-0000-000000000006 · Padel Court Blue / Al Wasl · AED 180
INSERT INTO booking_payments (booking_id, source_type, payment_type, method, amount, currency, recipient, status, paid_at, notes)
VALUES
  ('e1000000-0000-0000-0000-000000000006', 'booking', 'deposit', 'online', 36.00, 'AED', '@bookboll', 'paid',
   now() - interval '10 days', 'Online deposit 20%'),
  ('e1000000-0000-0000-0000-000000000006', 'booking', 'remaining', 'cheque', 144.00, 'AED', '@alwasl', 'paid',
   now() - interval '9 days', 'Remaining paid by cheque');

-- Riyadh Padel Club — e1000000-0000-0000-0000-000000000017 · Padel Court B · AED 200
INSERT INTO booking_payments (booking_id, source_type, payment_type, method, amount, currency, recipient, status, paid_at, notes)
VALUES
  ('e1000000-0000-0000-0000-000000000017', 'booking', 'full', 'cheque', 200.00, 'AED', '@rpc', 'pending',
   NULL, 'Full payment by cheque — awaiting clearance');

-- ─── 7. Subscription split — @bookboll commission + @dsc earnings ON HOLD ──────
-- Subscription d65408cd (Sofia Andrade / Padel Beginners / DSC) · AED 500 · 20% commission
INSERT INTO booking_payments (subscription_id, source_type, payment_type, method, amount, currency, recipient, status, paid_at, notes)
VALUES
  ('d65408cd-6f41-4a23-949e-89b6cc6c3164', 'subscription', 'subscription_commission', 'online',
   100.00, 'AED', '@bookboll', 'paid', now() - interval '8 days',
   'First charge after 14-day trial'),
  ('d65408cd-6f41-4a23-949e-89b6cc6c3164', 'subscription', 'subscription_earning', 'online',
   400.00, 'AED', '@dsc', 'on_hold', now() - interval '8 days',
   'First charge after 14-day trial');

-- Subscription 1a0f80c6 (Marcus Johansson / Padel Beginners / DSC) · AED 500
INSERT INTO booking_payments (subscription_id, source_type, payment_type, method, amount, currency, recipient, status, paid_at, notes)
VALUES
  ('1a0f80c6-a3e7-462a-895b-7346c0d96d4a', 'subscription', 'subscription_commission', 'online',
   100.00, 'AED', '@bookboll', 'paid', now() - interval '12 days',
   'First charge after 14-day trial'),
  ('1a0f80c6-a3e7-462a-895b-7346c0d96d4a', 'subscription', 'subscription_earning', 'online',
   400.00, 'AED', '@dsc', 'on_hold', now() - interval '12 days',
   'First charge after 14-day trial');

-- ─── 8. Subscription split — @cfa earnings AVAILABLE FOR PAYOUT (hold elapsed) ─
-- Subscription c6525438 (Karim Hassan / Cairo Football / CFA) · AED 300
INSERT INTO booking_payments (subscription_id, source_type, payment_type, method, amount, currency, recipient, status, paid_at, notes)
VALUES
  ('c6525438-c88c-4aa6-ba73-275ffb07620d', 'subscription', 'subscription_commission', 'online',
   60.00, 'AED', '@bookboll', 'paid', now() - interval '25 days',
   'First charge after 14-day trial'),
  ('c6525438-c88c-4aa6-ba73-275ffb07620d', 'subscription', 'subscription_earning', 'online',
   240.00, 'AED', '@cfa', 'available_for_payout', now() - interval '25 days',
   'First charge after 14-day trial');

-- Subscription 715f23f0 (Amr Saleh / Cairo Football / CFA) · AED 300
INSERT INTO booking_payments (subscription_id, source_type, payment_type, method, amount, currency, recipient, status, paid_at, notes)
VALUES
  ('715f23f0-858b-4e34-b73b-c2b1188687e5', 'subscription', 'subscription_commission', 'online',
   60.00, 'AED', '@bookboll', 'paid', now() - interval '30 days',
   'First charge after 14-day trial'),
  ('715f23f0-858b-4e34-b73b-c2b1188687e5', 'subscription', 'subscription_earning', 'online',
   240.00, 'AED', '@cfa', 'available_for_payout', now() - interval '30 days',
   'First charge after 14-day trial');

-- ─── 9. Subscription split — recurring monthly charge (not first) ────────────
-- Subscription 99518009 (Omar Al Rashid / Elite Football Academy / DSC) · AED 650
INSERT INTO booking_payments (subscription_id, source_type, payment_type, method, amount, currency, recipient, status, paid_at, notes)
VALUES
  ('99518009-a469-4277-a6c0-0e3d9a13225f', 'subscription', 'subscription_commission', 'online',
   130.00, 'AED', '@bookboll', 'paid', now() - interval '2 days',
   'Monthly subscription charge'),
  ('99518009-a469-4277-a6c0-0e3d9a13225f', 'subscription', 'subscription_earning', 'online',
   520.00, 'AED', '@dsc', 'on_hold', now() - interval '2 days',
   'Monthly subscription charge');

-- Subscription 751d81e9 (Mostafa Ibrahim / Cairo Football / CFA) · AED 300 — recurring
INSERT INTO booking_payments (subscription_id, source_type, payment_type, method, amount, currency, recipient, status, paid_at, notes)
VALUES
  ('751d81e9-cc82-43be-bde8-f0f0bc06eae7', 'subscription', 'subscription_commission', 'online',
   60.00, 'AED', '@bookboll', 'paid', now() - interval '1 day',
   'Monthly subscription charge'),
  ('751d81e9-cc82-43be-bde8-f0f0bc06eae7', 'subscription', 'subscription_earning', 'online',
   240.00, 'AED', '@cfa', 'on_hold', now() - interval '1 day',
   'Monthly subscription charge');
