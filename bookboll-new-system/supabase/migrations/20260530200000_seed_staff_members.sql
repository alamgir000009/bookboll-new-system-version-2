-- ── Staff member seeds ──────────────────────────────────────────────────────
-- Adds realistic facility admins, senior employees, junior employees and guards
-- under each provider business. These demonstrate the hierarchical team structure
-- visible in the Team & Users page.
--
-- owner_user_id links each staff member to their provider (business owner).
-- username is set to the provider's slug so the staff share the brand identity.
-- country_id is left NULL for staff — the app derives it from their provider.
--
-- Location IDs used:
--   41919b8c  DSC Main Courts          (owner: Ahmed / DSC)
--   513305f5  DSC Marina               (owner: Ahmed / DSC)
--   b1000000-...-1  Al Wasl Abu Dhabi  (owner: Fatima / alwasl)
--   b1000000-...-2  Doha Arena WestBay (owner: Yusuf  / dohaarena)
--   b1000000-...-5  Riyadh Padel Club  (owner: Khalid / rpc)
-- ─────────────────────────────────────────────────────────────────────────────

-- Staff members do NOT get a username — that column is only for provider_admin
-- brand-identity login. Staff authenticate via their own email. Setting username
-- to NULL avoids violating the admin_users_username_unique constraint.

