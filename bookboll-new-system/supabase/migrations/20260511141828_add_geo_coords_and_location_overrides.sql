/*
  # Add geographic coordinates and location-level overrides

  1. Changes to `cities`
     - `latitude`  numeric(10,7) — city center lat for reverse-geocode matching
     - `longitude` numeric(10,7) — city center lng

  2. Changes to `locations`
     - `latitude`         numeric(10,7) — exact venue lat (from geo-detection or manual)
     - `longitude`        numeric(10,7) — exact venue lng
     - `timezone_override` text          — nullable; overrides city/country timezone when set
     - `currency_override` text          — nullable; overrides country currency when set

  3. Notes
     - All new columns are nullable so existing rows are unaffected.
     - No RLS changes needed; existing policies on both tables remain.
*/

DO $$ BEGIN
  -- cities lat/lng
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='cities' AND column_name='latitude') THEN
    ALTER TABLE cities ADD COLUMN latitude numeric(10,7);
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='cities' AND column_name='longitude') THEN
    ALTER TABLE cities ADD COLUMN longitude numeric(10,7);
  END IF;

  -- locations lat/lng + overrides
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='locations' AND column_name='latitude') THEN
    ALTER TABLE locations ADD COLUMN latitude numeric(10,7);
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='locations' AND column_name='longitude') THEN
    ALTER TABLE locations ADD COLUMN longitude numeric(10,7);
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='locations' AND column_name='timezone_override') THEN
    ALTER TABLE locations ADD COLUMN timezone_override text;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='locations' AND column_name='currency_override') THEN
    ALTER TABLE locations ADD COLUMN currency_override text;
  END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_cities_coords ON cities(latitude, longitude);
CREATE INDEX IF NOT EXISTS idx_locations_coords ON locations(latitude, longitude);
