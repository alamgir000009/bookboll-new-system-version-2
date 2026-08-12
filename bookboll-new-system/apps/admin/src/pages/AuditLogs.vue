<script setup>
import { ref, onMounted, computed } from 'vue'
import { supabase } from '../lib/supabase.js'
import { Search, Download, Activity, User, X } from 'lucide-vue-next'
import PaginationBar from '../components/PaginationBar.vue'
import { usePagination } from '../lib/usePagination.js'
import { downloadCSV } from '../lib/useExport.js'

const logs = ref([])
const search = ref('')
const actionFilter = ref('')
const entityFilter = ref('')
const dateFrom = ref('')
const dateTo = ref('')

async function load() {
  const { data } = await supabase.from('audit_logs').select('*').order('created_at', { ascending: false }).limit(2000)
  logs.value = data || []
}
onMounted(load)

const uniqueActions = computed(() => [...new Set(logs.value.map(l => l.action).filter(Boolean))].sort())
const uniqueEntities = computed(() => [...new Set(logs.value.map(l => l.entity_type).filter(Boolean))].sort())

const filtered = computed(() => {
  const q = search.value.toLowerCase().trim()
  const from = dateFrom.value ? new Date(dateFrom.value) : null
  const to = dateTo.value ? new Date(dateTo.value + 'T23:59:59') : null
  return logs.value.filter(l => {
    if (q && !(
      (l.actor || '').toLowerCase().includes(q) ||
      (l.action || '').toLowerCase().includes(q) ||
      (l.details || '').toLowerCase().includes(q) ||
      (l.entity_type || '').toLowerCase().includes(q)
    )) return false
    if (actionFilter.value && l.action !== actionFilter.value) return false
    if (entityFilter.value && l.entity_type !== entityFilter.value) return false
    const d = new Date(l.created_at)
    if (from && d < from) return false
    if (to && d > to) return false
    return true
  })
})

const activeFilters = computed(() =>
  (actionFilter.value ? 1 : 0) + (entityFilter.value ? 1 : 0) + (dateFrom.value ? 1 : 0) + (dateTo.value ? 1 : 0))
function clearFilters() { search.value = ''; actionFilter.value = ''; entityFilter.value = ''; dateFrom.value = ''; dateTo.value = '' }

function exportLogs() {
  downloadCSV(filtered.value, [
    { label: 'Time',      fn: r => new Date(r.created_at).toLocaleString() },
    { label: 'Actor',     key: 'actor' },
    { label: 'Role',      fn: r => roleLabel(r.role) },
    { label: 'Action',    key: 'action' },
    { label: 'Entity',    key: 'entity_type' },
    { label: 'Entity ID', key: 'entity_id' },
    { label: 'Details',   key: 'details' },
  ], 'audit-logs')
}

const actionColor = (a) => ({
  created: 'badge-info', updated: 'badge-warning', deleted: 'badge-error',
  approved: 'badge-success', rejected: 'badge-error', changes_requested: 'badge-warning',
  enabled: 'badge-success', disabled: 'badge-neutral', booked: 'badge-info',
}[a] || 'badge-neutral')
const actionLabel = (a) => (a || '').replace(/_/g, ' ')
const roleLabel = (r) => ({
  super_admin: 'Super Admin', country_admin: 'Country Admin', platform_staff: 'Platform Staff',
  provider_admin: 'Owner', facility_admin: 'Manager', provider_supervisor: 'Supervisor',
  staff: 'Staff', provider_viewer: 'Viewer',
}[r] || r || '—')

const { page: logPage, perPage: logPerPage, totalPages: logTotalPages, paginated: paginatedLogs, visiblePages: logVisiblePages, goTo: logGoTo } = usePagination(filtered)
</script>

