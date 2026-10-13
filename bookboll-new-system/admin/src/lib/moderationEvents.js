/**
 * Event moderation — Laravel admin API
 * (/admin/moderation/events/*) for apps/admin Moderation.vue (Events only).
 */
import { apiFetch } from './api.js'

export function firstError(res, fallback = 'Request failed') {
  if (!res) return fallback
  if (res.errors && typeof res.errors === 'object') {
    const first = Object.values(res.errors).flat()[0]
    if (first) return String(first)
  }
  return res.message || fallback
}

export async function fetchEventModerationDashboard(query = {}) {
  return apiFetch('/admin/moderation/events/dashboard', { query })
}

export async function flagEvent(id, reason) {
  return apiFetch(`/admin/moderation/events/${id}/flag`, {
    method: 'POST',
    body: { reason },
  })
}

export async function removeEvent(id, reason) {
  return apiFetch(`/admin/moderation/events/${id}/remove`, {
    method: 'POST',
    body: { reason },
  })
}

export async function restoreEvent(id) {
  return apiFetch(`/admin/moderation/events/${id}/restore`, {
    method: 'POST',
    body: {},
  })
}
