/*
  # Membership program main category

  Membership programs now have a MAIN category (gym | class | academy) alongside the
  existing `sport_category` column, which becomes the TYPE / subtype (e.g. "Calisthenics",
  "Spinning", "Football"). Mirrors how listings use category + sport_category.

  Default 'academy' keeps existing sport-style programs working before the form is used.

  Idempotent: safe to re-run.
*/

ALTER TABLE class_programs ADD COLUMN IF NOT EXISTS category text NOT NULL DEFAULT 'academy';

-- Backfill any nulls (defensive) to the default.
UPDATE class_programs SET category = 'academy' WHERE category IS NULL OR category = '';
