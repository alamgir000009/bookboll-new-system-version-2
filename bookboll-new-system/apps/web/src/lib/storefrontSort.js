/**
 * storefrontSort.js — how the customer storefront orders venues & spaces.
 *
 * Rule (same for both views, "by space" and "by venue"):
 *   • If the visitor has shared their location → NEAREST FIRST (distance ascending).
 *     Items without coordinates sink below the located ones, ordered by the configured key.
 *   • If location is unavailable → fall back to the configured sort key chosen in the
 *     dashboard Listings page (super-admin platform-level on the Bookboll marketplace,
 *     or the provider's own choice on their white-label site).
 *
 * The configured key per view is resolved by brand.js `storefrontSortKey('space'|'venue')`.
 * Card items carry: { name, price, lat, lng, sortOrder, createdAt }.
 */
import { haversineKm } from './distance.js'

export const SORT_OPTIONS = [
  { key: 'recommended', label: 'Recommended' },
  { key: 'newest',      label: 'Newest first' },
  { key: 'price_asc',   label: 'Price: low to high' },
  { key: 'price_desc',  label: 'Price: high to low' },
  { key: 'name',        label: 'Name (A–Z)' },
]
const SORT_KEYS = SORT_OPTIONS.map(o => o.key)

export function normalizeSortKey(k) { return SORT_KEYS.includes(k) ? k : 'recommended' }
export function sortLabel(k) { return (SORT_OPTIONS.find(o => o.key === k) || SORT_OPTIONS[0]).label }

// Order by a configured key (no proximity).
function byKey(items, key) {
  const a = [...items]
  switch (normalizeSortKey(key)) {
    case 'newest':     a.sort((x, y) => new Date(y.createdAt || 0) - new Date(x.createdAt || 0)); break
    case 'price_asc':  a.sort((x, y) => (x.price ?? Infinity) - (y.price ?? Infinity)); break
    case 'price_desc': a.sort((x, y) => (y.price ?? -1) - (x.price ?? -1)); break
    case 'name':       a.sort((x, y) => (x.name || '').localeCompare(y.name || '', undefined, { sensitivity: 'base' })); break
    default:           a.sort((x, y) => Number(x.sortOrder ?? 9999) - Number(y.sortOrder ?? 9999)) // recommended (manual order)
  }
  return a
}

/**
 * Final storefront ordering.
 * @param items  card rows ({ name, price, lat, lng, sortOrder, createdAt })
 * @param opts   { key: configured fallback sort, coords: { lat, lng } | null }
 */
export function applyStorefrontSort(items, { key = 'recommended', coords = null } = {}) {
  if (!coords || coords.lat == null || coords.lng == null) return byKey(items, key)
  const tagged = items.map(it => ({
    it,
    d: (it.lat != null && it.lng != null) ? haversineKm(coords.lat, coords.lng, it.lat, it.lng) : Infinity,
  }))
  const located = tagged.filter(t => t.d !== Infinity).sort((a, b) => a.d - b.d).map(t => t.it)
  const unlocated = byKey(tagged.filter(t => t.d === Infinity).map(t => t.it), key)
  return [...located, ...unlocated]
}
