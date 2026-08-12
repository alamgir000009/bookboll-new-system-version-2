/*
  # Custom sign-up forms for programs & events

  Providers/hosts can attach OPTIONAL custom questions to a specific membership program
  or event (hosted game / tournament). Customers answer them at sign-up; the owner sees
  the answers. Questions + answers are stored as JSON (no per-field tables).

    • signup_form  jsonb '[]'  — the questions: [{ id, type, label, required, options[], placeholder }]
    • form_answers jsonb '{}'  — the customer's answers: { <fieldId>: value }

  Idempotent: safe to re-run.
*/

-- Tournaments are stored AS hosted_games rows (game_type='tournament'), so the one
-- hosted_games column covers both games and tournaments.
ALTER TABLE class_programs      ADD COLUMN IF NOT EXISTS signup_form  jsonb NOT NULL DEFAULT '[]'::jsonb;
ALTER TABLE hosted_games        ADD COLUMN IF NOT EXISTS signup_form  jsonb NOT NULL DEFAULT '[]'::jsonb;

ALTER TABLE class_subscriptions ADD COLUMN IF NOT EXISTS form_answers jsonb NOT NULL DEFAULT '{}'::jsonb;
ALTER TABLE game_join_requests  ADD COLUMN IF NOT EXISTS form_answers jsonb NOT NULL DEFAULT '{}'::jsonb;
