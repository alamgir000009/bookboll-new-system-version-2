// ── Wallet setup rules ───────────────────────────────────────────────────────
// A provider must set up how they get paid (their "wallet") BEFORE they can
// create their first membership program. The available payout methods depend on
// the provider's business country.
//
// Stripe Connect only supports businesses with a bank account in the countries
// below. Everywhere else, Bookboll's own wallet is offered (and is always available
// as the fully-wired option). (PayPal Hyperwallet was removed as a payout option.)

import { supabase } from './supabase.js'

// ISO-3166 alpha-2 codes where Stripe Connect can pay out to a local bank.
export const STRIPE_CONNECT_COUNTRIES = new Set([
  // North America
  'US', 'CA',
  // Europe & United Kingdom
  'GB', 'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR',
  'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'NO', 'PL', 'PT', 'RO', 'SK',
  'SI', 'ES', 'SE', 'CH',
  // Asia Pacific & Middle East
  'AU', 'NZ', 'SG', 'JP', 'HK', 'AE',
])

export const WALLET_METHOD_LABELS = {
  stripe_connect:     'Stripe Connect',
  paypal_hyperwallet: 'PayPal Hyperwallet',
  bookboll:           'Bookboll Wallet',
}

// A provider has a separate wallet per PURPOSE in each country.
export const WALLET_PURPOSES = ['bookings', 'subscription']
export const WALLET_PURPOSE_LABELS = { bookings: 'Bookings', subscription: 'Membership' }
export function normWalletPurpose(p) { return p === 'bookings' ? 'bookings' : 'subscription' }

// Returns the payout methods for a country + wallet purpose, always in the order
// Stripe Connect, Bookboll Wallet. Stripe is always shown (disabled when unsupported in the
// country). For a BOOKINGS wallet the Bookboll Wallet is shown but DISABLED (beta) — venue
// full-online must use a real rail; the easy Bookboll route is subscription-only for now.
export function methodsForCountry(isoCode, purpose = 'subscription') {
  const code = (isoCode || '').toUpperCase()
  const stripeOk = STRIPE_CONNECT_COUNTRIES.has(code)
  const isBookings = normWalletPurpose(purpose) === 'bookings'

  const methods = [
    {
      key: 'stripe_connect',
      label: 'Stripe Connect',
      external: true,
      available: stripeOk,
      badge: stripeOk ? 'Recommended' : 'Not available here',
      tone: stripeOk ? 'recommended' : 'disabled',
      blurb: stripeOk
        ? 'Fastest payouts straight to your bank account, with the lowest fees. The best option for your country.'
        : 'Fastest payouts straight to your bank, with the lowest fees — but Stripe Connect only supports businesses with a bank account in a supported country.',
    },
    {
      key: 'bookboll',
      label: 'Bookboll Wallet',
      external: false,
      available: !isBookings,
      badge: isBookings ? 'Beta · subscription only' : (stripeOk ? 'Alternative' : 'Recommended'),
      tone: isBookings ? 'disabled' : (stripeOk ? 'last' : 'recommended'),
      blurb: isBookings
        ? 'Coming soon for bookings. The Bookboll Wallet currently only powers subscription wallets — venue full-online payments need a real payout rail (Stripe Connect).'
        : 'Hold a balance with Bookboll and withdraw to your bank. Heads up: withdrawals ' +
          'may incur an extra 1–2% currency-conversion fee on top of the standard payment-gateway fees.',
    },
  ]

  return { stripeOk, methods }
}

// Super admin can PAUSE an approved payout method (payout_paused flag): the wallet keeps
// its status + balances but stops counting as ready/usable until resumed.
export function isWalletPaused(wallet) {
  return wallet?.payout_paused === true
}

// A wallet is "ready" (unlocks online payments / program creation) only once setup
// is fully active. Stripe/PayPal selections sit in 'pending' until their external
// onboarding is completed, so they intentionally do NOT count as ready here.
// A paused wallet is never ready, even though its setup_status stays 'active'.
export function isWalletReady(wallet) {
  return wallet?.setup_status === 'active' && !isWalletPaused(wallet)
}

// A wallet is "usable" (the country can EARN — take online payments / new subscriptions)
// as soon as a payout method is chosen, even an external rail that's still connecting
// ('pending'). The money accrues in the wallet and is disbursed once the rail is live —
// earning never pauses while connecting. (`ready`/active means the rail is fully wired.)
export function isWalletUsable(wallet) {
  return (wallet?.setup_status === 'active' || wallet?.setup_status === 'pending') && !isWalletPaused(wallet)
}

