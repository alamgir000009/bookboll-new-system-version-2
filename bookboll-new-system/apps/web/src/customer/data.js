/**
 * data.js — read helpers for the customer site. Everything is scoped to the
 * customer's selected city and only shows live/approved content.
 */
import { ref } from 'vue'
import { supabase } from '../lib/supabase.js'
import { apiFetch } from '../lib/api.js'
import { selectedCity, selectedCountry } from '../lib/customerSession.js'
import { fmt12 } from '../lib/slots.js'
import { roundsFor, nextMatchFor, totalGames } from './bracket.js'
import { notifyHostNewJoiner, notifyJoinerDecision, notifyMatchResult, notifyEventCancelled } from './eventNotify.js'
import { meetupState } from './meetup.js'
import { awardLoyalty, JOIN_BONUS, HOST_BONUS } from '../lib/loyalty.js'
import { checkCommunityMembership, loadCommunity } from './community.js'
import {
  apiLoadPrograms,
  apiLoadProgram,
  apiEnrollInProgram,
  apiLoadMySubscriptions,
  apiCancelMySubscription,
} from './apiPrograms.js'

// ── White-label scoping ─────────────────────────────────────────────────────────
// When the storefront is served for a provider's brand (see lib/brand.js), every
// listing/program/event read is restricted to that owner. null = the normal
// platform site (no scoping). Set once at app start by resolveBrand().
let brandOwnerId = null
export function setBrandScope(ownerUserId) { brandOwnerId = ownerUserId || null; _brandLocIds = null }
export function getBrandScope() { return brandOwnerId }

// Cached list of the branded owner's location ids (one round-trip, reused).
let _brandLocIds = null
async function brandLocationIds() {
  if (!brandOwnerId) return null
  if (_brandLocIds) return _brandLocIds
  const { data } = await supabase.from('locations').select('id').eq('owner_user_id', brandOwnerId)
  _brandLocIds = (data || []).map(l => l.id)
  return _brandLocIds
}
// On a branded site, an entity from a DIFFERENT owner must not be reachable even via a
// direct link. Returns true when we should hide it. (No brand → never hides.)
function ownerMismatch(ownerId) { return !!brandOwnerId && ownerId !== brandOwnerId }

// Bookboll's cut when hosting an event, split into its two distinct parts:
//
// 1) SEAT service fee — the non-refundable fee on the host's per-person / per-team
//    price, capped at 10% (deliberately LOWER than venue commission so hosting
//    stays attractive).
export const SEAT_FEE_PCT = 10   // final fallback when no country/global rate is set
export const seatFee = (amount) => Math.round((Number(amount) || 0) * SEAT_FEE_PCT / 100)
export const seatFeeAt = (amount, pct) =>
  Math.round((Number(amount) || 0) * (Number.isFinite(Number(pct)) ? Number(pct) : SEAT_FEE_PCT) / 100)

// The country a listing's venue sits in (location.country_id, else via its city).
async function listingCountryId(listingId) {
  if (!listingId) return null
  const { data: l } = await supabase.from('listings').select('id, location_id').eq('id', listingId).maybeSingle()
  if (!l?.location_id) return null
  const { data: loc } = await supabase.from('locations').select('country_id, city_id').eq('id', l.location_id).maybeSingle()
  if (loc?.country_id) return loc.country_id
  if (!loc?.city_id) return null
  const { data: city } = await supabase.from('cities').select('country_id').eq('id', loc.city_id).maybeSingle()
  return city?.country_id || null
}

/**
 * Events per-seat commission %: (community events: platform community rate first) →
 * country 'event' rate → platform global → 10.
 */
export async function eventSeatFeePct(listingId, { community = false } = {}) {
  try {
    if (community) {
      const { data: cc } = await supabase.from('platform_settings')
        .select('value').eq('key', 'community_commission').maybeSingle()
      if (cc?.value != null && cc.value !== '' && Number.isFinite(Number(cc.value))) return Number(cc.value)
    }
    const countryId = await listingCountryId(listingId)
    if (countryId) {
      const { data: cr } = await supabase.from('commission_rates')
        .select('rate_percent').eq('country_id', countryId).eq('listing_type', 'event').maybeSingle()
      if (cr?.rate_percent != null) return Number(cr.rate_percent)
    }
    const { data: gs } = await supabase.from('platform_settings')
      .select('value').eq('key', 'global_commission_event').maybeSingle()
    if (gs?.value != null && gs.value !== '' && Number.isFinite(Number(gs.value))) return Number(gs.value)
  } catch { /* fall through */ }
  return SEAT_FEE_PCT
}
//
// 2) COURT deposit — the commission the host pays online to reserve the court.
//    This is the SAME rate the venue's normal bookings use (listing.commission_rate
//    → country facility rate → 15% default), NOT a special event rate.
export async function courtCommissionPct(listingId) {
  try {
    const { data: l } = await supabase.from('listings').select('id, commission_rate, location_id').eq('id', listingId).maybeSingle()
    if (l?.commission_rate != null && l.commission_rate !== '') return Number(l.commission_rate)
    let countryId = null
    if (l?.location_id) {
      const { data: loc } = await supabase.from('locations').select('country_id, city_id').eq('id', l.location_id).maybeSingle()
      countryId = loc?.country_id || null
      if (!countryId && loc?.city_id) {
        const { data: city } = await supabase.from('cities').select('country_id').eq('id', loc.city_id).maybeSingle()
        countryId = city?.country_id || null
      }
    }
    if (countryId) {
      const { data: cr } = await supabase.from('commission_rates')
        .select('rate_percent').eq('country_id', countryId).eq('listing_type', 'facility').maybeSingle()
      if (cr?.rate_percent != null) return Number(cr.rate_percent)
    }
  } catch { /* fall through to the default */ }
  return 15
}
export const courtDeposit = (amount, pct) => Math.round((Number(amount) || 0) * (Number.isFinite(Number(pct)) ? Number(pct) : 15) / 100)

// Simulated payment rows for a host's court reservation (+ optional non-refundable
// seats service fee). Mirrors booking.js paymentRows: online/deposit + service fee →
// Bookboll (paid now, simulated); the on-arrival balance → provider (pending).
function hostPaymentRows(bookingId, charge, curr, includeServiceFee, loyaltyRedeem = 0) {
  const now = new Date().toISOString()
  const rows = []
  // Loyalty (Bookboll marketplace, host-side): funded by Bookboll — it reduces only the
  // amounts paid TO BOOKBOLL now (service fee first, then the court deposit / full-online).
  // The on-arrival venue balance is the provider's money and is NEVER touched.
  let L = Math.max(0, Number(loyaltyRedeem) || 0)
  const svc = includeServiceFee ? Math.max(0, Number(charge.serviceFee) || 0) : 0
  const svcNet = +Math.max(0, svc - L).toFixed(2); L = +Math.max(0, L - svc).toFixed(2)
  if (charge.bookbollOwned) {
    const courtNet = +Math.max(0, (Number(charge.courtTotal) || 0) - L).toFixed(2); L = +Math.max(0, L - (Number(charge.courtTotal) || 0)).toFixed(2)
    if (courtNet > 0) rows.push({ booking_id: bookingId, payment_type: 'full', method: 'online', amount: courtNet, currency: curr, recipient: 'bookboll', status: 'paid', paid_at: now, notes: 'Court — full online (Bookboll venue)' })
  } else {
    const depNet = +Math.max(0, (Number(charge.courtNow) || 0) - L).toFixed(2); L = +Math.max(0, L - (Number(charge.courtNow) || 0)).toFixed(2)
    if (depNet > 0) rows.push({ booking_id: bookingId, payment_type: 'deposit', method: 'online', amount: depNet, currency: curr, recipient: 'bookboll', status: 'paid', paid_at: now, notes: 'Court deposit (Bookboll commission)' })
    if (charge.courtArrival > 0) rows.push({ booking_id: bookingId, payment_type: 'balance', method: 'cash', amount: charge.courtArrival, currency: curr, recipient: 'provider', status: 'pending', paid_at: null, notes: 'Court balance — paid to venue on arrival' })
  }
  if (svcNet > 0) rows.push({ booking_id: bookingId, payment_type: 'service_fee', method: 'online', amount: svcNet, currency: curr, recipient: 'bookboll', status: 'paid', paid_at: now, notes: 'Seats service fee (non-refundable)' })
  return rows
}

// Whether the hosted-games tables exist yet (migration applied). null until probed.
// Lets the UI show a polished "coming soon" instead of erroring when they're absent.
export const gamesEnabled = ref(null)
export async function probeGames() {
  if (gamesEnabled.value !== null) return gamesEnabled.value
  const r = await supabase.from('hosted_games').select('id', { head: true, count: 'exact' })
  gamesEnabled.value = !r.error
  return gamesEnabled.value
}

const INTERVAL_LABEL = { weekly: 'week', biweekly: '2 wks', monthly: 'month', quarterly: 'quarter', yearly: 'year' }
export function intervalLabel(v) { return INTERVAL_LABEL[v] || 'month' }

// Below this, we hide the "N members" social-proof pill — a tiny/new program showing
// "1 member" reads worse than showing nothing. Threshold shared by cards + detail page.
export const MEMBER_COUNT_MIN = 5

function firstPhoto(row) {
  if (Array.isArray(row?.photos) && row.photos[0]) return row.photos[0]
  if (Array.isArray(row?.images) && row.images[0]) return row.images[0]
  return row?.photo_url || ''
}

