-- Location form upgrade: support multiple images + a Google Maps link.
--
-- Until now a location had a single `photo_url`. The upgraded shared
-- LocationModal lets providers upload several images (with client-side
-- compression) and paste a Google Maps share link. We keep `photo_url`
-- for backwards compatibility (it always mirrors the first gallery image),
-- and add:
--   - photos          : ordered JSON array of image URLs (the gallery)
--   - google_maps_url : optional share link to the place on Google Maps

ALTER TABLE locations ADD COLUMN IF NOT EXISTS photos jsonb DEFAULT '[]'::jsonb;
ALTER TABLE locations ADD COLUMN IF NOT EXISTS google_maps_url text;

-- Backfill: seed the gallery from any existing single photo_url so nothing
-- visually disappears for locations created before this change.
UPDATE locations
   SET photos = jsonb_build_array(photo_url)
 WHERE photo_url IS NOT NULL
   AND photo_url <> ''
   AND (photos IS NULL OR photos = '[]'::jsonb);
