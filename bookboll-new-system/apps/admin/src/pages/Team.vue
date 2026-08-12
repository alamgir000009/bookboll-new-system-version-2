<script setup>
import { ref, onMounted, computed, watch } from 'vue'
import { supabase } from '../lib/supabase.js'
import { askConfirm } from '../lib/confirm.js'
import { pushToast } from '../lib/toast.js'
import { markDirty, clearDirty, confirmDiscardIfDirty } from '../lib/unsavedGuard.js'
import { useCurrentUser } from '../lib/currentUser.js'
import { Plus, Search, Trash2, Pencil as Edit2, X, Shield, UserCog, User, MapPin, Building2, AtSign, Users, UsersRound, ChevronDown, Info, Crown, CircleArrowDown } from 'lucide-vue-next'
import PaginationBar from '../components/PaginationBar.vue'
import FilterBar from '../components/FilterBar.vue'
import { idIn } from '../lib/geoFilter.js'
import PermissionScopeEditor from '../components/PermissionScopeEditor.vue'
import RoleSelect from '../components/RoleSelect.vue'
import { usePagination } from '../lib/usePagination.js'
import { ROLE_INFO, roleLabel as roleLabelFn, PLATFORM_ROLES, PROVIDER_ROLES } from '../lib/permissions.js'
import { logAudit, AUDIT } from '../lib/audit.js'
import MultiSelect from '../components/MultiSelect.vue'
import { BUSINESS_TYPES, normalizeBusinessTypes, businessTypeLabel } from '../lib/businessTypes.js'

// Roles a super admin can assign (platform + provider) and a provider can assign
// to their own team (everything below Owner).
const ASSIGNABLE_ADMIN_ROLES = [...PLATFORM_ROLES, ...PROVIDER_ROLES]
const ASSIGNABLE_TEAM_ROLES = PROVIDER_ROLES.filter(r => r !== 'provider_admin')

const { loadUserContext, isSuperAdmin, isCountryAdmin, role: actorRole, currentUser, currentOwnerUserId, currentUsername, assignedCountryIds, assignedLocationIds } = useCurrentUser()

// Platform-side roles (Bookboll) all get the management view; provider roles get
// the "My Team" view. Country Admin / Platform Staff are platform-side.
const isPlatformSide = computed(() => isSuperAdmin.value || isCountryAdmin.value || actorRole.value === 'platform_staff')

// Roles the CURRENT user may assign — you can never grant a role at or above your
// own authority. Super → all; Country Admin → Platform Staff + provider; Platform
// Staff & providers → provider roles only.
const assignableRoles = computed(() => {
  if (isSuperAdmin.value) return [...PLATFORM_ROLES, ...PROVIDER_ROLES]
  if (isCountryAdmin.value) return ['platform_staff', ...PROVIDER_ROLES]
  return [...ASSIGNABLE_TEAM_ROLES]   // platform_staff + provider admins → provider roles only
})

// ── Jurisdiction (which businesses a platform user may manage) ────────────────
// A business's countries are derived from its locations (+ optional anchor on the
// owner row), the same way wallets are auto-created per location country.
function businessCountryIds(ownerId) {
  const set = new Set()
  for (const l of locations.value) {
    if (l.owner_user_id !== ownerId) continue
    const city = cities.value.find(c => c.id === l.city_id)
    if (city?.country_id) set.add(city.country_id)
  }
  const owner = allUsers.value.find(u => u.id === ownerId)
  if (owner?.country_id) set.add(owner.country_id)   // anchor for brand-new businesses
  return set
}
// The current platform user's country jurisdiction (null = global/unrestricted).
const jurisdictionCountryIds = computed(() => {
  if (isSuperAdmin.value) return null
  const ids = assignedCountryIds.value
  if (isCountryAdmin.value) {
    // Pre-migration fallback: a Country Admin with no assigned list uses their country_id.
    if (ids.length) return new Set(ids)
    return currentUser.value?.country_id ? new Set([currentUser.value.country_id]) : new Set()
  }
  if (actorRole.value === 'platform_staff') return ids.length ? new Set(ids) : null  // empty = inherited-global
  return null
})
const jurisdictionLocationSet = computed(() => new Set(assignedLocationIds.value))
// May the current platform user manage the business owned by `ownerId`?
function businessInJurisdiction(ownerId) {
  if (isSuperAdmin.value) return true
  // Platform Staff pinned to specific venues → only businesses that own one of them.
  if (actorRole.value === 'platform_staff' && jurisdictionLocationSet.value.size) {
    return locations.value.some(l => l.owner_user_id === ownerId && jurisdictionLocationSet.value.has(l.id))
  }
  const j = jurisdictionCountryIds.value
  if (!j) return true                 // unrestricted (super, or global platform staff)
  for (const c of businessCountryIds(ownerId)) if (j.has(c)) return true
  return false
}

// ── Shared ────────────────────────────────────────────────────────────────────
const locations       = ref([])
const listings        = ref([])   // for per-user listing-scope pickers
const programs        = ref([])   // for per-user program-scope pickers
const countries       = ref([])
const cities          = ref([])
// Roles that carry per-user permission overrides + location/listing/program scope.
const SCOPED_ROLES = ['facility_admin', 'provider_supervisor', 'staff', 'provider_viewer', 'platform_staff']
const isScopedRole = (r) => SCOPED_ROLES.includes(r)
// { [countryId]: { facility: number, program: number } }
// Loaded from commission_rates table — used to auto-fill new provider accounts.
const commissionRates = ref({})
const showModal = ref(false)
const editing   = ref(null)
const saving    = ref(false)
const search    = ref('')
const filterCountry = ref([])
const filterCity    = ref([])
const filterBizTypes = ref([])   // business-type filter for the provider roster
const bizTypeOptions = BUSINESS_TYPES.map(t => ({ value: t.key, label: t.label }))
const filterPlan = ref('')       // '' | 'premium' | 'free'
const triedSave = ref(false)
// Team members (admin_users) carry country_id / city_id directly.
function memberGeoMatch(u) {
  if (!idIn(u?.city_id, filterCity.value)) return false
  if (!idIn(u?.country_id, filterCountry.value)) return false
  return true
}
async function requestCloseTeamModal() { if (await confirmDiscardIfDirty()) showModal.value = false }

// ── Admin Users (super admin view) ────────────────────────────────────────────
const allUsers   = ref([])
const formError  = ref('')
const adminForm  = ref(defaultAdminForm())

function defaultAdminForm() {
  return {
    full_name: '', email: '', role: 'staff', country_id: '', location_id: '',
    owner_user_id: '', status: 'active', username: '', phone: '', city_id: '',
    commission_rate_facility: null, commission_rate_program: null,
    business_status: 'approved', description: '', license_number: '',
    custom_permissions: {}, assigned_location_ids: [], assigned_listing_ids: [], assigned_program_ids: [],
  }
}

const isProviderScopedRole = (r) => ['facility_admin', 'provider_supervisor', 'staff', 'provider_viewer', 'senior_employee', 'junior_employee', 'guard'].includes(r)
const businessOwners = computed(() => allUsers.value.filter(u => u.role === 'provider_admin' && businessInJurisdiction(u.id)))

const filteredUsers = computed(() => {
  const q = search.value.toLowerCase()
  if (!q) return allUsers.value
  return allUsers.value.filter(u =>
    (u.full_name || '').toLowerCase().includes(q) ||
    (u.email || '').toLowerCase().includes(q) ||
    (u.username || '').toLowerCase().includes(q) ||
    roleLabel(u.role).toLowerCase().includes(q)
  )
})
const { page: auPage, perPage: auPerPage, totalPages: auTotalPages, paginated: paginatedUsers, visiblePages: auVisiblePages, goTo: auGoTo } = usePagination(filteredUsers)

