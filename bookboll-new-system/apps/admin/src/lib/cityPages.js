/**
 * cityPages.js — data for the programmatic local-SEO landing pages:
 *   /venues/{city}            → all bookable venues in a city
 *   /venues/{city}/{sport}    → a specific sport in a city
 *
 * Slugs are derived from city names and the venue sport taxonomy. Marketplace (apex) only —
 * these reuse the storefront's `loadFacilities` (which is unscoped on apex). Best-effort:
 * unknown slugs resolve to null so the page renders a graceful (noindex) empty state.
 */
import { supabase } from './supabase.js'
import { slugify } from './blog.js'
import { loadFacilities } from '../customer/data.js'
import { VENUE_CATEGORIES } from './venueCategories.js'
import { membershipCategoryLabel as MEMBERSHIP_CAT } from './membershipCategories.js'

export const slugifyCity = (name) => slugify(name)
export const slugifySport = (s) => slugify(s)

// Venue detail URL with a keyword slug after the id: /facilities/<id>/<name-slug>.
// The id does the lookup; the slug carries the venue name for search engines.
// Bare /facilities/<id> URLs keep working — the detail page replaces them with
// the canonical slug form on load.
export const facilityPath = (id, name) => `/facilities/${id}${name ? '/' + slugify(name) : ''}`

// All venue subcategories across every main category (Football, Padel, Bowling, …).
const ALL_SPORTS = [...new Set(VENUE_CATEGORIES.flatMap(c => c.subcategories))].filter(s => s && s !== 'Other')

/** Map a sport slug back to its canonical taxonomy label, or null if unknown. */
export function sportFromSlug(slug) {
  if (!slug) return null
  const s = String(slug).toLowerCase()
  return ALL_SPORTS.find(name => slugifySport(name) === s) || null
}

/** Match a slug to a MAIN category (Sports / Hangout / Work), or null. */
export function categoryFromSlug(slug) {
  if (!slug) return null
  const s = String(slug).toLowerCase()
  return VENUE_CATEGORIES.find(c => c.key === s || slugify(c.label) === s) || null
}
export const categoryLabelFor = (key) => VENUE_CATEGORIES.find(c => c.key === key)?.label || 'Sports'
// SEO-friendly hub heading + generic noun per main category.
export const CATEGORY_HEADING = { sports: 'Sports venues', hangout: 'Things to do', work: 'Studios & workspaces' }
export const CATEGORY_NOUN = { sports: 'sports venues', hangout: 'places', work: 'workspaces' }

/** City name + slug for a city id (for building backlinks from a venue page). */
export async function cityForId(cityId) {
  if (!cityId) return null
  try {
    const { data } = await supabase.from('cities').select('name').eq('id', cityId).maybeSingle()
    if (!data?.name) return null
    return { name: data.name, slug: slugifyCity(data.name) }
  } catch { return null }
}

/** Resolve a city slug to its row (+ country meta). First match wins on slug collisions. */
export async function resolveCity(slug) {
  if (!slug) return null
  try {
    const { data: cities } = await supabase.from('cities').select('id, name, country_id').eq('status', 'active')
    const city = (cities || []).find(c => slugifyCity(c.name) === String(slug).toLowerCase())
    if (!city) return null
    let countryName = '', currencyCode = 'AED'
    if (city.country_id) {
      const { data: country } = await supabase.from('countries').select('name, currency_code').eq('id', city.country_id).maybeSingle()
      countryName = country?.name || ''
      currencyCode = country?.currency_code || 'AED'
    }
    return { id: city.id, name: city.name, slug: slugifyCity(city.name), countryId: city.country_id, countryName, currencyCode }
  } catch { return null }
}

