/*
  # Submission tracking for the approval queue

  The Approvals page could not answer "WHO submitted this and WHEN" — listings
  and class_programs carry only created_at (creation time, not edit time), and
  provider edits/submissions were never stamped. Add:

    • submitted_at       — when the provider last submitted for review
    • submitted_by_name  — the person who submitted (owner OR a staff member)

  Written best-effort by the provider save paths (Listings.vue /
  ClassesSubscriptions.vue); locations get the same columns for consistency
  (they share the approval/snapshot machinery). Idempotent.
*/

ALTER TABLE listings       ADD COLUMN IF NOT EXISTS submitted_at timestamptz;
ALTER TABLE listings       ADD COLUMN IF NOT EXISTS submitted_by_name text;
ALTER TABLE class_programs ADD COLUMN IF NOT EXISTS submitted_at timestamptz;
ALTER TABLE class_programs ADD COLUMN IF NOT EXISTS submitted_by_name text;
ALTER TABLE locations      ADD COLUMN IF NOT EXISTS submitted_at timestamptz;
ALTER TABLE locations      ADD COLUMN IF NOT EXISTS submitted_by_name text;
