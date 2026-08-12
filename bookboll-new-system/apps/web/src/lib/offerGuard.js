/**
 * offerGuard.js — membership intro-offer anti-abuse.
 *
 * Rule: ONE free trial per customer per PROVIDER. A customer is matched by their
 * account email OR their card fingerprint, so neither a fresh email nor a cancel/rejoin
 * resets the offer. Repeat customers are never blocked — they simply join at standard
 * pricing with the first payment taken now (no trial).
 *
 * "N months off" discount plans are NOT gated: that discount is the plan's ongoing
 * price (applied to every billing cycle), so cancelling and rejoining gains nothing.
 *
 * Best-effort by design: if the offer_redemptions table isn't migrated yet, checks
 * report "eligible" and records are silently skipped — enrolment never breaks.
 */
import { supabase } from './supabase.js'

export function normalizeOfferEmail(email) {
  return String(email || '').trim().toLowerCase()
}

/** Stable, non-reversible fingerprint of a card number (the PAN is never stored).
 *  In production this would be the PSP's card fingerprint (e.g. Stripe's). */
export async function cardFingerprint(cardNumber) {
  const digits = String(cardNumber || '').replace(/\D/g, '')
  if (digits.length < 12) return ''
  try {
    const buf = await crypto.subtle.digest('SHA-256', new TextEncoder().encode('bb-card:' + digits))
    return Array.from(new Uint8Array(buf)).map(b => b.toString(16).padStart(2, '0')).join('')
  } catch { return '' }
}

/** Has this customer (by email OR card) already redeemed an intro trial with this provider?
 *  Bookboll-owned programs (no owner) are never gated. Pre-migration → false (eligible). */
export async function hasUsedIntroOffer({ email, cardFp, ownerUserId } = {}) {
  if (!ownerUserId) return false
  const em = normalizeOfferEmail(email)
  const ors = []
  if (em) ors.push(`customer_email.eq.${em}`)
  if (cardFp) ors.push(`card_fingerprint.eq.${cardFp}`)
  if (!ors.length) return false
  try {
    const { data, error } = await supabase.from('offer_redemptions')
      .select('id').eq('owner_user_id', ownerUserId).or(ors.join(',')).limit(1)
    if (error) return false
    return (data || []).length > 0
  } catch { return false }
}

/** Record a redeemed intro trial (best-effort — never throws, never blocks enrolment). */
export async function recordOfferRedemption({ email, cardFp, ownerUserId, programId, subscriptionId, source = 'customer_web' } = {}) {
  if (!ownerUserId) return
  try {
    await supabase.from('offer_redemptions').insert({
      customer_email: normalizeOfferEmail(email),
      card_fingerprint: cardFp || null,
      owner_user_id: ownerUserId,
      program_id: programId || null,
      subscription_id: subscriptionId || null,
      offer_type: 'trial',
      source,
    })
  } catch { /* pre-migration / transient — enrolment already succeeded */ }
}
