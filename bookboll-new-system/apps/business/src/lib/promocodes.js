// ── Promocodes: validation, discount + redemption tracking ─────────────────────
// Shared by the customer checkout (apply a code) and the admin page (usage views).
// A code carries: discount_type/value, min_order, usage_limit/usage_count, valid_from/to,
// status, applicable_to (all|facility|program), owner_user_id + location_id scope,
// audience (all|new|specific) + target_emails + per_customer_limit, recipient.
import { supabase } from './supabase.js'

/** Discount this code yields on `amount` (never more than the order). */
export function computeDiscount(code, amount) {
  const amt = Number(amount) || 0
  if (!code || amt <= 0) return 0
  let d = code.discount_type === 'percentage'
    ? amt * (Number(code.discount_value) || 0) / 100
    : (Number(code.discount_value) || 0)
  d = Math.min(d, amt)
  return Math.max(0, Math.round(d * 100) / 100)
}

/** Quick "is this code currently usable at all" (no per-customer/scope context). */
export function isCodeLive(code, now = new Date()) {
  if (!code || code.status !== 'active') return false
  const today = now.toISOString().slice(0, 10)
  if (code.valid_from && today < code.valid_from) return false
  if (code.valid_to && today > code.valid_to) return false
  if (code.usage_limit && Number(code.usage_count || 0) >= Number(code.usage_limit)) return false
  return true
}

export const AUDIENCE_LABEL = { all: 'Everyone', new: 'New customers', specific: 'Specific customers' }

/** Coerce a (possibly null / string / array) scope column into a clean id array. */
function scopeArr(v) {
  if (Array.isArray(v)) return v.filter(Boolean)
  if (typeof v === 'string' && v.trim()) { try { const p = JSON.parse(v); return Array.isArray(p) ? p.filter(Boolean) : [] } catch { return [] } }
  return []
}
/** Effective scope for a dimension: prefer the multi array, fall back to the legacy single id. */
function effScope(arr, single) { const a = scopeArr(arr); return a.length ? a : (single ? [single] : []) }

/**
 * Validate a code for a checkout.
 * ctx: { amount, applicableTo:'facility'|'program', locationId, ownerUserId (or ownerId),
 *        listingId, programId, customerEmail } → { ok:true, code, discount } | { ok:false, reason }
 */
