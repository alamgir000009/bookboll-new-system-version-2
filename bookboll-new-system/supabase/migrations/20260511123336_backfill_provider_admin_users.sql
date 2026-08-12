/*
  # Backfill provider admin users for orphan businesses

  1. Purpose
    - Every `facility_providers` row should have at least one linked `admin_users` row
      whose `role = 'provider_admin'` and `provider_id` references that business.
    - Historically businesses could be created without a user, producing orphans.

  2. What this migration does
    - Finds every `facility_providers` row that currently has NO linked `admin_users`
    - Inserts one `admin_users` row per orphan business with:
      - full_name = contact_person (fallback: business_name)
      - email     = provider.email
      - role      = 'provider_admin'
      - provider_id = the business's id
      - country_id  = provider.country_id
      - status      = 'active'

  3. Safety notes
    - The INSERT is gated by a NOT EXISTS check, so re-running the migration is a no-op.
    - No data is deleted or modified in existing `admin_users` rows.
    - Emails that collide with existing users are skipped to avoid unique-constraint errors.
*/

INSERT INTO admin_users (full_name, email, role, provider_id, country_id, status)
SELECT
  COALESCE(NULLIF(TRIM(fp.contact_person), ''), fp.business_name) AS full_name,
  fp.email,
  'provider_admin' AS role,
  fp.id AS provider_id,
  fp.country_id,
  'active' AS status
FROM facility_providers fp
WHERE NOT EXISTS (
  SELECT 1 FROM admin_users au WHERE au.provider_id = fp.id
)
AND fp.email IS NOT NULL
AND fp.email <> ''
AND NOT EXISTS (
  SELECT 1 FROM admin_users au2 WHERE lower(au2.email) = lower(fp.email)
);
