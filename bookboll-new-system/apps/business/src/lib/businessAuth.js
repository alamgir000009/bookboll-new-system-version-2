/**
 * Business signup auth against the Laravel API (Sanctum token).
 * Admin dashboard login remains Supabase until a later step.
 */
import { apiFetch } from './api.js'

const TOKEN_KEY = 'bb_api_token'
const SNAPSHOT_KEY = 'bb_business_snapshot'

export function getApiToken() {
  try { return localStorage.getItem(TOKEN_KEY) || '' } catch { return '' }
}

export function setApiToken(token) {
  try {
    if (token) localStorage.setItem(TOKEN_KEY, token)
    else localStorage.removeItem(TOKEN_KEY)
  } catch { /* ignore */ }
}

export function clearApiToken() {
  setApiToken('')
  try { localStorage.removeItem(SNAPSHOT_KEY) } catch { /* ignore */ }
}

export function getBusinessSnapshot() {
  try {
    const raw = localStorage.getItem(SNAPSHOT_KEY)
    return raw ? JSON.parse(raw) : null
  } catch {
    return null
  }
}

export function setBusinessSnapshot(snapshot) {
  try {
    if (snapshot) localStorage.setItem(SNAPSHOT_KEY, JSON.stringify(snapshot))
    else localStorage.removeItem(SNAPSHOT_KEY)
  } catch { /* ignore */ }
}

/** First validation error message, or the top-level message. */
export function firstApiError(res, fallback = 'Something went wrong.') {
  if (!res) return fallback
  const errors = res.errors
  if (errors && typeof errors === 'object') {
    for (const v of Object.values(errors)) {
      if (Array.isArray(v) && v[0]) return String(v[0])
      if (typeof v === 'string' && v) return v
    }
  }
  return res.message || fallback
}

/**
 * @param {{
 *   name: string,
 *   business_name: string,
 *   username: string,
 *   business_types: string[],
 *   email: string,
 *   password: string,
 *   password_confirmation: string,
 *   country_id?: number|null,
 * }} payload
 */
export async function registerBusiness(payload) {
  const res = await apiFetch('/business/register', { method: 'POST', body: payload })
  if (res.success && res.data?.token) {
    setApiToken(res.data.token)
    setBusinessSnapshot({
      user: res.data.user || null,
      business: res.data.business || null,
      roles: res.data.roles || [],
    })
  }
  return res
}

export async function checkUsername(username) {
  return apiFetch('/business/username-available', {
    method: 'GET',
    query: { username },
  })
}

export async function loadCountries(search = '') {
  return apiFetch('/business/countries', {
    method: 'GET',
    query: search ? { search } : null,
  })
}
