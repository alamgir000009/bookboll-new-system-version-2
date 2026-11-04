/**
 * customerSession.js — state for the public customer-facing site.
 *
 *  • Selected location (country + city) — chosen in the Location Gate, persisted.
 *  • Lightweight customer identity — email-based, looked up/created in `customers`,
 *    persisted locally. (Demo-grade: no password; this is a storefront, not the admin.)
 *  • Loyalty tier helpers shared by the home badge + Rewards page.
 */
import { ref, computed } from 'vue'

const LOC_KEY  = 'bb_customer_location'
const CUST_KEY = 'bb_customer_identity'

function readJSON(key) { try { return JSON.parse(localStorage.getItem(key) || 'null') } catch { return null } }

// ── Location ────────────────────────────────────────────────────────────────
const _loc = readJSON(LOC_KEY)
export const selectedCountry = ref(_loc?.country || null)  // { id, name, currency_code }
export const selectedCity    = ref(_loc?.city || null)     // { id, name, country_id }
export const hasLocation = computed(() => !!selectedCity.value)

export function setLocation(country, city) {
  selectedCountry.value = country || null
  selectedCity.value = city || null
  localStorage.setItem(LOC_KEY, JSON.stringify({ country: country || null, city: city || null }))
  ensureRegionIso()
}

/**
 * The stored country may predate the iso_code field (used for phone dial-code
 * defaults), so backfill it and re-persist. Reference-data already carries every
 * country the caller can see, so this costs nothing extra.
 */
export async function ensureRegionIso() {
  const c = selectedCountry.value
  if (!c || c.iso_code || !c.id) return
  try {
    const { loadReferenceData } = await import('./referenceData.js')
    const { countries } = await loadReferenceData()
    const match = (countries || []).find(x => String(x.id) === String(c.id))
    const iso = match?.iso_code || match?.iso2 || ''
    if (iso) {
      selectedCountry.value = { ...c, iso_code: iso }
      try { localStorage.setItem(LOC_KEY, JSON.stringify({ country: selectedCountry.value, city: selectedCity.value })) } catch { /* ignore */ }
    }
  } catch { /* best-effort backfill */ }
}
ensureRegionIso()   // backfill on load for already-saved locations

export function clearLocation() {
  selectedCountry.value = null; selectedCity.value = null
  localStorage.removeItem(LOC_KEY)
}

// First-visit location prompt. We auto-open the (non-blocking, dismissible) location modal
// ONCE so the visitor can confirm/correct the auto-picked city, then never again — the flag
// is persisted so returning users, and SEO crawlers, aren't re-prompted. Independent of
// whether a city is set (a default always is), so it's a confirmation, not a wall.
const LOC_PROMPTED_KEY = 'bb_location_prompted'
export const locationPrompted = ref(localStorage.getItem(LOC_PROMPTED_KEY) === '1')
export function markLocationPrompted() {
  locationPrompted.value = true
  try { localStorage.setItem(LOC_PROMPTED_KEY, '1') } catch { /* ignore */ }
}

// Visitor's precise coordinates — captured ONLY when they grant geolocation (e.g. the
// location modal's "Use my current location"). Persisted so "X km away" keeps showing
// across visits. Null until granted → cards simply omit the distance when we don't have it.
const COORDS_KEY = 'bb_customer_coords'
export const userCoords = ref(readJSON(COORDS_KEY))   // { lat, lng } | null
export function setUserCoords(lat, lng) {
  if (lat == null || lng == null) return
  userCoords.value = { lat: Number(lat), lng: Number(lng) }
  try { localStorage.setItem(COORDS_KEY, JSON.stringify(userCoords.value)) } catch { /* ignore */ }
}

