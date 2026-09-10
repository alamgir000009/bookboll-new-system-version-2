<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { MapPin, ChevronDown, Search, Check, Percent, Award, X, ToggleLeft, ToggleRight,
  CalendarDays, GraduationCap, Users, Plus, Trash2 } from 'lucide-vue-next'
import PaginationBar from '../components/PaginationBar.vue'
import { useCurrentUser } from '../lib/currentUser.js'
import { pushToast } from '../lib/toast.js'
import { logAudit, AUDIT } from '../lib/audit.js'
import {
  fetchGeographyCountries,
  fetchGeographyCities,
  updateCountryStatus,
  updateCityStatus,
  bulkUpdateCityStatus,
  saveCommissionRate,
  updateCityModule,
} from '../lib/geography.js'
import { DEFAULT_LOYALTY, loadLoyaltyPrograms, saveLoyaltyProgram } from '../lib/loyalty.js'

const { isSuperAdmin, isCountryAdmin } = useCurrentUser()
const canManageGeo = computed(() => isSuperAdmin.value || isCountryAdmin.value)

// ── Per-city service modules ─────────────────────────────────────────────────
// A city can have any of Bookboll's three services switched off; the matching
// creation flow is then refused there (the API enforces it too). A missing or
// null flag reads as ON, so a city added before the switch existed is unchanged.
const CITY_MODULES = [
  { key: 'module_listings',    short: 'Listings',    label: 'booking listings',    icon: CalendarDays  },
  { key: 'module_classes',     short: 'Memberships', label: 'membership programs', icon: GraduationCap },
  { key: 'module_matchmaking', short: 'Matchmaking', label: 'player matchmaking',  icon: Users         },
]
const moduleSaving = ref(new Set())

async function toggleModule(city, key) {
  if (!canManageGeo.value) return
  const saveKey = city.id + '_' + key
  if (moduleSaving.value.has(saveKey)) return
  moduleSaving.value = new Set(moduleSaving.value).add(saveKey)

  const next = city[key] === false
  const res = await updateCityModule(city.id, key, next)

  moduleSaving.value.delete(saveKey)
  moduleSaving.value = new Set(moduleSaving.value)

  // Only reflect a write that actually landed — a stale pill misleads admins
  // about what providers can create in this city.
  if (!res.success) {
    pushToast(res.message || `Could not update ${key.replace('module_', '')} for ${city.name}.`, 'error')
    return
  }
  city[key] = next
  logAudit({
    action: AUDIT.UPDATED,
    entity_type: 'city',
    entity_id: city.id,
    details: `${next ? 'Enabled' : 'Disabled'} ${key.replace('module_', '')} for ${city.name}`,
  })
}

const countries = ref([])
/** @type {import('vue').Ref<Record<number|string, Array>>} */
const citiesByCountry = ref({})
const citiesLoaded = ref(new Set())
const citiesLoading = ref(new Set())
const expanded = ref(new Set())
const search = ref('')
const saving = ref(new Set())
const savedRecently = ref(new Set())
const loading = ref(true)

const countryPage = ref(1)
const countryPerPage = ref(25)
const countryTotal = ref(0)
const countryTotalPages = computed(() => Math.max(1, Math.ceil(countryTotal.value / countryPerPage.value) || 1))

const countryVisiblePages = computed(() => {
  const total = countryTotalPages.value
  const cur = countryPage.value
  if (total <= 7) return Array.from({ length: total }, (_, i) => i + 1)
  const pages = new Set([1, total, cur])
  if (cur > 1) pages.add(cur - 1)
  if (cur < total) pages.add(cur + 1)
  return Array.from(pages).sort((a, b) => a - b)
})

const commissionRates = ref({})
const commissionSaving = ref(new Set())
const commissionSaved = ref(new Set())

const DEFAULT_RATE = 15
const DEFAULT_EVENT_RATE = 10

function getRateObj(countryId) {
  const key = String(countryId)
  if (!commissionRates.value[key] && !commissionRates.value[countryId]) {
    commissionRates.value[key] = { facility: DEFAULT_RATE, program: DEFAULT_RATE, event: DEFAULT_EVENT_RATE }
  }
  return commissionRates.value[key] || commissionRates.value[countryId]
}

async function saveCommission(countryId, type) {
  const rate = parseFloat(getRateObj(countryId)[type])
  if (isNaN(rate) || rate < 0 || rate > 100) {
    pushToast('Commission must be a number between 0 and 100.', 'error')
    return
  }
  const key = `${countryId}_${type}`
  commissionSaving.value = new Set(commissionSaving.value).add(key)
  const res = await saveCommissionRate(countryId, type, rate)
  commissionSaving.value.delete(key)
  commissionSaving.value = new Set(commissionSaving.value)
  if (!res.success) {
    pushToast(res.message || 'Could not save the commission rate.', 'error')
    return
  }
  commissionSaved.value = new Set(commissionSaved.value).add(key)
  const cName = countries.value.find(c => c.id === countryId)?.name || 'country'
  logAudit({
    action: AUDIT.UPDATED,
    entity_type: 'commission_rate',
    entity_id: countryId,
    details: `Set ${type === 'facility' ? 'facility' : type === 'event' ? 'events per-seat' : 'membership'} commission to ${rate}% for ${cName}`,
  })
  setTimeout(() => {
    commissionSaved.value.delete(key)
    commissionSaved.value = new Set(commissionSaved.value)
  }, 1500)
}

