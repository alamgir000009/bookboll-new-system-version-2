/**
 * Thin axios wrapper for the Laravel Bookboll API.
 * Expects { success, message, data, errors } JSON bodies.
 */
import axios from 'axios'

const API_BASE = (import.meta.env.VITE_API_URL || 'http://localhost:8000/api').replace(/\/$/, '')

const client = axios.create({
  baseURL: API_BASE,
  headers: { Accept: 'application/json' },
})

/**
 * @param {string} path - Absolute path under the API base, e.g. `/business/register`
 * @param {{ method?: string, body?: object|null, token?: string|null, query?: Record<string, string|number|undefined|null> }} [opts]
 * @returns {Promise<{ success: boolean, message?: string, data?: any, errors?: any, code?: number, status: number }>}
 */
export async function apiFetch(path, { method = 'GET', body = null, token = null, query = null } = {}) {
  const headers = {}
  if (token) headers.Authorization = `Bearer ${token}`

  try {
    const res = await client.request({
      url: path.startsWith('/') ? path : `/${path}`,
      method,
      data: body ?? undefined,
      params: query || undefined,
      headers,
      validateStatus: () => true, // normalize success/error ourselves
    })

    const json = res.data && typeof res.data === 'object' ? res.data : null
    const ok = res.status >= 200 && res.status < 300

    // Laravel ValidationException default shape: { message, errors }
    if (json && typeof json.success !== 'boolean') {
      const hasErrors = json.errors && typeof json.errors === 'object'
      return {
        success: ok,
        message: json.message || (ok ? 'OK' : 'Request failed'),
        data: json.data ?? null,
        errors: hasErrors ? json.errors : {},
        status: res.status,
      }
    }

    return {
      success: Boolean(json?.success),
      message: json?.message || (ok ? 'OK' : 'Request failed'),
      data: json?.data,
      errors: json?.errors || {},
      code: json?.code,
      status: res.status,
    }
  } catch {
    return {
      success: false,
      message: 'Could not reach the server. Check that the API is running.',
      errors: {},
      status: 0,
    }
  }
}

export function apiBaseUrl() {
  return API_BASE
}
