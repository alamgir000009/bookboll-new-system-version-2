export function rowState(row) {
  const eligibility = row?.eligibility_status || 'eligible'
  const approval = row?.approval_status || 'approved'
  const hasSnapshot = !!row?.previous_snapshot

  if (eligibility === 'paused' && approval === 'pending' && hasSnapshot) return 'resubmitted'
  if (eligibility === 'paused') return 'paused'
  if (approval === 'pending') return 'pending_new'
  return 'active'
}

// Only CONTENT that could be inappropriate needs Bookboll approval (title, description,
// photos) — operational edits (schedule, pricing, slots…) go live instantly. This tells
// whether the submitted content differs from the live/approved content, so a content edit
// can be STAGED for review (in pending_content) while the live row keeps serving.
export function contentChanged(next, live, keys = ['name', 'description', 'photo_url', 'photos']) {
  const asArr = v => (Array.isArray(v) ? v : [])
  const asStr = v => (v == null ? '' : String(v))
  for (const k of keys) {
    const a = next?.[k], b = live?.[k]
    if (Array.isArray(a) || Array.isArray(b)) {
      const aa = asArr(a), bb = asArr(b)
      if (aa.length !== bb.length || aa.some((x, i) => x !== bb[i])) return true
    } else if (asStr(a) !== asStr(b)) return true
  }
  return false
}

export const TRIAL_TERMS_VERSION = '2026-05-11-v1'
export const PLATFORM_COMMISSION = 15
export const DEFAULT_TRIAL_DAYS = 14

// Free-trial length scales with the billing commitment: longer commitments earn
// longer trials. Super Admins can override the auto-filled value per program.
export const TRIAL_DAYS_BY_INTERVAL = {
  weekly:    3,
  biweekly:  7,
  monthly:   14,
  quarterly: 30,
  yearly:    30,
}

export function trialDaysForInterval(interval) {
  return TRIAL_DAYS_BY_INTERVAL[interval] ?? DEFAULT_TRIAL_DAYS
}

// Providers may pick a free-trial length from a small set of options per interval.
// The interval's default (TRIAL_DAYS_BY_INTERVAL) is the recommended choice.
export const TRIAL_OPTIONS_BY_INTERVAL = {
  weekly:    [3, 7],
  biweekly:  [7, 14],
  monthly:   [7, 14, 30],
  quarterly: [14, 30, 60],
  yearly:    [30, 60, 90],
}

export function trialOptionsForInterval(interval) {
  const opts = TRIAL_OPTIONS_BY_INTERVAL[interval] || [trialDaysForInterval(interval)]
  const rec = trialDaysForInterval(interval)
  // Always include the recommended value, sorted ascending.
  return [...new Set([...opts, rec])].sort((a, b) => a - b)
}
