/**
 * offerGuard.js — membership intro-offer anti-abuse (Laravel-backed).
 *
 * Rule: ONE free trial per customer per PROVIDER. Redemption is recorded
 * server-side on enroll; this module only checks eligibility for the UI.
 */
import { apiHasUsedIntroOffer } from '../customer/apiPrograms.js'

export function normalizeOfferEmail(email) {
  return String(email || '').trim().toLowerCase()
}

/** Stable, non-reversible fingerprint of a card number (the PAN is never stored). */
export async function cardFingerprint(cardNumber) {
  const digits = String(cardNumber || '').replace(/\D/g, '')
  if (digits.length < 12) return ''
  try {
    const buf = await crypto.subtle.digest('SHA-256', new TextEncoder().encode('bb-card:' + digits))
    return Array.from(new Uint8Array(buf)).map(b => b.toString(16).padStart(2, '0')).join('')
  } catch { return '' }
}

export async function hasUsedIntroOffer({ email, cardFp, ownerUserId } = {}) {
  return apiHasUsedIntroOffer({ email, cardFp, ownerUserId })
}

/** No-op on client — Laravel enroll records the redemption. */
export async function recordOfferRedemption() {
  return
}
