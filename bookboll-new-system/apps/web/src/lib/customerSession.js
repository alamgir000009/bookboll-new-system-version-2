/**
 * customerSession.js — state for the public customer-facing site.
 *
 *  • Selected location (country + city) — chosen in the Location Gate, persisted.
 *  • Auth is Laravel Sanctum (email/password or Google). A matching `customers`
 *    row is upserted so games/communities/loyalty still key off that UUID.
 *  • Loyalty tier helpers shared by the home badge + Rewards page.
 */
import { ref, computed } from 'vue'
import { supabase } from './supabase.js'
import { apiFetch, getCustomerToken, setCustomerToken, clearCustomerToken } from './api.js'

const LOC_KEY  = 'bb_customer_location'
const CUST_KEY = 'bb_customer_identity'

function readJSON(key) { try { return JSON.parse(localStorage.getItem(key) || 'null') } catch { return null } }

function isLaravelGeoId(id) {
  return id != null && id !== '' && /^\d+$/.test(String(id))
}

// ── Location ────────────────────────────────────────────────────────────────
const _loc = readJSON(LOC_KEY)
export const selectedCountry = ref(_loc?.country || null)  // { id, name, currency_code, iso_code }
export const selectedCity    = ref(_loc?.city || null)     // { id, name, country_id }
export const hasLocation = computed(() => !!selectedCity.value)

// Drop mockup Supabase UUID locations so catalog city_id matches Laravel venues.
if ((selectedCity.value?.id && !isLaravelGeoId(selectedCity.value.id))
  || (selectedCountry.value?.id && !isLaravelGeoId(selectedCountry.value.id))) {
  selectedCountry.value = null
  selectedCity.value = null
  try { localStorage.removeItem(LOC_KEY) } catch { /* ignore */ }
}

export function setLocation(country, city) {
  selectedCountry.value = country || null
  selectedCity.value = city || null
  localStorage.setItem(LOC_KEY, JSON.stringify({ country: country || null, city: city || null }))
  ensureRegionIso()
}

// Backfill iso_code on stored country rows (picker/API may omit it on older saves).
export async function ensureRegionIso() {
  const c = selectedCountry.value
  if (!c || c.iso_code || !isLaravelGeoId(c.id)) return
  const res = await apiFetch('/marketplace/countries')
  if (!res.success) return
  const match = (res.data?.countries || []).find(x => x.id === c.id)
  if (match?.iso_code) {
    selectedCountry.value = { ...c, iso_code: match.iso_code, currency_code: match.currency_code || c.currency_code }
    try { localStorage.setItem(LOC_KEY, JSON.stringify({ country: selectedCountry.value, city: selectedCity.value })) } catch { /* ignore */ }
  }
}
ensureRegionIso()
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
    const iso = guessCountryIso()
    const res = await apiFetch('/marketplace/default-city', { query: iso ? { country_iso: iso } : null })
    if (!res.success || !res.data?.city) return
    const country = res.data.country || null
    const city = res.data.city
    setLocation(country, { id: city.id, name: city.name, country_id: city.country_id })
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
  if (selectedCity.value?.id && !isLaravelGeoId(selectedCity.value.id)) {
    selectedCity.value = null
    selectedCountry.value = null
  }
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

function formatApiError(res, fallback) {
  const errors = res?.errors
  if (errors && typeof errors === 'object') {
    for (const v of Object.values(errors)) {
      const msg = Array.isArray(v) ? v[0] : v
      if (msg) return String(msg)
    }
  }
  return res?.message || fallback
}

/** Find or create the storefront `customers` row used by games/communities/loyalty. */
async function upsertStorefrontCustomer({ email, fullName, phone, countryId, marketing, avatarUrl }) {
  const cleanEmail = (email || '').trim().toLowerCase()
  if (!cleanEmail) return { error: 'Please enter your email.' }

  const { data: existing } = await supabase.from('customers').select('*').ilike('email', cleanEmail).maybeSingle()
  if (existing) return { customer: existing }

  const base = {
    full_name: (fullName || '').trim() || cleanEmail.split('@')[0],
    email: cleanEmail,
    phone: (phone || '').trim(),
    country_id: countryId || selectedCountry.value?.id || null,
    status: 'active',
    loyalty_tier: 'Rookie',
    loyalty_points: 0,
  }
  if (avatarUrl) base.avatar_url = avatarUrl
  const m = marketing || {}
  const row = {
    ...base,
    marketing_email: !!m.email,
    marketing_sms: !!m.sms,
    marketing_whatsapp: !!m.whatsapp,
  }
  let { data: created, error } = await supabase.from('customers').insert(row).select().maybeSingle()
  if (error && /marketing_|avatar_url|column/i.test(error.message || '')) {
    const r = await supabase.from('customers').insert(base).select().maybeSingle()
    created = r.data; error = r.error
  }
  if (error && /avatar_url|column/i.test(error.message || '')) {
    const { avatar_url: _avatar, ...noAvatar } = base
    const r = await supabase.from('customers').insert(noAvatar).select().maybeSingle()
    created = r.data; error = r.error
  }
  if (error) return { error: error.message }
  return { customer: created }
}

