-- Platform-side scoping (Phase 2 · Step 2)
-- ---------------------------------------------------------------------------
-- Country Admins can be assigned MULTIPLE countries (enforced everywhere).
-- Platform Staff reuse the existing assigned_location_ids for venue scope.
-- Idempotent.

ALTER TABLE admin_users
  ADD COLUMN IF NOT EXISTS assigned_country_ids uuid[] NOT NULL DEFAULT '{}';

COMMENT ON COLUMN admin_users.assigned_country_ids IS
  'Country Admins: the countries they manage (empty = none). Platform-side scope.';

-- Backfill: existing Country Admins keep their single country_id as their first
-- assigned country, so they are not suddenly locked out of everything.
UPDATE admin_users
   SET assigned_country_ids = ARRAY[country_id]
 WHERE role = 'country_admin'
   AND country_id IS NOT NULL
   AND (assigned_country_ids IS NULL OR cardinality(assigned_country_ids) = 0);
