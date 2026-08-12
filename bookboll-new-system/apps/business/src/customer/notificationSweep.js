/**
 * notificationSweep.js — fires the SCHEDULED notifications that have no single "moment":
 *   • booking_reminder            — X days before a booking (remind_before_days)
 *   • review_request              — the day after a booking (post-visit)
 *   • trial_ending                — X days before a trial converts (trial_ending_days)
 *   • membership_renewal_reminder — X days before a recurring charge (renewal_reminder_days)
 *
 * This build has no server cron, so the sweep runs CLIENT-SIDE when a provider opens their
 * dashboard (like windDownSweep). `notification_log` makes it idempotent — each (event,
 * subject, cycle) sends exactly once even across repeated opens; `cycle_key` is the date the
 * reminder is FOR, so the same subscription is reminded again next cycle. For true 24/7
 * delivery, deploy the same logic as a pg_cron edge function (see supabase/functions).
 *
 * Fire-and-forget throughout — a sweep hiccup never affects the dashboard.
 */
import { supabase } from '../lib/supabase.js'
import { loadProviderNotificationSettings } from '../lib/notificationTemplates.js'
import { notifyBookingReminder, notifyReviewRequest, notifyTrialEnding, notifyRenewalReminder } from './notificationDispatch.js'

const iso = (d) => d.toISOString().slice(0, 10)
const addDays = (base, n) => { const d = new Date(base + 'T00:00:00'); d.setDate(d.getDate() + n); return iso(d) }
const fmtDate = (d) => { try { return new Date(String(d).slice(0, 10) + 'T00:00:00').toLocaleDateString('en-US', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' }) } catch { return '' } }

async function loadSentKeys(triples) {
  const ids = [...new Set(triples.map(t => t.subject_id))]
  if (!ids.length) return new Set()
  const { data } = await supabase.from('notification_log').select('event_key,subject_id,cycle_key').in('subject_id', ids)
  const set = new Set()
  for (const r of (data || [])) set.add(`${r.event_key}|${r.subject_id}|${r.cycle_key}`)
  return set
}
async function markSent(event_key, subject_type, subject_id, cycle_key) {
  try { await supabase.from('notification_log').insert({ event_key, subject_type, subject_id, cycle_key }) } catch { /* unique conflict = already sent */ }
}

const sweptOwners = new Set()   // once per owner per app session (idempotency also covers repeats)

/**
 * Run the reminder sweep for one provider. Safe to call on every dashboard mount.
 * Pass no ownerId (or a falsy one) to no-op.
 */
export async function runNotificationSweep(ownerId) {
  if (!ownerId || sweptOwners.has(ownerId)) return
  sweptOwners.add(ownerId)
  try {
    const { settings } = await loadProviderNotificationSettings(ownerId)
    const remindDays  = Math.max(0, Number(settings?.remind_before_days) || 1)
    const trialDays   = Math.max(0, Number(settings?.trial_ending_days) || 3)
    const renewalDays = Math.max(0, Number(settings?.renewal_reminder_days) || 3)
    const today = iso(new Date())
    const yesterday = addDays(today, -1)

    // Owner's locations / listings / programs (for names + scoping).
    const { data: locs } = await supabase.from('locations').select('id,name,owner_user_id,currency_override').eq('owner_user_id', ownerId)
    const locMap = new Map((locs || []).map(l => [l.id, l]))
    const locIds = [...locMap.keys()]
    const { data: lst } = locIds.length ? await supabase.from('listings').select('id,name,location_id').in('location_id', locIds) : { data: [] }
    const listingMap = new Map((lst || []).map(l => [l.id, l]))
    const { data: progs } = await supabase.from('class_programs').select('*').eq('owner_user_id', ownerId)
    const progMap = new Map((progs || []).map(p => [p.id, p]))
    const progIds = [...progMap.keys()]

    // ── BOOKINGS: reminder (upcoming window) + review request (yesterday) ──
    if (locIds.length) {
      const { data: bks } = await supabase.from('bookings')
        .select('id,customer_id,listing_id,location_id,booking_date,start_time,end_time,total,booking_status')
        .in('location_id', locIds)
        .gte('booking_date', yesterday).lte('booking_date', addDays(today, remindDays))
        .eq('booking_status', 'confirmed')
      const bookings = (bks || []).filter(b => b.customer_id)
      const custIds = [...new Set(bookings.map(b => b.customer_id))]
      const { data: custs } = custIds.length ? await supabase.from('customers').select('id,full_name,email,phone').in('id', custIds) : { data: [] }
      const custMap = new Map((custs || []).map(c => [c.id, c]))

      const triples = []
      for (const b of bookings) {
        if (b.booking_date >= today && b.booking_date <= addDays(today, remindDays)) triples.push({ event_key: 'booking_reminder', subject_id: b.id, cycle_key: b.booking_date, b })
        if (b.booking_date === yesterday) triples.push({ event_key: 'review_request', subject_id: b.id, cycle_key: b.booking_date, b })
      }
      const sent = await loadSentKeys(triples)
      for (const t of triples) {
        if (sent.has(`${t.event_key}|${t.subject_id}|${t.cycle_key}`)) continue
        const b = t.b, c = custMap.get(b.customer_id); if (!c) continue
        const loc = locMap.get(b.location_id), listing = listingMap.get(b.listing_id)
        const customer = { id: c.id, name: c.full_name, email: c.email, phone: c.phone }
        const booking = { listing: listing?.name, venue: loc?.name, business_name: loc?.name, date: fmtDate(b.booking_date), start_time: b.start_time, end_time: b.end_time, code: String(b.id).slice(0, 8).toUpperCase(), currency: loc?.currency_override || 'AED', amount: b.total }
        if (t.event_key === 'booking_reminder') notifyBookingReminder({ ownerId, customer, booking })
        else notifyReviewRequest({ ownerId, customer, booking })
        await markSent(t.event_key, 'booking', b.id, b.booking_date)
      }
    }

    // ── SUBSCRIPTIONS: trial ending + renewal reminder ──
    if (progIds.length) {
      const { data: subs } = await supabase.from('class_subscriptions').select('*').in('program_id', progIds)
      const triples = []
      for (const s of (subs || [])) {
        if (s.trial_status === 'in_trial' && s.trial_ends_at && s.trial_ends_at >= today && s.trial_ends_at <= addDays(today, trialDays))
          triples.push({ event_key: 'trial_ending', subject_id: s.id, cycle_key: String(s.trial_ends_at).slice(0, 10), s })
        if (s.status === 'active' && s.trial_status === 'converted' && s.next_billing_at && s.next_billing_at >= today && s.next_billing_at <= addDays(today, renewalDays))
          triples.push({ event_key: 'membership_renewal_reminder', subject_id: s.id, cycle_key: String(s.next_billing_at).slice(0, 10), s })
      }
      const sent = await loadSentKeys(triples)
      for (const t of triples) {
        if (sent.has(`${t.event_key}|${t.subject_id}|${t.cycle_key}`)) continue
        const program = progMap.get(t.s.program_id); if (!program) continue
        if (t.event_key === 'trial_ending') notifyTrialEnding({ ownerId, sub: t.s, program })
        else notifyRenewalReminder({ ownerId, sub: t.s, program })
        await markSent(t.event_key, 'subscription', t.s.id, t.cycle_key)
      }
    }
  } catch { /* never break the dashboard */ }
}
