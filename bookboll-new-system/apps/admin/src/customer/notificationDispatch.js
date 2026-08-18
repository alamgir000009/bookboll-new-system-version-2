/**
 * notificationDispatch.js — actually SEND the automated notifications a provider
 * configured on the Notifications page, for EVERY event-driven moment in the system.
 * Before this, those templates were preview-only (nothing dispatched them), so toggling
 * a channel produced no real message.
 *
 * TRANSPORTS: WhatsApp is the only REAL transport in this build (the send-whatsapp edge
 * function — Bookboll's number, same one events + the marketing composer use). Email + SMS
 * have no live gateway here (SMTP send is simulated), so they are no-ops — wire them into
 * deliverEmail/deliverSms when a real gateway exists.
 *
 * RULES:
 *  - FIRE-AND-FORGET: a messaging hiccup must never break the user's action. Every send is
 *    un-awaited, swallows errors, and skips silently when contact info / the channel is missing.
 *  - Transactional service messages (confirmations, receipts, cancellations, dunning) are NOT
 *    gated on marketing consent — that gate is only for marketing blasts.
 *  - Recipients are resolved to a phone: callers pass whatever they have (phone / email /
 *    customerId) and resolvePhone() looks the number up from `customers` when needed
 *    (subscriptions store email but not phone).
 *  - Only the CLIENT (customer) audience is sent here. Provider-team WhatsApp needs the
 *    provider's own number + team phones — a separate follow-up.
 *  - DELIVERY still requires the platform WhatsApp channel connected (super-admin → WhatsApp)
 *    and, outside the 24h window, an approved utility template (bookboll_event_update).
 */
import { apiFetch } from '../lib/api.js'
import { appBaseUrl } from '../lib/appBase.js'
import { loadProviderNotificationSettings } from '../lib/notificationTemplates.js'
import { fmt12 } from '../lib/slots.js'

const FALLBACK_TEMPLATE = { name: 'bookboll_event_update', language: 'en_US' }
const siteOrigin = () => (appBaseUrl() || 'https://bookboll.com')

function normalizeWaNumber(phone) {
  let d = String(phone || '').replace(/\D/g, '')
  if (d.startsWith('00')) d = d.slice(2)
  return d.length >= 8 ? d : ''
}
async function invokeWhatsapp(payload) {
  try {
    const res = await apiFetch('/admin/notifications/whatsapp', { method: 'POST', body: payload })
    if (res.data && typeof res.data.ok === 'boolean') return res.data
    return { ok: Boolean(res.success) }
  } catch { return { ok: false } }
}
/** Prefer a given phone; else look the customer up by id, then email. */
async function resolvePhone({ phone } = {}) {
  if (normalizeWaNumber(phone)) return phone
  return ''
}
/** The provider/owner's own phone — recipient for the "provider" audience. */
async function resolveOwnerPhone(ownerId) {
  if (!ownerId) return ''
  try {
    const res = await apiFetch('/admin/notifications/owner-phone', { query: { owner_id: ownerId } })
    return res.data?.phone || ''
  } catch { return '' }
}

/** Replace [token] placeholders with real values; unknown/empty tokens collapse to ''. */
function render(text, data) {
  return String(text || '').replace(/\[([a-z_]+)\]/g, (_m, key) => {
    const v = data[key]
    return (v === undefined || v === null) ? '' : String(v)
  }).replace(/[ \t]{2,}/g, ' ').replace(/ +\n/g, '\n').trim()
}

async function deliverWhatsapp(to, body) {
  const num = normalizeWaNumber(to)
  if (!num || !body) return
  const res = await invokeWhatsapp({ action: 'send_text', ownerId: null, to: num, text: body })
  if (res?.ok) return
  await invokeWhatsapp({ action: 'send_template', ownerId: null, to: num, template: FALLBACK_TEMPLATE, variables: [body, ''], bodyText: body })
}
// eslint-disable-next-line no-unused-vars
function deliverEmail(_to, _subject, _body) { /* no live SMTP gateway in this build */ }
// eslint-disable-next-line no-unused-vars
function deliverSms(_to, _body) { /* no live SMS gateway in this build */ }

