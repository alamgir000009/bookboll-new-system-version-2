<script setup>
import { ref, computed, onMounted } from 'vue'
import { supabase } from '../lib/supabase.js'
import { Globe as Globe2, MapPin, ChevronDown, Search, Check, Percent, Save, CalendarDays, GraduationCap, Users, Award, Plus, Trash2, X, ToggleLeft, ToggleRight } from 'lucide-vue-next'
import PaginationBar from '../components/PaginationBar.vue'
import { usePagination } from '../lib/usePagination.js'
import { useCurrentUser } from '../lib/currentUser.js'
import { pushToast } from '../lib/toast.js'
import { logAudit, AUDIT } from '../lib/audit.js'

const { isSuperAdmin, isCountryAdmin, assignedCountryIds, currentUser } = useCurrentUser()
// A Country Admin only sees/manages the countries assigned to them (fallback to their
// single country_id until the assigned_country_ids migration is run). Super sees all.
const countryJurisdiction = computed(() => {
  if (isSuperAdmin.value) return null
  if (isCountryAdmin.value) {
    if (assignedCountryIds.value.length) return new Set(assignedCountryIds.value)
    return currentUser.value?.country_id ? new Set([currentUser.value.country_id]) : new Set()
  }
  return new Set()   // other roles don't reach this page
})
const scopedCountries = computed(() => {
  const j = countryJurisdiction.value
  return j ? countries.value.filter(c => j.has(c.id)) : countries.value
})
// Operational settings (commission, loyalty, city modules) can be managed by Super
// Admin and Country Admin. Country admins only ever SEE their own countries (scoped
// above), so this is jurisdiction-safe. Structural country/city provisioning stays
// super-admin only (ensureDefaultPrograms).
const canManageGeo = computed(() => isSuperAdmin.value || isCountryAdmin.value)

const countries = ref([])
const cities = ref([])
const programs = ref([])   // loyalty_programs rows (one per country)
const tiers = ref([])      // loyalty_tiers rows
const expanded = ref(new Set())
const search = ref('')
const saving = ref(new Set())  // ids currently being toggled
const savedRecently = ref(new Set())

// ── City service modules ──────────────────────────────────────────
// Three modules can be toggled per city by Super Admin.
// Disabled module → providers cannot create the relevant content in that city.
const CITY_MODULES = [
  { key: 'module_listings',    short: 'Listings',  label: 'booking listings',        icon: CalendarDays  },
  { key: 'module_classes',     short: 'Memberships', label: 'membership programs',    icon: GraduationCap },
  { key: 'module_matchmaking', short: 'Matchmaking', label: 'player matchmaking',    icon: Users         },
]
const moduleSaving = ref(new Set())

async function toggleModule(city, key) {
  if (!canManageGeo.value) return
  const saveKey = city.id + '_' + key
  if (moduleSaving.value.has(saveKey)) return
  moduleSaving.value = new Set(moduleSaving.value).add(saveKey)
  // city[key] may be undefined pre-migration (treat as true); toggle it
  const newVal = city[key] === false
  const { error } = await supabase.from('cities').update({ [key]: newVal }).eq('id', city.id)
  moduleSaving.value.delete(saveKey)
  moduleSaving.value = new Set(moduleSaving.value)
  // Only reflect a write that actually landed — a stale module pill misleads
  // admins about what providers can create in this city.
  if (error) { pushToast(`Could not update ${key.replace('module_', '')} for ${city.name}.`, 'error'); return }
  city[key] = newVal
}

// ── Commission rates ──────────────────────────────────────────────
// { [countryId]: { facility: number, program: number } }
const commissionRates = ref({})
const commissionSaving = ref(new Set())
const commissionSaved = ref(new Set())

// Platform default: 15% worldwide for both facility bookings and membership programs.
const DEFAULT_RATE = 15
const DEFAULT_EVENT_RATE = 10   // events per-seat fee — deliberately lower than venue commission

function getRateObj(countryId) {
  if (!commissionRates.value[countryId]) {
    commissionRates.value[countryId] = { facility: DEFAULT_RATE, program: DEFAULT_RATE, event: DEFAULT_EVENT_RATE }
  }
  return commissionRates.value[countryId]
}

