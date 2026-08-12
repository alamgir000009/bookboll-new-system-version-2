/*
  # Tournaments (single-elimination brackets on top of hosted_games)

  A tournament is a `hosted_games` row with game_type = 'tournament'. The host picks
  how many teams play; the bracket's rounds/matches are derived from that. Each match
  is one bookable slot on a court — when the tournament is created we reserve a real
  `bookings` row per match (so courts are held and facility owners see them), exactly
  like a normal game reservation.

  Teams join via the existing `game_join_requests` table (game_id = the tournament id;
  joiner_name = the team name). The host approves teams, generates the matchups (which
  fills round-0 matches), then taps the winner of each match to advance the bracket.

  1. hosted_games gains:
     - num_teams : the bracket size (4 | 8 | 16 | 32)
     - format    : reserved for future formats ('single_elim' for now)

  2. New table tournament_matches: one row per match (slot) in the bracket.

  3. Security: permissive anon+authenticated RLS, matching the rest of this app
     (public anon key, client-side scoping). Safe to re-run.
*/

ALTER TABLE hosted_games ADD COLUMN IF NOT EXISTS num_teams integer NOT NULL DEFAULT 0;
ALTER TABLE hosted_games ADD COLUMN IF NOT EXISTS format    text    NOT NULL DEFAULT 'single_elim';

CREATE TABLE IF NOT EXISTS tournament_matches (
  id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  tournament_id    uuid REFERENCES hosted_games(id) ON DELETE CASCADE,
  round            integer NOT NULL DEFAULT 0,        -- 0 = first round
  match_index      integer NOT NULL DEFAULT 0,        -- position within the round
  listing_id       uuid REFERENCES listings(id)  ON DELETE SET NULL,
  location_id      uuid REFERENCES locations(id) ON DELETE SET NULL,
  booking_id       uuid REFERENCES bookings(id)  ON DELETE SET NULL,  -- the court reservation for this match
  match_date       date,
  start_time       text NOT NULL DEFAULT '',
  end_time         text NOT NULL DEFAULT '',
  home_team        text NOT NULL DEFAULT '',          -- team name in the home slot (or '' = TBD)
  away_team        text NOT NULL DEFAULT '',
  home_request_id  uuid REFERENCES game_join_requests(id) ON DELETE SET NULL,
  away_request_id  uuid REFERENCES game_join_requests(id) ON DELETE SET NULL,
  winner           text NOT NULL DEFAULT '',          -- 'home' | 'away' | ''
  status           text NOT NULL DEFAULT 'scheduled', -- scheduled | done
  created_at       timestamptz DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_tmatch_tournament ON tournament_matches(tournament_id);
CREATE INDEX IF NOT EXISTS idx_tmatch_round      ON tournament_matches(tournament_id, round, match_index);

ALTER TABLE tournament_matches ENABLE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS tournament_matches_all ON tournament_matches;
CREATE POLICY tournament_matches_all ON tournament_matches FOR ALL TO anon, authenticated USING (true) WITH CHECK (true);
