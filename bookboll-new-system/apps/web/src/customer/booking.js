/**
 * booking.js — customer-side facility booking, reusing the SAME engine as the
 * admin dashboard: slot generation/conflicts from lib/slots.js, the pricing-cell →
 * pricing-group → base_price resolution, and the bookings/booking_payments/
 * booking_addons tables. Customer web bookings are created with origin
 * 'bookboll_web' (a recognised admin origin) and payment pending (pay at venue).
 */
import { supabase } from '../lib/supabase.js'
import { generateSlots, toMinutes } from '../lib/slots.js'
import { fetchSpecialDays, dayWindows, slotsForWindows, applySpecialDayPrice } from '../lib/specialDays.js'
import { recordBookingOnlinePayment } from '../lib/bookingPayment.js'
import { notifyBookingConfirmation, bookingConfirmationFrom } from './notificationDispatch.js'

// Effective Bookboll commission % for a facility booking:
// listing override → the location's country facility rate → 15% (platform default).
export async function resolveFacilityCommission(listing, location) {
  if (listing?.commission_rate != null && listing.commission_rate !== '') return Number(listing.commission_rate)
  let countryId = location?.country_id || null
  if (!countryId && location?.city_id) {
    const { data: city } = await supabase.from('cities').select('country_id').eq('id', location.city_id).maybeSingle()
    countryId = city?.country_id || null
  }
  if (countryId) {
    const { data: cr } = await supabase.from('commission_rates')
      .select('rate_percent').eq('country_id', countryId).eq('listing_type', 'facility').maybeSingle()
    if (cr?.rate_percent != null) return Number(cr.rate_percent)
  }
  return 15
}

// The country (id) a location belongs to (location → city → country). Used to route
// the full-online payout to the provider's wallet for THAT country.
async function resolveLocationCountryId(location) {
  if (location?.country_id) return location.country_id
  if (location?.city_id) {
    const { data: city } = await supabase.from('cities').select('country_id').eq('id', location.city_id).maybeSingle()
    return city?.country_id || null
  }
  return null
}

function ymd(d) { const z = n => String(n).padStart(2, '0'); return `${d.getFullYear()}-${z(d.getMonth() + 1)}-${z(d.getDate())}` }

// How many of `bookings` overlap the slot window (buffer widens each booking).
function overlapCount(bookings, slot, buffer = 0) {
  const s = toMinutes(slot.start_time), e = toMinutes(slot.end_time)
  return (bookings || []).reduce((n, b) => {
    const bs = toMinutes(b.start_time) - buffer, be = toMinutes(b.end_time) + buffer
    return n + (!(e <= bs || s >= be) ? 1 : 0)
  }, 0)
}

const PER_SPACE = new Set(['per_space', 'court'])
export function isPerPerson(listing) {
  const t = listing?.listing_type
  return !!t && !PER_SPACE.has(t)
}

// ── Slot holds ("reserved while you check out") ─────────────────────────────────
// A hold temporarily reserves a slot for THIS device so other users can't grab it,
// and the atomic RPC guarantees capacity is never exceeded. Everything degrades
// gracefully: if the bb_hold_* functions / slot_holds table aren't deployed yet,
// holds are skipped and booking behaves exactly as before.

// Stable per-device token used as the hold owner (works for guests, not just signed-in).
export function holdToken() {
  try {
    let t = localStorage.getItem('bb_hold_token')
    if (!t) { t = (window.crypto?.randomUUID?.() || ('h' + Date.now() + Math.random().toString(36).slice(2))); localStorage.setItem('bb_hold_token', t) }
    return t
  } catch { return 'anon-device' }
}

// True only when the holds feature isn't deployed yet (function/table absent), so we can
// safely fall back to no-holds. A real runtime error inside the function (e.g. a bad cast)
// must NOT be swallowed here — it should surface so the slot isn't silently left unheld.
function isMissingFnOrTable(error) {
  const m = `${error?.message || ''} ${error?.details || ''} ${error?.hint || ''}`
  return error?.code === 'PGRST202' || error?.code === 'PGRST205' ||
    /could not find the (function|table)|schema cache/i.test(m)
}

// The checkout hold/countdown length is a single platform-wide setting controlled by
// the superadmin (Settings → platform_settings.booking_hold_minutes). Cached after first read.
let _holdMinutes = null
export async function holdMinutes() {
  if (_holdMinutes != null) return _holdMinutes
  try {
    const { data } = await supabase.from('platform_settings').select('value').eq('key', 'booking_hold_minutes').maybeSingle()
    _holdMinutes = Math.max(1, Number(data?.value) || 10)
  } catch { _holdMinutes = 10 }
  return _holdMinutes
}

/**
 * Atomically reserve `slots` on `date` for this device, for the platform hold window.
 * Returns { ok, expiresAt, degraded } — degraded:true means holds aren't deployed and
 * booking should proceed without them. { ok:false, start_time } = a slot was just taken.
 */
