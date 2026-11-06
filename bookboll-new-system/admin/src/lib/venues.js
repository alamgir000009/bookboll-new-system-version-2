/**
 * Venues / locations — Laravel admin dashboard API
 * (/admin/venues/dashboard*) for apps/admin Listings + LocationModal.
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

export async function fetchVenuesDashboard(query = {}) {
  return apiFetch('/admin/venues/dashboard', { query })
}

export async function fetchVenueDashboard(id) {
  return apiFetch(`/admin/venues/dashboard/${id}`)
}

export async function createVenue(body) {
  return apiFetch('/admin/venues/dashboard', { method: 'POST', body })
}

export async function updateVenue(id, body) {
  return apiFetch(`/admin/venues/dashboard/${id}`, { method: 'PUT', body })
}

/**
 * Expand a pasted Google Maps link into coordinates. Short share links
 * (maps.app.goo.gl/…) only redirect to the long URL that holds them, and the
 * browser cannot read that redirect cross-origin — so the server follows it.
 */
export async function resolveMapsLink(url) {
  return apiFetch('/admin/venues/resolve-maps-link', { method: 'POST', body: { url } })
}

export async function patchVenue(id, body) {
  return apiFetch(`/admin/venues/dashboard/${id}`, { method: 'PATCH', body })
}

export async function deleteVenue(id) {
  return apiFetch(`/admin/venues/dashboard/${id}`, { method: 'DELETE' })
}

export async function sortVenues(updates) {
  return apiFetch('/admin/venues/dashboard/sort', {
    method: 'POST',
    body: { updates },
  })
}

export async function applyVenueHoursToListings(id, body) {
  return apiFetch(`/admin/venues/dashboard/${id}/apply-hours`, {
    method: 'POST',
    body,
  })
}