function mapLaravelUserToCustomer(user, preferences = {}) {
  const marketingEmail = !!(preferences?.notifications?.marketing_emails)
  return {
    id: user?.uuid || user?.id || '',
    full_name: user?.name || '',
    email: user?.email || '',
    phone: user?.phone || '',
    avatar_url: user?.image || '',
    bio: user?.bio || '',
    photos: Array.isArray(user?.photos) ? user.photos.filter(Boolean) : [],
    is_private: user?.is_private === true,
    marketing_email: marketingEmail,
    laravel_user_id: user?.id || null,
  }
}

export async function applyLaravelAuth(user, token, { marketing } = {}) {
  if (!token) return { error: 'Invalid login response.' }
  if (!user?.uuid) {
    return { error: 'Your account is missing a public profile ID. Please contact support.' }
  }
  setCustomerToken(token)
  const merged = mapLaravelUserToCustomer(user, {
    notifications: {
      marketing_emails: marketing?.email,
    },
  })
  if (marketing) {
    merged.marketing_email = !!marketing.email
    merged.marketing_sms = !!marketing.sms
    merged.marketing_whatsapp = !!marketing.whatsapp
  }
  persistCustomer(merged)
  clearCustomerLoyaltyCache()
  return { customer: customer.value, user }
}

export async function customerLogin({ email, password }) {
  const cleanEmail = (email || '').trim().toLowerCase()
  if (!cleanEmail) return { error: 'Please enter your email.' }
  if (!password) return { error: 'Please enter your password.' }
  const res = await apiFetch('/customer/login', { method: 'POST', body: { email: cleanEmail, password } })
  if (res.data?.reset_password === true) return { resetPassword: true }
  if (!res.success || !res.data?.token) return { error: formatApiError(res, 'Login failed. Please try again.') }
  return applyLaravelAuth(res.data.user, res.data.token)
}

export async function customerRegister({ name, email, phone, password, passwordConfirmation, marketing }) {
  const cleanEmail = (email || '').trim().toLowerCase()
  const fullName = (name || '').trim()
  if (!fullName) return { error: 'Please enter your name.' }
  if (!cleanEmail) return { error: 'Please enter your email.' }
  if (!phone || String(phone).replace(/\D/g, '').length < 6) return { error: 'Please enter a valid phone number.' }
  if (!password || password.length < 8) return { error: 'Password must be at least 8 characters.' }
  if (password !== passwordConfirmation) return { error: 'Passwords do not match.' }
  const res = await apiFetch('/register', {
    method: 'POST',
    body: {
      name: fullName,
      email: cleanEmail,
      phone: String(phone).trim(),
      password,
      password_confirmation: passwordConfirmation,
    },
  })
  if (!res.success || !res.data?.token) return { error: formatApiError(res, 'Could not create your account.') }
  return applyLaravelAuth(res.data.user, res.data.token, { marketing })
}

export async function customerGoogleLogin(idToken) {
  if (!idToken) return { error: 'Google sign-in failed. Please try again.' }
  const res = await apiFetch('/customer/google-login', { method: 'POST', body: { id_token: idToken } })
  if (!res.success || !res.data?.token) return { error: formatApiError(res, 'Google login failed. Please try again.') }
  return applyLaravelAuth(res.data.user, res.data.token)
}

export async function customerForgotPassword(email) {
  const cleanEmail = (email || '').trim().toLowerCase()
  if (!cleanEmail) return { error: 'Please enter your email.' }
  const res = await apiFetch('/customer/forgot-password', { method: 'POST', body: { email: cleanEmail } })
  if (!res.success) return { error: formatApiError(res, 'Failed to send OTP. Please try again.') }
  return { ok: true, message: res.message || 'OTP has been sent to your email.' }
}

