/*
  # Venue onboarding requests

  When a customer can't find a venue while browsing or hosting an event, they can ask
  Bookboll to onboard it ("Help us onboard them"). These land here for the sales team to
  action from the admin dashboard.

  1. New table
     - venue_requests: a user-submitted venue suggestion.

  2. Security
     - Permissive RLS (anon + authenticated), matching the rest of this app (public anon
       key with client-side scoping).
*/

CREATE TABLE IF NOT EXISTS venue_requests (
  id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  requested_by    uuid REFERENCES customers(id) ON DELETE SET NULL,
  requester_name  text NOT NULL DEFAULT '',
  venue_name      text NOT NULL DEFAULT '',
  area            text NOT NULL DEFAULT '',
  category        text NOT NULL DEFAULT 'sports',   -- sports | hangout | work
  details         text NOT NULL DEFAULT '',
  city_id         uuid REFERENCES cities(id) ON DELETE SET NULL,
  country_id      uuid REFERENCES countries(id) ON DELETE SET NULL,
  status          text NOT NULL DEFAULT 'new',       -- new | reviewing | contacted | onboarded | declined
  admin_note      text NOT NULL DEFAULT '',
  created_at      timestamptz DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_venue_requests_status ON venue_requests(status);
CREATE INDEX IF NOT EXISTS idx_venue_requests_city   ON venue_requests(city_id);

ALTER TABLE venue_requests ENABLE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS venue_requests_all ON venue_requests;
CREATE POLICY venue_requests_all ON venue_requests FOR ALL TO anon, authenticated USING (true) WITH CHECK (true);
