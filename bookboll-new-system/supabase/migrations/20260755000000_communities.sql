-- 20260755000000_communities.sql
-- COMMUNITY module, phase 1: clubs customers create/browse/join, with a post feed,
-- a club group chat, and meetups (hosted_games gain a community_id so a community's
-- events reuse the ENTIRE existing events machinery — join requests, waitlist,
-- payments, court bookings, tournaments, moderation).
--
-- NOTE ON SECURITY: storefront customers are a demo-grade email identity (no
-- auth.uid()), so access control is enforced in the app layer (src/customer/community.js),
-- exactly like hosted_games and the social tables. RLS here is permissive.

-- ── Communities (clubs) ────────────────────────────────────────────────────────
create table if not exists communities (
  id                 uuid primary key default gen_random_uuid(),
  owner_customer_id  uuid not null references customers(id) on delete cascade,
  name               text not null,
  description        text default '',
  sport              text default '',                 -- primary sport tag (same vocabulary as hosted_games.sport)
  sports             jsonb default '[]'::jsonb,       -- optional extra sport tags
  city_id            uuid,
  country_id         uuid,
  cover_image        text default '',
  images             jsonb default '[]'::jsonb,
  is_private         boolean default false,           -- private = join requests need owner/admin approval
  settings           jsonb default '{}'::jsonb,
  moderation_status  text default 'active',           -- 'active' | 'flagged' | 'removed' (same enum as hosted_games)
  moderation_reason  text,
  moderated_at       timestamptz,
  moderated_by       text,
  created_at         timestamptz default now()
);
create index if not exists idx_communities_city  on communities(city_id, created_at desc);
create index if not exists idx_communities_owner on communities(owner_customer_id);

-- ── Membership (join/follow). role: owner > admin > member. status 'pending'
--    only for private communities until approved. last_read_at = chat read cursor. ──
create table if not exists community_members (
  id            uuid primary key default gen_random_uuid(),
  community_id  uuid not null references communities(id) on delete cascade,
  customer_id   uuid not null references customers(id) on delete cascade,
  role          text not null default 'member',       -- 'owner' | 'admin' | 'member'
  status        text not null default 'accepted',     -- 'pending' | 'accepted'
  last_read_at  timestamptz,                          -- community chat read cursor
  joined_at     timestamptz default now(),
  unique (community_id, customer_id)
);
create index if not exists idx_cmembers_community on community_members(community_id, status);
create index if not exists idx_cmembers_customer  on community_members(customer_id, status);

-- ── Community feed posts (separate from customer_posts so the existing profile
--    feed never needs a new filter). ──────────────────────────────────────────
create table if not exists community_posts (
  id                 uuid primary key default gen_random_uuid(),
  community_id       uuid not null references communities(id) on delete cascade,
  author_id          uuid not null references customers(id) on delete cascade,
  body               text default '',
  photos             jsonb default '[]'::jsonb,
  moderation_status  text default 'active',
  moderation_reason  text,
  moderated_at       timestamptz,
  moderated_by       text,
  created_at         timestamptz default now()
);
create index if not exists idx_cposts_community on community_posts(community_id, created_at desc);

-- ── Meetups: a community's events ARE hosted_games rows (full machinery reuse). ──
alter table hosted_games add column if not exists community_id uuid;
create index if not exists idx_hosted_games_community on hosted_games(community_id);

-- ── Group chat: reuse customer_messages with a group conversation_key
--    ('community__<id>' / 'meet__<id>'). Group messages have no single recipient. ──
alter table customer_messages alter column recipient_id drop not null;

-- ── RLS: enable + permissive (app-layer gating; matches the customer-data model) ──
alter table communities      enable row level security;
alter table community_members enable row level security;
alter table community_posts  enable row level security;

do $$ begin
  if not exists (select 1 from pg_policies where tablename = 'communities' and policyname = 'communities_all') then
    create policy communities_all on communities for all using (true) with check (true);
  end if;
  if not exists (select 1 from pg_policies where tablename = 'community_members' and policyname = 'community_members_all') then
    create policy community_members_all on community_members for all using (true) with check (true);
  end if;
  if not exists (select 1 from pg_policies where tablename = 'community_posts' and policyname = 'community_posts_all') then
    create policy community_posts_all on community_posts for all using (true) with check (true);
  end if;
end $$;
