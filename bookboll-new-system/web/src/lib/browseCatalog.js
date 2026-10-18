import { categoryLabel } from './venueCategories.js'

// Sample once when the catalog changes, never while rendering a card. Each photo
// represents a different listing, and duplicate images are skipped.
export function sampleListingPhotos(listings, random = Math.random) {
  const pool = [...listings]
  for (let i = pool.length - 1; i > 0; i--) {
    const j = Math.floor(random() * (i + 1))
    ;[pool[i], pool[j]] = [pool[j], pool[i]]
  }
  const photos = []
  for (const listing of pool) {
    const photo = [listing.photo, ...(listing.photos || [])].find(p => p && !photos.includes(p))
    if (photo) photos.push(photo)
    if (photos.length === 3) break
  }
  return photos
}

export function catalogCategories(listings, clickCounts = {}) {
  const groups = new Map()
  for (const listing of listings) {
    const category = listing.category || 'sports'
    const type = listing.sport || ''
    const key = `${category}:${type}`
    if (!groups.has(key)) groups.set(key, { key, category, type, label: type || categoryLabel(category), listings: [] })
    groups.get(key).listings.push(listing)
  }
  const clicks = key => Number.isFinite(Number(clickCounts[key])) && Number(clickCounts[key]) >= 0 ? Number(clickCounts[key]) : 0
  return [...groups.values()].sort((a, b) => clicks(b.key) - clicks(a.key) || a.label.localeCompare(b.label) || a.key.localeCompare(b.key))
}

export function filterCatalog(listings, { q = '', category = '', type = '' } = {}) {
  const words = q.trim().toLocaleLowerCase().split(/\s+/).filter(Boolean)
  return listings.filter(l => {
    if (category && (l.category || 'sports') !== category) return false
    if (type && l.sport !== type) return false
    const text = [l.name, l.pinText, l.venueName, l.cityName, l.sport, categoryLabel(l.category)].filter(Boolean).join(' ').toLocaleLowerCase()
    return words.every(word => text.includes(word))
  })
}

// Group the matching listings, so a venue's category, count and starting price
// always describe the results the customer is actually looking at.
export function groupCatalogVenues(listings, venues = []) {
  const known = new Map(venues.map(v => [String(v.locationId || v.id), v]))
  const groups = new Map()
  for (const l of listings) {
    if (l.locationId == null) continue
    const key = String(l.locationId)
    if (!groups.has(key)) groups.set(key, [])
    groups.get(key).push(l)
  }
  return [...groups].map(([id, items]) => {
    const first = items[0]
    const venue = known.get(id)
    const prices = items.map(l => l.price).filter(p => p != null && Number.isFinite(Number(p))).map(Number)
    return {
      ...first, ...venue, id, locationId: id,
      name: venue?.name || first.venueName || first.pinText || 'Venue',
      photo: venue?.photo || first.photo,
      sortOrder: venue?.sortOrder ?? null,
      createdAt: venue?.createdAt ?? null,
      price: prices.length ? Math.min(...prices) : null,
      listingCount: items.length,
      sportsText: [...new Set(items.map(l => l.sport).filter(Boolean))].join(', '),
    }
  })
}

export function readBrowseQuery(query) {
  const text = key => typeof query[key] === 'string' ? query[key] : ''
  const view = ['venues', 'location'].includes(text('view')) ? 'venues' : 'listings'
  const choice = text(view === 'venues' ? 'venue_sort' : 'listing_sort') || text('sort')
  return {
    view,
    q: text('q'), category: text('category'), type: text('type'),
    sort: ['recommended', 'newest', 'price_asc', 'price_desc', 'price-asc', 'price-desc', 'name', 'nearest'].includes(choice) ? choice : 'rec',
  }
}

// Only allow local catalog/search destinations in back links supplied through URLs.
export function browseReturnPath(value) {
  return typeof value === 'string' && /^\/(?:ar\/)?(?:(?:facilities|venues)(?:\?|\/|$)|search(?:\?|$))/.test(value)
    ? value : '/facilities'
}
