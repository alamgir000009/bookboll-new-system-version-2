import { redemptionFields } from '../lib/checkoutRewards.js'
/**
 * Laravel-backed hosting APIs for apps/web.
 *
 * Mirrors the shapes the host wizard and the community Meetups tab already expect
 * from data.js, so the pages only need their import swapped.
 *
 * The server prices the host's bill — the wizard sends what the host picked
 * (court, times, seats, points to spend) and gets the charge back, so what it
 * shows is what gets charged.
 */
import { apiFetch } from '../lib/api.js'
import { selectedCountry } from '../lib/customerSession.js'
import { ref } from 'vue'

/** Final fallback when the API can't be reached. */
export const SEAT_FEE_PCT = 10

export const gamesEnabled = ref(null)
export const tournamentsEnabled = ref(null)
export const hangoutLocationEnabled = ref(null)

/**
 * What a FREE event at the host's own spot pays to publish.
 *
 * An own-spot event books no court and a free one sells no seats, so there is no
 * percentage for the platform to take — publishing was free, which made the Events
 * feed free to spam. The server charges this flat fee instead, in its own currency
 * (USD): there is no venue to take a currency from and no FX rate to convert with.
 * Mirrored here only so the wizard can SAY so before the host picks that option.
 */
export const OWN_SPOT_MIN_FEE = 5
export const ownSpotMinFee = ref(OWN_SPOT_MIN_FEE)
export const ownSpotMinFeeCurrency = ref('USD')

function formatErr(res, fallback) {
  const errors = res?.errors
  if (errors && typeof errors === 'object') {
    for (const v of Object.values(errors)) {
      const msg = Array.isArray(v) ? v[0] : v
      if (msg) return String(msg)
    }
  }
  return res?.message || fallback
}

/**
 * Feature flags plus the two percentages the wizard prices with. Cached per
 * listing so moving between steps doesn't re-fetch.
 */
const _config = {}
export async function gameConfig(listingId = null, community = false) {
  const key = `${listingId || 0}:${community ? 1 : 0}`
  if (_config[key]) return _config[key]

  const res = await apiFetch('/marketplace/games/config', {
    query: { ...(listingId ? { listing_id: listingId } : {}), ...(community ? { community: 1 } : {}) },
  })
  const data = res.success ? (res.data || {}) : {}

  gamesEnabled.value = data.games_enabled !== false
  tournamentsEnabled.value = data.tournaments_enabled !== false
  hangoutLocationEnabled.value = data.hangout_enabled !== false
  ownSpotMinFee.value = Number(data.own_spot_min_fee ?? OWN_SPOT_MIN_FEE)
  // No stand-in code: the publish fee is real money, and quoting it in a
  // currency the server never named misleads the host.
  ownSpotMinFeeCurrency.value = String(data.own_spot_min_fee_currency || '')

  _config[key] = {
    courtCommissionPct: Number(data.court_commission_pct ?? 15),
    seatFeePct: Number(data.seat_fee_pct ?? SEAT_FEE_PCT),
    bookbollOwned: !!data.bookboll_owned,
    paymentMethods: Array.isArray(data.payment_methods) ? data.payment_methods : [],
    depositAmount: Number(data.deposit_amount ?? 0),
    depositPercentage: Number(data.deposit_percentage ?? 0),
  }
  return _config[key]
}

export async function probeGames() {
  await gameConfig()
  return gamesEnabled.value
}

export async function probeTournaments() {
  await gameConfig()
  return tournamentsEnabled.value
}

export async function probeHangoutLocation() {
  await gameConfig()
  return hangoutLocationEnabled.value
}

// Pure helpers — the wizard uses these to preview the bill while the host types.
export const seatFee = (amount) => Math.round((Number(amount) || 0) * SEAT_FEE_PCT / 100)
export const seatFeeAt = (amount, pct) =>
  Math.round((Number(amount) || 0) * (Number.isFinite(Number(pct)) ? Number(pct) : SEAT_FEE_PCT) / 100)