async function saveCommission(countryId, type) {
  const rate = parseFloat(getRateObj(countryId)[type])
  if (isNaN(rate) || rate < 0 || rate > 100) {
    pushToast('Commission must be a number between 0 and 100.', 'error')
    return
  }
  const key = `${countryId}_${type}`
  commissionSaving.value = new Set(commissionSaving.value).add(key)
  const { error } = await supabase.from('commission_rates').upsert(
    { country_id: countryId, listing_type: type, rate_percent: rate, updated_at: new Date().toISOString() },
    { onConflict: 'country_id,listing_type' }
  )
  commissionSaving.value.delete(key)
  commissionSaving.value = new Set(commissionSaving.value)
  if (error) {   // never show "Saved" for a write that didn't land
    pushToast(`Could not save the commission rate: ${error.message}`, 'error')
    return
  }
  commissionSaved.value = new Set(commissionSaved.value).add(key)
  const cName = countries.value.find(c => c.id === countryId)?.name || 'country'
  logAudit({ action: AUDIT.UPDATED, entity_type: 'commission_rate', entity_id: countryId, details: `Set ${type === 'facility' ? 'facility' : type === 'event' ? 'events per-seat' : 'membership'} commission to ${rate}% for ${cName}` })
  setTimeout(() => {
    commissionSaved.value.delete(key)
    commissionSaved.value = new Set(commissionSaved.value)
  }, 1500)
}

async function load() {
  const [co, ci, cr, lp, lt] = await Promise.all([
    supabase.from('countries').select('id, name, iso_code, currency_code, currency_symbol, timezone, status').order('name'),
    supabase.from('cities').select('id, country_id, name, timezone, status').order('name'),
    supabase.from('commission_rates').select('*'),
    supabase.from('loyalty_programs').select('*'),
    supabase.from('loyalty_tiers').select('*').order('sort_order'),
  ])
  countries.value = co.data || []
  cities.value = ci.data || []
  programs.value = lp.data || []
  tiers.value = lt.data || []
  const rates = {}
  for (const r of (cr.data || [])) {
    if (!rates[r.country_id]) rates[r.country_id] = { facility: DEFAULT_RATE, program: DEFAULT_RATE, event: DEFAULT_EVENT_RATE }
    rates[r.country_id][r.listing_type] = Number(r.rate_percent)
  }
  commissionRates.value = rates
  // Every active country gets a default rewards program out of the box.
  if (await ensureDefaultPrograms()) {
    const [lp2, lt2] = await Promise.all([
      supabase.from('loyalty_programs').select('*'),
      supabase.from('loyalty_tiers').select('*').order('sort_order'),
    ])
    programs.value = lp2.data || []
    tiers.value = lt2.data || []
  }
}
onMounted(load)

// ── Loyalty program (per country) ─────────────────────────────────────────────
// Moved here from the standalone Loyalty page: each country's rewards + tiers are
// configured from its row, in a modal.
const programFor = (countryId) => programs.value.find(p => p.country_id === countryId) || null

const PRESET_TIERS = [
  { name: 'Rookie', color: '#92400e', bg_color: '#fef3c7', threshold: 0,     multiplier: 1   },
  { name: 'Star',   color: '#475569', bg_color: '#f1f5f9', threshold: 1000,  multiplier: 1.2 },
  { name: 'Legend', color: '#b45309', bg_color: '#fef9c3', threshold: 5000,  multiplier: 1.5 },
  { name: 'GOAT',   color: '#0f766e', bg_color: '#ccfbf1', threshold: 15000, multiplier: 2   },
]
function defaultLForm() {
  // Defaults for every currency: 1 point per unit spent; 100 points = 1 unit off.
  // Redemption is always in 1-unit steps (redeem_value fixed at 1) and funded by
  // Bookboll's platform fee only (enforced at checkout).
  return { name: 'Bookboll Rewards', earn_rate: 1, redeem_rate: 100, redeem_value: 1, min_redeem: 100, max_redeem_pct: 100, expiry_months: 12, is_active: true }
}
function defaultLTier(sortOrder = 0) {
  return { name: '', color: '#92400e', bg_color: '#fef3c7', threshold: 0, multiplier: 1, sort_order: sortOrder }
}

