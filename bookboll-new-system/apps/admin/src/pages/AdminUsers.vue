<script setup>
import { ref, onMounted, computed } from 'vue'
import { supabase } from '../lib/supabase.js'
import { askConfirm } from '../lib/confirm.js'
import { pushToast } from '../lib/toast.js'
import { Plus, Trash2, Shield, X, Pencil as Edit2, Building2, Search, AtSign } from 'lucide-vue-next'
import { useCurrentUser } from '../lib/currentUser.js'
import PaginationBar from '../components/PaginationBar.vue'
import { usePagination } from '../lib/usePagination.js'

const users = ref([])
const countries = ref([])
const cities = ref([])
const locations = ref([])
const showModal = ref(false)
const editing = ref(null)
const form = ref(defaultForm())
const search = ref('')
const formError = ref('')

const { loadUserContext, isSuperAdmin, currentOwnerUserId } = useCurrentUser()

const isProviderScopedRole = (r) => ['facility_admin', 'senior_employee', 'junior_employee', 'guard', 'staff'].includes(r)

function defaultForm() {
  return {
    full_name: '', email: '', role: 'staff', country_id: '', location_id: '', owner_user_id: '', status: 'active',
    username: '', phone: '', city_id: '', commission_rate: 15, business_status: 'approved', description: '', license_number: '',
  }
}

async function load() {
  const [u, c, ci, l] = await Promise.all([
    supabase.from('admin_users').select('*').order('created_at', { ascending: false }),
    supabase.from('countries').select('*').eq('status', 'active').order('name'),
    supabase.from('cities').select('*').eq('status', 'active').order('name'),
    supabase.from('locations').select('*'),
  ])
  users.value = u.data || []
  countries.value = c.data || []
  cities.value = ci.data || []
  locations.value = l.data || []
}
onMounted(load)

const roleLabel = (r) => ({ provider_admin: 'Provider Admin', facility_admin: 'Facility Admin', senior_employee: 'Senior Employee', junior_employee: 'Junior Employee', guard: 'Guard', country_admin: 'Country Admin', super_admin: 'Super Admin', staff: 'Staff' }[r] || r)
const roleBadge = (r) => ({ super_admin: 'badge-error', country_admin: 'badge-warning', provider_admin: 'badge-info', facility_admin: 'badge-info', senior_employee: 'badge-warning', junior_employee: 'badge-neutral', guard: 'badge-neutral' }[r] || 'badge-neutral')
const locationName = (id) => locations.value.find(l => l.id === id)?.name
const countryName = (id) => countries.value.find(c => c.id === id)?.name
const ownerUsername = (id) => {
  const owner = users.value.find(u => u.id === id)
  return owner?.username ? `@${owner.username}` : owner?.full_name || '—'
}

function scopeText(u) {
  if (u.role === 'provider_admin') return u.username ? `@${u.username}` : '—'
  if (u.location_id) return locationName(u.location_id) || '—'
  if (u.owner_user_id) return ownerUsername(u.owner_user_id)
  if (u.country_id) return countryName(u.country_id)
  return 'Global'
}

const businessOwners = computed(() => users.value.filter(u => u.role === 'provider_admin'))

const filteredUsers = computed(() => {
  const q = search.value.toLowerCase()
  if (!q) return users.value
  return users.value.filter(u =>
    (u.full_name || '').toLowerCase().includes(q) ||
    (u.email || '').toLowerCase().includes(q) ||
    (u.username || '').toLowerCase().includes(q) ||
    roleLabel(u.role).toLowerCase().includes(q)
  )
})

const { page: auPage, perPage: auPerPage, totalPages: auTotalPages, paginated: paginatedUsers, visiblePages: auVisiblePages, goTo: auGoTo } = usePagination(filteredUsers)

const statusBadge = (s) => ({ approved: 'badge-success', pending: 'badge-warning', rejected: 'badge-error' }[s] || 'badge-neutral')

