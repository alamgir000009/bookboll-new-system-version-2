/*
  Event moderation — lets Bookboll platform admins review user-hosted events and
  take down indecent / spam ones (and notify the host).

  moderation_status:
    'active'   — normal, visible on the customer storefront (default)
    'flagged'  — marked for review by an admin; STILL visible (internal triage)
    'removed'  — taken down: hidden from the storefront + the host is notified

  The customer data layer treats a missing column as 'active', so the site keeps
  working before this migration is applied.
*/

ALTER TABLE hosted_games ADD COLUMN IF NOT EXISTS moderation_status text NOT NULL DEFAULT 'active';
ALTER TABLE hosted_games ADD COLUMN IF NOT EXISTS moderation_reason text;
ALTER TABLE hosted_games ADD COLUMN IF NOT EXISTS moderated_at   timestamptz;
ALTER TABLE hosted_games ADD COLUMN IF NOT EXISTS moderated_by   text;

CREATE INDEX IF NOT EXISTS idx_hosted_games_moderation ON hosted_games (moderation_status);