export async function validatePromo(rawCode, ctx = {}) {
  const code = (rawCode || '').trim().toUpperCase()
  if (!code) return { ok: false, reason: 'Enter a promo code.' }

  const { data: rows, error } = await supabase.from('promocodes').select('*').ilike('code', code)
  if (error) return { ok: false, reason: 'Could not check the code — try again.' }
  const c = (rows || [])[0]
  if (!c) return { ok: false, reason: 'Invalid promo code.' }

  const today = new Date().toISOString().slice(0, 10)
  if (c.status !== 'active') return { ok: false, reason: 'This code is no longer active.' }
  if (c.valid_from && today < c.valid_from) return { ok: false, reason: 'This code isn’t active yet.' }
  if (c.valid_to && today > c.valid_to) return { ok: false, reason: 'This code has expired.' }
  if (c.usage_limit && Number(c.usage_count || 0) >= Number(c.usage_limit)) return { ok: false, reason: 'This code has reached its usage limit.' }

  // Scope: applicable_to + multi-scope (businesses / locations / listings / programs).
  // Each dimension only restricts when the code lists ids for it; an empty list = "any".
  // (`ownerUserId` is the canonical ctx key; older callers pass `ownerId`.)
  const ctxOwner = ctx.ownerUserId || ctx.ownerId || null
  // Provider-plan codes (Bookboll Premium upgrade) are isolated: they never apply to
  // bookings/memberships, and a plan upgrade only accepts codes explicitly marked for it
  // (an "all" bookings code must NOT discount someone's subscription).
  if (ctx.applicableTo === 'provider_plan') {
    if (c.applicable_to !== 'provider_plan') return { ok: false, reason: 'This code can’t be used for a plan upgrade.' }
  } else if (c.applicable_to === 'provider_plan') {
    return { ok: false, reason: 'This code only applies to Bookboll Premium plan upgrades.' }
  } else if (c.applicable_to && c.applicable_to !== 'all' && ctx.applicableTo && c.applicable_to !== ctx.applicableTo) {
    return { ok: false, reason: `This code only applies to ${c.applicable_to === 'facility' ? 'court rentals' : 'memberships'}.` }
  }
  const owners = effScope(c.owner_user_ids, c.owner_user_id)
  if (owners.length && ctxOwner && !owners.includes(ctxOwner)) {
    return { ok: false, reason: 'This code isn’t valid for this provider.' }
  }
  const locs = effScope(c.location_ids, c.location_id)
  if (locs.length && ctx.locationId && !locs.includes(ctx.locationId)) {
    return { ok: false, reason: 'This code isn’t valid at this location.' }
  }
  const listings = scopeArr(c.listing_ids)
  if (listings.length && !(ctx.listingId && listings.includes(ctx.listingId))) {
    return { ok: false, reason: 'This code isn’t valid for this listing.' }
  }
  const programs = scopeArr(c.program_ids)
  if (programs.length && !(ctx.programId && programs.includes(ctx.programId))) {
    return { ok: false, reason: 'This code isn’t valid for this membership.' }
  }
  if (c.min_order && Number(ctx.amount || 0) < Number(c.min_order)) {
    return { ok: false, reason: `Spend at least ${Number(c.min_order).toLocaleString()} to use this code.` }
  }

  // Audience targeting + per-customer limit
  const email = (ctx.customerEmail || '').trim().toLowerCase()
  if (c.audience === 'specific') {
    const list = Array.isArray(c.target_emails) ? c.target_emails.map(e => String(e).toLowerCase()) : []
    if (!email || !list.includes(email)) return { ok: false, reason: 'This code is reserved for selected customers.' }
  }
  if (email && (c.audience === 'new' || Number(c.per_customer_limit) > 0)) {
    const { data: reds } = await supabase.from('promocode_redemptions').select('promocode_id').eq('customer_email', email)
    const all = reds || []
    if (c.audience === 'new' && all.length) return { ok: false, reason: 'This code is for first-time customers only.' }
    if (Number(c.per_customer_limit) > 0 && all.filter(r => r.promocode_id === c.id).length >= Number(c.per_customer_limit)) {
      return { ok: false, reason: 'You’ve already used this code.' }
    }
  }

  const discount = computeDiscount(c, ctx.amount)
  if (discount <= 0) return { ok: false, reason: 'This code gives no discount on this order.' }
  return { ok: true, code: c, discount }
}

/** Record a redemption + bump usage_count. Best-effort (never blocks the order). */
export async function recordRedemption({ code, customer = {}, source = {}, amount, discount, currency }) {
  if (!code?.id) return
  try {
    await supabase.from('promocode_redemptions').insert({
      promocode_id: code.id,
      code: code.code || '',
      customer_email: (customer.email || '').toLowerCase(),
      customer_name: customer.name || '',
      source_type: source.type || '',
      source_id: source.id || null,
      order_amount: Number(amount) || 0,
      discount_amount: Number(discount) || 0,
      currency: currency || 'AED',
      recipient: code.recipient || '',
      owner_user_id: code.owner_user_id || null,
      country_id: source.countryId || null,
    })
    await supabase.from('promocodes').update({ usage_count: Number(code.usage_count || 0) + 1 }).eq('id', code.id)
  } catch { /* best-effort */ }
}

/** All redemptions for a code, newest first. */
export async function loadRedemptions(promocodeId) {
  if (!promocodeId) return []
  const { data } = await supabase.from('promocode_redemptions')
    .select('*').eq('promocode_id', promocodeId).order('redeemed_at', { ascending: false })
  return data || []
}