function openNew() {
  editing.value = null
  form.value = defaultForm()
  formError.value = ''
  if (!isSuperAdmin.value && currentOwnerUserId.value) {
    form.value.owner_user_id = currentOwnerUserId.value
  }
  showModal.value = true
}
function openEdit(u) {
  editing.value = u
  formError.value = ''
  form.value = {
    full_name: u.full_name || '',
    email: u.email || '',
    role: u.role || 'staff',
    country_id: u.country_id || '',
    location_id: u.location_id || '',
    owner_user_id: u.owner_user_id || '',
    status: u.status || 'active',
    username: u.username || '',
    phone: u.phone || '',
    city_id: u.city_id || '',
    commission_rate: u.commission_rate ?? 15,
    business_status: u.business_status || 'pending',
    description: u.description || '',
    license_number: u.license_number || '',
  }
  showModal.value = true
}

async function save() {
  formError.value = ''
  const isOwner = form.value.role === 'provider_admin'
  const isInternal = ['super_admin', 'country_admin'].includes(form.value.role)

  if (isOwner && !editing.value && !form.value.username.trim()) {
    formError.value = 'Company Login Username is required.'
    return
  }
  if (isOwner && !form.value.country_id) {
    formError.value = 'Country is required for a provider account — it locks the wallet currency.'
    return
  }

  const resolvedOwnerId = isOwner ? null
    : isInternal ? null
    : (form.value.owner_user_id || currentOwnerUserId.value || null)

  // Resolve the username for team members from the selected company
  let resolvedUsername = null
  if (isOwner) {
    resolvedUsername = editing.value ? undefined : form.value.username.trim().toLowerCase().replace(/\s+/g, '')
  } else if (!isInternal && resolvedOwnerId) {
    const owner = users.value.find(u => u.id === resolvedOwnerId)
    resolvedUsername = owner?.username || null
  }

  const payload = {
    full_name: form.value.full_name,
    email: form.value.email,
    role: form.value.role,
    country_id: form.value.country_id || null,
    location_id: form.value.location_id || null,
    owner_user_id: resolvedOwnerId,
    status: form.value.status,
    phone: isOwner ? form.value.phone : null,
    city_id: isOwner ? (form.value.city_id || null) : null,
    commission_rate: isOwner ? (Number(form.value.commission_rate) || 15) : null,
    business_status: isOwner ? (form.value.business_status || 'pending') : null,
    description: isOwner ? form.value.description : null,
    license_number: isOwner ? form.value.license_number : null,
  }

  // Only set username on insert for provider_admin (locked after creation), or propagate to team members
  if (resolvedUsername !== undefined) {
    payload.username = resolvedUsername
  }

  if (editing.value) await supabase.from('admin_users').update(payload).eq('id', editing.value.id)
  else await supabase.from('admin_users').insert(payload)

  showModal.value = false
  await load()
  await loadUserContext()
}

