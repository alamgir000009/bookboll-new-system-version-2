import { ref, computed } from 'vue'
import { supabase } from './supabase.js'
import { can as roleCan } from './permissions.js'

const STORAGE_KEY = 'bb_active_user'

export const activeUserId = ref(localStorage.getItem(STORAGE_KEY) || '')
export const currentUser = ref(null)
export const allUsers = ref([])
// The REAL signed-in account's email (set by lib/auth.js from the Supabase session).
// Impersonation is anchored to this — the active identity can never out-rank it.
export const realAuthEmail = ref('')

// May the real signed-in account act as `row`?
//   • your own identities (email match)      → always
//   • real super admin                        → anyone (the HQ act-as feature)
//   • real country admin / platform staff     → anyone EXCEPT a super admin
//   • real provider or below                  → own identities only
// This single rule backs the topbar switcher list, setActiveUser, and the boot
// fallback — so a provider session can never escalate to (or default into) super admin.
export function canActAs(row) {
  if (!row) return false
  const email = realAuthEmail.value
  if (!email) return false
  const own = allUsers.value.filter(u => (u.email || '').toLowerCase() === email)
  if ((row.email || '').toLowerCase() === email) return true
  if (own.some(u => u.role === 'super_admin')) return true
  if (own.some(u => u.role === 'country_admin' || u.role === 'platform_staff')) return row.role !== 'super_admin'
  return false
}
// The identities offered in the topbar switcher (only what the real account may use).
export const switchableUsers = computed(() => allUsers.value.filter(u => canActAs(u)))

export async function loadUserContext() {
  const { data } = await supabase.from('admin_users').select('*').order('role')
  allUsers.value = data || []

  let cur = activeUserId.value ? (allUsers.value.find(x => x.id === activeUserId.value) || null) : null
  // A stored selection the real account isn't allowed to use (stale, or an attempted
  // escalation via localStorage) is discarded instead of honoured.
  if (cur && !canActAs(cur)) cur = null
  if (!cur) {
    // Default to the signed-in account's OWN identity — never to the super-admin row.
    const email = realAuthEmail.value
    const own = email ? allUsers.value.filter(u => (u.email || '').toLowerCase() === email) : []
    cur = own.find(u => u.role === 'super_admin') || own[0] || null
  }
  currentUser.value = cur
  if (cur) {
    activeUserId.value = cur.id
    localStorage.setItem(STORAGE_KEY, cur.id)
  } else {
    activeUserId.value = ''
    localStorage.removeItem(STORAGE_KEY)
  }
}

export function setActiveUser(id) {
  const row = allUsers.value.find(x => x.id === id) || null
  if (!canActAs(row)) return   // silently refuse identities above the real account's rank
  activeUserId.value = id
  currentUser.value = row
  localStorage.setItem(STORAGE_KEY, id || '')
}

export const role = computed(() => currentUser.value?.role || 'super_admin')
export const isSuperAdmin = computed(() => role.value === 'super_admin')
export const isCountryAdmin = computed(() => role.value === 'country_admin')
export const isProviderScoped = computed(() =>
  // provider_supervisor / provider_viewer belong here too — leaving them out made
  // isProviderScoped-gated pages (Calendar, Check-in, Payments) treat them as
  // platform users and show them EVERY business's data.
  ['provider_admin', 'facility_admin', 'provider_supervisor', 'provider_viewer', 'senior_employee', 'junior_employee', 'guard', 'staff'].includes(role.value)
)
export const currentOwnerUserId = computed(() => {
  const u = currentUser.value
  if (!u) return null
  if (u.role === 'provider_admin') return u.id
  return u.owner_user_id || null
})
export const currentCountryId = computed(() => currentUser.value?.country_id || null)
export const allBusinessOwners = computed(() =>
  allUsers.value.filter(u => u.role === 'provider_admin')
)

// ── Per-user scope (location / listing / program restriction) ─────────────────
// Owners (provider_admin) and platform admins (super/country) see their whole
// business / the whole platform. Managers (facility_admin) and Staff can be
// pinned to specific locations / listings / programs via these arrays; empty =
// no restriction (all within their business). Columns added by the roles
// migration; absent → treated as empty.
export const assignedCountryIds  = computed(() => currentUser.value?.assigned_country_ids || [])
export const assignedLocationIds = computed(() => currentUser.value?.assigned_location_ids || [])
export const assignedListingIds  = computed(() => currentUser.value?.assigned_listing_ids || [])
export const assignedProgramIds  = computed(() => currentUser.value?.assigned_program_ids || [])
export const hasScopeRestriction = computed(() =>
  (assignedLocationIds.value.length + assignedListingIds.value.length + assignedProgramIds.value.length) > 0)

// Only Managers/Staff (not Owners, not platform admins) are narrowed by assignment.
function scopeNarrows() {
  return !isSuperAdmin.value && !isCountryAdmin.value && role.value !== 'provider_admin'
}
/** Narrow a list of locations (objects with .id) to the user's assigned scope. */
export function scopeLocations(locs) {
  if (!scopeNarrows()) return locs
  const ids = assignedLocationIds.value
  if (!ids.length) return locs
  const set = new Set(ids)
  return locs.filter(l => set.has(l.id))
}
/** Narrow listings (objects with .id) to assigned listings, if any are set. */
export function scopeListings(items) {
  if (!scopeNarrows()) return items
  const ids = assignedListingIds.value
  if (!ids.length) return items
  const set = new Set(ids)
  return items.filter(i => set.has(i.id))
}
/** Narrow programs (objects with .id) to assigned programs, if any are set. */
export function scopePrograms(items) {
  if (!scopeNarrows()) return items
  const ids = assignedProgramIds.value
  if (!ids.length) return items
  const set = new Set(ids)
  return items.filter(i => set.has(i.id))
}

// Per-user permission overrides ({ grant:[], revoke:[] }) stored on the user row.
// Absent until the roles migration adds the column → treated as "no overrides".
export const currentOverrides = computed(() => currentUser.value?.custom_permissions || null)

// Reactive capability check for the active user. Use in templates like
// `v-if="can(CAP.MANAGE_BOOKINGS)"` — re-evaluates when the active role or the
// user's per-user overrides change.
export function can(cap) {
  return roleCan(cap, role.value, currentOverrides.value)
}
export const currentUsername = computed(() => {
  const u = currentUser.value
  if (!u) return null
  if (u.role === 'provider_admin') return u.username || null
  if (u.owner_user_id) {
    const owner = allUsers.value.find(x => x.id === u.owner_user_id)
    return owner?.username || null
  }
  return u.username || null
})

export function useCurrentUser() {
  return {
    currentUser,
    activeUserId,
    allUsers,
    switchableUsers,
    allBusinessOwners,
    role,
    isSuperAdmin,
    isCountryAdmin,
    isProviderScoped,
    currentOwnerUserId,
    currentCountryId,
    currentUsername,
    can,
    currentOverrides,
    assignedCountryIds,
    assignedLocationIds,
    assignedListingIds,
    assignedProgramIds,
    hasScopeRestriction,
    scopeLocations,
    scopeListings,
    scopePrograms,
    setActiveUser,
    loadUserContext,
  }
}
