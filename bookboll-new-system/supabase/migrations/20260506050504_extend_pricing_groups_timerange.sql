/*
  # Extend pricing_groups with time range and day selection

  1. Changes
    - Add `start_time` text column to pricing_groups (HH:mm)
    - Add `end_time` text column to pricing_groups (HH:mm)
    - Add `days` text column to pricing_groups (comma-separated day indexes e.g. "1,2,3,4,5")

  These allow a pricing group to represent a time-range rule across selected days,
  matching the Groups/Slot-Grid UI pattern.
*/

DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='pricing_groups' AND column_name='start_time') THEN
    ALTER TABLE pricing_groups ADD COLUMN start_time text NOT NULL DEFAULT '18:00';
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='pricing_groups' AND column_name='end_time') THEN
    ALTER TABLE pricing_groups ADD COLUMN end_time text NOT NULL DEFAULT '22:00';
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='pricing_groups' AND column_name='days') THEN
    ALTER TABLE pricing_groups ADD COLUMN days text NOT NULL DEFAULT '1,2,3,4,5';
  END IF;
END $$;