// All gallery photos for a row (photos[] → images[] → single photo_url).
function allPhotos(row) {
  if (Array.isArray(row?.photos) && row.photos.length) return row.photos.filter(Boolean)
  if (Array.isArray(row?.images) && row.images.length) return row.images.filter(Boolean)
  return row?.photo_url ? [row.photo_url] : []
}

// Build a Google Maps link for a location: prefer the admin's share link, then exact
// coordinates, then a name+address text search.
function mapsUrl(loc) {
  if (!loc) return ''
  if (loc.google_maps_url) return loc.google_maps_url
  if (loc.latitude != null && loc.longitude != null)
    return `https://www.google.com/maps/search/?api=1&query=${loc.latitude},${loc.longitude}`
  const q = [loc.name, loc.address].filter(Boolean).join(', ')
  return q ? `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(q)}` : ''
}

// Lowest bookable price per listing: the min of its base_price and any pricing-group
// prices (ignoring zeros). Returns a map { [listingId]: number|null }.
async function startingPriceMap(listings) {
  const lids = (listings || []).map(l => l.id)
  let groups = []
  if (lids.length) {
    const r = await supabase.from('pricing_groups').select('listing_id, price').in('listing_id', lids)
    groups = r.error ? [] : (r.data || [])
  }
  const map = {}
  for (const l of (listings || [])) {
    const cands = [Number(l.base_price) || 0, ...groups.filter(g => g.listing_id === l.id).map(g => Number(g.price) || 0)].filter(p => p > 0)
    map[l.id] = cands.length ? Math.min(...cands) : null
  }
  return map
}

// A listing/program is shown publicly only once it's approved and not paused.
// (Missing values count as approved so pre-approval-era rows stay visible.)
function isPubliclyVisible(row) {
  return (row.approval_status || 'approved') === 'approved'
      && (row.eligibility_status || 'eligible') !== 'paused'
}

// Per-context listing visibility (placement). The Bookboll marketplace only shows
// listings flagged for it AND past approval/eligibility review; the provider's own
// white-label site shows their listings immediately (no Bookboll approval needed).
// Missing columns count as visible so the storefront works pre-migration.
function listingVisible(l) {
  if (brandOwnerId) return l.show_on_provider_site !== false
  return l.show_on_marketplace !== false && isPubliclyVisible(l)
}

// Programs use the same placement model as listings (see migration
// 20260619100000_program_placement.sql). Same rule as listingVisible.
function programVisible(p) {
  if (brandOwnerId) return p.show_on_provider_site !== false
  return p.show_on_marketplace !== false && isPubliclyVisible(p)
}

// A hosted event disappears from the storefront once a Bookboll admin removes it
// in moderation. (Missing column counts as 'active' so the site works pre-migration.)
function isEventVisible(g) {
  return (g.moderation_status || 'active') !== 'removed'
}

// ── Suspended businesses (admin "suspend business" in Approvals) ──────────────
// A suspended business disappears from the Bookboll MARKETPLACE entirely — every
// venue, listing and program. Their own white-label site keeps working, so the
// provider can still see their content while suspended. One cached fetch per
// session; errors fail open (nothing hidden) so a pre-migration DB is unaffected.
let _suspendedOwnersPromise = null
function suspendedOwners() {
  if (!_suspendedOwnersPromise) {
    _suspendedOwnersPromise = (async () => {
      try {
        const { data, error } = await supabase.from('admin_users').select('id').eq('business_status', 'suspended')
        return error ? new Set() : new Set((data || []).map(r => r.id))
      } catch { return new Set() }
    })()
  }
  return _suspendedOwnersPromise
}
async function ownerSuspended(ownerId) {
  if (!ownerId || brandOwnerId) return false   // own-site views are not blocked
  return (await suspendedOwners()).has(ownerId)
}

// Catalog query for Laravel marketplace endpoints. City UUIDs from the Supabase
// location picker cannot be sent as city_id — we match Laravel world cities by name.
async function marketplaceQuery(cityId, extra = {}) {
  const q = {}
  if (cityId != null && cityId !== '' && /^\d+$/.test(String(cityId))) q.city_id = Number(cityId)
  const name = extra.name || extra.city || selectedCity.value?.name || ''
  if (name) q.city = name
  const iso = extra.iso || extra.country_iso || selectedCountry.value?.iso_code || ''
  if (iso) q.country_iso = iso
  let slug = extra.brand_slug || ''
  if (!slug) {
    try {
      const { activeBrand } = await import('../lib/brand.js')
      slug = activeBrand.value?.slug || ''
    } catch { /* ignore circular-init */ }
  }
  if (slug) q.brand_slug = slug
  return q
}

// ── Facilities (bookable listings) in a city ───────────────────────────────────
// One card per LISTING: the listing name is the headline, its venue (location) name
// is shown by the pin. Cards link to the venue detail page (keyed by location id).
export async function loadFacilities(cityId, extra = {}) {
  if (!cityId && !extra.name && !selectedCity.value?.name) return []
  const res = await apiFetch('/marketplace/listings', { query: await marketplaceQuery(cityId, extra) })
  if (!res.success) return []
  return Array.isArray(res.data?.listings) ? res.data.listings : []
}

// One card per LOCATION (venue): the venue name is the headline, with its court
// count + sports. Used by the Facilities page "by location" toggle.
export async function loadLocations(cityId, extra = {}) {
  if (!cityId && !extra.name && !selectedCity.value?.name) return []
  const res = await apiFetch('/marketplace/venues', { query: await marketplaceQuery(cityId, extra) })
  if (!res.success) return []
  return Array.isArray(res.data?.venues) ? res.data.venues : []
}

// All of the ACTIVE brand's locations (the white-label "Our locations" page) — across every
// city, each with its visible listings + weekly hours. Brand-scoped; returns [] on the apex
// (where "locations" would span every provider — the marketplace uses /venues instead).
export async function loadBrandLocations() {
  if (!brandOwnerId) return []
  const { data: locs } = await supabase.from('locations').select('*')
    .eq('owner_user_id', brandOwnerId).eq('status', 'active').order('sort_order').order('name')
  const list = locs || []
  const ids = list.map(l => l.id)
  if (!ids.length) return []
  const { data: liData } = await supabase.from('listings').select('*').in('location_id', ids)
  const listings = (liData || []).filter(listingVisible)
  const priceMap = await startingPriceMap(listings)
  const hours = await Promise.all(list.map(l => loadLocationHours(l.id)))
  const cityIds = [...new Set(list.map(l => l.city_id).filter(Boolean))]
  const cityName = {}
  if (cityIds.length) {
    const { data: cs } = await supabase.from('cities').select('id, name').in('id', cityIds)
    ;(cs || []).forEach(c => { cityName[c.id] = c.name })
  }
  return list.map((l, i) => {
    const courts = listings.filter(x => x.location_id === l.id)
    const prices = courts.map(c => priceMap[c.id]).filter(p => p != null && p > 0)
    const sports = [...new Set(courts.map(c => c.sport_category).filter(Boolean))]
    return {
      id: l.id, name: l.name, photo: firstPhoto(l), address: l.address || '',
      cityName: cityName[l.city_id] || '', mapsUrl: mapsUrl(l),
      price: prices.length ? Math.min(...prices) : null,
      sportsText: sports.join(' · '),
      listings: courts.map(c => ({ id: c.id, name: c.name, sport: c.sport_category || '', photo: firstPhoto(c) || firstPhoto(l), price: priceMap[c.id] ?? null })),
      hours: hours[i] || [],
    }
  })
}

export async function loadFacility(id) {
  if (!id) return null
  const res = await apiFetch(`/marketplace/venues/${id}`, { query: await marketplaceQuery(null) })
  if (!res.success || !res.data?.facility) return null
  return res.data.facility
}

// Full detail for a single listing (court/pitch): the listing, its venue, pricing
// tiers and any add-ons offered.
export async function loadListing(id) {
  if (!id) return null
  const res = await apiFetch(`/marketplace/listings/${id}`, { query: await marketplaceQuery(null) })
  if (!res.success || !res.data?.detail) return null
  const d = res.data.detail
  if (d.listing && d.holdMinutes) d.listing.holdMinutes = d.holdMinutes
  return d
}

// Countries + cities where a PROVIDER actually has visible content on their white-label
// site — used to scope the location picker and to detect "we don't serve here". Cities are
// busiest-first. `show_on_provider_site !== false` mirrors the storefront's visibility rule.
export async function loadServedRegions(brandSlug) {
  if (!brandSlug) return { countries: [], cities: [] }
  const res = await apiFetch('/marketplace/served-regions', { query: { brand_slug: String(brandSlug).toLowerCase() } })
  if (!res.success) return { countries: [], cities: [] }
  return {
    countries: res.data?.countries || [],
    cities: res.data?.cities || [],
  }
}

// ── Programs / classes in a city ───────────────────────────────────────────────
export async function loadPrograms(cityId) {
  return apiLoadPrograms(cityId, brandOwnerId)
}

// Weekly opening hours — served with program detail from Laravel venues.
export async function loadLocationHours(locationId) {
  if (!locationId) return null
  return null
}

export async function loadProgram(id) {
  return apiLoadProgram(id, brandOwnerId)
}

// Customer self-enrolment (marketplace membership checkout) — Laravel authoritative.
export async function enrollInProgram(program, { customer, answers = {}, discount = 0, cardFp = '', loyaltyRedeem = 0 } = {}) {
  if (!program?.id) return { error: 'Program not found.' }
  if (!customer?.id) return { error: 'Please sign in to continue.' }
  return apiEnrollInProgram(program, { answers, discount, cardFp, loyaltyRedeem })
}

