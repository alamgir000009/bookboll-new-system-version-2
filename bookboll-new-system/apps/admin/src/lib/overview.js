/**
 * overview.js — the dashboard landing data (Home + SetupCoach).
 *
 * One call returns the onboarding checklist and the caller's venues with their
 * hours, listings and programmes, already scoped server-side: a provider gets
 * their own business, a platform admin gets their jurisdiction.
 */
import { apiFetch } from './api.js'

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
