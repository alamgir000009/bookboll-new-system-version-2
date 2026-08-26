/**
 * Laravel-backed booking adapter for ListingDetail.vue.
 * Same function names as booking.js for the I/O that hits a database;
 * quoting / payment-plan math stay in booking.js.
 */
import { apiFetch, getCustomerToken, setCustomerToken } from '../lib/api.js'
import { quoteBooking } from './booking.js'

const PAY_MAP = {
  cash: 'cash_on_arrival',
  deposit: 'deposit_remaining_on_arrival',
  online: 'online_payment',
}

export function holdToken() {
  try {
    let t = localStorage.getItem('bb_hold_token')
    if (!t) {
      t = (window.crypto?.randomUUID?.() || ('h' + Date.now() + Math.random().toString(36).slice(2)))
      localStorage.setItem('bb_hold_token', t)
    }
    return t
  } catch { return 'anon-device' }
}

let _holdMinutes = null
export async function holdMinutes() {
  if (_holdMinutes != null) return _holdMinutes
  _holdMinutes = 10
  return _holdMinutes
}

function hm(t) {
  return String(t || '').slice(0, 5)
}

function mapSlot(s) {
  const available = !!s.available
  return {
    start_time: hm(s.start_time),
    end_time: hm(s.end_time),
    price: Number(s.price) || 0,
    available,
    booked: !available,
    past: false,
    spacesLeft: Number(s.available_count ?? (available ? 1 : 0)),
    capacity: Number(s.max_bookings_per_slot || s.capacity || 1),
  }
}

export async function loadDayAvailability(listing, date) {
  const res = await apiFetch('/fetch-time-slots', { query: { listing_id: listing.id, date } })
  const raw = Array.isArray(res.data?.timeSlots) ? res.data.timeSlots : []
  const slots = raw.map(mapSlot)
  const msg = res.message || ''
  const holiday = /holiday/i.test(msg)
  const none = !slots.length
  if (listing?.holdMinutes) _holdMinutes = Math.max(1, Number(listing.holdMinutes) || 10)
  return {
    slots,
    closed: none && (holiday || /no available/i.test(msg) || !res.success),
    closedName: holiday ? 'Holiday' : (none ? 'Closed' : ''),
    special: false,
  }
}

export function dayScheduleInfo(_listing, _date, avail) {
  if (avail?.closed) return { closed: true, name: avail.closedName || 'Closed', windows: [] }
  if (avail?.special) return { closed: false, special: true, name: avail.closedName || 'Special schedule', windows: [{}] }
  return { closed: false, windows: [{}] }
}

export function buildDaySlots(_listing, _date, avail) {
  return avail?.slots || []
}

export async function loadMonthAvailability(listing, fromISO, toISO) {
  const slotsByDate = {}
  const from = new Date(fromISO + 'T00:00:00')
  const to = new Date(toISO + 'T00:00:00')
  const today = new Date(); today.setHours(0, 0, 0, 0)
  const days = []
  for (let d = new Date(from); d <= to; d.setDate(d.getDate() + 1)) {
    if (d < today) continue
    const z = n => String(n).padStart(2, '0')
    days.push(`${d.getFullYear()}-${z(d.getMonth() + 1)}-${z(d.getDate())}`)
  }
  const chunk = 7
  for (let i = 0; i < days.length; i += chunk) {
    const slice = days.slice(i, i + chunk)
    const results = await Promise.all(slice.map(date => loadDayAvailability(listing, date)))
    slice.forEach((date, idx) => { slotsByDate[date] = results[idx]?.slots || [] })
  }
  return { slotsByDate }
}

export function cheapestAvailableForDay(_listing, date, month) {
  const slots = month?.slotsByDate?.[date] || []
  let min = Infinity
  for (const s of slots) {
    if (s.available && Number(s.price) < min) min = Number(s.price)
  }
  return min === Infinity ? null : min
}

export async function holdSlots(listing, date, slots) {
  const list = (slots || []).map(s => ({ start_time: hm(s.start_time), end_time: hm(s.end_time) }))
  if (!list.length) return { ok: true }
  let earliest = null
  for (const s of list) {
    const res = await apiFetch('/lock-slot', {
      method: 'POST',
      body: { listing_id: listing.id, date, start_time: s.start_time, end_time: s.end_time },
    })
    if (!res.success) return { ok: false, start_time: s.start_time, reason: res.message }
    const until = res.data?.locked_until
    if (until) {
      const t = new Date(until).getTime()
      earliest = earliest ? Math.min(earliest, t) : t
    }
  }
  return { ok: true, expiresAt: earliest ? new Date(earliest).toISOString() : null }
}

export async function releaseHolds(listing, date = null) {
  await apiFetch('/unlock-all-slots', {
    method: 'POST',
    body: { listing_id: listing?.id || undefined, date: date || undefined },
  })
}

