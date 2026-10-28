/**
 * slots.js — single source of truth for time-slot generation & conflict checks.
 *
 * Two configurable knobs live on each listing:
 *   - slot_duration : length of one bookable slot, in minutes (15-min increments
 *                     from 15 min up to 8 hours)
 *   - buffer_time   : required gap between consecutive bookings, in minutes
 *
 * Slots always start on a clean boundary derived from the schedule's opening
 * minute, and step forward by (duration + buffer) so the buffer gap is baked in.
 */

// ── Option lists (shared by every setup form) ───────────────────────────────
// Friendly label for a minutes value, e.g. 75 -> "1 hour 15 min".
function slotDurationLabel(mins) {
  const h = Math.floor(mins / 60), m = mins % 60
  const hPart = h ? `${h} hour${h > 1 ? 's' : ''}` : ''
  const mPart = m ? `${m} min` : ''
  return [hPart, mPart].filter(Boolean).join(' ')
}
// Every 15-minute increment from 15 min up to 8 hours, so a provider can pick any
// hour plus :00 / :15 / :30 / :45 within it.
export const SLOT_DURATIONS = Array.from({ length: 32 }, (_, i) => {
  const value = (i + 1) * 15
  return { value, label: slotDurationLabel(value) }
})

// Buffer values are 15-minute multiples so slot start times always land on the
// calendar's 15-minute grid lines.
export const BUFFER_OPTIONS = [
  { value: 0,  label: 'No buffer' },
  { value: 15, label: '15 minutes' },
  { value: 30, label: '30 minutes' },
  { value: 45, label: '45 minutes' },
  { value: 60, label: '1 hour' },
]

// Calendar day-view granularity choices
export const CALENDAR_GRANULARITIES = [
  { value: 60, label: '1 hour' },
  { value: 30, label: '30 min' },
  { value: 15, label: '15 min' },
]

