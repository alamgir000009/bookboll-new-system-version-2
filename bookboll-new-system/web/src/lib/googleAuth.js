/**
 * googleAuth.js — "Continue with Google" for a storefront on any domain.
 *
 * Why this is not just a Google button: Google validates the origin of the page that
 * talks to it and accepts no wildcards, so alamgir.bookboll.com, every other tenant
 * subdomain, and every custom domain a provider connects would each have to be
 * registered by hand against a ~100 entry cap. They are created at runtime, so they
 * cannot be.
 *
 * Instead the API brokers it (App\Services\OAuthBrokerService): a popup does the whole
 * handshake on the one origin Google knows about, and this module collects the
 * finished session. The storefront itself never navigates — which is the point, since
 * ensureSignedIn() is awaited mid-booking in a dozen places and a page load would
 * destroy the pending promise along with the half-filled checkout behind the modal.
 *
 * The session is collected by polling, not by listening for the popup's postMessage.
 * That looks like the long way round and is deliberate: after the round-trip through
 * accounts.google.com, `window.opener` is frequently severed by Cross-Origin-Opener-
 * Policy and the message never arrives. The popup does post one, and it is used — but
 * only to cut the last second of waiting, never as the mechanism.
 */
import { API_BASE, apiFetch } from './api.js'
import { applyLaravelAuth } from './customerSession.js'

const START_URL = `${API_BASE}/auth/google/start`
const BROKER_ORIGIN = (() => {
  try { return new URL(API_BASE).origin } catch { return '' }
})()

const POLL_MS = 1000
const TIMEOUT_MS = 180000

/** How long to let the popup get as far as registering the handshake. */
const REGISTER_GRACE_MS = 30000

/** Set by cancelGoogleLogin() when the customer closes the modal mid-flow. */
let cancelled = false

/** Survives the top-level redirect fallback; cleared as soon as it is used. */
const RESUME_KEY = 'bb_google_resume'
const RESUME_PARAM = 'bb_auth'

function newSid() {
  try {
    if (crypto?.randomUUID) return crypto.randomUUID()
    const b = new Uint8Array(16)
    crypto.getRandomValues(b)
    return Array.from(b, (x) => x.toString(16).padStart(2, '0')).join('')
  } catch {
    return `${Date.now()}-${Math.random().toString(36).slice(2)}-${Math.random().toString(36).slice(2)}`
  }
}

function startUrl(sid, mode) {
  const q = new URLSearchParams({ sid, return: window.location.origin })
  if (mode === 'redirect') {
    q.set('mode', 'redirect')
    q.set('return_path', window.location.pathname + window.location.search + window.location.hash)
  }
  return `${START_URL}?${q}`
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms))

/**
 * Ask the broker whether this sid has a session waiting.
 *
 * Four outcomes, and keeping them apart is the whole point:
 *   ready    — signed in.
 *   pending  — the broker has the handshake; the customer is still on Google.
 *   waiting  — the broker has never heard of this sid. Not a failure: our first poll
 *              races the popup's own first request and usually wins it.
 *   refused  — spent, expired, or rejected. Stop.
 *
 * @returns {Promise<{ state: 'ready'|'pending'|'waiting'|'refused', error?: string, user?: object, token?: string }>}
 */
async function collect(sid) {
  // token:'' suppresses the stale Bearer the interceptor would otherwise attach —
  // a customer re-authenticating may still be holding an expired one.
  const res = await apiFetch('/customer/auth/exchange', { method: 'POST', body: { sid }, token: '' })

  if (res.success && res.data?.token) return { state: 'ready', user: res.data.user, token: res.data.token }
  if (res.success && res.data?.status === 'pending') return { state: 'pending' }
  if (res.status === 404) return { state: 'waiting' }
  if ([410, 403, 422].includes(res.status)) return { state: 'refused', error: res.message }
  // A network blip mid-poll is not a failed sign-in; keep waiting.
  return { state: 'waiting' }
}

/**
 * Open the popup and resolve once the customer is signed in.
 *
 * Mirrors the other customerSession.js helpers: `{ error }` on failure, `{ customer }`
 * on success, so AuthGate treats it exactly like an email login.
 *
 * @returns {Promise<{ error?: string, customer?: object, redirecting?: true }>}
 */