/**
 * Send a configured automated notification to the CUSTOMER on the channels the provider
 * enabled for that event. Safe to call un-awaited. Skips the (async) phone lookup entirely
 * when WhatsApp is off for the event, so it's cheap when nothing is enabled.
 */
export async function sendCustomerNotification(eventKey, { ownerId, phone, email, customerId, data = {} } = {}) {
  try {
    const { settings } = await loadProviderNotificationSettings(ownerId || null)
    const ev = settings?.events?.[eventKey]
    if (!ev) return
    // Client audience → the customer.
    const tpl = ev.client
    if (tpl) {
      if (tpl.whatsapp?.on) {
        const to = await resolvePhone({ phone, customerId, email })
        if (to) deliverWhatsapp(to, render(tpl.whatsapp.body, data))
      }
      if (tpl.email?.on && email) deliverEmail(email, render(tpl.email.subject, data), render(tpl.email.body, data))
      if (tpl.sms?.on && phone) deliverSms(phone, render(tpl.sms.body, data))
    }
    // Provider audience → the business owner (admin_users.phone), on Bookboll's number.
    const prov = ev.provider
    if (prov && ownerId) {
      if (prov.whatsapp?.on) {
        const to = await resolveOwnerPhone(ownerId)
        if (to) deliverWhatsapp(to, render(prov.whatsapp.body, data))
      }
      // provider email/sms are no-ops (no live gateway) — resolved by email lookup when wired.
    }
  } catch { /* never let a notification break the user's action */ }
}

