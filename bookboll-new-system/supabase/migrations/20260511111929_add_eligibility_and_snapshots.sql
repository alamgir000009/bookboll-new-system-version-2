/*
  # Add eligibility control, admin notes, and change snapshots

  ## Summary
  Adds the ability for admins to pause locations/listings with a reason note,
  tracks a snapshot of field values before any edit so the Approvals page can
  show a before/after diff, and ensures every save resets approval_status to
  'pending' for re-review.

  ## New Columns

  ### locations
  - `eligibility_status` (text, default 'eligible') — 'eligible' | 'paused'
  - `admin_note` (text) — reason written by admin when pausing
  - `previous_snapshot` (jsonb) — snapshot of row values before the latest edit

  ### listings
  - `eligibility_status` (text, default 'eligible') — 'eligible' | 'paused'
  - `admin_note` (text) — reason written by admin when pausing
  - `previous_snapshot` (jsonb) — snapshot of row values before the latest edit

  ## Notes
  - existing rows default to 'eligible' and null note/snapshot
  - approval_status logic (pending/approved/rejected) unchanged; we layer on top
*/

DO $$
BEGIN
  -- locations
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='locations' AND column_name='eligibility_status') THEN
    ALTER TABLE locations ADD COLUMN eligibility_status text NOT NULL DEFAULT 'eligible';
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='locations' AND column_name='admin_note') THEN
    ALTER TABLE locations ADD COLUMN admin_note text NOT NULL DEFAULT '';
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='locations' AND column_name='previous_snapshot') THEN
    ALTER TABLE locations ADD COLUMN previous_snapshot jsonb;
  END IF;

  -- listings
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='listings' AND column_name='eligibility_status') THEN
    ALTER TABLE listings ADD COLUMN eligibility_status text NOT NULL DEFAULT 'eligible';
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='listings' AND column_name='admin_note') THEN
    ALTER TABLE listings ADD COLUMN admin_note text NOT NULL DEFAULT '';
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='listings' AND column_name='previous_snapshot') THEN
    ALTER TABLE listings ADD COLUMN previous_snapshot jsonb;
  END IF;
END $$;