export const courtDeposit = (amount, pct) =>
  Math.round((Number(amount) || 0) * (Number.isFinite(Number(pct)) ? Number(pct) : 15) / 100)

export async function eventSeatFeePct(listingId, { community = false } = {}) {
  return (await gameConfig(listingId, community)).seatFeePct
}

export async function courtCommissionPct(listingId) {
  return (await gameConfig(listingId)).courtCommissionPct
}

/**
 * How this court may be paid for — the provider's own settings, the same ones a
 * customer booking it would see.
 */
export async function courtPaymentPlan(listingId) {
  const c = await gameConfig(listingId)
  return { methods: c.paymentMethods, depositAmount: c.depositAmount, depositPercentage: c.depositPercentage }
}

/**
 * Split a court total the way the chosen method pays it. Mirrors the server so the
 * wizard shows exactly what will be charged.
 */
export function splitCourt(total, method, plan = {}) {
  const amount = Number(total) || 0
  if (method === 'online') return { now: amount, arrival: 0 }
  if (method === 'cash') return { now: 0, arrival: amount }
  const flat = Number(plan.depositAmount) || 0
  const pct = Number(plan.depositPercentage) || 0
  // No house default: an unconfigured deposit charges nothing server-side, so quoting
  // a made-up percentage here would show the host a figure nothing collects.
  const deposit = Math.max(0, Math.min(flat > 0 ? flat : Math.floor(amount * pct / 100), amount))
  return { now: deposit, arrival: amount - deposit }
}

/** Labels for the host's payment choice. */
export const PAY_METHOD_LABEL = {
  online: 'Pay the venue in full now',
  deposit: 'Pay a deposit now, the rest at the venue',
  cash: 'Pay the venue on arrival',
}

/** Everything the host chose. The server prices it and reserves the court. */
function gamePayload(game) {
  return {
    game_type: game.game_type || 'game',
    listing_id: game.listing_id || null,
    city_id: game.city_id || null,
    country_id: game.country_id || null,
    title: game.title || '',
    sport: game.sport || '',
    game_date: game.game_date,
    start_time: game.start_time,
    end_time: game.end_time,
    price_per_person: Number(game.price_per_person) || 0,
    max_players: Number(game.max_players) || 0,
    skill_level: game.skill_level || 'all',
    description: game.description || '',
    prize_pot: Number(game.prize_pot) || 0,
    prize_note: game.prize_note || '',
    custom_location_name: game.custom_location_name || '',
    custom_location_address: game.custom_location_address || '',
    hide_location: !!game.hide_location,
    images: game.images || [],
    signup_form: game.signup_form || [],
    request_deadline: game.request_deadline || null,
    approval_mode: game.approval_mode || 'review',
    allow_waitlist: game.allow_waitlist !== false,
    lock_minutes_before: game.lock_minutes_before || null,
    play_format: game.play_format || null,
    courts_count: game.courts_count || null,
    community_id: game.community_id || null,
    community_public: !!game.community_public,
    host_name: game.host_name || '',
    host_phone: game.host_phone || '',
    host_whatsapp: game.host_whatsapp || game.host_phone || '',
    ...redemptionFields(game.rewards),
    ticket_mode: game.ticket_mode || 'arrival',
    payment_method: game.payment_method || null,
  }
}

/** Price the selection without publishing, so the wizard can show the bill. */
export async function quoteGame(game) {
  const res = await apiFetch('/customer/games/quote', { method: 'POST', body: gamePayload(game) })
  if (!res.success) return { error: formatErr(res, 'Could not price this.') }
  return { charge: res.data?.charge || null }
}