// A customer's membership subscriptions (for the "My memberships" page / account widget).
export async function loadMySubscriptions(customerId, email = '') {
  if (!customerId && !email) return []
  return apiLoadMySubscriptions(brandOwnerId)
}

/** Customer cancels their own membership: no further renewals are collected. */
export async function cancelMySubscription(subId) {
  return apiCancelMySubscription(subId)
}

// ── Hosted games in a city (resilient if the table isn't migrated yet) ─────────
export async function loadGames(cityId) {
  if (!cityId) return []
  const now = new Date()
  const today = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
  const nowHM = `${String(now.getHours()).padStart(2, '0')}:${String(now.getMinutes()).padStart(2, '0')}`
  // On a white-label site, only show events held at THIS provider's venues
  // (own-spot events have no location and correctly drop out).
  let brandLocs = null
  if (brandOwnerId) {
    brandLocs = await brandLocationIds()
    if (!brandLocs.length) { gamesEnabled.value = true; return [] }
  }
  // Only OPEN events (excludes cancelled/full/completed) whose date hasn't passed.
  // Flexible (no date) events are always kept.
  let gameQ = supabase.from('hosted_games')
    .select('*').eq('city_id', cityId).eq('status', 'open')
    .or(`game_date.gte.${today},game_date.is.null`)
    .order('game_date')
  if (brandLocs) gameQ = gameQ.in('location_id', brandLocs)
  const res = await gameQ
  gamesEnabled.value = !res.error
  if (res.error) return []
  // Also drop today's events whose end time has already passed, and any an admin removed.
  // Community-hosted events ARE listed publicly (discovery helps communities grow);
  // membership is enforced at JOIN time instead — non-members are asked to join the
  // community first (see requestToJoin's community gate).
  const live = (res.data || []).filter(isEventVisible)
    .filter(g => !g.game_date || g.game_date > today || !g.end_time || g.end_time.slice(0, 5) >= nowHM)
  return await _decorateGames(live)
}

