/**
 * billingPlans.js — helpers for a membership program's billing.
 *
 * Each program has ONE billing interval (providers create separate programs for
 * different intervals). The offer depends on that interval + the program type:
 *   • Monthly  → free trial (free_trial_days).
 *   • Longer than monthly + ongoing → bonus_months (extra free time), paid upfront.
 *   • Longer than monthly + fixed season → paid upfront at the set price.
 */

// Length of each interval in months (weekly/biweekly are fractions of a month).
export const INTERVAL_MONTHS = { weekly: 0.25, biweekly: 0.5, monthly: 1, quarterly: 3, yearly: 12 }

export function isLongerThanMonthly(interval) { return (INTERVAL_MONTHS[interval] || 1) > 1 }
// Paid upfront at signup (no free trial): longer-than-monthly intervals, or a one-time
// "pay in full" charge (used by fixed-duration / season programs).
export function chargesUpfront(interval) { return interval === 'one_time' || isLongerThanMonthly(interval) }

// Advance a YYYY-MM-DD date by one billing interval (for next_billing_at).
export function addIntervalToDate(dateStr, interval) {
  const d = new Date(dateStr + 'T00:00:00')
  if (interval === 'weekly') d.setDate(d.getDate() + 7)
  else if (interval === 'biweekly') d.setDate(d.getDate() + 14)
  else if (interval === 'quarterly') d.setMonth(d.getMonth() + 3)
  else if (interval === 'yearly') d.setFullYear(d.getFullYear() + 1)
  else d.setMonth(d.getMonth() + 1)   // monthly (default)
  return d.toISOString().slice(0, 10)
}

// next_billing date = start + one interval + any bonus months (ongoing extra free time).
export function nextBillingFrom(dateStr, interval, bonusMonths = 0) {
  let next = addIntervalToDate(dateStr, interval || 'monthly')
  const bonus = Number(bonusMonths) || 0
  if (bonus > 0) {
    const d = new Date(next + 'T00:00:00'); d.setMonth(d.getMonth() + bonus)
    next = d.toISOString().slice(0, 10)
  }
  return next
}

// ── MOAT: every program must offer a customer incentive ───────────────────────
// Two incentive types, chosen automatically by the plan:
//   • "trial"    → a mandatory free trial (Bookboll guarantee). Used by monthly
//                  ongoing plans and ALL fixed seasons (installments + pay-in-full).
//   • "discount" → "N months off" the list price. Used by longer-than-monthly
//                  ONGOING plans (quarterly / yearly), which are paid upfront.

const MONTH_DAYS = 30

// Free-trial options, ordered least→most generous. "1 session" is the only
// session-based one (it ends on the 2nd session). Each option is available only
// when the program's total span falls in [minDays, maxDays).
export const TRIAL_OPTIONS = [
  { key: 'session1', label: '1 session', days: 0,  sessions: 1, minDays: 1,             maxDays: 1 * MONTH_DAYS },
  { key: 'd7',       label: '7 days',    days: 7,  sessions: 0, minDays: 14,            maxDays: 2 * MONTH_DAYS },
  { key: 'd14',      label: '14 days',   days: 14, sessions: 0, minDays: 1 * MONTH_DAYS, maxDays: Infinity },
  { key: 'd30',      label: '30 days',   days: 30, sessions: 0, minDays: 2 * MONTH_DAYS, maxDays: Infinity },
]

export function trialOptionsForSpan(spanDays) {
  const s = Number(spanDays) || 0
  const opts = TRIAL_OPTIONS.filter(o => s >= o.minDays && s < o.maxDays)
  return opts.length ? opts : [TRIAL_OPTIONS[0]]   // always at least "1 session"
}
// Smart default: the most generous eligible trial for this span.
export function recommendedTrialForSpan(spanDays) {
  const opts = trialOptionsForSpan(spanDays)
  return opts[opts.length - 1]
}
export function trialOptionKey(days, sessions) {
  if (Number(sessions) > 0) return 'session1'
  const o = TRIAL_OPTIONS.find(t => t.days === Number(days) && !t.sessions)
  return o ? o.key : null
}

// The program's total length in days, used to gate trial options.
//   • Fixed season → its actual length (end−start, or duration_weeks×7).
//   • Ongoing      → the billing interval's length (monthly≈30d, weekly≈7d…).
const ONGOING_INTERVAL_DAYS = { weekly: 7, biweekly: 14, monthly: 30, quarterly: 90, yearly: 365 }
export function programSpanDays(program) {
  if (!program) return 30
  if (program.program_type === 'fixed_duration') {
    if (program.start_date && program.end_date) {
      const d = Math.round((new Date(program.end_date) - new Date(program.start_date)) / 86400000)
      if (d > 0) return d
    }
    if (program.duration_weeks) return Number(program.duration_weeks) * 7
    return 30
  }
  return ONGOING_INTERVAL_DAYS[program.billing_interval] || 30
}

// Which incentive a program uses (see note above).
export function offerModeFor(program) {
  if (!program) return 'trial'
  const ongoing = program.program_type !== 'fixed_duration'
  return (ongoing && isLongerThanMonthly(program.billing_interval)) ? 'discount' : 'trial'
}

// Discount offer (months off the list price) for longer upfront plans. Capped
// below the full term so the price can never reach zero.
export function discountOptionsForInterval(interval) {
  const m = Math.floor(INTERVAL_MONTHS[interval] || 12)
  const max = Math.min(3, Math.max(1, m - 1))
  return Array.from({ length: max }, (_, i) => i + 1)
}
export function recommendedDiscountForInterval(interval) {
  const opts = discountOptionsForInterval(interval)
  return (opts.includes(2) && (INTERVAL_MONTHS[interval] || 0) >= 6) ? 2 : opts[0]
}
export function discountedPrice(price, interval, discountMonths) {
  const m = INTERVAL_MONTHS[interval] || 12
  const d = Math.min(Number(discountMonths) || 0, m)
  return Math.max(0, +(Number(price) * (1 - d / m)).toFixed(2))
}