function mergeCommissionRates(rates) {
  const next = { ...commissionRates.value }
  for (const [cid, obj] of Object.entries(rates || {})) {
    next[cid] = {
      facility: Number(obj.facility ?? DEFAULT_RATE),
      program: Number(obj.program ?? DEFAULT_RATE),
      event: Number(obj.event ?? DEFAULT_EVENT_RATE),
    }
  }
  commissionRates.value = next
}

async function load(page = countryPage.value) {
  loading.value = true
  const res = await fetchGeographyCountries({
    page,
    records_per_page: countryPerPage.value,
    search: search.value.trim() || undefined,
  })
  loading.value = false
  if (!res.success) {
    pushToast(res.message || 'Could not load countries.', 'error')
    countries.value = []
    countryTotal.value = 0
    return
  }

  countries.value = res.data?.countries || []
  mergeCommissionRates(res.data?.commission_rates)

  const pagination = res.data?.pagination || {}
  countryTotal.value = Number(pagination.total ?? countries.value.length) || 0
  if (pagination.current_page) countryPage.value = Number(pagination.current_page)
}

let searchTimer
function scheduleLoad() {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    countryPage.value = 1
    load(1)
  }, 300)
}

watch(search, () => scheduleLoad())
watch(countryPerPage, () => {
  countryPage.value = 1
  load(1)
})

function countryGoTo(p) {
  countryPage.value = p
  load(p)
}

/* ── Loyalty programs (per country) ──────────────────────────────────────────
   A country with no saved row falls back to DEFAULT_LOYALTY, so every country
   always has an effective program — saving is what creates the row. */
const loyaltyPrograms = ref({})    // { [country_id]: normalized program }
const loyaltyLoaded = ref(false)

async function loadLoyalty() {
  if (!isSuperAdmin.value) return
  const res = await loadLoyaltyPrograms()
  if (res.error) { pushToast(res.error, 'error'); return }
  loyaltyPrograms.value = res.byCountry
  loyaltyTiers.value = res.tiers || []
  loyaltyLoaded.value = true
}

/** The saved row for a country, or null when it's still on platform defaults. */
const programFor = (countryId) => loyaltyPrograms.value[countryId] || null
/** What actually applies — the saved row, else the platform defaults. */
const effectiveProgram = (countryId) => programFor(countryId) || DEFAULT_LOYALTY

function loyaltySummary(country) {
  const p = effectiveProgram(country.id)
  const cur = country.currency_code || 'unit'
  return `${p.earn_rate} pt per ${cur} · ${p.redeem_rate} pts = ${cur} 1 off`
}

// ── Loyalty tiers ────────────────────────────────────────────────────────────
// Each country's own ladder. A customer's tier is the highest one whose
// threshold their lifetime points reach, so the ladder is per country: what
// counts as "Legend" in one market is not the same spend in another.
const PRESET_TIERS = [
  { name: 'Rookie', color: '#92400e', bg_color: '#fef3c7', threshold: 0,     multiplier: 1   },
  { name: 'Star',   color: '#475569', bg_color: '#f1f5f9', threshold: 1000,  multiplier: 1.2 },
  { name: 'Legend', color: '#b45309', bg_color: '#fef9c3', threshold: 5000,  multiplier: 1.5 },
  { name: 'GOAT',   color: '#0f766e', bg_color: '#ccfbf1', threshold: 15000, multiplier: 2   },
]
function defaultLTier(sortOrder = 0) {
  return { name: '', color: '#92400e', bg_color: '#fef3c7', threshold: 0, multiplier: 1, sort_order: sortOrder }
}
const loyaltyTiers = ref([])       // every tier row the server holds, all countries
const lTiers = ref([])             // the ladder being edited in the modal
function addLTier() { lTiers.value.push({ ...defaultLTier(lTiers.value.length), name: '' }) }
function removeLTier(i) { lTiers.value.splice(i, 1) }

/** This country's ladder, falling back to the presets when it has none yet. */
function tiersForProgram(programId) {
  const mine = loyaltyTiers.value.filter(t => t.program_id === programId)
  const base = mine.length ? mine : PRESET_TIERS
  return base
    .slice()
    .sort((a, b) => Number(a.threshold || 0) - Number(b.threshold || 0))
    .map((t, i) => ({
      name: t.name, color: t.color || '#92400e', bg_color: t.bg_color || '#fef3c7',
      threshold: Number(t.threshold || 0), multiplier: Number(t.multiplier || 1), sort_order: i,
    }))
}

const loyaltyCountry = ref(null)   // the country whose program is open in the editor
const lForm = ref({ ...DEFAULT_LOYALTY })
const lSaving = ref(false)
const lTriedSave = ref(false)

function openLoyalty(country) {
  loyaltyCountry.value = country
  lForm.value = { ...effectiveProgram(country.id) }
  lTiers.value = tiersForProgram(programFor(country.id)?.id ?? null)
  lTriedSave.value = false
}
function closeLoyalty() { loyaltyCountry.value = null }

async function saveLoyalty() {
  const country = loyaltyCountry.value
  if (!country || lSaving.value) return
  lTriedSave.value = true
  if (!lForm.value.name?.trim()) { pushToast('Program name is required.', 'error'); return }

  lSaving.value = true
  const res = await saveLoyaltyProgram(country.id, lForm.value, lTiers.value)
  lSaving.value = false
  if (res.error) { pushToast(res.error, 'error'); return }

  loyaltyPrograms.value = { ...loyaltyPrograms.value, [country.id]: res.program }
  // Replace this program's tiers with what the server actually stored.
  if (res.tiers) {
    const pid = res.program?.id ?? null
    loyaltyTiers.value = [...loyaltyTiers.value.filter(t => t.program_id !== pid), ...res.tiers]
  }
  logAudit({
    action: AUDIT.UPDATED,
    entity_type: 'loyalty_program',
    entity_id: country.id,
    details: `Saved loyalty program "${res.program.name}" for ${country.name}`,
  })
  pushToast(`Loyalty program saved for ${country.name}.`, 'success')
  closeLoyalty()
}