// Auto-provision a default (active) rewards program + preset tiers for every active
// country that doesn't have one yet. Self-healing: runs on load, so newly-enabled
// countries get one too. Returns true if it created anything.
async function ensureDefaultPrograms() {
  if (!isSuperAdmin.value) return false
  const missing = countries.value.filter(c => c.status === 'active' && !programs.value.some(p => p.country_id === c.id))
  if (!missing.length) return false
  const rows = missing.map(c => ({ country_id: c.id, ...defaultLForm(), updated_at: new Date().toISOString() }))
  const { data: created, error } = await supabase.from('loyalty_programs').insert(rows).select()
  if (error || !created?.length) return false
  const tierRows = []
  for (const p of created) {
    PRESET_TIERS.forEach((t, i) => tierRows.push({
      program_id: p.id, name: t.name, color: t.color, bg_color: t.bg_color,
      threshold: t.threshold, multiplier: t.multiplier, sort_order: i,
    }))
  }
  if (tierRows.length) {
    const { error: tErr } = await supabase.from('loyalty_tiers').insert(tierRows)
    // A failed tier insert isn't self-healing (the program now exists, so the
    // next load skips it) — surface it so the admin adds tiers by hand.
    if (tErr) pushToast('Default loyalty tiers could not be created — open the program and add tiers.', 'error')
  }
  return true
}

// Quick enable/disable straight from the country panel.
async function toggleLoyaltyActive(country) {
  const p = programFor(country.id)
  if (!p) return
  const newVal = !p.is_active
  const { error } = await supabase.from('loyalty_programs').update({ is_active: newVal, updated_at: new Date().toISOString() }).eq('id', p.id)
  if (error) { pushToast('Could not update loyalty program.', 'error'); return }
  p.is_active = newVal
  pushToast(newVal ? 'Loyalty program enabled' : 'Loyalty program disabled', 'success')
}

const loyaltyCountry = ref(null)   // the country whose program is being edited
const lForm = ref(defaultLForm())
const lTiers = ref([])
const lProgramId = ref(null)
const lSaving = ref(false)
const lTriedSave = ref(false)

function openLoyalty(country) {
  loyaltyCountry.value = country
  const p = programFor(country.id)
  lProgramId.value = p?.id || null
  lTriedSave.value = false
  if (p) {
    // redeem_value is standardised to 1 unit for every currency — coerce any legacy value.
    lForm.value = { name: p.name, earn_rate: p.earn_rate, redeem_rate: p.redeem_rate, redeem_value: 1, min_redeem: p.min_redeem, max_redeem_pct: p.max_redeem_pct, expiry_months: p.expiry_months, is_active: p.is_active }
    lTiers.value = tiers.value.filter(t => t.program_id === p.id).slice().sort((a, b) => a.sort_order - b.sort_order).map(t => ({ ...t }))
  } else {
    lForm.value = defaultLForm()
    lTiers.value = PRESET_TIERS.map((t, i) => ({ ...defaultLTier(i), ...t }))
  }
}
function closeLoyalty() { loyaltyCountry.value = null }
function addLTier() { lTiers.value.push({ ...defaultLTier(lTiers.value.length), name: '' }) }
function removeLTier(i) { lTiers.value.splice(i, 1) }

