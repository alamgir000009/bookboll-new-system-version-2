/**
 * customTypes.js — provider-suggested listing/program types ("Add new type").
 *
 * Built-in types live in venueCategories.js (venue) and membershipCategories.js (program).
 * When a provider needs a type that isn't built in, they add it inline on the listing/program
 * form (name + an existing category). It's stored on their listing/program right away and a
 * `pending` row is recorded in `custom_types`. When that listing/program is APPROVED, the type
 * is promoted to `approved` and joins the shared catalog for everyone (merged into the combobox).
 *
 * All DB calls are best-effort: before the migration is applied (or on any error) these no-op,
 * so the form still works — the listing just carries the typed value and nothing enters the
 * catalog until the table exists and an item is approved.
 */
import { ref } from 'vue'
import { apiFetch } from './api.js'

// Approved custom types, loaded once and cached: [{ domain, name, category }].
export const approvedTypes = ref([])
let loadedOnce = false

export async function loadApprovedTypes(force = false) {
  if (loadedOnce && !force) return approvedTypes.value
  try {
    const res = await apiFetch('/admin/custom-types')
    approvedTypes.value = res.success ? (res.data?.types || []) : []
    loadedOnce = true
  } catch { approvedTypes.value = [] }
  return approvedTypes.value
}

/** Approved custom types for one domain → [{ name, category }]. */
export function approvedTypesFor(domain) {
  return approvedTypes.value.filter(r => r.domain === domain)
}

/**
 * Record a provider-suggested type as `pending` (deduped — reuses an existing pending row for
 * the same domain+category+name). Caller has already confirmed it isn't a built-in/approved
 * type. Returns the cleaned name to store on the listing/program. Best-effort.
 */
export async function addPendingType({ domain, name, category }) {
  const clean = String(name || '').trim()
  if (!clean || !category || !domain) return clean
  try {
    // Deduping and authorship are the server's job — `createdBy` is taken from the
    // session there, so a suggestion can't be filed under someone else's name.
    const res = await apiFetch('/admin/custom-types', { method: 'POST', body: { domain, name: clean, category } })
    if (res.success && res.data?.name) return res.data.name
  } catch { /* best-effort: the listing still carries the type */ }
  return clean
}

/**
 * Refresh the cached catalogue after an approval.
 *
 * Promotion itself is the SERVER's — approving a listing or programme promotes the
 * type it carries (ApprovalsDashboardService), so the browser must not be able to
 * publish a type on its own. All that is left here is picking up the new list.
 */
export async function promoteTypesFor(_ref) {
  try { await loadApprovedTypes(true) } catch { /* ignore */ }
}