function openAdminNew() {
  editing.value = null
  adminForm.value = defaultAdminForm()
  formError.value = ''
  triedSave.value = false
  clearDirty()
  showModal.value = true
}
function openAdminEdit(u) {
  editing.value = u
  formError.value = ''
  adminForm.value = {
    full_name: u.full_name || '', email: u.email || '', role: u.role || 'staff',
    country_id: u.country_id || '', location_id: u.location_id || '',
    owner_user_id: u.owner_user_id || '', status: u.status || 'active',
    username: u.username || '', phone: u.phone || '', city_id: u.city_id || '',
    commission_rate_facility: u.commission_rate_facility ?? null,
    commission_rate_program:  u.commission_rate_program  ?? null,
    business_status: u.business_status || 'pending',
    description: u.description || '', license_number: u.license_number || '',
    custom_permissions: u.custom_permissions || {},
    assigned_location_ids: [...(u.assigned_location_ids || [])],
    assigned_listing_ids:  [...(u.assigned_listing_ids  || [])],
    assigned_program_ids:  [...(u.assigned_program_ids  || [])],
  }
  triedSave.value = false
  clearDirty()
  showModal.value = true
}
// Insert/update an admin_users row, tolerating columns this DB hasn't migrated yet
// (e.g. commission_rate_* on older schemas): on a "column does not exist" error we
// drop the named column and retry, so saving never hard-fails on optional fields.
async function persistAdminUser(payload) {
  const p = { ...payload }
  const run = () => editing.value
    ? supabase.from('admin_users').update(p).eq('id', editing.value.id)
    : supabase.from('admin_users').insert(p)
  for (let i = 0; i < 6; i++) {
    const { error } = await run()
    if (!error) return null
    const m = error.message.match(/'?([a-z_]+)'? column|column [^.]*\.([a-z_]+) does not exist/i)
    const col = m && (m[1] || m[2])
    if (col && col in p) { delete p[col]; continue }   // drop unknown column, retry
    return error
  }
  return { message: 'Could not save user (schema mismatch).' }
}

async function saveAdmin() {
  formError.value = ''
  triedSave.value = true
  // Authority: you can never assign a role at or above your own.
  if (!assignableRoles.value.includes(adminForm.value.role)) {
    formError.value = "You don't have permission to assign that role."
    return
  }
  const isOwner    = adminForm.value.role === 'provider_admin'
  const isInternal = ['super_admin', 'country_admin', 'platform_staff'].includes(adminForm.value.role)
  if (isOwner && !editing.value && !adminForm.value.username.trim()) {
    formError.value = 'Company Login Username is required.'
    return
  }
  const resolvedOwnerId = isOwner ? null : isInternal ? null : (adminForm.value.owner_user_id || currentOwnerUserId.value || null)
  let resolvedUsername = null
  if (isOwner) {
    resolvedUsername = editing.value ? undefined : adminForm.value.username.trim().toLowerCase().replace(/\s+/g, '')
  } else {
    // Staff/managers carry NO username (it's a unique company-login field owned by the
    // provider). Their company is resolved via owner_user_id; keep username null on
    // create and untouched on edit.
    resolvedUsername = editing.value ? undefined : null
  }
  // For provider-scoped roles, inherit country from the assigned provider rather than
  // requiring the admin to select it manually — it's always the same as the provider's country.
  let resolvedCountryId = adminForm.value.country_id || null
  if (isProviderScopedRole(adminForm.value.role) && resolvedOwnerId) {
    const owner = allUsers.value.find(u => u.id === resolvedOwnerId)
    if (owner?.country_id) resolvedCountryId = owner.country_id
  }
  // A brand-new business (no locations yet) gets a country anchor from the creator's
  // jurisdiction so it shows in their list until its locations define its countries.
  if (isOwner && !editing.value && !resolvedCountryId) {
    const j = jurisdictionCountryIds.value
    if (j && j.size) resolvedCountryId = [...j][0]
  }

  const payload = {
    full_name: adminForm.value.full_name, email: adminForm.value.email, role: adminForm.value.role,
    country_id: resolvedCountryId, location_id: adminForm.value.location_id || null,
    owner_user_id: resolvedOwnerId, status: adminForm.value.status,
    phone: isOwner ? adminForm.value.phone : null,
    city_id: isOwner ? (adminForm.value.city_id || null) : null,
    commission_rate_facility: isOwner ? (adminForm.value.commission_rate_facility != null ? Number(adminForm.value.commission_rate_facility) : null) : null,
    commission_rate_program:  isOwner ? (adminForm.value.commission_rate_program  != null ? Number(adminForm.value.commission_rate_program)  : null) : null,
    business_status: isOwner ? (adminForm.value.business_status || 'pending') : null,
    description: isOwner ? adminForm.value.description : null,
    license_number: isOwner ? adminForm.value.license_number : null,
    // Per-user permission overrides + scope apply only to scoped roles.
    custom_permissions:    isScopedRole(adminForm.value.role) ? (adminForm.value.custom_permissions || {}) : {},
    assigned_location_ids: isScopedRole(adminForm.value.role) ? (adminForm.value.assigned_location_ids || []) : [],
    assigned_listing_ids:  isScopedRole(adminForm.value.role) ? (adminForm.value.assigned_listing_ids  || []) : [],
    assigned_program_ids:  isScopedRole(adminForm.value.role) ? (adminForm.value.assigned_program_ids  || []) : [],
  }
  if (resolvedUsername !== undefined) payload.username = resolvedUsername
  // New Platform Staff inherit the creator's country jurisdiction (empty = global).
  if (adminForm.value.role === 'platform_staff' && !editing.value) {
    const j = jurisdictionCountryIds.value
    payload.assigned_country_ids = j ? [...j] : []
  }
  const error = await persistAdminUser(payload)
  if (error) { formError.value = error.message; pushToast('Could not save user.', 'error'); return }
  clearDirty()
  logAudit({ action: editing.value ? AUDIT.UPDATED : AUDIT.CREATED, entity_type: 'admin_user', entity_id: editing.value?.id || payload.email, details: `${editing.value ? 'Updated' : 'Created'} ${roleLabelFn(adminForm.value.role)} ${payload.full_name || payload.email || ''}`.trim() })
  pushToast(editing.value ? 'User updated' : 'User created', 'success')
  showModal.value = false
  await load()
  await loadUserContext()
}
async function removeAdmin(user) {
  // Same authority ladder as saveAdmin: you can only delete someone whose role
  // you could assign. Without this, an unassigned platform_staff (empty
  // jurisdiction = sees every business) could delete any provider business.
  if (!user || !assignableRoles.value.includes(user.role)) {
    pushToast("You don't have the authority to delete this user.", 'error')
    return
  }
  if (!(await askConfirm({
    title: 'Delete user?',
    message: 'This user will be removed. If they own a business, all their locations and wallet data will be removed too. This cannot be undone.',
    confirmText: 'Delete',
  }))) return
  const { error } = await supabase.from('admin_users').delete().eq('id', user.id)
  if (error) { pushToast('Could not delete user.', 'error'); return }
  pushToast('User deleted', 'success')
  load()
}

// ── My Team (provider view) ───────────────────────────────────────────────────
const team        = ref([])
const filterRole  = ref('')
const filterLoc   = ref('')
const teamForm    = ref(defaultTeamForm())

// Provider staff tiers (everything below Owner) — drives the overview count cards.
const TEAM_ROLES = ASSIGNABLE_TEAM_ROLES.map(v => ({
  value: v, label: ROLE_INFO[v].label, desc: ROLE_INFO[v].tagline, color: ROLE_INFO[v].color, bg: ROLE_INFO[v].bg,
}))

function defaultTeamForm() {
  return {
    full_name: '', email: '', role: 'staff', location_id: '', phone: '', status: 'active',
    custom_permissions: {}, assigned_location_ids: [], assigned_listing_ids: [], assigned_program_ids: [],
  }
}

// Scope options for the permission editor, limited to the relevant business.
// Super admin: the owner picked in the form. Provider: their own business.
const editorOwnerId = computed(() =>
  isSuperAdmin.value ? (adminForm.value.owner_user_id || null) : currentOwnerUserId.value)
const editorLocations = computed(() =>
  isSuperAdmin.value
    ? (editorOwnerId.value ? locations.value.filter(l => l.owner_user_id === editorOwnerId.value) : [])
    : locations.value)
