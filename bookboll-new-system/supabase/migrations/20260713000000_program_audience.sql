/*
  # Membership audience — who a class/academy program is for

  Class & academy programs target an audience: kids, adults, or both. Surfaced as a tag on
  the customer site (e.g. "Kids", "Adults", "Kids & Adults"). Gym memberships don't use it
  (left null). Nullable + no default so existing rows are unaffected. Idempotent.
*/

ALTER TABLE class_programs ADD COLUMN IF NOT EXISTS audience text;   -- 'kids' | 'adults' | 'both' | null
