import { apiFetch } from './api.js'

// The owner review path for own-site listings that take no online bookings.
//
// Payment on arrival is gone from that channel, so a listing that offered only
// cash there now takes nothing. The listing editor already guides one listing
// at a time; this is the list of every affected one, so an owner with forty
// courts does not have to open forty editors to find them.
//
// Nothing here chooses for the owner. The server refuses a request without an
// explicit method, and these helpers never send one the owner did not pick.

export const RESOLUTIONS = ['deposit', 'online', 'hide']

export const RESOLUTION_LABELS = {
  deposit: 'Take a deposit online',
  online: 'Take full payment online',
  hide: 'Remove from my website',
}

export const REASON_LABELS = {
  legacy_cash_only: 'Was set to payment on arrival only',
  no_online_method: 'No online payment method is switched on',
}

export const REFUSAL_LABELS = {
  not_found: 'No longer available to you',
  already_resolved: 'Already set up — left as it is',
  payouts_not_connected: 'Connect payouts before taking full payment online',
}

export const EMPTY_QUEUE = { listings: [], total: 0, complete: false, resolutions: RESOLUTIONS }

function listingRow(row) {
  if (!row || !Number.isInteger(row.id)) return null
  const available = row.available && typeof row.available === 'object' ? row.available : {}
  return {
    id: row.id,
    name: typeof row.name === 'string' && row.name.trim() ? row.name : 'Listing',
    venueName: typeof row.venue_name === 'string' ? row.venue_name : '',
    reason: REASON_LABELS[row.reason] ? row.reason : 'no_online_method',
    onMarketplace: row.on_marketplace === true,
    preview: {
      currency: typeof row.preview?.currency === 'string' ? row.preview.currency : '',
      basePrice: Number(row.preview?.base_price) || 0,
      effectiveRatePct: Number(row.preview?.effective_rate_pct) || 0,
      planEligible: row.preview?.plan_eligible === true,
      depositDueNow: Number(row.preview?.deposit_due_now) || 0,
      arrivalBalance: Number(row.preview?.arrival_balance) || 0,
    },
    // A method the server has not confirmed is unavailable, not offered. Full
    // online needs payouts in that venue's country, so this is per listing.
    available: {
      deposit: available.deposit === true,
      online: available.online === true,
      hide: available.hide === true,
    },
    onlineBlockedReason: typeof row.online_blocked_reason === 'string' ? row.online_blocked_reason : null,
  }
}

/**
 * The queue.
 *
 * `complete` says whether the count covers everything or only what came back.
 * A banner reading "3 listings need attention" when it means "3 on this page"
 * sends an owner away believing they are done.
 */
export async function loadOwnSiteReview({ perPage = 100 } = {}) {
  let res
  try {
    res = await apiFetch('/admin/listings/own-site-review', { query: { per_page: perPage } })
  } catch {
    return { ok: false, unsupported: false, error: 'Could not check your website listings.', queue: EMPTY_QUEUE }
  }

  // An older server without the endpoint is not a failure to show the owner;
  // the listing editor still guides them one listing at a time.
  if ([404, 405].includes(res?.status)) {
    return { ok: false, unsupported: true, queue: EMPTY_QUEUE }
  }
  if (!res?.success || !Array.isArray(res.data?.listings)) {
    return {
      ok: false,
      unsupported: false,
      error: typeof res?.message === 'string' && res.message.trim() ? res.message : 'Could not check your website listings.',
      queue: EMPTY_QUEUE,
    }
  }

  const listings = res.data.listings.map(listingRow).filter(Boolean)
  const total = Number.isInteger(res.data.total) ? res.data.total : listings.length

  return {
    ok: true,
    unsupported: false,
    queue: {
      listings,
      total,
      complete: res.data.coverage?.status === 'complete',
      resolutions: Array.isArray(res.data.resolutions) ? res.data.resolutions.filter(r => RESOLUTIONS.includes(r)) : RESOLUTIONS,
    },
  }
}

/**
 * Apply one method the owner named to the listings they picked.
 *
 * Reports both halves. A bulk apply that silently skips some listings leaves
 * the owner believing all of them are fixed — so a partial result is a partial
 * result, not a success.
 */
export async function applyOwnSiteMethod(method, listingIds) {
  if (!RESOLUTIONS.includes(method)) return { ok: false, error: 'Choose what should happen to these listings.' }
  // Positive integers only. Number(null) is 0, which would otherwise be sent
  // as a listing id and come back as "not found" — a request nobody meant.
  const ids = [...new Set((listingIds || []).map(Number).filter(id => Number.isInteger(id) && id > 0))]
  if (!ids.length) return { ok: false, error: 'Pick at least one listing.' }

  let res
  try {
    res = await apiFetch('/admin/listings/own-site-review', { method: 'POST', body: { method, listing_ids: ids } })
  } catch {
    return { ok: false, error: 'Could not save that change. Please try again.' }
  }

  if (!res?.success) {
    return { ok: false, error: typeof res?.message === 'string' && res.message.trim() ? res.message : 'Could not save that change.' }
  }

  const applied = Array.isArray(res.data?.applied) ? res.data.applied : []
  const refused = Array.isArray(res.data?.refused) ? res.data.refused : []

  return {
    // Confirmed from what the server says it applied, not from the 200.
    ok: applied.length > 0,
    applied,
    refused: refused.map(row => ({
      id: row.listing_id,
      name: typeof row.name === 'string' ? row.name : '',
      reason: row.reason,
      label: REFUSAL_LABELS[row.reason] || 'Could not be changed',
    })),
    remaining: Number.isInteger(res.data?.remaining) ? res.data.remaining : null,
    error: applied.length ? '' : 'None of those listings were changed.',
  }
}

/** What to tell the owner after an apply. */
export function applySummary(result) {
  if (!result) return ''
  if (!result.applied?.length && !result.refused?.length) return result.error || ''

  const done = result.applied.length
  const parts = []
  if (done) parts.push(`${done} ${done === 1 ? 'listing' : 'listings'} updated`)
  if (result.refused.length) parts.push(`${result.refused.length} left unchanged`)
  return parts.join(' · ')
}
