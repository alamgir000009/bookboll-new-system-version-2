/**
 * appBase.js — absolute base URLs for the three apps.
 *
 *   bookboll.com          → apps/web      (marketplace / white-label)
 *   business.bookboll.com → apps/business (B2B marketing + signup)
 *   admin.bookboll.com    → apps/admin    (login + dashboard)
 */

function stripSlash(u) {
  return (u || '').replace(/\/$/, '')
}

/**
 * A build-time base URL is only usable if it makes sense where the page is running.
 *
 * `VITE_WEB_URL` and friends default to http://localhost:5173 in .env, and Vite bakes
 * them into the bundle at BUILD time. A production build made without overriding them
 * ships those values to real users — which is how a hosted game on the live site handed
 * out a share link pointing at localhost. A localhost base is therefore ignored unless
 * the page itself is on localhost.
 */
function usableBase(raw) {
  const base = stripSlash(raw)
  if (!base) return ''
  if (typeof window === 'undefined') return base
  const isLocalBase = /^https?:\/\/(localhost|127\.0\.0\.1|\[::1\])(:\d+)?$/i.test(base)
  if (!isLocalBase) return base
  return /^(localhost|127\.0\.0\.1|\[::1\])$/i.test(window.location.hostname) ? base : ''
}

function currentOriginBase() {
  if (typeof window === 'undefined') return ''
  const base = (import.meta.env.BASE_URL || '/').replace(/\/$/, '')
  return window.location.origin + base
}

/**
 * Public marketplace / white-label origin.
 *
 * In THIS app the web base is the app you are already on, so the live origin wins
 * over any build-time value. That is what a share link has to use: a hangout hosted
 * on a provider's own white-label domain must be shareable on that domain, not
 * rewritten to the marketplace — and never to whatever VITE_WEB_URL happened to
 * hold when the bundle was built. (apps/admin and apps/business sit on a DIFFERENT
 * origin, so their copies still prefer the configured value.)
 */
export function webBaseUrl() {
  return currentOriginBase() || usableBase(import.meta.env.VITE_WEB_URL)
}

/** B2B marketing + signup origin (business.bookboll.com). */
export function businessBaseUrl() {
  const env = usableBase(import.meta.env.VITE_BUSINESS_URL)
  if (env) return env
  return currentOriginBase()
}

/** Admin dashboard origin (admin.bookboll.com). */
export function adminBaseUrl() {
  const env = usableBase(import.meta.env.VITE_ADMIN_URL)
  if (env) return env
  return currentOriginBase()
}

/**
 * Storefront-facing absolute base. Prefer VITE_WEB_URL so admin-built links
 * (previews, /pay/…, embeds) hit the public marketplace app.
 */
export function appBaseUrl() {
  return webBaseUrl() || currentOriginBase()
}