// A community's meetups: every hosted_games row tagged with this community_id —
// upcoming first, past events kept (history matters in a club), decorated like loadGames.
export async function loadCommunityEvents(communityId) {
  if (!communityId) return []
  try {
    const res = await supabase.from('hosted_games')
      .select('*').eq('community_id', communityId)
      .order('game_date', { ascending: false })
    if (res.error) return []
    const live = (res.data || []).filter(isEventVisible).filter(g => g.status !== 'cancelled')
    const today = ymdOf(new Date())
    // Upcoming (soonest first), then past (most recent first).
    const upcoming = live.filter(g => !g.game_date || g.game_date >= today).sort((a, b) => String(a.game_date || '9999').localeCompare(String(b.game_date || '9999')))
    const past = live.filter(g => g.game_date && g.game_date < today)
    return await _decorateGames([...upcoming, ...past])
  } catch { return [] }
}
function ymdOf(d) { return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}` }

async function _decorateGames(games) {
  const ids = games.map(g => g.id)
  let reqs = []
  if (ids.length) {
    const r = await supabase.from('game_join_requests').select('game_id, status, joiner_customer_id').in('game_id', ids)
    reqs = r.data || []
  }
  const locIds = [...new Set(games.map(g => g.location_id).filter(Boolean))]
  let locs = []
  if (locIds.length) { const r = await supabase.from('locations').select('id, name, address').in('id', locIds); locs = r.data || [] }
  const loc = id => locs.find(l => l.id === id) || null
  // People context (the thing that makes event cards feel alive): host name/avatar
  // + the avatar stack of accepted joiners — fetched in ONE batched customers query.
  const accepted = reqs.filter(r => r.status === 'accepted')
  const custIds = [...new Set([
    ...games.map(g => g.host_customer_id).filter(Boolean),
    ...accepted.map(r => r.joiner_customer_id).filter(Boolean),
  ])]
  let people = []
  if (custIds.length) {
    const r = await supabase.from('customers').select('id, full_name, avatar_url').in('id', custIds)
    people = r.data || []
  }
  const person = id => people.find(p => p.id === id) || null
  // Community-hosted events show the community as the host, not the posting member.
  const commIds = [...new Set(games.map(g => g.community_id).filter(Boolean))]
  let comms = []
  if (commIds.length) {
    const loaded = await Promise.all(commIds.map((id) => loadCommunity(id)))
    comms = loaded.filter(Boolean)
  }
  return games.map(g => {
    const host = person(g.host_customer_id)
    const goingPeople = accepted.filter(r => r.game_id === g.id).map(r => person(r.joiner_customer_id)).filter(Boolean)
    return {
      ...g,
      locationName: loc(g.location_id)?.name || '',
      locationAddress: loc(g.location_id)?.address || '',
      joinedCount: accepted.filter(r => r.game_id === g.id).length,
      hostName: host?.full_name || '',
      hostAvatar: host?.avatar_url || '',
      communityName: g.community_id ? (comms.find(c => c.id === g.community_id)?.name || '') : '',
      communityAvatar: g.community_id ? (comms.find(c => c.id === g.community_id)?.avatar_image || '') : '',
      goingAvatars: goingPeople.slice(0, 5).map(p => ({ name: p.full_name, avatar: p.avatar_url || '' })),
      goingNames: goingPeople.slice(0, 2).map(p => (p.full_name || '').split(' ')[0]).filter(Boolean),
    }
  })
}

// Resolve a country's ISO code (for phone dial-code defaults). Cached per id.
const _isoCache = {}
export async function loadCountryIso(id) {
  if (!id) return ''
  if (_isoCache[id] !== undefined) return _isoCache[id]
  const { data } = await supabase.from('countries').select('iso_code').eq('id', id).maybeSingle()
  _isoCache[id] = data?.iso_code || ''
  return _isoCache[id]
}

export async function loadGame(id) {
  const res = await supabase.from('hosted_games').select('*').eq('id', id).maybeSingle()
  if (res.error || !res.data) return null
  if (!isEventVisible(res.data)) return null   // removed by a Bookboll admin — hide even via direct link
  if (brandOwnerId) {   // on a branded site, only events at this provider's venues
    const ids = await brandLocationIds()
    if (!res.data.location_id || !ids.includes(res.data.location_id)) return null
  }
  const [decorated] = await _decorateGames([res.data])
  return decorated
}

// Public profile: only NON-sensitive info (name, join date, public activity). Never
// exposes email/phone/loyalty. Lets people see who's hosting and hosts vet requesters.
export async function loadPublicProfile(customerId) {
  if (!customerId) return null
  let c = await supabase.from('customers').select('id, full_name, created_at, avatar_url, photos, bio, is_private').eq('id', customerId).maybeSingle()
  if (c.error) c = await supabase.from('customers').select('id, full_name, created_at, avatar_url, photos').eq('id', customerId).maybeSingle()   // pre-social-migration
  if (c.error) c = await supabase.from('customers').select('id, full_name, created_at').eq('id', customerId).maybeSingle()   // pre-migration fallback
  if (c.error || !c.data) return null
  const now = new Date()
  const today = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
  const [hosted, joined, openRes] = await Promise.all([
    supabase.from('hosted_games').select('id', { count: 'exact', head: true }).eq('host_customer_id', customerId),
    supabase.from('game_join_requests').select('id', { count: 'exact', head: true }).eq('joiner_customer_id', customerId).eq('status', 'accepted'),
    supabase.from('hosted_games').select('*').eq('host_customer_id', customerId).eq('status', 'open').or(`game_date.gte.${today},game_date.is.null`).order('game_date'),
  ])
  // Community-only events stay private — don't surface them on the public profile.
  const hostedOpen = openRes.error ? [] : await _decorateGames((openRes.data || []).filter(isEventVisible))
  return {
    id: c.data.id,
    name: c.data.full_name || 'Player',
    joinedAt: c.data.created_at,
    avatar: c.data.avatar_url || '',
    photos: Array.isArray(c.data.photos) ? c.data.photos.filter(Boolean) : [],
    bio: c.data.bio || '',
    isPrivate: c.data.is_private === true,
    hostedCount: hosted.count || 0,
    joinedCount: joined.count || 0,
    hostedOpen,
  }
}

export function gamesAvailable() {
  // quick capability probe used to show "coming soon" vs the real section
  return supabase.from('hosted_games').select('id', { head: true, count: 'exact' }).then(r => !r.error)
}

// ── Game writes (host / join / respond) ────────────────────────────────────────

// Minutes-since-midnight for a booking time. A '00:00' END means end-of-day (1440),
// so a court booked until midnight is correctly seen as busy all evening.
function bkStart(t) { const p = String(t).slice(0, 5).split(':'); return (+p[0]) * 60 + (+p[1]) }
function bkEnd(t) { const m = bkStart(t); return m === 0 ? 1440 : m }

// Is the listing already reserved (by a normal booking OR another game) at this slot?
// Numeric + midnight-aware: raw string compare used to mis-handle 'HH:MM' vs 'HH:MM:SS'
// and never flagged a booking ending at 00:00 as a conflict.
async function slotTaken(listingId, date, start, end) {
  const r = await supabase.from('bookings')
    .select('start_time, end_time, booking_status')
    .eq('listing_id', listingId).eq('booking_date', date).neq('booking_status', 'cancelled')
  if (r.error) return false
  const rows = r.data || []
  if (!start || !end) return rows.length > 0      // no times given → any existing booking blocks
  const s = bkStart(start), e = bkEnd(end)
  return rows.some(b => b.start_time && b.end_time && s < bkEnd(b.end_time) && bkStart(b.start_time) < e)
}

/**
 * Host a game. Wired into the normal booking system: creates a real `bookings` row
 * that RESERVES the court (priced like any booking), then the hosted_game linked to
 * it. The host pays the court the usual way — deposit (Bookboll commission) online +
 * balance to the venue on arrival, or full online for Bookboll venues — PLUS a
 * non-refundable seats service fee (Bookboll's cut of the per-person price). Players
 * still pay the host their seat price offline on arrival. `charge` carries the money
 * breakdown computed by the host wizard; payments are SIMULATED (no gateway yet).
 */
// Best-effort: store the host's advertised prize pot. The columns may not exist until
// the 20260606010000 migration is applied, so a failure here is ignored — creation still
// succeeds; the pot just won't persist/show until then. `into` (the returned row) is
// stamped locally so it can display immediately after creation.
async function applyPrizePot(id, prizePot, prizeNote, into) {
  const pot = Number(prizePot) || 0
  const note = (prizeNote || '').trim()
  if (into) { into.prize_pot = pot; into.prize_note = note }
  if (!id || (pot <= 0 && !note)) return
  await supabase.from('hosted_games').update({ prize_pot: pot, prize_note: note }).eq('id', id)
}

// Best-effort: store a HANGOUT's own-spot location (public name + private exact address +
// hide flag). Columns added by 20260606020000_add_hangout_location.sql; missing → ignored.
async function applyHangoutLocation(id, name, address, hide, into) {
  const n = (name || '').trim(), a = (address || '').trim(), h = !!hide
  if (into) { into.custom_location_name = n; into.custom_location_address = a; into.hide_location = h }
  if (!id || (!n && !a && !h)) return
  await supabase.from('hosted_games').update({ custom_location_name: n, custom_location_address: a, hide_location: h }).eq('id', id)
}

// Best-effort: store a host's event photos (jsonb array, first = cover). Column added by
// 20260607010000_add_game_images.sql; missing → ignored so hosting still works pre-migration.
async function applyGameImages(id, images, into) {
  const arr = Array.isArray(images) ? images.filter(Boolean) : []
  if (into) into.images = arr
  if (!id || !arr.length) return
  await supabase.from('hosted_games').update({ images: arr }).eq('id', id)
}

// Whether the hangout location columns exist yet (migration applied). Only caches a TRUE
// result, so it self-heals once the migration is run (same pattern as tournaments).
export const hangoutLocationEnabled = ref(null)
export async function probeHangoutLocation() {
  if (hangoutLocationEnabled.value === true) return true
  const r = await supabase.from('hosted_games').select('hide_location').limit(1)
  hangoutLocationEnabled.value = !r.error
  return hangoutLocationEnabled.value
}

// Best-effort: write a custom sign-up form onto an event row only if the column exists
// (so creating events still works before the migration is applied).
async function applySignupForm(table, id, form, row) {
  if (!row || !('signup_form' in row)) return
  if (!Array.isArray(form) || !form.length) return
  await supabase.from(table).update({ signup_form: form }).eq('id', id)
}

// Best-effort: stamp the (optional) join-request deadline. Kept out of the main insert so
// events still create before the request_deadline column is migrated.
async function applyRequestDeadline(table, id, deadline) {
  if (!deadline) return
  try { await supabase.from(table).update({ request_deadline: deadline }).eq('id', id) } catch { /* pre-migration */ }
}

// Best-effort: stamp how joiners get in ('review' default / 'instant'). Kept out of the
// main insert so events still create before the approval_mode column is migrated.
async function applyApprovalMode(table, id, mode) {
  if (!mode || mode === 'review') return
  try { await supabase.from(table).update({ approval_mode: mode }).eq('id', id) } catch { /* pre-migration */ }
}

// Best-effort: how the host collects seat money (arrival cash vs their Stripe).
// Kept out of the main insert so events still create before the charge column
// is migrated (20260743) — GameDetail falls back to "pay on arrival" without it.
async function applyCharge(id, charge) {
  if (!charge) return
  try { await supabase.from('hosted_games').update({ charge }).eq('id', id) } catch { /* pre-migration */ }
}

export async function createGame(game) {
  const { charge, prize_pot, prize_note, custom_location_name, custom_location_address, hide_location, images, signup_form, request_deadline, approval_mode, allow_waitlist, ...row } = game
  // A HANGOUT can use the host's OWN spot (no court) → no listing_id, no booking.
  const ownSpot = !row.listing_id
  if (!row.game_date)  return { error: 'Please pick a date.' }
  if (!row.start_time || !row.end_time) return { error: 'Please set a start and end time.' }

  const curr = row.currency || 'AED'
  const c = {
    courtTotal: Number(charge?.courtTotal) || 0, courtNow: Number(charge?.courtNow) || 0,
    courtArrival: Number(charge?.courtArrival) || 0, serviceFee: Number(charge?.serviceFee) || 0,
    bookbollOwned: !!charge?.bookbollOwned,
  }
  // Host loyalty redemption — the host is paying BOOKBOLL now (court deposit/full + service
  // fee, all Bookboll's money). Points cap at that Bookboll-now total, funded by Bookboll,
  // and NEVER reduce the venue's on-arrival balance (courtArrival → provider). Absorbed
  // service-fee-first, then court, mirroring hostPaymentRows.
  const bookbollNow = +((c.bookbollOwned ? c.courtTotal : c.courtNow) + c.serviceFee).toFixed(2)
  const loyaltyApplied = +Math.min(Math.max(0, Number(charge?.loyaltyRedeem) || 0), bookbollNow).toFixed(2)
  const courtLoyalty = +Math.max(0, loyaltyApplied - c.serviceFee).toFixed(2)
  const courtPaidNow = +Math.max(0, (c.bookbollOwned ? c.courtTotal : c.courtNow) - courtLoyalty).toFixed(2)
  let bookingId = null

  if (!ownSpot) {
    // 1) Don't allow double-booking the court.
    if (await slotTaken(row.listing_id, row.game_date, row.start_time, row.end_time))
      return { error: 'That court is already booked for this time. Pick another time or court.' }
    const status = c.courtTotal <= 0 ? 'pending' : (c.courtArrival <= 0 ? 'paid' : (c.courtNow > 0 ? 'partial' : 'pending'))
    // 2) Reserve the court with a priced booking.
    const bk = await supabase.from('bookings').insert({
      listing_id: row.listing_id, location_id: row.location_id || null, customer_id: row.host_customer_id || null,
      booking_date: row.game_date, start_time: row.start_time, end_time: row.end_time,
      players: row.max_players || 1,
      base_price: c.courtTotal, total: c.courtTotal, amount_paid: courtPaidNow,
      payment_method: c.bookbollOwned ? 'online' : (c.courtNow > 0 ? 'deposit' : 'cash'), payment_status: status,
      booking_status: 'confirmed', origin: 'customer_game',
      notes: `Hosted ${row.game_type || 'game'}: ${row.title || row.sport}. Player seat payments handled offline; Bookboll service fee pre-paid by host.`,
    }).select('id').maybeSingle()
    if (bk.error || !bk.data) return { error: bk.error?.message || 'Could not reserve the court.' }
    bookingId = bk.data.id
  }

  // 3) Create the hosted game (linked to the booking, or stand-alone for an own-spot hangout).
  const res = await supabase.from('hosted_games').insert({ ...row, booking_id: bookingId }).select().maybeSingle()
  if (res.error) {
    if (bookingId) await supabase.from('bookings').delete().eq('id', bookingId)   // roll back the reservation
    return { error: res.error.message }
  }
  await applyPrizePot(res.data.id, prize_pot, prize_note, res.data)               // best-effort
  await applyHangoutLocation(res.data.id, custom_location_name, custom_location_address, hide_location, res.data)
  await applyGameImages(res.data.id, images, res.data)                            // best-effort
  await applySignupForm('hosted_games', res.data.id, signup_form, res.data)       // best-effort
  await applyRequestDeadline('hosted_games', res.data.id, request_deadline)       // best-effort
  await applyApprovalMode('hosted_games', res.data.id, approval_mode)             // best-effort
  // Waitlist defaults ON — only an explicit opt-out is written (column optional).
  if (allow_waitlist === false) {
    try { await supabase.from('hosted_games').update({ allow_waitlist: false }).eq('id', res.data.id) } catch { /* pre-migration */ }
  }
  await applyCharge(res.data.id, charge)                                          // best-effort — ticket mode must persist or "online tickets" silently degrades to cash

  // 4) Record the host's simulated payments — only when a court was actually booked.
  if (bookingId) {
    const rows = hostPaymentRows(bookingId, c, curr, true, loyaltyApplied)
    if (rows.length) await supabase.from('booking_payments').insert(rows)
  }
  // Loyalty: hosting a game earns a flat bonus (idempotent per game). Best-effort.
  if (res.data?.host_customer_id && res.data?.country_id) {
    awardLoyalty({ customerId: res.data.host_customer_id, countryId: res.data.country_id, flat: HOST_BONUS, sourceKey: `host:${res.data.id}`, reason: 'Hosted a game' })
  }
  return { game: res.data, loyaltyApplied }
}

// A joiner's existing request for a game (so we don't double-request).
export async function myRequestForGame(gameId, customerId) {
  if (!gameId || !customerId) return null
  const r = await supabase.from('game_join_requests').select('*').eq('game_id', gameId).eq('joiner_customer_id', customerId).maybeSingle()
  return r.error ? null : r.data
}

export async function requestToJoin(gameId, joiner) {
  // Load the game once for the deadline + approval-mode checks. select('*') so missing
  // columns pre-migration just yield undefined rather than erroring.
  let g = null
  try {
    const r = await supabase.from('hosted_games').select('*').eq('id', gameId).maybeSingle()
    g = r.data || null
  } catch { /* ignore — fall through and let the insert proceed */ }
  if (g?.request_deadline && new Date(g.request_deadline).getTime() < Date.now()) {
    return { error: 'Requests for this event have closed.' }
  }
  // Lifecycle lock: once the event locks (lock_minutes_before / deadline / start),
  // starts or ends, no new joins — the roster is final for the organizer.
  if (g) {
    const st = meetupState(g)
    if (st === 'locked') return { error: 'This event is locked — the roster is final. Message the host if you still want in.' }
    if (st === 'active') return { error: 'This event has already started.' }
    if (st === 'archived') return { error: 'This event has ended.' }
    if (st === 'cancelled') return { error: 'This event was cancelled.' }
  }
  // Community-only event: only accepted members of that community may join (unless the
  // host opted it into public listing). Non-members are pointed to join the community.
  if (g?.community_id && g.community_public !== true) {
    if (!joiner.customerId) return { error: 'This is a members-only community event — join the community first.', communityOnly: true, communityId: g.community_id }
    const check = await checkCommunityMembership(g.community_id, joiner.customerId)
    if (!check.is_member) {
      return { error: 'This is a members-only community event — join the community first.', communityOnly: true, communityId: g.community_id }
    }
  }
  // Instant-join events skip host approval — but never over-fill a capped event.
  // A FULL event queues the joiner as 'waitlisted' (any approval mode) unless the
  // host turned the waitlist off. Waitlisted joiners are promoted oldest-first
  // when a spot frees: instant events straight to accepted, review events back to
  // pending for the host to approve. Missing column (pre-migration) = waitlist ON.
  const instant = g?.approval_mode === 'instant'
  const waitlistOk = g?.allow_waitlist !== false
  let waitlisted = false
  if (Number(g?.max_players) > 0) {
    const c = await supabase.from('game_join_requests').select('id', { count: 'exact', head: true }).eq('game_id', gameId).eq('status', 'accepted')
    if (!c.error && (c.count || 0) >= Number(g.max_players)) {
      if (!waitlistOk) return { error: 'This event is full.' }
      waitlisted = true
    }
  }
  const base = {
    game_id: gameId,
    joiner_customer_id: joiner.customerId || null,
    joiner_name: joiner.name || '',
    joiner_phone: joiner.phone || '',
    joiner_whatsapp: joiner.whatsapp || joiner.phone || '',
    joiner_message: joiner.message || '',
    status: waitlisted ? 'waitlisted' : (instant ? 'accepted' : 'pending'),
  }
  // form_answers column added by a later migration — include it, fall back without.
  let res = await supabase.from('game_join_requests').insert({ ...base, form_answers: joiner.form_answers || {} }).select().maybeSingle()
  if (res.error) res = await supabase.from('game_join_requests').insert(base).select().maybeSingle()
  if (res.error) return { error: res.error.message }
  // WhatsApp the host (fire-and-forget): review → "wants to join", instant → "just joined".
  // Waitlisted joiners don't ping the host — nothing to act on until a spot frees.
  if (g && !waitlisted) notifyHostNewJoiner(g, base.joiner_name, { instant })
  // Loyalty: a CONFIRMED join (instant events) earns the flat activity bonus + the
  // seat price in points. Host-approved joins earn later, in respondToRequest.
  // Idempotent via source key 'gm:<requestId>'. Best-effort — never blocks joining.
  let earned = 0
  if (res.data?.status === 'accepted' && joiner.customerId && g?.country_id) {
    earned = await awardLoyalty({
      customerId: joiner.customerId, countryId: g.country_id,
      amount: Number(g.price_per_person) || 0, flat: JOIN_BONUS,
      sourceKey: `gm:${res.data.id}`, reason: 'Joined a game',
    })
  }
  return { request: res.data, waitlisted, earned }
}

// All requests for a game (host view — includes joiner contact details).
export async function loadGameRequests(gameId) {
  const r = await supabase.from('game_join_requests').select('*').eq('game_id', gameId).order('created_at')
  return r.error ? [] : (r.data || [])
}

export async function respondToRequest(requestId, status) {
  // Accepting must respect the cap — before this check a host could keep
  // accepting past max_players without any signal.
  const { data: req0 } = await supabase.from('game_join_requests').select('game_id, status').eq('id', requestId).maybeSingle()
  if (status === 'accepted' && req0?.game_id) {
    const { data: g } = await supabase.from('hosted_games').select('id, max_players').eq('id', req0.game_id).maybeSingle()
    const cap = Number(g?.max_players) || 0
    if (cap > 0) {
      const c = await supabase.from('game_join_requests').select('id', { count: 'exact', head: true }).eq('game_id', req0.game_id).eq('status', 'accepted')
      if (!c.error && (c.count || 0) >= cap) {
        return { error: `You've already accepted ${c.count} players — that's the cap. Remove someone or raise "max players" first.` }
      }
    }
  }
  const res = await supabase.from('game_join_requests').update({ status }).eq('id', requestId)
  if (res.error) return { error: res.error.message }
  // Loyalty: host approved the join → award the joiner (flat bonus + seat price),
  // idempotent via the same 'gm:<requestId>' key. Best-effort.
  if (status === 'accepted' && req0?.game_id) {
    try {
      const [{ data: rq }, { data: gL }] = await Promise.all([
        supabase.from('game_join_requests').select('joiner_customer_id').eq('id', requestId).maybeSingle(),
        supabase.from('hosted_games').select('country_id, price_per_person').eq('id', req0.game_id).maybeSingle(),
      ])
      if (rq?.joiner_customer_id && gL?.country_id) {
        await awardLoyalty({ customerId: rq.joiner_customer_id, countryId: gL.country_id, amount: Number(gL.price_per_person) || 0, flat: JOIN_BONUS, sourceKey: `gm:${requestId}`, reason: 'Joined a game' })
      }
    } catch { /* best-effort */ }
  }
  // Removing a previously-accepted player frees a spot → let the waitlist in.
  // Host-initiated removals always refund an online ticket (marker; simulated rail).
  if (status === 'rejected' && req0?.status === 'accepted' && req0?.game_id) {
    try {
      const { data: g0 } = await supabase.from('hosted_games').select('price_per_person, charge').eq('id', req0.game_id).maybeSingle()
      if (g0 && Number(g0.price_per_person) > 0 && g0.charge?.ticketMode === 'stripe') {
        await supabase.from('game_join_requests').update({ refund_state: 'refunded' }).eq('id', requestId)
      }
    } catch { /* pre-migration */ }
    await promoteWaitlist(req0.game_id)
  }
  // WhatsApp the joiner about the decision (fire-and-forget, never blocks the host).
  try {
    const { data: req } = await supabase.from('game_join_requests').select('*').eq('id', requestId).maybeSingle()
    if (req?.game_id && ['accepted', 'rejected'].includes(status)) {
      const { data: g } = await supabase.from('hosted_games').select('*').eq('id', req.game_id).maybeSingle()
      if (g) notifyJoinerDecision(g, req, status)
    }
  } catch { /* notification is best-effort */ }
  return { error: null }
}

// Accept EVERY pending request in one shot (host bulk action), oldest first, up
// to the event's capacity. Returns { accepted, leftover } where leftover is the
// number left pending because the cap filled up. Individual accept notifications
// are skipped for the bulk action — the host can reach anyone via the contact
// buttons — so this stays fast even with a big pile of requests.
export async function acceptAllRequests(gameId) {
  if (!gameId) return { error: 'Missing event.' }
  const { data: g } = await supabase.from('hosted_games').select('id, max_players').eq('id', gameId).maybeSingle()
  const cap = Number(g?.max_players) || 0
  const { data: reqs } = await supabase.from('game_join_requests')
    .select('id, status, created_at').eq('game_id', gameId).order('created_at')
  const rows = reqs || []
  const pending = rows.filter(r => r.status === 'pending')
  const acceptedCount = rows.filter(r => r.status === 'accepted').length
  if (!pending.length) return { accepted: 0, leftover: 0 }
  let take = pending
  if (cap > 0) {
    const remaining = Math.max(0, cap - acceptedCount)
    if (remaining <= 0) return { accepted: 0, leftover: pending.length, full: true }
    take = pending.slice(0, remaining)
  }
  const ids = take.map(r => r.id)
  const res = await supabase.from('game_join_requests').update({ status: 'accepted' }).in('id', ids)
  if (res.error) return { error: res.error.message }
  return { accepted: ids.length, leftover: pending.length - ids.length }
}

// Best-effort: live-rounds format fields (columns from migration 20260757).
export async function applyPlayFormat(gameId, { play_format = '', courts_count = null, teamSize = 2 } = {}) {
  if (!gameId || !play_format) return
  try {
    await supabase.from('hosted_games').update({
      play_format, courts_count: courts_count || 1,
      rounds: { teamSize: Math.max(1, Number(teamSize) || 2), current: 0, rounds: [] },
    }).eq('id', gameId)
  } catch { /* pre-migration — event publishes without live rounds */ }
}

// Persist the organizer's rounds document (atomic jsonb write, like images/signup_form).
export async function saveRounds(gameId, roundsDoc) {
  try {
    const { error } = await supabase.from('hosted_games').update({ rounds: roundsDoc }).eq('id', gameId)
    return { error: error?.message || null }
  } catch (e) { return { error: e.message } }
}

// Best-effort: meetup lifecycle field (column from migration 20260756).
export async function applyLifecycleFields(gameId, { lock_minutes_before = null } = {}) {
  if (!gameId) return
  if (lock_minutes_before == null) return
  try {
    await supabase.from('hosted_games').update({
      lock_minutes_before: lock_minutes_before || null,
    }).eq('id', gameId)
  } catch { /* pre-migration — event publishes without the extras */ }
}

// Online-ticket event? (pay-on-arrival holds no money, so no refund concept there)
function paidTickets(g) {
  return Number(g?.price_per_person) > 0 && g?.charge?.ticketMode === 'stripe'
}

// A joiner withdrawing their own request (pending = cancel, accepted = leave the game).
// Lifecycle rule (Reclub-style): BEFORE the lock deadline → free to drop, any online
// ticket auto-refunds (marker; payments are simulated) and the waitlist fills the seat.
// AT/AFTER the lock → self-serve drops are BLOCKED; only the host can release them.
export async function cancelJoinRequest(requestId) {
  let gameId = null, wasAccepted = false, g = null
  try {
    const { data } = await supabase.from('game_join_requests').select('game_id, status').eq('id', requestId).maybeSingle()
    gameId = data?.game_id || null
    wasAccepted = data?.status === 'accepted'
    if (gameId) {
      const r = await supabase.from('hosted_games').select('*').eq('id', gameId).maybeSingle()
      g = r.data || null
    }
  } catch { /* promote is best-effort */ }
  if (g) {
    const st = meetupState(g)
    if (['locked', 'active'].includes(st) && wasAccepted) {
      return { error: "This event is locked — spots can't be dropped this close to start. Message the host if you really can't make it." }
    }
  }
  // Paid ticket → keep the row as an auditable "refunded" record; free → just delete.
  if (g && wasAccepted && paidTickets(g)) {
    let res = await supabase.from('game_join_requests').update({ status: 'rejected', refund_state: 'refunded' }).eq('id', requestId)
    if (res.error) res = await supabase.from('game_join_requests').delete().eq('id', requestId)   // pre-migration fallback
    if (!res.error && gameId) await promoteWaitlist(gameId)
    return { error: res.error?.message || null, refunded: !res.error }
  }
  const res = await supabase.from('game_join_requests').delete().eq('id', requestId)
  if (!res.error && wasAccepted && gameId) await promoteWaitlist(gameId)
  return { error: res.error?.message || null }
}

// Host marks who actually SHOWED UP (archived/active events). Powers the player
// reliability score — and enforces the forfeit rule: an online ticket of a no-show
// after lock is forfeited to the organizer (marker; payments are simulated).
export async function markAttendance(requestId, attended) {
  try {
    const { data: req } = await supabase.from('game_join_requests').select('game_id').eq('id', requestId).maybeSingle()
    const patch = { attended: !!attended }
    if (req?.game_id) {
      const { data: g } = await supabase.from('hosted_games').select('*').eq('id', req.game_id).maybeSingle()
      if (g && paidTickets(g)) patch.refund_state = attended ? '' : 'forfeited'
    }
    let res = await supabase.from('game_join_requests').update(patch).eq('id', requestId)
    if (res.error) res = await supabase.from('game_join_requests').update({ attended: !!attended }).eq('id', requestId)
    return { error: res.error?.message || null }
  } catch (e) { return { error: e.message } }
}

// Move the earliest waitlisted joiner(s) into freed spot(s). Instant events
// promote straight to ACCEPTED (and tell the joiner); host-review events move
// them to PENDING so the host approves as usual (and ping the host). Fire-and-
// forget safe: any failure just leaves them waitlisted for the next free-up.
export async function promoteWaitlist(gameId) {
  try {
    const { data: g } = await supabase.from('hosted_games').select('*').eq('id', gameId).maybeSingle()
    if (!g || g.status === 'cancelled') return
    const cap = Number(g.max_players) || 0
    if (!cap) return
    const instant = g.approval_mode === 'instant'
    const { data: reqs } = await supabase.from('game_join_requests')
      .select('*').eq('game_id', gameId).in('status', ['accepted', 'pending', 'waitlisted']).order('created_at')
    // Pending requests on review events already "occupy" the host's queue for a
    // spot — count them so we never promote more people than can be approved.
    let free = cap - (reqs || []).filter(r => r.status === 'accepted' || (!instant && r.status === 'pending')).length
    for (const r of (reqs || []).filter(r => r.status === 'waitlisted')) {
      if (free <= 0) break
      const up = await supabase.from('game_join_requests').update({ status: instant ? 'accepted' : 'pending' }).eq('id', r.id)
      if (up.error) continue
      free--
      try {
        if (instant) notifyJoinerDecision(g, r, 'accepted')
        else notifyHostNewJoiner(g, r.joiner_name, { instant: false })
      } catch { /* best-effort */ }
    }
  } catch { /* best-effort */ }
}

// A user asking Bookboll to onboard a venue they couldn't find. Lands in the admin
// "Venue requests" inbox for the sales team. Best-effort: returns an error string only
// if the venue_requests table isn't there yet (migration not applied).
export async function requestVenueOnboarding(req) {
  const res = await supabase.from('venue_requests').insert(req)
  return { error: res.error?.message || null }
}

// Host edits their published event — safe fields only. The venue, date and time are
// tied to the real court booking, so rescheduling stays cancel-and-rehost.
const HOST_EDITABLE = ['title', 'description', 'skill_level', 'price_per_person', 'max_players', 'host_name', 'host_phone', 'host_whatsapp', 'request_deadline', 'prize_pot', 'prize_note', 'game_date', 'start_time', 'end_time']
export async function updateHostedGame(gameId, patch) {
  const clean = {}
  for (const k of HOST_EDITABLE) if (k in patch) clean[k] = patch[k]
  if (!Object.keys(clean).length) return { error: null }
  // The cap can't drop below the players already accepted.
  if ('max_players' in clean && Number(clean.max_players) > 0) {
    const c = await supabase.from('game_join_requests').select('id', { count: 'exact', head: true }).eq('game_id', gameId).eq('status', 'accepted')
    if (!c.error && (c.count || 0) > Number(clean.max_players)) {
      return { error: `${c.count} players are already in — max players can't go below that.` }
    }
  }
  let res = await supabase.from('hosted_games').update(clean).eq('id', gameId)
  if (res.error) {
    // request_deadline / prize columns post-date some databases — retry with core fields.
    const core = {}
    for (const k of ['title', 'description', 'skill_level', 'price_per_person', 'max_players', 'host_name', 'host_phone', 'host_whatsapp', 'game_date', 'start_time', 'end_time']) if (k in clean) core[k] = clean[k]
    if (!Object.keys(core).length) return { error: res.error.message }
    res = await supabase.from('hosted_games').update(core).eq('id', gameId)
    if (res.error) return { error: res.error.message }
  }
  // A raised cap frees spots → let waitlisted joiners in.
  if ('max_players' in clean) await promoteWaitlist(gameId)
  return { error: null }
}