/** Resolve a country slug to its row. First match wins on slug collisions. */
export async function resolveCountry(slug) {
  if (!slug) return null
  try {
    const { data: countries } = await supabase.from('countries').select('id, name, currency_code, iso_code').eq('status', 'active')
    const country = (countries || []).find(c => slugify(c.name) === String(slug).toLowerCase())
    if (!country) return null
    return { id: country.id, name: country.name, slug: slugify(country.name), currencyCode: country.currency_code || 'AED', iso: country.iso_code || '' }
  } catch { return null }
}

// Distinct venue TYPES (type chips) — derived from data so custom/legacy types are covered.
function summarizeTypes(all) {
  const t = {}
  for (const v of all) { if (!v.sport) continue; const sl = slugifySport(v.sport); if (!t[sl]) t[sl] = { name: v.sport, slug: sl, count: 0 }; t[sl].count++ }
  return Object.values(t).sort((a, b) => b.count - a.count)
}
// Distinct MAIN categories present (category chips).
function summarizeCategories(all) {
  const c = {}
  for (const v of all) { const key = v.category || 'sports'; if (!c[key]) c[key] = { key, slug: key, label: categoryLabelFor(key), count: 0 }; c[key].count++ }
  return Object.values(c).sort((a, b) => b.count - a.count)
}
// Filter `all` to the active segment (category OR type), returning the page mode.
function applySegment(all, segment) {
  if (!segment) return { mode: 'hub', category: null, typeName: null, venues: all }
  const cat = categoryFromSlug(segment)
  if (cat) return { mode: 'category', category: cat, typeName: null, venues: all.filter(v => (v.category || 'sports') === cat.key) }
  const venues = all.filter(v => v.sport && slugifySport(v.sport) === String(segment).toLowerCase())
  return { mode: 'type', category: null, typeName: venues[0]?.sport || segment, venues }
}

/**
 * Load venues for a PLACE (a city OR a country) with an optional second segment (a main
 * category or a venue type). Detects city vs country from the slug. Returns:
 *   { place:{type:'city'|'country', id, name, slug, currencyCode, countryName?, countrySlug?},
 *     mode, segment, category, typeName, venues, sports, categories, cities }
 * `cities` is the per-city drill-down list (country places only).
 */
export async function loadPlaceVenues(slug, segment = null) {
  const empty = { place: null, mode: null, segment: null, category: null, typeName: null, venues: [], sports: [], categories: [], cities: [] }

  const city = await resolveCity(slug)
  if (city) {
    const all = await loadFacilities(city.id)
    const place = { type: 'city', id: city.id, name: city.name, slug: city.slug, currencyCode: city.currencyCode, countryName: city.countryName, countrySlug: city.countryName ? slugify(city.countryName) : '' }
    return { place, segment, sports: summarizeTypes(all), categories: summarizeCategories(all), cities: [], ...applySegment(all, segment) }
  }

  const country = await resolveCountry(slug)
  if (country) {
    const { data: ccities } = await supabase.from('cities').select('id, name').eq('country_id', country.id).eq('status', 'active')
    const perCity = await Promise.all((ccities || []).map(async c => ({ c, list: await loadFacilities(c.id) })))
    const all = perCity.flatMap(x => x.list)
    const cities = perCity.filter(x => x.list.length).map(x => ({ name: x.c.name, slug: slugifyCity(x.c.name), count: x.list.length })).sort((a, b) => b.count - a.count)
    const place = { type: 'country', id: country.id, name: country.name, slug: country.slug, currencyCode: country.currencyCode }
    return { place, segment, sports: summarizeTypes(all), categories: summarizeCategories(all), cities, ...applySegment(all, segment) }
  }

  return empty
}

// Back-compat thin wrapper (city only). Prefer loadPlaceVenues.
export async function loadCityVenues(citySlug, segment = null) {
  const r = await loadPlaceVenues(citySlug, segment)
  return { ...r, city: r.place }
}

/** { from, currency } across a venue set (cheapest starting price), or null if none priced. */
export function priceRange(venues, currency = 'AED') {
  const prices = (venues || []).map(v => Number(v.price)).filter(p => p > 0)
  if (!prices.length) return null
  return { from: Math.min(...prices), currency }
}

