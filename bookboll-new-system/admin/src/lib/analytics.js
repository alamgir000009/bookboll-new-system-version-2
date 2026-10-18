/**
 * analytics.js — the dataset behind the Analytics dashboard (/dashboard).
 *
 * One call returns the raw collections the page charts from, already scoped:
 * a provider gets their own business, a platform admin gets everything their
 * jurisdiction covers. The KPI and chart maths stay in the page.
 */
import { apiFetch } from './api.js'

const EMPTY = {
  bookings: [], customers: [], providers: [], listings: [], locations: [],
  programs: [], wallets: [], withdrawals: [], cities: [], countries: [],
  subscriptions: [], subPayments: [], games: [], gameJoins: [],
}

export async function loadAnalytics() {
  const res = await apiFetch('/admin/dashboard/analytics')
  if (!res.success) return { ...EMPTY, error: res.message || 'Analytics could not be loaded.' }
  const d = res.data || {}
  return {
    bookings: d.bookings || [],
    customers: d.customers || [],
    providers: d.providers || [],
    listings: d.listings || [],
    locations: d.locations || [],
    programs: d.programs || [],
    wallets: d.wallets || [],
    withdrawals: d.withdrawals || [],
    cities: d.cities || [],
    countries: d.countries || [],
    subscriptions: d.subscriptions || [],
    // The API already filters these to paid rows.
    subPayments: d.sub_payments || [],
    games: d.games || [],
    gameJoins: d.game_joins || [],
  }
}
