/**
 * Platform settings — Laravel /api/admin/settings (key/value store).
 */
import { apiFetch } from './api.js'

export async function fetchSettings() {
  return apiFetch('/admin/settings')
}

/**
 * Bulk upsert: [{ key, value }, ...]
 */
export async function updateSettings(rows) {
  return apiFetch('/admin/settings', {
    method: 'POST',
    body: rows,
  })
}

export async function updateSetting(key, value) {
  return apiFetch(`/admin/settings/${encodeURIComponent(key)}`, {
    method: 'POST',
    body: { value },
  })
}

export function asBool(value, fallback = false) {
  if (value === true || value === 1 || value === '1' || value === 'true') return true
  if (value === false || value === 0 || value === '0' || value === 'false') return false
  if (value === null || value === undefined || value === '') return fallback
  return Boolean(value)
}

export function asNumber(value, fallback = 0) {
  const n = Number(value)
  return Number.isFinite(n) ? n : fallback
}
