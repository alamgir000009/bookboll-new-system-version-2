/*
  # Add location_id to class_programs

  1. Changes
    - Add `location_id` column to `class_programs` referencing `locations(id)`
    - Backfill `location_id` from `listings.location_id` where listing_id is set
    - Make `listing_id` optional (kept for backwards reference; programs belong to a location, not a listing)

  2. Notes
    - Programs are Academy offerings attached to a location, not a specific court listing.
*/

DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_name='class_programs' AND column_name='location_id'
  ) THEN
    ALTER TABLE class_programs ADD COLUMN location_id uuid REFERENCES locations(id) ON DELETE SET NULL;
  END IF;
END $$;

UPDATE class_programs cp
SET location_id = l.location_id
FROM listings l
WHERE cp.listing_id = l.id AND cp.location_id IS NULL;

CREATE INDEX IF NOT EXISTS idx_class_programs_location_id ON class_programs(location_id);
