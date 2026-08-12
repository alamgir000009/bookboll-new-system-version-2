/**
 * premium.js — Bookboll for Business PREMIUM plan (free vs premium providers).
 *
 * The plan lives on the business OWNER's admin_users row (`plan` = 'free'|'premium'),
 * so every team member of a premium business inherits it. Platform roles (super admin,
 * country admin, platform staff) are NEVER gated.
 *
 * Usage in a page:
 *   const { isPremium, requirePremium } = usePremium()
 *   <button @click="requirePremium('exports') && doExport()">
 * requirePremium() returns true when allowed; otherwise it opens the global Upgrade
 * modal (components/UpgradeModal.vue, mounted once in App.vue) and returns false.
 *
 * The subscription itself is SIMULATED (like the custom-domain fee): subscribing just
 * writes plan='premium' + premium_since. Pre-migration the column is missing → everyone
 * reads as FREE and upgrading shows a run-the-migration toast.
 */
import { ref, computed } from 'vue'
import { supabase } from './supabase.js'
import { useCurrentUser } from './currentUser.js'
import { pushToast } from './toast.js'
import { recordRedemption } from './promocodes.js'

// Pricing (USD, simulated). Monthly is the headline; yearly is billed once a year at
// ~17% off, shown as a lower per-month rate. Both start with a free trial.
export const PREMIUM_PRICE = 59              // monthly plan — USD / month
export const PREMIUM_YEARLY_PER_MONTH = 49   // yearly plan shown as $/month (billed annually)
export const PREMIUM_YEARLY_TOTAL = 588      // yearly plan billed once (49 × 12 ≈ 17% off 59 × 12)
export const PREMIUM_YEARLY_SAVE_PCT = 17
export const PREMIUM_TRIAL_DAYS = { monthly: 7, yearly: 14 }
// The upfront amount actually billed for a plan (after the trial).
export function billedAmount(billing) { return billing === 'yearly' ? PREMIUM_YEARLY_TOTAL : PREMIUM_PRICE }

// Canonical premium feature list (order = upsell display order).
export const PREMIUM_FEATURES = [
  { key: 'blog',       label: 'Website blog',       desc: 'Publish posts on your own site — great for Google.' },
  { key: 'marketing',  label: 'Marketing emails',   desc: 'Email campaigns to your opted-in customers.' },
  { key: 'exports',    label: 'Data exports',       desc: 'Download bookings, customers, payments & more as CSV.' },
  { key: 'promocodes', label: 'Promo codes',        desc: 'Discount codes with targeting & usage limits.' },
  { key: 'banners',    label: 'Custom home banners', desc: 'Your own hero slides with buttons & images.' },
  { key: 'widgets',    label: 'Embed & widgets',    desc: 'Booking widgets and buttons for your existing website.' },
  { key: 'whitelabel', label: 'White-label website', desc: 'Remove the Bookboll badge from your site — only a small footer credit remains.' },
  { key: 'own_site_only', label: 'Off-marketplace listings', desc: 'Hide a listing or program from the Bookboll Marketplace and sell it only on your own website.' },
]

// Global upgrade-modal state (rendered once by components/UpgradeModal.vue).
export const upgradeModal = ref({ open: false, feature: '' })
export function openUpgrade(featureKey = '') {
  upgradeModal.value = { open: true, feature: featureKey }
}

export function usePremium() {
  const { currentUser, allUsers, currentOwnerUserId, isSuperAdmin, isCountryAdmin, role } = useCurrentUser()

  // The business OWNER's row (the plan lives there; staff inherit it).
  const ownerRow = computed(() => {
    const oid = currentOwnerUserId.value
    if (!oid) return null
    if (currentUser.value?.id === oid) return currentUser.value
    return (allUsers.value || []).find(u => u.id === oid) || null
  })

  const isPlatform = computed(() =>
    isSuperAdmin.value || isCountryAdmin.value || role.value === 'platform_staff')

  const isPremium = computed(() =>
    isPlatform.value || ownerRow.value?.plan === 'premium')

  /** True when the feature may be used; otherwise opens the Upgrade modal and returns false. */
  function requirePremium(featureKey = '') {
    if (isPremium.value) return true
    openUpgrade(featureKey)
    return false
  }

  const upgrading = ref(false)
  /**
   * SIMULATED subscribe: flips the owner row to premium.
   * `promo` (optional) is a validated provider-plan code — `{ code, discount }` from
   * validatePromo({ applicableTo: 'provider_plan' }) — which is recorded as a redemption.
   */
  async function subscribePremium(promo = null, billing = 'yearly') {
    const oid = currentOwnerUserId.value
    if (!oid || upgrading.value) return false
    upgrading.value = true
    const { error } = await supabase.from('admin_users')
      .update({ plan: 'premium', premium_since: new Date().toISOString() }).eq('id', oid)
    upgrading.value = false
    if (error) {
      pushToast(/could not find|does not exist|schema cache/i.test(error.message || '')
        ? 'Run the provider_premium migration (20260725000000_provider_premium.sql) first.'
        : (error.message || 'Could not upgrade.'), 'error')
      return false
    }
    // Update the in-memory rows so gates lift immediately (no reload needed).
    const row = (allUsers.value || []).find(u => u.id === oid)
    if (row) { row.plan = 'premium'; row.premium_since = new Date().toISOString() }
    if (currentUser.value?.id === oid) { currentUser.value.plan = 'premium' }
    // Record the promo redemption (best-effort — never blocks the upgrade).
    const billed = billedAmount(billing)
    if (promo?.code?.id) {
      const owner = ownerRow.value
      await recordRedemption({
        code: promo.code,
        customer: { email: owner?.email || '', name: owner?.business_name || owner?.full_name || '' },
        source: { type: 'provider_plan', id: oid, countryId: owner?.country_id || null },
        amount: billed,
        discount: promo.discount || 0,
        currency: 'USD',
      })
    }
    const trial = PREMIUM_TRIAL_DAYS[billing] || PREMIUM_TRIAL_DAYS.monthly
    const per = billing === 'yearly' ? `$${PREMIUM_YEARLY_TOTAL}/year` : `$${PREMIUM_PRICE}/month`
    pushToast(`Premium started — your ${trial}-day free trial is on. ${per} after the trial (simulated).`, 'success')
    upgradeModal.value = { open: false, feature: '' }
    return true
  }

  return { isPremium, isPlatform, requirePremium, subscribePremium, upgrading, ownerRow }
}
