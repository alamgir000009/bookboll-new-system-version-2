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

function currentOriginBase() {
  if (typeof window === 'undefined') return ''
  const base = (import.meta.env.BASE_URL || '/').replace(/\/$/, '')
  return window.location.origin + base
}

/** Public marketplace / white-label origin (bookboll.com). */
export function webBaseUrl() {
  const env = stripSlash(import.meta.env.VITE_WEB_URL || '')
  if (env) return env
  return currentOriginBase()
}

/** B2B marketing + signup origin (business.bookboll.com). */
export function businessBaseUrl() {
  const env = stripSlash(import.meta.env.VITE_BUSINESS_URL || '')
  if (env) return env
  return currentOriginBase()
}

/** Admin dashboard origin (admin.bookboll.com). */
export function adminBaseUrl() {
  const env = stripSlash(import.meta.env.VITE_ADMIN_URL || '')
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
