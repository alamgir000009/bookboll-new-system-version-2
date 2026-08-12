/**
 * customerSession.js — state for the public customer-facing site.
 *
 *  • Selected location (country + city) — chosen in the Location Gate, persisted.
 *  • Lightweight customer identity — email-based, looked up/created in `customers`,
 *    persisted locally. (Demo-grade: no password; this is a storefront, not the admin.)
 *  • Loyalty tier helpers shared by the home badge + Rewards page.
 */
import { ref, computed } from 'vue'
import { supabase } from './supabase.js'

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

// The stored country may predate the iso_code field (used for phone dial-code defaults).
// Backfill it from the DB so `selectedCountry.iso_code` is always available + persisted.
export async function ensureRegionIso() {
  const c = selectedCountry.value
  if (!c || c.iso_code || !c.id) return
  const { data } = await supabase.from('countries').select('iso_code').eq('id', c.id).maybeSingle()
  if (data?.iso_code) {
    selectedCountry.value = { ...c, iso_code: data.iso_code }
    try { localStorage.setItem(LOC_KEY, JSON.stringify({ country: selectedCountry.value, city: selectedCity.value })) } catch { /* ignore */ }
  }
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

// First-visit default: instead of walling the storefront behind the country modal (bad for users
// AND crawlers), auto-pick a sensible city so the homepage renders real content immediately.
// Geo-aware: prefer the busiest city in the VISITOR'S country (timezone/language), else the busiest
// city overall. Best-effort + non-destructive: only runs when no city is set; the location chip
// still lets the user change it. Apex marketplace only. SEO note: per-city ranking is carried by
// the /venues/{city} landing pages — this default only affects a direct visitor's first view.
export async function ensureDefaultLocation() {
  if (selectedCity.value) return
  try {
    const { data: locs } = await supabase.from('locations').select('city_id').eq('status', 'active')
    const tally = {}
    ;(locs || []).forEach(l => { if (l.city_id) tally[l.city_id] = (tally[l.city_id] || 0) + 1 })
    const cityIds = Object.keys(tally)
    if (!cityIds.length) return
    const { data: cities } = await supabase.from('cities').select('id, name, country_id').in('id', cityIds)
    const byVenues = (a, b) => (tally[b.id] || 0) - (tally[a.id] || 0)

    let chosen = null
    const iso = guessCountryIso()
    if (iso) {
      const { data: countries } = await supabase.from('countries').select('id, iso_code')
      const ids = new Set((countries || []).filter(c => (c.iso_code || '').toUpperCase() === iso).map(c => c.id))
      const inCountry = (cities || []).filter(c => ids.has(c.country_id)).sort(byVenues)
      if (inCountry.length) chosen = inCountry[0]
    }
    if (!chosen) chosen = (cities || []).slice().sort(byVenues)[0]   // fallback: busiest overall
    if (!chosen) return

    const { data: country } = await supabase.from('countries').select('id, name, currency_code, iso_code').eq('id', chosen.country_id).maybeSingle()
    setLocation(country || null, { id: chosen.id, name: chosen.name, country_id: chosen.country_id })
  } catch { /* leave unset → the gate will prompt */ }
}

// White-label location default. A provider's site must always open on a city THEY serve —
// never on a city carried over from the Bookboll marketplace (same-origin localStorage) that
// they don't operate in. So: keep the current city only if this provider serves it; otherwise
// (nothing chosen, or a carried-over city they don't serve) land on their busiest served city.
// If they serve nowhere, leave it as-is — the storefront's "browse Bookboll" prompt handles it.
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

// Sign in / register a customer by email (creates the row if new). Demo-grade.
// `marketing` is an opt-in consent map { email, sms, whatsapp } collected at signup.
export async function customerSignIn({ email, fullName, phone, countryId, marketing }) {
  const cleanEmail = (email || '').trim().toLowerCase()
  if (!cleanEmail) return { error: 'Please enter your email.' }

  const { data: existing } = await supabase.from('customers').select('*').ilike('email', cleanEmail).maybeSingle()
  if (existing) { persistCustomer(existing); return { customer: existing } }

  // New customer
  const base = {
    full_name: (fullName || '').trim() || cleanEmail.split('@')[0],
    email: cleanEmail,
    phone: (phone || '').trim(),   // '' (not null) — customers.phone is NOT NULL

    country_id: countryId || selectedCountry.value?.id || null,
    status: 'active',
    loyalty_tier: 'Rookie',
    loyalty_points: 0,
  }
  const m = marketing || {}
  const row = {
    ...base,
    marketing_email: !!m.email,
    marketing_sms: !!m.sms,
    marketing_whatsapp: !!m.whatsapp,
  }
  let { data: created, error } = await supabase.from('customers').insert(row).select().maybeSingle()
  // Pre-migration: marketing_* columns may not exist yet — retry without them.
  if (error && /marketing_|column/i.test(error.message || '')) {
    const r = await supabase.from('customers').insert(base).select().maybeSingle()
    created = r.data; error = r.error
  }
  if (error) return { error: error.message }
  persistCustomer(created)
  return { customer: created }
}

export function customerSignOut() { persistCustomer(null) }

// ── Google (demo) + phone-OTP sign-up ──────────────────────────────────────────
// DEMO Google: a real Google identity would come back from supabase.auth.signInWithOAuth;
// here the AuthGate hands us the {email, firstName, lastName} the user "signed in with". If that
// email already has an account we sign them straight in; a new email goes to the "complete your
// account" + OTP step (nothing is written until the phone is verified).
export async function customerGoogleDemo({ email, firstName, lastName }) {
  const cleanEmail = (email || '').trim().toLowerCase()
  if (!cleanEmail) return { error: 'A Google email is required.' }
  const { data: existing } = await supabase.from('customers').select('*').ilike('email', cleanEmail).maybeSingle()
  if (existing) { persistCustomer(existing); return { customer: existing, existing: true } }
  return { needsComplete: true, prefill: { email: cleanEmail, firstName: (firstName || '').trim(), lastName: (lastName || '').trim() } }
}

// Send a verification code to `phone` over WhatsApp (preferred) or SMS. Returns
// { ok, channel, delivered, demoCode? } — demoCode is present only while the function runs in
// demo-echo mode (for testing without a live WhatsApp/SMS delivery).
export async function otpSend({ phone, channel = 'whatsapp', purpose = 'customer_signup' }) {
  try {
    const { data, error } = await supabase.functions.invoke('otp', { body: { action: 'send', phone, channel, purpose } })
    if (error) return { ok: false, error: 'Could not send the code — please try again.' }
    return data || { ok: false, error: 'No response.' }
  } catch { return { ok: false, error: 'Could not reach the verification service.' } }
}

export async function otpVerify({ phone, code }) {
  try {
    const { data, error } = await supabase.functions.invoke('otp', { body: { action: 'verify', phone, code } })
    if (error) return { ok: false, error: 'Verification failed — please try again.' }
    return data || { ok: false, error: 'No response.' }
  } catch { return { ok: false, error: 'Could not reach the verification service.' } }
}

// Create the customer AFTER the phone is verified (Google/complete-account flow). Splits the
// name and marks the phone verified + the provider. Degrades gracefully pre-migration.
export async function completeCustomerSignup({ email, firstName, lastName, phone, countryId, provider = 'google', marketing }) {
  const cleanEmail = (email || '').trim().toLowerCase()
  if (!cleanEmail) return { error: 'Email is required.' }
  // Guard: if the account was created in a parallel tab meanwhile, just sign in.
  const { data: existing } = await supabase.from('customers').select('*').ilike('email', cleanEmail).maybeSingle()
  if (existing) { persistCustomer(existing); return { customer: existing } }

  const fn = (firstName || '').trim(), ln = (lastName || '').trim()
  const m = marketing || {}
  const base = {
    full_name: `${fn} ${ln}`.trim() || cleanEmail.split('@')[0],
    email: cleanEmail,
    phone: (phone || '').trim(),
    country_id: countryId || selectedCountry.value?.id || null,
    status: 'active', loyalty_tier: 'Rookie', loyalty_points: 0,
    marketing_email: !!m.email, marketing_sms: !!m.sms, marketing_whatsapp: !!m.whatsapp,
  }
  const full = { ...base, first_name: fn, last_name: ln, phone_verified: true, auth_provider: provider }

  let { data: created, error } = await supabase.from('customers').insert(full).select().maybeSingle()
  // Pre-migration fallbacks: drop the new auth columns, then the marketing columns, if missing.
  if (error && /first_name|last_name|phone_verified|auth_provider|column/i.test(error.message || '')) {
    const r = await supabase.from('customers').insert(base).select().maybeSingle()
    created = r.data; error = r.error
  }
  if (error && /marketing_|column/i.test(error.message || '')) {
    const { marketing_email, marketing_sms, marketing_whatsapp, ...noMkt } = base
    const r = await supabase.from('customers').insert(noMkt).select().maybeSingle()
    created = r.data; error = r.error
  }
  if (error) return { error: error.message }
  persistCustomer(created)
  return { customer: created }
}

// Update the signed-in customer's profile (name, avatar_url, photos…) and refresh the
// locally-stored identity. Best-effort: if a column isn't migrated yet, the change still
// shows for this session (and persists once the migration is applied).
export async function updateCustomerProfile(patch) {
  if (!customer.value?.id) return { error: 'Not signed in' }
  let { data, error } = await supabase.from('customers').update(patch).eq('id', customer.value.id).select().maybeSingle()
  // Pre-migration: optional columns may not exist yet — retry without them so the rest of the
  // profile still saves. Drop the newest columns first (marketing_*), then fall back to dropping
  // the social columns (bio/is_private) for a very old DB.
  if (error && /marketing_|column/i.test(error.message || '')) {
    const { marketing_email, marketing_sms, marketing_whatsapp, marketing_updated_at, ...noMkt } = patch
    let r = await supabase.from('customers').update(noMkt).eq('id', customer.value.id).select().maybeSingle()
    if (r.error && /bio|is_private|column/i.test(r.error.message || '')) {
      const { bio, is_private, ...safe } = noMkt
      r = await supabase.from('customers').update(safe).eq('id', customer.value.id).select().maybeSingle()
    }
    data = r.data; error = r.error
  } else if (error && /bio|is_private|column/i.test(error.message || '')) {
    const { bio, is_private, ...safe } = patch
    const r = await supabase.from('customers').update(safe).eq('id', customer.value.id).select().maybeSingle()
    data = r.data; error = r.error
  }
  persistCustomer(data || { ...customer.value, ...patch })
  return { ok: true }
}

// ── Loyalty tiers (mirror of the admin per-country model) ──────────────────────
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

// Load the signed-in customer's loyalty for the selected country (falls back to
// their highest across countries). Returns { points, lifetime, tier, next, progress }.
export async function loadCustomerLoyalty() {
  if (!customer.value?.id) return null
  const { data } = await supabase.from('customer_loyalty').select('*').eq('customer_id', customer.value.id)
  const rows = data || []
  if (!rows.length) {
    const tier = tierForPoints(0)
    return { points: 0, lifetime: 0, tier, next: nextTier(0), progress: 0, countryId: selectedCountry.value?.id || null }
  }
  // Prefer the selected country; else the row with the most lifetime points.
  let row = selectedCountry.value?.id ? rows.find(r => r.country_id === selectedCountry.value.id) : null
  if (!row) row = [...rows].sort((a, b) => Number(b.lifetime_points || 0) - Number(a.lifetime_points || 0))[0]
  const lifetime = Number(row.lifetime_points || 0)
  const tier = tierForPoints(lifetime)
  const next = nextTier(lifetime)
  const progress = next ? Math.min(100, Math.round(((lifetime - tier.threshold) / (next.threshold - tier.threshold)) * 100)) : 100
  return { points: Number(row.points || 0), lifetime, tier, next, progress, countryId: row.country_id }
}
