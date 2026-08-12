/**
 * notification-cron — TRUE 24/7 scheduled notifications (booking reminders, post-visit
 * review requests, trial-ending, renewal reminders). This is the server-side twin of the
 * client-side src/customer/notificationSweep.js: it runs on a schedule (pg_cron / Supabase
 * scheduled function) so reminders go out even when nobody has the dashboard open.
 *
 * IDEMPOTENT via the notification_log table (one row per event+subject+cycle).
 * Sends WhatsApp on BOOKBOLL's number using platform_settings.whatsapp_config (or the
 * WHATSAPP_* env secrets). Respects each provider's per-event WhatsApp on/off toggle and
 * uses their custom wording when set, else a sensible default.
 *
 * DEPLOY:
 *   supabase functions deploy notification-cron --no-verify-jwt
 * SCHEDULE (Supabase SQL editor — runs hourly; adjust as you like):
 *   select cron.schedule('bookboll-notification-cron','0 * * * *', $$
 *     select net.http_post(
 *       url    := 'https://<PROJECT_REF>.functions.supabase.co/notification-cron',
 *       headers:= '{"Content-Type":"application/json","x-cron-key":"<CRON_KEY>"}'::jsonb
 *     ); $$);
 *   -- set CRON_KEY as a function secret and pass the same value in the header.
 */
import { createClient } from 'https://esm.sh/@supabase/supabase-js@2'

const SB_URL = Deno.env.get('SUPABASE_URL')!
const SB_SERVICE = Deno.env.get('SUPABASE_SERVICE_ROLE_KEY')!
const CRON_KEY = Deno.env.get('CRON_KEY') || ''
const sb = createClient(SB_URL, SB_SERVICE)

