/*
  # Provider business types

  Collected at signup (multi-select: what kind of business the provider runs) and shown /
  filterable on the super-admin Team roster. Stored as a jsonb array of type keys on the
  provider's admin_users row, e.g. ["sports_facility","gym"].

  Keys (see src/lib/businessTypes.js): sports_facility · gym · academy · classes · other_venue

  Idempotent — safe to re-run. Existing providers default to [] (unknown).
*/

ALTER TABLE admin_users ADD COLUMN IF NOT EXISTS business_types jsonb NOT NULL DEFAULT '[]'::jsonb;
