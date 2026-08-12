-- 20260757000000_matchmaking_streetcred.sql
-- COMMUNITY module, phase 3:
--   • Matchmaking — organizers run live ROUNDS at a meetup (round robin / Swiss /
--     king of the court) across N courts. Rounds are an organizer-authored atomic
--     document → jsonb on hosted_games (the images/signup_form precedent); tapping
--     a winner per court feeds a live W-L leaderboard.
--   • Street cred — post-match peer badges ("props"): purely social recognition.
--     unique(game, giver, receiver, badge) stops spam.

alter table hosted_games add column if not exists play_format text default '';   -- '' | round_robin | swiss | king_court
alter table hosted_games add column if not exists courts_count int;
alter table hosted_games add column if not exists rounds jsonb;                  -- { teamSize, current, rounds:[{n, courts:[{court, home, away, winner, rated}], sitOut}] }

create table if not exists street_cred (
  id          uuid primary key default gen_random_uuid(),
  game_id     uuid not null references hosted_games(id) on delete cascade,
  giver_id    uuid not null references customers(id) on delete cascade,
  receiver_id uuid not null references customers(id) on delete cascade,
  badge       text not null,        -- great_sport | clutch | hustle | wall | organizer_star
  created_at  timestamptz default now(),
  unique (game_id, giver_id, receiver_id, badge)
);
create index if not exists idx_streetcred_receiver on street_cred(receiver_id);

alter table street_cred enable row level security;
do $$ begin
  if not exists (select 1 from pg_policies where tablename = 'street_cred' and policyname = 'street_cred_all') then
    create policy street_cred_all on street_cred for all using (true) with check (true);
  end if;
end $$;
