/*
  # Add sort_order to locations and listings

  Adds a sort_order integer column to both tables so the admin can
  manually control the display order. Existing rows get their order
  seeded from created_at so nothing jumps around after the migration.
*/

DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_name = 'locations' AND column_name = 'sort_order'
  ) THEN
    ALTER TABLE locations ADD COLUMN sort_order integer DEFAULT 0;
  END IF;
END $$;

DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_name = 'listings' AND column_name = 'sort_order'
  ) THEN
    ALTER TABLE listings ADD COLUMN sort_order integer DEFAULT 0;
  END IF;
END $$;

-- Seed sort_order from created_at order per-location for listings, and globally for locations
WITH ranked AS (
  SELECT id, ROW_NUMBER() OVER (ORDER BY created_at ASC) - 1 AS rn FROM locations
)
UPDATE locations SET sort_order = ranked.rn FROM ranked WHERE locations.id = ranked.id;

WITH ranked AS (
  SELECT id, location_id, ROW_NUMBER() OVER (PARTITION BY location_id ORDER BY created_at ASC) - 1 AS rn FROM listings
)
UPDATE listings SET sort_order = ranked.rn FROM ranked WHERE listings.id = ranked.id;
