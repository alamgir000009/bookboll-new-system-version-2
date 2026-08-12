-- ── Extend promocodes with recipient, creator, and scope columns ─────────────
--
-- recipient:      'bookboll' — discount is absorbed by Bookboll's platform fee
--                 'provider' — discount is absorbed by the provider's offline share
--
-- creator_role:   'super_admin' | 'provider'
--   Super Admin codes can target either recipient.
--   Provider codes are always recipient='provider' (they can only discount their own share).
--
-- owner_user_id:  NULL = global / platform-wide code (super admin)
--                 UUID = code belongs to / is scoped to a specific provider_admin
--
-- applicable_to:  'all'      — works on all booking types
--                 'facility' — court rentals only
--                 'program'  — academy / class / subscription programs only
--
-- location_id:    NULL = not scoped to a location
--                 UUID = only valid for bookings at this specific location
-- ─────────────────────────────────────────────────────────────────────────────

ALTER TABLE promocodes
  ADD COLUMN IF NOT EXISTS recipient      TEXT NOT NULL DEFAULT 'bookboll'
    CHECK (recipient IN ('bookboll', 'provider')),
  ADD COLUMN IF NOT EXISTS creator_role   TEXT NOT NULL DEFAULT 'super_admin'
    CHECK (creator_role IN ('super_admin', 'provider')),
  ADD COLUMN IF NOT EXISTS owner_user_id  UUID REFERENCES admin_users(id) ON DELETE SET NULL,
  ADD COLUMN IF NOT EXISTS applicable_to  TEXT NOT NULL DEFAULT 'all'
    CHECK (applicable_to IN ('all', 'facility', 'program')),
  ADD COLUMN IF NOT EXISTS location_id    UUID REFERENCES locations(id) ON DELETE SET NULL;

-- Helpful indexes for lookups at booking time
CREATE INDEX IF NOT EXISTS idx_promocodes_owner_user_id ON promocodes(owner_user_id);
CREATE INDEX IF NOT EXISTS idx_promocodes_recipient     ON promocodes(recipient);
CREATE INDEX IF NOT EXISTS idx_promocodes_applicable_to ON promocodes(applicable_to);
