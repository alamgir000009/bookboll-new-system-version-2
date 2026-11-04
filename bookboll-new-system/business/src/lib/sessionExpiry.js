/**
 * What happens the moment the server stops recognising this session.
 *
 * Revoking a token from the admin panel only invalidates it SERVER side. The
 * browser kept the stale token, so an abandoned signup could sit here holding
 * credentials the API had already thrown away — every call failing, nothing
 * saying why. Only initAuth(), on the first page load, ever noticed.
 *
 * This site has no login screen of its own: it is the marketing and signup
 * surface, and the admin app owns real sign-in (see lib/auth.js). So there is
 * nowhere to send anyone. Dropping the token and reloading in place is the
 * whole job — every route here renders for a visitor with no account, and the
 * signup flow becomes reachable again instead of failing against a dead token.
 *
 * Deliberately free of imports from auth.js: that module imports apiFetch, and
 * apiFetch has to call into this one. Clearing the key directly is also the
 * surer thing — the reload discards every in-memory store anyway.
 */

/** Keys that belong to the signed-in account. Locale and theme are this device's. */
const SESSION_KEYS = [
  'bb_api_token', // api.js / businessAuth.js — the Sanctum bearer
]

// A revoked token usually 401s several in-flight requests in the same tick.
// Without this latch each one would clear storage and queue its own reload.
let handled = false

/** Test seam: a fresh page gets a fresh latch. */
export function resetSessionExpiryLatch() {
  handled = false
}

export function isSessionExpiryHandled() {
  return handled
}

/**
 * Should this response end the session?
 *
 * Only when the request actually CARRIED a token. Most of this site is read by
 * visitors who have never signed in, and a 401 on an anonymous call simply
 * means "sign in to do that" — it must not reload the page under them.
 */
export function isSessionExpired(status, sentToken) {
  return status === 401 && Boolean(sentToken)
}

/** Drop the dead token and re-render the page as a visitor. */
export function endExpiredSession() {
  if (handled) return
  handled = true

  for (const key of SESSION_KEYS) {
    try { localStorage.removeItem(key) } catch { /* storage can be unavailable */ }
  }

  if (typeof window === 'undefined') return

  window.location.reload()
}
