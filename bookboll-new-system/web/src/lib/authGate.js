/**
 * authGate.js — a mandatory "sign in / create account" gate for the customer storefront.
 *
 * Call `await ensureSignedIn()` at the start of any value action (book, host, join,
 * subscribe). It resolves immediately when the customer is already signed in; otherwise
 * it opens the global <AuthGate> modal (mounted once in CustomerLayout) and resolves
 * true after a successful sign-in/create, or false if the customer cancels.
 */
import { ref } from 'vue'
import { isCustomer } from './customerSession.js'

export const authGateOpen = ref(false)
let _resolve = null

export function ensureSignedIn() {
  if (isCustomer.value) return Promise.resolve(true)
  authGateOpen.value = true
  return new Promise((resolve) => { _resolve = resolve })
}

// Called by <AuthGate> when the customer signs in (ok=true) or cancels (ok=false).
export function resolveAuthGate(ok) {
  authGateOpen.value = false
  const r = _resolve
  _resolve = null
  if (r) r(!!ok)
}
