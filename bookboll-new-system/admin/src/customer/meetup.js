/**
 * meetup.js — the event LIFECYCLE, derived (never stored): a pure function of the
 * clock and the game row. Matches Reclub's meet state machine:
 *
 *   open       → RSVP is open
 *   waitlisted → player cap reached; new joins queue (auto-promoted on drops)
 *   locked     → lock deadline passed; self-serve drops are blocked
 *   active     → the event is being played right now
 *   archived   → the event ended (chat read-only, attendance marking, props)
 *   cancelled  → host cancelled
 *
 * The lock moment: start − lock_minutes_before when set, else the host's
 * request_deadline, else the start time itself. Only real facts live in DB
 * columns (status/timestamps) — no cron flips anything.
 */

function dt(dateStr, timeStr, fallback = '00:00') {
  return new Date(`${dateStr}T${String(timeStr || fallback).slice(0, 5)}:00`)
}

export function gameStart(g) {
  if (!g?.game_date) return null
  return dt(g.game_date, g.start_time, '00:00')
}
export function gameEnd(g) {
  if (!g?.game_date) return null
  const end = dt(g.game_date, g.end_time || g.start_time || '23:59', '23:59')
  const start = gameStart(g)
  if (start && end <= start) end.setDate(end.getDate() + 1)   // past-midnight ends
  return end
}
/** When self-serve joins/drops stop. Null = never locks (flexible-date events). */
export function lockAt(g) {
  const start = gameStart(g)
  if (!start) return null
  const mins = Number(g.lock_minutes_before)
  if (Number.isFinite(mins) && mins > 0) return new Date(start.getTime() - mins * 60000)
  if (g.request_deadline) {
    const d = new Date(g.request_deadline)
    if (!isNaN(d.getTime())) return d < start ? d : start
  }
  return start
}

/** The derived lifecycle state. acceptedCount fills the 'waitlisted' stage. */
export function meetupState(g, acceptedCount = null, now = new Date()) {
  if (!g) return 'open'
  if (g.status === 'cancelled') return 'cancelled'
  const end = gameEnd(g)
  if (end && now > end) return 'archived'
  const start = gameStart(g)
  if (start && now >= start) return 'active'
  const lock = lockAt(g)
  if (lock && now >= lock) return 'locked'
  const cap = Number(g.max_players) || 0
  const joined = acceptedCount == null ? Number(g.joinedCount) || 0 : acceptedCount
  if (cap > 0 && joined >= cap) return 'waitlisted'
  return 'open'
}

/** Chip metadata per state (label + tone class suffix used by cards/detail pages). */
export const STATE_META = {
  open:       { label: 'Open',       tone: 'open' },
  waitlisted: { label: 'Waitlist',   tone: 'wait' },
  locked:     { label: 'Locked',     tone: 'lock' },
  active:     { label: 'Live now',   tone: 'live' },
  archived:   { label: 'Ended',      tone: 'done' },
  cancelled:  { label: 'Cancelled',  tone: 'off' },
}

/** Human "locks in 1h 20m" countdown (null when not applicable / already locked). */
export function lockCountdown(g, now = new Date()) {
  const lock = lockAt(g)
  if (!lock) return null
  const ms = lock.getTime() - now.getTime()
  if (ms <= 0) return null
  const m = Math.floor(ms / 60000)
  if (m < 60) return `${m}m`
  const h = Math.floor(m / 60)
  if (h < 48) return `${h}h ${m % 60}m`
  return `${Math.floor(h / 24)}d`
}

/** May the joiner still cancel THEIR OWN spot (auto-refund window)? */
export function canSelfCancel(g, now = new Date()) {
  const st = meetupState(g, null, now)
  return st === 'open' || st === 'waitlisted'
}
