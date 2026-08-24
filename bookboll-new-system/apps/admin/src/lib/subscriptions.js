/**
 * Subscriptions — Laravel admin dashboard API
 * (/admin/subscriptions/dashboard*) for apps/admin Subscribers.vue.
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

export async function fetchSubscriptionsDashboard(query = {}) {
  return apiFetch('/admin/subscriptions/dashboard', { query })
}

export async function fetchSubscriptionDashboard(id) {
  return apiFetch(`/admin/subscriptions/dashboard/${id}`)
}

export async function createSubscription(body) {
  return apiFetch('/admin/subscriptions/dashboard', { method: 'POST', body })
}

export async function chargeSubscription(id) {
  return apiFetch(`/admin/subscriptions/dashboard/${id}/charge`, { method: 'POST', body: {} })
}

export async function retryChargeSubscription(id) {
  return apiFetch(`/admin/subscriptions/dashboard/${id}/retry-charge`, { method: 'POST', body: {} })
}

export async function cancelSubscription(id, body = {}) {
  return apiFetch(`/admin/subscriptions/dashboard/${id}/cancel`, { method: 'POST', body })
}

export async function bulkCancelSubscriptions(ids) {
  return apiFetch('/admin/subscriptions/dashboard/bulk-cancel', {
    method: 'POST',
    body: { ids },
  })
}

export async function pauseSubscription(id) {
  return apiFetch(`/admin/subscriptions/dashboard/${id}/pause`, { method: 'POST', body: {} })
}

export async function resumeSubscription(id) {
  return apiFetch(`/admin/subscriptions/dashboard/${id}/resume`, { method: 'POST', body: {} })
}

export async function markSubscriptionPastDue(id) {
  return apiFetch(`/admin/subscriptions/dashboard/${id}/mark-past-due`, { method: 'POST', body: {} })
}

export async function retentionMonthOff(id, body = {}) {
  return apiFetch(`/admin/subscriptions/dashboard/${id}/retention/month-off`, {
    method: 'POST',
    body,
  })
}

export async function winbackSubscription(id) {
  return apiFetch(`/admin/subscriptions/dashboard/${id}/winback`, { method: 'POST', body: {} })
}

export async function fetchSubscriptionPayments(id) {
  return apiFetch(`/admin/subscriptions/dashboard/${id}/payments`)
}

export async function deleteProgram(id) {
  return apiFetch(`/admin/subscriptions/programs/${id}`, { method: 'DELETE' })
}
