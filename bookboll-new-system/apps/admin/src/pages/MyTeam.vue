<script setup>
import { ref, onMounted, computed } from 'vue'
import { supabase } from '../lib/supabase.js'
import { askConfirm } from '../lib/confirm.js'
import { pushToast } from '../lib/toast.js'
import { useCurrentUser } from '../lib/currentUser.js'
import { Plus, Search, Trash2, Pencil as Edit2, X, Users, Shield, UserCog, User, MapPin, ChevronDown } from 'lucide-vue-next'
import PaginationBar from '../components/PaginationBar.vue'
import { usePagination } from '../lib/usePagination.js'

const { currentOwnerUserId, isSuperAdmin, currentUsername } = useCurrentUser()

const team = ref([])
const locations = ref([])
const search = ref('')
const filterRole = ref('')
const filterLocation = ref('')
const showModal = ref(false)
const editing = ref(null)
const form = ref(defaultForm())
const saving = ref(false)

const TEAM_ROLES = [
  { value: 'facility_admin', label: 'Facility Admin', desc: 'Manages a location: bookings, listings, staff', color: '#0891b2', bg: '#ecfeff' },
  { value: 'senior_employee', label: 'Senior Employee', desc: 'Full operational access, no admin privileges', color: '#059669', bg: '#ecfdf5' },
  { value: 'junior_employee', label: 'Junior Employee', desc: 'Limited access to daily operations', color: '#d97706', bg: '#fffbeb' },
]

function defaultForm() {
  return { full_name: '', email: '', role: 'junior_employee', location_id: '', phone: '', status: 'active' }
}

async function load() {
  const ownerId = currentOwnerUserId.value
  if (!ownerId) return

  const [tm, lc] = await Promise.all([
    supabase.from('admin_users')
      .select('*')
      .eq('owner_user_id', ownerId)
      .in('role', ['facility_admin', 'senior_employee', 'junior_employee'])
      .order('created_at', { ascending: false }),
    supabase.from('locations')
      .select('id, name')
      .eq('owner_user_id', ownerId)
      .order('name'),
  ])
  team.value = tm.data || []
  locations.value = lc.data || []
}
onMounted(load)

const locationName = (id) => locations.value.find(l => l.id === id)?.name || '—'
const roleInfo = (r) => TEAM_ROLES.find(x => x.value === r) || { label: r, color: '#64748b', bg: '#f8fafc' }

const filtered = computed(() => {
  let list = team.value
  if (filterRole.value) list = list.filter(u => u.role === filterRole.value)
  if (filterLocation.value) list = list.filter(u => u.location_id === filterLocation.value)
  const q = search.value.trim().toLowerCase()
  if (q) list = list.filter(u =>
    (u.full_name || '').toLowerCase().includes(q) ||
    (u.email || '').toLowerCase().includes(q)
  )
  return list
})

const { page: teamPage, perPage: teamPerPage, totalPages: teamTotalPages, paginated: paginatedTeam, visiblePages: teamVisiblePages, goTo: teamGoTo } = usePagination(filtered)

const countByRole = computed(() => {
  const counts = {}
  for (const r of TEAM_ROLES) counts[r.value] = team.value.filter(u => u.role === r.value).length
  return counts
})

function openInvite() {
  editing.value = null
  form.value = defaultForm()
  showModal.value = true
}

function openEdit(u) {
  editing.value = u
  form.value = {
    full_name: u.full_name || '',
    email: u.email || '',
    role: u.role || 'junior_employee',
    location_id: u.location_id || '',
    phone: u.phone || '',
    status: u.status || 'active',
  }
  showModal.value = true
}

async function save() {
  if (!form.value.full_name || !form.value.email || !form.value.role) return
  saving.value = true
  const payload = {
    full_name: form.value.full_name.trim(),
    email: form.value.email.trim().toLowerCase(),
    role: form.value.role,
    location_id: form.value.location_id || null,
    phone: form.value.phone || null,
    status: form.value.status,
    owner_user_id: currentOwnerUserId.value,
    username: currentUsername.value || null,
  }
  if (editing.value) {
    await supabase.from('admin_users').update(payload).eq('id', editing.value.id)
  } else {
    await supabase.from('admin_users').insert(payload)
  }
  saving.value = false
  showModal.value = false
  load()
}