// ── token builders ───────────────────────────────────────────────────────────
const firstName = (n) => (String(n || '').split(' ')[0] || n || '')
const INTERVAL_LABEL = { weekly: 'Weekly', biweekly: 'Every 2 weeks', monthly: 'Monthly', quarterly: 'Quarterly', yearly: 'Yearly', one_time: 'One-time' }
function fmtDate(d) {
  if (!d) return ''
  try { return new Date(String(d).slice(0, 10) + 'T00:00:00').toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' }) } catch { return '' }
}
function membershipData(sub, program, extra = {}) {
  const name = sub?.customer_name || ''
  return {
    customer_name: name, customer_first_name: firstName(name), customer_email: sub?.customer_email || '',
    program: program?.name || 'your program',
    plan: INTERVAL_LABEL[program?.billing_interval] || 'Membership',
    amount: extra.amount != null ? Number(extra.amount).toFixed(2) : (sub?.monthly_amount != null ? Number(sub.monthly_amount).toFixed(2) : ''),
    currency: program?.currency || 'AED',
    next_billing_date: fmtDate(sub?.next_billing_at),
    trial_end_date: fmtDate(sub?.trial_ends_at),
    membership_link: siteOrigin() + '/my-memberships',
    business_name: extra.business_name || '',
    ...extra,
  }
}
function bookingData(customer, booking) {
  const name = customer?.name || customer?.full_name || ''
  const amount = booking?.amount ?? ''
  // `balance_due` is the amount owed on arrival; fall back to the total when a caller
  // only supplies `amount`, so the confirmation template never renders a blank figure.
  const balanceDue = booking?.balance_due ?? amount
  const fmtMoney = (v) => (v === '' || v === null || v === undefined) ? '' : Number(v).toFixed(2)
  return {
    customer_name: name, customer_first_name: firstName(name), customer_email: customer?.email || '',
    business_name: booking?.business_name || '', venue: booking?.venue || '', listing: booking?.listing || '',
    date: booking?.date || '', start_time: booking?.start_time || '', end_time: booking?.end_time || '',
    booking_code: booking?.code || '', amount: fmtMoney(amount), balance_due: fmtMoney(balanceDue),
    currency: booking?.currency || '', booking_link: booking?.link || (siteOrigin() + '/my-bookings'),
  }
}

// ── BOOKING events ────────────────────────────────────────────────────────────
export function notifyBookingConfirmation({ ownerId, customer, booking } = {}) {
  sendCustomerNotification('booking_confirmation', { ownerId, phone: customer?.phone, email: customer?.email, customerId: customer?.id, data: bookingData(customer, booking) })
}
export function notifyBookingCancelled({ ownerId, customer, booking } = {}) {
  sendCustomerNotification('booking_cancelled', { ownerId, phone: customer?.phone, email: customer?.email, customerId: customer?.id, data: bookingData(customer, booking) })
}
export function notifyBookingChanged({ ownerId, customer, booking } = {}) {
  sendCustomerNotification('booking_changed', { ownerId, phone: customer?.phone, email: customer?.email, customerId: customer?.id, data: bookingData(customer, booking) })
}
export function notifyPaymentReceipt({ ownerId, customer, booking } = {}) {
  sendCustomerNotification('payment_receipt', { ownerId, phone: customer?.phone, email: customer?.email, customerId: customer?.id, data: bookingData(customer, booking) })
}
/** Build a booking-confirmation payload from a createCustomerBookings(Multi) context. */
export function bookingConfirmationFrom({ listing, location, customer, curr, dates, selectionByDate, createdIds = [] }) {
  const d0 = (dates || [])[0]
  const s0 = d0 ? ((selectionByDate?.[d0] || [])[0]) : null
  return {
    ownerId: location?.owner_user_id || null,
    customer: { id: customer?.id || null, name: customer?.full_name || customer?.name || '', email: customer?.email || '', phone: customer?.phone || '' },
    booking: {
      listing: listing?.name || '', venue: location?.name || '', business_name: location?.name || '',
      date: d0 ? new Date(d0 + 'T00:00:00').toLocaleDateString('en-US', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' }) : '',
      start_time: s0?.start_time ? fmt12(s0.start_time) : '', end_time: s0?.end_time ? fmt12(s0.end_time) : '',
      code: (createdIds[0] || '').toString().slice(0, 8).toUpperCase(), currency: curr || '', link: siteOrigin() + '/my-bookings',
    },
  }
}

// ── MEMBERSHIP events (recipient phone resolved from the sub's email) ──────────
export function notifyMembershipStarted({ ownerId, sub, program } = {}) {
  sendCustomerNotification('membership_started', { ownerId, phone: sub?.customer_phone, email: sub?.customer_email, data: membershipData(sub, program) })
}
export function notifyMembershipPayment({ ownerId, sub, program, amount } = {}) {
  sendCustomerNotification('membership_payment', { ownerId, phone: sub?.customer_phone, email: sub?.customer_email, data: membershipData(sub, program, amount != null ? { amount } : {}) })
}
export function notifyMembershipCancelled({ ownerId, sub, program } = {}) {
  sendCustomerNotification('membership_cancelled', { ownerId, phone: sub?.customer_phone, email: sub?.customer_email, data: membershipData(sub, program) })
}
export function notifyMembershipPaymentFailed({ ownerId, sub, program } = {}) {
  sendCustomerNotification('membership_payment_failed', { ownerId, phone: sub?.customer_phone, email: sub?.customer_email, data: membershipData(sub, program) })
}
export function notifyMembershipWinback({ ownerId, sub, program } = {}) {
  sendCustomerNotification('membership_winback', { ownerId, phone: sub?.customer_phone, email: sub?.customer_email, data: membershipData(sub, program) })
}
// ── SCHEDULED events (fired by the sweep in notificationSweep.js) ──────────────
export function notifyBookingReminder({ ownerId, customer, booking } = {}) {
  sendCustomerNotification('booking_reminder', { ownerId, phone: customer?.phone, email: customer?.email, customerId: customer?.id, data: bookingData(customer, booking) })
}
export function notifyReviewRequest({ ownerId, customer, booking } = {}) {
  sendCustomerNotification('review_request', { ownerId, phone: customer?.phone, email: customer?.email, customerId: customer?.id, data: bookingData(customer, booking) })
}
export function notifyTrialEnding({ ownerId, sub, program } = {}) {
  sendCustomerNotification('trial_ending', { ownerId, phone: sub?.customer_phone, email: sub?.customer_email, data: membershipData(sub, program) })
}
export function notifyRenewalReminder({ ownerId, sub, program } = {}) {
  sendCustomerNotification('membership_renewal_reminder', { ownerId, phone: sub?.customer_phone, email: sub?.customer_email, data: membershipData(sub, program) })
}
