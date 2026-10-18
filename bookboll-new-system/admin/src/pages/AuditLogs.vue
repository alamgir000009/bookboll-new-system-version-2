<script setup>
/**
 * Audit Logs — Laravel /api/admin/audit-logs (Sanctum).
 * Maps backend fields (timestamp, resource, actor) into the existing table UI.
 */
import { ref, onMounted, computed, watch } from 'vue'
import { apiFetch } from '../lib/api.js'
import { Search, Download, Activity, User, X, Loader } from 'lucide-vue-next'
import PaginationBar from '../components/PaginationBar.vue'
import { pushToast } from '../lib/toast.js'

const logs = ref([])
const stats = ref({ total_logs: 0, todays_logs: 0, action_types: 0, unique_actors: 0 })
const uniqueActions = ref([])
const uniqueEntities = ref([])
const loading = ref(false)
const exporting = ref(false)

const search = ref('')
const actionFilter = ref('')
const entityFilter = ref('')
const dateFrom = ref('')
const dateTo = ref('')

const logPage = ref(1)
const logPerPage = ref(25)
const logTotal = ref(0)
const logTotalPages = computed(() => Math.max(1, Math.ceil(logTotal.value / logPerPage.value) || 1))

const logVisiblePages = computed(() => {
  const total = logTotalPages.value
  const cur = logPage.value
  if (total <= 7) return Array.from({ length: total }, (_, i) => i + 1)
  const pages = new Set([1, total, cur])
  if (cur > 1) pages.add(cur - 1)
  if (cur < total) pages.add(cur + 1)
  return Array.from(pages).sort((a, b) => a - b)
})

function mapLog(row) {
  const actorUser = row.actor || null
  const roles = actorUser?.roles_array || actorUser?.roles || []
  const roleName = Array.isArray(roles)
    ? (typeof roles[0] === 'string' ? roles[0] : roles[0]?.name) || ''
    : ''

  return {
    id: row.id,
    created_at: row.timestamp || row.created_at,
    actor: actorUser?.name || actorUser?.email || row.actor_email || 'System',
    actor_email: actorUser?.email || row.actor_email || '',
    role: roleName,
    action: row.action || '',
    entity_type: row.resource || '',
    entity_id: row.resource_id || '',
    details: row.details || '',
    ip_address: row.ip_address || '',
    device: row.device || '',
  }
}

let searchTimer
async function load() {
  loading.value = true
  const res = await apiFetch('/admin/audit-logs', {
    query: {
      page: logPage.value,
      records_per_page: logPerPage.value,
      search: search.value.trim() || undefined,
      action: actionFilter.value || undefined,
      resource: entityFilter.value || undefined,
      date_from: dateFrom.value || undefined,
      date_to: dateTo.value || undefined,
    },
  })
  loading.value = false

  if (!res.success) {
    pushToast(res.message || 'Could not load audit logs.', 'error')
    logs.value = []
    logTotal.value = 0
    return
  }

  const paginator = res.data?.logs
  const rows = Array.isArray(paginator?.data) ? paginator.data : (Array.isArray(paginator) ? paginator : [])
  logs.value = rows.map(mapLog)
  logTotal.value = Number(paginator?.total ?? rows.length) || 0
  if (paginator?.current_page) logPage.value = Number(paginator.current_page)

  if (res.data?.stats) stats.value = res.data.stats
  const opts = res.data?.filterOptions || {}
  if (Array.isArray(opts.actions)) uniqueActions.value = opts.actions
  if (Array.isArray(opts.resources)) uniqueEntities.value = opts.resources
}

function scheduleLoad() {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    logPage.value = 1
    load()
  }, 300)
}

watch([actionFilter, entityFilter, dateFrom, dateTo], () => {
  logPage.value = 1
  load()
})
watch(search, () => scheduleLoad())
watch(logPerPage, () => {
  logPage.value = 1
  load()
})

function logGoTo(p) {
  logPage.value = p
  load()
}

const activeFilters = computed(() =>
  (actionFilter.value ? 1 : 0) + (entityFilter.value ? 1 : 0) + (dateFrom.value ? 1 : 0) + (dateTo.value ? 1 : 0))

function clearFilters() {
  search.value = ''
  actionFilter.value = ''
  entityFilter.value = ''
  dateFrom.value = ''
  dateTo.value = ''
  logPage.value = 1
  load()
}

async function exportLogs() {
  exporting.value = true
  const res = await apiFetch('/admin/audit-logs/export', {
    query: {
      action: actionFilter.value || undefined,
      date_from: dateFrom.value || undefined,
      date_to: dateTo.value || undefined,
    },
  })
  exporting.value = false
  if (!res.success || !res.data?.download_url) {
    pushToast(res.message || 'Export failed.', 'error')
    return
  }
  window.open(res.data.download_url, '_blank', 'noopener')
}

const actionColor = (a) => {
  const key = String(a || '').toLowerCase()
  if (/creat|store|add|book/.test(key)) return 'badge-info'
  if (/updat|edit|chang|request/.test(key)) return 'badge-warning'
  if (/delet|reject|remov|cancel|disable|suspend/.test(key)) return 'badge-error'
  if (/approv|enable|confirm|success/.test(key)) return 'badge-success'
  return 'badge-neutral'
}
const actionLabel = (a) => (a || '').replace(/_/g, ' ')
const roleLabel = (r) => ({
  super_admin: 'Super Admin', country_admin: 'Country Admin', platform_staff: 'Platform Staff',
  admin: 'Admin', provider_admin: 'Owner', facility_admin: 'Manager', provider_supervisor: 'Supervisor',
  staff: 'Staff', provider_viewer: 'Viewer',
  senior_employee: 'Senior Employee', junior_employee: 'Junior Employee', guard: 'Guard',
}[r] || r || '—')

