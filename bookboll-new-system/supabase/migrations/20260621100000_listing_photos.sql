/*
  # Listing gallery images

  Listings can now have multiple photos (first = cover), like locations and programs.
  Adds a `photos` jsonb array column; the legacy single `photo_url` is kept and mirrored
  to photos[0] by the app for back-compat.

  Backfills the array from any existing photo_url so current covers keep showing.

  Idempotent: safe to re-run.
*/

ALTER TABLE listings ADD COLUMN IF NOT EXISTS photos jsonb NOT NULL DEFAULT '[]'::jsonb;

-- Seed the gallery from the existing single photo where the array is still empty.
UPDATE listings
SET photos = jsonb_build_array(photo_url)
WHERE (photos IS NULL OR photos = '[]'::jsonb)
  AND photo_url IS NOT NULL AND photo_url <> '';
