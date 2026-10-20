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

/**
 * Copy a listing into a venue.
 *
 * The venue is named explicitly — the server authorizes it exactly as it would
 * a listing typed by hand, so a provider cannot copy into another business's
 * venue by sending its id.
 */
export async function duplicateListing(id, { venueId, name = '' } = {}) {
  if (!id || !venueId) return { success: false, message: 'Choose the venue to copy this listing into.' }
  return apiFetch(`/admin/listings/dashboard/${id}/duplicate`, {
    method: 'POST',
    body: { venue_id: venueId, ...(name.trim() ? { name: name.trim() } : {}) },
  })
}

export async function sortListings(updates) {
  return apiFetch('/admin/listings/dashboard/sort', {
    method: 'POST',
    body: { updates },
  })
}

/** Map Laravel add-on row → Listings.vue dropdown shape */
export function mapAddOnRow(a) {
  if (!a) return null
  return {
    id: a.id,
    name: a.name || '',
    description: a.description || '',
    price: Number(a.price) || 0,
    status: a.status || 'active',
    image_url: a.image || a.image_url || '',
    location_id: a.location_id ?? null,
    hours_extension: a.hours_extension ?? null,
    stock: a.stock ?? null,
    category: a.category || '',
  }
}

/** Map Laravel resource row → Listings.vue dropdown shape */
export function mapResourceRow(r) {
  if (!r) return null
  return {
    id: r.id,
    name: r.name || '',
    type: r.unit_type || r.type || '',
    quantity: Number(r.total_quantity ?? r.quantity) || 0,
    status: r.status || 'active',
    location_id: r.location_id ?? null,
  }
}

export async function fetchAddOns(query = {}) {
  const res = await apiFetch('/admin/add-ons', { query })
  if (!res.success) return res
  const raw = res.data?.addOns ?? res.data?.addons ?? res.data ?? []
  const list = Array.isArray(raw) ? raw : []
  return { ...res, data: list.map(mapAddOnRow).filter(Boolean) }
}

export async function createAddOn(body) {
  return apiFetch('/admin/add-ons', { method: 'POST', body })
}

export async function updateAddOn(id, body) {
  return apiFetch(`/admin/add-ons/${id}`, { method: 'PUT', body })
}

export async function deleteAddOn(id) {
  return apiFetch(`/admin/add-ons/${id}`, { method: 'DELETE' })
}

export async function fetchResources(query = {}) {
  const res = await apiFetch('/admin/resources', {
    query: { records_per_page: 500, ...query },
  })
  if (!res.success) return res
  const page = res.data?.resources
  const list = Array.isArray(page?.data) ? page.data : (Array.isArray(page) ? page : [])
  return { ...res, data: list.map(mapResourceRow).filter(Boolean) }
}

export async function createResource(body) {
  return apiFetch('/admin/resources', { method: 'POST', body })
}

export async function updateResource(id, body) {
  return apiFetch(`/admin/resources/${id}`, { method: 'PUT', body })
}

export async function deleteResource(id) {
  return apiFetch(`/admin/resources/${id}`, { method: 'DELETE' })
}
