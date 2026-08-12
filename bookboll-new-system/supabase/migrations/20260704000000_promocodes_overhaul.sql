-- ════════════════════════════════════════════════════════════════════════════
-- Promocodes overhaul: customer targeting + redemption tracking (roadmap #8)
-- ════════════════════════════════════════════════════════════════════════════

-- Targeting + per-customer limit on the code itself.
alter table promocodes
  add column if not exists audience           text    not null default 'all',          -- 'all' | 'new' | 'specific'
  add column if not exists target_emails      jsonb   not null default '[]'::jsonb,     -- lowercased emails for 'specific'
  add column if not exists per_customer_limit integer not null default 0;               -- 0 = unlimited per customer

-- Relax the creator_role CHECK so platform roles (country admin / platform staff) can
-- create codes too (CP2b writes the actual actor role).
do $$ declare c record; begin
  for c in select con.conname from pg_constraint con join pg_class rel on rel.oid = con.conrelid
    where rel.relname = 'promocodes' and con.contype = 'c' and pg_get_constraintdef(con.oid) ilike '%creator_role%'
  loop execute format('alter table promocodes drop constraint %I', c.conname); end loop;
end $$;

-- One row per redemption — powers usage tracking + per-customer / new-customer rules.
create table if not exists promocode_redemptions (
  id              uuid primary key default gen_random_uuid(),
  promocode_id    uuid references promocodes(id) on delete set null,
  code            text not null default '',
  customer_email  text not null default '',
  customer_name   text not null default '',
  source_type     text not null default '',      -- 'booking' | 'subscription'
  source_id       uuid,
  order_amount    numeric not null default 0,
  discount_amount numeric not null default 0,
  currency        text not null default 'AED',
  recipient       text not null default '',       -- 'bookboll' | 'provider' (who absorbs it)
  owner_user_id   uuid,
  country_id      uuid,
  redeemed_at     timestamptz default now()
);
create index if not exists idx_promocode_redemptions_code  on promocode_redemptions(promocode_id);
create index if not exists idx_promocode_redemptions_email on promocode_redemptions(customer_email);

alter table promocode_redemptions enable row level security;
do $$ begin
  begin execute 'create policy "anon_all_promocode_redemptions" on promocode_redemptions for all to anon using (true) with check (true)'; exception when duplicate_object then null; end;
  begin execute 'create policy "auth_all_promocode_redemptions" on promocode_redemptions for all to authenticated using (true) with check (true)'; exception when duplicate_object then null; end;
end $$;
