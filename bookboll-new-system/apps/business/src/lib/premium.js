/**
 * premium.js — Bookboll for Business PREMIUM plan: the published pricing and
 * feature list rendered on business.bookboll.com/pricing.
 *
 * Entitlement (who IS premium, and the upgrade flow) is enforced in the admin
 * dashboard and on the server — the public pricing page only shows the offer.
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

// Pricing (USD, simulated). Monthly is the headline; yearly is billed once a year at
// ~17% off, shown as a lower per-month rate. Both start with a free trial.
export const PREMIUM_PRICE = 59              // monthly plan — USD / month
export const PREMIUM_YEARLY_PER_MONTH = 49   // yearly plan shown as $/month (billed annually)
export const PREMIUM_YEARLY_TOTAL = 588      // yearly plan billed once (49 × 12 ≈ 17% off 59 × 12)
export const PREMIUM_YEARLY_SAVE_PCT = 17
export const PREMIUM_TRIAL_DAYS = { monthly: 7, yearly: 14 }
// The upfront amount actually billed for a plan (after the trial).
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