/** Summary-card toggle: flips is_active on the saved row, creating it if needed. */
async function toggleLoyaltyActive(country) {
  const current = effectiveProgram(country.id)
  const res = await saveLoyaltyProgram(country.id, { ...current, is_active: !current.is_active })
  if (res.error) { pushToast(res.error, 'error'); return }
  loyaltyPrograms.value = { ...loyaltyPrograms.value, [country.id]: res.program }
  logAudit({
    action: res.program.is_active ? AUDIT.ENABLED : AUDIT.DISABLED,
    entity_type: 'loyalty_program',
    entity_id: country.id,
    details: `Loyalty program ${res.program.is_active ? 'activated' : 'deactivated'} for ${country.name}`,
  })
}

onMounted(() => { load(1); loadLoyalty() })

function citiesOf(countryId) {
  return citiesByCountry.value[countryId] || citiesByCountry.value[String(countryId)] || []
}

async function ensureCities(countryId) {
  if (citiesLoaded.value.has(countryId) || citiesLoading.value.has(countryId)) return
  citiesLoading.value = new Set(citiesLoading.value).add(countryId)
  const res = await fetchGeographyCities(countryId)
  citiesLoading.value.delete(countryId)
  citiesLoading.value = new Set(citiesLoading.value)
  if (!res.success) {
    pushToast(res.message || 'Could not load cities.', 'error')
    return
  }
  citiesByCountry.value = {
    ...citiesByCountry.value,
    [countryId]: res.data?.cities || [],
  }
  citiesLoaded.value = new Set(citiesLoaded.value).add(countryId)
}

function toggle(id) {
  if (expanded.value.has(id)) {
    expanded.value.delete(id)
  } else {
    expanded.value.add(id)
    ensureCities(id)
  }
  expanded.value = new Set(expanded.value)
}

function flash(id) {
  savedRecently.value = new Set(savedRecently.value).add(id)
  setTimeout(() => {
    savedRecently.value.delete(id)
    savedRecently.value = new Set(savedRecently.value)
  }, 1200)
}

async function toggleCountry(country) {
  if (saving.value.has(country.id)) return
  saving.value = new Set(saving.value).add(country.id)
  const newStatus = country.status === 'active' ? 'inactive' : 'active'
  const res = await updateCountryStatus(country.id, newStatus)
  saving.value.delete(country.id)
  saving.value = new Set(saving.value)
  if (!res.success) {
    pushToast(res.message || `Could not update ${country.name}.`, 'error')
    return
  }
  country.status = res.data?.country?.status || newStatus
  flash(country.id)
  logAudit({
    action: newStatus === 'active' ? AUDIT.ENABLED : AUDIT.DISABLED,
    entity_type: 'country',
    entity_id: country.id,
    details: `${newStatus === 'active' ? 'Enabled' : 'Disabled'} country ${country.name}`,
  })
}

async function toggleCity(city) {
  if (saving.value.has(city.id)) return
  saving.value = new Set(saving.value).add(city.id)
  const newStatus = city.status === 'active' ? 'inactive' : 'active'
  const res = await updateCityStatus(city.id, newStatus)
  saving.value.delete(city.id)
  saving.value = new Set(saving.value)
  if (!res.success) {
    pushToast(res.message || `Could not update ${city.name}.`, 'error')
    return
  }
  city.status = res.data?.city?.status || newStatus
  flash(city.id)
}

const bulkSaving = ref(false)
async function toggleAllCities(countryId, targetStatus) {
  if (bulkSaving.value) return
  await ensureCities(countryId)
  const batch = citiesOf(countryId).filter(c => c.status !== targetStatus)
  if (!batch.length) return
  bulkSaving.value = true
  const res = await bulkUpdateCityStatus(countryId, targetStatus, batch.map(c => c.id))
  bulkSaving.value = false
  if (!res.success) {
    pushToast(res.message || 'Could not update the cities.', 'error')
    return
  }
  batch.forEach(c => { c.status = targetStatus })
}

function activeCount(countryId) {
  return citiesOf(countryId).filter(c => c.status === 'active').length
}

function cityCountLabel(country) {
  if (citiesLoaded.value.has(country.id)) {
    return `${activeCount(country.id)} / ${citiesOf(country.id).length}`
  }
  return String(country.cities_count ?? 0)
}
</script>

