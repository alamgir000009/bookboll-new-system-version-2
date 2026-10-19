// Account-wide mobile-app status, mirrored in web/src/lib/mobileAppStatus.js.
//
// The browser can only ever keep a per-device self-report. The server knows
// something the browser cannot: whether this ACCOUNT has signed in from the
// native app. So when it answers, it decides whether to prompt.
//
// Three rules, all of them about not making things worse than the prompt we
// already have:
//   - A server that cannot answer never blocks anything. The page falls back to
//     the existing per-visit prompt.
//   - Only an explicit suppression silences the prompt. Silence is not consent.
//   - Cached status is dropped on sign-out and account switch, and only ever
//     requested for the identity signed in now.

export const APP_AUDIENCES = ['customer', 'admin']

const PATHS = {
  customer: { status: '/customer/mobile-app-status', preference: '/customer/mobile-app-preferences' },
  admin: { status: '/admin/mobile-app-status', preference: '/admin/mobile-app-preferences' },
}

// Keyed by audience AND account, so one identity's answer is never shown to
// another. Cleared wholesale on sign-out.
const cache = new Map()
const cacheKey = (audience, accountId) => `${audience}:${String(accountId || '')}`

export function clearMobileAppStatus(accountId = null) {
  if (accountId === null) { cache.clear(); return }
  for (const key of [...cache.keys()]) {
    if (key.endsWith(`:${String(accountId)}`)) cache.delete(key)
  }
}

function normalize(status, audience) {
  const usage = status?.usage_status === 'used_app' ? 'used_app' : 'unknown'
  return {
    app: APP_AUDIENCES.includes(status?.app) ? status.app : audience,
    // Evidence only. A self-report is reported separately and never promoted.
    usage_status: usage,
    // `unknown` carries no timestamps; a date beside it would be a claim.
    first_authenticated_use_at: usage === 'used_app' && typeof status?.first_authenticated_use_at === 'string' ? status.first_authenticated_use_at : null,
    last_authenticated_use_at: usage === 'used_app' && typeof status?.last_authenticated_use_at === 'string' ? status.last_authenticated_use_at : null,
    self_reported: status?.self_reported === true,
    // Only an explicit true suppresses. An older or partial payload prompts.
    suppress_prompt: status?.suppress_prompt === true,
  }
}

/**
 * This account's status for one app audience.
 *
 * `known: false` means the server did not answer — not that the account has
 * never used the app. The caller keeps its own prompt behaviour in that case.
 */
export async function fetchMobileAppStatus(request, audience, accountId, { force = false } = {}) {
  if (!APP_AUDIENCES.includes(audience) || !accountId) {
    return { known: false, status: null }
  }

  const key = cacheKey(audience, accountId)
  if (!force && cache.has(key)) return cache.get(key)

  let res
  try {
    res = await request(PATHS[audience].status)
  } catch {
    return { known: false, status: null }
  }

  // A server without the endpoint, or one that refuses this identity, is not an
  // answer about adoption. Neither is cached: the deploy may land mid-session.
  if (!res?.success || !res.data?.status) return { known: false, status: null }

  const result = { known: true, status: normalize(res.data.status, audience) }
  cache.set(key, result)
  return result
}

/**
 * "I already have the app", account-wide.
 *
 * Undoable by passing false. Succeeds only if the server says it stored it —
 * otherwise the browser-local self-report stays the only record, and the
 * caller is told so rather than shown a confirmation.
 */
export async function setMobileAppSelfReport(request, audience, accountId, selfReported) {
  if (!APP_AUDIENCES.includes(audience)) return { ok: false, synced: false }

  let res
  try {
    res = await request(PATHS[audience].preference, { method: 'PUT', body: { self_reported: !!selfReported } })
  } catch {
    return { ok: false, synced: false }
  }

  if (!res?.success || !res.data?.status) return { ok: false, synced: false }

  const status = normalize(res.data.status, audience)
  // Confirmed against what came back, not against the fact of a 200.
  if (status.self_reported !== !!selfReported) return { ok: false, synced: false, status }

  cache.set(cacheKey(audience, accountId), { known: true, status })
  return { ok: true, synced: true, status }
}

/**
 * Should the website offer the app card?
 *
 * `localAllows` is the existing per-visit / per-account browser decision. The
 * server can only ever take the prompt away, never add one the page would not
 * otherwise show.
 */
export function shouldOfferApp(statusResult, localAllows) {
  if (!localAllows) return false
  if (!statusResult?.known) return true
  return !statusResult.status.suppress_prompt
}
