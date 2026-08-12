/*
  # Hosted games / tournaments (customer-to-customer)

  A customer (host) books a facility listing and opens it as a game others can join.
  Joiners send a request with their contact details; the host vets them offline
  (WhatsApp/phone) and accepts or rejects. Payment is settled offline between host
  and joiner on arrival — the platform is NOT involved in it.

  1. New tables
     - hosted_games: the open game/tournament a host has created.
     - game_join_requests: a joiner's request to join, with contact info + status.

  2. Security
     - Permissive RLS (anon + authenticated) matching the rest of this app, which
       uses the public anon key with client-side scoping.
*/

CREATE TABLE IF NOT EXISTS hosted_games (
  id                uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  host_customer_id  uuid REFERENCES customers(id) ON DELETE SET NULL,
  host_name         text NOT NULL DEFAULT '',
  host_phone        text NOT NULL DEFAULT '',
  host_whatsapp     text NOT NULL DEFAULT '',
  location_id       uuid REFERENCES locations(id) ON DELETE SET NULL,
  listing_id        uuid REFERENCES listings(id) ON DELETE SET NULL,
  booking_id        uuid REFERENCES bookings(id) ON DELETE SET NULL,  -- the real facility reservation this game holds
  city_id           uuid REFERENCES cities(id) ON DELETE SET NULL,
  country_id        uuid REFERENCES countries(id) ON DELETE SET NULL,
  title             text NOT NULL DEFAULT '',
  sport             text NOT NULL DEFAULT '',
  game_type         text NOT NULL DEFAULT 'game',   -- 'game' | 'tournament'
  game_date         date,
  start_time        text NOT NULL DEFAULT '',
  end_time          text NOT NULL DEFAULT '',
  price_per_person  numeric NOT NULL DEFAULT 0,
  currency          text NOT NULL DEFAULT 'AED',
  max_players       integer NOT NULL DEFAULT 10,
  skill_level       text NOT NULL DEFAULT 'all',     -- all | beginner | intermediate | advanced
  description       text NOT NULL DEFAULT '',
  status            text NOT NULL DEFAULT 'open',     -- open | full | cancelled | completed
  created_at        timestamptz DEFAULT now()
);

CREATE TABLE IF NOT EXISTS game_join_requests (
  id                  uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  game_id             uuid REFERENCES hosted_games(id) ON DELETE CASCADE,
  joiner_customer_id  uuid REFERENCES customers(id) ON DELETE SET NULL,
  joiner_name         text NOT NULL DEFAULT '',
  joiner_phone        text NOT NULL DEFAULT '',
  joiner_whatsapp     text NOT NULL DEFAULT '',
  joiner_message      text NOT NULL DEFAULT '',
  status              text NOT NULL DEFAULT 'pending', -- pending | accepted | rejected
  created_at          timestamptz DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_hosted_games_city   ON hosted_games(city_id);
CREATE INDEX IF NOT EXISTS idx_hosted_games_host   ON hosted_games(host_customer_id);
CREATE INDEX IF NOT EXISTS idx_join_requests_game  ON game_join_requests(game_id);

ALTER TABLE hosted_games        ENABLE ROW LEVEL SECURITY;
ALTER TABLE game_join_requests  ENABLE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS hosted_games_all ON hosted_games;
CREATE POLICY hosted_games_all ON hosted_games FOR ALL TO anon, authenticated USING (true) WITH CHECK (true);

DROP POLICY IF EXISTS game_join_requests_all ON game_join_requests;
CREATE POLICY game_join_requests_all ON game_join_requests FOR ALL TO anon, authenticated USING (true) WITH CHECK (true);
