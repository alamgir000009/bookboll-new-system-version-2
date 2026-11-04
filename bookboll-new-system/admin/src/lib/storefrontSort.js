/** Customer defaults are independent for listings and venues.
 * Location changes the order only when a customer selects Closest first. */
import { haversineKm } from './distance.js'
export const SORT_OPTIONS = [
  { key: 'recommended', label: 'Recommended' },
  { key: 'newest', label: 'Newest first' },
  { key: 'price_asc', label: 'Price: low to high' },
  { key: 'price_desc', label: 'Price: high to low' },
  { key: 'name', label: 'Name (A–Z)' },
]
export const CUSTOMER_SORT_OPTIONS = [...SORT_OPTIONS, { key: 'nearest', label: 'Closest first' }]
export function normalizeSortKey(key) { return SORT_OPTIONS.some(o => o.key === key) ? key : 'recommended' }
export function sortLabel(key) { return (CUSTOMER_SORT_OPTIONS.find(o => o.key === key) || SORT_OPTIONS[0]).label }
export function resolveStorefrontSort(view, providerSort, platformSort) {
  // Businesses without a configured value use Recommended, never platform defaults.
  return normalizeSortKey((providerSort ?? platformSort)?.[view === 'venue' ? 'venue' : 'space'])
}
const number = value => value !== '' && value != null && Number.isFinite(Number(value)) ? Number(value) : null
export function hasCoordinates(coords) {
  const lat = number(coords?.lat), lng = number(coords?.lng)
  return lat != null && lng != null && Math.abs(lat) <= 90 && Math.abs(lng) <= 180
}
const rank = item => number(item.sortOrder) ?? Infinity
const date = item => Number.isFinite(Date.parse(item.createdAt)) ? Date.parse(item.createdAt) : 0
function compare(a, b, key) {
  if (key === 'name') return String(a.name || '').localeCompare(String(b.name || ''), undefined, { sensitivity: 'base' })
  if (key === 'newest') return date(b) - date(a)
  if (key === 'price_asc' || key === 'price_desc') {
    const x = number(a.price), y = number(b.price)
    if (x == null || y == null) return x == null ? (y == null ? 0 : 1) : -1
    return key === 'price_asc' ? x - y : y - x
  }
  return rank(a) - rank(b)
}
export function applyStorefrontSort(items, { key = 'recommended', coords = null } = {}) {
  const recommended = (a, b) => compare(a, b, 'recommended') || 0
  if (key === 'nearest' && hasCoordinates(coords)) {
    const distance = item => hasCoordinates(item) ? haversineKm(Number(coords.lat), Number(coords.lng), Number(item.lat), Number(item.lng)) : Infinity
    return items.map(item => ({ item, distance: distance(item) }))
      .sort((a, b) => (a.distance - b.distance) || recommended(a.item, b.item)).map(x => x.item)
  }
  return [...items].sort((a, b) => compare(a, b, normalizeSortKey(key)) || recommended(a, b))
}
