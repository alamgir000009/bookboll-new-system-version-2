/**
 * overview.js — the dashboard landing data (Home + SetupCoach).
 *
 * One call returns the onboarding checklist and the caller's venues with their
 * hours, listings and programmes, already scoped server-side: a provider gets
 * their own business, a platform admin gets their jurisdiction.
 */
import { ref } from 'vue'
import { apiFetch } from './api.js'

/**
 * Bumped whenever something that moves the setup checklist is saved — a venue,
 * a listing, a programme.
 *
 * The SetupCoach used to re-check only when the ROUTE PATH changed, but a venue
 * is added in a modal on the page you are already on. Nothing navigated, so the
 * coach never re-checked and kept telling the provider to add the venue they had
 * just added. Anything that creates one of those calls notifySetupChanged().
 */
export const setupChangedAt = ref(0)
export function notifySetupChanged() { setupChangedAt.value = Date.now() }

const EMPTY_SETUP = {
  is_provider: false,
  has_location: false,
  has_listing: false,
  has_program: false,
  wallet_ready: false,
  site_slug: null,
  business_name: null,
}

/** @returns {Promise<{ setup: object, locations: Array }>} */
export async function loadOverview() {
  const res = await apiFetch('/admin/overview')
  if (!res.success) return { setup: { ...EMPTY_SETUP }, locations: [] }
  return {
    setup: { ...EMPTY_SETUP, ...(res.data?.setup || {}) },
    locations: Array.isArray(res.data?.locations) ? res.data.locations : [],
  }
}
