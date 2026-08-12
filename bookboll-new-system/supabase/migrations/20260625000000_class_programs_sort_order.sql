/*
  # Membership program ordering

  Lets providers re-arrange the order of their membership programs on the Listings page
  (both the grouped-by-location view and the flat "All Listings" view), mirroring how
  listings/locations already use a `sort_order` column.

  Default 0 → with the app's `ORDER BY sort_order, created_at DESC` the current
  newest-first order is preserved until the provider drags rows to reorder.

  Idempotent: safe to re-run.
*/

ALTER TABLE class_programs ADD COLUMN IF NOT EXISTS sort_order integer DEFAULT 0;
CREATE INDEX IF NOT EXISTS idx_class_programs_sort_order ON class_programs(sort_order);