const editorLocIdSet = computed(() => new Set(editorLocations.value.map(l => l.id)))
const editorListings = computed(() => listings.value.filter(l => editorLocIdSet.value.has(l.location_id)))
const editorPrograms = computed(() => programs.value.filter(p => editorLocIdSet.value.has(p.location_id)))

const filteredTeam = computed(() => {
  let list = team.value
  if (filterRole.value) list = list.filter(u => u.role === filterRole.value)
  // "Location" = who has access to this venue: scoped members via their
  // assigned_location_ids (the Access Scope picker), legacy members via the old
  // single location_id, and members with NO scope at all (= whole business).
  if (filterLoc.value)  list = list.filter(u => {
    const ids = u.assigned_location_ids || []
    if (ids.length) return ids.includes(filterLoc.value)
    if (u.location_id) return u.location_id === filterLoc.value
    return true   // unscoped = access to every location, this one included
  })
  if (filterCountry.value.length || filterCity.value.length) list = list.filter(memberGeoMatch)
  const q = search.value.trim().toLowerCase()
  if (q) list = list.filter(u =>
    (u.full_name || '').toLowerCase().includes(q) ||
    (u.email || '').toLowerCase().includes(q)
  )
  return list
})
const { page: teamPage, perPage: teamPerPage, totalPages: teamTotalPages, paginated: paginatedTeam, visiblePages: teamVisiblePages, goTo: teamGoTo } = usePagination(filteredTeam)

const countByRole = computed(() => {
  const counts = {}
  for (const r of TEAM_ROLES) counts[r.value] = team.value.filter(u => u.role === r.value).length
  return counts
})

function openTeamNew() {
  editing.value = null
  teamForm.value = defaultTeamForm()
  triedSave.value = false
  clearDirty()
  showModal.value = true
}
function openTeamEdit(u) {
  editing.value = u
  teamForm.value = {
    full_name: u.full_name || '', email: u.email || '', role: u.role || 'staff',
    location_id: u.location_id || '', phone: u.phone || '', status: u.status || 'active',
    custom_permissions: u.custom_permissions || {},
    assigned_location_ids: [...(u.assigned_location_ids || [])],
    assigned_listing_ids:  [...(u.assigned_listing_ids  || [])],
    assigned_program_ids:  [...(u.assigned_program_ids  || [])],
  }
  triedSave.value = false
  clearDirty()
  showModal.value = true
}
async function saveTeam() {
  triedSave.value = true
  if (!teamForm.value.full_name || !teamForm.value.email || !teamForm.value.role) {
    pushToast('Please fill in the name, email and role.', 'error'); return
  }
  saving.value = true
  const payload = {
    full_name: teamForm.value.full_name.trim(), email: teamForm.value.email.trim().toLowerCase(),
    role: teamForm.value.role, location_id: teamForm.value.location_id || null,
    phone: teamForm.value.phone || null, status: teamForm.value.status,
    owner_user_id: currentOwnerUserId.value,
    custom_permissions: teamForm.value.custom_permissions || {},
    assigned_location_ids: teamForm.value.assigned_location_ids || [],
    assigned_listing_ids:  teamForm.value.assigned_listing_ids  || [],
    assigned_program_ids:  teamForm.value.assigned_program_ids  || [],
  }
  const { error } = editing.value
    ? await supabase.from('admin_users').update(payload).eq('id', editing.value.id)
    : await supabase.from('admin_users').insert(payload)
  saving.value = false
  if (error) { pushToast('Could not save team member.', 'error'); return }
  clearDirty()
  logAudit({ action: editing.value ? AUDIT.UPDATED : AUDIT.CREATED, entity_type: 'team_member', entity_id: editing.value?.id || payload.email, details: `${editing.value ? 'Updated' : 'Added'} ${roleLabelFn(teamForm.value.role)} ${payload.full_name || payload.email || ''}`.trim() })
  pushToast(editing.value ? 'Team member updated' : 'Team member added', 'success')
  showModal.value = false
  load()
}
async function removeTeam(u) {
  if (!(await askConfirm({
    title: 'Remove team member?',
    message: `${u.full_name || u.email} will be removed from your team and will lose access.`,
    confirmText: 'Remove',
  }))) return
  const { error } = await supabase.from('admin_users').delete().eq('id', u.id)
  if (error) { pushToast('Could not remove team member.', 'error'); return }
  pushToast('Team member removed', 'success')
  load()
}

// ── Load ──────────────────────────────────────────────────────────────────────
async function load() {
  if (isPlatformSide.value) {
    const [u, c, ci, l, cr, li, pg] = await Promise.all([
      supabase.from('admin_users').select('*').order('created_at', { ascending: false }),
      supabase.from('countries').select('*').eq('status', 'active').order('name'),
      supabase.from('cities').select('*').eq('status', 'active').order('name'),
      supabase.from('locations').select('*'),
      supabase.from('commission_rates').select('*'),
      supabase.from('listings').select('id, name, location_id'),
      supabase.from('class_programs').select('id, name, location_id, owner_user_id'),
    ])
    allUsers.value   = u.data || []
    countries.value  = c.data || []
    cities.value     = ci.data || []
    locations.value  = l.data || []
    listings.value   = li.data || []
    programs.value   = pg.data || []
    const rates = {}
    for (const r of (cr.data || [])) {
      if (!rates[r.country_id]) rates[r.country_id] = { facility: 20, program: 20 }
      rates[r.country_id][r.listing_type] = Number(r.rate_percent)
    }
    commissionRates.value = rates
  } else {
    const ownerId = currentOwnerUserId.value
    if (!ownerId) return
    const [tm, lc] = await Promise.all([
      supabase.from('admin_users').select('*').eq('owner_user_id', ownerId)
        .in('role', ['facility_admin', 'provider_supervisor', 'staff', 'provider_viewer', 'senior_employee', 'junior_employee', 'guard'])
        .order('created_at', { ascending: false }),
      supabase.from('locations').select('id, name').eq('owner_user_id', ownerId).order('name'),
    ])
    team.value      = tm.data || []
    locations.value = lc.data || []
    // Listings + programs for the per-user scope pickers (owner's own catalog).
    const locIds = (lc.data || []).map(l => l.id)
    if (locIds.length) {
      const [li, pg] = await Promise.all([
        supabase.from('listings').select('id, name, location_id').in('location_id', locIds),
        supabase.from('class_programs').select('id, name, location_id').in('location_id', locIds),
      ])
      listings.value = li.data || []
      programs.value = pg.data || []
    } else { listings.value = []; programs.value = [] }
  }
}
onMounted(load)
// Reload when the active user changes (role/jurisdiction differ → different data set).
watch(() => currentUser.value?.id, () => load())

// ── Grouped view helpers (super admin) ────────────────────────────────────────

// Platform-level team: Bookboll staff (super/country admins + platform staff with no
// owner). Limited to roles the current user may manage (so a Platform Staff doesn't
// see/manage other platform staff or admins), plus themselves.
const platformTeam = computed(() =>
  allUsers.value.filter(u => {
    const isPlatformMember = ['super_admin', 'country_admin', 'platform_staff'].includes(u.role) ||
      (isProviderScopedRole(u.role) && !u.owner_user_id)
    if (!isPlatformMember) return false
    if (isSuperAdmin.value) return true
    return u.id === currentUser.value?.id || assignableRoles.value.includes(u.role)
  })
)

// Each provider_admin with their staff grouped — only businesses in the current
// platform user's jurisdiction (country / assigned venues).
const providerGroups = computed(() => {
  const providers = allUsers.value.filter(u => u.role === 'provider_admin' && businessInJurisdiction(u.id))
  return providers.map(p => ({
    provider: p,
    staff: allUsers.value.filter(u => u.owner_user_id === p.id),
  }))
})

// Expand state for business groups — all collapsed by default
const expandedBusinesses = ref(new Set())

