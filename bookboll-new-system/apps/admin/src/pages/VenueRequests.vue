<script setup>
import { ref, computed, onMounted } from 'vue'
import { supabase } from '../lib/supabase.js'
import { Store, MapPin, User, Clock, Search, CircleCheck as CheckCircle } from 'lucide-vue-next'
import { categoryLabel } from '../lib/venueCategories.js'
import FilterBar from '../components/FilterBar.vue'
import { matchesCityGeo } from '../lib/geoFilter.js'

const requests = ref([])
const cities = ref([])
const countries = ref([])
const loading = ref(true)
const tab = ref('open')      // open | onboarded | declined | all
const search = ref('')
const filterCountry = ref([])
const filterCity = ref([])

async function load() {
  loading.value = true
  const [r, ci, co] = await Promise.all([
    supabase.from('venue_requests').select('*').order('created_at', { ascending: false }),
    supabase.from('cities').select('id, name, country_id'),
    supabase.from('countries').select('id, name').order('name'),
  ])
  requests.value = r.error ? [] : (r.data || [])
  cities.value = ci.data || []
  countries.value = co.data || []
  loading.value = false
}
onMounted(load)

const STATUSES = ['new', 'reviewing', 'contacted', 'onboarded', 'declined']
const OPEN = ['new', 'reviewing', 'contacted']
const cityName = (id) => cities.value.find(c => c.id === id)?.name || ''
const catLabel = (k) => categoryLabel(k)

const openCount = computed(() => requests.value.filter(r => OPEN.includes(r.status)).length)
const filtered = computed(() => {
  const q = search.value.toLowerCase().trim()
  return requests.value.filter(r => {
    if (tab.value === 'open' && !OPEN.includes(r.status)) return false
    if (tab.value === 'onboarded' && r.status !== 'onboarded') return false
    if (tab.value === 'declined' && r.status !== 'declined') return false
    if ((filterCountry.value.length || filterCity.value.length) && !matchesCityGeo(r.city_id, cities.value, filterCountry.value, filterCity.value)) return false
    if (q && !`${r.venue_name} ${r.area} ${r.requester_name}`.toLowerCase().includes(q)) return false
    return true
  })
})

async function setStatus(req, status) {
  await supabase.from('venue_requests').update({ status }).eq('id', req.id)
  req.status = status
}
async function saveNote(req) {
  await supabase.from('venue_requests').update({ admin_note: req.admin_note || '' }).eq('id', req.id)
}
function statusClass(s) {
  if (s === 'onboarded') return 'badge-success'
  if (s === 'declined') return 'badge-neutral'
  if (s === 'new') return 'badge-warning'
  return 'badge-info'
}
function fmtDate(d) { return d ? new Date(d).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : '' }
</script>