export async function customerResetPassword({ email, otp, password, passwordConfirmation }) {
  const cleanEmail = (email || '').trim().toLowerCase()
  if (!cleanEmail) return { error: 'Please enter your email.' }
  if (!otp) return { error: 'Please enter the OTP from your email.' }
  if (!password || password.length < 8) return { error: 'Password must be at least 8 characters.' }
  if (password !== passwordConfirmation) return { error: 'Passwords do not match.' }
  const res = await apiFetch('/customer/reset-password', {
    method: 'POST',
    body: {
      email: cleanEmail,
      otp: String(otp).trim(),
      password,
      password_confirmation: passwordConfirmation,
    },
  })
  if (!res.success) return { error: formatApiError(res, 'Failed to reset password. Please check your OTP and try again.') }
  if (res.data?.token) return applyLaravelAuth(res.data.user, res.data.token)
  return { ok: true, message: res.message || 'Password has been reset successfully.' }
}

// Guest checkout fallback: create/find the storefront identity without a password.
export async function customerSignIn({ email, fullName, phone, countryId, marketing }) {
  const linked = await upsertStorefrontCustomer({ email, fullName, phone, countryId, marketing })
  if (linked.error) return linked
  persistCustomer(linked.customer)
  return { customer: linked.customer }
}

export async function customerSignOut() {
  if (getCustomerToken()) {
    try { await apiFetch('/logout', { method: 'POST' }) } catch { /* ignore */ }
  }
  clearCustomerToken()
  clearCustomerLoyaltyCache()
  persistCustomer(null)
}

// Update the signed-in customer's profile (name, avatar_url, photos…) and refresh the
// locally-stored identity. Best-effort: if a column isn't migrated yet, the change still
// shows for this session (and persists once the migration is applied).
export async function updateCustomerProfile(patch) {
  if (!getCustomerToken()) return { error: 'Not signed in' }
  const body = {}
  if ('full_name' in patch) body.name = patch.full_name
  if ('bio' in patch) body.bio = patch.bio ?? ''
  if ('photos' in patch) body.photos = Array.isArray(patch.photos) ? patch.photos.filter(Boolean) : []
  if ('is_private' in patch) body.is_private = !!patch.is_private

  if (Object.keys(body).length) {
    const res = await apiFetch('/customer/update-profile', { method: 'POST', body })
    if (!res.success) return { error: formatApiError(res, 'Could not save your profile.') }
    const user = res.data?.user || {}
    persistCustomer({
      ...(customer.value || {}),
      ...mapLaravelUserToCustomer(user, { notifications: { marketing_emails: customer.value?.marketing_email } }),
      ...(patch.avatar_url ? { avatar_url: patch.avatar_url } : {}),
    })
    return { ok: true }
  }

  persistCustomer({ ...(customer.value || {}), ...patch })
  return { ok: true }
}

function mergeLaravelUser(user, preferences) {
  const current = customer.value || {}
  return {
    ...current,
    ...mapLaravelUserToCustomer(user, preferences),
  }
}

export async function loadCustomerAccount() {
  if (!getCustomerToken()) return { error: 'Please sign in to see your account.' }
  const [profile, prefs] = await Promise.all([
    apiFetch('/customer/profile'),
    apiFetch('/customer/preferences'),
  ])
  if (!profile.success) return { error: formatApiError(profile, 'Could not load your account.') }
  const user = profile.data?.user || {}
  const preferences = prefs.success ? (prefs.data?.preferences || {}) : {}
  const merged = mergeLaravelUser(user, preferences)
  persistCustomer(merged)
  return { ok: true, customer: merged }
}

export async function saveCustomerAccount(patch) {
  if (!getCustomerToken()) return { error: 'Please sign in to save your account.' }
  const name = String(patch?.full_name || '').trim()
  if (!name) return { error: 'Please enter your name.' }
  if (name.length > 100) return { error: 'Name must be 100 characters or fewer.' }

  const profileRes = await apiFetch('/customer/update-profile', {
    method: 'POST',
    body: {
      name,
      bio: patch.bio ?? '',
      photos: Array.isArray(patch.photos) ? patch.photos.filter(Boolean) : [],
      is_private: !!patch.is_private,
    },
  })
  if (!profileRes.success) return { error: formatApiError(profileRes, 'Could not save your profile.') }

  const prefsRes = await apiFetch('/customer/preferences', {
    method: 'POST',
    body: { notifications: { marketing_emails: !!patch.marketing_email } },
  })
  if (!prefsRes.success) return { error: formatApiError(prefsRes, 'Profile saved, but marketing preferences could not be updated.') }

  const user = profileRes.data?.user || {}
  persistCustomer({
    ...mergeLaravelUser(user, prefsRes.data?.preferences || {}),
    marketing_sms: !!patch.marketing_sms,
    marketing_whatsapp: !!patch.marketing_whatsapp,
    avatar_url: patch.avatar_url || user?.image || customer.value?.avatar_url || '',
  })
  return { ok: true }
}

