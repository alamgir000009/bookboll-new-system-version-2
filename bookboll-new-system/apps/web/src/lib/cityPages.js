/**
 * cityPages.js — data for the programmatic local-SEO landing pages:
 *   /venues/{city}            → all bookable venues in a city
 *   /venues/{city}/{sport}    → a specific sport in a city
 *
 * Geography resolves via Laravel marketplace geo APIs (nnjeim/world ids).
 */
import { slugify } from './blog.js'
import { apiFetch } from './api.js'
import { loadFacilities } from '../customer/data.js'
import { VENUE_CATEGORIES } from './venueCategories.js'

export const slugifyCity = (name) => slugify(name)
export const slugifySport = (s) => slugify(s)

export const facilityPath = (id, name) => `/facilities/${id}${name ? '/' + slugify(name) : ''}`

const ALL_SPORTS = [...new Set(VENUE_CATEGORIES.flatMap(c => c.subcategories))].filter(s => s && s !== 'Other')

export function sportFromSlug(slug) {
  if (!slug) return null
  const s = String(slug).toLowerCase()
  return ALL_SPORTS.find(name => slugifySport(name) === s) || null
}

export function categoryFromSlug(slug) {
  if (!slug) return null
  const s = String(slug).toLowerCase()
  return VENUE_CATEGORIES.find(c => c.key === s || slugify(c.label) === s) || null
}
export const categoryLabelFor = (key) => VENUE_CATEGORIES.find(c => c.key === key)?.label || 'Sports'
export const CATEGORY_HEADING = { sports: 'Sports venues', hangout: 'Things to do', work: 'Studios & workspaces' }
export const CATEGORY_NOUN = { sports: 'sports venues', hangout: 'places', work: 'workspaces' }

export async function cityForId(cityId, fallbackName = '') {
  if (fallbackName) return { name: fallbackName, slug: slugifyCity(fallbackName) }
  if (!cityId || !/^\d+$/.test(String(cityId))) return null
  try {
    const res = await apiFetch(`/marketplace/cities/${cityId}`)
    if (!res.success || !res.data?.city?.name) return null
    return { name: res.data.city.name, slug: slugifyCity(res.data.city.name) }
  } catch { return null }
}

export async function resolveCity(slug) {
  if (!slug) return null
  try {
    const res = await apiFetch('/marketplace/cities', { query: { slug: String(slug).toLowerCase() } })
    const city = res.data?.city
    if (!city) return null
    const country = city.country || {}
    return {
      id: city.id,
      name: city.name,
      slug: slugifyCity(city.name),
      countryId: city.country_id,
      countryName: country.name || '',
      countryIso: country.iso_code || '',
      currencyCode: country.currency_code || 'AED',
    }
  } catch { return null }
}

export async function resolveCountry(slug) {
  if (!slug) return null
  try {
    const res = await apiFetch('/marketplace/countries', { query: { slug: String(slug).toLowerCase() } })
    const country = res.data?.country
    if (!country) return null
    return {
      id: country.id,
      name: country.name,
      slug: slugify(country.name),
      currencyCode: country.currency_code || 'AED',
      iso: country.iso_code || '',
    }
  } catch { return null }
}

function summarizeTypes(all) {
  const t = {}
  for (const v of all) { if (!v.sport) continue; const sl = slugifySport(v.sport); if (!t[sl]) t[sl] = { name: v.sport, slug: sl, count: 0 }; t[sl].count++ }
  return Object.values(t).sort((a, b) => b.count - a.count)
}

function summarizeCategories(all) {
  const c = {}
  for (const v of all) { const key = v.category || 'sports'; if (!c[key]) c[key] = { key, slug: key, label: categoryLabelFor(key), count: 0 }; c[key].count++ }
  return Object.values(c).sort((a, b) => b.count - a.count)
}

function applySegment(all, segment) {
  if (!segment) return { mode: 'hub', category: null, typeName: null, venues: all }
  const cat = categoryFromSlug(segment)
  if (cat) return { mode: 'category', category: cat, typeName: null, venues: all.filter(v => (v.category || 'sports') === cat.key) }
  const venues = all.filter(v => v.sport && slugifySport(v.sport) === String(segment).toLowerCase())
  return { mode: 'type', category: null, typeName: venues[0]?.sport || segment, venues }
}

export async function loadPlaceVenues(slug, segment = null) {
  const empty = { place: null, mode: null, segment: null, category: null, typeName: null, venues: [], sports: [], categories: [], cities: [] }

  const city = await resolveCity(slug)
  if (city) {
    const all = await loadFacilities(city.id, { name: city.name, iso: city.countryIso })
    const place = { type: 'city', id: city.id, name: city.name, slug: city.slug, currencyCode: city.currencyCode, countryName: city.countryName, countrySlug: city.countryName ? slugify(city.countryName) : '' }
    return { place, segment, sports: summarizeTypes(all), categories: summarizeCategories(all), cities: [], ...applySegment(all, segment) }
  }

  const country = await resolveCountry(slug)
  if (country) {
    const res = await apiFetch('/marketplace/cities', { query: { country_id: country.id, limit: 500 } })
    const ccities = res.success ? (res.data?.cities || []) : []
    const perCity = await Promise.all((ccities || []).map(async c => ({ c, list: await loadFacilities(c.id, { name: c.name, iso: country.iso }) })))
    const all = perCity.flatMap(x => x.list)
    const cities = perCity.filter(x => x.list.length).map(x => ({ name: x.c.name, slug: slugifyCity(x.c.name), count: x.list.length })).sort((a, b) => b.count - a.count)
    const place = { type: 'country', id: country.id, name: country.name, slug: country.slug, currencyCode: country.currencyCode }
    return { place, segment, sports: summarizeTypes(all), categories: summarizeCategories(all), cities, ...applySegment(all, segment) }
  }

  return empty
}

export async function loadCityVenues(citySlug, segment = null) {
  const r = await loadPlaceVenues(citySlug, segment)
  return { ...r, city: r.place }
}

export function priceRange(venues, currency = 'AED') {
  const prices = (venues || []).map(v => Number(v.price)).filter(p => p > 0)
  if (!prices.length) return null
  return { from: Math.min(...prices), currency }
}

let _directoryCache = null
export async function loadDirectory() {
  if (_directoryCache) return _directoryCache
  try {
    const res = await apiFetch('/marketplace/directory')
    _directoryCache = res.success ? (res.data?.groups || []) : []
    return _directoryCache
  } catch { return [] }
}
