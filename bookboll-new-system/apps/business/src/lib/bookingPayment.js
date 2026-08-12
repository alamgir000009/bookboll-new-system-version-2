import { supabase } from './supabase.js'
import { ensureCountryWallet, isAutomaticPayout, recordAutoPayout } from './walletSetup.js'
import { applyWalletDelta } from './walletLedger.js'

/**
 * Records a FULL ONLINE booking payment for a PROVIDER venue as a split ledger,
 * mirroring recordSubscriptionPayment (lib/subscriptionPayment.js):
 *   1. @bookboll commission row    — booking_payments, status 'paid'
 *   2. @<provider_username> earning — booking_payments, status 'paid'
 *      (payout hold lifecycle is tracked separately in wallet_transactions)
 * Plus a wallet_transactions credit (on hold) and a provider_wallets balance bump.
 *
 * Only call this for provider-owned venues paying the FULL amount online — that's
 * the money that flows through Bookboll and must be paid out. Deposit/cash and
 * Bookboll-owned venues do not use this.
 *
 * @param {object} args
 * @param {string} args.bookingId
 * @param {string} args.ownerUserId   provider's admin_users.id (location.owner_user_id)
 * @param {string} [args.countryId]   the listing's country (location → city → country).
 *                                    The provider's wallet FOR THAT COUNTRY receives the
 *                                    payout (multi-country providers have one per country).
 * @param {number} args.total         the full amount charged online
 * @param {string} args.currency
 * @param {number} args.ratePct       Bookboll commission % (effective rate for the listing)
 * @param {string} [args.customerLabel]
 * @returns {Promise<object|null>} the wallet record (created if missing), or null on failure
 */
export async function recordBookingOnlinePayment({ bookingId, ownerUserId, countryId, total, currency, ratePct, customerLabel, loyaltyRedeem = 0 }) {
  if (!ownerUserId || !(Number(total) > 0)) return null
  const gross = Number(total) || 0
  const rate = Math.max(0, Math.min(100, Number(ratePct) || 0))
  const platformFee = Math.round(gross * rate) / 100
  const providerShare = +(gross - platformFee).toFixed(2)
  // Loyalty redemption is funded ENTIRELY by Bookboll's platform fee — the provider's
  // share is never touched. Cap it at the platform fee no matter what was requested.
  const loyalty = Math.max(0, Math.min(Number(loyaltyRedeem) || 0, platformFee))
  const netPlatformFee = +(platformFee - loyalty).toFixed(2)
  const collected = +(gross - loyalty).toFixed(2)   // what the customer actually paid online
  const curr = currency || 'AED'

  // Resolve provider user (need country_id to derive wallet currency)
  const { data: providerUser } = await supabase
    .from('admin_users')
    .select('username, full_name, business_name, country_id')
    .eq('id', ownerUserId)
    .maybeSingle()
  const providerRecipient = providerUser?.username
    ? `@${providerUser.username}`
    : (providerUser?.business_name || providerUser?.full_name || '@provider')

  // Resolve the COUNTRY wallet (multi-country providers have one wallet per country).
  // Fall back to the provider's home country if no countryId was passed.
  const resolvedCountryId = countryId || providerUser?.country_id || null
  // Credit the country's BOOKINGS wallet — auto-created (inheriting the provider's chosen
  // bookings payout method) if they don't have one there yet, so each country's earnings stay
  // in its own currency. Venue full-online money is kept separate from subscription earnings.
  const wallet = await ensureCountryWallet(ownerUserId, resolvedCountryId, curr, 'bookings')
  if (!wallet) return null

  const holdDays = Number(wallet.hold_days) || 7
  const now = new Date()
  const availableOn = new Date(now)
  availableOn.setDate(availableOn.getDate() + holdDays)
  const available_on = availableOn.toISOString().slice(0, 10)
  const label = customerLabel || 'customer'
  const description = loyalty > 0 ? `Full online booking payment (− ${curr} ${loyalty} loyalty)` : 'Full online booking payment'

  // 1. Bookboll commission row — paid immediately. Reduced by any loyalty redemption
  //    (the redemption is absorbed by Bookboll's fee, not the provider).
  await supabase.from('booking_payments').insert({
    booking_id: bookingId,
    source_type: 'booking',
    payment_type: 'booking_commission',
    method: 'online',
    amount: netPlatformFee,
    currency: curr,
    recipient: '@bookboll',
    status: 'paid',
    paid_at: now.toISOString(),
    notes: description,
  })

  // 2. Provider earning row — paid immediately (hold lifecycle in wallet_transactions)
  await supabase.from('booking_payments').insert({
    booking_id: bookingId,
    source_type: 'booking',
    payment_type: 'booking_earning',
    method: 'online',
    amount: providerShare,
    currency: curr,
    recipient: providerRecipient,
    status: 'paid',
    paid_at: now.toISOString(),
    notes: description,
  })

  const automatic = isAutomaticPayout(wallet)   // Stripe split = auto-paid; else manual withdrawal

  // 3. Wallet transaction credit. Automatic = settled immediately (no hold); manual = on hold.
  const { data: walletTx } = await supabase.from('wallet_transactions').insert({
    owner_user_id: ownerUserId,
    type: 'booking_online',
    direction: 'credit',
    amount: collected,
    provider_share: providerShare,
    platform_fee: netPlatformFee,
    source_id: bookingId,
    source_label: `Booking · ${label}`,
    description: automatic ? `${description} — auto-paid via Stripe` : description,
    status: automatic ? 'completed' : 'on_hold',
    currency: curr,
    country_id: wallet.country_id || resolvedCountryId,   // group under the wallet that holds it
    wallet_id: wallet.id,
    available_on: automatic ? null : available_on,
    hold_days_snapshot: automatic ? 0 : holdDays,
  }).select().maybeSingle()

  // 4. Settle. Automatic → record a paid payout per earning (nothing accrues to withdraw);
  //    manual → accrue to pending until the hold clears, then the provider withdraws.
  if (automatic) {
    await recordAutoPayout({ ownerUserId, wallet, amount: providerShare, currency: curr, sourceLabel: `Booking · ${label}` })
    await applyWalletDelta(wallet.id, { earned: providerShare, withdrawn: providerShare })
  } else {
    await applyWalletDelta(wallet.id, { pending: providerShare, earned: providerShare })
  }

  return { wallet, walletTx, available_on, holdDays, platformFee: netPlatformFee, providerShare, loyaltyRedeemed: loyalty, automatic }
}