/** Publish a game or a hangout. */
export async function createGame(game) {
  try { redemptionFields(game.rewards) } catch (error) { return { error: error.message } }
  if (!game.game_date) return { error: 'Please pick a date.' }
  if (!game.start_time || !game.end_time) return { error: 'Please set a start and end time.' }

  const res = await apiFetch('/customer/games', { method: 'POST', body: gamePayload(game) })
  if (!res.success || !res.data?.game) {
    return { error: formatErr(res, 'Could not publish this.') }
  }

  // The server already spent the points, so the caller must not redeem again.
  return {
    game: res.data.game,
    charge: res.data.charge || null,
    loyaltyApplied: Number(res.data.charge?.loyaltyRedeem) || 0,
    // When there is money to pay the event is created but NOT published, and the
    // host has to finish at Stripe first. The caller must send them there rather
    // than showing a share link for an event that isn't live yet.
    requiresPayment: !!res.data.requires_payment,
    checkoutUrl: res.data.checkout_url || '',
  }
}

/**
 * Every joinable event in a city — the Events page. The server drops finished and
 * cancelled events; the page filters by search, sport and price on top.
 */
export async function loadGames(cityId) {
  const iso = selectedCountry.value?.iso_code || ''
  if (!cityId && !iso) return []
  const query = {}
  if (cityId) query.city_id = cityId
  if (iso) query.country_iso = iso
  const res = await apiFetch('/marketplace/games', { query })
  gamesEnabled.value = !!res.success
  if (!res.success) return []
  return res.data?.games || []
}

/** Events I'm hosting, with how the roster is filling up. */
export async function loadMyHostedGames(customerId) {
  if (!customerId) return []
  const res = await apiFetch('/customer/games/mine/hosted')
  if (!res.success) return []
  return res.data?.games || []
}

/** Events I've asked to join, each with the event attached. */
export async function loadMyJoinRequests(customerId) {
  if (!customerId) return []
  const res = await apiFetch('/customer/games/mine/joined')
  if (!res.success) return []
  return res.data?.requests || []
}

/** Events posted to a community — the community page's Meetups tab. */
export async function loadCommunityEvents(communityId) {
  if (!communityId) return []
  const res = await apiFetch(`/marketplace/communities/${encodeURIComponent(communityId)}/events`)
  if (!res.success) return []
  return res.data?.events || []
}

/** Stamp an existing event with a community. */
export async function applyCommunityId(gameId, communityId, isPublic = false) {
  if (!gameId || !communityId) return
  await apiFetch(`/customer/games/${encodeURIComponent(gameId)}/community`, {
    method: 'PATCH',
    body: { community_id: communityId, community_public: !!isPublic },
  })
}

/** Toggle whether a community event is also listed publicly. */
export async function setCommunityEventPublic(gameId, isPublic) {
  if (!gameId) return { error: 'Missing event.' }
  const res = await apiFetch(`/customer/games/${encodeURIComponent(gameId)}/community`, {
    method: 'PATCH',
    body: { community_public: !!isPublic },
  })
  if (!res.success) return { error: formatErr(res, 'Could not update the event.') }
  return { ok: true }
}

// ── Viewing and joining ──────────────────────────────────────────────────────

/** One event, decorated the same way the cards are. */
export async function loadGame(id) {
  if (!id) return null
  const res = await apiFetch(`/marketplace/games/${encodeURIComponent(id)}`)
  if (!res.success || !res.data?.game) return null
  return res.data.game
}

/** The viewer's own request for an event, so we don't ask them to join twice. */
export async function myRequestForGame(gameId, customerId) {
  if (!gameId || !customerId) return null
  const res = await apiFetch(`/customer/games/${encodeURIComponent(gameId)}/my-request`)
  if (!res.success) return null
  return res.data?.request || null
}

/** The host's roster. */
export async function loadGameRequests(gameId) {
  if (!gameId) return []
  const res = await apiFetch(`/customer/games/${encodeURIComponent(gameId)}/requests`)
  if (!res.success) return []
  return res.data?.requests || []
}

/**
 * Ask to join. A members-only community event comes back with the community to
 * join first, so the page can point the visitor there instead of a dead end.
 */
