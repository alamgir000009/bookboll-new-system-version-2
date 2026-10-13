/**
 * crossApp.js — absolute URLs between web, business, and admin apps.
 */
import { webBaseUrl, adminBaseUrl, businessBaseUrl } from './appBase.js'

export function adminLoginUrl(query = '') {
  const q = query ? (query.startsWith('?') ? query : `?${query}`) : ''
  return `${adminBaseUrl()}/login${q}`
}

export function adminDashboardUrl(path = '/admin') {
  const p = path.startsWith('/') ? path : `/${path}`
  return `${adminBaseUrl()}${p}`
}

export function businessBase() {
  return businessBaseUrl()
}

export function businessSignupUrl() {
  return `${businessBaseUrl()}/signup`
}

export function businessHomeUrl(path = '/') {
  const p = path.startsWith('/') ? path : `/${path}`
  return `${businessBaseUrl()}${p}`
}

/** @deprecated use businessSignupUrl */
export function webSignupUrl() {
  return businessSignupUrl()
}

/** @deprecated use businessHomeUrl */
export function webForBusinessUrl() {
  return businessHomeUrl('/')
}

/** Hand session tokens to the admin app after signup. */
export function adminAuthCallbackUrl({ accessToken, refreshToken, welcome = true } = {}) {
  const u = new URL(`${adminBaseUrl()}/auth/callback`)
  if (accessToken) u.searchParams.set('access_token', accessToken)
  if (refreshToken) u.searchParams.set('refresh_token', refreshToken)
  if (welcome) u.searchParams.set('welcome', '1')
  return u.toString()
}