function toggleBusiness(id) {
  const s = new Set(expandedBusinesses.value)
  s.has(id) ? s.delete(id) : s.add(id)
  expandedBusinesses.value = s
}

// Auto-expand businesses whose staff match the current search
watch(search, (q) => {
  if (!q.trim()) return
  const lower = q.trim().toLowerCase()
  const s = new Set(expandedBusinesses.value)
  for (const g of providerGroups.value) {
    const staffMatch = g.staff.some(u =>
      (u.full_name || '').toLowerCase().includes(lower) ||
      (u.email || '').toLowerCase().includes(lower) ||
      roleLabel(u.role).toLowerCase().includes(lower)
    )
    if (staffMatch) s.add(g.provider.id)
  }
  expandedBusinesses.value = s
})

const filteredPlatformTeam = computed(() => {
  const q = search.value.trim().toLowerCase()
  return platformTeam.value.filter(u =>
    memberGeoMatch(u) && (!q ||
      (u.full_name || '').toLowerCase().includes(q) ||
      (u.email || '').toLowerCase().includes(q) ||
      roleLabel(u.role).toLowerCase().includes(q))
  )
})

const filteredProviderGroups = computed(() => {
  const q = search.value.trim().toLowerCase()
  return providerGroups.value.filter(g => {
    if (filterCountry.value.length || filterCity.value.length) {
      if (!memberGeoMatch(g.provider) && !g.staff.some(memberGeoMatch)) return false
    }
    // Business type (multi): provider matches if they have ANY selected type.
    if (filterBizTypes.value.length) {
      const types = normalizeBusinessTypes(g.provider.business_types)
      if (!filterBizTypes.value.some(t => types.includes(t))) return false
    }
    // Plan filter (Premium / Free).
    if (filterPlan.value && (filterPlan.value === 'premium') !== isPremiumPlan(g.provider)) return false
    if (!q) return true
    const provMatch =
      (g.provider.full_name || '').toLowerCase().includes(q) ||
      (g.provider.email || '').toLowerCase().includes(q) ||
      (g.provider.username || '').toLowerCase().includes(q)
    const staffMatch = g.staff.some(u =>
      (u.full_name || '').toLowerCase().includes(q) ||
      (u.email || '').toLowerCase().includes(q) ||
      roleLabel(u.role).toLowerCase().includes(q)
    )
    return provMatch || staffMatch
  })
})

// Open invite modal pre-filled with a company assignment
function openAddStaffFor(providerId) {
  editing.value = null
  adminForm.value = { ...defaultAdminForm(), role: 'facility_admin', owner_user_id: providerId }
  formError.value = ''
  triedSave.value = false
  clearDirty()
  showModal.value = true
}

// When a super admin picks a country for a NEW provider account,
// auto-fill both commission rates from that country's defaults.
// (Does nothing when editing an existing record — stored values take precedence.)
watch(
  () => adminForm.value.country_id,
  (countryId) => {
    if (adminForm.value.role !== 'provider_admin' || editing.value || !countryId) return
    const defaults = commissionRates.value[countryId]
    if (defaults) {
      adminForm.value.commission_rate_facility = defaults.facility
      adminForm.value.commission_rate_program  = defaults.program
    }
  }
)

// ── Helpers ───────────────────────────────────────────────────────────────────
const roleLabel  = (r) => roleLabelFn(r)
const roleBadge  = (r) => ({ super_admin: 'badge-error', country_admin: 'badge-warning', provider_admin: 'badge-info', facility_admin: 'badge-info', senior_employee: 'badge-warning', junior_employee: 'badge-neutral', guard: 'badge-neutral' }[r] || 'badge-neutral')
const roleInfo   = (r) => TEAM_ROLES.find(x => x.value === r) || { label: r, color: '#64748b', bg: '#f8fafc' }
const locName    = (id) => locations.value.find(l => l.id === id)?.name || '—'
// Human summary of a user's location scope (assigned_location_ids; empty = all).
function scopeSummary(u) {
  const ids = u?.assigned_location_ids || []
  if (!ids.length) return u?.location_id ? locName(u.location_id) : 'All locations'
  if (ids.length === 1) return locName(ids[0])
  return `${ids.length} locations`
}
const countryName = (id) => countries.value.find(c => c.id === id)?.name
const ownerTag   = (id) => { const o = allUsers.value.find(u => u.id === id); return o?.username ? `@${o.username}` : o?.full_name || '—' }

function scopeText(u) {
  if (u.role === 'provider_admin') return u.username ? `@${u.username}` : '—'
  if (u.location_id) return locName(u.location_id)
  if (u.owner_user_id) return ownerTag(u.owner_user_id)
  if (u.country_id) return countryName(u.country_id)
  return 'Global'
}

const statusBadge = (s) => ({ approved: 'badge-success', pending: 'badge-warning', rejected: 'badge-error' }[s] || 'badge-neutral')

// ── Provider plan (Free / Premium) — super admin only ──────────────────────────
// The plan lives on the provider's own admin_users row; flipping it here lets Bookboll
// comp a business to Premium or move them back to Free without touching billing.
const isPremiumPlan = (p) => p?.plan === 'premium'
const planBusyId = ref('')
async function setProviderPlan(provider, plan) {
  if (!isSuperAdmin.value || !provider?.id || planBusyId.value) return
  if (isPremiumPlan(provider) === (plan === 'premium')) return   // no change
  if (plan === 'free') {
    const ok = await askConfirm({
      title: 'Move this business to Free?',
      message: `${provider.business_name || provider.full_name} will lose Premium features (blog, marketing emails, exports, promo codes, custom banners, scoped widgets). Their data stays; the features just lock again.`,
      confirmText: 'Move to Free', danger: true,
    })
    if (!ok) return
  }
  planBusyId.value = provider.id
  const patch = plan === 'premium'
    ? { plan: 'premium', premium_since: new Date().toISOString() }
    : { plan: 'free', premium_since: null }
  const { error } = await supabase.from('admin_users').update(patch).eq('id', provider.id)
  planBusyId.value = ''
  if (error) {
    pushToast(/could not find|does not exist|schema cache/i.test(error.message || '')
      ? 'Run the provider_premium migration (20260725000000) first.'
      : (error.message || 'Could not change the plan.'), 'error')
    return
  }
  // Update the in-memory row so the badge flips instantly (allUsers drives the groups).
  const row = allUsers.value.find(u => u.id === provider.id)
  if (row) { row.plan = patch.plan; row.premium_since = patch.premium_since }
  logAudit({ action: plan === 'premium' ? AUDIT.ENABLED : AUDIT.DISABLED, entity_type: 'provider_plan', entity_id: provider.id, details: `Set ${provider.business_name || provider.full_name} to ${plan === 'premium' ? 'Premium' : 'Free'}` })
  pushToast(plan === 'premium' ? 'Business upgraded to Premium' : 'Business moved to Free', 'success')
}
const timeAgo = (ts) => {
  if (!ts) return 'Never'
  const diff = Date.now() - new Date(ts).getTime()
  const mins = Math.floor(diff / 60000)
  if (mins < 60) return `${mins}m ago`
  const hrs = Math.floor(mins / 60)
  if (hrs < 24) return `${hrs}h ago`
  return `${Math.floor(hrs / 24)}d ago`
}

function avatarInitials(name) { return (name || '?').split(' ').map(s => s[0]).slice(0, 2).join('').toUpperCase() }
const avatarColors = ['#0891b2', '#059669', '#d97706', '#dc2626', '#7c3aed', '#db2777']
function avatarColor(id) {
  const n = id ? parseInt(id.replace(/-/g, '').slice(0, 8), 16) : 0
  return avatarColors[n % avatarColors.length]
}
</script>

