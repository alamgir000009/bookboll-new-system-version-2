-- One QR per member ("member card"):
--   entry_passes gains kind 'member' — ref_id = customers.id, owner_user_id NULL
--   (the pass belongs to the member, not to one business; the check-in lookup
--   scopes what it reveals to the scanning business).
--
-- attendance_logs gains program/subscription columns: with a member-level pass,
-- the pass alone no longer says WHICH class the visit was for — the check-in
-- screen picks the membership and stamps it here (also powers the class roster).

ALTER TABLE entry_passes DROP CONSTRAINT IF EXISTS entry_passes_kind_check;
ALTER TABLE entry_passes ADD CONSTRAINT entry_passes_kind_check
  CHECK (kind IN ('booking', 'membership', 'member'));

ALTER TABLE attendance_logs ADD COLUMN IF NOT EXISTS program_id uuid;
ALTER TABLE attendance_logs ADD COLUMN IF NOT EXISTS subscription_id uuid;
CREATE INDEX IF NOT EXISTS attendance_logs_program_day_idx
  ON attendance_logs (program_id, checked_at);
