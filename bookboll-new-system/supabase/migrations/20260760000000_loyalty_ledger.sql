-- 20260760000000_loyalty_ledger.sql
-- Loyalty EARN ledger: one row per awarded action, keyed uniquely so an award can
-- never double-count and every grant is auditable (the old earn path was silent and
-- unverifiable). source_key examples:
--   'bk:<bookingId>'  — venue booking
--   'gm:<requestId>'  — confirmed game/event join
--   'host:<gameId>'   — hosted a game/tournament
create table if not exists loyalty_ledger (
  id          uuid primary key default gen_random_uuid(),
  customer_id uuid not null references customers(id) on delete cascade,
  country_id  uuid,
  source_key  text not null,
  points      integer not null default 0,
  reason      text default '',
  created_at  timestamptz default now(),
  unique (customer_id, source_key)
);
create index if not exists loyalty_ledger_customer_idx on loyalty_ledger (customer_id);

alter table loyalty_ledger enable row level security;
do $$ begin
  if not exists (select 1 from pg_policies where tablename = 'loyalty_ledger' and policyname = 'loyalty_ledger_all') then
    create policy loyalty_ledger_all on loyalty_ledger for all to anon, authenticated using (true) with check (true);
  end if;
end $$;
