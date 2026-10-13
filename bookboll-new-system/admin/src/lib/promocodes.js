/**
 * Promocodes — Laravel admin API + shared discount helpers.
 * UI shape matches the enhanced apps/admin Promocodes page.
 */
import { apiFetch } from './api.js'

export function computeDiscount(code, amount) {
  const amt = Number(amount) || 0
  if (!code || amt <= 0) return 0
  let d = code.discount_type === 'percentage'
    ? amt * (Number(code.discount_value) || 0) / 100
    : (Number(code.discount_value) || 0)
  d = Math.min(d, amt)
  return Math.max(0, Math.round(d * 100) / 100)
}

export function isCodeLive(code, now = new Date()) {
  if (!code || code.status !== 'active') return false
  const today = now.toISOString().slice(0, 10)
  if (code.valid_from && today < code.valid_from) return false
  if (code.valid_to && today > code.valid_to) return false
  if (code.usage_limit && Number(code.usage_count || 0) >= Number(code.usage_limit)) return false
  return true
}

export const AUDIENCE_LABEL = { all: 'Everyone', new: 'New customers', specific: 'Specific customers' }

export async function fetchPromocodes(query = {}) {
  return apiFetch('/admin/promocodes', { query: { dashboard: 1, ...query } })
}

export async function fetchPromocodeOptions() {
  return apiFetch('/admin/promocodes/options')
}

export async function createPromocode(body) {
  return apiFetch('/admin/promocodes', { method: 'POST', body })
}

export async function updatePromocode(id, body) {
  return apiFetch(`/admin/promocodes/${id}`, { method: 'PUT', body })
}

export async function deletePromocode(id) {
  return apiFetch(`/admin/promocodes/${id}`, { method: 'DELETE' })
}

export async function loadRedemptions(promocodeId) {
  if (!promocodeId) return []
  const res = await apiFetch(`/admin/promocodes/${promocodeId}/redemptions`)
  if (!res.success) return []
  return res.data?.redemptions || []
}

function scopeArr(v) {
  if (Array.isArray(v)) return v.filter(Boolean)
  if (typeof v === 'string' && v.trim()) {
    try {
      const p = JSON.parse(v)
      return Array.isArray(p) ? p.filter(Boolean) : []
    } catch { return [] }
  }
  return []
}

function effScope(arr, single) {
  const a = scopeArr(arr)
  return a.length ? a : (single ? [single] : [])
}

function idsMatch(list, value) {
  if (value == null || value === '') return false
  const want = String(value)
  return list.some((id) => String(id) === want)
}

/**
 * Validate a code for checkout / Premium upgrade.
 * ctx: { amount, applicableTo, locationId, ownerUserId (or ownerId),
 *        listingId, programId, customerEmail }
 * → { ok:true, code, discount } | { ok:false, reason }
 */
