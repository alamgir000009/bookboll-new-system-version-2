/*
  # Event join-request deadline

  Lets a host set a cut-off after which no new join requests / applications are accepted
  for a game, hangout or tournament (all stored in hosted_games). Recommended but optional.

  - request_deadline : timestamptz, NULL = no deadline (requests open until the event fills/starts)

  Additive + nullable, so existing events keep working unchanged.
*/

ALTER TABLE hosted_games ADD COLUMN IF NOT EXISTS request_deadline timestamptz;
