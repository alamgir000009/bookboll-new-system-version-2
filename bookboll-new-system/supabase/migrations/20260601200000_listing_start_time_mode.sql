-- ── Smart booking slots: per-listing start-time mode ────────────────────────
-- start_time_mode:
--   'auto'  — booking screen offers every bookable slot across open hours (default)
--   'fixed' — only the explicit start times in fixed_start_times are bookable
-- fixed_start_times: array of 'HH:MM' strings (only used when mode = 'fixed')

ALTER TABLE listings
  ADD COLUMN IF NOT EXISTS start_time_mode   TEXT    NOT NULL DEFAULT 'auto'
    CHECK (start_time_mode IN ('auto', 'fixed')),
  ADD COLUMN IF NOT EXISTS fixed_start_times TEXT[]  NOT NULL DEFAULT '{}';