<template>
  <div class="page">
    <div class="page-header">
      <div>
        <div class="page-title">Countries &amp; Cities</div>
        <div class="page-subtitle">Enable countries &amp; cities, and set each country's commission rates</div>
      </div>
    </div>

    <!-- Search bar -->
    <div class="search-bar">
      <Search :size="15" class="search-icon" />
      <input v-model="search" placeholder="Search countries or cities…" class="search-input" />
    </div>

    <!-- Legend -->
    <div class="legend">
      <span class="legend-item"><span class="cb active"></span> Enabled — appears in location dropdowns</span>
      <span class="legend-item"><span class="cb"></span> Disabled — hidden from location dropdowns</span>
    </div>

    <!-- Country list -->
    <div class="country-list">
      <div
        v-for="country in countries"
        :key="country.id"
        class="country-row-wrap"
        :class="{ inactive: country.status !== 'active' }"
      >
        <!-- Country header row -->
        <div class="country-row" @click="toggle(country.id)">
          <!-- Checkbox -->
          <div
            class="cb-wrap"
            @click.stop="toggleCountry(country)"
            :title="country.status === 'active' ? 'Disable country' : 'Enable country'"
          >
            <div class="cb" :class="{ active: country.status === 'active', saving: saving.has(country.id) }">
              <Check v-if="country.status === 'active'" :size="11" stroke-width="3" />
            </div>
          </div>

          <!-- Flag-style ISO badge -->
          <div class="iso-badge">{{ country.iso_code }}</div>

          <!-- Name -->
          <div class="country-name">{{ country.name }}</div>

          <!-- Meta -->
          <div class="country-meta">
            <span v-if="country.currency_code" class="meta-pill">{{ country.currency_code }}</span>
            <span class="meta-pill city-count">
              <MapPin :size="10" />
              {{ cityCountLabel(country) }}
            </span>
          </div>

          <!-- Expand toggle -->
          <div class="expand-btn" :class="{ open: expanded.has(country.id) }">
            <ChevronDown :size="15" />
          </div>
        </div>

        <!-- Cities + Commission panel -->
        <div v-if="expanded.has(country.id)" class="cities-panel">

          <!-- Commission rates (Super Admin + Country Admin in jurisdiction) -->
          <div v-if="canManageGeo" class="commission-section">
            <div class="commission-section-title">
              <Percent :size="13" />
              Commission Rates for {{ country.name }}
            </div>
            <div class="commission-row">
              <div class="commission-field">
                <label class="commission-label">Facility Listings (%)</label>
                <div class="commission-input-wrap">
                  <input
                    type="number" min="0" max="100" step="0.5"
                    class="commission-input"
                    :value="getRateObj(country.id).facility"
                    @input="getRateObj(country.id).facility = $event.target.value"
                    @blur="saveCommission(country.id, 'facility')"
                    @keydown.enter="saveCommission(country.id, 'facility')"
                  />
                  <span class="pct-suffix">%</span>
                </div>
              </div>
              <div class="commission-field">
                <label class="commission-label">Membership Programs (%)</label>
                <div class="commission-input-wrap">
                  <input
                    type="number" min="0" max="100" step="0.5"
                    class="commission-input"
                    :value="getRateObj(country.id).program"
                    @input="getRateObj(country.id).program = $event.target.value"
                    @blur="saveCommission(country.id, 'program')"
                    @keydown.enter="saveCommission(country.id, 'program')"
                  />
                  <span class="pct-suffix">%</span>
                </div>
              </div>
              <div class="commission-field">
                <label class="commission-label">Events — per seat (%)</label>
                <div class="commission-input-wrap">
                  <input
                    type="number" min="0" max="100" step="0.5"
                    class="commission-input"
                    :value="getRateObj(country.id).event"
                    @input="getRateObj(country.id).event = $event.target.value"
                    @blur="saveCommission(country.id, 'event')"
                    @keydown.enter="saveCommission(country.id, 'event')"
                  />
                  <span class="pct-suffix">%</span>
                </div>
              </div>
              <div class="commission-status">
                <span v-if="commissionSaving.has(`${country.id}_facility`) || commissionSaving.has(`${country.id}_program`)" class="cr-saving">Saving…</span>
                <span v-else-if="commissionSaved.has(`${country.id}_facility`) || commissionSaved.has(`${country.id}_program`)" class="cr-saved"><Check :size="12" /> Saved</span>
                <span v-else class="cr-hint">Tab or Enter to save</span>
              </div>
            </div>
            <div class="commission-note">
              Default rates for {{ country.name }} (platform default: 15%). Facility bookings use this rate live (a per-listing override wins);
              membership programs use it as the default commission when a new program is created here.
            </div>
          </div>

          <!-- Loyalty program summary (super admin only) -->
          <div v-if="isSuperAdmin && loyaltyLoaded" class="loyalty-section">
            <div class="loyalty-left">
              <div class="ly-card-head" style="border:none; background:none; padding:0 0 8px;">
                <Award :size="15" />Loyalty Program
              </div>
              <div class="loyalty-summary">
                <button
                  class="ly-status-toggle"
                  :class="effectiveProgram(country.id).is_active ? 'on' : 'off'"
                  :title="effectiveProgram(country.id).is_active ? 'Click to deactivate' : 'Click to activate'"
                  @click.stop="toggleLoyaltyActive(country)"
                >
                  <ToggleRight v-if="effectiveProgram(country.id).is_active" :size="14" />
                  <ToggleLeft v-else :size="14" />
                  {{ effectiveProgram(country.id).is_active ? 'Active' : 'Inactive' }}
                </button>
                <span class="loyalty-sum-text">{{ loyaltySummary(country) }}</span>
                <span v-if="!programFor(country.id)" class="loyalty-sum-text muted">· platform default</span>
              </div>
            </div>
            <button class="btn btn-secondary btn-sm" @click.stop="openLoyalty(country)">
              <Award :size="13" />Edit
            </button>
          </div>

          <!-- Cities -->
          <div class="cities-panel-header">
            <span>Cities</span>
            <div style="display:flex;gap:8px;">
              <button class="link-btn" :disabled="bulkSaving || citiesLoading.has(country.id)" @click.stop="toggleAllCities(country.id, 'active')">Enable all</button>
              <button class="link-btn muted" :disabled="bulkSaving || citiesLoading.has(country.id)" @click.stop="toggleAllCities(country.id, 'inactive')">Disable all</button>
            </div>
          </div>
          <div v-if="citiesLoading.has(country.id)" class="no-cities">Loading cities…</div>
          <div v-else class="cities-grid">
            <div
              v-for="city in citiesOf(country.id)"
              :key="city.id"
              class="city-item"
              :class="{ inactive: city.status !== 'active' }"
            >
              <div class="city-top" @click="toggleCity(city)">
                <div class="cb sm" :class="{ active: city.status === 'active', saving: saving.has(city.id) }">
                  <Check v-if="city.status === 'active'" :size="9" stroke-width="3" />
                </div>
                <span class="city-name">{{ city.name }}</span>
                <Check v-if="savedRecently.has(city.id)" :size="11" class="saved-flash" />
              </div>
              <!-- Service module toggles — only for an active city, and only for
                   someone who may manage geography. -->
              <div v-if="city.status === 'active' && canManageGeo" class="city-modules">
                <button
                  v-for="mod in CITY_MODULES"
                  :key="mod.key"
                  class="mod-pill"
                  :class="{ 'mod-off': city[mod.key] === false }"
                  :title="(city[mod.key] !== false ? 'Click to disable ' : 'Click to enable ') + mod.label"
                  :disabled="moduleSaving.has(city.id + '_' + mod.key)"
                  @click.stop="toggleModule(city, mod.key)"
                >
                  <component :is="mod.icon" :size="9" />
                  {{ mod.short }}
                </button>
              </div>
            </div>
            <div v-if="!citiesOf(country.id).length" class="no-cities">No cities configured for this country.</div>
          </div>
        </div>
      </div>

      <div v-if="loading" class="empty-state">Loading countries…</div>
      <div v-else-if="!countries.length" class="empty-state">
        {{ search.trim() ? `No countries match "${search}"` : 'No countries found.' }}
      </div>
    </div>
    <PaginationBar
      v-if="!loading && countryTotal > 0"
      :page="countryPage" :perPage="countryPerPage" :totalPages="countryTotalPages"
      :total="countryTotal" :visiblePages="countryVisiblePages"
      @update:perPage="countryPerPage = $event"
      @goTo="countryGoTo"
    />

    <!-- ── Loyalty program editor (per country) ─────────────────────────── -->
    <Teleport to="body">
      <div v-if="loyaltyCountry" class="ly-backdrop" @click.self="closeLoyalty">
        <div class="ly-modal">
          <div class="ly-head">
            <div>
              <div class="ly-title"><Award :size="17" />Loyalty Program — {{ loyaltyCountry.name }}</div>
              <div class="ly-sub">Per-country earn &amp; redeem rules</div>
            </div>
            <div class="ly-head-right">
              <button class="ly-toggle" @click="lForm.is_active = !lForm.is_active" :title="lForm.is_active ? 'Click to deactivate' : 'Click to activate'">
                <ToggleRight v-if="lForm.is_active" :size="22" class="toggle-on" />
                <ToggleLeft v-else :size="22" class="toggle-off" />
                <span>{{ lForm.is_active ? 'Active' : 'Inactive' }}</span>
              </button>
              <button class="ly-x" @click="closeLoyalty"><X :size="18" /></button>
            </div>
          </div>

          <div class="ly-body">
            <div class="ly-card">
              <div class="ly-card-head"><Award :size="15" />Earn &amp; Redeem Rules</div>
              <div class="ly-card-body">
                <div class="form-group">
                  <label class="form-label">Program Name</label>
                  <input v-model="lForm.name" :class="{ 'ly-invalid': lTriedSave && !lForm.name?.trim() }" placeholder="e.g. Bookboll Rewards" />
                  <div v-if="lTriedSave && !lForm.name?.trim()" class="ly-err">Program name is required</div>
                </div>
                <div class="ly-rules-grid">
                  <div class="form-group">
                    <label class="form-label">Points per {{ loyaltyCountry.currency_code || 'unit' }} spent</label>
                    <input type="number" v-model="lForm.earn_rate" min="0" step="0.1" />
                  </div>
                  <div class="form-group">
                    <label class="form-label">Points for {{ loyaltyCountry.currency_code || 'unit' }} 1 discount</label>
                    <input type="number" v-model="lForm.redeem_rate" min="1" />
                    <div class="ly-hint">e.g. 100 = 100 points redeem {{ loyaltyCountry.currency_code || 'unit' }} 1 off. Redemptions come out of Bookboll's fee, never the provider's share.</div>
                  </div>
                  <div class="form-group">
                    <label class="form-label">Minimum points to redeem</label>
                    <input type="number" v-model="lForm.min_redeem" min="0" />
                  </div>
                  <div class="form-group">
                    <label class="form-label">Max redeem % of booking</label>
                    <input type="number" v-model="lForm.max_redeem_pct" min="0" max="100" />
                  </div>
                  <div class="form-group">
                    <label class="form-label">Points expire after (months, 0 = never)</label>
                    <input type="number" v-model="lForm.expiry_months" min="0" />
                  </div>
                </div>
              </div>
            </div>

            <!-- Tiers — this country's own ladder -->
            <div class="ly-card">
              <div class="ly-card-head">
                <Award :size="15" />Membership Tiers
                <button class="btn btn-secondary btn-sm" style="margin-left:auto" @click="addLTier"><Plus :size="13" />Add Tier</button>
              </div>
              <div class="ly-card-body">
                <div v-if="!lTiers.length" class="ly-empty-tiers">No tiers yet. Add at least one.</div>
                <div v-for="(t, i) in lTiers" :key="i" class="ly-tier-row">
                  <div class="ly-tier-preview" :style="`background:${t.bg_color}; color:${t.color}`">
                    <Award :size="13" /><span>{{ t.name || '…' }}</span>
                  </div>
                  <div class="ly-tier-fields">
                    <div class="form-group sm"><label class="form-label">Name</label><input v-model="t.name" placeholder="e.g. Legend" /></div>
                    <div class="form-group sm"><label class="form-label">Threshold</label><input type="number" v-model="t.threshold" min="0" /></div>
                    <div class="form-group sm"><label class="form-label">Multiplier</label><input type="number" v-model="t.multiplier" min="1" step="0.1" /></div>
                    <div class="ly-color-pair">
                      <div class="form-group sm"><label class="form-label">Text</label><input type="color" v-model="t.color" class="ly-color-input" /></div>
                      <div class="form-group sm"><label class="form-label">Bg</label><input type="color" v-model="t.bg_color" class="ly-color-input" /></div>
                    </div>
                  </div>
                  <button class="ly-x danger" @click="removeLTier(i)"><Trash2 :size="15" /></button>
                </div>
                <div class="ly-hint">A customer sits in the highest tier their lifetime points reach.</div>
              </div>
            </div>
          </div>

          <div class="ly-foot">
            <div class="ly-foot-hint">Toggle <b>Active</b> above to disable rewards — every country falls back to the platform default program.</div>
            <div style="display:flex; align-items:center; gap:10px;">
              <button class="btn btn-secondary" @click="closeLoyalty">Cancel</button>
              <button class="btn btn-primary" :disabled="lSaving" @click="saveLoyalty">
                {{ lSaving ? 'Saving…' : (programFor(loyaltyCountry.id) ? 'Save changes' : 'Create program') }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<style scoped>
.search-bar {
  position: relative; margin-bottom: 14px;
}
.search-icon {
  position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--text-muted); pointer-events: none;
}
.search-input {
  width: 100%; padding: 9px 12px 9px 36px; border: 1px solid var(--border);
  border-radius: 10px; font-size: 14px; background: var(--surface, #ffffff);
}
.search-input:focus { outline: none; border-color: var(--primary); }

.legend {
  display: flex; gap: 20px; margin-bottom: 14px; flex-wrap: wrap;
}
.legend-item {
  display: flex; align-items: center; gap: 8px; font-size: 12.5px; color: var(--text-muted);
}

/* Checkbox */
.cb {
  width: 18px; height: 18px; border-radius: 5px; border: 2px solid var(--border);
  display: flex; align-items: center; justify-content: center;
  background: var(--surface, #ffffff); flex-shrink: 0; transition: all 0.12s; cursor: pointer;
}
.cb.active { background: var(--primary); border-color: var(--primary); color: #fff; }
.cb.saving { opacity: 0.5; }
.cb.sm { width: 15px; height: 15px; border-radius: 4px; }

.cb-wrap { padding: 6px; margin: -6px; flex-shrink: 0; }

.country-list { display: flex; flex-direction: column; gap: 6px; }

.country-row-wrap {
  background: var(--surface, #ffffff); border: 1px solid var(--border); border-radius: 12px;
  overflow: hidden; transition: box-shadow 0.15s;
}
.country-row-wrap:hover { box-shadow: 0 2px 8px rgba(0,0,0,.07); }
.country-row-wrap.inactive { opacity: 0.65; }

.country-row {
  display: flex; align-items: center; gap: 12px; padding: 12px 16px;
  cursor: pointer; user-select: none;
}
.country-row:hover { background: #fafafa; }

.iso-badge {
  font-size: 11px; font-weight: 700; padding: 3px 7px; border-radius: 5px;
  background: #f1f5f9; color: #475569; letter-spacing: .04em; flex-shrink: 0;
  min-width: 34px; text-align: center;
}

.country-name { flex: 1; font-weight: 600; font-size: 14px; }

.country-meta { display: flex; align-items: center; gap: 6px; flex-shrink: 0; }
.meta-pill {
  font-size: 12px; padding: 2px 8px; border-radius: 999px;
  background: var(--bg); color: var(--text-muted); border: 1px solid var(--border);
  display: inline-flex; align-items: center; gap: 4px;
}
.city-count { color: var(--primary); background: #f0fdf4; border-color: #bbf7d0; }

.expand-btn {
  color: var(--text-muted); transition: transform 0.2s; flex-shrink: 0;
  display: flex; align-items: center;
}
.expand-btn.open { transform: rotate(180deg); }

/* Cities panel */
.cities-panel {
  border-top: 1px solid var(--border); background: #fafafa; padding: 14px 18px 18px;
}
.cities-panel-header {
  display: flex; justify-content: space-between; align-items: center;
  font-size: 12px; font-weight: 600; color: var(--text-muted); text-transform: uppercase;
  letter-spacing: .05em; margin-bottom: 12px;
}
.link-btn {
  font-size: 12px; color: var(--primary); font-weight: 500; padding: 0;
  background: none; border: none; cursor: pointer; text-decoration: underline;
}
.link-btn.muted { color: var(--text-muted); }
.link-btn:hover { opacity: 0.75; }

.cities-grid {
  display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: 6px;
}

.city-item {
  display: flex; flex-direction: column;
  border: 1px solid var(--border); border-radius: 8px;
  background: var(--surface, #ffffff); transition: border-color 0.12s; font-size: 13px;
  overflow: hidden;
}
.city-item:hover { border-color: var(--primary); }
.city-item.inactive { opacity: 0.55; }

.city-top {
  display: flex; align-items: center; gap: 8px;
  padding: 7px 10px; cursor: pointer;
}
.city-top:hover { background: #f0fdf4; }
.city-name { flex: 1; }

/* Service module pill row */
.city-modules {
  display: flex; gap: 4px; padding: 0 10px 7px; flex-wrap: wrap;
}
.mod-pill {
  display: inline-flex; align-items: center; gap: 3px;
  font-size: 10px; font-weight: 600; padding: 2px 6px;
  border-radius: 4px; border: none; cursor: pointer;
  background: #dcfce7; color: #15803d;
  transition: opacity 0.1s, background 0.15s;
  letter-spacing: 0.01em; line-height: 1.4;
}
.mod-pill.mod-off {
  background: #fee2e2; color: #991b1b;
  text-decoration: line-through; text-decoration-color: #ef4444;
}
.mod-pill:hover:not(:disabled) { opacity: 0.75; }
.mod-pill:disabled { opacity: 0.4; cursor: wait; }

.city-modules { display: flex; flex-wrap: wrap; gap: 4px; padding: 0 8px 7px 26px; }
.mod-pill {
  display: inline-flex; align-items: center; gap: 3px;
  font-size: 10px; font-weight: 600; padding: 2px 6px;
  border-radius: 4px; border: none; cursor: pointer;
  background: #dcfce7; color: #15803d;
  transition: opacity 0.1s, background 0.15s;
  letter-spacing: 0.01em; line-height: 1.4;
}
.mod-pill.mod-off {
  background: #fee2e2; color: #991b1b;
  text-decoration: line-through; text-decoration-color: #ef4444;
}
.mod-pill:hover:not(:disabled) { opacity: 0.75; }
.mod-pill:disabled { opacity: 0.4; cursor: wait; }

.saved-flash { color: var(--primary); flex-shrink: 0; animation: fadeout 1.2s ease forwards; }
@keyframes fadeout { 0% { opacity:1 } 80% { opacity:1 } 100% { opacity:0 } }

.no-cities { font-size: 13px; color: var(--text-muted); padding: 8px 0; }

/* Commission rates section */
.commission-section {
  background: #f8fafc; border: 1px solid var(--border, #e2e8f0); border-radius: 10px;
  padding: 14px 16px; margin-bottom: 16px;
}
.commission-section-title {
  display: flex; align-items: center; gap: 6px;
  font-size: 12px; font-weight: 700; color: var(--text-muted);
  text-transform: uppercase; letter-spacing: .05em; margin-bottom: 12px;
}
.commission-row { display: flex; align-items: flex-end; gap: 20px; flex-wrap: wrap; }
.commission-field { display: flex; flex-direction: column; gap: 5px; }
.commission-label { font-size: 12px; font-weight: 500; color: var(--text-muted); }
.commission-input-wrap { position: relative; display: flex; align-items: center; }
.commission-input {
  width: 90px; padding: 7px 28px 7px 10px; border: 1px solid var(--border);
  border-radius: 8px; font-size: 14px; font-weight: 600; background: var(--surface, #ffffff);
  appearance: textfield; -moz-appearance: textfield;
}
.commission-input::-webkit-inner-spin-button,
.commission-input::-webkit-outer-spin-button { -webkit-appearance: none; }
.commission-input:focus { outline: none; border-color: var(--primary); }
.pct-suffix {
  position: absolute; right: 9px; font-size: 13px; color: var(--text-muted);
  pointer-events: none;
}
.commission-status { display: flex; align-items: center; padding-bottom: 2px; }
.cr-hint { font-size: 12px; color: var(--text-muted); }
.cr-saving { font-size: 12px; color: var(--text-muted); font-style: italic; }
.cr-saved { display: flex; align-items: center; gap: 4px; font-size: 12px; color: var(--primary); font-weight: 600; }
.commission-note { font-size: 12px; color: var(--text-muted); margin-top: 10px; line-height: 1.5; }

.empty-state {
  text-align: center; padding: 40px; color: var(--text-muted); font-size: 14px;
  background: var(--surface, #ffffff); border: 1px solid var(--border); border-radius: 12px;
}

/* ── Loyalty section (in expanded country panel) ──────────────────────────── */
.loyalty-section {
  display: flex; align-items: center; justify-content: space-between; gap: 16px;
  background: #f8fafc; border: 1px solid var(--border, #e2e8f0); border-radius: 10px;
  padding: 14px 16px; margin-bottom: 16px; flex-wrap: wrap;
}
.loyalty-left { min-width: 0; }
.loyalty-summary { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.ly-status-toggle { display: inline-flex; align-items: center; gap: 5px; font-size: 11.5px; font-weight: 700; padding: 3px 10px; border-radius: 999px; border: 1px solid transparent; cursor: pointer; }
.ly-status-toggle.on { background: #dcfce7; color: #166534; }
.ly-status-toggle.on:hover { background: #bbf7d0; }
.ly-status-toggle.off { background: #f1f5f9; color: var(--text-muted, #64748b); }
.ly-status-toggle.off:hover { background: var(--border, #e2e8f0); }
.loyalty-sum-text { font-size: 13px; color: var(--text); }
.loyalty-sum-text.muted { color: var(--text-muted); }

/* ── Loyalty editor modal ─────────────────────────────────────────────────── */
.ly-backdrop { position: fixed; inset: 0; background: rgba(15,23,42,.5); display: flex; align-items: flex-start; justify-content: center; padding: 40px 16px; z-index: 1000; overflow-y: auto; }
.ly-modal { width: 100%; max-width: 760px; background: var(--surface, #ffffff); border-radius: 16px; box-shadow: 0 24px 60px rgba(0,0,0,.25); display: flex; flex-direction: column; }
.ly-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; padding: 18px 22px; border-bottom: 1px solid var(--border); }
.ly-title { display: flex; align-items: center; gap: 8px; font-size: 17px; font-weight: 800; color: var(--text); }
.ly-sub { font-size: 12.5px; color: var(--text-muted); margin-top: 3px; }
.ly-head-right { display: flex; align-items: center; gap: 12px; flex-shrink: 0; }
.ly-toggle { display: flex; align-items: center; gap: 6px; cursor: pointer; font-size: 13px; font-weight: 600; background: none; border: none; color: var(--text); }
.toggle-on { color: #10b981; }
.toggle-off { color: var(--text-light, #94a3b8); }
.ly-x { width: 32px; height: 32px; border-radius: 8px; border: none; background: #f1f5f9; color: #475569; cursor: pointer; display: flex; align-items: center; justify-content: center; }
.ly-x:hover { background: var(--border, #e2e8f0); }
.ly-x.danger { background: transparent; color: #dc2626; width: auto; height: auto; padding: 6px; }
.ly-x.danger:hover { background: #fef2f2; }
.ly-body { padding: 18px 22px; display: flex; flex-direction: column; gap: 16px; overflow-y: auto; }
.ly-card { border: 1px solid var(--border); border-radius: 12px; overflow: hidden; }
.ly-card-head { display: flex; align-items: center; gap: 8px; padding: 12px 16px; border-bottom: 1px solid var(--border); font-size: 14px; font-weight: 700; color: var(--text); background: #f8fafc; }
.ly-card-body { padding: 16px; }
.ly-rules-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0 16px; }
@media (max-width: 640px) { .ly-rules-grid { grid-template-columns: 1fr; } }
.ly-invalid { border-color: #ef4444 !important; }
.ly-err { font-size: 12px; color: #dc2626; margin-top: 4px; }
.ly-hint { font-size: 11.5px; color: var(--text-light, #94a3b8); margin-top: 4px; line-height: 1.45; }
.ly-empty-tiers { text-align: center; padding: 24px; color: var(--text-muted); font-size: 13px; background: #fafafa; border-radius: 8px; }
.ly-tier-row { display: flex; align-items: flex-start; gap: 10px; padding: 14px 0; border-bottom: 1px solid var(--border); }
.ly-tier-row:last-child { border-bottom: none; }
.ly-tier-preview { min-width: 88px; padding: 6px 10px; border-radius: 999px; font-size: 12px; font-weight: 700; display: inline-flex; align-items: center; gap: 5px; justify-content: center; flex-shrink: 0; margin-top: 22px; }
.ly-tier-fields { flex: 1; display: grid; grid-template-columns: 1fr 1fr; gap: 0 12px; }
.ly-color-pair { display: flex; gap: 10px; align-items: flex-end; }
.form-group.sm { margin-bottom: 8px; }
.ly-color-input { width: 52px; height: 32px; padding: 0; border: 1px solid var(--border); border-radius: 6px; cursor: pointer; }
.ly-foot { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 16px 22px; border-top: 1px solid var(--border); }
.ly-foot-hint { font-size: 12px; color: var(--text-muted); max-width: 320px; line-height: 1.4; }

/* ── Phone (≤600px) — mobile-only adjustments, desktop untouched ─────────── */
@media (max-width: 600px) {
  /* iOS zoom guard: all interactive text inputs ≥16px */
  .search-input,
  .commission-input,
  .ly-modal input { font-size: 16px; }

  /* Country header row: tighter gaps, ellipsize long names so pills never overflow */
  .country-row { gap: 8px; padding: 12px 12px; }
  .country-name { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

  /* Commission fields: stack full-width with comfortable tap targets */
  .commission-row { flex-direction: column; align-items: stretch; gap: 12px; }
  .commission-field { width: 100%; }
  .commission-input-wrap { width: 100%; }
  .commission-input { width: 100%; min-height: 44px; }

  /* City module pills: readable size + bigger tap target */
  /* Finger-sized module toggles + bulk links (these are the page's most-tapped controls) */
  .mod-pill { font-size: 12px; padding: 9px 12px; min-height: 38px; }
  .link-btn { padding: 10px 12px; min-height: 40px; display: inline-flex; align-items: center; }

  /* Enable all / Disable all: real tap targets */
  .link-btn { padding: 8px 4px; font-size: 13px; }

  /* Loyalty editor modal → full-screen bottom sheet; content scrolls inside body */
  .ly-backdrop { padding: 0; align-items: flex-end; overflow-y: hidden; }
  .ly-modal { max-width: none; height: 100dvh; max-height: 100dvh; border-radius: 0; }
  .ly-head { padding: 14px 16px; }
  .ly-title { font-size: 16px; }
  .ly-body { flex: 1; min-height: 0; overflow-y: auto; padding: 14px 16px; }

  /* Tier rows: wrap — preview pill + delete on line 1, fields full-width below */
  .ly-tier-row { flex-wrap: wrap; }
  .ly-tier-preview { margin-top: 0; }
  .ly-tier-row .ly-x.danger { order: 1; margin-left: auto; }
  .ly-tier-fields { grid-template-columns: 1fr; min-width: 0; flex: 1 1 100%; order: 2; }

  /* Footer: stack hint under full-width button row */
  .ly-foot { flex-direction: column-reverse; align-items: stretch; gap: 10px; padding: 14px 16px; }
  .ly-foot > div:last-child { width: 100%; }
  .ly-foot .btn { flex: 1; min-height: 44px; }
  .ly-foot-hint { max-width: none; }
}

/* Very narrow phones (≤400px): drop the currency pill, keep the city count */
@media (max-width: 400px) {
  .country-meta .meta-pill:first-child { display: none; }
  .country-meta .meta-pill.city-count { display: inline-flex; }
}
</style>
