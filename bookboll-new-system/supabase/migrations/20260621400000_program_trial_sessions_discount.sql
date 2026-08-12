/*
  # MOAT incentives: session-based trials + discount offers

  Every membership program must offer an incentive (a free trial, or a discount on
  longer ongoing plans) — "none" is not allowed. Two columns support this:

    • free_trial_sessions — for the new "1 free session" trial (the customer attends
      one session free; billing starts on the 2nd session). 1 = first session free,
      0 = not a session trial. (Day-based trials still use free_trial_days.)
    • discount_months    — "N months off" the list price for longer-than-monthly
      ONGOING plans (replaces the old bonus_months offer). The charged amount is
      price × (1 − discount_months / interval_months).

  The legacy bonus_months column is left in place (unused) for safety.

  Idempotent: safe to re-run.
*/

ALTER TABLE class_programs ADD COLUMN IF NOT EXISTS free_trial_sessions integer NOT NULL DEFAULT 0;
ALTER TABLE class_programs ADD COLUMN IF NOT EXISTS discount_months integer NOT NULL DEFAULT 0;
