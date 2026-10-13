/**
 * Shared country/city filtering for admin list pages.
 *
 * Most records reach a country/city through their location:
 *   record.location_id → locations.city_id → cities.country_id
 * Some records carry city_id directly (venue requests, events, team members).
 *
 * Pass the page's already-loaded `locations` and `cities` arrays. IDs are
 * compared as strings so number/uuid mismatches don't bite.
 *
 * Filters are MULTI-SELECT: country/city filter values may be an array of ids
 * (empty = no filter), a single id, or '' (= no filter). Helpers normalize both.
 */

/** Normalize a filter value (array | single id | '' | null) → array of non-empty string ids. */
export function asIds(v) {
  if (Array.isArray(v)) return v.filter(x => x != null && x !== '').map(String)
  return (v == null || v === '') ? [] : [String(v)]
}

/** Does `value` pass a (possibly multi-select) filter? Empty filter = passes. */
export function idIn(value, filter) {
  const ids = asIds(filter)
  return !ids.length || (value != null && value !== '' && ids.includes(String(value)))
}

export function cityIdOfLocation(locationId, locations) {
  if (!locationId) return null
  const loc = (locations || []).find(l => String(l.id) === String(locationId))
  return loc ? loc.city_id : null
}

export function countryIdOfCity(cityId, cities) {
  if (!cityId) return null
  const c = (cities || []).find(x => String(x.id) === String(cityId))
  return c ? c.country_id : null
}

export function countryIdOfLocation(locationId, locations, cities) {
  return countryIdOfCity(cityIdOfLocation(locationId, locations), cities)
}

// Does a record whose city is `cityId` match the selected country/city filters?
// Country + city are AND-ed; each empty filter is ignored. Both accept arrays.
export function matchesCityGeo(cityId, cities, filterCountry, filterCity) {
  const countryIds = asIds(filterCountry)
  const cityIds = asIds(filterCity)
  if (!countryIds.length && !cityIds.length) return true
  if (cityIds.length && !idIn(cityId, cityIds)) return false
  if (countryIds.length && !idIn(countryIdOfCity(cityId, cities), countryIds)) return false
  return true
}

// Does a record at `locationId` match the selected country/city filters?
export function matchesLocationGeo(locationId, locations, cities, filterCountry, filterCity) {
  if (!asIds(filterCountry).length && !asIds(filterCity).length) return true
  return matchesCityGeo(cityIdOfLocation(locationId, locations), cities, filterCountry, filterCity)
}
