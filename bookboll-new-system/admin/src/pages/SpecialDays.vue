<script setup>
import { ref, computed, onMounted, watch, nextTick } from 'vue'
import { useRoute } from 'vue-router'
import { askConfirm } from '../lib/confirm.js'
import { pushToast } from '../lib/toast.js'
import { markDirty, clearDirty, confirmDiscardIfDirty } from '../lib/unsavedGuard.js'
import { fmt12, fmtRange12, toMinutes, endMinutes } from '../lib/slots.js'
import {
  fetchSpecialDaysDashboard,
  createSpecialDays,
  updateSpecialDay,
  deleteSpecialDay,
  clearSpecialDays,
  countSpecialDayConflicts,
  firstError,
} from '../lib/specialDays.js'
import {
  CalendarDays, X, Trash2, Pencil as Edit2,
  ChevronLeft, ChevronRight, Ban, Clock,
  MapPin, AlertTriangle, Info, Check, CalendarX, CalendarClock,
} from 'lucide-vue-next'
import { useCurrentUser } from '../lib/currentUser.js'
import MultiSelect from '../components/MultiSelect.vue'

const { isSuperAdmin, isCountryAdmin, currentOwnerUserId, isProviderScoped, scopeLocations } = useCurrentUser()
const isPlatformAdmin = computed(() => isSuperAdmin.value || isCountryAdmin.value)

// ── data ──────────────────────────────────────────────────────────────────────
const days      = ref([])
const locations = ref([])
const listings  = ref([])
const showModal = ref(false)
const triedSave = ref(false)
const editing   = ref(null)
const saving    = ref(false)
const modalMode = ref('create')  // 'create' (dates preselected on page) | 'edit'
const form      = ref(defaultForm())

// Calendar state (drives the main page calendar)
const calYear   = ref(new Date().getFullYear())
const calMonth  = ref(new Date().getMonth())
const selDates  = ref([])   // ['YYYY-MM-DD', ...]

function defaultForm() {
  return {
    name: '', type: 'holiday', location_id: '', listing_id: '',
    open_time: '09:00', close_time: '23:00',
    // OPTIONAL pricing override (custom days only): 'none' = follow the
    // listing's base & dynamic pricing; 'flat' = one price per slot;
    // 'percent' = adjust the listing's resolved price by ±%.
    priceMode: 'none', custom_price: '', pct: '',
  }
}
// CREATE-mode scope: pick MANY venues, then (optionally) many listings within
// them. Empty venues = all my venues; empty listings = whole venue(s).
const selLocIds     = ref([])
const selListingIds = ref([])
const targetLocIds = computed(() =>
  selLocIds.value.length ? selLocIds.value : locations.value.map(l => l.id))
const venueOptions = computed(() => locations.value.map(l => ({ value: l.id, label: l.name })))
const listingOptions = computed(() => {
  const set = new Set(targetLocIds.value)
  return listings.value.filter(l => set.has(l.location_id))
    .map(l => ({ value: l.id, label: `${l.name}${selLocIds.value.length !== 1 ? ` — ${locationName(l.location_id)}` : ''}` }))
})
// Deselecting a venue prunes listings that no longer belong to the scope.
watch(selLocIds, () => {
  const set = new Set(targetLocIds.value)
  selListingIds.value = selListingIds.value.filter(id => set.has(listings.value.find(l => l.id === id)?.location_id))
}, { deep: true })

// ── quick select (rapid multi-date picking) ───────────────────────────────────
const qsFrom = ref(''); const qsTo = ref(''); const qsDows = ref([])
function toggleQsDow(d) {
  const i = qsDows.value.indexOf(d)
  if (i >= 0) qsDows.value.splice(i, 1); else qsDows.value.push(d)
}
function addQuickSelect() {
  if (!qsFrom.value || !qsTo.value || qsTo.value < qsFrom.value) {
    pushToast('Pick a valid From / To range first.', 'error'); return
  }
  const set = new Set(selDates.value)
  let added = 0
  const d = parseDateLocal(qsFrom.value); const end = parseDateLocal(qsTo.value)
  for (let i = 0; i < 370 && d <= end; i++, d.setDate(d.getDate() + 1)) {
    if (qsDows.value.length && !qsDows.value.includes(d.getDay())) continue
    const iso = fmtDate(d)
    if (!set.has(iso)) { set.add(iso); added++ }
  }
  selDates.value = [...set]
  pushToast(added ? `Added ${added} date${added > 1 ? 's' : ''} to the selection.` : 'Those dates were already selected.', added ? 'success' : 'info')
}

// ── bulk clear ────────────────────────────────────────────────────────────────
const showClear = ref(false)
const clearing  = ref(false)
const cForm = ref({ location_id: '', listing_id: '', type: 'all', dates: 'upcoming', from: '', to: '' })
function openClear(preset = {}) {
  cForm.value = { location_id: '', listing_id: '', type: 'all', dates: selDates.value.length ? 'selected' : 'upcoming', from: '', to: '', ...preset }
  showClear.value = true
}
const cListings = computed(() => listings.value.filter(l => l.location_id === cForm.value.location_id))
watch(() => cForm.value.location_id, () => { cForm.value.listing_id = '' })
// Rows the current bulk-clear selection would delete (live preview count).
const clearMatches = computed(() => {
  const f = cForm.value
  return days.value.filter(d => {
    if (f.location_id && d.location_id !== f.location_id) return false
    if (f.listing_id && d.listing_id !== f.listing_id) return false
    if (f.type === 'off' && !d.is_closed) return false
    if (f.type === 'custom' && d.is_closed) return false
    if (f.dates === 'selected' && !selDates.value.includes(d.date)) return false
    if (f.dates === 'upcoming' && d.date < today) return false
    if (f.dates === 'past' && d.date >= today) return false
    if (f.dates === 'range') {
      if (f.from && d.date < f.from) return false
      if (f.to && d.date > f.to) return false
      if (!f.from && !f.to) return false
    }
    return true
  })
})
async function runClear() {
  const rows = clearMatches.value
  if (!rows.length) return
  if (!(await askConfirm({
    title: `Clear ${rows.length} special day${rows.length > 1 ? 's' : ''}?`,
    message: 'The normal weekly schedule (and listing pricing) will apply again on those dates. This cannot be undone.',
    confirmText: `Clear ${rows.length}`,
  }))) return
  clearing.value = true
  try {
    const ids = rows.map(r => r.id)
    const res = await clearSpecialDays(ids)
    if (!res.success) throw new Error(firstError(res, 'Could not clear special days.'))
    pushToast(`Cleared ${ids.length} special day${ids.length > 1 ? 's' : ''}.`, 'success')
    showClear.value = false
    await load()
  } catch (e) {
    pushToast(e?.message || 'Could not clear special days.', 'error')
  } finally { clearing.value = false }
}

