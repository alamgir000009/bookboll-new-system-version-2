/**
 * booking.js — customer-side facility booking, reusing the SAME engine as the
 * admin dashboard: slot generation/conflicts from lib/slots.js, the pricing-cell →
 * pricing-group → base_price resolution, and the bookings/booking_payments/
 * booking_addons tables. Customer web bookings are created with origin
 * 'bookboll_web' (a recognised admin origin) and payment pending (pay at venue).
 */
const PER_SPACE = new Set(['per_space', 'court'])
export function isPerPerson(listing) {
  const t = listing?.listing_type
  return !!t && !PER_SPACE.has(t)
}

// ── Slot holds ("reserved while you check out") ─────────────────────────────────
// A hold temporarily reserves a slot for THIS device so other users can't grab it,
// and the atomic RPC guarantees capacity is never exceeded. Everything degrades
// gracefully: if the bb_hold_* functions / slot_holds table aren't deployed yet,
// holds are skipped and booking behaves exactly as before.

// Stable per-device token used as the hold owner (works for guests, not just signed-in).
export function extensionSlotPrice(listing, fullPrice) {
  const mode = listing?.extension_pricing || 'none'
  const val = Number(listing?.extension_value) || 0
  if (mode === 'flat') return Math.max(0, val)
  if (mode === 'percent') return Math.max(0, Number(fullPrice) * (1 - val / 100))
  return Number(fullPrice) || 0   // 'none' → extra slots at full price
}
export function extensionsEnabled(listing) {
  return (listing?.extension_pricing === 'percent' || listing?.extension_pricing === 'flat') && Number(listing?.extension_value) > 0
}

// How many adjacent slots AFTER the first in a contiguous run may be discounted as
// extensions (default 1). e.g. 1 → 6pm full, 7pm discounted, 8pm back to full price.
export function extensionMaxSlots(listing) {
  return Math.max(1, Math.floor(Number(listing?.extension_max_slots) || 1))
}
// Within a contiguous run, is the slot at 0-based index `i` a discounted extension?
// Index 0 is the base (full price); indices 1..maxSlots are discounted; beyond that full price.
export function isExtensionIndex(listing, i, maxSlots = extensionMaxSlots(listing)) {
  return i >= 1 && i <= maxSlots
}

/**
 * The next slot the customer can be *offered* as a discounted extension: the
 * available slot immediately after the end of their current selection. Returns
 * null if extensions are off, nothing's selected, or there's no free next slot.
 */
function groupBlocks(sortedSlots) {
  const blocks = []
  for (const s of sortedSlots) {
    const last = blocks[blocks.length - 1]
    if (last && last[last.length - 1].end_time === s.start_time) last.push(s)
    else blocks.push([s])
  }
  return blocks
}

// Add-ons are simple products: line charge = quantity × price, charged once for the whole
// booking (no per-hour / per-slot scaling). Extra args are ignored (legacy callers).
export function addonAmount(addon, qty) {
  return (qty || 0) * Number(addon.price || 0)
}

// ── Payment model ──────────────────────────────────────────────────────────────
// Bookboll-owned venue (location has no owner_user_id) → full ONLINE payment (all the
// money is Bookboll's). Provider venue → DEPOSIT (the platform fee, paid online) +
// remaining paid to the provider ON ARRIVAL. A Bookboll admin may also switch a
// provider listing to CASH (all on arrival) via cash_on_arrival.
/**
 * Resolve the payment options that apply for the current SURFACE.
 *  - 'marketplace' (default): the listing's standard options, unchanged.
 *  - 'site' (provider's own white-label site): the provider-customizable `site_*`
 *    options. Full online is only offered when the provider has a connected payout
 *    method (`providerHasPayout`); otherwise that method is hidden.
 * Returns an object shaped like a listing_payment_options row, so paymentPlan/checkout
 * consume it unchanged.
 */