export async function holdSlots(listing, date, slots) {
  const payload = (slots || []).map(s => ({ start_time: s.start_time, end_time: s.end_time }))
  if (!payload.length) return { ok: true }
  const { data, error } = await supabase.rpc('bb_hold_slots', {
    p_listing_id: listing.id, p_date: date, p_slots: payload,
    p_held_by: holdToken(), p_minutes: await holdMinutes(),
  })
  // Holds are a best-effort enhancement. A genuine capacity conflict is returned as
  // data.ok===false (handled below), so ANY hard RPC error here means the hold system
  // itself is unavailable/broken — degrade to no-holds rather than blocking ALL booking.
  // (createCustomerBookingsMulti still re-checks availability before committing.)
  if (error) {
    if (!isMissingFnOrTable(error)) console.warn('[holds] bb_hold_slots failed, booking without holds:', error.message)
    return { ok: true, degraded: true }
  }
  if (data && data.ok === false) return { ok: false, reason: data.reason || 'slot_taken', start_time: data.start_time }
  return { ok: true, expiresAt: data?.expires_at || null }
}

/** Release this device's holds (after booking, or when the customer abandons checkout). */
export async function releaseHolds(listing, date = null) {
  try {
    await supabase.rpc('bb_release_holds', { p_held_by: holdToken(), p_listing_id: listing?.id || null, p_date: date })
  } catch { /* best-effort; holds expire on their own anyway */ }
}

// Sum of active holds overlapping a slot window (no buffer — raw capacity).
function holdOverlap(holds, slot) {
  const s = toMinutes(slot.start_time), e = toMinutes(slot.end_time)
  return (holds || []).reduce((n, h) => n + ((toMinutes(h.start_time) < e && toMinutes(h.end_time) > s) ? (Number(h.qty) || 1) : 0), 0)
}

// The resource graph for a listing: which resources it consumes, each resource's stock,
// every listing connected via a shared resource, and the listing's pricing rules.
// Date-independent — shared by the single-day and month-range availability loaders.
async function loadResourceGraph(listing) {
  const listingId = listing.id
  // Weekly schedule rows ride along here (date-independent, like pricing):
  // buildDaySlots resolves them per date via dayWindows().
  const [lr, lh] = await Promise.all([
    supabase.from('listing_resources').select('resource_id, quantity').eq('listing_id', listingId),
    supabase.from('listing_hours').select('day_of_week, is_open, open_time, close_time').eq('listing_id', listingId),
  ])
  const hours = lh.error ? [] : (lh.data || [])
  const myResources = lr.error ? [] : (lr.data || [])
  const resIds = myResources.map(r => r.resource_id)

  const resourceTotals = {}      // resourceId -> total units in stock
  const resourceListings = {}    // resourceId -> [{ listing_id, quantity(perBooking) }]
  const connected = new Set([listingId])
  if (resIds.length) {
    const [rRes, allLr] = await Promise.all([
      supabase.from('resources').select('id, quantity, status').in('id', resIds),
      supabase.from('listing_resources').select('listing_id, resource_id, quantity').in('resource_id', resIds),
    ])
    ;(rRes.data || []).forEach(r => { if ((r.status || 'active') !== 'inactive') resourceTotals[r.id] = Number(r.quantity) || 0 })
    ;(allLr.data || []).forEach(x => {
      (resourceListings[x.resource_id] ||= []).push({ listing_id: x.listing_id, quantity: Number(x.quantity) || 1 })
      connected.add(x.listing_id)
    })
  }
  const [c, g] = await Promise.all([
    supabase.from('pricing_cells').select('*').eq('listing_id', listingId),
    supabase.from('pricing_groups').select('*').eq('listing_id', listingId),
  ])
  return { connected, myResources, resourceTotals, resourceListings, cells: c.data || [], groups: g.data || [], hours }
}

// Everything needed to compute a day's slot capacity: bookings on this listing AND
// on every listing that shares one of its resources, the listing's pricing rules,
// and the resource graph (per-booking need + each resource's total stock).
export async function loadDayAvailability(listing, date) {
  const listingId = listing.id
  const graph = await loadResourceGraph(listing)

  // Bookings for the day across this listing + all resource-sharing listings.
  const [b, h, sd] = await Promise.all([
    supabase.from('bookings').select('listing_id, start_time, end_time')
      .in('listing_id', [...graph.connected]).eq('booking_date', date).neq('booking_status', 'cancelled'),
    // Active holds on THIS listing (others' in-progress checkouts). Empty pre-migration.
    supabase.from('slot_holds').select('listing_id, start_time, end_time, qty, held_by')
      .eq('listing_id', listingId).eq('booking_date', date).gt('expires_at', new Date().toISOString()),
    // Special days for the venue on this date (closures / replacement hours).
    fetchSpecialDays({ locationId: listing.location_id, date }),
  ])

  return {
    bookings: b.error ? [] : (b.data || []),
    cells: graph.cells, groups: graph.groups,
    holds: h.error ? [] : (h.data || []),
    hours: graph.hours, specials: sd,
    myResources: graph.myResources, resourceTotals: graph.resourceTotals, resourceListings: graph.resourceListings,
  }
}

