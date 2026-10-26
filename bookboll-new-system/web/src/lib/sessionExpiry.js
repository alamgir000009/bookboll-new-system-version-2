/**
 * What happens the moment the server stops recognising this customer.
 *
 * Revoking a token from the admin panel only invalidates it SERVER side. The
 * browser kept the stale token and the screen it had already fetched, so every
 * action after that quietly failed — a booking that would not submit, a list
 * that never refreshed — while the header still showed the customer as signed
 * in. Nothing checked for this after the first page load.
 *
 * The storefront is a PUBLIC site, so this does not bounce anyone to a login
 * screen: every route here already renders for a guest, and a customer reading
 * a venue page should keep reading it. Dropping the session and reloading in
 * place is enough — the page comes back signed out, and pages that need an
 * account (My Bookings and friends) show their own sign-in prompt because they
 * read the token that is now gone.
 *
 * Deliberately free of imports from customerSession.js: that module imports
 * apiFetch, and apiFetch has to call into this one. Clearing the keys directly
 * is also the surer thing — the reload discards every in-memory store anyway.
 */

/**
 * Keys that belong to the signed-in account.
 *
 * The location picker, theme and language are NOT here. They are properties of
 * this device, not of the account that just expired, and wiping them would
 * dump the reader back on the country chooser for something they did not do.
 */
const SESSION_KEYS = [
  'bb_customer_token',    // api.js — the Sanctum bearer
  'bb_customer_identity', // customerSession.js — cached name/email/id
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
 * Only when the request actually CARRIED a token. Guests browse this site all
 * day and a 401 on an anonymous call simply means "sign in to do that" — it
 * must never reload the page under someone who was never signed in.
 */
export function isSessionExpired(status, sentToken) {
  return status === 401 && Boolean(sentToken)
}

/** Drop everything tied to the account and re-render the page as a guest. */
export function endExpiredSession() {
  if (handled) return
  handled = true

  for (const key of SESSION_KEYS) {
    try { localStorage.removeItem(key) } catch { /* storage can be unavailable */ }
  }

  if (typeof window === 'undefined') return

  window.location.reload()
}
