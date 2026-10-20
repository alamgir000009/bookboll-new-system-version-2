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
export function generateSlots(listing, openMins = 6 * 60, closeMins = 23 * 60) {
  const duration = normalizeDuration(listing?.slot_duration)

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

/** Human label for a duration value, e.g. 90 -> "1h 30m". */
export function durationLabel(mins) {
  const n = Number(mins) || 0
  const h = Math.floor(n / 60)
  const m = n % 60
  if (h && m) return `${h}h ${m}m`
  if (h) return `${h}h`
  return `${m}m`
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

export const DAY_LABELS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']

/**
 * A day's open periods, in the editor's shape: [{open_time, close_time}].
 * Accepts the grouped row ({is_open, periods}) or a raw DB row (open_time/close_time).
 */
export function periodsOf(dayRow) {
  if (!dayRow || !dayRow.is_open) return []
  if (dayRow.periods?.length) return dayRow.periods.filter(p => p?.open_time && p?.close_time)
  if (dayRow.open_time && dayRow.close_time) return [{ open_time: dayRow.open_time, close_time: dayRow.close_time }]
  return []
}

/**
 * Where two period lists overlap — the listing's hours cut down to the venue's.
 *
 * The server only ever sells a slot inside BOTH schedules
 * (ListingService::findOverlappingScheduleBlocks), so an admin view that reads
 * the listing's hours alone shows hours the venue is shut for, and prices them.
 * Midnight as a close is end-of-day on both sides, exactly as the server treats it.
 *
 * @returns {Array<{open_time, close_time}>} minute-sorted, non-overlapping
 */
export function intersectPeriods(a, b) {
  const out = []
  for (const x of a) {
    const xs = toMinutes(x.open_time), xe = endMinutes(x.close_time)
    for (const y of b) {
      const ys = toMinutes(y.open_time), ye = endMinutes(y.close_time)
      const s = Math.max(xs, ys), e = Math.min(xe, ye)
      if (s < e) out.push({ open_time: toTimeStr(s), close_time: e === 24 * 60 ? '00:00' : toTimeStr(e) })
    }
  }
  return out.sort((p, q) => toMinutes(p.open_time) - toMinutes(q.open_time))
}

/**
 * The slot start minutes a list of periods produces, mirroring
 * ListingService::generateTimeSlots() exactly:
 *  - step by the slot length (the server does NOT add buffer_time here)
 *  - keep a slot whose START is inside the period
 *  - allow the last one to overrun only when the period closes at midnight,
 *    which is how a legitimate 23:00-01:00 slot exists
 *
 * @returns {number[]} ascending, de-duplicated
 */
export function periodSlotStarts(periods, step) {
  const starts = new Set()
  if (!(step > 0)) return []
  for (const p of periods) {
    const open = toMinutes(p.open_time)
    const close = endMinutes(p.close_time)
    const closesAtMidnight = toMinutes(p.close_time) === 0
    for (let m = open; m < close; m += step) {
      if (!closesAtMidnight && m + step > close) break
      starts.add(m)
    }
  }
  return [...starts].sort((x, y) => x - y)
}

/**
 * The slots a listing can actually be booked at, per day of the week.
 *
 * This is the ONE place the admin works out bookable times, because every other
 * attempt at it drifted from the server. generateSlots() defaults to a hardcoded
 * 06:00-23:00 window, so a listing that opens at 15:00 was offered a 6 AM start
 * that no customer could ever book, and its real 21:00 and 23:00 slots were
 * missing entirely. The pricing grid had its own copy of the same mistake.
 *
 * @param {Array} hours - listing rows [{ day_of_week, is_open, periods }]
 * @param {number} slotDuration - minutes
 * @param {Array} [venueHours] - the venue's rows in the same shape; when given,
 *   each day is cut down to the hours the venue is also open, as the server does.
 *   A day the venue has no row for stays as the listing says — that means the
 *   venue's hours simply were not loaded, not that it is shut.
 * @returns {Array<{start_time: string, end_time: string, days: number[]}>}
 */
export function bookableSlots(hours, slotDuration, venueHours = null) {
  const step = normalizeDuration(slotDuration)
  if (!step || !Array.isArray(hours)) return []

  const byStart = new Map()
  for (const day of hours) {
    const dow = Number(day?.day_of_week)
    for (const m of periodSlotStarts(effectivePeriods(day, venueHours), step)) {
      const start = toTimeStr(m)
      if (!byStart.has(start)) byStart.set(start, { start_time: start, end_time: toTimeStr((m + step) % (24 * 60)), days: new Set() })
      byStart.get(start).days.add(dow)
    }
  }

  return [...byStart.values()]
    .sort((a, b) => toMinutes(a.start_time) - toMinutes(b.start_time))
    .map(r => ({ ...r, days: [...r.days].sort((a, b) => a - b) }))
}

/** A day's listing periods, cut down to the venue's where the venue's are known. */
export function effectivePeriods(dayRow, venueHours = null) {
  const mine = periodsOf(dayRow)
  if (!mine.length || !Array.isArray(venueHours) || !venueHours.length) return mine
  const venueDay = venueHours.find(v => Number(v?.day_of_week) === Number(dayRow?.day_of_week))
  if (!venueDay) return mine
  return intersectPeriods(mine, periodsOf(venueDay))
}

/** "Every day", "Fri, Sat", "Mon-Thu, Sun" — how a start time's days read. */
export function daysLabel(days) {
  if (!days?.length) return ''
  if (days.length === 7) return 'Every day'

  // Collapse runs in week order (Mon first, Sunday last) so "Mon, Tue, Wed, Thu"
  // reads as "Mon-Thu" instead of four chips' worth of noise.
  const order = [1, 2, 3, 4, 5, 6, 0]
  const present = order.filter(d => days.includes(d))
  const out = []
  let run = []
  const flush = () => {
    if (!run.length) return
    out.push(run.length >= 3
      ? `${DAY_LABELS[run[0]]}-${DAY_LABELS[run[run.length - 1]]}`
      : run.map(d => DAY_LABELS[d]).join(', '))
    run = []
  }
  for (let i = 0; i < present.length; i++) {
    const d = present[i]
    if (!run.length || order.indexOf(d) === order.indexOf(run[run.length - 1]) + 1) run.push(d)
    else { flush(); run = [d] }
  }
  flush()

  return out.join(', ')
}