async function saveLoyalty() {
  const country = loyaltyCountry.value
  if (!country) return
  lTriedSave.value = true
  if (!lForm.value.name?.trim()) { pushToast('Program name is required.', 'error'); return }
  // Every numeric field must be a real number — Number('') is NaN and would be
  // rejected by the NOT NULL numeric columns.
  const nums = {
    earn_rate: Number(lForm.value.earn_rate), redeem_rate: Number(lForm.value.redeem_rate),
    redeem_value: 1,   // standardised: redemptions are always in 1-currency-unit steps
    min_redeem: Number(lForm.value.min_redeem),
    max_redeem_pct: Number(lForm.value.max_redeem_pct), expiry_months: Number(lForm.value.expiry_months),
  }
  const bad = Object.entries(nums).find(([, v]) => !Number.isFinite(v) || v < 0)
  if (bad) { pushToast('All loyalty numbers must be filled in (0 or more).', 'error'); return }
  lSaving.value = true
  let pid = lProgramId.value
  const payload = {
    country_id: country.id,
    name: lForm.value.name,
    ...nums,
    is_active: lForm.value.is_active,
    updated_at: new Date().toISOString(),
  }
  // Every write is checked: loyalty tables are RLS-restricted to super admins,
  // so a blocked write must surface as an error — never a green "saved" toast.
  let werr = null
  if (pid) {
    const r = await supabase.from('loyalty_programs').update(payload).eq('id', pid)
    werr = r.error
  } else {
    const { data, error } = await supabase.from('loyalty_programs').insert(payload).select().maybeSingle()
    werr = error
    pid = data?.id
    if (!werr && !pid) werr = { message: 'The program was not created.' }
  }
  if (!werr && pid) {
    const del = await supabase.from('loyalty_tiers').delete().eq('program_id', pid)
    werr = del.error
    const valid = lTiers.value.filter(t => t.name.trim())
    if (!werr && valid.length) {
      const ins = await supabase.from('loyalty_tiers').insert(valid.map((t, i) => ({
        program_id: pid, name: t.name, color: t.color, bg_color: t.bg_color,
        threshold: Number(t.threshold) || 0, multiplier: Number(t.multiplier) || 1, sort_order: i,
      })))
      werr = ins.error
    }
  }
  lSaving.value = false
  if (werr) {
    pushToast(`Could not save the loyalty program: ${werr.message}`, 'error')
    return   // keep the modal open so nothing typed is lost
  }
  await load()
  closeLoyalty()
  pushToast('Loyalty program saved', 'success')
}


function citiesOf(countryId) {
  return cities.value.filter(c => c.country_id === countryId)
}

const filtered = computed(() => {
  const q = search.value.trim().toLowerCase()
  const base = scopedCountries.value
  if (!q) return base
  return base.filter(c =>
    c.name.toLowerCase().includes(q) ||
    c.iso_code?.toLowerCase().includes(q) ||
    citiesOf(c.id).some(ci => ci.name.toLowerCase().includes(q))
  )
})

const { page: countryPage, perPage: countryPerPage, totalPages: countryTotalPages, paginated: paginatedCountries, visiblePages: countryVisiblePages, goTo: countryGoTo } = usePagination(filtered, 25)

function toggle(id) {
  if (expanded.value.has(id)) expanded.value.delete(id)
  else expanded.value.add(id)
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
  const { error } = await supabase.from('countries').update({ status: newStatus }).eq('id', country.id)
  saving.value.delete(country.id)
  saving.value = new Set(saving.value)
  if (error) { pushToast(`Could not update ${country.name}.`, 'error'); return }
  country.status = newStatus
  flash(country.id)
  logAudit({ action: newStatus === 'active' ? AUDIT.ENABLED : AUDIT.DISABLED, entity_type: 'country', entity_id: country.id, details: `${newStatus === 'active' ? 'Enabled' : 'Disabled'} country ${country.name}` })
}

async function toggleCity(city) {
  if (saving.value.has(city.id)) return
  saving.value = new Set(saving.value).add(city.id)
  const newStatus = city.status === 'active' ? 'inactive' : 'active'
  const { error } = await supabase.from('cities').update({ status: newStatus }).eq('id', city.id)
  saving.value.delete(city.id)
  saving.value = new Set(saving.value)
  if (error) { pushToast(`Could not update ${city.name}.`, 'error'); return }
  city.status = newStatus
  flash(city.id)
}

// toggle all cities in a country at once
const bulkSaving = ref(false)
async function toggleAllCities(countryId, targetStatus) {
  if (bulkSaving.value) return
  const batch = citiesOf(countryId).filter(c => c.status !== targetStatus)
  if (!batch.length) return
  bulkSaving.value = true
  const { error } = await supabase.from('cities').update({ status: targetStatus }).in('id', batch.map(c => c.id))
  bulkSaving.value = false
  if (error) { pushToast('Could not update the cities.', 'error'); return }
  batch.forEach(c => { c.status = targetStatus })
}

function activeCount(countryId) {
  return citiesOf(countryId).filter(c => c.status === 'active').length
}
</script>