// IANA timezone → ISO country, for the markets we operate in (+ a few common origins).
// Timezone is a better PHYSICAL-location signal than navigator.language (which is often the
// OS locale, e.g. en-US on a phone used in Qatar). Unknown zones fall through to language, then
// to the busiest city. Extend as new countries are added.
const TZ_TO_ISO = {
  'Asia/Dubai': 'AE', 'Asia/Qatar': 'QA', 'Asia/Bahrain': 'BH', 'Asia/Kuwait': 'KW',
  'Asia/Riyadh': 'SA', 'Asia/Muscat': 'OM', 'Africa/Cairo': 'EG', 'Europe/London': 'GB',
  'Asia/Singapore': 'SG', 'Europe/Lisbon': 'PT', 'Atlantic/Madeira': 'PT', 'Atlantic/Azores': 'PT',
  'Asia/Karachi': 'PK', 'Asia/Kolkata': 'IN',
}
/** Best-effort guess of the visitor's country ISO from timezone, then language region. */
export function guessCountryIso() {
  try {
    const tz = Intl.DateTimeFormat().resolvedOptions().timeZone
    if (tz && TZ_TO_ISO[tz]) return TZ_TO_ISO[tz]
  } catch { /* ignore */ }
  for (const l of (navigator.languages || [navigator.language] || [])) {
    const m = /[-_]([A-Za-z]{2})$/.exec(l || '')
    if (m) return m[1].toUpperCase()
  }
  return null
}

export function ensureBrandLocation(regions) {
  const served = regions?.cities || []
  if (!served.length) return
  if (selectedCity.value && served.some(c => c.id === selectedCity.value.id)) return
  const c0 = served[0]
  const country = (regions.countries || []).find(x => x.id === c0.country_id) || null
  setLocation(country, { id: c0.id, name: c0.name, country_id: c0.country_id })
}

// ── Customer identity ─────────────────────────────────────────────────────────
export const customer = ref(readJSON(CUST_KEY))   // { id, full_name, email, phone, country_id }
export const isCustomer = computed(() => !!customer.value?.id)

// ── Theme (customer storefront dark mode) ─────────────────────────────────────
// Applied as a class on <html>; only the customer `.cust` shell is themed, so the
// admin/auth areas are unaffected.
const THEME_KEY = 'bb_customer_theme'
// Automatic day/night DEFAULT: light during local daytime (07:00–18:59), dark at
// night (19:00–06:59), from the user's own clock. Exported so the admin dashboard
// (lib/adminTheme.js) applies the exact same rule. The computed default is NEVER
// written to localStorage — only an explicit toggle persists — so an untouched
// theme keeps adapting day to day, while a user choice always wins.
export function timeDefaultTheme(d = new Date()) {
  const h = d.getHours()
  return (h >= 7 && h < 19) ? 'light' : 'dark'
}
export const customerTheme = ref(localStorage.getItem(THEME_KEY) || timeDefaultTheme())
export function applyCustomerTheme() {
  if (typeof document !== 'undefined') document.documentElement.classList.toggle('cust-dark', customerTheme.value === 'dark')
}
export function toggleCustomerTheme() {
  customerTheme.value = customerTheme.value === 'dark' ? 'light' : 'dark'
  try { localStorage.setItem(THEME_KEY, customerTheme.value) } catch { /* ignore */ }
  applyCustomerTheme()
}
applyCustomerTheme()   // apply saved theme at load (no flash)

function persistCustomer(c) {
  customer.value = c
  if (c) localStorage.setItem(CUST_KEY, JSON.stringify(c))
  else localStorage.removeItem(CUST_KEY)
}

export const TIERS = [
  { name: 'Rookie', threshold: 0,     color: '#64748b', bg: '#f1f5f9', emoji: '🥉' },
  { name: 'Star',   threshold: 1000,  color: '#2563eb', bg: '#dbeafe', emoji: '⭐' },
  { name: 'Legend', threshold: 5000,  color: '#b45309', bg: '#fef3c7', emoji: '🔥' },
  { name: 'GOAT',   threshold: 15000, color: '#059669', bg: '#d1fae5', emoji: '🐐' },
]
export function tierForPoints(lifetime) {
  const pts = Number(lifetime || 0)
  return [...TIERS].reverse().find(t => pts >= t.threshold) || TIERS[0]
}
export function nextTier(lifetime) {
  const pts = Number(lifetime || 0)
  return TIERS.find(t => t.threshold > pts) || null
}
