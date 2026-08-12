-- Roles & permissions restructure (Phase 2 · checkpoint 1)
-- ---------------------------------------------------------------------------
-- 1. Per-user permission overrides + scope assignments on admin_users.
-- 2. Collapse the three legacy employee tiers into a single flexible `staff`
--    role, carrying their old powers as per-user grants.
--
-- New role model:
--   Platform : super_admin, country_admin, platform_staff (new)
--   Provider : provider_admin (Owner), facility_admin (Manager), staff (new)
--
-- custom_permissions shape: { "grant": ["cap", ...], "revoke": ["cap", ...] }
-- Capability keys match src/lib/permissions.js CAP.*.
-- Idempotent: safe to re-run.

-- 1) Columns ----------------------------------------------------------------
ALTER TABLE admin_users
  ADD COLUMN IF NOT EXISTS custom_permissions   jsonb  NOT NULL DEFAULT '{}'::jsonb,
  ADD COLUMN IF NOT EXISTS assigned_location_ids uuid[] NOT NULL DEFAULT '{}',
  ADD COLUMN IF NOT EXISTS assigned_listing_ids  uuid[] NOT NULL DEFAULT '{}',
  ADD COLUMN IF NOT EXISTS assigned_program_ids  uuid[] NOT NULL DEFAULT '{}';

COMMENT ON COLUMN admin_users.custom_permissions IS
  'Per-user capability overrides layered on the role base: {"grant":[...],"revoke":[...]}';
COMMENT ON COLUMN admin_users.assigned_location_ids IS
  'If non-empty, the user is scoped to only these locations (else all in their business/country).';
COMMENT ON COLUMN admin_users.assigned_listing_ids IS
  'Optional finer scope: restrict the user to these specific listings.';
COMMENT ON COLUMN admin_users.assigned_program_ids IS
  'Optional finer scope: restrict the user to these specific programs.';

-- 2) Collapse legacy employee roles into `staff` + grants --------------------
-- Senior employee → staff with bookings + payments
UPDATE admin_users
   SET role = 'staff',
       custom_permissions = jsonb_build_object('grant', jsonb_build_array('manage_bookings','accept_payments'))
 WHERE role = 'senior_employee';

-- Junior employee → staff with bookings
UPDATE admin_users
   SET role = 'staff',
       custom_permissions = jsonb_build_object('grant', jsonb_build_array('manage_bookings'))
 WHERE role = 'junior_employee';

-- Guard → staff, view-only (base staff capability)
UPDATE admin_users
   SET role = 'staff',
       custom_permissions = '{}'::jsonb
 WHERE role = 'guard';