<template>
  <div class="page">
    <div class="page-header">
      <div>
        <div class="page-title">Countries &amp; Cities</div>
        <div class="page-subtitle">Enable countries &amp; cities, and set each country's commission and loyalty program</div>
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
        v-for="country in paginatedCountries"
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
            <span class="meta-pill">{{ country.currency_code }}</span>
            <span class="meta-pill city-count">
              <MapPin :size="10" />
              {{ activeCount(country.id) }} / {{ citiesOf(country.id).length }}
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

          <!-- Loyalty program (Super Admin + Country Admin in jurisdiction) -->
          <!-- Loyalty writes are RLS-restricted to SUPER admins — a country admin's
               edits would be silently discarded by the database, so don't offer them. -->
          <div v-if="isSuperAdmin" class="loyalty-section">
            <div class="loyalty-left">
              <div class="commission-section-title" style="margin-bottom:8px"><Award :size="13" />Loyalty Program</div>
              <div v-if="programFor(country.id)" class="loyalty-summary">
                <button
                  class="ly-status-toggle"
                  :class="programFor(country.id).is_active ? 'on' : 'off'"
                  @click.stop="toggleLoyaltyActive(country)"
                  :title="programFor(country.id).is_active ? 'Click to disable' : 'Click to enable'"
                >
                  <ToggleRight v-if="programFor(country.id).is_active" :size="17" />
                  <ToggleLeft v-else :size="17" />
                  {{ programFor(country.id).is_active ? 'Active' : 'Inactive' }}
                </button>
                <span class="loyalty-sum-text">
                  {{ programFor(country.id).earn_rate }} pt per {{ country.currency_code }} ·
                  {{ programFor(country.id).redeem_rate }} pts = {{ country.currency_code }} 1 off
                </span>
              </div>
              <div v-else class="loyalty-sum-text muted">{{ country.status === 'active' ? 'No loyalty program yet.' : 'Enable this country to add a loyalty program.' }}</div>
            </div>
            <button class="btn btn-secondary btn-sm" @click.stop="openLoyalty(country)">
              <Award :size="13" />{{ programFor(country.id) ? 'Edit' : 'Set up' }}
            </button>
          </div>

          <!-- Cities -->
          <div class="cities-panel-header">
            <span>Cities</span>
            <div style="display:flex;gap:8px;">
              <button class="link-btn" @click.stop="toggleAllCities(country.id, 'active')">Enable all</button>
              <button class="link-btn muted" @click.stop="toggleAllCities(country.id, 'inactive')">Disable all</button>
            </div>
          </div>
          <div class="cities-grid">
            <div
              v-for="city in citiesOf(country.id)"
              :key="city.id"
              class="city-item"
              :class="{ inactive: city.status !== 'active' }"
            >
              <!-- City enable / disable row -->
              <div class="city-top" @click="toggleCity(city)">
                <div class="cb sm" :class="{ active: city.status === 'active', saving: saving.has(city.id) }">
                  <Check v-if="city.status === 'active'" :size="9" stroke-width="3" />
                </div>
                <span class="city-name">{{ city.name }}</span>
                <Check v-if="savedRecently.has(city.id)" :size="11" class="saved-flash" />
              </div>
              <!-- Service module toggles — when city is active and user can manage geography -->
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

      <div v-if="!filtered.length" class="empty-state">
        No countries match "{{ search }}"
      </div>
    </div>
    <PaginationBar
      v-if="filtered.length > 0"
      :page="countryPage" :perPage="countryPerPage" :totalPages="countryTotalPages"
      :total="filtered.length" :visiblePages="countryVisiblePages"
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
              <div class="ly-sub">Per-country rewards &amp; membership tiers</div>
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
            <!-- Earn & redeem rules -->
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
                    <label class="form-label">Points for {{ loyaltyCountry.currency_code }} 1 discount</label>
                    <input type="number" v-model="lForm.redeem_rate" min="1" />
                    <div class="ly-hint">e.g. 100 = 100 points redeem {{ loyaltyCountry.currency_code }} 1 off. Redemptions come out of Bookboll's fee, never the provider's share.</div>
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

            <!-- Tiers -->
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
              </div>
            </div>
          </div>

          <div class="ly-foot">
            <div class="ly-foot-hint">Toggle <b>Active</b> above to disable rewards — every country keeps its default program.</div>
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