// Availability across a whole date range in one shot — bookings + holds grouped by date,
// reusing the same resource graph. Used to label every calendar day with its cheapest
// AVAILABLE slot price (so booked-out cheap slots don't mislead).
export async function loadMonthAvailability(listing, fromISO, toISO) {
  const listingId = listing.id
  const graph = await loadResourceGraph(listing)
  const [b, h, sd] = await Promise.all([
    supabase.from('bookings').select('listing_id, start_time, end_time, booking_date')
      .in('listing_id', [...graph.connected]).gte('booking_date', fromISO).lte('booking_date', toISO).neq('booking_status', 'cancelled'),
    supabase.from('slot_holds').select('listing_id, start_time, end_time, qty, held_by, booking_date')
      .eq('listing_id', listingId).gte('booking_date', fromISO).lte('booking_date', toISO).gt('expires_at', new Date().toISOString()),
    fetchSpecialDays({ locationId: listing.location_id, from: fromISO, to: toISO }),
  ])
  const bookingsByDate = {}; (b.error ? [] : (b.data || [])).forEach(r => { (bookingsByDate[r.booking_date] ||= []).push(r) })
  const holdsByDate = {}; (h.error ? [] : (h.data || [])).forEach(r => { (holdsByDate[r.booking_date] ||= []).push(r) })
  return { ...graph, bookingsByDate, holdsByDate, specials: sd }
}

// Cheapest AVAILABLE slot price on `date` from pre-loaded month data, or null if the day
// is fully booked / has no open future slots. Reuses buildDaySlots for identical logic.
export function cheapestAvailableForDay(listing, date, month, heldBy = null) {
  if (!month) return null
  const avail = {
    bookings: month.bookingsByDate[date] || [],
    holds: month.holdsByDate[date] || [],
    cells: month.cells, groups: month.groups,
    hours: month.hours, specials: month.specials,
    myResources: month.myResources, resourceTotals: month.resourceTotals, resourceListings: month.resourceListings,
  }
  let min = Infinity
  for (const s of buildDaySlots(listing, date, avail, heldBy)) {
    if (s.available && Number(s.price) < min) min = Number(s.price)
  }
  return min === Infinity ? null : min
}

// Price for a given slot start, mirroring the admin's resolveSlotPrice().
export function slotPriceFor(listing, date, startTime, cells, groups) {
  const base = Number(listing?.base_price) || 0
  if (!date) return base
  const dow = new Date(date + 'T00:00:00').getDay()
  const cell = (cells || []).find(c => c.day_of_week === dow && c.slot_time === startTime)
  if (cell) {
    if (cell.direct_price != null) return Number(cell.direct_price)
    if (cell.pricing_group_id) {
      const grp = (groups || []).find(g => g.id === cell.pricing_group_id)
      if (grp) return Number(grp.price)
    }
  }
  return base
}

// The day's schedule as the customer experiences it: special-day override →
// weekly listing_hours → the legacy 06:00–23:00 default. Exposed so pages can
// show "Closed — Eid Al-Fitr" instead of a generic "no times" message.
export function dayScheduleInfo(listing, date, avail) {
  return dayWindows({ date, listing, hoursRows: avail?.hours, specialRows: avail?.specials })
}

// Decorate every generated slot with price, spaces-left and availability.
// spacesLeft = min( listing's max_bookings_per_slot − its own bookings,
//                   for each required resource: floor(stock left across all its
//                   listings / this listing's per-booking need) ).
export function buildDaySlots(listing, date, avail, heldBy = null) {
  const now = new Date()
  const isToday = date === ymd(now)
  const nowMins = now.getHours() * 60 + now.getMinutes()
  const buffer = Number(listing?.buffer_time) || 0
  const maxPerSlot = Math.max(1, Number(listing?.max_bookings_per_slot) || 1)
  const myId = listing.id
  const myBookings = (avail.bookings || []).filter(b => b.listing_id === myId)
  // Live holds on this listing reserve capacity too — but ignore this device's own
  // holds so the customer still sees the slots they're holding as selectable.
  const otherHolds = (avail.holds || []).filter(h => h.held_by !== heldBy)

  // Special days override the weekly schedule — and may carry an optional
  // pricing override (flat price or multiplier) applied per slot below.
  const sched = dayScheduleInfo(listing, date, avail)
  if (sched.closed) return []

  return slotsForWindows(listing, sched.windows).map(s => {
    let spacesLeft = maxPerSlot - overlapCount(myBookings, s, buffer) - holdOverlap(otherHolds, s)
    let capacity = maxPerSlot

    for (const mr of (avail.myResources || [])) {
      const perBooking = Number(mr.quantity) || 1
      const total = avail.resourceTotals[mr.resource_id] || 0
      let usedUnits = 0
      for (const cl of (avail.resourceListings[mr.resource_id] || [])) {
        const clBookings = (avail.bookings || []).filter(b => b.listing_id === cl.listing_id)
        usedUnits += overlapCount(clBookings, s) * (Number(cl.quantity) || 1)
      }
      spacesLeft = Math.min(spacesLeft, Math.floor((total - usedUnits) / perBooking))
      capacity = Math.min(capacity, Math.floor(total / perBooking))
    }

    spacesLeft = Math.max(0, spacesLeft)
    const past = isToday && toMinutes(s.start_time) <= nowMins
    const available = spacesLeft > 0 && !past
    return {
      start_time: s.start_time, end_time: s.end_time,
      price: applySpecialDayPrice(slotPriceFor(listing, date, s.start_time, avail.cells, avail.groups), sched),
      spacesLeft, capacity, past, available, booked: !available && !past,
    }
  })
}

