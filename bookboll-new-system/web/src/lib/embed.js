/**
 * embed.js — "embedded storefront" mode for the provider's Embed & Widgets snippets.
 *
 * A provider drops an <iframe> on their own website pointing at their Bookboll site with
 * `?embed=<scope>`. That scope locks the embedded experience to ONE flow so the widget does
 * exactly one job (e.g. a "Venue booking" widget only ever shows venues + booking, never
 * memberships or the rest of the site). The only non-restricted scope is `full`.
 *
 * Scope is read ONCE at module load and kept for the whole SPA session, so it survives
 * in-iframe navigation (Vue Router drops the query string on internal links).
 */

let _scope = ''
if (typeof window !== 'undefined') {
  try { _scope = (new URLSearchParams(window.location.search).get('embed') || '').toLowerCase() } catch { _scope = '' }
}

// Each scoped embed: where it "starts" + which customer paths it allows. Anything else is
// redirected back to `home`, so the customer can't wander out of the widget's flow.
const SCOPES = {
  venues: {
    home: '/facilities',
    allow: (p) => p === '/facilities' || p.startsWith('/listings/') || p.startsWith('/facilities/'),
  },
  programs: {
    home: '/programs',
    allow: (p) => p === '/programs' || p.startsWith('/programs/'),
  },
  // Customer self-service: sign in + manage bookings & memberships.
  account: {
    home: '/account',
    allow: (p) => p === '/account' || p === '/my-bookings' || p === '/my-memberships',
  },
  // Just the sign-in / create-account box.
  auth: {
    home: '/account',
    allow: (p) => p === '/account',
  },
}

/** The active embed scope: '' (not embedded) | 'full' | 'venues' | 'programs'. */
export function embedScope() { return _scope }
/** Any embed mode (incl. full) — used to strip admin chrome from the storefront. */
export function isEmbedded() { return !!_scope }
/** A FLOW-restricted embed (venues/programs) — locks navigation + hides global nav. */
export function isScopedEmbed() { return !!SCOPES[_scope] }
/** Landing path for the current scope. */
export function embedHome() { return SCOPES[_scope]?.home || '/' }
/** Is a customer path allowed within the current scope? (true when not scope-restricted) */
export function embedAllows(path) {
  const s = SCOPES[_scope]
  if (!s) return true
  return s.allow(path || '')
}
