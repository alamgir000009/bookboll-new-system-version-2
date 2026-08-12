-- 20260629000000_social_profiles.sql
-- Social layer for the marketplace storefront: profile bio + privacy, posts (multi-photo),
-- follows (with accept for private profiles), and direct messages.
--
-- NOTE ON SECURITY: storefront customers are a demo-grade, email-based identity (no Supabase
-- auth session / auth.uid()). So access control for private profiles is enforced in the app
-- layer (lib/social.js), exactly like the rest of the customer-facing data. RLS here is
-- permissive (consistent with the other customer tables) so the anon client can read/write.

-- ── Profile fields ──────────────────────────────────────────────────────────
alter table customers add column if not exists bio text default '';
alter table customers add column if not exists is_private boolean default false;

-- ── Posts (a profile's own feed: caption + photo gallery) ───────────────────
create table if not exists customer_posts (
  id          uuid primary key default gen_random_uuid(),
  author_id   uuid not null references customers(id) on delete cascade,
  body        text default '',
  photos      jsonb default '[]'::jsonb,          -- array of image URLs
  created_at  timestamptz default now()
);
create index if not exists idx_customer_posts_author on customer_posts(author_id, created_at desc);

-- ── Follows (follower → following). For public targets status starts 'accepted';
--    for private targets it starts 'pending' until the owner accepts. ──────────
create table if not exists customer_follows (
  id           uuid primary key default gen_random_uuid(),
  follower_id  uuid not null references customers(id) on delete cascade,
  following_id uuid not null references customers(id) on delete cascade,
  status       text not null default 'accepted',  -- 'pending' | 'accepted'
  created_at   timestamptz default now(),
  unique (follower_id, following_id)
);
create index if not exists idx_follows_following on customer_follows(following_id, status);
create index if not exists idx_follows_follower  on customer_follows(follower_id, status);

-- ── Direct messages. conversation_key = the two ids sorted + joined, so both
--    directions share one thread. ──────────────────────────────────────────────
create table if not exists customer_messages (
  id               uuid primary key default gen_random_uuid(),
  conversation_key text not null,                 -- 'minId__maxId'
  sender_id        uuid not null references customers(id) on delete cascade,
  recipient_id     uuid not null references customers(id) on delete cascade,
  body             text not null,
  created_at       timestamptz default now(),
  read_at          timestamptz
);
create index if not exists idx_messages_convo     on customer_messages(conversation_key, created_at);
create index if not exists idx_messages_recipient on customer_messages(recipient_id, read_at);

-- ── RLS: enable + permissive (app-layer gating; matches the customer-data model) ──
alter table customer_posts    enable row level security;
alter table customer_follows  enable row level security;
alter table customer_messages enable row level security;

do $$ begin
  if not exists (select 1 from pg_policies where tablename = 'customer_posts' and policyname = 'customer_posts_all') then
    create policy customer_posts_all on customer_posts for all using (true) with check (true);
  end if;
  if not exists (select 1 from pg_policies where tablename = 'customer_follows' and policyname = 'customer_follows_all') then
    create policy customer_follows_all on customer_follows for all using (true) with check (true);
  end if;
  if not exists (select 1 from pg_policies where tablename = 'customer_messages' and policyname = 'customer_messages_all') then
    create policy customer_messages_all on customer_messages for all using (true) with check (true);
  end if;
end $$;
