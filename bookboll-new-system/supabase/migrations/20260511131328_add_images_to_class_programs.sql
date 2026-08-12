/*
  # Add images jsonb column to class_programs

  1. Changes
    - Add `images` jsonb column (default empty array) for multi-image support on programs.
  2. Notes
    - Existing `photo_url` column is preserved for backwards compatibility.
*/

DO $$
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_name='class_programs' AND column_name='images'
  ) THEN
    ALTER TABLE class_programs ADD COLUMN images jsonb NOT NULL DEFAULT '[]'::jsonb;
  END IF;
END $$;
