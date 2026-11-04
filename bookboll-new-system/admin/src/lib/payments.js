/**
 * Payments — Laravel admin API for the Payments page listing.
 * Backend: GET /api/admin/payments/dashboard (Laravel paginator)
 */
import { apiFetch } from './api.js'

export async function fetchPaymentsDashboard(query = {}) {
  return apiFetch('/admin/payments/dashboard', { query })
}