export async function requestToJoin(gameId, joiner = {}) {
  const res = await apiFetch(`/customer/games/${encodeURIComponent(gameId)}/join`, {
    method: 'POST',
    body: {
      joiner_name: joiner.name || '',
      joiner_phone: joiner.phone || '',
      joiner_whatsapp: joiner.whatsapp || joiner.phone || '',
      joiner_message: joiner.message || '',
      form_answers: joiner.form_answers || {},
    },
  })
  if (!res.success) {
    const communityId = res.errors?.community_id || null
    return { error: formatErr(res, 'Could not send your request.'), communityOnly: !!communityId, communityId }
  }
  return { request: res.data?.request }
}

/** Accept everyone waiting, oldest first, without going over the cap. */
export async function acceptAllRequests(gameId) {
  if (!gameId) return { error: 'Missing event.' }
  const res = await apiFetch(`/customer/games/${encodeURIComponent(gameId)}/requests/accept-all`, { method: 'POST' })
  if (!res.success) return { error: formatErr(res, 'Could not accept the requests.') }
  return {
    accepted: Number(res.data?.accepted) || 0,
    leftover: Number(res.data?.leftover) || 0,
    full: !!res.data?.full,
  }
}

/** Host accepts or rejects someone. */
export async function respondToRequest(requestId, status) {
  const res = await apiFetch(`/customer/game-requests/${encodeURIComponent(requestId)}`, {
    method: 'PATCH',
    body: { status },
  })
  if (!res.success) return { error: formatErr(res, 'Could not update the request.') }
  return { ok: true }
}

/** Drop a request — the joiner backing out, or the host removing them. */
export async function cancelJoinRequest(requestId) {
  const res = await apiFetch(`/customer/game-requests/${encodeURIComponent(requestId)}`, { method: 'DELETE' })
  if (!res.success) return { error: formatErr(res, 'Could not cancel.') }
  return { ok: true }
}

/** Host edits an event after publishing. */
export async function updateHostedGame(gameId, patch) {
  const res = await apiFetch(`/customer/games/${encodeURIComponent(gameId)}`, { method: 'PATCH', body: patch })
  if (!res.success) return { error: formatErr(res, 'Could not save.') }
  return { error: null, game: res.data?.game }
}

/** Host calls the event off; the court reservation is released with it. */
export async function cancelHostedGame(gameId) {
  const res = await apiFetch(`/customer/games/${encodeURIComponent(gameId)}/cancel`, { method: 'POST' })
  if (!res.success) return { error: formatErr(res, 'Could not cancel the event.') }
  return { ok: true }
}

/** Host ticks off who turned up. */
export async function markAttendance(requestId, attended) {
  const res = await apiFetch(`/customer/game-requests/${encodeURIComponent(requestId)}/attendance`, {
    method: 'POST',
    body: { attended: !!attended },
  })
  return { error: res.success ? null : formatErr(res, 'Could not save attendance.') }
}

/** Host's saved round sheet. */
export async function saveRounds(gameId, rounds) {
  const res = await apiFetch(`/customer/games/${encodeURIComponent(gameId)}/rounds`, {
    method: 'POST',
    body: { rounds },
  })
  return { error: res.success ? null : formatErr(res, 'Could not save rounds.') }
}

/** A country's ISO code, for phone dial-code defaults. Cached per id. */
const _iso = {}
export async function loadCountryIso(id) {
  if (!id) return ''
  if (_iso[id] !== undefined) return _iso[id]
  const res = await apiFetch('/marketplace/countries')
  const rows = res.success ? (res.data?.countries || res.data || []) : []
  for (const c of rows) _iso[c.id] = c.iso_code || c.iso2 || ''
  return _iso[id] || ''
}

// ── Tournaments ──────────────────────────────────────────────────────────────

