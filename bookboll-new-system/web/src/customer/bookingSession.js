/**
 * bookingSession.js — a single, persisted "checkout in progress" for venue booking.
 *
 * When a customer holds slots on a listing, the hold (and its countdown) used to live only
 * inside ListingDetail and was released the moment they navigated away or refreshed. This
 * store lifts that into a global, localStorage-backed session so the reservation SURVIVES
 * navigation + refresh, and a floating <CheckoutResumeCard> can offer "Continue" / "Close".
 *
 * One session at a time: starting a hold on a different listing replaces (and releases) the
 * previous one. The countdown is driven by an absolute `expiresAt` epoch, so a reload resumes
 * from the correct remaining time. On expiry (or Close) the holds are released and the card
 * auto-dismisses.
 */
import { ref } from 'vue'
import { apiFetch } from '../lib/api.js'

// Holds live in Laravel `slot_locks`, scoped server-side to this device's session
// (helpers.php getSessionId) — so a release only needs the listing id.
function releaseListingHolds(listingId) {
  return apiFetch('/unlock-all-slots', { method: 'POST', body: { listing_id: listingId } })
}

const KEY = 'bb_checkout_session'

function hydrate() {
  try {
    const s = JSON.parse(localStorage.getItem(KEY) || 'null')
    if (s && Number(s.expiresAt) > Date.now()) return s
  } catch { /* ignore */ }
  localStorage.removeItem(KEY)
  return null
}

// { listingId, listingName, locationName, currency, total, slotCount, whenLabel,
//   selectionByDate, expiresAt, holdToken } | null
export const checkoutSession = ref(hydrate())
export const checkoutRemaining = ref(secondsLeft())   // live seconds remaining

function persist() {
  if (checkoutSession.value) localStorage.setItem(KEY, JSON.stringify(checkoutSession.value))
  else localStorage.removeItem(KEY)
}

function secondsLeft() {
  const exp = Number(checkoutSession.value?.expiresAt) || 0
  return exp ? Math.max(0, Math.round((exp - Date.now()) / 1000)) : 0
}

export function setCheckoutSession(s) {
  const prev = checkoutSession.value
  // Single active session: switching to a DIFFERENT venue releases the previous venue's
  // holds (different listing_id, so this never touches the new venue's holds).
  if (prev && prev.listingId !== s.listingId && prev.listingId) {
    releaseListingHolds(prev.listingId).catch(() => {})
  }
  checkoutSession.value = s
  persist()
  checkoutRemaining.value = secondsLeft()
  ensureTicking()
}

export function clearCheckoutSession() {
  checkoutSession.value = null
  persist()
  checkoutRemaining.value = 0
  stopTicking()
}

// Close / expiry: best-effort release this device's holds for the session's listing, then clear.
export async function endCheckoutSession() {
  const s = checkoutSession.value
  clearCheckoutSession()
  if (s?.listingId) {
    try { await releaseListingHolds(s.listingId) }
    catch { /* holds expire on their own anyway */ }
  }
}

// One module-level interval, only running while a session exists.
let timer = null
function ensureTicking() {
  if (timer || !checkoutSession.value) return
  timer = setInterval(() => {
    checkoutRemaining.value = secondsLeft()
    if (checkoutRemaining.value <= 0) endCheckoutSession()
  }, 1000)
}
function stopTicking() { if (timer) { clearInterval(timer); timer = null } }

// Resume ticking if we hydrated an active session on load.
if (checkoutSession.value) ensureTicking()