INSERT INTO admin_users (id, full_name, email, role, status, owner_user_id, location_id)
VALUES

  -- ── Dubai Sports City (DSC) — Ahmed Al Mansouri ──────────────────────────
  ('e2000000-0000-0000-0000-000000000001',
   'James Carter', 'james.carter@dsc.ae',
   'facility_admin', 'active',
   '5ea6f1c5-0d55-4d50-a9a5-24003caadb52',  -- Ahmed (DSC)
   '41919b8c-770e-4b96-81be-05cc07261687'),  -- Main Courts

  ('e2000000-0000-0000-0000-000000000002',
   'Priya Sharma', 'priya.sharma@dsc.ae',
   'senior_employee', 'active',
   '5ea6f1c5-0d55-4d50-a9a5-24003caadb52',
   '41919b8c-770e-4b96-81be-05cc07261687'),  -- Main Courts

  ('e2000000-0000-0000-0000-000000000003',
   'Carlos Gomes', 'carlos.gomes@dsc.ae',
   'senior_employee', 'active',
   '5ea6f1c5-0d55-4d50-a9a5-24003caadb52',
   '513305f5-b266-4a7c-b411-9d5369a2ca32'),  -- Marina

  ('e2000000-0000-0000-0000-000000000004',
   'Wei Chen', 'wei.chen@dsc.ae',
   'junior_employee', 'active',
   '5ea6f1c5-0d55-4d50-a9a5-24003caadb52',
   '41919b8c-770e-4b96-81be-05cc07261687'),  -- Main Courts

  ('e2000000-0000-0000-0000-000000000005',
   'Rami Khalil', 'rami.khalil@dsc.ae',
   'guard', 'active',
   '5ea6f1c5-0d55-4d50-a9a5-24003caadb52',
   '513305f5-b266-4a7c-b411-9d5369a2ca32'),  -- Marina

  -- ── Al Wasl Sports Hub — Fatima Al Rashidi ────────────────────────────────
  ('e2000000-0000-0000-0000-000000000006',
   'Laila Hassan', 'laila.hassan@alwasl.ae',
   'facility_admin', 'active',
   'a1000000-0000-0000-0000-000000000001',  -- Fatima (Al Wasl)
   'b1000000-0000-0000-0000-000000000001'), -- Abu Dhabi hub

  ('e2000000-0000-0000-0000-000000000007',
   'Rania Malik', 'rania.malik@alwasl.ae',
   'senior_employee', 'active',
   'a1000000-0000-0000-0000-000000000001',
   'b1000000-0000-0000-0000-000000000001'),

  ('e2000000-0000-0000-0000-000000000008',
   'Tariq Omar', 'tariq.omar@alwasl.ae',
   'junior_employee', 'active',
   'a1000000-0000-0000-0000-000000000001',
   'b1000000-0000-0000-0000-000000000001'),

  -- ── Doha Arena — Yusuf Al Farsi ───────────────────────────────────────────
  ('e2000000-0000-0000-0000-000000000009',
   'Hamad Al Kuwari', 'hamad@dohaarena.qa',
   'facility_admin', 'active',
   'a1000000-0000-0000-0000-000000000002',  -- Yusuf (Doha Arena)
   'b1000000-0000-0000-0000-000000000002'), -- West Bay

  ('e2000000-0000-0000-0000-000000000010',
   'Salma Jaber', 'salma.jaber@dohaarena.qa',
   'senior_employee', 'active',
   'a1000000-0000-0000-0000-000000000002',
   'b1000000-0000-0000-0000-000000000002'),

  ('e2000000-0000-0000-0000-000000000011',
   'Ali Al Mohannadi', 'ali@dohaarena.qa',
   'junior_employee', 'active',
   'a1000000-0000-0000-0000-000000000002',
   'b1000000-0000-0000-0000-000000000002'),

  -- ── Cairo Elite Courts — Nadia Kamel ─────────────────────────────────────
  ('e2000000-0000-0000-0000-000000000012',
   'Tarek Samir', 'tarek.samir@cairoelite.eg',
   'facility_admin', 'active',
   'a1000000-0000-0000-0000-000000000003',  -- Nadia (Cairo Elite)
   NULL),  -- covers all locations

  ('e2000000-0000-0000-0000-000000000013',
   'Heba Youssef', 'heba.youssef@cairoelite.eg',
   'senior_employee', 'active',
   'a1000000-0000-0000-0000-000000000003',
   NULL),

  -- ── Cairo Football Arena — Mahmoud Hassan ────────────────────────────────
  ('e2000000-0000-0000-0000-000000000014',
   'Mohamed Farouk', 'mohamed.farouk@cfa.eg',
   'facility_admin', 'active',
   '8d7f528d-0fd7-48d5-9f1a-8f19dc80c392',  -- Mahmoud (CFA)
   NULL),

  ('e2000000-0000-0000-0000-000000000015',
   'Asmaa Kamal', 'asmaa.kamal@cfa.eg',
   'senior_employee', 'active',
   '8d7f528d-0fd7-48d5-9f1a-8f19dc80c392',
   NULL),

  ('e2000000-0000-0000-0000-000000000016',
   'Ibrahim Nour', 'ibrahim.nour@cfa.eg',
   'junior_employee', 'active',
   '8d7f528d-0fd7-48d5-9f1a-8f19dc80c392',
   NULL),

  -- ── Riyadh Padel Club — Khalid Al Saud ───────────────────────────────────
  ('e2000000-0000-0000-0000-000000000017',
   'Abdullah Al Qahtani', 'abdullah@rpc.sa',
   'facility_admin', 'active',
   '21f623cf-de7d-44a7-a638-258c9c284173',  -- Khalid (RPC)
   'b1000000-0000-0000-0000-000000000005'), -- Al Olaya

  ('e2000000-0000-0000-0000-000000000018',
   'Saud Al Ghamdi', 'saud.ghamdi@rpc.sa',
   'senior_employee', 'active',
   '21f623cf-de7d-44a7-a638-258c9c284173',
   'b1000000-0000-0000-0000-000000000005'),

  ('e2000000-0000-0000-0000-000000000019',
   'Nora Al Zahrani', 'nora.zahrani@rpc.sa',
   'junior_employee', 'active',
   '21f623cf-de7d-44a7-a638-258c9c284173',
   'b1000000-0000-0000-0000-000000000005')

ON CONFLICT (id) DO NOTHING;