// The next `count` days as { date, weekday, day, label } for the day picker.
export function upcomingDays(count = 14) {
  const out = []
  const base = new Date()
  for (let i = 0; i < count; i++) {
    const d = new Date(base); d.setDate(base.getDate() + i)
    out.push({
      date: ymd(d),
      weekday: d.toLocaleDateString('en-US', { weekday: 'short' }),
      day: d.getDate(),
      month: d.toLocaleDateString('en-US', { month: 'short' }),
      label: i === 0 ? 'Today' : i === 1 ? 'Tomorrow' : '',
    })
  }
  return out
}

// ── Extensions + multi-slot quoting ────────────────────────────────────────────

// Price of an *extra* (contiguous) slot after the first one in a block.
export function extensionSlotPrice(listing, fullPrice) {
  const mode = listing?.extension_pricing || 'none'
  const val = Number(listing?.extension_value) || 0
  if (mode === 'flat') return Math.max(0, val)
  if (mode === 'percent') return Math.max(0, Number(fullPrice) * (1 - val / 100))
  return Number(fullPrice) || 0   // 'none' → extra slots at full price
}
export function extensionsEnabled(listing) {
  return (listing?.extension_pricing === 'percent' || listing?.extension_pricing === 'flat') && Number(listing?.extension_value) > 0
}

// How many adjacent slots AFTER the first in a contiguous run may be discounted as
// extensions (default 1). e.g. 1 → 6pm full, 7pm discounted, 8pm back to full price.
export function extensionMaxSlots(listing) {
  return Math.max(1, Math.floor(Number(listing?.extension_max_slots) || 1))
}
// Within a contiguous run, is the slot at 0-based index `i` a discounted extension?
// Index 0 is the base (full price); indices 1..maxSlots are discounted; beyond that full price.
export function isExtensionIndex(listing, i, maxSlots = extensionMaxSlots(listing)) {
  return i >= 1 && i <= maxSlots
}

/**
 * The next slot the customer can be *offered* as a discounted extension: the
 * available slot immediately after the end of their current selection. Returns
 * null if extensions are off, nothing's selected, or there's no free next slot.
 */
export function extensionOffer(listing, daySlots, selectedSlots) {
  if (!extensionsEnabled(listing) || !selectedSlots || !selectedSlots.length) return null
  const sorted = [...selectedSlots].sort((a, b) => (a.start_time < b.start_time ? -1 : 1))
  const frontier = sorted[sorted.length - 1].end_time   // latest end time
  // Length of the trailing contiguous run ending at the frontier. The next slot would
  // sit at index = chainLen; only offer it discounted while that stays within the cap.
  let chainLen = 1
  for (let i = sorted.length - 1; i > 0; i--) {
    if (sorted[i - 1].end_time === sorted[i].start_time) chainLen++
    else break
  }
  if (chainLen > extensionMaxSlots(listing)) return null
  const selectedKeys = new Set(selectedSlots.map(s => s.start_time))
  const next = (daySlots || []).find(s => s.start_time === frontier && s.available && !selectedKeys.has(s.start_time))
  if (!next) return null
  const full = Number(next.price) || 0
  const ext = extensionSlotPrice(listing, full)
  return { slot: { start_time: next.start_time, end_time: next.end_time, price: next.price }, fullPrice: full, extPrice: ext, savings: Math.max(0, full - ext), savePct: full > 0 ? Math.round((1 - ext / full) * 100) : 0 }
}

// Group time-sorted slots into contiguous blocks (prev.end === next.start).
function groupBlocks(sortedSlots) {
  const blocks = []
  for (const s of sortedSlots) {
    const last = blocks[blocks.length - 1]
    if (last && last[last.length - 1].end_time === s.start_time) last.push(s)
    else blocks.push([s])
  }
  return blocks
}

// Add-ons are simple products: line charge = quantity × price, charged once for the whole
// booking (no per-hour / per-slot scaling). Extra args are ignored (legacy callers).
export function addonAmount(addon, qty) {
  return (qty || 0) * Number(addon.price || 0)
}