export async function resolveFacilityCommission(listing) {
  const n = Number(listing?.commission_rate)
  return Number.isFinite(n) && n > 0 ? n : 15
}

function guestPwKey(email) {
  return 'bb_guest_pw:' + String(email || '').trim().toLowerCase()
}

async function ensureLaravelCustomer(customer) {
  if (getCustomerToken()) return { ok: true }
  const email = String(customer?.email || '').trim()
  const name = String(customer?.full_name || customer?.name || 'Guest').trim() || 'Guest'
  const phone = String(customer?.phone || '').replace(/\D/g, '') || '00000000'
  if (!email) return { error: 'Please add your email to book.' }

  const stored = (() => { try { return localStorage.getItem(guestPwKey(email)) || '' } catch { return '' } })()
  if (stored) {
    const login = await apiFetch('/customer/login', { method: 'POST', body: { email, password: stored } })
    if (login.success && login.data?.token) {
      setCustomerToken(login.data.token)
      return { ok: true, user: login.data.user }
    }
  }

  const password = (window.crypto?.randomUUID?.() || (Date.now() + 'Aa1xxxxx')).replace(/-/g, '').slice(0, 12) + 'Aa1'
  const reg = await apiFetch('/register', {
    method: 'POST',
    body: { name, email, phone, password, password_confirmation: password },
  })
  if (reg.success && reg.data?.token) {
    try { localStorage.setItem(guestPwKey(email), password) } catch { /* ignore */ }
    setCustomerToken(reg.data.token)
    return { ok: true, user: reg.data.user }
  }

  if (stored === '' && /already exists/i.test(reg.message || '')) {
    return { error: 'An account with this email already exists. Please sign in to complete booking.' }
  }
  return { error: reg.message || 'Could not start checkout.' }
}

function bookingPayload({ listing, location, selectionByDate, addons, players, customer, payment }) {
  const dates = Object.keys(selectionByDate || {}).filter(d => (selectionByDate[d] || []).length).sort()
  if (!dates.length) return { error: 'Please pick at least one time slot.' }

  const blocks = []
  for (const d of dates) {
    const q = quoteBooking(listing, selectionByDate[d], [], players)
    for (const b of q.blocks) {
      blocks.push({
        date: d,
        start_time: hm(b.start),
        end_time: hm(b.end),
        price: Number(b.total) || 0,
        quantity: Math.max(1, players || 1),
      })
    }
  }
  const first = blocks[0]
  const rest = blocks.slice(1)
  const addonIds = (addons || []).filter(a => a.qty > 0).flatMap(a => Array(a.qty).fill(a.id))

  return {
    venue_id: location?.id || listing.venue_id || listing.location_id,
    listing_id: listing.id,
    date: first.date,
    start_time: first.start_time,
    end_time: first.end_time,
    payment_method: PAY_MAP[payment?.method] || 'cash_on_arrival',
    quantity: first.quantity,
    add_ons: addonIds.length ? addonIds : undefined,
    phone: customer?.phone || undefined,
    notes: undefined,
    promocode: payment?.promocode || undefined,
    recurring_bookings: rest.length
      ? rest.map(r => ({
          date: r.date,
          start_time: r.start_time,
          end_time: r.end_time,
          price: r.price,
          quantity: r.quantity,
          add_ons: addonIds.length ? addonIds : undefined,
        }))
      : undefined,
  }
}

export async function createCustomerBookingsMulti({
  listing, location, selectionByDate, addons = [], players = 1, customer,
  payment = { method: 'cash' },
}) {
  const auth = await ensureLaravelCustomer(customer)
  if (auth.error) return { error: auth.error }

  const payload = bookingPayload({ listing, location, selectionByDate, addons, players, customer, payment })
  if (payload.error) return payload

  const method = payload.payment_method
  if (method === 'online_payment' || method === 'deposit_remaining_on_arrival') {
    const res = await apiFetch('/customer/payments/create-checkout-session', { method: 'POST', body: payload })
    if (!res.success) return { error: res.message || 'Could not start payment.' }
    if (res.data?.free_booking && res.data?.bookings) {
      await releaseHolds(listing)
      return { bookings: (res.data.bookings || []).map(b => b.id || b), currency: location?.currency_override || 'AED' }
    }
    if (res.data?.url) {
      window.location.href = res.data.url
      return { redirect: true, bookings: [], currency: location?.currency_override || 'AED' }
    }
    return { error: res.message || 'Failed to create checkout session.' }
  }

  const res = await apiFetch('/customer/bookings/confirm', { method: 'POST', body: payload })
  if (!res.success) return { error: res.message || 'Could not create the booking.' }
  await releaseHolds(listing)
  const bookings = (res.data?.bookings || []).map(b => b.id || b)
  return { bookings, currency: location?.currency_override || 'AED' }
}