onMounted(load)
</script>

<template>
  <div class="page">
    <div class="page-header">
      <div>
        <h1 class="page-title">Audit Logs</h1>
        <div class="page-subtitle">Complete record of admin actions for accountability</div>
      </div>
      <button class="btn btn-secondary" :disabled="exporting" @click="exportLogs">
        <Loader v-if="exporting" :size="16" class="spin" />
        <Download v-else :size="16" />
        Export
      </button>
    </div>

    <div class="al-stats" v-if="stats">
      <div class="al-stat"><span class="al-stat-n">{{ stats.total_logs }}</span><span class="al-stat-l">Total</span></div>
      <div class="al-stat"><span class="al-stat-n">{{ stats.todays_logs }}</span><span class="al-stat-l">Today</span></div>
      <div class="al-stat"><span class="al-stat-n">{{ stats.action_types }}</span><span class="al-stat-l">Actions</span></div>
      <div class="al-stat"><span class="al-stat-n">{{ stats.unique_actors }}</span><span class="al-stat-l">Actors</span></div>
    </div>

    <div class="card">
      <div class="al-toolbar">
        <div class="search"><Search :size="16" /><input v-model="search" placeholder="Search actor, action, details…" /></div>
        <select v-model="actionFilter" class="al-select">
          <option value="">All actions</option>
          <option v-for="a in uniqueActions" :key="a" :value="a">{{ actionLabel(a) }}</option>
        </select>
        <select v-model="entityFilter" class="al-select">
          <option value="">All entities</option>
          <option v-for="e in uniqueEntities" :key="e" :value="e">{{ String(e).replace(/_/g, ' ') }}</option>
        </select>
        <input type="date" v-model="dateFrom" class="al-date" title="From" />
        <input type="date" v-model="dateTo" class="al-date" title="To" />
        <button v-if="activeFilters || search" class="btn btn-secondary btn-sm" @click="clearFilters"><X :size="13" />Clear</button>
        <span class="al-count">{{ logTotal }} event{{ logTotal === 1 ? '' : 's' }}</span>
      </div>

      <div class="table-wrap">
        <table>
          <thead>
            <tr><th>Time</th><th>Actor</th><th>Role</th><th>Action</th><th>Entity</th><th>Details</th></tr>
          </thead>
          <tbody>
            <tr v-if="loading"><td colspan="6" class="empty"><Loader :size="18" class="spin" /> Loading…</td></tr>
            <tr v-for="l in logs" :key="l.id" v-else>
              <td style="color: var(--text-muted); font-size: 13px; white-space: nowrap;">{{ l.created_at ? new Date(l.created_at).toLocaleString() : '—' }}</td>
              <td>
                <div class="cell-main">
                  <div class="log-avatar"><User :size="12" /></div>
                  <div>
                    <div style="font-weight: 500;">{{ l.actor }}</div>
                    <div v-if="l.actor_email && l.actor_email !== l.actor" style="font-size: 11px; color: var(--text-muted);">{{ l.actor_email }}</div>
                  </div>
                </div>
              </td>
              <td><span class="badge badge-neutral">{{ roleLabel(l.role) }}</span></td>
              <td><span class="badge" :class="actionColor(l.action)"><Activity :size="11" />{{ actionLabel(l.action) }}</span></td>
              <td style="white-space: nowrap;">{{ (l.entity_type || '—').replace(/_/g, ' ') }}</td>
              <td style="font-size: 13px; color: var(--text);">{{ l.details }}</td>
            </tr>
            <tr v-if="!loading && !logs.length"><td colspan="6" class="empty">No audit events match your filters</td></tr>
          </tbody>
        </table>
      </div>
      <PaginationBar
        :page="logPage" :perPage="logPerPage" :totalPages="logTotalPages"
        :total="logTotal" :visiblePages="logVisiblePages"
        @update:perPage="logPerPage = $event"
        @goTo="logGoTo"
      />
    </div>
  </div>
</template>

<style scoped>
.al-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 16px; }
.al-stat { background: var(--card); border: 1px solid var(--border); border-radius: 12px; padding: 14px 16px; display: flex; flex-direction: column; gap: 4px; }
.al-stat-n { font-size: 22px; font-weight: 700; color: var(--text); }
.al-stat-l { font-size: 12px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.04em; }
.al-toolbar { display: flex; align-items: center; gap: 10px; padding: 14px 20px; border-bottom: 1px solid var(--border); flex-wrap: wrap; }
.al-toolbar .search { flex: 1; min-width: 220px; }
.al-select, .al-date { height: 36px; padding: 0 10px; border: 1px solid var(--border); border-radius: 8px; background: var(--card); font-size: 13px; color: var(--text); }
.al-date { width: 140px; }
.al-count { margin-left: auto; font-size: 12px; color: var(--text-muted); white-space: nowrap; }
.cell-main { display: flex; align-items: center; gap: 8px; }
.log-avatar { width: 26px; height: 26px; border-radius: 50%; background: var(--bg); color: var(--text-muted); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.spin { animation: spin 0.8s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
.empty { display: flex; align-items: center; justify-content: center; gap: 8px; }

@media (max-width: 800px) {
  .al-stats { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 600px) {
  .al-toolbar { gap: 8px; padding: 12px 14px; }
  .al-toolbar .search { flex: 1 1 100%; min-width: 0; }
  .al-select { flex: 1 1 calc(50% - 4px); min-width: 0; font-size: 16px; height: 44px; }
  .al-date { flex: 1 1 calc(50% - 4px); width: auto; min-width: 0; font-size: 16px; height: 44px; }
  .al-count { flex-basis: 100%; margin-left: 0; }
}
</style>
