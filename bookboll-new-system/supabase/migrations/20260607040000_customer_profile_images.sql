/*
  # Customer profile images

  Lets a player set a profile picture and add photos to their public profile.

  1. Changes
     - customers.avatar_url text NOT NULL DEFAULT ''       (profile picture)
     - customers.photos     jsonb NOT NULL DEFAULT '[]'    (extra profile photos)

  2. Notes
     - Additive + idempotent. Written best-effort by the app, so the storefront keeps
       working before this migration is applied (avatar just falls back to the initial).
*/

ALTER TABLE customers ADD COLUMN IF NOT EXISTS avatar_url text NOT NULL DEFAULT '';
ALTER TABLE customers ADD COLUMN IF NOT EXISTS photos jsonb NOT NULL DEFAULT '[]'::jsonb;