<template>
  <div class="page">

    <!-- ── PLATFORM VIEW (Super / Country Admin / Platform Staff) ──────── -->
    <template v-if="isPlatformSide">
      <div class="page-header">
        <div>
          <div class="page-title">Team &amp; Users</div>
          <div class="page-subtitle">{{ isSuperAdmin ? 'Platform admins and all provider businesses with their staff.' : 'Bookboll staff and the provider businesses in your jurisdiction.' }}</div>
        </div>
        <button class="btn btn-primary" @click="openAdminNew"><Plus :size="16" />Invite User</button>
      </div>

      <!-- Search -->
      <FilterBar
        v-model:search="search"
        v-model:country="filterCountry"
        v-model:city="filterCity"
        :countries="countries"
        :cities="cities"
        :extra-count="(filterBizTypes.length ? 1 : 0) + (filterPlan ? 1 : 0)"
        search-placeholder="Search by name, email or @username…"
        @clear="filterBizTypes = []; filterPlan = ''"
      >
        <div class="lf-selects">
          <div class="lf-field"><label>Business type</label>
            <MultiSelect v-model="filterBizTypes" :options="bizTypeOptions" placeholder="All types" />
          </div>
          <div class="lf-field"><label>Plan</label>
            <select v-model="filterPlan" class="lf-select">
              <option value="">All plans</option>
              <option value="premium">Premium</option>
              <option value="free">Free</option>
            </select>
          </div>
        </div>
      </FilterBar>


      <!-- ── Platform Team ───────────────────────────────────────────── -->
      <div v-if="filteredPlatformTeam.length" class="section-block">
        <div class="section-label"><Shield :size="13" />Platform Team <span class="section-count">{{ filteredPlatformTeam.length }}</span></div>
        <div class="card" style="padding:0;overflow:hidden;">
          <div class="table-wrap">
            <table>
              <thead>
                <tr><th>Name</th><th>Email</th><th>Role</th><th>Scope</th><th>Status</th><th>Last Login</th><th></th></tr>
              </thead>
              <tbody>
                <tr v-for="u in filteredPlatformTeam" :key="u.id">
                  <td>
                    <div class="cell-main">
                      <div class="avatar-sm" :style="`background:${avatarColor(u.id)}`">{{ avatarInitials(u.full_name) }}</div>
                      <div style="font-weight:500;">{{ u.full_name }}</div>
                    </div>
                  </td>
                  <td style="color:var(--text-muted);">{{ u.email }}</td>
                  <td><span class="badge" :class="roleBadge(u.role)"><Shield :size="11" />{{ roleLabel(u.role) }}</span></td>
                  <td style="color:var(--text-muted);">{{ scopeText(u) }}</td>
                  <td><span class="badge" :class="u.status==='active'?'badge-success':'badge-neutral'">{{ u.status }}</span></td>
                  <td style="color:var(--text-muted);">{{ timeAgo(u.last_login) }}</td>
                  <td><div class="row-actions"><button class="btn-icon" @click="openAdminEdit(u)"><Edit2 :size="15" /></button><button class="btn-icon danger" @click="removeAdmin(u)"><Trash2 :size="15" /></button></div></td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- ── Provider Businesses ─────────────────────────────────────── -->
      <div class="section-block">
        <div class="section-label"><Building2 :size="13" />Provider Businesses <span class="section-count">{{ filteredProviderGroups.length }}</span></div>

        <div v-if="!filteredProviderGroups.length" class="card" style="text-align:center;padding:32px;color:var(--text-muted);">
          No providers found.
        </div>

        <div v-for="g in filteredProviderGroups" :key="g.provider.id" class="biz-group">

          <!-- Business header row -->
          <div class="biz-header" @click="toggleBusiness(g.provider.id)">
            <div class="biz-icon"><Building2 :size="17" /></div>
            <div class="biz-name-block">
              <div class="biz-name">{{ g.provider.business_name || g.provider.full_name }}</div>
              <div v-if="g.provider.username" class="biz-username">@{{ g.provider.username }}</div>
            </div>
            <div class="biz-owner-email">{{ g.provider.email }}</div>
            <div class="biz-badges">
              <span class="biz-plan-chip" :class="{ premium: isPremiumPlan(g.provider) }" :title="isPremiumPlan(g.provider) ? 'On the Premium plan' : 'On the Free plan'">
                <Crown v-if="isPremiumPlan(g.provider)" :size="11" />{{ isPremiumPlan(g.provider) ? 'Premium' : 'Free' }}
              </span>
              <span v-for="t in normalizeBusinessTypes(g.provider.business_types)" :key="t" class="biz-type-chip">{{ businessTypeLabel(t) }}</span>
              <span class="badge" :class="statusBadge(g.provider.business_status)">{{ g.provider.business_status || 'pending' }}</span>
              <span class="biz-staff-count"><UsersRound :size="12" />{{ g.staff.length }} staff</span>
              <span v-if="g.provider.country_id" class="biz-country-pill">{{ countryName(g.provider.country_id) }}</span>
            </div>
            <div class="biz-actions" @click.stop>
              <button v-if="isSuperAdmin && !isPremiumPlan(g.provider)" class="btn-icon plan-up" :disabled="planBusyId === g.provider.id" @click="setProviderPlan(g.provider, 'premium')" title="Upgrade to Premium"><Crown :size="15" /></button>
              <button v-else-if="isSuperAdmin" class="btn-icon plan-down" :disabled="planBusyId === g.provider.id" @click="setProviderPlan(g.provider, 'free')" title="Move to Free"><CircleArrowDown :size="15" /></button>
              <button class="btn-icon" @click="openAdminEdit(g.provider)" title="Edit business"><Edit2 :size="15" /></button>
              <button class="btn-icon danger" @click="removeAdmin(g.provider)" title="Delete business"><Trash2 :size="15" /></button>
            </div>
            <div class="biz-chevron" :class="{ open: expandedBusinesses.has(g.provider.id) }"><ChevronDown :size="16" /></div>
          </div>

          <!-- Staff list (expanded) -->
          <div v-if="expandedBusinesses.has(g.provider.id)" class="staff-section">
            <table class="staff-table">
              <thead>
                <tr>
                  <th>Staff Member</th><th>Role</th><th>Location</th><th>Status</th><th>Last Login</th><th></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="m in g.staff" :key="m.id">
                  <td>
                    <div class="cell-main">
                      <div class="avatar-sm sm" :style="`background:${avatarColor(m.id)}`">{{ avatarInitials(m.full_name) }}</div>
                      <div>
                        <div style="font-weight:500;font-size:13px;">{{ m.full_name || '—' }}</div>
                        <div style="font-size:12px;color:var(--text-muted);">{{ m.email }}</div>
                      </div>
                    </div>
                  </td>
                  <td><span class="badge" :class="roleBadge(m.role)">{{ roleLabel(m.role) }}</span></td>
                  <td style="font-size:13px;color:var(--text-muted);">{{ scopeSummary(m) }}</td>
                  <td><span class="badge" :class="m.status==='active'?'badge-success':'badge-neutral'">{{ m.status }}</span></td>
                  <td style="font-size:12px;color:var(--text-muted);">{{ timeAgo(m.last_login) }}</td>
                  <td><div class="row-actions"><button class="btn-icon" @click="openAdminEdit(m)"><Edit2 :size="15" /></button><button class="btn-icon danger" @click="removeAdmin(m)"><Trash2 :size="15" /></button></div></td>
                </tr>
                <tr v-if="!g.staff.length">
                  <td colspan="6" class="empty-staff">No staff assigned yet.</td>
                </tr>
              </tbody>
            </table>
            <div class="staff-add-row">
              <button class="btn btn-sm btn-secondary" @click="openAddStaffFor(g.provider.id)">
                <Plus :size="13" />Add staff member
              </button>
            </div>
          </div>

        </div>
      </div>

      <!-- Admin modal -->
      <div v-if="showModal" class="modal-backdrop" @click.self="requestCloseTeamModal">
        <div class="modal" @input="markDirty" @change="markDirty">
          <div class="modal-header">
            <div class="modal-title">{{ editing ? 'Edit User' : 'Invite User' }}</div>
            <button class="btn-icon" @click="requestCloseTeamModal"><X :size="18" /></button>
          </div>
          <div class="modal-body">
            <div class="grid grid-2">
              <div class="form-group"><label class="form-label">Full Name</label><input v-model="adminForm.full_name" /></div>
              <div class="form-group"><label class="form-label">Email</label><input v-model="adminForm.email" /></div>
            </div>
            <div class="form-group"><label class="form-label">Role</label>
              <RoleSelect v-model="adminForm.role" :roles="assignableRoles" />
            </div>
            <div v-if="adminForm.role === 'provider_admin'" class="inline-business">
              <div class="inline-business-header"><Building2 :size="14" />Company details</div>
              <div class="form-group">
                <label class="form-label">Company Login Username <span class="required">*</span></label>
                <div class="username-input-wrap">
                  <span class="username-prefix"><AtSign :size="14" /></span>
                  <input v-model="adminForm.username" :readonly="!!editing" :class="{ 'input-locked': !!editing, 'input-invalid': triedSave && !editing && !adminForm.username.trim() }" placeholder="e.g. dsc" style="padding-left:34px;" />
                </div>
                <div v-if="editing" class="field-hint">Username is locked after creation.</div>
              </div>
              <div class="biz-country-note">
                <Info :size="13" />
                <span>This business can operate in any country. Its countries &amp; currencies are set automatically from the locations it adds — a wallet is created per country.</span>
              </div>
              <!--
                Commission rates — editable only by Super Admin.
                Providers see these as read-only.
                On new accounts, values auto-fill from the country's defaults
                (set in Countries & Cities → Commission Rates).
                NULL means "inherit country default at charge time".
              -->
              <div class="grid grid-2">
                <div class="form-group">
                  <label class="form-label">Listing Commission %</label>
                  <input v-if="isSuperAdmin" type="number" min="0" max="100" step="0.5"
                    v-model="adminForm.commission_rate_facility" placeholder="e.g. 20" />
                  <input v-else
                    :value="adminForm.commission_rate_facility != null ? adminForm.commission_rate_facility + '%' : '—'"
                    readonly class="input-locked" />
                  <div class="field-hint">Court &amp; space bookings</div>
                </div>
                <div class="form-group">
                  <label class="form-label">Memberships %</label>
                  <input v-if="isSuperAdmin" type="number" min="0" max="100" step="0.5"
                    v-model="adminForm.commission_rate_program" placeholder="e.g. 20" />
                  <input v-else
                    :value="adminForm.commission_rate_program != null ? adminForm.commission_rate_program + '%' : '—'"
                    readonly class="input-locked" />
                  <div class="field-hint">Membership programs</div>
                </div>
              </div>
              <div v-if="isSuperAdmin && !editing && adminForm.country_id" style="margin-top:-8px;margin-bottom:8px;font-size:12px;">
                <span v-if="commissionRates[adminForm.country_id]" style="color:var(--primary)">
                  ↑ Auto-filled from {{ countryName(adminForm.country_id) }} defaults. Adjust if needed.
                </span>
                <span v-else style="color:var(--text-muted)">
                  No rates configured for {{ countryName(adminForm.country_id) }} yet — enter manually or set defaults in Countries &amp; Cities first.
                </span>
              </div>
              <div class="form-group"><label class="form-label">Account Status</label><input :value="adminForm.business_status" readonly class="input-locked" /></div>
              <div class="form-group"><label class="form-label">Description</label><textarea v-model="adminForm.description" rows="2"></textarea></div>
            </div>
            <div v-else-if="isProviderScopedRole(adminForm.role)">
              <div class="form-group">
                <label class="form-label">Company</label>
                <select v-model="adminForm.owner_user_id">
                  <option value="">@bookboll</option>
                  <option v-for="b in businessOwners" :key="b.id" :value="b.id">@{{ b.username || b.full_name }}</option>
                </select>
                <div class="field-hint">Pick the business, then set this person's exact access &amp; venues below.</div>
              </div>
            </div>
            <!--
              Country field — only super_admin and country_admin need to select a country explicitly.
              Provider-scoped roles (facility_admin, senior_employee, junior_employee, guard, staff)
              inherit country_id from their assigned provider at save time — no manual selection needed.
              Provider admins set their country in the Company details section above.
            -->
            <div class="grid grid-2" v-if="['super_admin','country_admin'].includes(adminForm.role)">
              <div class="form-group"><label class="form-label">Country</label>
                <select v-model="adminForm.country_id"><option value="">—</option><option v-for="c in countries" :key="c.id" :value="c.id">{{ c.name }}</option></select>
              </div>
              <div class="form-group"><label class="form-label">Status</label>
                <select v-model="adminForm.status"><option>active</option><option>inactive</option></select>
              </div>
            </div>
            <!-- Status only for all other roles (provider_admin and provider-scoped) -->
            <div class="form-group" v-else>
              <label class="form-label">Status</label>
              <select v-model="adminForm.status"><option>active</option><option>inactive</option></select>
            </div>
            <!-- Per-user permissions + location/listing/program scope -->
            <div v-if="isScopedRole(adminForm.role)" class="perm-editor-wrap">
              <PermissionScopeEditor
                :role="adminForm.role"
                v-model:permissions="adminForm.custom_permissions"
                v-model:locationIds="adminForm.assigned_location_ids"
                v-model:listingIds="adminForm.assigned_listing_ids"
                v-model:programIds="adminForm.assigned_program_ids"
                :available-locations="editorLocations"
                :available-listings="editorListings"
                :available-programs="editorPrograms"
              />
            </div>
            <div v-if="formError" class="form-error">{{ formError }}</div>
          </div>
          <div class="modal-footer">
            <button class="btn btn-secondary" @click="requestCloseTeamModal">Cancel</button>
            <button class="btn btn-primary" @click="saveAdmin">Save</button>
          </div>
        </div>
      </div>
    </template>

    <!-- ── PROVIDER VIEW ─────────────────────────────────────────────── -->
    <template v-else>
      <div class="page-header">
        <div>
          <div class="page-title">My Team</div>
          <div class="page-subtitle">Manage staff members who have access to your facility operations.</div>
        </div>
        <button class="btn btn-primary" @click="openTeamNew"><Plus :size="15" />Invite Member</button>
      </div>

      <!-- Role overview cards -->
      <div class="role-cards">
        <div v-for="r in TEAM_ROLES" :key="r.value" class="role-card" :style="`border-top:3px solid ${r.color}`">
          <div class="role-card-top">
            <div class="role-card-icon" :style="`background:${r.bg}; color:${r.color}`">
              <Shield v-if="r.value==='facility_admin'" :size="18" />
              <UserCog v-else-if="r.value==='senior_employee'" :size="18" />
              <User v-else :size="18" />
            </div>
            <div class="role-card-count" :style="`color:${r.color}`">{{ countByRole[r.value] }}</div>
          </div>
          <div class="role-card-title">{{ r.label }}</div>
          <div class="role-card-desc">{{ r.desc }}</div>
        </div>
      </div>

      <!-- Toolbar -->
      <FilterBar
        v-model:search="search"
        v-model:country="filterCountry"
        v-model:city="filterCity"
        :countries="countries"
        :cities="cities"
        :extra-count="(filterRole ? 1 : 0) + (filterLoc ? 1 : 0)"
        search-placeholder="Search by name or email..."
        @clear="filterRole = ''; filterLoc = ''"
      >
        <div class="lf-selects">
          <div class="lf-field"><label>Role</label>
            <select class="filter-select" v-model="filterRole">
              <option value="">All roles</option>
              <option v-for="r in TEAM_ROLES" :key="r.value" :value="r.value">{{ r.label }}</option>
            </select>
          </div>
          <div class="lf-field"><label>Location</label>
            <select class="filter-select" v-model="filterLoc">
              <option value="">All locations</option>
              <option v-for="l in locations" :key="l.id" :value="l.id">{{ l.name }}</option>
            </select>
          </div>
        </div>
      </FilterBar>

      <!-- Empty state -->
      <div v-if="!team.length" class="team-empty">
        <Users :size="36" style="opacity:0.25" />
        <div class="team-empty-title">No team members yet</div>
        <div class="team-empty-sub">Invite facility admins, senior and junior employees to collaborate on your operations.</div>
        <button class="btn btn-primary" @click="openTeamNew"><Plus :size="14" />Invite your first member</button>
      </div>

      <template v-else>
        <div v-if="filteredTeam.length" class="team-grid">
          <div v-for="u in paginatedTeam" :key="u.id" class="team-card">
            <div class="team-card-header">
              <div class="team-avatar" :style="`background:${avatarColor(u.id)}`">{{ avatarInitials(u.full_name) }}</div>
              <div class="team-card-info">
                <div class="team-name">{{ u.full_name || '—' }}</div>
                <div class="team-email">{{ u.email }}</div>
              </div>
              <div class="team-card-menu">
                <button class="btn-icon" @click="openTeamEdit(u)" title="Edit"><Edit2 :size="15" /></button>
                <button class="btn-icon danger" @click="removeTeam(u)" title="Remove"><Trash2 :size="15" /></button>
              </div>
            </div>
            <div class="team-card-body">
              <span class="role-pill" :style="`background:${roleInfo(u.role).bg}; color:${roleInfo(u.role).color}; border-color:${roleInfo(u.role).color}22`">
                <Shield v-if="u.role==='facility_admin'" :size="11" />
                <UserCog v-else-if="u.role==='senior_employee'" :size="11" />
                <User v-else :size="11" />
                {{ roleInfo(u.role).label }}
              </span>
              <span v-if="u.status !== 'active'" class="status-pill status-inactive">Inactive</span>
            </div>
            <div class="team-card-footer">
              <div class="team-meta"><MapPin :size="12" /><span>{{ scopeSummary(u) }}</span></div>
              <div v-if="u.phone" class="team-meta"><span>{{ u.phone }}</span></div>
            </div>
          </div>
        </div>
        <div v-else class="card empty" style="text-align:center;padding:32px;">No team members match your filters.</div>
        <PaginationBar v-if="filteredTeam.length > 0" :page="teamPage" :perPage="teamPerPage" :totalPages="teamTotalPages" :total="filteredTeam.length" :visiblePages="teamVisiblePages" @update:perPage="teamPerPage=$event" @goTo="teamGoTo" />
      </template>

      <!-- Provider modal -->
      <div v-if="showModal" class="modal-backdrop" @click.self="requestCloseTeamModal">
        <div class="modal" style="max-width:520px;" @input="markDirty" @change="markDirty">
          <div class="modal-header">
            <div class="modal-title">{{ editing ? 'Edit Member' : 'Invite Team Member' }}</div>
            <button class="btn-icon" @click="requestCloseTeamModal"><X :size="18" /></button>
          </div>
          <div class="modal-body">
            <div class="form-group" style="margin-bottom:20px;">
              <label class="form-label">Role</label>
              <RoleSelect v-model="teamForm.role" :roles="ASSIGNABLE_TEAM_ROLES" />
            </div>
            <div class="form-row">
              <div class="form-group"><label class="form-label">Full name <span class="required">*</span></label><input v-model="teamForm.full_name" :class="{ 'input-invalid': triedSave && !teamForm.full_name }" placeholder="Jane Smith" /></div>
              <div class="form-group"><label class="form-label">Email <span class="required">*</span></label><input v-model="teamForm.email" type="email" :class="{ 'input-invalid': triedSave && !teamForm.email }" placeholder="jane@example.com" /></div>
            </div>
            <div class="form-row">
              <div class="form-group"><label class="form-label">Phone</label><input v-model="teamForm.phone" placeholder="+971 50 123 4567" /></div>
              <div class="form-group"><label class="form-label">Status</label>
                <select v-model="teamForm.status"><option value="active">Active</option><option value="inactive">Inactive</option></select>
              </div>
            </div>
            <!-- Per-user permissions + location/listing/program scope -->
            <PermissionScopeEditor
              :role="teamForm.role"
              v-model:permissions="teamForm.custom_permissions"
              v-model:locationIds="teamForm.assigned_location_ids"
              v-model:listingIds="teamForm.assigned_listing_ids"
              v-model:programIds="teamForm.assigned_program_ids"
              :available-locations="editorLocations"
              :available-listings="editorListings"
              :available-programs="editorPrograms"
            />
          </div>
          <div class="modal-footer">
            <button class="btn btn-secondary" @click="requestCloseTeamModal">Cancel</button>
            <button class="btn btn-primary" :disabled="!teamForm.full_name || !teamForm.email || saving" @click="saveTeam">{{ editing ? 'Save changes' : 'Invite member' }}</button>
          </div>
        </div>
      </div>
    </template>

  </div>