export function effectivePaymentOptions(po, { surface = 'marketplace', providerHasPayout = false } = {}) {
  if (surface !== 'site') return po
  // No saved options row yet → the provider-site defaults (10% deposit + cash;
  // full-online needs a payout method anyway).
  if (!po) return { deposit_enabled: true, online_payment_enabled: false, cash_on_arrival: true, deposit_percentage: 10, deposit_amount: 0 }
  return {
    deposit_enabled: !!po.site_deposit_enabled,
    online_payment_enabled: !!po.site_online_enabled && !!providerHasPayout,
    cash_on_arrival: !!po.site_cash_enabled,
    deposit_percentage: Number(po.site_deposit_percentage) || 0,
    deposit_amount: 0,
  }
}

export function paymentPlan(location, paymentOptions) {
  const bookbollOwned = !location?.owner_user_id
  const o = paymentOptions
  const depositPct = Number(o?.deposit_percentage) || 0
  const depositAmount = Number(o?.deposit_amount) || 0
  if (o) {
    // Whatever the admin enabled — any combination; the customer picks if >1.
    const methods = []
    if (o.online_payment_enabled) methods.push('online')
    if (o.deposit_enabled) methods.push('deposit')
    if (o.cash_on_arrival) methods.push('cash')
    if (methods.length) return { bookbollOwned, methods, depositPct, depositAmount }
  }
  // No options row yet → sensible default from ownership.
  return bookbollOwned
    ? { bookbollOwned, methods: ['online'], depositPct: 0, depositAmount: 0 }
    : { bookbollOwned, methods: ['deposit'], depositPct: depositPct || 20, depositAmount }
}
// Split a total into what's paid online now vs on arrival, for a chosen method.
// Deposit = fixed amount if set, else percentage (defaults to 20% if neither set).
export function paymentSplit(method, total, depositPct = 0, depositAmount = 0) {
  total = Number(total) || 0
  if (method === 'online') return { method: 'online', online: total, arrival: 0, deposit: 0, total }
  if (method === 'cash')   return { method: 'cash', online: 0, arrival: total, deposit: 0, total }
  const amt = Number(depositAmount) || 0
  const pct = Number(depositPct) || 0
  let deposit = amt > 0 ? amt : Math.round(total * (pct > 0 ? pct : 20) / 100)
  deposit = Math.max(0, Math.min(deposit, total))
  return { method: 'deposit', online: deposit, arrival: total - deposit, deposit, total }
}
// Pure pricing only — this file computes quotes and payment splits and takes no money.
// The actual charge happens server-side: customer/apiBooking.js posts to
// /customer/payments/create-checkout-session and the customer is redirected to Stripe.
export function quoteBooking(listing, selectedSlots, addonsSelected = [], players = 1) {
  const slots = [...(selectedSlots || [])].sort((a, b) => (a.start_time < b.start_time ? -1 : 1))
  const perPerson = isPerPerson(listing)
  const ppl = perPerson ? Math.max(1, players) : 1
  const maxExt = extensionMaxSlots(listing)
  const blocks = groupBlocks(slots).map(block => {
    let total = 0
    // Index 0 = base (full); 1..maxExt = discounted extensions; beyond that back to full price.
    block.forEach((s, i) => { total += (isExtensionIndex(listing, i, maxExt) ? extensionSlotPrice(listing, s.price) : (Number(s.price) || 0)) })
    total *= ppl
    return { slots: block, start: block[0].start_time, end: block[block.length - 1].end_time, total }
  })
  const slotsTotal = blocks.reduce((n, b) => n + b.total, 0)
  const fullTotal = slots.reduce((n, s) => n + (Number(s.price) || 0), 0) * ppl   // price with no extension discount
  const slotMins = Number(listing?.slot_duration) || 60
  const totalHours = (slots.length * slotMins) / 60
  const pricedAddons = (addonsSelected || []).map(a => ({ ...a, amount: addonAmount(a, a.qty, totalHours) }))
  const addonsTotal = pricedAddons.reduce((n, a) => n + a.amount, 0)
  return { blocks, slotsTotal, addonsTotal, total: slotsTotal + addonsTotal, totalHours, totalSlots: slots.length, pricedAddons, savings: Math.max(0, fullTotal - slotsTotal) }
}

/**
 * Create bookings for a multi-slot selection. Contiguous runs become one booking
 * each (spanning the run); non-contiguous selections become separate bookings in
 * the same checkout. Add-ons are charged once and attached to the first booking.
 * Payment is pending (pay at the venue). Same tables as the admin.
 */