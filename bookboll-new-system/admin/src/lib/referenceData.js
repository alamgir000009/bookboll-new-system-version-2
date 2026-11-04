/**
 * referenceData.js — providers, venues, listings, cities and countries in one
 * call, already narrowed to what the signed-in account may see.
 *
 * Replaces the four separate table reads every scoped page used to make. The
 * server applies ownership (a provider's own business) or jurisdiction (a
 * country admin's countries), so pages no longer filter for access themselves —
 * they only filter for what the user picked.
 */
import { apiFetch } from './api.js'

const EMPTY = { providers: [], venues: [], listings: [], cities: [], countries: [], commissionRates: [], scope: {} }

/** @returns {Promise<{providers:Array,venues:Array,listings:Array,cities:Array,countries:Array,scope:object}>} */
export async function loadReferenceData() {
  const res = await apiFetch('/admin/reference-data')
  if (!res.success) return { ...EMPTY }
  const d = res.data || {}
  return {
    providers: d.providers || [],
    venues: d.venues || [],
    listings: d.listings || [],
    cities: d.cities || [],
    countries: d.countries || [],
    commissionRates: d.commission_rates || [],
    scope: d.scope || {},
  }
}

/**
 * Venues under the key the dashboard pages still use (`locations`), so callers
 * that speak the Postgres vocabulary keep working.
 */
export async function loadScopedLocations() {
  const { venues } = await loadReferenceData()
  return venues.map(v => ({ ...v, owner_user_id: v.owner_user_id, city_id: v.city_id }))
}
