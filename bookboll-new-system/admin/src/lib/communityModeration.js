/**
 * Community moderation — Laravel admin API
 * (/admin/moderation/communities/*) for apps/admin Moderation.vue Communities tab.
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

export async function fetchCommunityModerationDashboard(query = {}) {
  return apiFetch('/admin/moderation/communities/dashboard', { query })
}

export async function flagCommunity(id, reason) {
  return apiFetch(`/admin/moderation/communities/${id}/flag`, {
    method: 'POST',
    body: { reason },
  })
}

export async function removeCommunity(id, reason) {
  return apiFetch(`/admin/moderation/communities/${id}/remove`, {
    method: 'POST',
    body: { reason },
  })
}

export async function restoreCommunity(id) {
  return apiFetch(`/admin/moderation/communities/${id}/restore`, {
    method: 'POST',
    body: {},
  })
}
