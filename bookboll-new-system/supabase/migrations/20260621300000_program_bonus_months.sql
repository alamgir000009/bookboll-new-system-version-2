/*
  # Membership program bonus months

  Each program has ONE billing interval (providers create separate programs for different
  intervals). For longer-than-monthly ongoing programs the offer is "bonus months free" —
  pay upfront, get N extra months before the next charge. Monthly programs use the existing
  free_trial_days instead.

  Adds a single integer column; no jsonb plan array.

  Idempotent: safe to re-run.
*/

ALTER TABLE class_programs ADD COLUMN IF NOT EXISTS bonus_months integer NOT NULL DEFAULT 0;