/** Publish a knockout bracket. Every game reserves its own court. */
export async function createTournament(t) {
  try { redemptionFields(t.rewards) } catch (error) { return { error: error.message } }
  const matches = Array.isArray(t.matches) ? t.matches : []
  if (!matches.length) return { error: 'Please schedule every game.' }

  for (const m of matches) {
    if (!m.listing_id || !m.date || !m.start_time || !m.end_time) {
      return { error: 'Every game needs a venue, court, date and time.' }
    }
  }

  const res = await apiFetch('/customer/tournaments', {
    method: 'POST',
    body: {
      title: t.title || '',
      sport: t.sport || '',
      num_teams: Number(t.num_teams) || 0,
      city_id: t.city_id || null,
      country_id: t.country_id || null,
      price_per_person: Number(t.price_per_person) || 0,
      max_players: Number(t.num_teams) || 0,
      skill_level: t.skill_level || 'all',
      description: t.description || '',
      prize_pot: Number(t.prize_pot) || 0,
      prize_note: t.prize_note || '',
      signup_form: t.signup_form || [],
      request_deadline: t.request_deadline || null,
      approval_mode: t.approval_mode || 'review',
      lock_minutes_before: t.lock_minutes_before || null,
      community_id: t.community_id || null,
      community_public: !!t.community_public,
      host_name: t.host_name || '',
      host_phone: t.host_phone || '',
      host_whatsapp: t.host_whatsapp || t.host_phone || '',
      ...redemptionFields(t.rewards),
      ticket_mode: t.ticket_mode || 'arrival',
      payment_method: t.payment_method || null,
      matches: matches.map((m) => ({
        round: Number(m.round) || 0,
        index: Number(m.index) || 0,
        listing_id: m.listing_id,
        date: m.date,
        start_time: m.start_time,
        end_time: m.end_time,
      })),
    },
  })

  if (!res.success || !res.data?.tournament) {
    return { error: formatErr(res, 'Could not publish the tournament.') }
  }

  // The server already spent the points, so the caller must not redeem again.
  return {
    tournament: res.data.tournament,
    charge: res.data.charge || null,
    loyaltyApplied: Number(res.data.charge?.loyaltyRedeem) || 0,
    requiresPayment: !!res.data.requires_payment,
    checkoutUrl: res.data.checkout_url || '',
  }
}

/** A tournament with its bracket, matches and teams. */
export async function loadTournament(id) {
  if (!id) return null
  const res = await apiFetch(`/marketplace/tournaments/${encodeURIComponent(id)}`)
  if (!res.success || !res.data?.tournament) return null
  return {
    tournament: res.data.tournament,
    matches: res.data.matches || [],
    requests: res.data.requests || [],
    totalRounds: Number(res.data.totalRounds) || 1,
  }
}

/** Seed round one from the accepted teams. */
export async function generateMatchups(tournamentId) {
  const res = await apiFetch(`/customer/tournaments/${encodeURIComponent(tournamentId)}/matchups`, { method: 'POST' })
  if (!res.success) return { error: formatErr(res, 'Could not seed the bracket.') }
  return { count: res.data?.count || 0, overflow: res.data?.overflow || 0 }
}

/** Record a match result; the winner moves into the next round. */
export async function setMatchWinner(tournamentId, match, side) {
  const res = await apiFetch(`/customer/tournament-matches/${encodeURIComponent(match.id)}/winner`, {
    method: 'PATCH',
    body: { winner: side },
  })
  if (!res.success) return { error: formatErr(res, 'Could not save the result.') }
  return { ok: true }
}

/** Undo a match result — refused once the winner has played on. */
export async function clearMatchWinner(tournamentId, match) {
  const res = await apiFetch(`/customer/tournament-matches/${encodeURIComponent(match.id)}/winner`, { method: 'DELETE' })
  if (!res.success) return { error: formatErr(res, 'Could not clear the result.') }
  return { ok: true }
}

/**
 * Tournament games whose time has passed with no winner yet — the host still has
 * to advance the bracket. Powers the bell and the on-login prompt.
 */
export async function loadDueTournamentMatches(customerId) {
  if (!customerId) return []
  const res = await apiFetch('/customer/games/mine/due-matches')
  if (!res.success) return []
  return res.data?.due || []
}

/** Raw rows the notification bell builds its list from. */
export async function loadNotificationFeed(customerId) {
  if (!customerId) return null
  const res = await apiFetch('/customer/notifications/feed')
  if (!res.success) return null
  return res.data || null
}
