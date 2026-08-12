/*
  # Add a calendar colour to class programs

  Programs render as bookings on the calendar. Until now every program block was
  hard-coded amber (#d97706). This adds a per-program `color` so each academy
  program can be given its own colour, used for its bookings in the calendar.

  1. Changes
     - `class_programs.color` (text, default '#7c3aed') — hex colour for the
       program's bookings in the calendar / schedule views.

  Safe to run repeatedly (guarded by IF NOT EXISTS).
*/

DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_name = 'class_programs' AND column_name = 'color'
  ) THEN
    ALTER TABLE class_programs ADD COLUMN color text NOT NULL DEFAULT '#7c3aed';
  END IF;
END $$;
