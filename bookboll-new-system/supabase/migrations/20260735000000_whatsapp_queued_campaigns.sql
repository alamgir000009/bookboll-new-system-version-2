-- WhatsApp queued campaigns: compose a campaign against a still-PENDING template
-- and have it AUTO-SEND the moment Meta approves the template — or surface the
-- rejection reason if Meta declines. The queue "pump" runs client-side whenever
-- the WhatsApp page or the Customers marketing composer is opened.
--
-- payload snapshot: { template:{name,language}, bodyText, recipients:[{to,customerId,variables[]}],
--                     audience, senderLabel, createdBy } — everything send_campaign needs later.

create table if not exists whatsapp_queued_campaigns (
  id uuid primary key default gen_random_uuid(),
  owner_user_id uuid,                      -- null = Bookboll platform campaigns
  template_name text not null,
  template_language text not null default 'en_US',
  payload jsonb not null,
  status text not null default 'awaiting_approval'
    check (status in ('awaiting_approval', 'sent', 'rejected', 'cancelled')),
  reject_reason text,
  result jsonb,                            -- { sent, failed, dropped } after auto-send
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now(),
  sent_at timestamptz,
  dismissed_at timestamptz                 -- outcome banner acknowledged in the dashboard
);

create index if not exists idx_wa_queue_owner on whatsapp_queued_campaigns (owner_user_id, status);

alter table whatsapp_queued_campaigns enable row level security;
drop policy if exists "wa_queue_all" on whatsapp_queued_campaigns;
create policy "wa_queue_all" on whatsapp_queued_campaigns for all using (true) with check (true);
