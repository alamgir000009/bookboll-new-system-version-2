// Search only catalog fields already exposed by the public marketplace APIs.
// Profiles need a dedicated public search endpoint; never use admin customer data.
export const SEARCH_TYPES = ['all', 'listings', 'communities', 'memberships', 'events', 'people']
export const CATALOG_TYPES = SEARCH_TYPES.filter(type => !['all', 'people'].includes(type))

export function readSearchQuery(query = {}) {
  return {
    q: typeof query.q === 'string' ? query.q.trim().slice(0, 120) : '',
    type: SEARCH_TYPES.includes(query.type) ? query.type : 'all',
  }
}

export function emptySearchCatalog() {
  return Object.fromEntries(CATALOG_TYPES.map(type => [type, []]))
}

function normalize(text) {
  return String(text || '').normalize('NFKD').replace(/\p{M}/gu, '').toLocaleLowerCase().replace(/\s+/g, ' ').trim()
}

export function searchCatalog(items, query, type) {
  const needle = normalize(query)
  if (needle.length < 2) return []
  const words = needle.split(' ')
  return items.map(item => {
    const name = normalize(item.name || item.title)
    const fields = type === 'listings'
      ? [item.name, item.sport, item.pinText, item.venueName, item.cityName, item.description]
      : type === 'communities'
        ? [item.name, item.sport, ...(item.sports || []), item.description, item.cityName]
        : type === 'memberships'
          ? [item.name, item.sport, item.category, item.description, item.locationName, item.venueName, item.cityName]
          : [item.title, item.sport, item.description, item.hostName, item.communityName, item.locationName, item.venueName, item.custom_location_name, item.location_text]
    const haystack = normalize(fields.filter(Boolean).join(' '))
    const matches = words.every(word => haystack.includes(word))
    const score = name === needle ? 3 : name.startsWith(needle) ? 2 : name.includes(needle) ? 1 : 0
    return { item, matches, score }
  }).filter(result => result.matches).sort((a, b) => b.score - a.score).map(result => result.item)
}

export async function fetchSearchCatalog(request, { city, country, enabled = CATALOG_TYPES } = {}) {
  const cityQuery = city?.id ? { city_id: city.id } : {}
  const regionalQuery = { ...cityQuery, ...(country?.iso_code ? { country_iso: country.iso_code } : {}) }
  // Listings also accept a city name for compatibility with older UUID pickers.
  const listingQuery = { ...regionalQuery, ...(city?.name ? { city: city.name } : {}) }
  if (listingQuery.city_id && !/^\d+$/.test(String(listingQuery.city_id))) delete listingQuery.city_id
  const sources = [
    { type: 'listings', path: '/marketplace/listings', key: 'listings', query: listingQuery },
    { type: 'communities', path: '/marketplace/communities', key: 'communities', query: cityQuery },
    { type: 'memberships', path: '/marketplace/programs', key: 'programs', query: regionalQuery },
    { type: 'events', path: '/marketplace/games', key: 'games', query: regionalQuery },
  ].filter(source => enabled.includes(source.type))
  const responses = await Promise.allSettled(sources.map(source => request(source.path, { query: source.query })))
  const catalog = emptySearchCatalog()
  const failed = []
  responses.forEach((result, i) => {
    const source = sources[i]
    if (result.status !== 'fulfilled' || !result.value.success || !Array.isArray(result.value.data?.[source.key])) {
      failed.push(source.type)
      return
    }
    catalog[source.type] = result.value.data[source.key]
  })
  return { catalog, failed }
}