export async function validatePromo(rawCode, ctx = {}) {
  const code = (rawCode || '').trim().toUpperCase()
  if (!code) return { ok: false, reason: 'Enter a promo code.' }

  const res = await fetchPromocodes({ search: code, records_per_page: 50, page: 1 })
  if (!res.success) return { ok: false, reason: 'Could not check the code — try again.' }
  const c = (res.data?.promocodes || []).find((row) => String(row.code || '').toUpperCase() === code)
  if (!c) return { ok: false, reason: 'Invalid promo code.' }

  const today = new Date().toISOString().slice(0, 10)
  if (c.status !== 'active') return { ok: false, reason: 'This code is no longer active.' }
  if (c.valid_from && today < c.valid_from) return { ok: false, reason: 'This code isn’t active yet.' }
  if (c.valid_to && today > c.valid_to) return { ok: false, reason: 'This code has expired.' }
  if (c.usage_limit && Number(c.usage_count || 0) >= Number(c.usage_limit)) {
    return { ok: false, reason: 'This code has reached its usage limit.' }
  }

  const ctxOwner = ctx.ownerUserId || ctx.ownerId || null
  if (ctx.applicableTo === 'provider_plan') {
    if (c.applicable_to !== 'provider_plan') return { ok: false, reason: 'This code can’t be used for a plan upgrade.' }
  } else if (c.applicable_to === 'provider_plan') {
    return { ok: false, reason: 'This code only applies to Bookboll Premium plan upgrades.' }
  } else if (c.applicable_to && c.applicable_to !== 'all' && ctx.applicableTo && c.applicable_to !== ctx.applicableTo) {
    return { ok: false, reason: `This code only applies to ${c.applicable_to === 'facility' ? 'court rentals' : 'memberships'}.` }
  }

  const owners = effScope(c.owner_user_ids, c.owner_user_id)
  if (owners.length && ctxOwner && !idsMatch(owners, ctxOwner)) {
    return { ok: false, reason: 'This code isn’t valid for this provider.' }
  }
  const locs = effScope(c.location_ids, c.location_id)
  if (locs.length && ctx.locationId && !idsMatch(locs, ctx.locationId)) {
    return { ok: false, reason: 'This code isn’t valid at this location.' }
  }
  const listings = scopeArr(c.listing_ids)
  if (listings.length && !(ctx.listingId && idsMatch(listings, ctx.listingId))) {
    return { ok: false, reason: 'This code isn’t valid for this listing.' }
  }
  const programs = scopeArr(c.program_ids)
  if (programs.length && !(ctx.programId && idsMatch(programs, ctx.programId))) {
    return { ok: false, reason: 'This code isn’t valid for this membership.' }
  }
  if (c.min_order && Number(ctx.amount || 0) < Number(c.min_order)) {
    return { ok: false, reason: `Spend at least ${Number(c.min_order).toLocaleString()} to use this code.` }
  }

  const email = (ctx.customerEmail || '').trim().toLowerCase()
  if (c.audience === 'specific') {
    const list = Array.isArray(c.target_emails) ? c.target_emails.map((e) => String(e).toLowerCase()) : []
    if (!email || !list.includes(email)) return { ok: false, reason: 'This code is reserved for selected customers.' }
  }
  if (email && (c.audience === 'new' || Number(c.per_customer_limit) > 0)) {
    const reds = await loadRedemptions(c.id)
    if (c.audience === 'new' && reds.some((r) => String(r.customer_email || '').toLowerCase() === email)) {
      return { ok: false, reason: 'This code is for first-time customers only.' }
    }
    if (Number(c.per_customer_limit) > 0) {
      const used = reds.filter((r) => String(r.customer_email || '').toLowerCase() === email).length
      if (used >= Number(c.per_customer_limit)) return { ok: false, reason: 'You’ve already used this code.' }
    }
  }

  const discount = computeDiscount(c, ctx.amount)
  if (discount <= 0) return { ok: false, reason: 'This code gives no discount on this order.' }
  return { ok: true, code: c, discount }
}

export async function recordRedemption({ code, customer = {}, source = {}, amount, discount, currency }) {
  if (!code?.id) return
  try {
    await apiFetch(`/admin/promocodes/${code.id}/redemptions`, {
      method: 'POST',
      body: {
        customer_email: (customer.email || '').toLowerCase(),
        customer_name: customer.name || '',
        source_type: source.type || '',
        source_id: source.id || null,
        order_amount: Number(amount) || 0,
        discount_amount: Number(discount) || 0,
        currency: currency || '',
        recipient: code.recipient || '',
        owner_user_id: code.owner_user_id || null,
        country_id: source.countryId || null,
      },
    })
  } catch { /* best-effort */ }
}

export function firstError(res, fallback = 'Could not save promocode.') {
  if (!res) return fallback
  const errors = res.errors || {}
  const first = Object.values(errors).flat().find(Boolean)
  if (first) return String(first)
  if (/already been taken|duplicate|unique/i.test(res.message || '')) return 'A promocode with this code already exists.'
  return res.message || fallback
}
