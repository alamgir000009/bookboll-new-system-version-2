/**
 * businessTypes.js — what kind of business a provider runs.
 *
 * Picked (multi-select) during signup, stored as a jsonb array of keys on
 * admin_users.business_types, and shown/filterable on the super-admin Team roster.
 */

export const BUSINESS_TYPES = [
  { key: 'sports_facility', label: 'Sports facility', hint: 'Padel, football, tennis, courts & pitches' },
  { key: 'gym',             label: 'Gym / fitness',    hint: 'Gyms & fitness studios' },
  { key: 'academy',         label: 'Academy',          hint: 'Sports academies & training' },
  { key: 'classes',         label: 'Classes',          hint: 'Yoga, martial arts, dance…' },
  { key: 'other_venue',     label: 'Other venue',      hint: 'Hangouts, entertainment & work spaces' },
]

const LABEL = Object.fromEntries(BUSINESS_TYPES.map(t => [t.key, t.label]))

/** Human label for one type key ('' for unknown keys). */
export function businessTypeLabel(key) { return LABEL[key] || '' }

/** Coerce a stored value (jsonb array / JSON string / null) into a clean array of known keys. */
export function normalizeBusinessTypes(raw) {
  let v = raw
  if (typeof v === 'string' && v.trim()) { try { v = JSON.parse(v) } catch { return [] } }
  if (!Array.isArray(v)) return []
  return v.filter(k => LABEL[k])
}

/** Short display string, e.g. "Sports facility · Gym / fitness" ('' when none saved). */
export function businessTypesText(raw) {
  return normalizeBusinessTypes(raw).map(businessTypeLabel).join(' · ')
}
