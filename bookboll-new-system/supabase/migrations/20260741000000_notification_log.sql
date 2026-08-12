-- notification_log — idempotency ledger for SCHEDULED notifications (booking reminders,
-- trial-ending, renewal reminders, post-visit review requests). The client-side sweep
-- (src/customer/notificationSweep.js) and/or a pg_cron edge function record one row per
-- (event, subject, cycle) so a reminder is sent exactly once per cycle — even if the
-- dashboard is opened repeatedly. `cycle_key` is the date the reminder is FOR (e.g. the
-- booking_date, next_billing_at or trial_ends_at), so the same subscription is reminded
-- again next cycle when that date changes.
create table if not exists notification_log (
  id           uuid primary key default gen_random_uuid(),
  event_key    text not null,
  subject_type text not null,        -- 'booking' | 'subscription'
  subject_id   uuid not null,
  cycle_key    text not null,        -- the date/cycle this reminder is for
  sent_at      timestamptz not null default now(),
  unique (event_key, subject_id, cycle_key)
);
create index if not exists idx_notif_log_lookup on notification_log (event_key, subject_id, cycle_key);

alter table notification_log enable row level security;
drop policy if exists notif_log_all on notification_log;
create policy notif_log_all on notification_log for all using (true) with check (true);