// ── Payment model ──────────────────────────────────────────────────────────────
// Bookboll-owned venue (location has no owner_user_id) → full ONLINE payment (all the
// money is Bookboll's). Provider venue → DEPOSIT (the platform fee, paid online) +
// remaining paid to the provider ON ARRIVAL. A Bookboll admin may also switch a
// provider listing to CASH (all on arrival) via cash_on_arrival.
/**
 * Resolve the payment options that apply for the current SURFACE.
 *  - 'marketplace' (default): the listing's standard options, unchanged.
 *  - 'site' (provider's own white-label site): the provider-customizable `site_*`
 *    options. Full online is only offered when the provider has a connected payout
 *    method (`providerHasPayout`); otherwise that method is hidden.
 * Returns an object shaped like a listing_payment_options row, so paymentPlan/checkout
 * consume it unchanged.
 */
export function effectivePaymentOptions(po, { surface = 'marketplace', providerHasPayout = false } = {}) {
  if (surface !== 'site') return po
  // No saved options row yet → the provider-site defaults (10% deposit + cash;
  // full-online needs a payout method anyway).
  if (!po) return { deposit_enabled: true, online_payment_enabled: false, cash_on_arrival: true, deposit_percentage: 10, deposit_amount: 0 }
  return {
    deposit_enabled: !!po.site_deposit_enabled,
    online_payment_enabled: !!po.site_online_enabled && !!providerHasPayout,
    cash_on_arrival: !!po.site_cash_enabled,
    deposit_percentage: Number(po.site_deposit_percentage) || 0,
    deposit_amount: 0,
  }
}

export function paymentPlan(location, paymentOptions) {
  const bookbollOwned = !location?.owner_user_id
  const o = paymentOptions
  const depositPct = Number(o?.deposit_percentage) || 0
  const depositAmount = Number(o?.deposit_amount) || 0
  if (o) {
    // Whatever the admin enabled — any combination; the customer picks if >1.
    const methods = []
    if (o.online_payment_enabled) methods.push('online')
    if (o.deposit_enabled) methods.push('deposit')
    if (o.cash_on_arrival) methods.push('cash')
    if (methods.length) return { bookbollOwned, methods, depositPct, depositAmount }
  }
  // No options row yet → sensible default from ownership.
  return bookbollOwned
    ? { bookbollOwned, methods: ['online'], depositPct: 0, depositAmount: 0 }
    : { bookbollOwned, methods: ['deposit'], depositPct: depositPct || 20, depositAmount }
}
// Split a total into what's paid online now vs on arrival, for a chosen method.
// Deposit = fixed amount if set, else percentage (defaults to 20% if neither set).
export function paymentSplit(method, total, depositPct = 0, depositAmount = 0) {
  total = Number(total) || 0
  if (method === 'online') return { method: 'online', online: total, arrival: 0, deposit: 0, total }
  if (method === 'cash')   return { method: 'cash', online: 0, arrival: total, deposit: 0, total }
  const amt = Number(depositAmount) || 0
  const pct = Number(depositPct) || 0
  let deposit = amt > 0 ? amt : Math.round(total * (pct > 0 ? pct : 20) / 100)
  deposit = Math.max(0, Math.min(deposit, total))
  return { method: 'deposit', online: deposit, arrival: total - deposit, deposit, total }
}
// booking_payments rows, routing deposit/online → Bookboll, balance/cash → provider.
// NOTE: no real gateway yet — the online/deposit portion is SIMULATED as 'paid' so the
// end-to-end workflow can be tested. The on-arrival balance stays 'pending'.
// (When a real gateway is added, only this function + the simulated status need changing.)
function paymentRows(bookingId, split, curr, bookbollOwned, loyaltyRedeem = 0) {
  const now = new Date().toISOString()
  const onArrival = bookbollOwned ? 'bookboll' : 'provider'   // who collects the cash/balance
  // For a Bookboll-owned venue the whole amount is Bookboll's, so a loyalty redemption
  // (online only) simply reduces what Bookboll collects. Provider venues never reach
  // here for online — they go through recordBookingOnlinePayment.
  const L = Math.max(0, Number(loyaltyRedeem) || 0)
  if (split.method === 'online') return [{ booking_id: bookingId, payment_type: 'full', method: 'online', amount: +(split.total - L).toFixed(2), currency: curr, recipient: 'bookboll', status: 'paid', paid_at: now }]
  if (split.method === 'cash')   return [{ booking_id: bookingId, payment_type: 'full', method: 'cash', amount: split.total, currency: curr, recipient: onArrival, status: 'pending', paid_at: null }]
  const rows = []
  // Deposit → Bookboll: loyalty (funded by Bookboll) reduces what's charged online.
  // If points cover the whole deposit, nothing is charged today (no deposit row).
  const depNet = +Math.max(0, split.deposit - L).toFixed(2)
  if (depNet > 0) rows.push({ booking_id: bookingId, payment_type: 'deposit', method: 'online', amount: depNet, currency: curr, recipient: 'bookboll', status: 'paid', paid_at: now })
  if (split.arrival > 0) rows.push({ booking_id: bookingId, payment_type: 'balance', method: 'cash', amount: split.arrival, currency: curr, recipient: onArrival, status: 'pending', paid_at: null })
  return rows
}
const PAY_NOTE = {
  online: 'Booked online via Bookboll — paid online.',
  cash: 'Booked online via Bookboll — pay at the venue.',
  deposit: 'Booked online via Bookboll — deposit online, balance on arrival.',
}

