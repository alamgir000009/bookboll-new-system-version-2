/*
  # Add own-spot location to hangouts

  A "hangout" (game_type 'event') can be hosted at the host's OWN place instead of a
  booked venue. These columns store that own-spot location and a privacy flag:

  1. Columns on `hosted_games`
     - `custom_location_name`    text  — public-facing spot name the host sets (e.g. "JBR Beach").
                                          Always shown publicly. Empty for venue-booked events.
     - `custom_location_address` text  — the exact address/details. Shown to accepted joiners
                                          (and the host); hidden from the public when hide_location.
     - `hide_location`           bool  — when true, only the host + accepted joiners see the exact
                                          address; everyone else sees just `custom_location_name`.

  Notes
  - Idempotent (IF NOT EXISTS). Safe to re-run.
  - No RLS changes: hosted_games already lets the anon storefront insert/update.
  - The app probes `hide_location` and only enables the "My own spot" + "hide exact location"
    options once this is applied; until then hangouts are venue-booked only.
*/

ALTER TABLE hosted_games ADD COLUMN IF NOT EXISTS custom_location_name    text    NOT NULL DEFAULT '';
ALTER TABLE hosted_games ADD COLUMN IF NOT EXISTS custom_location_address text    NOT NULL DEFAULT '';
ALTER TABLE hosted_games ADD COLUMN IF NOT EXISTS hide_location           boolean NOT NULL DEFAULT false;
