/**
 * Laravel-backed programs / memberships for apps/web.
 * Preserves shapes expected by CustomerPrograms, ProgramDetail, MyMemberships.
 */
import { apiFetch } from '../lib/api.js'

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

function withBrand(query = {}, brandOwner = null) {
  const q = { ...query }
  if (brandOwner) q.brand_owner = brandOwner
  return q
}

export async function apiLoadPrograms(cityId, brandOwner = null) {
  if (!cityId && !brandOwner) return []
  const res = await apiFetch('/marketplace/programs', {
    query: withBrand(cityId ? { city_id: cityId } : {}, brandOwner),
  })
  if (!res.success) return []
  return res.data?.programs || []
}

export async function apiLoadProgram(id, brandOwner = null) {
  if (!id) return null
  const res = await apiFetch(`/marketplace/programs/${encodeURIComponent(id)}`, {
    query: withBrand({}, brandOwner),
  })
  if (!res.success || !res.data?.program) return null
  return {
    program: res.data.program,
    image: res.data.image || '',
    photos: res.data.photos || [],
    location: res.data.location || null,
    openingHours: res.data.openingHours || null,
    memberCount: res.data.memberCount || 0,
  }
}

export async function apiEnrollInProgram(program, { answers = {}, discount = 0, cardFp = '', loyaltyRedeem = 0 } = {}) {
  if (!program?.id) return { error: 'Program not found.' }
  const res = await apiFetch(`/customer/programs/${encodeURIComponent(program.id)}/enroll`, {
    method: 'POST',
    body: {
      answers: answers || {},
      discount: Number(discount) || 0,
      card_fp: cardFp || '',
      loyalty_redeem: Number(loyaltyRedeem) || 0,
    },
  })
  if (!res.success) return { error: formatErr(res, 'Could not complete sign-up.') }
  return {
    subscription: res.data?.subscription,
    charged: !!res.data?.charged,
    amount: res.data?.amount,
    joiningFee: res.data?.joiningFee,
    charged_amount: res.data?.charged_amount,
    discountApplied: res.data?.discountApplied,
    loyaltyApplied: res.data?.loyaltyApplied,
    introUsed: !!res.data?.introUsed,
    countryId: res.data?.countryId,
    currency: res.data?.currency,
  }
}

export async function apiLoadMySubscriptions(brandOwner = null) {
  const res = await apiFetch('/customer/programs/mine', { query: withBrand({}, brandOwner) })
  if (!res.success) return []
  return res.data?.subscriptions || []
}

export async function apiCancelMySubscription(subId) {
  if (!subId) return { error: 'Missing subscription.' }
  const res = await apiFetch(`/customer/programs/subscriptions/${encodeURIComponent(subId)}/cancel`, {
    method: 'POST',
  })
  if (!res.success) return { error: formatErr(res, 'Could not cancel.') }
  return { error: null }
}

export async function apiHasUsedIntroOffer({ email, cardFp, ownerUserId } = {}) {
  if (!ownerUserId) return false
  const res = await apiFetch('/customer/programs/offers/check', {
    method: 'POST',
    body: {
      owner_user_id: ownerUserId,
      email: email || '',
      card_fp: cardFp || '',
    },
  })
  if (!res.success) return false
  return !!res.data?.used
}

export async function apiLoadMemberPassBundle() {
  const res = await apiFetch('/customer/programs/passes')
  if (!res.success) return { pass: null, memberships: [], legacyPasses: [] }
  return {
    pass: res.data?.pass || null,
    memberships: res.data?.memberships || [],
    legacyPasses: res.data?.legacyPasses || [],
  }
}
