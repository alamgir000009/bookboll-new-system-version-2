/**
 * loyalty.js — Bookboll loyalty points: config, earn/redeem math, ledger updates.
 *
 * Defaults (apply to ALL countries/currencies unless a super admin edits the
 * per-country program in the dashboard → Countries page):
 *   • Earn:   1 point per 1 unit spent.
 *   • Redeem: 100 points = 1 unit off (redeem_rate 100).
 *
 * Redemption is ALWAYS in whole 1-currency-unit steps — redeem_value is fixed at 1
 * for every country/currency, so the only knob is "points per 1 unit" (redeem_rate).
 * Redemption is funded ENTIRELY by Bookboll's platform fee — the provider's payout
 * is never reduced. So a redemption is always capped at the order's platform fee.
 * Loyalty is a Bookboll-marketplace feature only (not on provider white-label sites);
 * callers gate that with isWhiteLabel.
 */
import { supabase } from './supabase.js'

export const DEFAULT_LOYALTY = {
  earn_rate: 1, redeem_rate: 100, redeem_value: 1,
  min_redeem: 100, max_redeem_pct: 100, is_active: true,
}

// Activity bonuses (flat, on top of any spend-based points) for games/events.
export const JOIN_BONUS = 50    // confirmed into a game/event
export const HOST_BONUS = 100   // published a game/tournament

export function normalizeLoyalty(p) {
  if (!p) return { ...DEFAULT_LOYALTY }
  return {
    earn_rate: Number(p.earn_rate ?? DEFAULT_LOYALTY.earn_rate),
    redeem_rate: Number(p.redeem_rate) > 0 ? Number(p.redeem_rate) : DEFAULT_LOYALTY.redeem_rate,
    // Redemption is standardised at 1 currency unit per step for every currency —
    // any legacy value (e.g. a stored "5") is coerced to 1, so redeem_rate always
    // reads as "points for 1 unit off".
    redeem_value: 1,
    min_redeem: Math.max(0, Number(p.min_redeem ?? DEFAULT_LOYALTY.min_redeem)),
    max_redeem_pct: Number(p.max_redeem_pct ?? DEFAULT_LOYALTY.max_redeem_pct),
    is_active: p.is_active !== false,
  }
}

/** Effective loyalty config for a country — the per-country row overrides the default. */
export async function loadLoyaltyConfig(countryId) {
  if (!countryId) return { ...DEFAULT_LOYALTY }
  try {
    const { data } = await supabase.from('loyalty_programs').select('*').eq('country_id', countryId).maybeSingle()
    return data ? normalizeLoyalty(data) : { ...DEFAULT_LOYALTY }
  } catch { return { ...DEFAULT_LOYALTY } }
}

/** Whole points earned for spending `amount`. */
export function earnedPoints(amount, cfg = DEFAULT_LOYALTY) {
  const c = normalizeLoyalty(cfg)
  return Math.max(0, Math.floor((Number(amount) || 0) * c.earn_rate))
}

/** Currency value of `points` (whole redeem-units only). */
export function pointsValue(points, cfg = DEFAULT_LOYALTY) {
  const c = normalizeLoyalty(cfg)
  return Math.floor((Number(points) || 0) / c.redeem_rate) * c.redeem_value
}

/**
 * The maximum a customer can redeem on this order.
 * @returns {{ points, amount }} amount in currency (whole redeem-units), points to spend.
 */
export function maxRedeemable({ points, platformFee, orderTotal, cfg = DEFAULT_LOYALTY }) {
  const c = normalizeLoyalty(cfg)
  if (!c.is_active) return { points: 0, amount: 0 }
  if ((Number(points) || 0) < c.min_redeem) return { points: 0, amount: 0 }
  let cap = pointsValue(points, c)                                  // value of their balance
  cap = Math.min(cap, Math.max(0, Number(platformFee) || 0))        // ← never more than the platform fee
  if (c.max_redeem_pct > 0) cap = Math.min(cap, (Number(orderTotal) || 0) * c.max_redeem_pct / 100)
  const units = Math.floor(cap / c.redeem_value)
  const amount = +(units * c.redeem_value).toFixed(2)
  const pts = units * c.redeem_rate
  if (pts < c.min_redeem || amount <= 0) return { points: 0, amount: 0 }
  return { points: pts, amount }
}

/**
 * Award loyalty points for an action. Points = spend-based (amount × earn_rate) +
 * a flat bonus. IDEMPOTENT: a unique (customer_id, source_key) ledger row means the
 * same action can never grant twice, and every award is logged (auditable — the old
 * path was silent). Returns points granted (0 if nothing/already-awarded).
 * Best-effort: degrades to a direct bump if the ledger table isn't migrated yet.
 */
export async function awardLoyalty({ customerId, countryId, amount = 0, flat = 0, sourceKey, reason = '', cfg }) {
  if (!customerId || !countryId || !sourceKey) return 0
  const c = cfg ? normalizeLoyalty(cfg) : await loadLoyaltyConfig(countryId)
  if (!c.is_active) return 0
  const pts = earnedPoints(amount, c) + Math.max(0, Math.floor(Number(flat) || 0))
  if (pts <= 0) return 0
  try {
    // Idempotency gate: try to claim this source_key in the ledger.
    const led = await supabase.from('loyalty_ledger')
      .insert({ customer_id: customerId, country_id: countryId, source_key: sourceKey, points: pts, reason })
      .select().maybeSingle()
    if (led.error) {
      // Already awarded for this action → do nothing (don't double-bump).
      if (/duplicate|unique|conflict|23505/i.test(led.error.message || '')) return 0
      // else: ledger table missing (pre-migration) → fall through to a legacy bump.
    }
    const { data: row } = await supabase.from('customer_loyalty')
      .select('id, points, lifetime_points').eq('customer_id', customerId).eq('country_id', countryId).maybeSingle()
    if (row) {
      await supabase.from('customer_loyalty').update({
        points: Number(row.points || 0) + pts,
        lifetime_points: Number(row.lifetime_points || 0) + pts,
      }).eq('id', row.id)
    } else {
      await supabase.from('customer_loyalty').insert({ customer_id: customerId, country_id: countryId, points: pts, lifetime_points: pts })
    }
  } catch { /* best-effort */ }
  return pts
}

/** A customer's earning history (the ledger, newest first) — for the "how you earned" breakdown. */
export async function loadLoyaltyLedger(customerId, { countryId = null, limit = 40 } = {}) {
  if (!customerId) return []
  try {
    let q = supabase.from('loyalty_ledger').select('id, source_key, points, reason, created_at').eq('customer_id', customerId)
    if (countryId) q = q.eq('country_id', countryId)
    const { data, error } = await q.order('created_at', { ascending: false }).limit(limit)
    return error ? [] : (data || [])
  } catch { return [] }
}

/** Spend points from the balance (lifetime/tier unaffected). Best-effort. Returns points spent. */
export async function redeemLoyalty({ customerId, countryId, points }) {
  const p = Math.max(0, Math.floor(Number(points) || 0))
  if (!customerId || !countryId || p <= 0) return 0
  try {
    const { data: row } = await supabase.from('customer_loyalty')
      .select('id, points').eq('customer_id', customerId).eq('country_id', countryId).maybeSingle()
    if (!row) return 0
    await supabase.from('customer_loyalty').update({ points: Math.max(0, Number(row.points || 0) - p) }).eq('id', row.id)
  } catch { /* best-effort */ }
  return p
}