/**
 * Price a whole selection: groups slots into contiguous blocks (each block's first
 * slot full price, the rest discounted as extensions), multiplies per-person slots
 * by `players`, and prices add-ons by their charge_unit.
 */
export function quoteBooking(listing, selectedSlots, addonsSelected = [], players = 1) {
  const slots = [...(selectedSlots || [])].sort((a, b) => (a.start_time < b.start_time ? -1 : 1))
  const perPerson = isPerPerson(listing)
  const ppl = perPerson ? Math.max(1, players) : 1
  const maxExt = extensionMaxSlots(listing)
  const blocks = groupBlocks(slots).map(block => {
    let total = 0
    // Index 0 = base (full); 1..maxExt = discounted extensions; beyond that back to full price.
    block.forEach((s, i) => { total += (isExtensionIndex(listing, i, maxExt) ? extensionSlotPrice(listing, s.price) : (Number(s.price) || 0)) })
    total *= ppl
    return { slots: block, start: block[0].start_time, end: block[block.length - 1].end_time, total }
  })
  const slotsTotal = blocks.reduce((n, b) => n + b.total, 0)
  const fullTotal = slots.reduce((n, s) => n + (Number(s.price) || 0), 0) * ppl   // price with no extension discount
  const slotMins = Number(listing?.slot_duration) || 60
  const totalHours = (slots.length * slotMins) / 60
  const pricedAddons = (addonsSelected || []).map(a => ({ ...a, amount: addonAmount(a, a.qty, totalHours) }))
  const addonsTotal = pricedAddons.reduce((n, a) => n + a.amount, 0)
  return { blocks, slotsTotal, addonsTotal, total: slotsTotal + addonsTotal, totalHours, totalSlots: slots.length, pricedAddons, savings: Math.max(0, fullTotal - slotsTotal) }
}

/**
 * Create bookings for a multi-slot selection. Contiguous runs become one booking
 * each (spanning the run); non-contiguous selections become separate bookings in
 * the same checkout. Add-ons are charged once and attached to the first booking.
 * Payment is pending (pay at the venue). Same tables as the admin.
 */
export async function createCustomerBookings({ listing, location, date, selectedSlots, addons = [], players = 1, customer }) {
  if (!selectedSlots || !selectedSlots.length) return { error: 'Please pick at least one time slot.' }
  const curr = location?.currency_override || 'AED'
  const recipient = !location?.owner_user_id ? 'bookboll' : 'provider'

  // Re-check every selected slot still has space (capacity + shared resources).
  const availNow = await loadDayAvailability(listing, date)
  const freshById = {}; buildDaySlots(listing, date, availNow).forEach(s => { freshById[s.start_time] = s })
  for (const s of selectedSlots) {
    const f = freshById[s.start_time]
    if (!f || !f.available) return { error: 'One of your times was just taken. Please re-check your selection.' }
  }
  // price from the fresh (authoritative) slot prices
  const fresh = selectedSlots.map(s => ({ ...s, price: freshById[s.start_time].price }))
  const quote = quoteBooking(listing, fresh, addons, players)

  const created = []
  for (let bi = 0; bi < quote.blocks.length; bi++) {
    const block = quote.blocks[bi]
    const isFirst = bi === 0
    const addonTotal = isFirst ? quote.addonsTotal : 0
    const total = block.total + addonTotal
    const { data: booking, error } = await supabase.from('bookings').insert({
      listing_id: listing.id, location_id: listing.location_id, customer_id: customer?.id || null,
      booking_date: date, start_time: block.start, end_time: block.end,
      players: isPerPerson(listing) ? Math.max(1, players) : 1,
      base_price: block.total, addon_total: addonTotal, discount: 0, total,
      amount_paid: 0, payment_method: 'cash', payment_status: 'pending', booking_status: 'confirmed',
      origin: 'bookboll_web', notes: 'Booked online via Bookboll — pay at the venue.',
    }).select('id').maybeSingle()
    if (error || !booking) return { error: error?.message || 'Could not create the booking.' }
    created.push(booking.id)
    if (total > 0) {
      await supabase.from('booking_payments').insert({
        booking_id: booking.id, payment_type: 'full', method: 'cash',
        amount: total, currency: curr, recipient, status: 'pending', paid_at: null,
      })
    }
    if (isFirst) {
      const rows = quote.pricedAddons.filter(a => a.qty > 0).map(a => ({
        booking_id: booking.id, addon_id: a.id, quantity: a.qty, unit_price: Number(a.price), subtotal: a.amount,
      }))
      if (rows.length) await supabase.from('booking_addons').insert(rows)
    }
  }
  // Fire-and-forget booking confirmation to the customer (WhatsApp live transport).
  try { notifyBookingConfirmation(bookingConfirmationFrom({ listing, location, customer, curr, dates: [date], selectionByDate: { [date]: selectedSlots }, createdIds: created })) } catch { /* non-blocking */ }
  return { bookings: created, quote, currency: curr }
}

