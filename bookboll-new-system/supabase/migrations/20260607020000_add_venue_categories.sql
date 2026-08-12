/*
  # Venue main categories

  Bookboll is expanding beyond sports venues (Hangout spots like restaurants, bowling,
  escape rooms; Work spaces like podcast/content studios). Each bookable listing now has
  a MAIN category; the existing `sport_category` column continues to hold the TYPE /
  subcategory (e.g. 'Football', 'Bowling', 'Podcast Studio').

  1. Changes
     - listings.category text NOT NULL DEFAULT 'sports'   ('sports' | 'hangout' | 'work')

  2. Notes
     - Additive + idempotent. Existing listings default to 'sports', so nothing breaks.
     - The app reads category best-effort (missing column → treated as 'sports'), so the
       site keeps working before this migration is applied.
*/

ALTER TABLE listings
  ADD COLUMN IF NOT EXISTS category text NOT NULL DEFAULT 'sports';

CREATE INDEX IF NOT EXISTS idx_listings_category ON listings(category);