// Host cancels their own event. Also cancels the linked court booking (if any), which
// frees the slot for others. Payments are simulated, so there's no refund flow here.
export async function cancelHostedGame(gameId, bookingId) {
  const res = await supabase.from('hosted_games').update({ status: 'cancelled' }).eq('id', gameId)
  if (res.error) return { error: res.error.message }
  if (bookingId) await supabase.from('bookings').update({ booking_status: 'cancelled' }).eq('id', bookingId)
  // WhatsApp everyone who joined or asked to join (fire-and-forget, capped in eventNotify).
  try {
    const [{ data: g }, { data: reqs }] = await Promise.all([
      supabase.from('hosted_games').select('*').eq('id', gameId).maybeSingle(),
      supabase.from('game_join_requests').select('*').eq('game_id', gameId),
    ])
    if (g) notifyEventCancelled(g, reqs || [])
  } catch { /* notification is best-effort */ }
  return { ok: true }
}

// Games I'm hosting, each decorated with its pending/accepted request counts.
export async function loadMyHostedGames(customerId) {
  if (!customerId) return []
  const r = await supabase.from('hosted_games').select('*').eq('host_customer_id', customerId).order('game_date', { ascending: false })
  if (r.error) return []
  const games = await _decorateGames(r.data || [])
  const ids = games.map(g => g.id)
  let reqs = []
  if (ids.length) { const rr = await supabase.from('game_join_requests').select('game_id, status').in('game_id', ids); reqs = rr.data || [] }
  return games.map(g => ({
    ...g,
    pendingCount: reqs.filter(x => x.game_id === g.id && x.status === 'pending').length,
    acceptedCount: reqs.filter(x => x.game_id === g.id && x.status === 'accepted').length,
  }))
}