/**
 * Directory of the whole marketplace for the footer hub + /venues directory:
 *   [{ country:{name,slug}, cities:[{ name, slug, count, topSports:[{name,slug,count}] }] }]
 * Bulk (4 queries, no per-city calls), using the SAME marketplace-visibility rule as the
 * storefront so it never links to empty pages. Cached after first load.
 */
let _directoryCache = null
export async function loadDirectory() {
  if (_directoryCache) return _directoryCache
  try {
    const [countriesRes, citiesRes, locsRes, listingsRes, progsRes] = await Promise.all([
      supabase.from('countries').select('id, name').eq('status', 'active'),
      supabase.from('cities').select('id, name, country_id').eq('status', 'active'),
      supabase.from('locations').select('id, city_id').eq('status', 'active'),
      supabase.from('listings').select('location_id, sport_category, show_on_marketplace, approval_status, eligibility_status'),
      supabase.from('class_programs').select('location_id, category, show_on_marketplace, approval_status, eligibility_status').eq('status', 'active'),
    ])
    const countries = countriesRes.data || [], cities = citiesRes.data || [], locs = locsRes.data || []
    const listings = listingsRes.data || [], programs = progsRes.data || []
    const cityIdOfLoc = Object.fromEntries(locs.map(l => [l.id, l.city_id]))
    const cityById = Object.fromEntries(cities.map(c => [c.id, c]))
    const countryById = Object.fromEntries(countries.map(c => [c.id, c]))
    // Same rule as data.js listingVisible()/programVisible() on the apex marketplace.
    const visible = (r) => r.show_on_marketplace !== false
      && (r.approval_status || 'approved') === 'approved'
      && (r.eligibility_status || 'eligible') !== 'paused'

    const cityAgg = {}   // cityId → { count, sports:{slug:{..}}, progCount, memberships:{key:{..}} }
    const ensure = (cid) => (cityAgg[cid] ||= { count: 0, sports: {}, progCount: 0, memberships: {} })
    for (const l of listings) {
      if (!visible(l)) continue
      const cid = cityIdOfLoc[l.location_id]; if (!cid) continue
      const a = ensure(cid); a.count++
      if (l.sport_category) {
        const sl = slugifySport(l.sport_category)
        if (!a.sports[sl]) a.sports[sl] = { name: l.sport_category, slug: sl, count: 0 }
        a.sports[sl].count++
      }
    }
    for (const p of programs) {
      if (!visible(p)) continue
      const cid = cityIdOfLoc[p.location_id]; if (!cid) continue
      const a = ensure(cid); a.progCount++
      const key = p.category || 'academy'
      if (!a.memberships[key]) a.memberships[key] = { key, slug: key, label: MEMBERSHIP_CAT(key), count: 0 }
      a.memberships[key].count++
    }

    const byCountry = {}
    for (const [cid, agg] of Object.entries(cityAgg)) {
      const city = cityById[cid]; if (!city || (!agg.count && !agg.progCount)) continue
      const country = countryById[city.country_id]
      const key = country?.id || '_'
      if (!byCountry[key]) byCountry[key] = { country: { name: country?.name || 'Other', slug: country ? slugify(country.name) : 'other' }, cities: [] }
      byCountry[key].cities.push({
        name: city.name, slug: slugifyCity(city.name), count: agg.count, programCount: agg.progCount,
        topSports: Object.values(agg.sports).sort((a, b) => b.count - a.count),
        memberships: Object.values(agg.memberships).sort((a, b) => b.count - a.count),
      })
    }
    const totals = g => g.cities.reduce((n, c) => n + c.count + c.programCount, 0)
    _directoryCache = Object.values(byCountry)
      .map(g => ({ country: g.country, cities: g.cities.sort((a, b) => (b.count + b.programCount) - (a.count + a.programCount)) }))
      .sort((a, b) => totals(b) - totals(a))
    return _directoryCache
  } catch { return [] }
}
