-- 20260756000000_meetup_lifecycle_rating.sql
-- COMMUNITY module, phase 2 — meetup lifecycle:
--   • A lock deadline on every hosted game (self-serve drops are blocked once
--     locked), plus refund/forfeit markers on join requests. The
--     Open→Waitlisted→Locked→Active→Archived state itself is DERIVED client-side
--     from timestamps (no cron) — only real facts live in columns.
--   • Attendance — hosts mark who showed up; no-shows after lock forfeit their spot.

-- ── Meetup lifecycle ────────────────────────────────────────────────────────
alter table hosted_games add column if not exists lock_minutes_before int;    -- null = lock at start time

alter table game_join_requests add column if not exists attended boolean;     -- null = unmarked; set by the host
alter table game_join_requests add column if not exists refund_state text default '';  -- '' | 'refunded' | 'forfeited'
