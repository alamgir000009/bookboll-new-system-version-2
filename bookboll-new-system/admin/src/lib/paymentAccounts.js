/**
 * paymentAccounts.js — a provider's connected bank account (Listings page).
 *
 * The API resolves WHOSE account this is from the session: a provider is pinned
 * to their own business, so `owner_user_id` is only honoured for platform admins
 * acting on a provider's behalf.
 */
import { apiFetch } from './api.js'

export async function fetchPaymentAccount(ownerUserId = null) {
  const res = await apiFetch('/admin/payment-accounts', {
    query: ownerUserId ? { owner_user_id: ownerUserId } : null,
  })
  return res.success ? (res.data?.account || null) : null
}

export async function createPaymentAccount(body, ownerUserId = null) {
  return apiFetch('/admin/payment-accounts', {
    method: 'POST',
    body: ownerUserId ? { ...body, owner_user_id: ownerUserId } : body,
  })
}

export async function updatePaymentAccount(id, body) {
  return apiFetch(`/admin/payment-accounts/${encodeURIComponent(id)}`, { method: 'PUT', body })
}

export async function deletePaymentAccount(id) {
  return apiFetch(`/admin/payment-accounts/${encodeURIComponent(id)}`, { method: 'DELETE' })
}

/** Default ordering, scoped by the authenticated actor on the server. */
export async function fetchStorefrontSort() {
  const res = await apiFetch('/admin/storefront-sort')
  if (!res.success) throw new Error(res.message || 'Could not load customer ordering.')
  const s = res.data?.sort || {}
  return { space: s.space || 'recommended', venue: s.venue || 'recommended' }
}

export async function saveStorefrontSortValue(view, value) {
  return apiFetch('/admin/storefront-sort', { method: 'PUT', body: { view, value } })
}
