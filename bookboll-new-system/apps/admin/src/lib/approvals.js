/**
 * Approvals — Laravel admin dashboard API
 * (/admin/approvals/*) for apps/admin Approvals.vue (listings + programs).
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

export async function fetchApprovalsDashboard(query = {}) {
  return apiFetch('/admin/approvals/dashboard', { query })
}

export async function approveContent(type, id) {
  return apiFetch(`/admin/approvals/${type}/${id}/approve`, { method: 'POST', body: {} })
}

export async function requestContentChanges(type, id, body = {}) {
  return apiFetch(`/admin/approvals/${type}/${id}/request-changes`, {
    method: 'POST',
    body,
  })
}

/** Suspend a provider's whole business from the marketplace. */
export async function suspendBusiness(ownerUserId) {
  return apiFetch(`/admin/approvals/businesses/${encodeURIComponent(ownerUserId)}/suspend`, {
    method: 'POST', body: {},
  })
}

/** Put a suspended business back on the marketplace. */
export async function reinstateBusiness(ownerUserId) {
  return apiFetch(`/admin/approvals/businesses/${encodeURIComponent(ownerUserId)}/reinstate`, {
    method: 'POST', body: {},
  })
}

export async function bulkApproveContent(items) {
  return apiFetch('/admin/approvals/bulk-approve', {
    method: 'POST',
    body: { items },
  })
}