export async function uploadCustomerAvatar(file) {
  if (!file || !String(file.type || '').startsWith('image/')) return { error: 'Please choose a valid image file.' }
  if (!getCustomerToken()) return { error: 'Please sign in to update your photo.' }
  const body = new FormData()
  body.append('image', file)
  const res = await apiFetch('/customer/update-profile-image', { method: 'POST', body })
  if (!res.success) return { error: formatApiError(res, 'Could not update your profile picture.') }
  const url = res.data?.image || ''
  if (!url) return { error: 'Could not update your profile picture.' }
  persistCustomer({ ...(customer.value || {}), avatar_url: url })
  return { url }
}

// ── Loyalty (Laravel-backed; tier ladder uses lifetime earned) ─────────────────
const TIER_STYLE = {
  bronze:   { color: '#64748b', bg: '#f1f5f9' },
  silver:   { color: '#2563eb', bg: '#dbeafe' },
  gold:     { color: '#b45309', bg: '#fef3c7' },
  platinum: { color: '#059669', bg: '#d1fae5' },
  rookie:   { color: '#64748b', bg: '#f1f5f9' },
  star:     { color: '#2563eb', bg: '#dbeafe' },
  legend:   { color: '#b45309', bg: '#fef3c7' },
  goat:     { color: '#059669', bg: '#d1fae5' },
}

const DEFAULT_TIERS = [
  { name: 'Bronze', slug: 'bronze', min_points: 0, threshold: 0, color: '#64748b', bg: '#f1f5f9' },
  { name: 'Silver', slug: 'silver', min_points: 1000, threshold: 1000, color: '#2563eb', bg: '#dbeafe' },
  { name: 'Gold', slug: 'gold', min_points: 2500, threshold: 2500, color: '#b45309', bg: '#fef3c7' },
  { name: 'Platinum', slug: 'platinum', min_points: 5000, threshold: 5000, color: '#059669', bg: '#d1fae5' },
]

/** @deprecated use loyaltyTiers from loadCustomerRewards */
export const TIERS = DEFAULT_TIERS

export function mapLaravelTiers(raw) {
  const list = Array.isArray(raw) && raw.length ? raw : DEFAULT_TIERS
  return list.map(t => {
    const slug = String(t.slug || t.name || '').toLowerCase()
    const style = TIER_STYLE[slug] || TIER_STYLE.bronze
    const threshold = Number(t.min_points ?? t.threshold ?? 0)
    return {
      ...t,
      slug,
      name: t.name || slug,
      threshold,
      min_points: threshold,
      color: style.color,
      bg: style.bg,
    }
  })
}

function tierForLifetime(lifetime, tiers) {
  const pts = Number(lifetime || 0)
  return [...tiers].reverse().find(t => pts >= t.threshold) || tiers[0]
}

function nextTierForLifetime(lifetime, tiers) {
  const pts = Number(lifetime || 0)
  return tiers.find(t => t.threshold > pts) || null
}

function loyaltyFromPayload(data) {
  const tiers = mapLaravelTiers(data?.tiers)
  const balance = Number(data?.balance) || 0
  const lifetime = Number(data?.lifetime_earned) || 0
  const tier = tierForLifetime(lifetime, tiers)
  const next = nextTierForLifetime(lifetime, tiers)
  const progress = next
    ? Math.min(100, Math.round(((lifetime - tier.threshold) / (next.threshold - tier.threshold)) * 100))
    : 100
  const ledger = Array.isArray(data?.ledger) ? data.ledger : []
  return { points: balance, lifetime, tier, next, progress, tiers, ledger }
}

let _loyaltyCache = null

export async function loadCustomerRewards() {
  if (!getCustomerToken()) return { error: 'Please sign in to see your rewards.' }
  const res = await apiFetch('/customer/loyalty')
  if (!res.success) return { error: formatApiError(res, 'Could not load your rewards.') }
  const payload = loyaltyFromPayload(res.data)
  _loyaltyCache = payload
  return { ok: true, ...payload }
}

export async function loadCustomerLoyalty() {
  if (!customer.value?.id || !getCustomerToken()) return null
  if (_loyaltyCache) return _loyaltyCache
  const res = await loadCustomerRewards()
  if (res.error) return null
  return _loyaltyCache
}

export function clearCustomerLoyaltyCache() {
  _loyaltyCache = null
}
