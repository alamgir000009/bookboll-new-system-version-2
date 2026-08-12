/*
  # Add prize pots to hosted games & tournaments

  Hosts can optionally offer a prize pot for a game or tournament. The pot is paid
  by the HOST directly to the winner(s) — Bookboll never holds or guarantees it (see
  the in-app liability waiver). These columns just store what the host advertises so
  it can be shown to players browsing/joining.

  1. Columns on `hosted_games`
     - `prize_pot`  numeric  — advertised prize amount (in the game's currency). 0 = none.
     - `prize_note` text     — optional split/details, e.g. "1st AED 500 · 2nd AED 200".

  Notes
  - Idempotent (IF NOT EXISTS). Safe to re-run.
  - No RLS changes: hosted_games already allows the anon storefront to insert/update.
  - The app writes these best-effort, so creation keeps working before this is applied;
    prize pots simply won't persist/show until it is.
*/

ALTER TABLE hosted_games ADD COLUMN IF NOT EXISTS prize_pot  numeric NOT NULL DEFAULT 0;
ALTER TABLE hosted_games ADD COLUMN IF NOT EXISTS prize_note text    NOT NULL DEFAULT '';
