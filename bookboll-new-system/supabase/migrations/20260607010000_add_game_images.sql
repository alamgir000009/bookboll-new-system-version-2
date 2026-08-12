/*
  # Event images for hosted games / hangouts

  Lets a host attach optional photos to their event. Stored as a jsonb array of
  hosted image URLs (first = cover), mirroring the multi-image pattern used by
  class_programs. Empty array = no images, and the UI falls back to the coloured
  header, so events created without photos still display correctly.

  1. Changes
     - hosted_games.images  jsonb  NOT NULL DEFAULT '[]'

  2. Notes
     - Additive + idempotent. The app writes images best-effort, so hosting keeps
       working even before this migration is applied (the photos just won't persist
       until then) — same self-healing approach as prize_pot / hangout_location.
*/

ALTER TABLE hosted_games
  ADD COLUMN IF NOT EXISTS images jsonb NOT NULL DEFAULT '[]'::jsonb;