<template>
  <div class="page">
    <div class="page-header">
      <div>
        <div class="page-title">Audit Logs</div>
        <div class="page-subtitle">Complete record of admin actions for accountability</div>
      </div>
      <button class="btn btn-secondary" @click="exportLogs"><Download :size="16" />Export</button>
    </div>

    <div class="card">
      <!-- Toolbar -->
      <div class="al-toolbar">
        <div class="search"><Search :size="16" /><input v-model="search" placeholder="Search actor, action, details…" /></div>
        <select v-model="actionFilter" class="al-select">
          <option value="">All actions</option>
          <option v-for="a in uniqueActions" :key="a" :value="a">{{ actionLabel(a) }}</option>
        </select>
        <select v-model="entityFilter" class="al-select">
          <option value="">All entities</option>
          <option v-for="e in uniqueEntities" :key="e" :value="e">{{ e.replace(/_/g, ' ') }}</option>
        </select>
        <input type="date" v-model="dateFrom" class="al-date" title="From" />
        <input type="date" v-model="dateTo" class="al-date" title="To" />
        <button v-if="activeFilters || search" class="btn btn-secondary btn-sm" @click="clearFilters"><X :size="13" />Clear</button>
        <span class="al-count">{{ filtered.length }} event{{ filtered.length === 1 ? '' : 's' }}</span>
      </div>

      <div class="table-wrap">
        <table>
          <thead>
            <tr><th>Time</th><th>Actor</th><th>Role</th><th>Action</th><th>Entity</th><th>Details</th></tr>
          </thead>
          <tbody>
            <tr v-for="l in paginatedLogs" :key="l.id">
              <td style="color: var(--text-muted); font-size: 13px; white-space: nowrap;">{{ new Date(l.created_at).toLocaleString() }}</td>
              <td>
                <div class="cell-main">
                  <div class="log-avatar"><User :size="12" /></div>
                  <div style="font-weight: 500;">{{ l.actor }}</div>
                </div>
              </td>
              <td><span class="badge badge-neutral">{{ roleLabel(l.role) }}</span></td>
              <td><span class="badge" :class="actionColor(l.action)"><Activity :size="11" />{{ actionLabel(l.action) }}</span></td>
              <td style="white-space: nowrap;">{{ (l.entity_type || '—').replace(/_/g, ' ') }}</td>
              <td style="font-size: 13px; color: var(--text);">{{ l.details }}</td>
            </tr>
            <tr v-if="!filtered.length"><td colspan="6" class="empty">No audit events match your filters</td></tr>
          </tbody>
        </table>
      </div>
      <PaginationBar
        :page="logPage" :perPage="logPerPage" :totalPages="logTotalPages"
        :total="filtered.length" :visiblePages="logVisiblePages"
        @update:perPage="logPerPage = $event"
        @goTo="logGoTo"
      />
    </div>
  </div>
</template>

<style scoped>
.al-toolbar { display: flex; align-items: center; gap: 10px; padding: 14px 20px; border-bottom: 1px solid var(--border); flex-wrap: wrap; }
.al-toolbar .search { flex: 1; min-width: 220px; }
.al-select, .al-date { height: 36px; padding: 0 10px; border: 1px solid var(--border); border-radius: 8px; background: var(--card); font-size: 13px; color: var(--text); }
.al-date { width: 140px; }
.al-count { margin-left: auto; font-size: 12px; color: var(--text-muted); white-space: nowrap; }
.mono { font-family: 'SF Mono', Monaco, monospace; color: var(--text-muted); }
.cell-main { display: flex; align-items: center; gap: 8px; }
.log-avatar { width: 26px; height: 26px; border-radius: 50%; background: var(--bg); color: var(--text-muted); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }

@media (max-width: 600px) {
  .al-toolbar { gap: 8px; padding: 12px 14px; }
  .al-toolbar .search { flex: 1 1 100%; min-width: 0; }
  .al-select { flex: 1 1 calc(50% - 4px); min-width: 0; font-size: 16px; height: 44px; }
  .al-date { flex: 1 1 calc(50% - 4px); width: auto; min-width: 0; font-size: 16px; height: 44px; }
  .al-count { flex-basis: 100%; margin-left: 0; }
}
</style>