async function remove(id) {
  if (!(await askConfirm({
    title: 'Delete user?',
    message: 'This user will be removed. If they own a business, all their locations and wallet data will be removed too. This cannot be undone.',
    confirmText: 'Delete',
  }))) return
  const { error } = await supabase.from('admin_users').delete().eq('id', id)
  if (error) { pushToast('Could not delete user.', 'error'); return }
  pushToast('User deleted', 'success')
  load()
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
</script>

<template>
  <div class="page">
    <div class="page-header">
      <div>
        <div class="page-title">Users</div>
        <div class="page-subtitle">Team members and company owners. A Provider Admin user represents a company.</div>
      </div>
      <button class="btn btn-primary" @click="openNew"><Plus :size="16" />Invite User</button>
    </div>

    <div class="card">
      <div class="toolbar" style="padding: 16px 20px; margin: 0;">
        <div class="search"><Search :size="16" /><input v-model="search" placeholder="Search users or @username..." /></div>
      </div>

      <div class="table-wrap">
        <table>
          <thead>
            <tr><th>Name</th><th>Email</th><th>Role</th><th>Scope</th><th>Business Status</th><th>Status</th><th>Last Login</th><th></th></tr>
          </thead>
          <tbody>
            <tr v-for="u in paginatedUsers" :key="u.id">
              <td>
                <div class="cell-main">
                  <div v-if="u.role === 'provider_admin'" class="mini-avatar"><Building2 :size="15" /></div>
                  <div v-else class="avatar-sm">{{ (u.full_name||'?').split(' ').map(n=>n[0]).slice(0,2).join('') }}</div>
                  <div>
                    <div style="font-weight: 500;">{{ u.full_name }}</div>
                    <div v-if="u.role === 'provider_admin' && u.username" style="font-size:12px; color: var(--text-muted);">@{{ u.username }}</div>
                  </div>
                </div>
              </td>
              <td>{{ u.email }}</td>
              <td><span class="badge" :class="roleBadge(u.role)"><Shield :size="11" />{{ roleLabel(u.role) }}</span></td>
              <td>{{ scopeText(u) }}</td>
              <td>
                <span v-if="u.role === 'provider_admin'" class="badge" :class="statusBadge(u.business_status)">{{ u.business_status }}</span>
                <span v-else style="color: var(--text-muted);">—</span>
              </td>
              <td><span class="badge" :class="u.status==='active'?'badge-success':'badge-neutral'">{{ u.status }}</span></td>
              <td style="color: var(--text-muted);">{{ timeAgo(u.last_login) }}</td>
              <td>
                <div class="row-actions">
                  <button class="btn-icon" @click="openEdit(u)"><Edit2 :size="15" /></button>
                  <button class="btn-icon danger" @click="remove(u.id)"><Trash2 :size="15" /></button>
                </div>
              </td>
            </tr>
            <tr v-if="!filteredUsers.length"><td colspan="8" class="empty">No users yet</td></tr>
          </tbody>
        </table>
      </div>
      <PaginationBar
        :page="auPage" :perPage="auPerPage" :totalPages="auTotalPages"
        :total="filteredUsers.length" :visiblePages="auVisiblePages"
        @update:perPage="auPerPage = $event"
        @goTo="auGoTo"
      />
    </div>

    <div v-if="showModal" class="modal-backdrop" @click.self="showModal=false">
      <div class="modal">
        <div class="modal-header">
          <div class="modal-title">{{ editing ? 'Edit User' : 'Invite User' }}</div>
          <button class="btn-icon" @click="showModal=false"><X :size="18" /></button>
        </div>
        <div class="modal-body">
          <div class="grid grid-2">
            <div class="form-group"><label class="form-label">Full Name</label><input v-model="form.full_name" /></div>
            <div class="form-group"><label class="form-label">Email</label><input v-model="form.email" /></div>
          </div>
          <div class="form-group"><label class="form-label">Role</label>
            <select v-model="form.role">
              <option value="super_admin">Super Admin</option>
              <option value="country_admin">Country Admin</option>
              <option value="provider_admin">Provider Admin (owns a company)</option>
              <option value="facility_admin">Facility Admin</option>
              <option value="senior_employee">Senior Employee</option>
              <option value="junior_employee">Junior Employee</option>
              <option value="guard">Guard</option>
            </select>
          </div>

          <div v-if="form.role === 'provider_admin'" class="inline-business">
            <div class="inline-business-header"><Building2 :size="14" />Company details</div>

            <div class="form-group">
              <label class="form-label">Company Login Username <span class="required">*</span></label>
              <div class="username-input-wrap">
                <span class="username-prefix"><AtSign :size="14" /></span>
                <input
                  v-model="form.username"
                  :readonly="!!editing"
                  :class="{ 'input-locked': !!editing }"
                  placeholder="e.g. dsc"
                  style="padding-left: 34px;"
                />
              </div>
              <div v-if="editing" class="field-hint">Username is locked after creation.</div>
            </div>

            <div class="form-group">
              <label class="form-label">Country <span class="required">*</span></label>
              <select v-model="form.country_id" :disabled="!!editing">
                <option value="">Select country</option>
                <option v-for="c in countries" :key="c.id" :value="c.id">{{ c.name }}</option>
              </select>
              <div v-if="editing" class="field-hint">Country is locked after creation — it sets the wallet currency.</div>
              <div v-else class="field-hint">Locks the provider's wallet to that country's currency (e.g. AED, QAR, EGP).</div>
            </div>

            <div class="grid grid-2">
              <div class="form-group"><label class="form-label">Commission %</label><input type="number" v-model="form.commission_rate" /></div>
              <div class="form-group"><label class="form-label">Account Status</label>
                <input :value="form.business_status" readonly class="input-locked" />
              </div>
            </div>
            <div class="form-group"><label class="form-label">Description</label><textarea v-model="form.description" rows="2"></textarea></div>
          </div>

          <div v-else-if="isProviderScopedRole(form.role)">
            <div v-if="isSuperAdmin" class="form-group">
              <label class="form-label">Company</label>
              <select v-model="form.owner_user_id">
                <option value="">@bookboll</option>
                <option v-for="b in businessOwners" :key="b.id" :value="b.id">@{{ b.username || b.full_name }}</option>
              </select>
            </div>
            <div v-else-if="form.owner_user_id" class="assigned-chip" style="margin-bottom:16px;">
              <AtSign :size="13" />Company: <strong>{{ businessOwners.find(b => b.id === form.owner_user_id)?.username || '—' }}</strong>
            </div>
            <div class="form-group">
              <label class="form-label">Location (optional)</label>
              <select v-model="form.location_id">
                <option value="">— Any location</option>
                <option v-for="l in locations.filter(x => !form.owner_user_id || x.owner_user_id === form.owner_user_id)" :key="l.id" :value="l.id">{{ l.name }}</option>
              </select>
            </div>
          </div>

          <div class="grid grid-2" v-if="form.role !== 'provider_admin'">
            <div class="form-group"><label class="form-label">Country</label>
              <select v-model="form.country_id"><option value="">—</option><option v-for="c in countries" :key="c.id" :value="c.id">{{ c.name }}</option></select>
            </div>
            <div class="form-group"><label class="form-label">Status</label>
              <select v-model="form.status"><option>active</option><option>inactive</option></select>
            </div>
          </div>
          <div class="form-group" v-if="form.role === 'provider_admin'">
            <label class="form-label">Status</label>
            <select v-model="form.status"><option>active</option><option>inactive</option></select>
          </div>

          <div v-if="formError" class="form-error">{{ formError }}</div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" @click="showModal=false">Cancel</button>
          <button class="btn btn-primary" @click="save">Save</button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.cell-main { display: flex; align-items: center; gap: 10px; }
.avatar-sm { width: 34px; height: 34px; border-radius: 50%; background: linear-gradient(135deg, #0f766e, #14b8a6); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 12px; }
.mini-avatar { width: 34px; height: 34px; border-radius: 8px; background: #ccfbf1; color: var(--primary); display: flex; align-items: center; justify-content: center; }
.row-actions { display: flex; gap: 4px; }
.btn-icon { padding: 7px; border-radius: 6px; color: var(--text-muted); display: inline-flex; }
.btn-icon:hover { background: var(--bg); color: var(--text); }
.btn-icon.danger:hover { background: #fee2e2; color: var(--error); }
.btn-icon.ok:hover { background: #d1fae5; color: #065f46; }
.inline-business { margin: 12px 0; padding: 14px; border: 1px dashed var(--border); border-radius: 10px; background: #f8fafc; }
.assigned-chip { display: inline-flex; align-items: center; gap: 6px; padding: 8px 12px; background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; border-radius: 8px; font-size: 13px; margin-bottom: 14px; }
.inline-business-header { font-weight: 600; font-size: 13px; display: flex; align-items: center; gap: 6px; margin-bottom: 10px; color: var(--text); }
.username-input-wrap { position: relative; }
.username-prefix { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: var(--text-muted); display: flex; align-items: center; pointer-events: none; }
.input-locked { background: #f1f5f9; color: var(--text-muted); cursor: not-allowed; }
.field-hint { font-size: 12px; color: var(--text-muted); margin-top: 4px; }
.required { color: #dc2626; }
.form-error { margin-top: 12px; padding: 10px 14px; background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; color: #991b1b; font-size: 13px; }
</style>