<template>
  <div class="page">
    <div class="page-header">
      <div>
        <div class="page-title">Venue Requests</div>
        <div class="page-subtitle">Venues that customers asked us to onboard — for the sales team to action</div>
      </div>
      <div v-if="openCount > 0" class="total-badge">{{ openCount }} open</div>
    </div>

    <FilterBar
      v-model:search="search"
      v-model:country="filterCountry"
      v-model:city="filterCity"
      :countries="countries"
      :cities="cities"
      search-placeholder="Search venue, area or requester..."
    />

    <div class="tabs">
      <div class="tab" :class="{ active: tab === 'open' }" @click="tab = 'open'">Open <span v-if="openCount" class="tab-badge">{{ openCount }}</span></div>
      <div class="tab" :class="{ active: tab === 'onboarded' }" @click="tab = 'onboarded'">Onboarded</div>
      <div class="tab" :class="{ active: tab === 'declined' }" @click="tab = 'declined'">Declined</div>
      <div class="tab" :class="{ active: tab === 'all' }" @click="tab = 'all'">All</div>
    </div>

    <div v-if="loading" class="vr-msg">Loading…</div>
    <div v-else-if="!filtered.length" class="empty-state">
      <Store :size="32" />
      <div class="empty-title">No venue requests here</div>
      <div class="empty-sub">When customers ask us to onboard a venue, it shows up here.</div>
    </div>
    <div v-else class="vr-list">
      <div v-for="r in filtered" :key="r.id" class="vr-card card">
        <div class="vr-top">
          <div class="vr-icon"><Store :size="20" /></div>
          <div class="vr-main">
            <div class="vr-name">{{ r.venue_name || 'Unnamed venue' }} <span class="vr-cat">{{ catLabel(r.category) }}</span></div>
            <div class="vr-meta">
              <span v-if="r.area"><MapPin :size="12" /> {{ r.area }}</span>
              <span v-if="cityName(r.city_id)">{{ cityName(r.city_id) }}</span>
              <span><User :size="12" /> {{ r.requester_name || 'Anonymous' }}</span>
              <span><Clock :size="12" /> {{ fmtDate(r.created_at) }}</span>
            </div>
            <p v-if="r.details" class="vr-details">{{ r.details }}</p>
          </div>
          <span class="badge" :class="statusClass(r.status)">{{ r.status }}</span>
        </div>
        <div class="vr-actions">
          <label class="vr-lbl">Status</label>
          <select :value="r.status" class="vr-sel" @change="setStatus(r, $event.target.value)">
            <option v-for="s in STATUSES" :key="s" :value="s">{{ s }}</option>
          </select>
          <input v-model="r.admin_note" class="vr-note" placeholder="Note for the team…" @blur="saveNote(r)" />
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.tabs { display: flex; gap: 4px; margin-bottom: 16px; }
.tab { display: flex; align-items: center; gap: 6px; padding: 8px 16px; font-size: 14px; font-weight: 600; color: var(--text-muted); cursor: pointer; border-bottom: 2px solid transparent; }
.tab.active { color: var(--text); border-bottom-color: #0f766e; }
.tab-badge { background: #ef4444; color: #fff; font-size: 11px; font-weight: 700; padding: 1px 6px; border-radius: 999px; }
.vr-msg { text-align: center; padding: 40px; color: var(--text-muted); }
.vr-list { display: flex; flex-direction: column; gap: 12px; }
.vr-card { padding: 16px 18px; margin-bottom: 0; }
.vr-top { display: flex; align-items: flex-start; gap: 14px; }
.vr-icon { width: 42px; height: 42px; border-radius: 10px; background: #ecfdf5; color: #059669; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.vr-main { flex: 1; min-width: 0; }
.vr-name { font-size: 15.5px; font-weight: 700; color: var(--text); display: flex; align-items: center; gap: 9px; flex-wrap: wrap; }
.vr-cat { font-size: 11.5px; font-weight: 700; color: #0f766e; background: #ccfbf1; padding: 2px 9px; border-radius: 999px; }
.vr-meta { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; font-size: 12.5px; color: var(--text-muted); margin-top: 6px; }
.vr-meta span { display: inline-flex; align-items: center; gap: 4px; }
.vr-details { font-size: 13.5px; color: var(--text); line-height: 1.5; margin: 9px 0 0; }
.vr-actions { display: flex; align-items: center; gap: 10px; margin-top: 14px; padding-top: 14px; border-top: 1px solid var(--border); flex-wrap: wrap; }
.vr-lbl { font-size: 12px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: .04em; }
.vr-sel { font: inherit; font-size: 13.5px; padding: 7px 10px; border-radius: 8px; border: 1px solid var(--border); background: var(--surface, #ffffff); text-transform: capitalize; }
.vr-note { flex: 1; min-width: 180px; font: inherit; font-size: 13.5px; padding: 8px 11px; border-radius: 8px; border: 1px solid var(--border); }
.vr-note:focus, .vr-sel:focus { outline: none; border-color: #14b8a6; }
.badge-info { background: #eff6ff; color: #1d4ed8; }
.badge-neutral { background: #f1f5f9; color: var(--text-muted, #64748b); }
</style>