// ── existing-bookings conflict check (shown in the configure modal) ───────────
const conflictCount = ref(0)
async function checkConflicts() {
  conflictCount.value = 0
  if (!showModal.value || !selDates.value.length) return
  const locIds = modalMode.value === 'create' ? targetLocIds.value
    : (form.value.location_id ? [form.value.location_id] : [])
  if (!locIds.length) return
  const listingIds = modalMode.value === 'create'
    ? selListingIds.value
    : (form.value.listing_id ? [form.value.listing_id] : [])
  conflictCount.value = await countSpecialDayConflicts({
    dates: selDates.value,
    locationIds: locIds,
    listingIds,
  })
}
watch([showModal, selLocIds, selListingIds, () => form.value.location_id, () => form.value.listing_id], checkConflicts, { deep: true })

// ── load ──────────────────────────────────────────────────────────────────────
async function load() {
  const res = await fetchSpecialDaysDashboard()
  if (!res.success) {
    pushToast(res.message || 'Could not load special days.', 'error')
    days.value = []
    locations.value = []
    listings.value = []
    return
  }
  const d = res.data || {}
  let locs = d.locations || []
  if (isProviderScoped.value) {
    locs = scopeLocations(locs)
  } else if (!isPlatformAdmin.value && currentOwnerUserId.value) {
    locs = locs.filter(l => l.owner_user_id === currentOwnerUserId.value)
  }
  locations.value = locs
  const ownedLocIds = new Set(locations.value.map(x => x.id))
  listings.value = (d.listings || []).filter(x => isPlatformAdmin.value || ownedLocIds.has(x.location_id))
  days.value = (d.special_days || []).filter(x => isPlatformAdmin.value || ownedLocIds.has(x.location_id))
}
const route = useRoute()
// Arriving from the listing form's "Add a Special Day": this page is date-first
// (pick dates on the calendar, then Custom Schedule). Pre-filter to the location
// and remember the target so the form is prefilled once dates are chosen.
const pendingPrefill = ref(null)
onMounted(async () => {
  await load()
  if (route.query.new === '1') {
    pendingPrefill.value = {
      location_id: route.query.location_id ? String(route.query.location_id) : '',
      listing_id:  route.query.listing_id  ? String(route.query.listing_id)  : '',
    }
    pushToast('Select the date(s) on the calendar, then choose “Custom Schedule”.', 'info', 6000)
  }
})
watch(currentOwnerUserId, load)