// Games I've requested to join, decorated with my request status + the game info.
export async function loadMyJoinRequests(customerId) {
  if (!customerId) return []
  const r = await supabase.from('game_join_requests').select('*').eq('joiner_customer_id', customerId).order('created_at', { ascending: false })
  if (r.error) return []
  const reqs = r.data || []
  const gameIds = [...new Set(reqs.map(x => x.game_id))]
  let games = []
  if (gameIds.length) {
    const g = await supabase.from('hosted_games').select('*').in('id', gameIds)
    games = g.error ? [] : await _decorateGames(g.data || [])
  }
  return reqs.map(req => ({ ...req, game: games.find(g => g.id === req.game_id) || null }))
}

// ── Tournaments (single-elimination brackets) ──────────────────────────────────
// Whether the tournament_matches table exists yet (migration applied). null until probed.
export const tournamentsEnabled = ref(null)
export async function probeTournaments() {
  // Only cache a SUCCESS — if the migration isn't applied yet we keep re-checking, so
  // the feature self-heals the moment it's added (no hard refresh needed). Caching a
  // false here would stick for the whole session and wrongly show "needs setup".
  if (tournamentsEnabled.value === true) return true
  // Check the num_teams column (added by the same migration as tournament_matches) —
  // a HEAD/count probe on a missing table can falsely succeed, but selecting a missing
  // column reliably errors. Both ship together, so this gates the whole feature.
  const r = await supabase.from('hosted_games').select('num_teams').limit(1)
  tournamentsEnabled.value = !r.error
  return tournamentsEnabled.value
}

