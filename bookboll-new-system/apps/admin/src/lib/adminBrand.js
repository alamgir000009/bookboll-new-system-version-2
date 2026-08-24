/**
 * adminBrand.js — branding for the ADMIN DASHBOARD chrome itself (the internal
 * back-office), separate from the customer-facing marketplace brand (lib/brand.js).
 *
 * Persisted in Laravel `settings` (`admin_logo_url`, `admin_primary`) via
 * /api/admin/settings. Dual-writes to Supabase platform_settings so storefront
 * helpers that still read Supabase stay in sync.
 *   - logo    → sidebar, collapsed rail and auth screens
 *   - primary → dashboard buttons, active nav and the logo square
 *
 * Colours are applied SCOPED to the admin shell (an inline CSS-var style on the
 * `.app` element in App.vue) so they never leak into the customer storefront.
 */
import { ref, computed } from 'vue'
import { supabase } from './supabase.js'
import { fetchSettings, updateSettings } from './settings.js'

export const DEFAULT_ADMIN_PRIMARY = '#0f766e'   // Bookboll teal (matches --primary)

// Shared state — loaded once in App.vue, read by App / AccountPicker / Login / Signup.
export const adminBrand = ref({ logo: '', primary: '' })

let loaded = false

async function dualWriteAdminBrand(logo, primary) {
  try {
    const now = new Date().toISOString()
    await supabase.from('platform_settings').upsert([
      { key: 'admin_logo_url', value: logo || '', updated_at: now },
      { key: 'admin_primary', value: primary || '', updated_at: now },
    ], { onConflict: 'key' })
  } catch {
    // Best-effort — Laravel is source of truth for admin chrome.
  }
}

export async function loadAdminBrand(force = false) {
  if (loaded && !force) return adminBrand.value
  try {
    const res = await fetchSettings()
    if (res?.success && res.data?.settings) {
      const m = res.data.settings
      adminBrand.value = {
        logo: m.admin_logo_url || '',
        primary: m.admin_primary || '',
      }
    }
  } catch {
    // Offline / unauthenticated: keep the built-in defaults.
  }
  loaded = true
  return adminBrand.value
}

/** The chosen dashboard colour, or the Bookboll default when unset. */
export const adminPrimary = computed(() => adminBrand.value.primary || DEFAULT_ADMIN_PRIMARY)

/**
 * CSS custom properties to bind on the admin shell (`.app`).
 *
 * ALWAYS emits a full set (defaulting to Bookboll teal): the marketplace brand
 * (lib/brand.js applyBrandTheme) writes --brand/--brand-accent as inline styles
 * on <html> at boot, so if the admin shell emitted nothing it would INHERIT the
 * marketplace colours — the dashboard must stay on its own colour (default teal,
 * or the super-admin's dashboard pick), never ride the storefront theme.
 * Derived shades use color-mix so we don't need a JS colour library.
 */
export const adminBrandVars = computed(() => {
  const c = adminBrand.value.primary || DEFAULT_ADMIN_PRIMARY
  return {
    '--primary':        c,
    '--primary-dark':   `color-mix(in srgb, ${c}, #000 16%)`,
    '--primary-light':  `color-mix(in srgb, ${c}, #fff 22%)`,
    '--brand':          c,
    '--brand-accent':   `color-mix(in srgb, ${c}, #fff 18%)`,
    '--sidebar-active': `color-mix(in srgb, ${c}, #fff 18%)`,
  }
})

/** Inline style for a logo SQUARE (sidebar/auth) so its gradient follows the
 *  dashboard colour — always concrete for the same isolation reason as above. */
export const adminLogoSquareStyle = computed(() => {
  const c = adminBrand.value.primary || DEFAULT_ADMIN_PRIMARY
  return { background: `linear-gradient(135deg, color-mix(in srgb, ${c}, #fff 18%), ${c})` }
})

/** Persist the dashboard branding (best-effort) and apply it live. Used by the
 *  My Website "Admin dashboard" card so changes take effect the moment you make
 *  them — no separate Save required. */
export async function saveAdminBrand({ logo = '', primary = '' } = {}) {
  const rows = [
    { key: 'admin_logo_url', value: logo || '' },
    { key: 'admin_primary', value: primary || '' },
  ]
  try {
    const res = await updateSettings(rows)
    if (!res?.success) return false
    adminBrand.value = { logo: logo || '', primary: primary || '' }
    await dualWriteAdminBrand(logo, primary)
    return true
  } catch {
    return false
  }
}