// ── Per-country wallet helpers (multi-country providers) ──────────────────────
// A provider has one CURRENT wallet per country (+ possibly legacy wallets that keep
// paying out their existing subscriptions). These resolve from the provider's wallets.

// The CURRENT wallet row for a country + purpose (the one new earnings bind to), or null.
// `purpose` defaults to 'subscription' (existing rows backfill to that); a row with no
// purpose column yet is treated as 'subscription'.
export function walletForCountry(wallets, countryId, purpose = 'subscription') {
  if (!Array.isArray(wallets) || !countryId) return null
  const want = normWalletPurpose(purpose)
  const match = (w) => w.country_id === countryId && normWalletPurpose(w.purpose) === want
  return wallets.find(w => match(w) && w.is_current !== false)
    || wallets.find(match)
    || null
}

// ── Per-PURPOSE gating (bookings vs subscription) ────────────────────────────
// Wallets of a given purpose only.
function walletsOfPurpose(wallets, purpose) {
  const want = normWalletPurpose(purpose)
  return (Array.isArray(wallets) ? wallets : []).filter(w => normWalletPurpose(w.purpose) === want)
}
// The gate that LIFTS a restriction for a purpose: an ACTIVE payout method on a wallet of that
// purpose (bookings → venue full-online; subscription → membership programs).
// Pre-migration safety: until the `purpose` column exists, NO row carries a purpose, so the
// single legacy wallet serves BOTH purposes — fall back to "any ready method" so we don't
// suddenly gate bookings before the split migration is applied.
export function hasActivePayoutMethodFor(wallets, purpose) {
  const list = Array.isArray(wallets) ? wallets : []
  const migrated = list.some(w => w && w.purpose != null)
  if (!migrated) return list.some(w => w?.setup_method && isWalletReady(w))
  return walletsOfPurpose(list, purpose).some(w => w?.setup_method && isWalletReady(w))
}
// The country's CURRENT wallet of that purpose is active / usable.
export function isCountryWalletReadyFor(wallets, countryId, purpose) {
  return isWalletReady(walletForCountry(wallets, countryId, purpose))
}
export function isCountryWalletUsableFor(wallets, countryId, purpose) {
  return isWalletUsable(walletForCountry(wallets, countryId, purpose))
}

// True when the country's CURRENT wallet is active (fully connected rail).
export function isCountryWalletReady(wallets, countryId) {
  return isWalletReady(walletForCountry(wallets, countryId))
}

// True when the country's CURRENT wallet is usable (method chosen — can earn now).
export function isCountryWalletUsable(wallets, countryId) {
  return isWalletUsable(walletForCountry(wallets, countryId))
}

// Country ids where the provider operates (has a location) but has no usable current
// wallet yet — used to nudge "set up a wallet for <country>".
export function countriesNeedingWallet(wallets, operatingCountryIds) {
  const ids = Array.from(operatingCountryIds || [])
  return ids.filter(id => id && !isCountryWalletUsable(wallets, id))
}

// A provider can take full online payments / run programs anywhere once they have ANY usable
// wallet — a local wallet isn't required up-front; earnings auto-create one per country.
export function hasUsableWallet(wallets) {
  return Array.isArray(wallets) && wallets.some(isWalletUsable)
}

// The real prerequisite for online payments is a chosen PAYOUT METHOD (Stripe or Bookboll).
// True once any wallet has a usable, method-selected setup.
export function hasPayoutMethod(wallets) {
  return Array.isArray(wallets) && wallets.some(w => w?.setup_method && isWalletUsable(w))
}

// The gate that LIFTS listing/program restrictions: the provider has an ACTIVE
// (fully approved/connected) payout method on at least one wallet. Manual payouts
// only reach 'active' after Bookboll approves the KYC; Stripe Connect reaches it on
// connect. A merely-submitted ('pending_review') or rejected method does NOT lift.
export function hasActivePayoutMethod(wallets) {
  return Array.isArray(wallets) && wallets.some(w => w?.setup_method && isWalletReady(w))
}