// Reserve a court with a real booking (so the slot is held + the facility owner sees
// it). Mirrors createGame's reservation. Returns { booking } or { error }.
async function reserveCourt({ listing_id, location_id, customer_id, date, start_time, end_time, players, note, total = 0, amount_paid = 0, status = 'pending', method = 'cash' }) {
  // Guard with the SAME availability engine a normal customer booking uses — live holds,
  // per-slot capacity, shared-resource stock, special-day closures and past-slot rejection —
  // then take an ATOMIC hold so two concurrent publishes can't grab one court+slot. Falls
  // back to the plain slotTaken check if the listing/engine can't be loaded.
  let releaseHold = null   // set when we took a transient hold; released once the booking lands
  try {
    const { data: listing } = await supabase.from('listings').select('*').eq('id', listing_id).maybeSingle()
    if (listing) {
      const booking = await import('./booking.js')
      const avail = await booking.loadDayAvailability(listing, date)
      if (booking.dayScheduleInfo(listing, date, avail).closed)
        return { error: `The venue is closed on ${date} — pick another date for that game.` }
      const me = booking.holdToken()
      const ws = bkStart(start_time), we = bkEnd(end_time)
      const covering = booking.buildDaySlots(listing, date, avail, me).filter(x => bkStart(x.start_time) < we && bkEnd(x.end_time) > ws)
      if (!covering.length || covering.some(x => !x.available))
        return { error: `That court isn't available at ${fmt12(start_time)} on ${date} — it may have just been booked. Pick another time.` }
      const held = await booking.holdSlots(listing, date, covering.map(x => ({ start_time: x.start_time, end_time: x.end_time })))
      if (!held.ok) return { error: `That court was just booked at ${fmt12(start_time)} on ${date}. Pick another time.` }
      // Release right after the insert: the booking then occupies the slot, and a stale
      // hold would wrongly consume one of a multi-capacity court's remaining spaces for the
      // NEXT match in the same slot.
      releaseHold = () => booking.releaseHolds(listing, date)
    } else if (await slotTaken(listing_id, date, start_time, end_time)) {
      return { error: `That court is already booked at ${fmt12(start_time)} on ${date}.` }
    }
  } catch {
    if (await slotTaken(listing_id, date, start_time, end_time))
      return { error: `That court is already booked at ${fmt12(start_time)} on ${date}.` }
  }
  const bk = await supabase.from('bookings').insert({
    listing_id, location_id: location_id || null, customer_id: customer_id || null,
    booking_date: date, start_time, end_time, players: players || 1,
    base_price: total, total, amount_paid,
    payment_method: method, payment_status: status,
    booking_status: 'confirmed', origin: 'customer_game', notes: note || '',
  }).select('id').maybeSingle()
  if (releaseHold) await releaseHold()
  if (bk.error || !bk.data) return { error: bk.error?.message || 'Could not reserve the court.' }
  return { booking: bk.data }
}

/**
 * Create a tournament: one hosted_games parent (game_type 'tournament') plus one
 * tournament_matches row per match, each holding a real court reservation. `matches`
 * is a FLAT list, each individually scheduled: { round, index, listing_id,
 * location_id, date, start_time, end_time }. Matches within a round may be on
 * different courts / venues / dates / times — the caller enforces round ordering
 * (a round finishes before the next begins). Rolls back everything on failure.
 */
export async function createTournament(t) {
  const teams = Number(t.num_teams) || 0
  if (!teams) return { error: 'Pick how many teams will play.' }
  const matches = Array.isArray(t.matches) ? t.matches : []
  if (matches.length !== totalGames(teams)) return { error: 'Please schedule every game.' }
  for (const mt of matches) {
    if (!mt.listing_id || !mt.location_id || !mt.date || !mt.start_time || !mt.end_time)
      return { error: 'Every game needs a venue, court, date and time.' }
  }

  // Overlapping matches on one court are validated per-match by reserveCourt below, which
  // re-checks each against the live availability engine (respecting the court's capacity —
  // some listings host several games per slot) and rolls back the whole tournament if any
  // slot can't hold another game.

  // Parent's representative info = the earliest-scheduled match.
  const sorted = [...matches].sort((a, b) => `${a.date}T${a.start_time}`.localeCompare(`${b.date}T${b.start_time}`))
  const first = sorted[0], last = sorted[sorted.length - 1]

  const tourn = await supabase.from('hosted_games').insert({
    host_customer_id: t.host_customer_id || null,
    host_name: t.host_name || '', host_phone: t.host_phone || '', host_whatsapp: t.host_whatsapp || t.host_phone || '',
    location_id: first.location_id || null, listing_id: first.listing_id || null,
    city_id: t.city_id || null, country_id: t.country_id || null,
    title: t.title || '', sport: t.sport || '', game_type: 'tournament',
    num_teams: teams, format: 'single_elim',
    game_date: first.date, start_time: first.start_time, end_time: last.end_time,
    price_per_person: Number(t.price_per_person) || 0, currency: t.currency || 'AED',
    max_players: teams, skill_level: t.skill_level || 'all',
    description: t.description || '', status: 'open',
  }).select().maybeSingle()
  if (tourn.error || !tourn.data) return { error: tourn.error?.message || 'Could not create the tournament.' }
  const tournamentId = tourn.data.id
  // Entry-fee collection mode (arrival vs the host's Stripe) — same shape as games.
  // Best-effort AFTER the insert: with it inline, a database without the charge
  // column (migration 20260743 not applied) failed EVERY tournament publish.
  await applyCharge(tournamentId, t.charge || null)
  await applyPrizePot(tournamentId, t.prize_pot, t.prize_note, tourn.data)   // best-effort prize pot
  await applySignupForm('hosted_games', tournamentId, t.signup_form, tourn.data)   // best-effort custom form
  await applyRequestDeadline('hosted_games', tournamentId, t.request_deadline)     // best-effort deadline
  await applyApprovalMode('hosted_games', tournamentId, t.approval_mode)           // best-effort instant join

  const curr = t.currency || 'AED'
  // Host loyalty redemption — the host pays BOOKBOLL now (each match's court deposit/full +
  // the one seats service fee, all Bookboll's money). Points cap at that Bookboll-now total,
  // funded by Bookboll, and NEVER reduce any venue's on-arrival balance. Absorbed service-fee
  // first, then across the matches in order (mirrors hostPaymentRows / createGame).
  const svcFee = Number(t.service_fee) || 0
  let bookbollNowTotal = svcFee
  for (const mt of matches) {
    const ct = Number(mt.price) || 0
    bookbollNowTotal += mt.bookbollOwned ? ct : courtDeposit(ct, mt.commissionPct)
  }
  const loyaltyApplied = +Math.min(Math.max(0, Number(t.loyaltyRedeem) || 0), +bookbollNowTotal.toFixed(2)).toFixed(2)
  const svcLoyalty = +Math.min(loyaltyApplied, svcFee).toFixed(2)
  let loyBudget = +Math.max(0, loyaltyApplied - svcLoyalty).toFixed(2)   // remainder spread over match courts
  const createdBookings = []
  const matchRows = []
  const matchCharges = []   // parallel to createdBookings: per-match court charge for payment rows
  const matchLoyalties = [] // parallel: loyalty absorbed by each match's Bookboll court deposit
  for (const mt of matches) {
    const courtTotal = Number(mt.price) || 0
    // Deposit at the court's own commission rate (passed from the wizard per match).
    const courtNow = mt.bookbollOwned ? courtTotal : courtDeposit(courtTotal, mt.commissionPct)
    const courtArrival = courtTotal - courtNow
    const matchLoyalty = +Math.min(loyBudget, courtNow).toFixed(2)   // never exceeds this court's Bookboll deposit
    loyBudget = +Math.max(0, loyBudget - matchLoyalty).toFixed(2)
    const courtPaidNow = +Math.max(0, courtNow - matchLoyalty).toFixed(2)
    const status = courtTotal <= 0 ? 'pending' : (courtArrival <= 0 ? 'paid' : (courtNow > 0 ? 'partial' : 'pending'))
    const res = await reserveCourt({
      listing_id: mt.listing_id, location_id: mt.location_id, customer_id: t.host_customer_id,
      date: mt.date, start_time: mt.start_time, end_time: mt.end_time, players: teams,
      total: courtTotal, amount_paid: courtPaidNow, status, method: mt.bookbollOwned ? 'online' : (courtNow > 0 ? 'deposit' : 'cash'),
      note: `Tournament: ${t.title || t.sport} — round ${mt.round + 1}, match ${mt.index + 1}. Player payments offline; Bookboll service fee pre-paid by host.`,
    })
    if (res.error) {
      for (const id of createdBookings) await supabase.from('bookings').delete().eq('id', id)
      await supabase.from('hosted_games').delete().eq('id', tournamentId)   // cascades any matches
      return { error: res.error }
    }
    createdBookings.push(res.booking.id)
    matchCharges.push({ courtTotal, courtNow, courtArrival, bookbollOwned: !!mt.bookbollOwned, serviceFee: 0 })
    matchLoyalties.push(matchLoyalty)
    matchRows.push({
      tournament_id: tournamentId, round: mt.round, match_index: mt.index,
      listing_id: mt.listing_id, location_id: mt.location_id || null, booking_id: res.booking.id,
      match_date: mt.date, start_time: mt.start_time, end_time: mt.end_time, status: 'scheduled',
    })
  }
  const ins = await supabase.from('tournament_matches').insert(matchRows)
  if (ins.error) {
    for (const id of createdBookings) await supabase.from('bookings').delete().eq('id', id)
    await supabase.from('hosted_games').delete().eq('id', tournamentId)
    return { error: ins.error.message }
  }

  // Record simulated payments: each match's court deposit/full + balance, plus the
  // one non-refundable seats service fee (charged once, on the first match's booking).
  const payRows = []
  createdBookings.forEach((bid, i) => payRows.push(...hostPaymentRows(bid, matchCharges[i], curr, false, matchLoyalties[i])))
  if (svcFee > 0 && createdBookings[0]) payRows.push(...hostPaymentRows(createdBookings[0], { courtTotal: 0, courtNow: 0, courtArrival: 0, bookbollOwned: true, serviceFee: svcFee }, curr, true, svcLoyalty))
  if (payRows.length) await supabase.from('booking_payments').insert(payRows)
  // Loyalty: hosting a tournament earns a flat bonus (idempotent). Best-effort.
  if (tourn.data?.host_customer_id && tourn.data?.country_id) {
    awardLoyalty({ customerId: tourn.data.host_customer_id, countryId: tourn.data.country_id, flat: HOST_BONUS, sourceKey: `host:${tourn.data.id}`, reason: 'Hosted a tournament' })
  }
  return { tournament: tourn.data, loyaltyApplied }
}