const iso = (d: Date) => d.toISOString().slice(0, 10)
const addDays = (base: string, n: number) => { const d = new Date(base + 'T00:00:00'); d.setDate(d.getDate() + n); return iso(d) }
const fmtDate = (d: string) => { try { return new Date(String(d).slice(0, 10) + 'T00:00:00').toLocaleDateString('en-US', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' }) } catch { return '' } }
const INTERVAL_LABEL: Record<string, string> = { weekly: 'Weekly', biweekly: 'Every 2 weeks', monthly: 'Monthly', quarterly: 'Quarterly', yearly: 'Yearly', one_time: 'One-time' }

// Fallback wording when a provider hasn't customised the template. [tokens] are filled below.
const DEFAULTS: Record<string, string> = {
  booking_reminder: 'Reminder: your booking of [listing] at [venue] is on [date] at [start_time]. See you there! [booking_link]',
  review_request: 'Thanks for visiting [venue]! How did it go? Leave a quick review: [booking_link]',
  trial_ending: 'Hi [customer_first_name], your [program] free trial ends [trial_end_date] and then continues at [currency] [amount]. Manage it: [membership_link]',
  membership_renewal_reminder: 'Hi [customer_first_name], your [program] membership renews on [next_billing_date] for [currency] [amount]. Nothing to do — we\'ll charge your card on file.',
}
const DEFAULT_LEAD: Record<string, number> = { booking_reminder: 1, trial_ending: 3, membership_renewal_reminder: 3 }

function render(text: string, data: Record<string, unknown>) {
  return String(text || '').replace(/\[([a-z_]+)\]/g, (_m, k) => { const v = data[k]; return (v === undefined || v === null) ? '' : String(v) }).replace(/[ \t]{2,}/g, ' ').trim()
}
function normPhone(p: unknown) { let d = String(p || '').replace(/\D/g, ''); if (d.startsWith('00')) d = d.slice(2); return d.length >= 8 ? d : '' }

// WhatsApp sender (Bookboll's number). Reads platform config or env secrets.
let WA: { phone_id: string; token: string } | null = null
async function waConfig() {
  if (WA) return WA
  const { data } = await sb.from('platform_settings').select('value').eq('key', 'whatsapp_config').maybeSingle()
  let cfg: any = {}
  try { cfg = typeof data?.value === 'string' ? JSON.parse(data.value) : (data?.value || {}) } catch { cfg = {} }
  WA = { phone_id: cfg.phone_number_id || Deno.env.get('WHATSAPP_PHONE_ID') || '', token: cfg.access_token || Deno.env.get('WHATSAPP_ACCESS_TOKEN') || '' }
  return WA
}
async function sendText(to: string, body: string) {
  const num = normPhone(to); if (!num || !body) return
  const { phone_id, token } = await waConfig()
  if (!phone_id || !token) return
  try {
    await fetch(`https://graph.facebook.com/v20.0/${phone_id}/messages`, {
      method: 'POST', headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
      body: JSON.stringify({ messaging_product: 'whatsapp', to: num, type: 'text', text: { body } }),
    })
  } catch { /* out-of-window / not connected → skip (in-app bell still covers it) */ }
}

// Effective per-owner WhatsApp template for an event's client audience: { on, body }.
const settingsCache = new Map<string, any>()
async function ownerEvent(ownerId: string | null, eventKey: string) {
  const key = ownerId || 'platform'
  if (!settingsCache.has(key)) {
    let platform: any = null, provider: any = null
    const pf = await sb.from('platform_settings').select('value').eq('key', 'notification_templates').maybeSingle()
    try { platform = pf.data?.value ? JSON.parse(pf.data.value) : null } catch { platform = null }
    if (ownerId) { const pr = await sb.from('admin_users').select('notification_templates').eq('id', ownerId).maybeSingle(); provider = pr.data?.notification_templates || null }
    settingsCache.set(key, { platform, provider })
  }
  const { platform, provider } = settingsCache.get(key)
  const c = provider?.events?.[eventKey]?.client?.whatsapp ?? platform?.events?.[eventKey]?.client?.whatsapp ?? null
  const on = c?.on === true
  const body = (c?.body && String(c.body).trim()) ? c.body : DEFAULTS[eventKey]
  return { on, body }
}

async function alreadySent(event_key: string, subject_id: string, cycle_key: string) {
  const { data } = await sb.from('notification_log').select('id').eq('event_key', event_key).eq('subject_id', subject_id).eq('cycle_key', cycle_key).limit(1)
  return !!(data && data.length)
}
async function logSent(event_key: string, subject_type: string, subject_id: string, cycle_key: string) {
  try { await sb.from('notification_log').insert({ event_key, subject_type, subject_id, cycle_key }) } catch { /* unique conflict = fine */ }
}

async function ownerPhone(ownerId: string | null) {
  if (!ownerId) return ''
  const { data } = await sb.from('admin_users').select('phone').eq('id', ownerId).maybeSingle()
  return data?.phone || ''
}

async function run() {
  const today = iso(new Date())
  const yesterday = addDays(today, -1)
  let sent = 0

  // ── BOOKINGS: reminder (upcoming) + review request (yesterday) ──
  const maxLead = 3
  const { data: bks } = await sb.from('bookings')
    .select('id,customer_id,listing_id,location_id,booking_date,start_time,end_time,total,booking_status')
    .gte('booking_date', yesterday).lte('booking_date', addDays(today, maxLead)).eq('booking_status', 'confirmed')
  const bookings = (bks || []).filter((b: any) => b.customer_id)
  const locIds = [...new Set(bookings.map((b: any) => b.location_id).filter(Boolean))]
  const lstIds = [...new Set(bookings.map((b: any) => b.listing_id).filter(Boolean))]
  const custIds = [...new Set(bookings.map((b: any) => b.customer_id).filter(Boolean))]
  const locMap = new Map(((await sb.from('locations').select('id,name,owner_user_id,currency_override').in('id', locIds.length ? locIds : ['00000000-0000-0000-0000-000000000000'])).data || []).map((l: any) => [l.id, l]))
  const lstMap = new Map(((await sb.from('listings').select('id,name').in('id', lstIds.length ? lstIds : ['00000000-0000-0000-0000-000000000000'])).data || []).map((l: any) => [l.id, l]))
  const custMap = new Map(((await sb.from('customers').select('id,full_name,phone').in('id', custIds.length ? custIds : ['00000000-0000-0000-0000-000000000000'])).data || []).map((c: any) => [c.id, c]))

  for (const b of bookings) {
    const loc: any = locMap.get(b.location_id); const c: any = custMap.get(b.customer_id); if (!c) continue
    const ownerId = loc?.owner_user_id || null
    const data = { customer_first_name: String(c.full_name || '').split(' ')[0] || '', listing: (lstMap.get(b.listing_id) as any)?.name || '', venue: loc?.name || '', date: fmtDate(b.booking_date), start_time: b.start_time, currency: loc?.currency_override || 'AED', booking_link: 'https://bookboll.com/my-bookings' }
    // reminder
    if (b.booking_date >= today && b.booking_date <= addDays(today, DEFAULT_LEAD.booking_reminder)) {
      const ev = await ownerEvent(ownerId, 'booking_reminder')
      if (ev.on && !(await alreadySent('booking_reminder', b.id, b.booking_date))) { await sendText(c.phone, render(ev.body, data)); await logSent('booking_reminder', 'booking', b.id, b.booking_date); sent++ }
    }
    // review request (post-visit)
    if (b.booking_date === yesterday) {
      const ev = await ownerEvent(ownerId, 'review_request')
      if (ev.on && !(await alreadySent('review_request', b.id, b.booking_date))) { await sendText(c.phone, render(ev.body, data)); await logSent('review_request', 'booking', b.id, b.booking_date); sent++ }
    }
  }

  // ── SUBSCRIPTIONS: trial ending + renewal reminder ──
  const { data: subs } = await sb.from('class_subscriptions').select('*')
    .or(`and(trial_status.eq.in_trial,trial_ends_at.gte.${today},trial_ends_at.lte.${addDays(today, 7)}),and(status.eq.active,trial_status.eq.converted,next_billing_at.gte.${today},next_billing_at.lte.${addDays(today, 7)})`)
  const progIds = [...new Set((subs || []).map((s: any) => s.program_id).filter(Boolean))]
  const progMap = new Map(((await sb.from('class_programs').select('*').in('id', progIds.length ? progIds : ['00000000-0000-0000-0000-000000000000'])).data || []).map((p: any) => [p.id, p]))

  for (const s of (subs || [])) {
    const program: any = progMap.get(s.program_id); if (!program) continue
    const ownerId = program.owner_user_id || null
    const to = (async () => { const { data: cu } = await sb.from('customers').select('phone').ilike('email', s.customer_email || '_none_').limit(1); return cu && cu[0]?.phone || '' })
    const data = { customer_first_name: String(s.customer_name || '').split(' ')[0] || '', program: program.name, currency: program.currency || 'AED', amount: s.monthly_amount != null ? Number(s.monthly_amount).toFixed(2) : '', next_billing_date: fmtDate(s.next_billing_at), trial_end_date: fmtDate(s.trial_ends_at), membership_link: 'https://bookboll.com/my-memberships' }
    if (s.trial_status === 'in_trial' && s.trial_ends_at && s.trial_ends_at >= today && s.trial_ends_at <= addDays(today, DEFAULT_LEAD.trial_ending)) {
      const ev = await ownerEvent(ownerId, 'trial_ending'); const cyc = String(s.trial_ends_at).slice(0, 10)
      if (ev.on && !(await alreadySent('trial_ending', s.id, cyc))) { await sendText(await to(), render(ev.body, data)); await logSent('trial_ending', 'subscription', s.id, cyc); sent++ }
    }
    if (s.status === 'active' && s.trial_status === 'converted' && s.next_billing_at && s.next_billing_at >= today && s.next_billing_at <= addDays(today, DEFAULT_LEAD.membership_renewal_reminder)) {
      const ev = await ownerEvent(ownerId, 'membership_renewal_reminder'); const cyc = String(s.next_billing_at).slice(0, 10)
      if (ev.on && !(await alreadySent('membership_renewal_reminder', s.id, cyc))) { await sendText(await to(), render(ev.body, data)); await logSent('membership_renewal_reminder', 'subscription', s.id, cyc); sent++ }
    }
  }
  return sent
}

Deno.serve(async (req) => {
  if (CRON_KEY && req.headers.get('x-cron-key') !== CRON_KEY) return new Response('unauthorized', { status: 401 })
  try { const sent = await run(); return Response.json({ ok: true, sent }) }
  catch (e) { return Response.json({ ok: false, error: String(e) }, { status: 500 }) }
})
