/**
 * Calendar — Laravel admin API for apps/admin Calendar.vue.
 * Backend: GET /api/admin/bookings/calendar-dashboard
 */
import { apiFetch } from './api.js'

/**
 * @param {{ from?: string, to?: string, date?: string, venue_id?: string|number, listing_id?: string|number }} [query]
 */
export async function fetchCalendarDashboard(query = {}) {
  return apiFetch('/admin/bookings/calendar-dashboard', { query })
}