/**
 * Create bookings across one OR MORE days in a single checkout. `selectionByDate`
 * is { 'YYYY-MM-DD': [{start_time,end_time,price}], ... }. Within each day,
 * contiguous runs become one booking (first slot full, extras discounted);
 * non-contiguous runs become separate bookings. Add-ons are charged ONCE for the
 * whole checkout (priced over total hours) and attached to the very first booking.
 */
export async function createCustomerBookingsMulti({ listing, location, selectionByDate, addons = [], players = 1, customer, payment = { method: 'cash', depositPct: 0 }, surface = 'marketplace', providerHasPayout = false }) {
  const dates = Object.keys(selectionByDate || {}).filter(d => (selectionByDate[d] || []).length).sort()
  if (!dates.length) return { error: 'Please pick at least one time slot.' }
  const curr = location?.currency_override || 'AED'
  const bookbollOwned = !location?.owner_user_id
  // On the provider's OWN website all revenue is theirs — Bookboll takes no commission;
  // it only ever holds the deposit it physically can't pay out (no payout method).
  const ownSite = surface === 'site' && !bookbollOwned
  // Channel stamp: bookings made on a provider's own white-label site are recorded
  // as provider_site so dashboards can separate them from marketplace bookings.
  const originStamp = surface === 'site' ? 'provider_site' : 'bookboll_web'
  // For marketplace provider venues paid in full online, Bookboll takes its commission and
  // the provider's share is credited to their wallet (resolved once for the checkout).
  const ratePct = (!bookbollOwned && !ownSite && payment.method === 'online')
    ? await resolveFacilityCommission(listing, location) : 0
  // The country whose wallet receives the provider's share (multi-country providers),
  // also used to tag a promo redemption with its country.
  const countryId = await resolveLocationCountryId(location)
  const customerLabel = customer?.full_name || customer?.name || customer?.email || 'customer'

  // Re-check availability for every selected slot on every day (ignoring our own holds),
  // then ATOMICALLY (re)acquire the holds — this is the real guard against double-booking
  // under concurrency. Degrades to the plain re-check if holds aren't deployed yet.
  const me = holdToken()
  const freshByDate = {}
  for (const d of dates) {
    const avail = await loadDayAvailability(listing, d)
    const map = {}; buildDaySlots(listing, d, avail, me).forEach(s => { map[s.start_time] = s })
    for (const s of selectionByDate[d]) { if (!map[s.start_time]?.available) return { error: 'One of your selected times was just taken. Please re-check.' } }
    const held = await holdSlots(listing, d, selectionByDate[d])
    if (!held.ok) return { error: 'One of your selected times was just taken. Please re-check.' }
    freshByDate[d] = map
  }

  // Add-ons priced over total booked hours across all days, charged once.
  const slotMins = Number(listing.slot_duration) || 60
  const totalSlots = dates.reduce((n, d) => n + selectionByDate[d].length, 0)
  const totalHours = (totalSlots * slotMins) / 60
  const pricedAddons = (addons || []).map(a => ({ ...a, amount: addonAmount(a, a.qty, totalHours) }))
  const addonsTotal = pricedAddons.reduce((n, a) => n + a.amount, 0)

  // Promo discount: spread across blocks in order until exhausted (reduces the charged total).
  let remainingDiscount = Math.max(0, Number(payment.discount) || 0)
  // Loyalty redemption (ONLINE or DEPOSIT): funded by Bookboll's platform fee / the online
  // deposit — spread across blocks, never exceeding each block's Bookboll-funded amount, so
  // the provider's share is untouched.
  let remainingLoyalty = (payment.method === 'online' || payment.method === 'deposit') ? Math.max(0, Number(payment.loyaltyRedeem) || 0) : 0

  const created = []
  let first = true
  for (const d of dates) {
    const fresh = selectionByDate[d].map(s => ({ ...s, price: freshByDate[d][s.start_time].price }))
    const q = quoteBooking(listing, fresh, [], players)
    for (const block of q.blocks) {
      const addonTotal = first ? addonsTotal : 0
      const gross = block.total + addonTotal
      const blockDiscount = Math.min(remainingDiscount, gross)
      remainingDiscount -= blockDiscount
      const total = gross - blockDiscount
      const split = paymentSplit(payment.method, total, payment.depositPct, payment.depositAmount)
      // How much loyalty this block can absorb — always Bookboll's money only:
      //  • online → the block's platform fee (whole amount if Bookboll-owned).
      //  • deposit → the block's online deposit (Bookboll's on the marketplace). The
      //    own-site-with-payout deposit is the PROVIDER's money, so it's excluded (that
      //    path is white-label anyway, where loyalty never applies).
      let blockLoyalty = 0
      if (remainingLoyalty > 0) {
        if (split.method === 'online') {
          const blockPlatform = bookbollOwned ? total : (Math.round(total * ratePct) / 100)
          blockLoyalty = +Math.min(remainingLoyalty, blockPlatform).toFixed(2)
        } else if (split.method === 'deposit' && !(ownSite && providerHasPayout)) {
          blockLoyalty = +Math.min(remainingLoyalty, split.deposit).toFixed(2)
        }
        remainingLoyalty = +(remainingLoyalty - blockLoyalty).toFixed(2)
      }
      const { data: booking, error } = await supabase.from('bookings').insert({
        listing_id: listing.id, location_id: listing.location_id, customer_id: customer?.id || null,
        booking_date: d, start_time: block.start, end_time: block.end,
        players: isPerPerson(listing) ? Math.max(1, players) : 1,
        base_price: block.total, addon_total: addonTotal, discount: blockDiscount, total,
        amount_paid: +(split.online - blockLoyalty).toFixed(2),
        payment_method: split.method,
        payment_status: split.method === 'cash' ? 'pending' : (split.arrival > 0 ? 'partial' : 'paid'),
        booking_status: 'confirmed',
        origin: originStamp, notes: PAY_NOTE[split.method] || PAY_NOTE.cash,
      }).select('id').maybeSingle()
      if (error || !booking) return { error: error?.message || 'Could not create the booking.' }
      created.push(booking.id)
      if (total > 0) {
        if (ownSite && providerHasPayout && split.method === 'deposit' && split.online > 0) {
          // Own site + connected payout: the online DEPOSIT is the provider's money —
          // credit their wallet (0 Bookboll cut); the balance is cash on arrival.
          await recordBookingOnlinePayment({
            bookingId: booking.id, ownerUserId: location.owner_user_id, countryId,
            total: split.online, currency: curr, ratePct: 0, customerLabel,
          })
          if (split.arrival > 0) {
            await supabase.from('booking_payments').insert({
              booking_id: booking.id, payment_type: 'balance', method: 'cash',
              amount: split.arrival, currency: curr, recipient: 'provider', status: 'pending', paid_at: null,
            })
          }
        } else if (split.method === 'online' && !bookbollOwned) {
          // Provider full online — marketplace: Bookboll commission split + wallet credit.
          // Own site (ratePct already 0): 100% credited to the provider's wallet.
          await recordBookingOnlinePayment({
            bookingId: booking.id, ownerUserId: location.owner_user_id, countryId,
            total: split.total, currency: curr, ratePct, customerLabel, loyaltyRedeem: blockLoyalty,
          })
        } else {
          // Deposit (marketplace, or own-site without a payout method → deposit goes to
          // Bookboll as the platform fee) + cash balance/full cash to the recipient.
          const rows = paymentRows(booking.id, split, curr, bookbollOwned, blockLoyalty)
          if (rows.length) await supabase.from('booking_payments').insert(rows)
        }
      }
      if (first) {
        const rows = pricedAddons.filter(a => a.qty > 0).map(a => ({
          booking_id: booking.id, addon_id: a.id, quantity: a.qty, unit_price: Number(a.price), subtotal: a.amount,
        }))
        if (rows.length) await supabase.from('booking_addons').insert(rows)
        first = false
      }
    }
  }
  // Booked — drop our holds for this listing so the freed/used slots resolve immediately.
  await releaseHolds(listing)
  // Fire-and-forget: send the provider's configured "booking confirmation" to the
  // customer (WhatsApp is the live transport). Never blocks or breaks the booking.
  try { notifyBookingConfirmation(bookingConfirmationFrom({ listing, location, customer, curr, dates, selectionByDate, createdIds: created })) } catch { /* non-blocking */ }
  const loyaltyRequested = (payment.method === 'online' || payment.method === 'deposit') ? Math.max(0, Number(payment.loyaltyRedeem) || 0) : 0
  return {
    bookings: created, currency: curr, countryId,
    discountApplied: (Math.max(0, Number(payment.discount) || 0) - remainingDiscount),
    loyaltyApplied: +(loyaltyRequested - remainingLoyalty).toFixed(2),
  }
}

// A signed-in customer's upcoming bookings (for "My bookings").
export async function loadMyBookings(customerId) {
  if (!customerId) return []
  const r = await supabase.from('bookings').select('*').eq('customer_id', customerId).order('booking_date', { ascending: false })
  if (r.error) return []
  const rows = r.data || []
  const listingIds = [...new Set(rows.map(b => b.listing_id).filter(Boolean))]
  const locIds = [...new Set(rows.map(b => b.location_id).filter(Boolean))]
  let listings = [], locs = []
  if (listingIds.length) { const x = await supabase.from('listings').select('id, name').in('id', listingIds); listings = x.data || [] }
  if (locIds.length) { const x = await supabase.from('locations').select('id, name').in('id', locIds); locs = x.data || [] }
  return rows.map(b => ({
    ...b,
    listingName: listings.find(l => l.id === b.listing_id)?.name || 'Court',
    locationName: locs.find(l => l.id === b.location_id)?.name || '',
  }))
}
