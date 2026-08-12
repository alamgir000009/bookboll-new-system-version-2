-- Service modules per city.
-- Super Admin can disable individual services for a city.
-- When a module is disabled, the relevant creation flows are blocked for that city.
--
--   module_listings    → court / space booking listings
--   module_classes     → academy classes & subscriptions
--   module_matchmaking → player matchmaking (host/join events)
--
-- All modules are ON by default. NULL is treated as ON everywhere in the app.

ALTER TABLE cities
  ADD COLUMN IF NOT EXISTS module_listings    BOOLEAN NOT NULL DEFAULT TRUE,
  ADD COLUMN IF NOT EXISTS module_classes     BOOLEAN NOT NULL DEFAULT TRUE,
  ADD COLUMN IF NOT EXISTS module_matchmaking BOOLEAN NOT NULL DEFAULT TRUE;
