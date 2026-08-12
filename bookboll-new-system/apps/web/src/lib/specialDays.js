/**
 * specialDays.js — single source of truth for how Special Days affect
 * SLOT AVAILABILITY on a concrete date.
 *
 * A special_days row scopes to a whole venue (listing_id NULL) or to one
 * listing inside it. Two kinds:
 *   • Off-day (is_closed)             → no bookable slots on that date.
 *   • Custom  (open_time/close_time)  → that window REPLACES the regular
 *                                       weekly schedule for that date.
 *
 * Pricing: OPTIONAL per-special-day override on custom-schedule days.
 *   • custom_price set        → every slot that date costs exactly this.
 *   • price_multiplier ≠ 1    → the listing's resolved price (base or dynamic)
 *                               is multiplied (e.g. 1.25 = +25%).
 *   • neither                 → the listing form's base & dynamic pricing apply
 *                               unchanged (the default, and the only pre-2026-07
 *                               behavior). Apply via applySpecialDayPrice().
 *
 * Precedence on a given date (most specific wins):
 *   1. listing-specific row  (closed beats custom)
 *   2. venue-wide row        (closed beats custom)
 *
 * The same rules are mirrored server-side in bb_available_slots /
 * bb_hold_slots (supabase/migrations/20260742000000_special_days_availability.sql)
 * so the WhatsApp bot and the hold gate stay consistent — keep them in sync.
 */
import { supabase } from './supabase.js'
import { toMinutes, endMinutes, generateSlots } from './slots.js'

// Regular-schedule fallback when a listing has no listing_hours rows at all —
// the same 06:00–23:00 window generateSlots() defaults to.
const DEFAULT_OPEN = 6 * 60
const DEFAULT_CLOSE = 23 * 60

/** Fetch special_days rows for one venue / a set of venues, optionally date-bounded. */
export async function fetchSpecialDays({ locationId, locationIds, date, from, to } = {}) {
  let q = supabase.from('special_days')
    .select('id, location_id, listing_id, name, date, type, is_closed, open_time, close_time, custom_price, price_multiplier')
  if (locationId) q = q.eq('location_id', locationId)
  else if (locationIds?.length) q = q.in('location_id', locationIds)
  if (date) q = q.eq('date', date)
  if (from) q = q.gte('date', from)
  if (to) q = q.lte('date', to)
  const { data, error } = await q
  return error ? [] : (data || [])
}

// A row is usable when it closes the day or carries a valid replacement window.
function usable(r) {
  if (r.is_closed) return true
  if (!r.open_time || !r.close_time) return false
  return endMinutes(r.close_time) > toMinutes(r.open_time)
}

// Best row within one scope: closure wins over a custom window.
function pickRow(scope) {
  const closed = scope.find(r => r.is_closed)
  if (closed) return { closed: true, name: closed.name || 'Closed' }
  const custom = scope[0]
  if (!custom) return null
  const mult = Number(custom.price_multiplier)
  return {
    closed: false,
    openMins: toMinutes(custom.open_time),
    closeMins: endMinutes(custom.close_time),
    name: custom.name || 'Special schedule',
    customPrice: custom.custom_price != null ? Number(custom.custom_price) : null,
    multiplier: Number.isFinite(mult) && mult > 0 ? mult : 1,
  }
}

/**
 * The effective special-day override for one listing on one date, or null when
 * the regular schedule applies.
 * → { closed:true, name } | { closed:false, openMins, closeMins, name } | null
 */
export function resolveSpecialDay(rows, { date, locationId, listingId }) {
  const relevant = (rows || []).filter(r =>
    r.date === date &&
    (!locationId || r.location_id === locationId) &&
    (!r.listing_id || r.listing_id === listingId) &&
    usable(r)
  )
  if (!relevant.length) return null
  const own = relevant.filter(r => r.listing_id)
  return (own.length && pickRow(own)) || pickRow(relevant.filter(r => !r.listing_id))
}

/**
 * The bookable window(s) for a listing on a date, resolving:
 *   special-day override → weekly listing_hours → 06:00–23:00 default.
 *
 * @param date        'YYYY-MM-DD'
 * @param listing     needs id + location_id
 * @param hoursRows   raw listing_hours rows for THIS listing (all weekdays fine)
 * @param specialRows special_days rows covering the listing's venue
 * @returns { closed, special, name, windows: [{ openMins, closeMins }] }
 */
export function dayWindows({ date, listing, hoursRows, specialRows }) {
  const sd = resolveSpecialDay(specialRows, {
    date, locationId: listing?.location_id, listingId: listing?.id,
  })
  if (sd?.closed) return { closed: true, special: true, name: sd.name, windows: [] }
  if (sd) return {
    closed: false, special: true, name: sd.name,
    windows: [{ openMins: sd.openMins, closeMins: sd.closeMins }],
    pricing: { customPrice: sd.customPrice ?? null, multiplier: sd.multiplier ?? 1 },
  }

  if (hoursRows?.length) {
    const dow = new Date(date + 'T00:00:00').getDay()
    const windows = hoursRows
      .filter(r => Number(r.day_of_week) === dow && r.is_open && r.open_time && r.close_time)
      .map(r => ({ openMins: toMinutes(r.open_time), closeMins: endMinutes(r.close_time) }))
      .filter(w => w.closeMins > w.openMins)
      .sort((a, b) => a.openMins - b.openMins)
    if (windows.length) return { closed: false, special: false, name: '', windows }
    // Hours are configured but this weekday has no open period → closed.
    return { closed: true, special: false, name: '', windows: [] }
  }
  // No weekly hours configured at all → legacy always-open window.
  return { closed: false, special: false, name: '', windows: [{ openMins: DEFAULT_OPEN, closeMins: DEFAULT_CLOSE }] }
}

/** generateSlots across multiple windows, deduped and time-sorted. */
export function slotsForWindows(listing, windows) {
  const seen = new Set()
  const out = []
  for (const w of windows || []) {
    for (const s of generateSlots(listing, w.openMins, w.closeMins)) {
      if (!seen.has(s.start_time)) { seen.add(s.start_time); out.push(s) }
    }
  }
  return out.sort((a, b) => toMinutes(a.start_time) - toMinutes(b.start_time))
}

/** Does [start_time, end_time] fit fully inside one of the windows? */
export function slotInWindows(slot, windows) {
  const s = toMinutes(slot.start_time), e = endMinutes(slot.end_time)
  return (windows || []).some(w => s >= w.openMins && e <= w.closeMins)
}

/**
 * Apply a special day's OPTIONAL pricing override to a slot price.
 * `sched` is a dayWindows() result; on regular days (or overrides without
 * pricing) the input price passes through untouched.
 */
export function applySpecialDayPrice(price, sched) {
  const p = Number(price) || 0
  const pr = sched?.pricing
  if (!pr) return p
  if (pr.customPrice != null) return pr.customPrice
  const m = Number(pr.multiplier)
  if (Number.isFinite(m) && m > 0 && m !== 1) return Math.round(p * m * 100) / 100
  return p
}
