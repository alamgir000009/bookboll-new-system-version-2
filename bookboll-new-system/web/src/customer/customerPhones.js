/**
 * customerPhones.js — remembers the shopper's phone number PER COUNTRY.
 *
 * Whenever a phone is given while booking / hosting / joining (or on the account page)
 * it's saved against (customer, country). Next time the shopper is in THAT country the
 * field is prefilled; in a different country it is NOT suggested (a Qatar number won't
 * be offered for a UAE booking).
 *
 * Storage is localStorage, keyed by the customer id — consistent with the storefront's
 * existing localStorage-backed identity (there is no server session). As a convenience
 * the most-recent number is also written to `customers.phone` so the admin/host side
 * sees a reachable number. (Upgrading to a per-country DB table is a drop-in later.)
 */
import { customer } from '../lib/customerSession.js'
import { apiFetch } from '../lib/api.js'

const KEY = 'bb_customer_phones'

function readAll() { try { return JSON.parse(localStorage.getItem(KEY) || '{}') } catch { return {} } }
function writeAll(m) { try { localStorage.setItem(KEY, JSON.stringify(m)) } catch { /* ignore */ } }
function who() { return customer.value?.id || 'guest' }

// The remembered phone for this shopper in a given country ('' if none yet).
export function getSavedPhone(countryId) {
  if (!countryId) return ''
  return readAll()[who()]?.[countryId] || ''
}

/**
 * What the phone field should start with.
 *
 * The number remembered for THIS country wins — a Qatar number should not be
 * offered for a UAE booking. Failing that, a signed-in shopper's account number
 * is used: they gave it at sign-up, and making them retype it on every first
 * booking (or on any new device, where the per-country memory is empty) is the
 * bug this fixes. Guests still start blank.
 */
export function getPrefillPhone(countryId) {
  return getSavedPhone(countryId) || (customer.value?.phone || '').trim()
}

// Remember a phone for (this shopper, country). No-op for blank values.
export function saveCustomerPhone(countryId, phone) {
  const p = (phone || '').trim()
  if (!countryId || !p) return
  const all = readAll()
  const id = who()
  all[id] = all[id] || {}
  all[id][countryId] = p
  writeAll(all)

  // Bonus: keep the account's latest number current (local identity + DB, best-effort).
  if (customer.value?.id) {
    customer.value = { ...customer.value, phone: p }
    try { localStorage.setItem('bb_customer_identity', JSON.stringify(customer.value)) } catch { /* ignore */ }
    // Fire-and-forget so a failed write never blocks checkout. Needs a signed-in
    // Sanctum token; guests just keep the localStorage copy.
    apiFetch('/customer/update-profile', { method: 'POST', body: { phone: p } }).catch(() => {})
  }
}

// When a guest signs in mid-checkout their 'guest' numbers should follow them.
export function migrateGuestPhones(newCustomerId) {
  if (!newCustomerId) return
  const all = readAll()
  if (!all.guest) return
  all[newCustomerId] = { ...all.guest, ...(all[newCustomerId] || {}) }
  delete all.guest
  writeAll(all)
}