// ── helpers ──────────────────────────────────────────────────────────────────
const MONTH_NAMES = ['January','February','March','April','May','June','July','August','September','October','November','December']
const DOW_SHORT   = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat']
const TIMES = Array.from({ length: 96 }, (_, i) => {
  const h = Math.floor(i / 4), m = (i % 4) * 15      // 15-minute increments
  return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`
})

function fmtDate(d) {
  return `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`
}
function parseDateLocal(str) { const [y,m,d] = str.split('-').map(Number); return new Date(y, m-1, d) }
function displayDate(str) { return parseDateLocal(str).toLocaleDateString('en-US', { weekday:'short', month:'short', day:'numeric', year:'numeric' }) }
function shortDate(str) { return parseDateLocal(str).toLocaleDateString('en-US', { month:'short', day:'numeric' }) }
const today = fmtDate(new Date())

function locationName(id) { return locations.value.find(l => l.id === id)?.name || 'All locations' }
function listingName(id)  { return id ? (listings.value.find(l => l.id === id)?.name || '—') : null }
const typeColor = t => (t === 'custom' ? '#6366f1' : '#ef4444')

// ── calendar grid ─────────────────────────────────────────────────────────────
const calDays = computed(() => {
  const first = new Date(calYear.value, calMonth.value, 1)
  const last  = new Date(calYear.value, calMonth.value + 1, 0)
  const startDow = first.getDay()
  const grid = []
  for (let i = startDow - 1; i >= 0; i--) grid.push({ date: fmtDate(new Date(calYear.value, calMonth.value, -i)), inMonth: false })
  for (let d = 1; d <= last.getDate(); d++) grid.push({ date: fmtDate(new Date(calYear.value, calMonth.value, d)), inMonth: true })
  while (grid.length % 7 !== 0) { const p = parseDateLocal(grid[grid.length-1].date); p.setDate(p.getDate()+1); grid.push({ date: fmtDate(p), inMonth: false }) }
  return grid
})

// Map date → events (respecting the location filter)
const eventsByDate = computed(() => {
  const map = {}
  for (const d of days.value) (map[d.date] = map[d.date] || []).push(d)
  return map
})
function eventsOn(date) { return eventsByDate.value[date] || [] }

function prevMonth() { if (calMonth.value === 0) { calMonth.value = 11; calYear.value-- } else calMonth.value-- }
function nextMonth() { if (calMonth.value === 11) { calMonth.value = 0; calYear.value++ } else calMonth.value++ }
function goToday()   { calYear.value = new Date().getFullYear(); calMonth.value = new Date().getMonth() }

function toggleDate(date) {
  const i = selDates.value.indexOf(date)
  if (i >= 0) selDates.value.splice(i, 1)
  else selDates.value.push(date)
}
function isSelected(date) { return selDates.value.includes(date) }
function clearSelection() { selDates.value = [] }

// ── listing selector ───────────────────────────────────────────────────────────
// Both types scope to the whole venue (blank listing) or one listing inside it.
const listingsForLocation = computed(() => listings.value.filter(l => l.location_id === form.value.location_id))
watch(() => form.value.location_id, () => { form.value.listing_id = '' })

// Custom hours must be a real window.
// (close 12:00 AM = midnight at the END of the day, so it's a valid window end)
const timeInvalid = computed(() =>
  form.value.type === 'custom' &&
  endMinutes(form.value.close_time) <= toMinutes(form.value.open_time))

const priceInvalid = computed(() => {
  if (form.value.type !== 'custom') return false
  if (form.value.priceMode === 'flat') return !(Number(form.value.custom_price) > 0)
  if (form.value.priceMode === 'percent') {
    const p = Number(form.value.pct)
    return !Number.isFinite(p) || p === 0 || p <= -100
  }
  return false
})
const canSave = computed(() => {
  if (modalMode.value === 'create' ? !targetLocIds.value.length : !form.value.location_id) return false
  if (timeInvalid.value || priceInvalid.value) return false
  if (modalMode.value === 'create' && !selDates.value.length) return false
  return true
})

// ── open configure (from page selection) ──────────────────────────────────────
function openConfigure(type) {
  if (!selDates.value.length) return
  editing.value = null
  modalMode.value = 'create'
  selLocIds.value = []
  selListingIds.value = []
  form.value = { ...defaultForm(), type }
  // Apply a prefill carried over from the listing form, if any.
  if (pendingPrefill.value && type === 'custom') {
    if (pendingPrefill.value.location_id) selLocIds.value = [pendingPrefill.value.location_id]
    if (pendingPrefill.value.listing_id) nextTick(() => { selListingIds.value = [pendingPrefill.value && pendingPrefill.value.listing_id].filter(Boolean); pendingPrefill.value = null })
    else pendingPrefill.value = null
  }
  triedSave.value = false
  clearDirty()
  showModal.value = true
}
function openEdit(d) {
  editing.value = d
  modalMode.value = 'edit'
  const mult = Number(d.price_multiplier)
  form.value = {
    name: d.name,
    type: d.type === 'custom' || (d.open_time && !d.is_closed) ? 'custom' : 'holiday',
    location_id: d.location_id || '',
    listing_id: '',
    open_time: (d.open_time || '09:00').slice(0, 5),
    close_time: (d.close_time || '23:00').slice(0, 5),
    priceMode: d.custom_price != null ? 'flat' : (Number.isFinite(mult) && mult > 0 && mult !== 1 ? 'percent' : 'none'),
    custom_price: d.custom_price != null ? String(d.custom_price) : '',
    pct: Number.isFinite(mult) && mult > 0 && mult !== 1 ? String(Math.round((mult - 1) * 100)) : '',
  }
  // location_id assignment above triggers the watcher that clears listing_id →
  // restore the row's listing on the next tick so the edit form shows its scope.
  if (d.listing_id) nextTick(() => { form.value.listing_id = d.listing_id })
  const dt = parseDateLocal(d.date)
  calYear.value = dt.getFullYear(); calMonth.value = dt.getMonth()
  selDates.value = [d.date]
  triedSave.value = false
  clearDirty()
  showModal.value = true
}
function closeModal() { clearDirty(); showModal.value = false; editing.value = null }
async function requestClose() { if (await confirmDiscardIfDirty()) closeModal() }

// ── save ──────────────────────────────────────────────────────────────────────
async function save() {
  triedSave.value = true
  if (!canSave.value) { pushToast('Please complete the highlighted required fields.', 'error'); return }
  saving.value = true
  try {
    // OPTIONAL pricing override (custom days only): flat price, ±% on the
    // listing's resolved price, or none (listing base & dynamic pricing apply).
    const pricing = form.value.type === 'custom' && form.value.priceMode === 'flat'
      ? { custom_price: Number(form.value.custom_price), price_multiplier: 1 }
      : form.value.type === 'custom' && form.value.priceMode === 'percent'
        ? { custom_price: null, price_multiplier: Math.round((1 + Number(form.value.pct) / 100) * 10000) / 10000 }
        : { custom_price: null, price_multiplier: 1 }
    const base = {
      name: form.value.name || (form.value.type === 'holiday' ? 'Holiday' : 'Custom Day'),
      type: form.value.type === 'custom' ? 'custom' : 'holiday',
      location_id: form.value.location_id,
      listing_id: form.value.listing_id || null,
      is_closed: form.value.type === 'holiday',
      open_time: form.value.type === 'custom' ? form.value.open_time : null,
      close_time: form.value.type === 'custom' ? form.value.close_time : null,
      ...pricing,
    }
    let res
    if (editing.value) {
      res = await updateSpecialDay(editing.value.id, { ...base, date: selDates.value[0] })
    } else if (selListingIds.value.length) {
      // Listing-specific rows: one per (listing × date); each row carries its
      // listing's own venue so precedence resolves correctly.
      const rows = []
      for (const lid of selListingIds.value) {
        const locId = listings.value.find(l => l.id === lid)?.location_id
        if (!locId) continue
        for (const date of selDates.value) rows.push({ ...base, location_id: locId, listing_id: lid, date })
      }
      res = await createSpecialDays(rows)
    } else {
      // Venue-wide rows: one per (venue × date). Empty venue selection = all.
      const rows = []
      for (const locId of targetLocIds.value)
        for (const date of selDates.value) rows.push({ ...base, location_id: locId, listing_id: null, date })
      res = await createSpecialDays(rows)
    }
    if (!res.success) throw new Error(firstError(res, 'Could not save the special day.'))
    clearDirty()
    pushToast(editing.value ? 'Special day updated' : `Special day${selDates.value.length > 1 ? 's' : ''} saved`, 'success')
    closeModal()
    clearSelection()
    await load()
  } catch (e) {
    pushToast(e?.message || 'Could not save the special day.', 'error')
  } finally { saving.value = false }
}
async function remove(id) {
  if (!(await askConfirm({
    title: 'Delete special day?',
    message: 'This special day will be removed and the normal schedule will apply on that date.',
    confirmText: 'Delete',
  }))) return
  const res = await deleteSpecialDay(id)
  if (!res.success) { pushToast(firstError(res, 'Could not delete special day.'), 'error'); return }
  pushToast('Special day deleted', 'success')
  load()
}

// ── list (side panel) ──────────────────────────────────────────────────────────
const upcoming = computed(() => days.value.filter(d => d.date >= today))
const pastCount = computed(() => days.value.filter(d => d.date < today).length)
// Pricing badge for the upcoming list.
function priceBadge(d) {
  if (d.is_closed) return ''
  if (d.custom_price != null) return `Flat ${Number(d.custom_price)}`
  const m = Number(d.price_multiplier)
  if (Number.isFinite(m) && m > 0 && m !== 1) return `${m > 1 ? '+' : ''}${Math.round((m - 1) * 100)}%`
  return ''
}
const selectedSorted = computed(() => selDates.value.slice().sort())
</script>

<template>
  <div class="page">
    <div class="page-header">
      <div>
        <div class="page-title">Special Days</div>
        <div class="page-subtitle">Tap dates on the calendar, then close them or override the schedule for a venue or a single listing — with optional special pricing for those dates.</div>
      </div>
    </div>

    <div class="sd-layout">
      <!-- ── Main calendar ─────────────────────────────────────────────── -->
      <div class="card sd-cal-card">
        <div class="sd-cal-nav">
          <div class="sd-cal-title">{{ MONTH_NAMES[calMonth] }} {{ calYear }}</div>
          <div class="sd-cal-nav-btns">
            <button class="btn btn-secondary btn-sm" @click="goToday">Today</button>
            <button class="cal-nav-btn" @click="prevMonth"><ChevronLeft :size="16" /></button>
            <button class="cal-nav-btn" @click="nextMonth"><ChevronRight :size="16" /></button>
          </div>
        </div>

        <div class="sd-grid sd-grid-head">
          <div v-for="d in DOW_SHORT" :key="d" class="sd-dow">{{ d }}</div>
        </div>
        <div class="sd-grid">
          <button
            v-for="cell in calDays" :key="cell.date"
            type="button"
            class="sd-cell"
            :class="{ 'out-month': !cell.inMonth, 'selected': isSelected(cell.date), 'today': cell.date === today, 'is-past': cell.date < today }"
            :disabled="!cell.inMonth"
            @click="cell.inMonth && toggleDate(cell.date)"
          >
            <span class="sd-cell-num">{{ parseDateLocal(cell.date).getDate() }}</span>
            <span v-if="isSelected(cell.date)" class="sd-cell-check"><Check :size="12" /></span>
            <div class="sd-cell-events">
              <span
                v-for="ev in eventsOn(cell.date).slice(0,2)" :key="ev.id"
                class="sd-event-chip"
                :style="{ background: typeColor(ev.type) + '22', color: typeColor(ev.type) }"
                :title="`${ev.name || (ev.is_closed ? 'Closed' : 'Custom')} · ${locationName(ev.location_id)}`"
                @click.stop="openEdit(ev)"
              >
                <component :is="ev.is_closed ? CalendarX : CalendarClock" :size="10" />
                {{ ev.is_closed ? 'Off' : fmt12((ev.open_time || '').slice(0,5)) }}
              </span>
              <span v-if="eventsOn(cell.date).length > 2" class="sd-event-more">+{{ eventsOn(cell.date).length - 2 }}</span>
            </div>
          </button>
        </div>

        <div class="sd-legend">
          <span><span class="lg-dot" style="background:#ef4444"></span>Off-day / Holiday</span>
          <span><span class="lg-dot" style="background:#6366f1"></span>Custom schedule</span>
          <span><span class="lg-dot lg-sel"></span>Selected</span>
        </div>
      </div>

      <!-- ── Side: selection actions + upcoming list ──────────────────── -->
      <div class="sd-side">
        <div class="card sd-selection-card">
          <div class="sd-side-title">Selected dates</div>
          <div v-if="!selDates.length" class="sd-side-empty">
            <CalendarDays :size="26" style="opacity:.3" />
            <div>Click dates on the calendar to select them.</div>
          </div>
          <template v-else>
            <div class="sd-sel-chips">
              <span v-for="d in selectedSorted" :key="d" class="sd-sel-chip">
                {{ shortDate(d) }}
                <button @click="toggleDate(d)"><X :size="10" /></button>
              </span>
            </div>
            <div class="sd-action-btns">
              <button class="btn btn-danger" @click="openConfigure('holiday')"><CalendarX :size="15" />Mark as Off-day</button>
              <button class="btn btn-primary" @click="openConfigure('custom')"><CalendarClock :size="15" />Custom Schedule</button>
              <button class="btn btn-secondary btn-sm" @click="openClear({ dates: 'selected' })"><Trash2 :size="13" />Clear special days on these dates</button>
              <button class="btn btn-secondary btn-sm" @click="clearSelection">Clear selection</button>
            </div>
          </template>
        </div>

        <div class="card sd-qs-card">
          <div class="sd-side-title">Quick select</div>
          <div class="sd-qs-range">
            <input type="date" v-model="qsFrom" aria-label="From" />
            <span class="sd-qs-sep">→</span>
            <input type="date" v-model="qsTo" aria-label="To" />
          </div>
          <div class="sd-qs-dows">
            <button v-for="(d, i) in DOW_SHORT" :key="d" type="button" class="sd-qs-dow"
              :class="{ on: qsDows.includes(i) }" @click="toggleQsDow(i)">{{ d }}</button>
          </div>
          <button class="btn btn-secondary btn-sm sd-qs-add" @click="addQuickSelect">Add to selection</button>
          <div class="sd-qs-hint">e.g. every Friday for the summer: pick the range, tap Fri, Add. No weekday tapped = every day in the range.</div>
        </div>

        <div class="card sd-upcoming-card">
          <div class="sd-up-head">
            <div class="sd-side-title">Upcoming ({{ upcoming.length }})</div>
            <button class="sd-clear-link" @click="openClear()"><Trash2 :size="12" />Bulk clear…</button>
          </div>
          <div v-if="!upcoming.length" class="sd-side-empty-sm">None scheduled.</div>
          <div v-if="pastCount" class="sd-past-row">
            {{ pastCount }} past special day{{ pastCount > 1 ? 's' : '' }} on record
            <button class="sd-clear-link" @click="openClear({ dates: 'past' })">clean up</button>
          </div>
          <div v-else class="sd-up-list">
            <div v-for="d in upcoming" :key="d.id" class="sd-up-item" :style="`border-left:3px solid ${typeColor(d.type)}`">
              <div class="sd-up-main">
                <div class="sd-up-name">{{ d.name || (d.is_closed ? 'Off-day' : 'Custom') }}</div>
                <div class="sd-up-date">{{ displayDate(d.date) }}</div>
                <div class="sd-up-meta">
                  <MapPin :size="10" />{{ locationName(d.location_id) }}
                  <template v-if="listingName(d.listing_id)"> · {{ listingName(d.listing_id) }}</template>
                  <template v-if="d.open_time"> · {{ fmtRange12(d.open_time.slice(0,5), d.close_time.slice(0,5)) }}</template>
                  <span v-if="priceBadge(d)" class="sd-price-badge">{{ priceBadge(d) }}</span>
                </div>
              </div>
              <div class="sd-up-actions">
                <button class="btn-icon" @click="openEdit(d)"><Edit2 :size="13" /></button>
                <button class="btn-icon danger" @click="remove(d.id)"><Trash2 :size="13" /></button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ═══════════════ CONFIGURE MODAL ═══════════════════════════════════ -->
    <div v-if="showModal" class="modal-backdrop" @click.self="requestClose">
      <div class="modal special-modal" @input="markDirty" @change="markDirty">
        <div class="modal-header">
          <div class="modal-title">
            {{ editing ? 'Edit Special Day' : (form.type === 'holiday' ? 'Mark Off-days' : 'Set Custom Schedule') }}
          </div>
          <button class="btn-icon" @click="requestClose"><X :size="18" /></button>
        </div>

        <div class="modal-body sd-modal-body">
          <div v-if="triedSave && modalMode === 'create' && !selDates.length" class="form-error-banner">
            <AlertTriangle :size="15" />Please pick at least one date on the calendar.
          </div>
          <!-- Dates summary -->
          <div class="sd-dates-summary">
            <CalendarDays :size="14" />
            <template v-if="editing">{{ displayDate(selDates[0]) }}</template>
            <template v-else>
              <span class="sd-ds-count">{{ selDates.length }} date{{ selDates.length > 1 ? 's' : '' }}</span>
              <span class="sd-ds-list">{{ selectedSorted.map(shortDate).join(', ') }}</span>
            </template>
          </div>

          <!-- Type toggle -->
          <div class="field-section">
            <div class="field-label">Type</div>
            <div class="type-toggle">
              <button type="button" class="type-btn" :class="{ active: form.type === 'holiday' }" @click="form.type = 'holiday'">
                <Ban :size="18" />
                <div><div class="type-btn-title">Off-day (Closed)</div><div class="type-btn-sub">No bookings on these dates</div></div>
              </button>
              <button type="button" class="type-btn" :class="{ active: form.type === 'custom' }" @click="form.type = 'custom'">
                <Clock :size="18" />
                <div><div class="type-btn-title">Custom Schedule</div><div class="type-btn-sub">Different hours for the venue or one listing</div></div>
              </button>
            </div>
          </div>

          <!-- Scope -->
          <div class="field-section">
            <div class="field-label">Scope</div>
            <!-- CREATE: multi-select venues, then listings within them.
                 Empty venues = ALL venues; empty listings = whole venue(s). -->
            <div v-if="modalMode === 'create'" class="scope-row">
              <div class="form-group" style="flex:1">
                <label class="form-label">Venues</label>
                <MultiSelect v-model="selLocIds" placeholder="All my venues" :options="venueOptions" />
              </div>
              <div class="form-group" style="flex:1">
                <label class="form-label">
                  Listings
                  <span class="opt">optional — blank = whole venue(s)</span>
                </label>
                <MultiSelect v-model="selListingIds" placeholder="All listings (venue-wide)" :options="listingOptions" />
              </div>
            </div>
            <div v-else class="scope-row">
              <div class="form-group" style="flex:1">
                <label class="form-label">Location <span class="req">*</span></label>
                <select v-model="form.location_id" :class="{ 'input-invalid': triedSave && !form.location_id }">
                  <option value="">Select location…</option>
                  <option v-for="l in locations" :key="l.id" :value="l.id">{{ l.name }}</option>
                </select>
                <div v-if="triedSave && !form.location_id" class="field-error-text"><AlertTriangle :size="12" />Location is required</div>
              </div>
              <div class="form-group" style="flex:1">
                <label class="form-label">
                  Listing
                  <span class="opt">optional — blank = whole venue</span>
                </label>
                <select v-model="form.listing_id" :disabled="!form.location_id">
                  <option value="">All listings (entire venue)</option>
                  <option v-for="l in listingsForLocation" :key="l.id" :value="l.id">{{ l.name }}</option>
                </select>
              </div>
            </div>
            <div v-if="conflictCount" class="conflict-warn">
              <AlertTriangle :size="14" />
              <span><strong>{{ conflictCount }} existing booking{{ conflictCount > 1 ? 's' : '' }}</strong> on the selected date(s) in this scope. They are NOT cancelled automatically — review them on the <router-link to="/bookings">Bookings page</router-link> if these dates are closing.</span>
            </div>
            <div v-if="modalMode === 'create'" class="holiday-info">
              <Info :size="13" />
              <span v-if="selListingIds.length">
                {{ form.type === 'holiday' ? 'Closes' : 'The custom hours apply to' }}
                <strong>{{ selListingIds.length }} listing{{ selListingIds.length > 1 ? 's' : '' }}</strong>
                across {{ new Set(selListingIds.map(id => listings.find(l => l.id === id)?.location_id)).size }} venue(s) on the selected date(s) — other listings keep their normal schedule.
              </span>
              <span v-else>
                {{ form.type === 'holiday' ? 'Closes' : 'The custom hours apply to' }}
                <strong>{{ selLocIds.length ? selLocIds.length : 'ALL ' + locations.length }} venue{{ targetLocIds.length > 1 ? 's' : '' }}</strong>
                (every listing in them) on the selected date(s).
              </span>
            </div>
            <div v-else-if="form.location_id" class="holiday-info">
              <Info :size="13" />
              <span v-if="form.type === 'holiday' && !form.listing_id">Closes <strong>all listings</strong> at {{ locationName(form.location_id) }} on the selected date(s) — no bookings possible.</span>
              <span v-else-if="form.type === 'holiday'">Closes <strong>{{ listingName(form.listing_id) }}</strong> on the selected date(s) — other listings keep their normal schedule.</span>
              <span v-else-if="!form.listing_id">The custom hours replace the regular schedule for <strong>every listing</strong> at {{ locationName(form.location_id) }} on the selected date(s).</span>
              <span v-else>The custom hours replace the regular schedule for <strong>{{ listingName(form.listing_id) }}</strong> only — other listings keep their normal schedule.</span>
            </div>
          </div>

          <!-- Custom schedule -->
          <div v-if="form.type === 'custom'" class="field-section">
            <div class="field-label">Hours on these dates</div>
            <div class="custom-schedule-grid">
              <div class="form-group"><label class="form-label">Opens at</label>
                <select v-model="form.open_time" :class="{ 'input-invalid': triedSave && timeInvalid }"><option v-for="t in TIMES" :key="t" :value="t">{{ fmt12(t) }}</option></select>
              </div>
              <div class="form-group"><label class="form-label">Closes at</label>
                <select v-model="form.close_time" :class="{ 'input-invalid': triedSave && timeInvalid }"><option v-for="t in TIMES" :key="t" :value="t">{{ fmt12(t) }}</option></select>
              </div>
            </div>
            <div v-if="triedSave && timeInvalid" class="field-error-text" style="margin-top:8px"><AlertTriangle :size="12" />Closing time must be after opening time</div>
          </div>

          <!-- OPTIONAL special pricing (custom days only) -->
          <div v-if="form.type === 'custom'" class="field-section">
            <div class="field-label">Pricing on these dates <span class="opt">optional</span></div>
            <div class="sd-price-modes">
              <button type="button" class="sd-pm" :class="{ active: form.priceMode === 'none' }" @click="form.priceMode = 'none'">
                <div class="sd-pm-t">Listing pricing</div><div class="sd-pm-s">Base &amp; dynamic pricing apply as usual</div>
              </button>
              <button type="button" class="sd-pm" :class="{ active: form.priceMode === 'flat' }" @click="form.priceMode = 'flat'">
                <div class="sd-pm-t">Flat price</div><div class="sd-pm-s">Every slot costs one set price</div>
              </button>
              <button type="button" class="sd-pm" :class="{ active: form.priceMode === 'percent' }" @click="form.priceMode = 'percent'">
                <div class="sd-pm-t">Adjust by %</div><div class="sd-pm-s">Raise or discount the normal prices</div>
              </button>
            </div>
            <div v-if="form.priceMode === 'flat'" class="sd-price-input">
              <label class="form-label">Price per slot</label>
              <input type="number" min="0" step="0.01" v-model="form.custom_price" placeholder="e.g. 250"
                :class="{ 'input-invalid': triedSave && priceInvalid }" />
              <div v-if="triedSave && priceInvalid" class="field-error-text"><AlertTriangle :size="12" />Enter a price above 0</div>
            </div>
            <div v-else-if="form.priceMode === 'percent'" class="sd-price-input">
              <label class="form-label">Adjustment % <span class="opt">positive = surcharge, negative = discount</span></label>
              <input type="number" min="-99" max="900" step="1" v-model="form.pct" placeholder="e.g. 25 or -20"
                :class="{ 'input-invalid': triedSave && priceInvalid }" />
              <div v-if="triedSave && priceInvalid" class="field-error-text"><AlertTriangle :size="12" />Enter a non-zero % above -100</div>
            </div>
            <div class="price-keep-note">
              <Info :size="13" />
              <span v-if="form.priceMode === 'none'">Slot availability follows these hours; <strong>prices stay exactly as set on the listing</strong>.</span>
              <span v-else-if="form.priceMode === 'flat'">Every slot on these dates will cost <strong>{{ form.custom_price || '—' }}</strong>, overriding base &amp; dynamic pricing.</span>
              <span v-else>Normal slot prices (base &amp; dynamic) are adjusted by <strong>{{ form.pct || '—' }}%</strong> on these dates.</span>
            </div>
          </div>

          <!-- Label -->
          <div class="field-section" style="border-bottom:none">
            <div class="field-label">Label <span class="opt">optional</span></div>
            <input v-model="form.name" placeholder='e.g. "Eid Al-Fitr", "Summer Hours"' />
          </div>
        </div>

        <div class="modal-footer">
          <button class="btn btn-secondary" @click="requestClose">Cancel</button>
          <button class="btn btn-primary" :disabled="saving" @click="save">
            {{ saving ? 'Saving…' : editing ? 'Save changes' : `Apply to ${selDates.length} date${selDates.length > 1 ? 's' : ''}` }}
          </button>
        </div>
      </div>
    </div>

    <!-- ═══════════════ BULK CLEAR MODAL ═══════════════════════════════════ -->
    <div v-if="showClear" class="modal-backdrop" @click.self="showClear = false">
      <div class="modal special-modal">
        <div class="modal-header">
          <div class="modal-title">Clear special days</div>
          <button class="btn-icon" @click="showClear = false"><X :size="18" /></button>
        </div>
        <div class="modal-body sd-modal-body">
          <div class="field-section">
            <div class="field-label">Scope</div>
            <div class="scope-row">
              <div class="form-group" style="flex:1">
                <label class="form-label">Venue</label>
                <select v-model="cForm.location_id">
                  <option value="">All my venues</option>
                  <option v-for="l in locations" :key="l.id" :value="l.id">{{ l.name }}</option>
                </select>
              </div>
              <div class="form-group" style="flex:1">
                <label class="form-label">Listing <span class="opt">optional</span></label>
                <select v-model="cForm.listing_id" :disabled="!cForm.location_id">
                  <option value="">Any listing / venue-wide</option>
                  <option v-for="l in cListings" :key="l.id" :value="l.id">{{ l.name }}</option>
                </select>
              </div>
            </div>
          </div>
          <div class="field-section">
            <div class="field-label">What to clear</div>
            <div class="sd-clear-chips">
              <button type="button" :class="{ on: cForm.type === 'all' }" @click="cForm.type = 'all'">Everything</button>
              <button type="button" :class="{ on: cForm.type === 'off' }" @click="cForm.type = 'off'">Off-days only</button>
              <button type="button" :class="{ on: cForm.type === 'custom' }" @click="cForm.type = 'custom'">Custom schedules only</button>
            </div>
          </div>
          <div class="field-section" style="border-bottom:none">
            <div class="field-label">Dates</div>
            <div class="sd-clear-chips">
              <button v-if="selDates.length" type="button" :class="{ on: cForm.dates === 'selected' }" @click="cForm.dates = 'selected'">Selected on calendar ({{ selDates.length }})</button>
              <button type="button" :class="{ on: cForm.dates === 'upcoming' }" @click="cForm.dates = 'upcoming'">All upcoming</button>
              <button type="button" :class="{ on: cForm.dates === 'range' }" @click="cForm.dates = 'range'">Date range</button>
              <button type="button" :class="{ on: cForm.dates === 'past' }" @click="cForm.dates = 'past'">Past only</button>
            </div>
            <div v-if="cForm.dates === 'range'" class="sd-qs-range" style="margin-top:10px">
              <input type="date" v-model="cForm.from" aria-label="From" />
              <span class="sd-qs-sep">→</span>
              <input type="date" v-model="cForm.to" aria-label="To" />
            </div>
          </div>
          <div class="sd-clear-preview" :class="{ none: !clearMatches.length }">
            <template v-if="clearMatches.length">Matches <strong>{{ clearMatches.length }}</strong> special day{{ clearMatches.length > 1 ? 's' : '' }} — the normal schedule &amp; pricing will apply again on those dates.</template>
            <template v-else>Nothing matches this selection.</template>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" @click="showClear = false">Cancel</button>
          <button class="btn btn-danger" :disabled="clearing || !clearMatches.length" @click="runClear">
            <Trash2 :size="15" />{{ clearing ? 'Clearing…' : `Clear ${clearMatches.length || ''}` }}
          </button>
        </div>
      </div>
    </div>

    <!-- Phone: dates picked on the calendar live above the (stacked) side panel,
         so surface the two actions in a thumb-reach bottom bar instead. -->
    <div v-if="selDates.length && !showModal" class="sd-mobile-bar">
      <div class="sd-mb-count">{{ selDates.length }} date{{ selDates.length > 1 ? 's' : '' }}</div>
      <button class="btn btn-danger" @click="openConfigure('holiday')"><CalendarX :size="15" />Off-day</button>
      <button class="btn btn-primary" @click="openConfigure('custom')"><CalendarClock :size="15" />Custom</button>
      <button class="sd-mb-clear" title="Clear selection" @click="clearSelection"><X :size="16" /></button>
    </div>
  </div>
</template>

<style scoped>
.page { max-width: 100%; overflow-x: clip; }

/* Layout */
.sd-layout { display: grid; grid-template-columns: minmax(0, 1fr) 340px; gap: 20px; align-items: start; max-width: 100%; }
@media (max-width: 1000px) { .sd-layout { grid-template-columns: minmax(0, 1fr); } }

/* Calendar */
.sd-cal-card { padding: 18px 20px; }
.sd-cal-nav { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; }
.sd-cal-title { font-size: 18px; font-weight: 700; }
.sd-cal-nav-btns { display: flex; align-items: center; gap: 8px; }
.cal-nav-btn { width: 32px; height: 32px; border-radius: 8px; border: 1px solid var(--border); background: var(--surface, #ffffff); color: var(--text-muted); display: flex; align-items: center; justify-content: center; }
.cal-nav-btn:hover { background: var(--bg); color: var(--text); }

.sd-grid { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 6px; }
/* Tighten the month grid so 7 columns fit a phone without overflow. */
@media (max-width: 600px) {
  .sd-cal-card { padding: 14px 12px; }
  .sd-grid { gap: 4px; }
}
.sd-grid-head { margin-bottom: 6px; }
.sd-dow { text-align: center; font-size: 11px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: .04em; padding: 4px 0; }
.sd-cell {
  position: relative; min-height: 78px; padding: 6px; border-radius: 10px; min-width: 0; overflow: hidden;
  border: 1.5px solid var(--border); background: var(--surface, #ffffff); cursor: pointer;
  display: flex; flex-direction: column; gap: 4px; text-align: left; transition: all .12s;
}
.sd-cell:hover:not(:disabled) { border-color: var(--primary); background: #f0fdfa; }
.sd-cell.selected { border-color: var(--primary); background: #ecfdf5; box-shadow: 0 0 0 2px rgba(16,185,129,.18); }
.sd-cell.today .sd-cell-num { background: var(--primary); color: #fff; }
.sd-cell.out-month { opacity: .3; cursor: default; background: #fafafa; }
.sd-cell.is-past { opacity: .6; }
.sd-cell-num { font-size: 13px; font-weight: 600; width: 22px; height: 22px; border-radius: 50%; display: flex; align-items: center; justify-content: center; }
.sd-cell-check { position: absolute; top: 6px; right: 6px; color: var(--primary); }
.sd-cell-events { display: flex; flex-direction: column; gap: 2px; margin-top: auto; }
.sd-event-chip { display: inline-flex; align-items: center; gap: 3px; font-size: 10px; font-weight: 600; padding: 1px 5px; border-radius: 4px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.sd-event-chip:hover { filter: brightness(.92); }
.sd-event-more { font-size: 10px; color: var(--text-muted); padding-left: 3px; }

.sd-legend { display: flex; gap: 16px; margin-top: 14px; font-size: 12px; color: var(--text-muted); }
.sd-legend span { display: inline-flex; align-items: center; gap: 5px; }
.lg-dot { width: 10px; height: 10px; border-radius: 3px; display: inline-block; }
.lg-sel { background: #ecfdf5; border: 2px solid var(--primary); }

/* Side */
.sd-side { display: flex; flex-direction: column; gap: 16px; position: sticky; top: 16px; }
.sd-selection-card, .sd-upcoming-card { padding: 16px; min-width: 0; max-width: 100%; }
.sd-side-title { font-size: 12px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: .06em; margin-bottom: 12px; }
.sd-side-empty { display: flex; flex-direction: column; align-items: center; gap: 8px; padding: 20px; text-align: center; color: var(--text-muted); font-size: 13px; }
.sd-side-empty-sm { color: var(--text-muted); font-size: 13px; }
.sd-sel-chips { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 14px; }
.sd-sel-chip { display: inline-flex; align-items: center; gap: 5px; padding: 3px 7px 3px 9px; background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; border-radius: 6px; font-size: 12px; font-weight: 600; }
.sd-sel-chip button { display: inline-flex; width: 15px; height: 15px; border-radius: 4px; background: rgba(6,95,70,.15); color: #065f46; align-items: center; justify-content: center; }
.sd-action-btns { display: flex; flex-direction: column; gap: 8px; }
.sd-action-btns .btn { justify-content: center; }

.sd-up-list { display: flex; flex-direction: column; gap: 8px; max-height: 420px; overflow-y: auto; }
.sd-up-item { display: flex; gap: 8px; padding: 10px 12px; background: var(--bg); border-radius: 8px; }
.sd-up-main { flex: 1; min-width: 0; }
.sd-up-name { font-size: 13px; font-weight: 600; }
.sd-up-date { font-size: 11.5px; color: var(--text-muted); margin-top: 1px; }
.sd-up-meta { font-size: 11px; color: var(--text-muted); margin-top: 4px; display: flex; align-items: center; gap: 3px; flex-wrap: wrap; }
.sd-up-actions { display: flex; flex-direction: column; gap: 2px; }
.btn-icon { padding: 6px; border-radius: 6px; color: var(--text-muted); display: inline-flex; }
.btn-icon:hover { background: var(--surface, #ffffff); color: var(--text); }
.btn-icon.danger:hover { background: #fee2e2; color: #dc2626; }

/* Modal */
.special-modal { max-width: 640px; width: 100%; }
.sd-modal-body { display: flex; flex-direction: column; gap: 0; }
.sd-dates-summary { display: flex; align-items: center; gap: 8px; padding: 10px 14px; background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 9px; font-size: 13px; color: #065f46; margin-bottom: 4px; flex-wrap: wrap; }
.sd-ds-count { font-weight: 700; }
.sd-ds-list { color: #047857; }
.field-section { padding: 16px 0; border-bottom: 1px solid var(--border); }
.field-label { font-size: 12px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: .06em; margin-bottom: 10px; }
.opt { font-size: 11px; color: var(--text-muted); font-weight: 400; text-transform: none; letter-spacing: 0; margin-left: 4px; }
.req { font-size: 11px; color: #dc2626; font-weight: 500; text-transform: none; letter-spacing: 0; margin-left: 4px; }
.type-toggle { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.type-btn { display: flex; align-items: flex-start; gap: 12px; padding: 14px 16px; border: 2px solid var(--border); border-radius: 10px; background: var(--surface, #ffffff); text-align: left; cursor: pointer; transition: all .15s; }
.type-btn:hover { border-color: var(--primary); background: #f0fdfa; }
.type-btn.active { border-color: var(--primary); background: #f0fdfa; box-shadow: 0 0 0 3px rgba(16,185,129,.12); }
.type-btn-title { font-size: 14px; font-weight: 600; }
.type-btn-sub { font-size: 12px; color: var(--text-muted); margin-top: 2px; }
.scope-row { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
.scope-row .form-group { min-width: 0; }
.scope-row :deep(.ms-wrap) { min-width: 0; }
.conflict-warn { display: flex; align-items: flex-start; gap: 8px; padding: 10px 12px; background: #fff7ed; border: 1px solid #fed7aa; border-radius: 8px; font-size: 13px; color: #9a3412; margin-top: 10px; line-height: 1.5; }
.holiday-info { display: flex; align-items: flex-start; gap: 8px; padding: 10px 12px; background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; font-size: 13px; color: #1e40af; margin-top: 10px; line-height: 1.5; }
.custom-schedule-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
.price-keep-note { display: flex; align-items: flex-start; gap: 8px; padding: 10px 12px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; font-size: 12.5px; color: #166534; margin-top: 12px; line-height: 1.5; }
@media (max-width: 680px) {
  .type-toggle, .scope-row, .custom-schedule-grid { grid-template-columns: 1fr; }
  /* ≥16px fields stop iOS auto-zoom on focus. */
  .special-modal select, .special-modal input { font-size: 16px; }
}

/* Below 1000px the layout is one column: the side stack follows the calendar,
   so sticky serves no purpose and can misbehave inside the grid parent.
   (Placed after the .sd-side base rule — equal specificity, source order wins.) */
@media (max-width: 1000px) {
  .sd-side { position: static; }
}

/* Sticky bottom action bar — phones/small tablets only (hidden on desktop,
   where the side panel is visible next to the calendar). */
.sd-mobile-bar { display: none; }
@media (max-width: 1000px) {
  .sd-mobile-bar {
    /* z below the app's mobile nav layers (overlay z-30, drawer z-40) so an
       open navigation drawer dims and covers the bar instead of vice versa. */
    position: fixed; left: 0; right: 0; bottom: 0; z-index: 25;
    display: flex; align-items: center; gap: 8px;
    padding: 10px 12px calc(10px + env(safe-area-inset-bottom));
    background: var(--surface, #ffffff); border-top: 1px solid var(--border);
    box-shadow: 0 -6px 20px rgba(15, 23, 42, .08);
    animation: sdBarIn .18s ease;
  }
  .sd-mobile-bar .btn { flex: 1; justify-content: center; min-height: 44px; }
  .sd-mb-count { font-size: 12px; font-weight: 700; color: var(--text-muted); white-space: nowrap; }
  .sd-mb-clear {
    width: 40px; height: 40px; flex-shrink: 0; border-radius: 10px;
    border: 1px solid var(--border); background: var(--surface, #ffffff); color: var(--text-muted);
    display: inline-flex; align-items: center; justify-content: center;
  }
  /* Keep the last calendar rows / upcoming list reachable above the bar. */
  .page { padding-bottom: 76px; }
}
@keyframes sdBarIn { from { transform: translateY(100%); } to { transform: none; } }

/* Modal on phones: global CSS already turns it into a bottom sheet — pin the
   footer inside the sheet so Save stays reachable while the form scrolls. */
@media (max-width: 600px) {
  .special-modal { display: flex; flex-direction: column; }
  .special-modal .sd-modal-body { overflow-y: auto; flex: 1; -webkit-overflow-scrolling: touch; }
  .special-modal .modal-footer { position: sticky; bottom: 0; background: var(--surface, #ffffff); z-index: 2; }
}

/* Phone — narrow screens (360px/344px): fit the 7-col calendar without
   overflow, ≥16px form fields, ≥44px-ish tap targets, nothing under 11px.
   Kept at the END of the style block so these beat the base rules above
   (scoped rules share specificity; source order decides). */
@media (max-width: 600px) {
  .cal-nav-btn { width: 40px; height: 40px; }
  .sd-dow { font-size: 11px; letter-spacing: 0; padding: 2px 0; }
  .sd-cell { padding: 4px 3px; min-height: 60px; border-radius: 8px; }
  .sd-cell-num { width: 18px; height: 18px; font-size: 12px; }
  .sd-cell-check { top: 3px; right: 3px; }
  /* ~42px cells can't fit chip text legibly — show the icon only. */
  .sd-event-chip { font-size: 0; padding: 2px; justify-content: center; }
  /* Icon-only chip is interactive (opens edit) — enlarge its hit area. */
  .sd-event-chip { min-height: 22px; padding: 4px 3px; }
  .sd-event-more { font-size: 11px; }
  .sd-legend { flex-wrap: wrap; gap: 8px 12px; }
  .btn-icon { padding: 10px; }
  /* Space out & enlarge the edit/delete tap targets in the upcoming list. */
  .sd-up-actions { gap: 6px; }
  .sd-up-actions .btn-icon { min-width: 40px; min-height: 40px; }
  .sd-sel-chip button { width: 22px; height: 22px; }
}

/* Quick select */
.sd-qs-card { padding: 16px; min-width: 0; max-width: 100%; }
.sd-qs-range { display: flex; align-items: center; gap: 8px; }
.sd-qs-range input { flex: 1 1 0; min-width: 0; max-width: 100%; height: 38px; padding: 0 8px; border: 1px solid var(--border); border-radius: 8px; background: var(--surface, #ffffff); font-size: 13px; appearance: none; -webkit-appearance: none; }
.sd-qs-sep { color: var(--text-muted); flex-shrink: 0; }
.sd-qs-dows { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 10px; }
.sd-qs-dow { flex: 1 1 0; min-width: 34px; padding: 7px 0; border: 1px solid var(--border); border-radius: 7px; background: var(--surface, #ffffff); font-size: 11px; font-weight: 700; color: var(--text-muted); cursor: pointer; }
.sd-qs-dow.on { background: #ecfdf5; border-color: var(--primary); color: #065f46; }
.sd-qs-add { width: 100%; justify-content: center; margin-top: 10px; }
.sd-qs-hint { font-size: 11.5px; color: var(--text-muted); margin-top: 8px; line-height: 1.5; }

/* Upcoming head + past row + clear links */
.sd-up-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.sd-clear-link { display: inline-flex; align-items: center; gap: 4px; font-size: 12px; font-weight: 700; color: #dc2626; background: none; border: none; cursor: pointer; padding: 4px 6px; border-radius: 6px; }
.sd-clear-link:hover { background: #fee2e2; }
.sd-past-row { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-top: 10px; padding-top: 10px; border-top: 1px dashed var(--border); font-size: 12px; color: var(--text-muted); }
.sd-price-badge { display: inline-flex; padding: 1px 7px; border-radius: 999px; background: #fef3c7; color: #92400e; font-weight: 700; font-size: 10.5px; }

/* Pricing modes */
.sd-price-modes { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; }
.sd-pm { padding: 10px 12px; border: 2px solid var(--border); border-radius: 10px; background: var(--surface, #ffffff); text-align: left; cursor: pointer; transition: all .15s; min-width: 0; }
.sd-pm:hover { border-color: var(--primary); }
.sd-pm.active { border-color: var(--primary); background: #f0fdfa; box-shadow: 0 0 0 3px rgba(16,185,129,.12); }
.sd-pm-t { font-size: 13px; font-weight: 700; }
.sd-pm-s { font-size: 11px; color: var(--text-muted); margin-top: 2px; line-height: 1.35; }
.sd-price-input { margin-top: 12px; max-width: 280px; }
.sd-price-input input { width: 100%; box-sizing: border-box; }

/* Bulk clear */
.sd-clear-chips { display: flex; flex-wrap: wrap; gap: 8px; }
.sd-clear-chips button { padding: 8px 14px; border: 1.5px solid var(--border); border-radius: 999px; background: var(--surface, #ffffff); font-size: 13px; font-weight: 600; color: var(--text); cursor: pointer; }
.sd-clear-chips button.on { border-color: var(--primary); background: #ecfdf5; color: #065f46; }
.sd-clear-preview { margin-top: 4px; padding: 10px 12px; border-radius: 9px; background: #fff7ed; border: 1px solid #fed7aa; color: #9a3412; font-size: 13px; }
.sd-clear-preview.none { background: var(--bg); border-color: var(--border); color: var(--text-muted); }

@media (max-width: 600px) {
  .sd-price-modes { grid-template-columns: 1fr; }
  .sd-qs-range input { font-size: 16px; height: 44px; }
  .sd-clear-chips button { min-height: 42px; }
}
</style>
