/**
 * data.js — catalog reads for the customer storefront: venues, listings and
 * membership programmes, scoped to the visitor's selected city (and, on a
 * white-label site, to that provider).
 *
 * Games, tournaments and social live in apiGames.js / apiPublicProfile.js.
 */
import { apiFetch } from '../lib/api.js'
import { attachCategoryPopularity } from './categoryPopularity.js'
import { selectedCity, selectedCountry } from '../lib/customerSession.js'
import {
  apiLoadPrograms,
  apiLoadProgram,
  apiEnrollInProgram,
  apiLoadMySubscriptions,
  apiCancelMySubscription,
} from './apiPrograms.js'

// ── White-label scoping ─────────────────────────────────────────────────────────
// When the storefront is served for a provider's brand (see lib/brand.js), every
// listing/program/event read is restricted to that owner. null = the normal
// platform site (no scoping). Set once at app start by resolveBrand().
let brandOwnerId = null
export function setBrandScope(ownerUserId) { brandOwnerId = ownerUserId || null; _brandLocIds = null }
let _brandLocIds = null
const INTERVAL_LABEL = { weekly: 'week', biweekly: '2 wks', monthly: 'month', quarterly: 'quarter', yearly: 'year' }
export function intervalLabel(v) { return INTERVAL_LABEL[v] || 'month' }

// Below this, we hide the "N members" social-proof pill — a tiny/new program showing
// "1 member" reads worse than showing nothing. Threshold shared by cards + detail page.
export const MEMBER_COUNT_MIN = 5

export async function brandSlug() {
  try {
    const { activeBrand } = await import('../lib/brand.js')
    return activeBrand.value?.slug || ''
  } catch { return '' }
}
async function brandQuery() {
  const slug = await brandSlug()
  return slug ? { brand_slug: slug } : {}
}

// Catalog query for Laravel marketplace endpoints. City UUIDs from the Supabase
// location picker cannot be sent as city_id — we match Laravel world cities by name.
async function marketplaceQuery(cityId, extra = {}) {
  const q = {}
  if (cityId != null && cityId !== '' && /^\d+$/.test(String(cityId))) q.city_id = Number(cityId)
  const name = extra.name || extra.city || selectedCity.value?.name || ''
  if (name) q.city = name
  const iso = extra.iso || extra.country_iso || selectedCountry.value?.iso_code || ''
  if (iso) q.country_iso = iso
  if (selectedCountry.value?.id && iso === selectedCountry.value?.iso_code) q.country_id = selectedCountry.value.id
  let slug = extra.brand_slug || ''
  if (!slug) {
    try {
      const { activeBrand } = await import('../lib/brand.js')
      slug = activeBrand.value?.slug || ''
    } catch { /* ignore circular-init */ }
  }
  if (slug) q.brand_slug = slug
  return q
}

// ── Facilities (bookable listings) in a city ───────────────────────────────────
// One card per LISTING: the listing name is the headline, its venue (location) name
// is shown by the pin. Cards link to the venue detail page (keyed by location id).
export async function loadFacilities(cityId, extra = {}) {
  if (!cityId && !extra.name && !selectedCity.value?.name) return []
  const query = await marketplaceQuery(cityId, extra)
  const res = await apiFetch('/marketplace/listings', { query })
  if (!res.success) return []
  return attachCategoryPopularity(Array.isArray(res.data?.listings) ? res.data.listings : [], query, res.data || {})
}

// One card per LOCATION (venue): the venue name is the headline, with its court
// count + sports. Used by the Facilities page "by location" toggle.
export async function loadLocations(cityId, extra = {}) {
  if (!cityId && !extra.name && !selectedCity.value?.name) return []
  const res = await apiFetch('/marketplace/venues', { query: await marketplaceQuery(cityId, extra) })
  if (!res.success) return []
  return Array.isArray(res.data?.venues) ? res.data.venues : []
}

// All of the ACTIVE brand's locations (the white-label "Our locations" page) — across every
// city, each with its visible listings + weekly hours. Brand-scoped; returns [] on the apex
// (where "locations" would span every provider — the marketplace uses /venues instead).
export async function loadBrandLocations() {
  if (!brandOwnerId) return []
  // Brand slug only — this page spans EVERY city the provider operates in, so the
  // visitor's selected city must not narrow it.
  const res = await apiFetch('/marketplace/brand-locations', { query: await brandQuery() })
  if (!res.success) return []
  return Array.isArray(res.data?.locations) ? res.data.locations : []
}

export async function loadFacility(id) {
  if (!id) return null
  const res = await apiFetch(`/marketplace/venues/${id}`, { query: await marketplaceQuery(null) })
  if (!res.success || !res.data?.facility) return null
  return res.data.facility
}

// Full detail for a single listing (court/pitch): the listing, its venue, pricing
// tiers and any add-ons offered.
export async function loadListing(id) {
  if (!id) return null
  const res = await apiFetch(`/marketplace/listings/${id}`, { query: await marketplaceQuery(null) })
  if (!res.success || !res.data?.detail) return null
  const d = res.data.detail
  if (d.listing && d.holdMinutes) d.listing.holdMinutes = d.holdMinutes
  return d
}

// Countries + cities where a PROVIDER actually has visible content on their white-label
// site — used to scope the location picker and to detect "we don't serve here". Cities are
// busiest-first. `show_on_provider_site !== false` mirrors the storefront's visibility rule.
export async function loadServedRegions(brandSlug) {
  if (!brandSlug) return { countries: [], cities: [] }
  const res = await apiFetch('/marketplace/served-regions', { query: { brand_slug: String(brandSlug).toLowerCase() } })
  if (!res.success) return { countries: [], cities: [] }
  return {
    countries: res.data?.countries || [],
    cities: res.data?.cities || [],
  }
}

// ── Programs / classes in a city ───────────────────────────────────────────────
export async function loadPrograms(cityId, { iso = '' } = {}) {
  return apiLoadPrograms(cityId, brandOwnerId, iso)
}

// Weekly opening hours — served with program detail from Laravel venues.
export async function loadProgram(id) {
  return apiLoadProgram(id, brandOwnerId)
}

// Customer self-enrolment (marketplace membership checkout) — Laravel authoritative.
export async function enrollInProgram(program, { customer, answers = {}, discount = 0, promocode = null, rewards = null } = {}) {
  if (!program?.id) return { error: 'Program not found.' }
  if (!customer?.id) return { error: 'Please sign in to continue.' }
  return apiEnrollInProgram(program, { answers, discount, promocode, rewards })
}

// A customer's membership subscriptions (for the "My memberships" page / account widget).
export async function loadMySubscriptions(customerId, email = '') {
  if (!customerId && !email) return []
  return apiLoadMySubscriptions(brandOwnerId)
}

/** Customer cancels their own membership: no further renewals are collected. */
export async function cancelMySubscription(subId) {
  return apiCancelMySubscription(subId)
}

// ── Hosted games in a city (resilient if the table isn't migrated yet) ─────────
