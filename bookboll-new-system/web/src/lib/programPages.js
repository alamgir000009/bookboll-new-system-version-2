/**
 * programPages.js — data for the membership local-SEO landing pages:
 *   /memberships/{place}            → all memberships in a city or country
 *   /memberships/{place}/{segment}  → segment is a main category (gym/class/academy) OR a
 *                                     program type (yoga/football/…); the page detects which.
 *
 * Mirrors cityPages.js (venues) but for class_programs. Reuses city/country resolution + slugs
 * from cityPages. Apex marketplace only. Best-effort: unknown slugs → null → noindex empty.
 */
import { slugifyCity, slugifySport, resolveCity, resolveCountry, priceRange } from './cityPages.js'
import { slugify } from './blog.js'
import { apiFetch } from './api.js'
import { loadPrograms } from '../customer/data.js'
import { MEMBERSHIP_CATEGORIES, MEMBERSHIP_CATEGORY_BY_KEY } from './membershipCategories.js'

export { priceRange }

// SEO-friendly hub heading + generic noun per membership category.
export const MEMBERSHIP_HEADING = { gym: 'Gyms', class: 'Fitness classes', academy: 'Sports academies' }
export const MEMBERSHIP_NOUN = { gym: 'gyms', class: 'classes', academy: 'academies' }
export const membershipCatLabel = (key) => MEMBERSHIP_CATEGORY_BY_KEY[key]?.label || 'Academy'

/** Match a slug to a membership main category (gym/class/academy), or null. */
export function membershipCategoryFromSlug(slug) {
  if (!slug) return null
  const s = String(slug).toLowerCase()
  return MEMBERSHIP_CATEGORIES.find(c => c.key === s || slugify(c.label) === s) || null
}

// Distinct program TYPES (Yoga, Football, …) for the type chips — from data.
function summarizeTypes(all) {
  const t = {}
  for (const p of all) { if (!p.sport) continue; const sl = slugifySport(p.sport); if (!t[sl]) t[sl] = { name: p.sport, slug: sl, count: 0 }; t[sl].count++ }
  return Object.values(t).sort((a, b) => b.count - a.count)
}
// Distinct main categories present (gym/class/academy).
function summarizeCategories(all) {
  const c = {}
  for (const p of all) { const key = p.category || 'academy'; if (!c[key]) c[key] = { key, slug: key, label: membershipCatLabel(key), count: 0 }; c[key].count++ }
  return Object.values(c).sort((a, b) => b.count - a.count)
}
function applySegment(all, segment) {
  if (!segment) return { mode: 'hub', category: null, typeName: null, programs: all }
  const cat = membershipCategoryFromSlug(segment)
  if (cat) return { mode: 'category', category: cat, typeName: null, programs: all.filter(p => (p.category || 'academy') === cat.key) }
  const programs = all.filter(p => p.sport && slugifySport(p.sport) === String(segment).toLowerCase())
  return { mode: 'type', category: null, typeName: programs[0]?.sport || segment, programs }
}

/**
 * Load memberships for a PLACE (city OR country) + optional segment (membership category or type).
 * Returns { place:{type,id,name,slug,currencyCode,countryName?,countrySlug?}, mode, segment,
 *   category, typeName, programs, categories, types, cities }.
 */
export async function loadPlacePrograms(slug, segment = null) {
  const empty = { place: null, mode: null, segment: null, category: null, typeName: null, programs: [], categories: [], types: [], cities: [] }

  const city = await resolveCity(slug)
  if (city) {
    const all = await loadPrograms(city.id, { iso: city.countryIso })
    const place = { type: 'city', id: city.id, name: city.name, slug: city.slug, currencyCode: city.currencyCode, countryName: city.countryName, countrySlug: city.countryName ? slugify(city.countryName) : '' }
    return { place, segment, categories: summarizeCategories(all), types: summarizeTypes(all), cities: [], ...applySegment(all, segment) }
  }

  const country = await resolveCountry(slug)
  if (country) {
    const res = await apiFetch('/marketplace/cities', { query: { country_id: country.id, limit: 500 } })
    const ccities = res.success ? (res.data?.cities || []) : []
    const perCity = await Promise.all((ccities || []).map(async c => ({ c, list: await loadPrograms(c.id, { iso: country.iso }) })))
    const all = perCity.flatMap(x => x.list)
    const cities = perCity.filter(x => x.list.length).map(x => ({ name: x.c.name, slug: slugifyCity(x.c.name), count: x.list.length })).sort((a, b) => b.count - a.count)
    const place = { type: 'country', id: country.id, name: country.name, slug: country.slug, currencyCode: country.currencyCode }
    return { place, segment, categories: summarizeCategories(all), types: summarizeTypes(all), cities, ...applySegment(all, segment) }
  }

  return empty
}
