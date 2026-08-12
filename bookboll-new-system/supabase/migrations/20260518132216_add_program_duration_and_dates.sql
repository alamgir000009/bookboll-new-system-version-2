/*
  # Add program duration and optional start/end dates to class_programs

  1. New columns on class_programs
     - `program_type`   text DEFAULT 'ongoing' — 'ongoing' | 'fixed_duration'
     - `duration_weeks` integer nullable        — only used when type = fixed_duration
     - `start_date`     date nullable           — optional program start date
     - `end_date`       date nullable           — optional program end date (auto-filled from duration_weeks + start_date if left blank)
*/

DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'class_programs' AND column_name = 'program_type') THEN
    ALTER TABLE class_programs ADD COLUMN program_type text NOT NULL DEFAULT 'ongoing';
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'class_programs' AND column_name = 'duration_weeks') THEN
    ALTER TABLE class_programs ADD COLUMN duration_weeks integer;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'class_programs' AND column_name = 'start_date') THEN
    ALTER TABLE class_programs ADD COLUMN start_date date;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'class_programs' AND column_name = 'end_date') THEN
    ALTER TABLE class_programs ADD COLUMN end_date date;
  END IF;
END $$;
