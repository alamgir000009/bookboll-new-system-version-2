/*
  # Add program_id and notes to bookings

  1. Changes
    - `bookings.program_id` (uuid, nullable, FK to class_programs) — links auto-generated
      slot blocks back to the program that created them; null for regular bookings
    - `bookings.notes` (text, default '') — free-text label; used to store the program name
      on program-block rows so they display meaningfully in the calendar and bookings table

  2. Index
    - Index on program_id for fast lookup when bulk-cancelling a program's future blocks

  3. No destructive changes — both columns are additive and nullable/defaulted
*/

DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_name = 'bookings' AND column_name = 'program_id'
  ) THEN
    ALTER TABLE bookings ADD COLUMN program_id uuid REFERENCES class_programs(id) ON DELETE SET NULL;
  END IF;
END $$;

DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_name = 'bookings' AND column_name = 'notes'
  ) THEN
    ALTER TABLE bookings ADD COLUMN notes text NOT NULL DEFAULT '';
  END IF;
END $$;

CREATE INDEX IF NOT EXISTS idx_bookings_program_id ON bookings(program_id);
