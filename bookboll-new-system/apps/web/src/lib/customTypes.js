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
import { supabase } from './supabase.js'

// Approved custom types, loaded once and cached: [{ domain, name, category }].
export const approvedTypes = ref([])
let loadedOnce = false

export async function loadApprovedTypes(force = false) {
  if (loadedOnce && !force) return approvedTypes.value
  try {
    const { data, error } = await supabase.from('custom_types')
      .select('domain, name, category').eq('status', 'approved')
    if (error) throw error
    approvedTypes.value = data || []
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
export async function addPendingType({ domain, name, category, createdBy }) {
  const clean = String(name || '').trim()
  if (!clean || !category || !domain) return clean
  try {
    const { data: existing } = await supabase.from('custom_types').select('id')
      .eq('domain', domain).eq('category', category).eq('status', 'pending').ilike('name', clean).limit(1)
    if (!existing || !existing.length) {
      await supabase.from('custom_types').insert({ domain, name: clean, category, status: 'pending', created_by: createdBy || null })
    }
  } catch { /* best-effort: the listing still carries the type */ }
  return clean
}

/**
 * Promote a matching pending type to the shared catalog — call when a listing/program is
 * approved. Keyed by domain + category + name, so the listing's own type value drives it.
 * Best-effort; refreshes the cache on success.
 */
export async function promoteTypesFor({ domain, name, category }) {
  const clean = String(name || '').trim()
  if (!clean || !category || !domain) return
  try {
    const { error } = await supabase.from('custom_types')
      .update({ status: 'approved', approved_at: new Date().toISOString() })
      .eq('domain', domain).eq('category', category).eq('status', 'pending').ilike('name', clean)
    if (!error) await loadApprovedTypes(true)
  } catch { /* ignore */ }
}
