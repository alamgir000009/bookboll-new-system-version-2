-- Event waitlist toggle: hosts choose whether a FULL event queues joiners.
-- Default ON (the pre-existing behaviour for instant-join events). The app treats
-- a missing column as ON, so this migration is safe to apply at any time.
alter table hosted_games add column if not exists allow_waitlist boolean not null default true;
