/**
 * Customers — Laravel admin API for the enhanced apps/admin CRM page.
 * Payload shape matches the existing Customers UI (full_name, tags, per-country loyalty).
 */
import { apiFetch } from './api.js'
import { customerTypeFilterConfirmed } from './customerTypes.js'

export async function fetchCustomersDashboard(query = {}) {
  const params = {}
  for (const [key, value] of Object.entries(query || {})) {
    if (value === undefined || value === null || value === '') continue
    if (Array.isArray(value) && !value.length) continue
    params[key] = value
  }
  const response = await apiFetch('/admin/customers/dashboard', { query: params })
  if (response.success && !customerTypeFilterConfirmed(response.data, params)) return {success:false,message:'Customer-type filtering needs the updated backend. Clear this filter to view the directory.',data:null}
  return response
}

export async function fetchCustomerCrm(id) {
  if (!id) return { success: false, message: 'Missing customer', data: null }
  return apiFetch(`/admin/customers/${id}/crm`)
}

export async function createCustomer(body) {
  return apiFetch('/admin/customers', { method: 'POST', body })
}

export async function updateCustomer(id, body) {
  return apiFetch(`/admin/customers/${id}`, { method: 'PUT', body })
}

export async function deleteCustomer(id) {
  return apiFetch(`/admin/customers/${id}`, { method: 'DELETE' })
}

export async function replaceCustomerLoyalty(id, loyalty) {
  return apiFetch(`/admin/customers/${id}/loyalty`, {
    method: 'PUT',
    body: { loyalty: loyalty || [] },
  })
}

export async function upsertCustomerLoyalty(id, row) {
  return apiFetch(`/admin/customers/${id}/loyalty`, {
    method: 'POST',
    body: row,
  })
}

export async function deleteCustomerLoyalty(id, countryId) {
  return apiFetch(`/admin/customers/${id}/loyalty/${countryId}`, { method: 'DELETE' })
}

export function firstError(res, fallback = 'Could not save customer.') {
  if (!res) return fallback
  const errors = res.errors || {}
  const first = Object.values(errors).flat().find(Boolean)
  if (first) return String(first)
  if (/already been taken|duplicate|unique/i.test(res.message || '')) return 'A customer with this email already exists.'
  return res.message || fallback
}
