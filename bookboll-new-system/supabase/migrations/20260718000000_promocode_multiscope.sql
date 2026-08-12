/*
  # Promocode multi-scope

  A promocode can now be scoped to MULTIPLE businesses, locations, listings and membership
  programs at once (previously a single provider + single location). Empty array = no restriction
  on that dimension ("any"). Validation (lib/promocodes.js) honours the arrays, falling back to the
  legacy single columns (owner_user_id / location_id) for codes created before this migration.

  The legacy single columns are KEPT (populated with the first selected id on save) so existing
  display, jurisdiction and filtering keep working.

  Idempotent (IF NOT EXISTS) + backfilled from the legacy singles.
*/

ALTER TABLE promocodes ADD COLUMN IF NOT EXISTS owner_user_ids uuid[] NOT NULL DEFAULT '{}';
ALTER TABLE promocodes ADD COLUMN IF NOT EXISTS location_ids   uuid[] NOT NULL DEFAULT '{}';
ALTER TABLE promocodes ADD COLUMN IF NOT EXISTS listing_ids    uuid[] NOT NULL DEFAULT '{}';
ALTER TABLE promocodes ADD COLUMN IF NOT EXISTS program_ids    uuid[] NOT NULL DEFAULT '{}';

-- Backfill the arrays from the legacy single-value columns (only where still empty).
UPDATE promocodes SET owner_user_ids = ARRAY[owner_user_id]
  WHERE owner_user_id IS NOT NULL AND (owner_user_ids IS NULL OR owner_user_ids = '{}');
UPDATE promocodes SET location_ids = ARRAY[location_id]
  WHERE location_id IS NOT NULL AND (location_ids IS NULL OR location_ids = '{}');

-- GIN indexes for array-membership lookups.
CREATE INDEX IF NOT EXISTS idx_promocodes_owner_user_ids ON promocodes USING gin(owner_user_ids);
CREATE INDEX IF NOT EXISTS idx_promocodes_location_ids   ON promocodes USING gin(location_ids);
CREATE INDEX IF NOT EXISTS idx_promocodes_listing_ids    ON promocodes USING gin(listing_ids);
CREATE INDEX IF NOT EXISTS idx_promocodes_program_ids    ON promocodes USING gin(program_ids);
