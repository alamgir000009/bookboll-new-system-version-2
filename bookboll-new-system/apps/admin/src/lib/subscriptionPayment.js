import { supabase } from './supabase.js'
import { ensureCountryWallet, isAutomaticPayout, recordAutoPayout } from './walletSetup.js'
import { applyWalletDelta } from './walletLedger.js'

/**
 * Records a subscription payment as two booking_payments rows (split ledger):
 *   1. @bookboll commission row  — status: 'paid'
 *   2. @<provider_username> earnings row — status: 'paid'
 *      (payout hold lifecycle is tracked separately in wallet_transactions)
 *
 * Also inserts a wallet_transactions credit and updates provider_wallets balances.
 * Returns the wallet record (created if missing), or null on failure.
 */
export async function recordSubscriptionPayment(subscription, program, isFirstCharge = false, firstChargeDiscount = 0, loyaltyRedeem = 0) {
  const fullGross = Number(subscription.monthly_amount) || 0
  // A signup promo discounts ONLY this first charge — the recurring price (monthly_amount)
  // stays intact. Split the commission/share over the discounted amount at the same rate.
  const disc = isFirstCharge ? Math.min(Math.max(0, Number(firstChargeDiscount) || 0), fullGross) : 0
  const gross = +(fullGross - disc).toFixed(2)
  const feeRate = fullGross > 0 ? (Number(subscription.platform_fee) || 0) / fullGross : 0
  const platformFee = +(gross * feeRate).toFixed(2)
  const providerShare = +(gross - platformFee).toFixed(2)
  // Loyalty redemption (first charge only): funded ENTIRELY by Bookboll's commission —
  // it reduces the commission row, never the provider's earning. Capped at the fee.
  const loyalty = isFirstCharge ? Math.min(Math.max(0, Number(loyaltyRedeem) || 0), platformFee) : 0
  const netPlatformFee = +(platformFee - loyalty).toFixed(2)
  const currency = program.currency || 'AED'

  // Resolve provider user (need country_id to derive wallet currency)
  const { data: providerUser } = await supabase
    .from('admin_users')
    .select('username, full_name, business_name, country_id')
    .eq('id', program.owner_user_id)
    .maybeSingle()
  const providerRecipient = providerUser?.username
    ? `@${providerUser.username}`
    : (providerUser?.business_name || providerUser?.full_name || '@provider')

  // Credit the wallet the subscription is BOUND to (the one it was created under). Old
  // subscriptions keep paying their original/legacy wallet; switching the provider's
  // gateway never moves an existing subscription. Fall back (legacy data) to the owner's
  // CURRENT wallet for the program's country.
  let wallet = null
  if (subscription.wallet_id) {
    const { data } = await supabase.from('provider_wallets').select('*').eq('id', subscription.wallet_id).maybeSingle()
    wallet = data
  }
  if (!wallet) {
    let resolvedCountryId = subscription.country_id || program.country_id || null
    if (!resolvedCountryId && program.location_id) {
      const { data: loc } = await supabase.from('locations').select('city_id').eq('id', program.location_id).maybeSingle()
      if (loc?.city_id) {
        const { data: city } = await supabase.from('cities').select('country_id').eq('id', loc.city_id).maybeSingle()
        resolvedCountryId = city?.country_id || null
      }
    }
    if (!resolvedCountryId) resolvedCountryId = providerUser?.country_id || null
    // Auto-create the program country's SUBSCRIPTION wallet (inheriting the provider's payout
    // method) if missing, so each country's earnings stay in its own currency.
    wallet = await ensureCountryWallet(program.owner_user_id, resolvedCountryId, currency, 'subscription')
  }
  if (!wallet) return null
  const resolvedCountryId = wallet.country_id

  const holdDays = Number(wallet.hold_days) || 7
  const now = new Date()
  const availableOn = new Date(now)
  availableOn.setDate(availableOn.getDate() + holdDays)
  const available_on = availableOn.toISOString().slice(0, 10)

  const chargeDescription = isFirstCharge
    ? `First charge after ${program.free_trial_days || 14}-day trial`
    : 'Monthly subscription charge'

  // 1. Bookboll commission row — paid immediately. Reduced by any loyalty redemption
  //    (absorbed by Bookboll's fee, so the provider's earning below is untouched).
  await supabase.from('booking_payments').insert({
    booking_id: null,
    subscription_id: subscription.id,
    source_type: 'subscription',
    payment_type: 'subscription_commission',
    method: 'online',
    amount: netPlatformFee,
    currency,
    recipient: '@bookboll',
    status: 'paid',
    paid_at: now.toISOString(),
    notes: loyalty > 0 ? `${chargeDescription} (− ${currency} ${loyalty} loyalty)` : chargeDescription,
  })

  // 2. Provider earnings row — paid immediately (hold lifecycle in wallet_transactions)
  await supabase.from('booking_payments').insert({
    booking_id: null,
    subscription_id: subscription.id,
    source_type: 'subscription',
    payment_type: 'subscription_earning',
    method: 'online',
    amount: providerShare,
    currency,
    recipient: providerRecipient,
    status: 'paid',
    paid_at: now.toISOString(),
    notes: chargeDescription,
  })

  const automatic = isAutomaticPayout(wallet)   // Stripe split = auto-paid; else manual withdrawal
  const sourceLabel = `Subscription · ${subscription.customer_name || subscription.customer_email || 'customer'}`

  // 3. Wallet transaction credit. Automatic = settled now (no hold); manual = on hold.
  const { data: walletTx } = await supabase.from('wallet_transactions').insert({
    owner_user_id: program.owner_user_id,
    type: 'subscription_online',
    direction: 'credit',
    amount: +(gross - loyalty).toFixed(2),   // cash actually collected (net of loyalty, absorbed by Bookboll's fee)
    provider_share: providerShare,           // unchanged — the provider is paid in full
    platform_fee: netPlatformFee,
    source_id: subscription.id,
    source_label: sourceLabel,
    description: automatic ? `${chargeDescription} — auto-paid via Stripe` : chargeDescription,
    status: automatic ? 'completed' : 'on_hold',
    currency,
    country_id: resolvedCountryId,
    wallet_id: wallet.id,
    available_on: automatic ? null : available_on,
    hold_days_snapshot: automatic ? 0 : holdDays,
  }).select().maybeSingle()

  // 4. Settle (automatic → paid payout per earning; manual → accrue to pending for withdrawal).
  if (automatic) {
    await recordAutoPayout({ ownerUserId: program.owner_user_id, wallet, amount: providerShare, currency, sourceLabel })
    await applyWalletDelta(wallet.id, { earned: providerShare, withdrawn: providerShare })
  } else {
    await applyWalletDelta(wallet.id, { pending: providerShare, earned: providerShare })
  }

  return { wallet, walletTx, available_on, holdDays, automatic, loyaltyApplied: loyalty }
}