</template>

<style scoped>
/* Role explanation panel in the create/edit account form */
.role-explain { margin-top: 10px; padding: 10px 12px; border-left: 3px solid var(--border); background: #f8fafc; border-radius: 0 8px 8px 0; }
.role-explain-head { font-size: 13px; font-weight: 700; margin-bottom: 6px; }
.role-explain-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 3px; }
.role-explain-list li { font-size: 12.5px; line-height: 1.4; }
.re-can { color: #15803d; }
.re-cant { color: #b91c1c; }

.cell-main { display: flex; align-items: center; gap: 10px; }
.avatar-sm { width: 34px; height: 34px; border-radius: 50%; background: linear-gradient(135deg, #0f766e, #14b8a6); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 12px; }
.mini-avatar { width: 34px; height: 34px; border-radius: 8px; background: #ccfbf1; color: var(--primary); display: flex; align-items: center; justify-content: center; }
.row-actions { display: flex; gap: 4px; }
.btn-icon { padding: 7px; border-radius: 6px; color: var(--text-muted); display: inline-flex; }
.btn-icon:hover { background: var(--bg); color: var(--text); }
.btn-icon.danger:hover { background: #fee2e2; color: var(--error); }
.inline-business { margin: 12px 0; padding: 14px; border: 1px dashed var(--border); border-radius: 10px; background: #f8fafc; }
.biz-country-note { display: flex; align-items: flex-start; gap: 8px; font-size: 12.5px; color: var(--text-muted); background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 8px; padding: 9px 11px; margin-bottom: 10px; }
.biz-country-note svg { flex-shrink: 0; margin-top: 1px; color: #0284c7; }
.inline-business-header { font-weight: 600; font-size: 13px; display: flex; align-items: center; gap: 6px; margin-bottom: 10px; }
.username-input-wrap { position: relative; }
.username-prefix { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: var(--text-muted); display: flex; align-items: center; pointer-events: none; }
.input-locked { background: #f1f5f9; color: var(--text-muted); cursor: not-allowed; }
.field-hint { font-size: 12px; color: var(--text-muted); margin-top: 4px; }
.required { color: #dc2626; }
.form-error { margin-top: 12px; padding: 10px 14px; background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; color: #991b1b; font-size: 13px; }
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.filter-select { padding: 8px 10px; border: 1px solid var(--border); border-radius: 8px; background: var(--surface, #ffffff); font-size: 13px; color: var(--text); cursor: pointer; }
.role-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 14px; margin-bottom: 24px; }
.role-card { background: var(--surface, #ffffff); border: 1px solid var(--border); border-radius: 12px; padding: 18px; }
.role-card-top { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px; }
.role-card-icon { width: 38px; height: 38px; border-radius: 10px; display: flex; align-items: center; justify-content: center; }
.role-card-count { font-size: 28px; font-weight: 800; line-height: 1; }
.role-card-title { font-size: 14px; font-weight: 700; color: var(--text); margin-bottom: 4px; }
.role-card-desc { font-size: 12px; color: var(--text-muted); line-height: 1.5; }
.team-empty { display: flex; flex-direction: column; align-items: center; gap: 10px; padding: 64px 24px; text-align: center; background: var(--surface, #ffffff); border: 1px solid var(--border); border-radius: 12px; }
.team-empty-title { font-size: 16px; font-weight: 700; color: var(--text); }
.team-empty-sub { font-size: 13px; color: var(--text-muted); max-width: 380px; }
.team-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 14px; }
.team-card { background: var(--surface, #ffffff); border: 1px solid var(--border); border-radius: 12px; padding: 16px; display: flex; flex-direction: column; gap: 12px; transition: box-shadow 0.15s; }
.team-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,0.08); }
.team-card-header { display: flex; align-items: center; gap: 12px; }
.team-avatar { width: 40px; height: 40px; border-radius: 50%; color: #fff; font-size: 14px; font-weight: 700; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.team-card-info { flex: 1; min-width: 0; }
.team-name { font-size: 14px; font-weight: 700; color: var(--text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.team-email { font-size: 12px; color: var(--text-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.team-card-menu { display: flex; gap: 4px; flex-shrink: 0; }
.team-card-body { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.role-pill { display: inline-flex; align-items: center; gap: 5px; padding: 4px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; border: 1px solid; }
.status-pill { display: inline-flex; align-items: center; padding: 3px 9px; border-radius: 999px; font-size: 11.5px; font-weight: 600; }
.status-inactive { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
.team-card-footer { display: flex; flex-direction: column; gap: 4px; padding-top: 8px; border-top: 1px solid var(--border); }
.team-meta { display: flex; align-items: center; gap: 5px; font-size: 12px; color: var(--text-muted); }
.role-selector { display: flex; flex-direction: column; gap: 8px; }
.role-option { display: flex; align-items: flex-start; gap: 12px; padding: 12px 14px; border: 1.5px solid var(--border); border-radius: 10px; cursor: pointer; transition: all 0.15s; }
.role-option:hover { border-color: var(--primary); }
.role-option.selected { border-color: var(--primary); background: #f0f9ff; }
.role-option-icon { width: 34px; height: 34px; border-radius: 8px; background: #f1f5f9; color: var(--text-muted); display: flex; align-items: center; justify-content: center; flex-shrink: 0; transition: all 0.15s; }
.role-option-label { font-size: 13.5px; font-weight: 700; color: var(--text); }
.role-option-desc { font-size: 12px; color: var(--text-muted); margin-top: 2px; }
.perm-summary { background: #f8fafc; border: 1px solid var(--border); border-radius: 10px; padding: 14px; }
.perm-summary-title { font-size: 12px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 10px; }
.perm-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; }
.perm-item { font-size: 12.5px; padding-left: 18px; position: relative; }
.perm-item::before { content: ''; position: absolute; left: 0; top: 3px; width: 10px; height: 10px; border-radius: 50%; }
.perm-item.yes { color: #065f46; }
.perm-item.yes::before { background: #059669; }
.perm-item.no { color: var(--text-light, #94a3b8); }
.perm-item.no::before { background: #cbd5e1; }
.modal-footer { display: flex; justify-content: flex-end; gap: 10px; padding: 16px 24px; border-top: 1px solid var(--border); }

/* ── Grouped super-admin layout ─────────────────────────────── */
.section-block { margin-bottom: 28px; }
.section-label {
  display: flex; align-items: center; gap: 7px;
  font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em;
  color: var(--text-muted); margin-bottom: 10px;
}
.section-count {
  background: var(--border); color: var(--text-muted); border-radius: 999px;
  padding: 1px 7px; font-size: 11px; font-weight: 600;
}

/* Business group card */
.biz-group {
  background: var(--surface, #ffffff); border: 1px solid var(--border); border-radius: 12px;
  margin-bottom: 10px; overflow: hidden; transition: box-shadow 0.15s;
}
.biz-group:hover { box-shadow: 0 2px 10px rgba(0,0,0,.06); }

.biz-header {
  display: flex; align-items: center; gap: 12px;
  padding: 14px 18px; cursor: pointer; user-select: none;
}
.biz-header:hover { background: #fafafa; }

.biz-icon {
  width: 38px; height: 38px; border-radius: 10px;
  background: #ccfbf1; color: var(--primary);
  display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.biz-name-block { min-width: 160px; }
.biz-name { font-weight: 700; font-size: 14px; color: var(--text); }
.biz-username { font-size: 12px; color: var(--text-muted); margin-top: 1px; }
.biz-owner-email { font-size: 13px; color: var(--text-muted); flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

.biz-badges { display: flex; align-items: center; gap: 6px; flex-shrink: 0; flex-wrap: wrap; }
.biz-type-chip { display: inline-flex; align-items: center; font-size: 10.5px; font-weight: 700; color: #0f766e; background: #f0fdfa; border: 1px solid #99f6e4; border-radius: 999px; padding: 2px 8px; white-space: nowrap; }
.biz-plan-chip { display: inline-flex; align-items: center; gap: 3px; font-size: 10.5px; font-weight: 800; letter-spacing: .02em; color: var(--text-muted, #64748b); background: #f1f5f9; border: 1px solid var(--border, #e2e8f0); border-radius: 999px; padding: 2px 9px; white-space: nowrap; }
.biz-plan-chip.premium { color: #b45309; background: #fef3c7; border-color: #fde68a; }
.btn-icon.plan-up { color: #d97706; }
.btn-icon.plan-up:hover { background: #fef3c7; }
.btn-icon.plan-down { color: var(--text-light, #94a3b8); }
.btn-icon.plan-down:hover { background: #f1f5f9; color: #475569; }
.biz-staff-count {
  display: inline-flex; align-items: center; gap: 4px;
  font-size: 12px; color: var(--text-muted); background: var(--bg);
  border: 1px solid var(--border); border-radius: 999px; padding: 2px 9px;
}
.biz-country-pill {
  font-size: 11px; color: #0369a1; background: #e0f2fe;
  border: 1px solid #bae6fd; border-radius: 999px; padding: 2px 8px; font-weight: 600;
}

.biz-actions { display: flex; gap: 4px; flex-shrink: 0; }
.biz-chevron { color: var(--text-muted); transition: transform 0.2s; flex-shrink: 0; display: flex; }
.biz-chevron.open { transform: rotate(180deg); }

/* Staff sub-table */
.staff-section { border-top: 1px solid var(--border); background: #fafafa; }
.staff-table { width: 100%; border-collapse: collapse; }
.staff-table th {
  padding: 9px 16px 9px 20px; font-size: 11px; font-weight: 600;
  text-transform: uppercase; letter-spacing: .05em; color: var(--text-muted);
  background: #f1f5f9; border-bottom: 1px solid var(--border); text-align: left;
}
.staff-table td { padding: 11px 16px 11px 20px; border-bottom: 1px solid var(--border); font-size: 13px; }
.staff-table tbody tr:last-child td { border-bottom: none; }
.staff-table tbody tr:hover td { background: #f8fafc; }
.avatar-sm.sm { width: 28px; height: 28px; font-size: 10px; }
.empty-staff { text-align: center; color: var(--text-muted); font-size: 13px; padding: 20px; }

.staff-add-row {
  padding: 10px 20px 14px;
  border-top: 1px dashed var(--border);
  background: #f8fafc;
}
.btn-sm { padding: 6px 12px; font-size: 13px; border-radius: 7px; display: inline-flex; align-items: center; gap: 5px; }

/* ── Phone (≤600px) ─────────────────────────────────────────── */
@media (max-width: 600px) {
  /* Business header row: wrap instead of clipping actions/chevron off-screen */
  .biz-header { flex-wrap: wrap; padding: 12px 14px; row-gap: 8px; }
  .biz-name-block { min-width: 0; flex: 1; order: 1; }
  .biz-actions { order: 2; margin-left: auto; }
  .biz-chevron { order: 3; }
  .biz-name { white-space: normal; overflow-wrap: anywhere; }
  .biz-owner-email { flex-basis: 100%; order: 5; }
  .biz-badges { flex-basis: 100%; order: 6; }

  /* Expanded staff table: scroll inside its own container (table-wrap convention) */
  .staff-section { overflow-x: auto; -webkit-overflow-scrolling: touch; }
  .staff-table { min-width: 620px; }

  /* Match global mobile input convention (16px prevents iOS zoom) */
  .filter-select { font-size: 16px; padding: 10px; }

  /* Align bottom-sheet footer spacing with global sheet rules */
  .modal-footer { padding: 14px 18px; gap: 8px; }
}
</style>