// Full tournament detail: parent + matches (with court names) + team join requests.
export async function loadTournament(id) {
  const res = await supabase.from('hosted_games').select('*').eq('id', id).maybeSingle()
  if (res.error || !res.data) return null
  if (brandOwnerId) {   // branded site → only this provider's tournaments
    const ids = await brandLocationIds()
    if (!res.data.location_id || !ids.includes(res.data.location_id)) return null
  }
  const [game] = await _decorateGames([res.data])

  const m = await supabase.from('tournament_matches').select('*').eq('tournament_id', id).order('round').order('match_index')
  const matches = m.error ? [] : (m.data || [])
  const listingIds = [...new Set(matches.map(x => x.listing_id).filter(Boolean))]
  let listings = []
  if (listingIds.length) { const r = await supabase.from('listings').select('id, name').in('id', listingIds); listings = r.data || [] }
  const listingName = lid => listings.find(l => l.id === lid)?.name || ''

  const rq = await supabase.from('game_join_requests').select('*').eq('game_id', id).order('created_at')
  const requests = rq.error ? [] : (rq.data || [])

  return {
    tournament: game,
    matches: matches.map(x => ({ ...x, listingName: listingName(x.listing_id) })),
    requests,
    totalRounds: roundsFor(game.num_teams || game.max_players || 0),
  }
}

// Seed the accepted teams into the round-0 matches (sequential pairing: t0 v t1, …).
export async function generateMatchups(tournamentId, teams) {
  const rq = await supabase.from('game_join_requests')
    .select('*').eq('game_id', tournamentId).eq('status', 'accepted').order('created_at')
  const accepted = rq.error ? [] : (rq.data || [])
  const m = await supabase.from('tournament_matches')
    .select('*').eq('tournament_id', tournamentId).eq('round', 0).order('match_index')
  const round0 = m.error ? [] : (m.data || [])
  const totalRounds = Math.max(1, Math.round(Math.log2(Math.max(2, round0.length * 2))))
  for (let i = 0; i < round0.length; i++) {
    const home = accepted[i * 2] || null, away = accepted[i * 2 + 1] || null
    await supabase.from('tournament_matches').update({
      home_team: home?.joiner_name || '', home_request_id: home?.id || null,
      away_team: away?.joiner_name || '', away_request_id: away?.id || null,
    }).eq('id', round0[i].id)
    // BYE: a lone team in a round-0 pairing auto-advances — without this the
    // bracket stalls whenever fewer teams than bracket slots were accepted
    // (setMatchWinner refuses a side with no team).
    if (home && !away) {
      await supabase.from('tournament_matches').update({ winner: 'home', status: 'done' }).eq('id', round0[i].id)
      const nxt = nextMatchFor(0, i, totalRounds)
      if (nxt) {
        const target = await supabase.from('tournament_matches').select('id')
          .eq('tournament_id', tournamentId).eq('round', nxt.round).eq('match_index', nxt.index).maybeSingle()
        if (target.data) {
          const patch = nxt.slot === 'home'
            ? { home_team: home.joiner_name, home_request_id: home.id }
            : { away_team: home.joiner_name, away_request_id: home.id }
          await supabase.from('tournament_matches').update(patch).eq('id', target.data.id)
        }
      }
    }
  }
  // Teams accepted beyond the bracket size can't be seeded — surface it to the host.
  return { count: accepted.length, overflow: Math.max(0, accepted.length - round0.length * 2) }
}

// Record a match winner and advance them into the next round's match slot.
export async function setMatchWinner(tournamentId, match, side, totalRounds) {
  const team = side === 'home' ? match.home_team : match.away_team
  const reqId = side === 'home' ? match.home_request_id : match.away_request_id
  if (!team) return { error: 'That side has no team yet.' }
  const up = await supabase.from('tournament_matches').update({ winner: side, status: 'done' }).eq('id', match.id)
  if (up.error) return { error: up.error.message }

  const nxt = nextMatchFor(match.round, match.match_index, totalRounds)
  if (nxt) {
    const target = await supabase.from('tournament_matches').select('id')
      .eq('tournament_id', tournamentId).eq('round', nxt.round).eq('match_index', nxt.index).maybeSingle()
    if (target.data) {
      const patch = nxt.slot === 'home'
        ? { home_team: team, home_request_id: reqId || null }
        : { away_team: team, away_request_id: reqId || null }
      await supabase.from('tournament_matches').update(patch).eq('id', target.data.id)
    }
  }
  // WhatsApp both teams how the match went (fire-and-forget, best-effort).
  try {
    const reqIds = [match.home_request_id, match.away_request_id].filter(Boolean)
    if (reqIds.length) {
      const [{ data: g }, { data: reqRows }] = await Promise.all([
        supabase.from('hosted_games').select('*').eq('id', tournamentId).maybeSingle(),
        supabase.from('game_join_requests').select('*').in('id', reqIds),
      ])
      if (g) {
        const byId = Object.fromEntries((reqRows || []).map(r => [r.id, r]))
        notifyMatchResult(g, match, side, { home: byId[match.home_request_id], away: byId[match.away_request_id] })
      }
    }
  } catch { /* notification is best-effort */ }
  return { ok: true }
}

// Undo a recorded winner (host picked the wrong team). Clears this match's result AND
// removes the team it had advanced into the next round's slot — but refuses if that next
// match has ALREADY been decided (undo the later round first, so the bracket stays sound).
export async function clearMatchWinner(tournamentId, match, totalRounds) {
  const nxt = nextMatchFor(match.round, match.match_index, totalRounds)
  if (nxt) {
    const { data: nextM } = await supabase.from('tournament_matches')
      .select('id, winner').eq('tournament_id', tournamentId).eq('round', nxt.round).eq('match_index', nxt.index).maybeSingle()
    if (nextM?.winner) return { error: 'That team already played their next match. Undo that later result first, then change this one.' }
    if (nextM) {
      const patch = nxt.slot === 'home'
        ? { home_team: '', home_request_id: null }
        : { away_team: '', away_request_id: null }
      await supabase.from('tournament_matches').update(patch).eq('id', nextM.id)
    }
  }
  const up = await supabase.from('tournament_matches').update({ winner: '', status: 'scheduled' }).eq('id', match.id)
  if (up.error) return { error: up.error.message }
  return { ok: true }
}

// Matches whose scheduled time has PASSED but no winner is recorded yet — the host
// needs to update the bracket. Powers the bell notification AND the on-login quick-
// update popup (BracketUpdatePrompt). Only decidable matches count (both teams set).
export async function loadDueTournamentMatches(customerId) {
  if (!customerId) return []
  const t = await supabase.from('hosted_games')
    .select('id, title, sport, num_teams, max_players')
    .eq('host_customer_id', customerId).eq('game_type', 'tournament').eq('status', 'open')
  if (t.error || !t.data?.length) return []
  const byId = Object.fromEntries(t.data.map(x => [x.id, x]))
  const m = await supabase.from('tournament_matches')
    .select('*').in('tournament_id', t.data.map(x => x.id))
    .not('match_date', 'is', null)
    .order('match_date').order('start_time')
  if (m.error) return []
  const now = new Date()
  const today = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
  const nowHM = `${String(now.getHours()).padStart(2, '0')}:${String(now.getMinutes()).padStart(2, '0')}`
  return (m.data || [])
    .filter(x => !x.winner)   // undecided ('' in this schema, null defensively)
    .filter(x => x.home_team && x.away_team)
    .filter(x => x.match_date < today || (x.match_date === today && (x.end_time || '23:59').slice(0, 5) <= nowHM))
    .map(x => {
      const tn = byId[x.tournament_id]
      return { match: x, tournament: tn, totalRounds: roundsFor(tn.num_teams || tn.max_players || 0) }
    })
}
