/**
 * Bookings — Laravel admin API for the enhanced apps/admin Bookings page.
 * Payload shape matches the existing Bookings UI (location_id, booking_date, origin).
 */
import { apiFetch } from './api.js'

export async function fetchBookingsDashboard() {
  return apiFetch('/admin/bookings/dashboard')
}

export async function fetchBookingCrm(id) {
  if (!id) return { success: false, message: 'Missing booking', data: null }
  return apiFetch(`/admin/bookings/${id}/crm`)
}

export async function fetchBookingOccupancy(listingId, date) {
  return apiFetch('/admin/bookings/occupancy', {
    query: { listing_id: listingId, date },
  })
}

export async function createDashboardBooking(body) {
  return apiFetch('/admin/bookings/dashboard', { method: 'POST', body })
}

export async function updateDashboardBooking(id, body) {
  return apiFetch(`/admin/bookings/${id}/dashboard`, { method: 'PUT', body })
}

export async function cancelDashboardBooking(body) {
  return apiFetch('/admin/bookings/dashboard-cancel', { method: 'POST', body })
}

export async function collectDashboardPayment(bookingId, paymentId, body) {
  return apiFetch(`/admin/bookings/${bookingId}/payments/${paymentId}/collect`, {
    method: 'PUT',
    body,
  })
}

export async function generateDashboardPaymentLink(body) {
  return apiFetch('/admin/bookings/dashboard-payment-link', { method: 'POST', body })
}

export async function registerCustomer(body) {
  return apiFetch('/admin/customers/register', { method: 'POST', body })
}

export function firstError(res, fallback = 'Could not save booking.') {
  if (!res) return fallback
  const errors = res.errors || {}
  const first = Object.values(errors).flat().find(Boolean)
  if (first) return String(first)
  return res.message || fallback
}