// ── Time helpers ─────────────────────────────────────────────────────────────
export function toMinutes(t) {
  if (!t) return 0
  const [h, m] = String(t).split(':').map(Number)
  return (h || 0) * 60 + (m || 0)
}
export function toTimeStr(mins) {
  const h = Math.floor(mins / 60)
  const m = mins % 60
  return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`
}
/**
 * Minutes-of-day for an END/CLOSE time. Midnight as a closing time means the
 * END of the day, so '00:00' (and '24:00') resolve to 1440 — a schedule like
 * 12:00 PM → 12:00 AM is a 12-hour window, not an empty one.
 */
export function endMinutes(t) {
  const m = toMinutes(t)
  return m === 0 ? 24 * 60 : m
}

// ── Display formatting (12-hour AM/PM) ─────────────────────────────────────────
// The single place that turns an internal 24h "HH:MM" (or a minutes number, or a Date)
// into the customer/dashboard-facing 12-hour label, e.g. "9:00 AM", "2:30 PM".
// Storage, inputs and comparisons stay 24h — only DISPLAY uses this.
export function fmt12(t) {
  if (t === null || t === undefined || t === '') return ''
  let h, m
  if (t instanceof Date) { h = t.getHours(); m = t.getMinutes() }
  else if (typeof t === 'number') { h = Math.floor(t / 60); m = t % 60 }
  else { const p = String(t).split(':'); h = parseInt(p[0], 10); m = parseInt(p[1], 10) || 0 }
  if (isNaN(h)) return String(t)
  if (h >= 24) h -= 24   // '24:00' = midnight at the end of the day → 12:00 AM
  const ap = h >= 12 ? 'PM' : 'AM'
  const h12 = h % 12 || 12
  return `${h12}:${String(m).padStart(2, '0')} ${ap}`
}

// "9:00 AM – 10:30 AM" for a start/end pair (any form fmt12 accepts). `sep` overrides the dash.
export function fmtRange12(start, end, sep = ' – ') {
  const s = fmt12(start), e = fmt12(end)
  if (s && e) return `${s}${sep}${e}`
  return s || e
}

/** Normalise a slot_duration to one of the allowed values (defaults to 60). */
export function normalizeDuration(d) {
  const n = Number(d)
  return SLOT_DURATIONS.some(o => o.value === n) ? n : (n > 0 ? n : 60)
}

/**
 * Generate bookable slots between two minute-of-day bounds.
 * @param {object} listing  - needs slot_duration (+ optional buffer_time)
 * @param {number} openMins - opening time in minutes from midnight (default 06:00)
 * @param {number} closeMins- closing time in minutes from midnight (default 23:00)
 * @returns {Array<{start_time, end_time}>}
 */
export function generateSlots(listing, openMins = 6 * 60, closeMins = 23 * 60, dayOfWeek = null) {
  const duration = normalizeDuration(listing?.slot_duration)

  // ── Custom slots: the provider's own list, each with its own length ──
  if (listing?.start_time_mode === 'custom' && Array.isArray(listing?.custom_slots) && listing.custom_slots.length) {
    const seen = new Set()
    return listing.custom_slots
      .filter(r => r && r.start_time && r.end_time && (dayOfWeek == null || (r.days || []).map(Number).includes(Number(dayOfWeek))))
      .map(r => ({ start_time: String(r.start_time).slice(0, 5), end_time: String(r.end_time).slice(0, 5) }))
      .filter(r => {
        const a = toMinutes(r.start_time)
        let b = endMinutes(r.end_time)
        if (b <= a) b += 24 * 60
        if (a < openMins || a >= closeMins) return false
        if (closeMins < 24 * 60 && b > closeMins) return false
        if (seen.has(r.start_time)) return false
        seen.add(r.start_time)
        return true
      })
      .sort((x, y) => toMinutes(x.start_time) - toMinutes(y.start_time))
  }

  // ── Fixed start times: provider defined an explicit list ──
  if (listing?.start_time_mode === 'fixed' && Array.isArray(listing?.fixed_start_times) && listing.fixed_start_times.length) {
    return listing.fixed_start_times
      .map(t => toMinutes(t))
      .filter(s => s >= openMins && s + duration <= closeMins)
      .sort((a, b) => a - b)
      .map(s => ({ start_time: toTimeStr(s), end_time: toTimeStr(s + duration) }))
  }

  // ── Automatic: every bookable slot, stepping by duration + buffer ──
  const buffer = Number(listing?.buffer_time) || 0
  const step   = duration + buffer
  const slots  = []
  let start = openMins
  if (step <= 0) return slots
  while (start + duration <= closeMins) {
    slots.push({ start_time: toTimeStr(start), end_time: toTimeStr(start + duration) })
    start += step
  }
  return slots
}

/**
 * Does a candidate slot conflict with any existing booking?
 * Buffer is applied symmetrically: each booking reserves `buffer` minutes of
 * turnaround on both sides, so two bookings always keep at least `buffer` apart.
 */
export function slotConflicts(slot, bookings, buffer = 0) {
  const s = toMinutes(slot.start_time)
  const e = endMinutes(slot.end_time)
  return (bookings || []).some(b => {
    const bs = toMinutes(b.start_time) - buffer
    const be = endMinutes(b.end_time) + buffer
    return !(e <= bs || s >= be)
  })
}

/** Customer-facing duration, e.g. 90 -> "1 hr 30 min". */
export function durationLabel(mins) {
  const value = Number(mins)
  if (!Number.isFinite(value) || value <= 0) return ''
  const n = Math.round(value)
  const h = Math.floor(n / 60)
  const m = n % 60
  if (h && m) return `${h} hr ${m} min`
  if (h) return `${h} hr`
  return `${m} min`
}

/**
 * Build a short preview of the first few slot start times for a duration+buffer,
 * used as a helper hint under the setup form.
 */
export function previewSlots(duration, buffer = 0, startMins = 9 * 60, count = 4) {
  const step = normalizeDuration(duration) + (Number(buffer) || 0)
  const out = []
  let t = startMins
  for (let i = 0; i < count; i++) { out.push(toTimeStr(t)); t += step }
  return out
}

/** Day/night bucket for a 'HH:MM' start — powers the sun/moon icons on slot
 *  pickers (customers routinely misread AM/PM). Day = 6:00-16:59 — from
 *  5 PM it's evening, so the moon shows. */
export function dayPeriod(hhmm) {
  const h = Math.floor(toMinutes(hhmm) / 60)
  return h >= 6 && h < 17 ? 'day' : 'night'
}
