/**
 * Listings — Laravel admin dashboard API
 * (/admin/listings/dashboard*) for apps/admin Listings.vue.
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

export async function fetchListingsDashboard(query = {}) {
  return apiFetch('/admin/listings/dashboard', { query })
}

export async function fetchListingDashboard(id) {
  return apiFetch(`/admin/listings/dashboard/${id}`)
}

export async function createListing(body) {
  return apiFetch('/admin/listings/dashboard', { method: 'POST', body })
}

export async function updateListing(id, body) {
  return apiFetch(`/admin/listings/dashboard/${id}`, { method: 'PUT', body })
}

export async function patchListing(id, body) {
  return apiFetch(`/admin/listings/dashboard/${id}`, { method: 'PATCH', body })
}

export async function deleteListing(id) {
  return apiFetch(`/admin/listings/dashboard/${id}`, { method: 'DELETE' })
}

export async function sortListings(updates) {
  return apiFetch('/admin/listings/dashboard/sort', {
    method: 'POST',
    body: { updates },
  })
}
