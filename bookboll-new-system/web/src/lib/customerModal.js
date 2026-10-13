/**
 * customerModal.js — global "Customer Details" modal control.
 *
 * Open the shared customer details modal from anywhere (any table/form):
 *   import { openCustomer } from '../lib/customerModal.js'
 *   openCustomer(customerId)
 *
 * A single <CustomerDetailModal /> mounted once in App.vue renders it.
 *
 * `requestEditCustomerId` lets the modal's "Edit" action hand off to the
 * Customers page (which owns the full edit form). Customers.vue watches it,
 * opens the edit form, then clears it.
 */
import { ref } from 'vue'

export const customerModalId       = ref(null)
export const requestEditCustomerId = ref(null)
export const requestBlockCustomerId = ref(null)

export function openCustomer(id) { if (id) customerModalId.value = id }
export function closeCustomer()  { customerModalId.value = null }
export function useCustomerModal() { return openCustomer }
