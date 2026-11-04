/**
 * data.js — the slice of storefront data the ADMIN dashboard needs.
 *
 * This used to be the full customer-storefront data layer (1,766 lines against
 * Supabase). In the split apps that job belongs to apps/web; the dashboard only ever
 * reached for two things — the brand scope and the regions a provider actually serves
 * — so those are all that remains, now on our own backend.
 *
 * Everything else that lived here is in apps/web/src/customer/data.js.
 */
import { apiFetch } from '../lib/api.js'

// Which provider's storefront the dashboard is previewing (null = the marketplace).
// Kept as module state because lib/brand.js sets it during brand resolution.
let brandOwnerId = null

export function setBrandScope(ownerUserId) {
  brandOwnerId = ownerUserId || null
}

export function currentBrandScope() {
  return brandOwnerId
}

/**
 * The countries and cities a white-label site actually serves — derived from the
 * provider's own venues, so their storefront never offers a city they don't operate in.
 */
export async function loadServedRegions(brandSlug) {
  if (!brandSlug) return { countries: [], cities: [] }
  const res = await apiFetch('/marketplace/served-regions', {
    query: { brand_slug: String(brandSlug).toLowerCase() },
  })
  if (!res.success) return { countries: [], cities: [] }
  return {
    countries: res.data?.countries || [],
    cities: res.data?.cities || [],
  }
}
