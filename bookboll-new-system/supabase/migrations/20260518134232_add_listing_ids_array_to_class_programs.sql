/*
  # Add listing_ids array to class_programs

  Adds a `listing_ids` jsonb column that stores an array of listing UUIDs.
  The existing `listing_id` column is kept intact for backwards compatibility.
  New code will write to `listing_ids`; `listing_id` will hold the first element
  of that array (or null) for any legacy queries.
*/

DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_name = 'class_programs' AND column_name = 'listing_ids'
  ) THEN
    ALTER TABLE class_programs ADD COLUMN listing_ids jsonb NOT NULL DEFAULT '[]'::jsonb;
  END IF;
END $$;