// Account-level payout state derived from the provider's wallets, for the UI/admin:
//   'active' | 'paused' | 'pending_review' | 'rejected' | 'none'
// Prefers an active (unpaused) method, then a paused one, then a submitted one,
// then a rejected one.
export function payoutSetupState(wallets) {
  const list = Array.isArray(wallets) ? wallets : []
  if (list.some(w => w?.setup_method && w.setup_status === 'active' && !isWalletPaused(w))) return 'active'
  if (list.some(w => w?.setup_method && w.setup_status === 'active' && isWalletPaused(w))) return 'paused'
  if (list.some(w => w?.setup_status === 'pending_review')) return 'pending_review'
  if (list.some(w => w?.setup_status === 'rejected')) return 'rejected'
  return 'none'
}

// Stripe Connect = AUTOMATIC payout (split at checkout, paid out per earning, no withdrawal).
// Anything else (Bookboll Wallet) = MANUAL withdrawal.
export function isAutomaticPayout(wallet) {
  return wallet?.setup_method === 'stripe_connect'
}

// Record an AUTOMATIC payout (Stripe split): the money was disbursed at checkout, so we log a
// PAID withdrawal_requests row per earning. Best-effort + resilient to missing optional columns.
export async function recordAutoPayout({ ownerUserId, wallet, amount, currency, sourceLabel }) {
  if (!ownerUserId || !wallet?.id || !(Number(amount) > 0)) return
  const payload = {
    owner_user_id: ownerUserId,
    country_id: wallet.country_id || null,
    wallet_id: wallet.id,
    amount: Number(amount),
    currency: currency || wallet.currency || 'AED',
    payout_method: 'stripe_connect',
    payout_details: 'Automatic Stripe Connect payout (split at checkout)',
    status: 'paid',
    notes: sourceLabel ? `Auto-payout · ${sourceLabel}` : 'Automatic payout',
  }
  try {
    const ins = await supabase.from('withdrawal_requests').insert(payload)
    if (ins.error) {
      const { notes, country_id, wallet_id, payout_details, ...min } = payload
      await supabase.from('withdrawal_requests').insert(min)
    }
  } catch { /* ignore */ }
}

// Resolve the wallet a payment in `countryId` + `purpose` pays into, AUTO-CREATING a local wallet
// for that (country, purpose) if the provider doesn't have one yet — inheriting their chosen payout
// method + bank details from an existing SAME-PURPOSE wallet, in the country's own currency.
// Returns the wallet row (null if no owner). `purpose`: 'bookings' | 'subscription'.
export async function ensureCountryWallet(ownerUserId, countryId, fallbackCurrency, purpose = 'subscription') {
  if (!ownerUserId) return null
  const want = normWalletPurpose(purpose)
  const { data: wallets } = await supabase.from('provider_wallets').select('*').eq('owner_user_id', ownerUserId)
  const list = wallets || []
  const existing = countryId ? walletForCountry(list, countryId, want) : null
  if (existing) return existing
  // Template to inherit the payout method from — a SAME-PURPOSE usable wallet (so a bookings
  // wallet never inherits a Bookboll method, which isn't allowed for bookings).
  const samePurpose = list.filter(w => normWalletPurpose(w.purpose) === want)
  const template = samePurpose.find(w => w.is_current !== false && isWalletUsable(w)) || samePurpose.find(isWalletUsable) || samePurpose[0] || null
  // The new wallet earns in the country's own currency.
  let currency = fallbackCurrency || template?.currency || 'AED'
  if (countryId) {
    const { data: country } = await supabase.from('countries').select('currency_code').eq('id', countryId).maybeSingle()
    if (country?.currency_code) currency = country.currency_code
  }
  const seed = { owner_user_id: ownerUserId, country_id: countryId || null, currency, is_current: true, purpose: want }
  // Inherit method + bank details from the template (only fields that exist on its row).
  // payout_paused is inherited too — an auto-created wallet must not silently resurrect
  // a payout method the super admin paused.
  const INHERIT = ['setup_method', 'setup_status', 'setup_completed_at', 'hold_days', 'payout_paused',
    'payout_bank_country', 'payout_bank_name', 'payout_account_holder', 'payout_iban',
    'payout_account_number', 'payout_routing_number', 'payout_swift', 'payout_account_type']
  if (template) for (const f of INHERIT) if (template[f] != null) seed[f] = template[f]
  let { data: created, error } = await supabase.from('provider_wallets').insert(seed).select().maybeSingle()
  if (error) { const { purpose: _p, ...base } = seed; ({ data: created } = await supabase.from('provider_wallets').insert(base).select().maybeSingle()) }  // pre-migration fallback
  return created || template || null
}