export async function customerGoogleLoginPopup() {
  const sid = newSid()

  // window.open has to happen in the same tick as the click or the blocker eats it,
  // so the URL is built first and nothing is awaited before this line.
  let popup = null
  try {
    popup = window.open(startUrl(sid, 'popup'), 'bookboll_google', 'width=480,height=680,noopener=no')
  } catch {
    popup = null
  }

  if (!popup) return redirectFallback(sid)

  // Fast path only — see the module docblock on why this is not load-bearing.
  let nudged = false
  const onMessage = (e) => {
    if (e.origin === BROKER_ORIGIN && e.data?.source === 'bookboll-auth') nudged = true
  }
  window.addEventListener('message', onMessage)
  cancelled = false

  try {
    const deadline = Date.now() + TIMEOUT_MS
    const registerBy = Date.now() + REGISTER_GRACE_MS
    let registered = false

    while (Date.now() < deadline) {
      if (cancelled) {
        try { popup.close() } catch { /* already gone */ }
        return { error: '' }
      }

      const got = await collect(sid)

      if (got.state === 'ready') {
        try { popup.close() } catch { /* already gone */ }
        return applyLaravelAuth(got.user, got.token)
      }
      if (got.state === 'refused') {
        return { error: got.error || 'Google sign-in could not be completed. Please try again.' }
      }
      if (got.state === 'pending') registered = true

      // The broker has never seen this sid. Before it has acknowledged the handshake
      // that is simply the popup still loading, so keep waiting — but not forever: if
      // it never registers, start() refused us or never ran, and the popup is showing
      // the real reason.
      if (got.state === 'waiting' && !registered && Date.now() > registerBy) {
        return { error: 'Google sign-in could not be started. Please try again.' }
      }

      // Deliberately NOT checking popup.closed. accounts.google.com sends
      // Cross-Origin-Opener-Policy, which severs our handle to the popup the moment
      // the customer gets there — and a severed handle reports closed === true while
      // the window is still open in front of them. Treating that as "cancelled"
      // killed live sign-ins a second or two in. The customer cancels by closing the
      // modal instead, which calls cancelGoogleLogin().
      await sleep(nudged ? 150 : POLL_MS)
    }

    return { error: 'Google sign-in timed out. Please try again.' }
  } finally {
    window.removeEventListener('message', onMessage)
  }
}

/**
 * Abandon a sign-in in progress.
 *
 * Needed because the popup cannot be watched: once it reaches Google we can no longer
 * tell whether it is open, so the customer closing the modal is the only cancellation
 * signal there is. Resolves the waiting call with an empty error, which AuthGate shows
 * as nothing — they cancelled, they do not need to be told.
 */
export function cancelGoogleLogin() {
  cancelled = true
}

/**
 * Popups are blocked outright in some in-app browsers (Instagram, Facebook), where the
 * button would otherwise do nothing at all. This leaves the page, so whatever the
 * customer had typed into the booking behind the modal is lost — unavoidable once we
 * navigate, and still far better than a dead button. They land back on the same path,
 * signed in.
 */
function redirectFallback(sid) {
  try {
    sessionStorage.setItem(RESUME_KEY, sid)
  } catch {
    return { error: 'Google sign-in needs popups enabled in this browser. Please sign in with email instead.' }
  }
  window.location.assign(startUrl(sid, 'redirect'))

  // The page is on its way out; never resolving keeps the button in its busy state
  // instead of flashing an error during unload.
  return new Promise(() => {})
}

/**
 * Finish a redirect-fallback sign-in on the page the customer lands back on.
 *
 * Safe and cheap to call on every boot: it does nothing unless the broker sent us back
 * with ?bb_auth, and it strips that parameter either way so a reload or a shared link
 * never replays it.
 *
 * @returns {Promise<boolean>} whether a session was collected.
 */
export async function resumeGoogleLogin() {
  let url
  try {
    url = new URL(window.location.href)
  } catch {
    return false
  }

  const returned = url.searchParams.get(RESUME_PARAM)
  if (!returned) return false

  url.searchParams.delete(RESUME_PARAM)
  try { window.history.replaceState({}, '', url.pathname + url.search + url.hash) } catch { /* ignore */ }

  let stored = null
  try {
    stored = sessionStorage.getItem(RESUME_KEY)
    sessionStorage.removeItem(RESUME_KEY)
  } catch { /* ignore */ }

  // The sid must be the one this tab started with. Without that check, a link with
  // someone else's sid appended would let the recipient collect their session.
  if (!stored || stored !== returned) return false

  const got = await collect(returned)
  if (got.state !== 'ready') return false

  const res = await applyLaravelAuth(got.user, got.token)
  return !res.error
}
