-- Entry passes + attendance: per-booking / per-membership QR + text codes so a
-- provider can verify identity at the door and mark attendance ("check-in").
--
-- entry_passes: ONE pass per (kind, ref). kind 'booking' → bookings.id,
--               kind 'membership' → class_subscriptions.id. Codes are short
--               human-readable (6 chars, unambiguous alphabet), generated
--               app-side lazily when the customer first opens My Pass.
-- attendance_logs: one row per confirmed check-in (memberships check in many times).
--
-- Demo-grade RLS (permissive anon, same posture as the rest of the schema —
-- security is app-layer; tighten before production).

create table if not exists entry_passes (
  id uuid primary key default gen_random_uuid(),
  kind text not null check (kind in ('booking', 'membership')),
  ref_id uuid not null,
  owner_user_id uuid,                 -- provider that must be at the door (scopes lookups)
  customer_id uuid,                   -- customers.id (bookings)
  customer_email text,                -- class_subscriptions key (memberships) / convenience
  code text not null unique,
  created_at timestamptz not null default now(),
  unique (kind, ref_id)
);

create index if not exists idx_entry_passes_code on entry_passes (code);
create index if not exists idx_entry_passes_owner on entry_passes (owner_user_id);

create table if not exists attendance_logs (
  id uuid primary key default gen_random_uuid(),
  pass_id uuid not null references entry_passes(id) on delete cascade,
  checked_at timestamptz not null default now(),
  checked_by uuid,                    -- admin_users id of the staff member confirming
  note text
);

create index if not exists idx_attendance_pass on attendance_logs (pass_id);
create index if not exists idx_attendance_when on attendance_logs (checked_at);

alter table entry_passes enable row level security;
alter table attendance_logs enable row level security;

drop policy if exists "entry_passes_all" on entry_passes;
create policy "entry_passes_all" on entry_passes for all using (true) with check (true);

drop policy if exists "attendance_logs_all" on attendance_logs;
create policy "attendance_logs_all" on attendance_logs for all using (true) with check (true);