async function remove(u) {
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

function avatarInitials(name) {
  return (name || '?').split(' ').map(s => s[0]).slice(0, 2).join('').toUpperCase()
}

const avatarColors = ['#0891b2', '#059669', '#d97706', '#dc2626', '#7c3aed', '#db2777']
function avatarColor(id) {
  const n = id ? parseInt(id.replace(/-/g, '').slice(0, 8), 16) : 0
  return avatarColors[n % avatarColors.length]
}
</script>

<template>
  <div class="page">
    <div class="page-header">
      <div>
        <div class="page-title">My Team</div>
        <div class="page-subtitle">Manage staff members who have access to your facility operations.</div>
      </div>
      <button class="btn btn-primary" @click="openInvite"><Plus :size="15" />Invite Member</button>
    </div>

    <!-- Role overview cards -->
    <div class="role-cards">
      <div v-for="r in TEAM_ROLES" :key="r.value" class="role-card" :style="`border-top: 3px solid ${r.color}`">
        <div class="role-card-top">
          <div class="role-card-icon" :style="`background:${r.bg}; color:${r.color}`">
            <Shield v-if="r.value === 'facility_admin'" :size="18" />
            <UserCog v-else-if="r.value === 'senior_employee'" :size="18" />
            <User v-else :size="18" />
          </div>
          <div class="role-card-count" :style="`color:${r.color}`">{{ countByRole[r.value] }}</div>
        </div>
        <div class="role-card-title">{{ r.label }}</div>
        <div class="role-card-desc">{{ r.desc }}</div>
      </div>
    </div>

    <!-- Toolbar -->
    <div class="toolbar">
      <div class="search">
        <Search :size="15" />
        <input v-model="search" placeholder="Search by name or email..." />
      </div>
      <select v-model="filterRole" class="filter-select">
        <option value="">All roles</option>
        <option v-for="r in TEAM_ROLES" :key="r.value" :value="r.value">{{ r.label }}</option>
      </select>
      <select v-model="filterLocation" class="filter-select">
        <option value="">All locations</option>
        <option v-for="l in locations" :key="l.id" :value="l.id">{{ l.name }}</option>
      </select>
    </div>

    <!-- Empty state -->
    <div v-if="!team.length" class="team-empty">
      <Users :size="36" style="opacity:0.25" />
      <div class="team-empty-title">No team members yet</div>
      <div class="team-empty-sub">Invite facility admins, senior and junior employees to collaborate on your operations.</div>
      <button class="btn btn-primary" @click="openInvite"><Plus :size="14" />Invite your first member</button>
    </div>

    <!-- Team grid -->
    <div v-else-if="filtered.length" class="team-grid">
      <div v-for="u in paginatedTeam" :key="u.id" class="team-card">
        <div class="team-card-header">
          <div class="team-avatar" :style="`background:${avatarColor(u.id)}`">{{ avatarInitials(u.full_name) }}</div>
          <div class="team-card-info">
            <div class="team-name">{{ u.full_name || '—' }}</div>
            <div class="team-email">{{ u.email }}</div>
          </div>
          <div class="team-card-menu">
            <button class="btn-icon" @click="openEdit(u)" title="Edit"><Edit2 :size="15" /></button>
            <button class="btn-icon danger" @click="remove(u)" title="Remove"><Trash2 :size="15" /></button>
          </div>
        </div>

        <div class="team-card-body">
          <span class="role-pill" :style="`background:${roleInfo(u.role).bg}; color:${roleInfo(u.role).color}; border-color:${roleInfo(u.role).color}22`">
            <Shield v-if="u.role === 'facility_admin'" :size="11" />
            <UserCog v-else-if="u.role === 'senior_employee'" :size="11" />
            <User v-else :size="11" />
            {{ roleInfo(u.role).label }}
          </span>

          <span v-if="u.status !== 'active'" class="status-pill status-inactive">Inactive</span>
        </div>

        <div class="team-card-footer">
          <div class="team-meta">
            <MapPin :size="12" />
            <span>{{ u.location_id ? locationName(u.location_id) : 'All locations' }}</span>
          </div>
          <div v-if="u.phone" class="team-meta">
            <span>{{ u.phone }}</span>
          </div>
        </div>
      </div>
    </div>

    <PaginationBar
      v-if="filtered.length > 0"
      :page="teamPage" :perPage="teamPerPage" :totalPages="teamTotalPages"
      :total="filtered.length" :visiblePages="teamVisiblePages"
      @update:perPage="teamPerPage = $event"
      @goTo="teamGoTo"
    />

    <div v-if="team.length && !filtered.length" class="card empty" style="text-align:center; padding:32px;">No team members match your filters.</div>

    <!-- Modal -->
    <div v-if="showModal" class="modal-backdrop" @click.self="showModal = false">
      <div class="modal" style="max-width:520px;">
        <div class="modal-header">
          <div class="modal-title">{{ editing ? 'Edit Member' : 'Invite Team Member' }}</div>
          <button class="btn-icon" @click="showModal = false"><X :size="18" /></button>
        </div>
        <div class="modal-body">

          <!-- Role selector -->
          <div class="form-group" style="margin-bottom:20px;">
            <label class="form-label">Role</label>
            <div class="role-selector">
              <label v-for="r in TEAM_ROLES" :key="r.value" class="role-option" :class="{ selected: form.role === r.value }">
                <input type="radio" :value="r.value" v-model="form.role" style="display:none;" />
                <div class="role-option-icon" :style="form.role === r.value ? `background:${r.bg}; color:${r.color}` : ''">
                  <Shield v-if="r.value === 'facility_admin'" :size="16" />
                  <UserCog v-else-if="r.value === 'senior_employee'" :size="16" />
                  <User v-else :size="16" />
                </div>
                <div>
                  <div class="role-option-label">{{ r.label }}</div>
                  <div class="role-option-desc">{{ r.desc }}</div>
                </div>
              </label>
            </div>
          </div>

          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Full name <span class="required">*</span></label>
              <input v-model="form.full_name" placeholder="Jane Smith" />
            </div>
            <div class="form-group">
              <label class="form-label">Email <span class="required">*</span></label>
              <input v-model="form.email" type="email" placeholder="jane@example.com" />
            </div>
          </div>

          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Phone</label>
              <input v-model="form.phone" placeholder="+971 50 123 4567" />
            </div>
            <div class="form-group">
              <label class="form-label">Assigned location</label>
              <select v-model="form.location_id">
                <option value="">All locations (no restriction)</option>
                <option v-for="l in locations" :key="l.id" :value="l.id">{{ l.name }}</option>
              </select>
            </div>
          </div>

          <div class="form-group">
            <label class="form-label">Status</label>
            <select v-model="form.status">
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
            </select>
          </div>

          <!-- Permission summary -->
          <div class="perm-summary">
            <div class="perm-summary-title">Access summary for {{ roleInfo(form.role).label }}</div>
            <div class="perm-grid">
              <template v-if="form.role === 'facility_admin'">
                <div class="perm-item yes">Manage bookings & listings</div>
                <div class="perm-item yes">View & manage customers</div>
                <div class="perm-item yes">View payments & reports</div>
                <div class="perm-item yes">Manage junior staff</div>
                <div class="perm-item no">Edit business settings</div>
                <div class="perm-item no">Access wallet / payouts</div>
              </template>
              <template v-else-if="form.role === 'senior_employee'">
                <div class="perm-item yes">Manage bookings</div>
                <div class="perm-item yes">View customers</div>
                <div class="perm-item yes">View payments</div>
                <div class="perm-item no">Edit listings</div>
                <div class="perm-item no">Manage staff</div>
                <div class="perm-item no">Access wallet / payouts</div>
              </template>
              <template v-else>
                <div class="perm-item yes">View bookings</div>
                <div class="perm-item yes">Check-in customers</div>
                <div class="perm-item no">Edit bookings</div>
                <div class="perm-item no">View payments</div>
                <div class="perm-item no">Manage staff</div>
                <div class="perm-item no">Access wallet / payouts</div>
              </template>
            </div>
          </div>

        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" @click="showModal = false">Cancel</button>
          <button class="btn btn-primary" :disabled="!form.full_name || !form.email || saving" @click="save">
            {{ editing ? 'Save changes' : 'Invite member' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
/* Role overview cards */
.role-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 14px; margin-bottom: 24px; }
.role-card { background: var(--surface, #ffffff); border: 1px solid var(--border); border-radius: 12px; padding: 18px; }
.role-card-top { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px; }
.role-card-icon { width: 38px; height: 38px; border-radius: 10px; display: flex; align-items: center; justify-content: center; }
.role-card-count { font-size: 28px; font-weight: 800; line-height: 1; }
.role-card-title { font-size: 14px; font-weight: 700; color: var(--text); margin-bottom: 4px; }
.role-card-desc { font-size: 12px; color: var(--text-muted); line-height: 1.5; }

/* Toolbar */
.filter-select { padding: 8px 10px; border: 1px solid var(--border); border-radius: 8px; background: var(--surface, #ffffff); font-size: 13px; color: var(--text); cursor: pointer; }

/* Empty */
.team-empty { display: flex; flex-direction: column; align-items: center; gap: 10px; padding: 64px 24px; text-align: center; background: var(--surface, #ffffff); border: 1px solid var(--border); border-radius: 12px; }
.team-empty-title { font-size: 16px; font-weight: 700; color: var(--text); }
.team-empty-sub { font-size: 13px; color: var(--text-muted); max-width: 380px; }

/* Team grid */
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

/* Role selector in modal */
.role-selector { display: flex; flex-direction: column; gap: 8px; }
.role-option { display: flex; align-items: flex-start; gap: 12px; padding: 12px 14px; border: 1.5px solid var(--border); border-radius: 10px; cursor: pointer; transition: all 0.15s; }
.role-option:hover { border-color: var(--primary); }
.role-option.selected { border-color: var(--primary); background: #f0f9ff; }
.role-option-icon { width: 34px; height: 34px; border-radius: 8px; background: #f1f5f9; color: var(--text-muted); display: flex; align-items: center; justify-content: center; flex-shrink: 0; transition: all 0.15s; }
.role-option-label { font-size: 13.5px; font-weight: 700; color: var(--text); }
.role-option-desc { font-size: 12px; color: var(--text-muted); margin-top: 2px; }

/* Permissions summary */
.perm-summary { background: #f8fafc; border: 1px solid var(--border); border-radius: 10px; padding: 14px; }
.perm-summary-title { font-size: 12px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 10px; }
.perm-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; }
.perm-item { font-size: 12.5px; padding-left: 18px; position: relative; }
.perm-item::before { content: ''; position: absolute; left: 0; top: 3px; width: 10px; height: 10px; border-radius: 50%; }
.perm-item.yes { color: #065f46; }
.perm-item.yes::before { background: #059669; }
.perm-item.no { color: var(--text-light, #94a3b8); }
.perm-item.no::before { background: #cbd5e1; }

.required { color: #dc2626; }
.modal-footer { display: flex; justify-content: flex-end; gap: 10px; padding: 16px 24px; border-top: 1px solid var(--border); }

/* Phone: match global bottom-sheet spacing (scoped rules outweigh style.css @600px) */
@media (max-width: 600px) {
  .modal-footer { padding: 14px 18px; gap: 8px; }
  .modal { max-width: 100% !important; } /* beat inline max-width:520px so the sheet spans full width */
}
</style>
