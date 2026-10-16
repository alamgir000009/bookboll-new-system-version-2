<script setup>
import { ref, computed, watch } from 'vue'
import { Plus, Trash2 } from 'lucide-vue-next'
import { fmt12 } from '../lib/slots'
import { markDirty } from '../lib/unsavedGuard.js'

const DAY_NAME = { 0: 'Sun', 1: 'Mon', 2: 'Tue', 3: 'Wed', 4: 'Thu', 5: 'Fri', 6: 'Sat' }

/* `pricing_groups.days` comes back in two shapes, depending on which editor last
   wrote the listing: this grid posts numeric indices ("1,2,3", Sunday = 0), while
   the API validates lowercase day NAMES ("monday,tuesday"). Parsing only numbers
   meant a name-based group showed EVERY day pill switched off — and the moment
   anyone tapped a day, the Set was seeded with NaN and the days it already had were
   written away. That is the likeliest way a working group became a dead one. */
const DAY_INDEX = {
  sun: 0, sunday: 0, mon: 1, monday: 1, tue: 2, tues: 2, tuesday: 2,
  wed: 3, weds: 3, wednesday: 3, thu: 4, thur: 4, thurs: 4, thursday: 4,
  fri: 5, friday: 5, sat: 6, saturday: 6,
}
const ALL_TIMES = Array.from({ length: 24 * 2 }, (_, i) => {
  const h = Math.floor(i / 2), m = (i % 2) * 30
  return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`
})

const props = defineProps({
  groups: { type: Array, required: true },
  cells: { type: Array, required: true },
  basePrice: { type: Number, default: 0 },
  slotDuration: { type: Number, default: 60 },
  pattern: { type: String, default: 'A' },
  currency: { type: String, default: 'AED' },
  hours: { type: Array, default: () => [] },
})
const emit = defineEmits(['update:groups', 'update:cells', 'update:pattern', 'below-base'])

const activeGroupId = ref(null)
const directPriceInput = ref(null)
const priceInputError = ref(false)
const priceErrorMsg = ref('')
// Tell the parent when the active error is specifically "below base price" so it can
// highlight the base-price field (the thing the user actually needs to change).
watch([priceInputError, priceErrorMsg], () => {
  emit('below-base', priceInputError.value && /lower than base price/i.test(priceErrorMsg.value))
})

const TIER_COLORS = ['#0f766e', '#3b82f6', '#f59e0b', '#10b981', '#ef4444', '#06b6d4', '#8b5cf6', '#ec4899']
// Keep the two views in sync: the Slot Grid stores per-cell direct prices, the Groups
// view stores cells assigned to a named price group. When the user switches views we
// convert the cells so the SAME prices show up in both (so painting the grid no longer
// leaves the Groups view empty, and vice-versa).
watch(() => props.pattern, (p, prev) => {
  if (p === prev) return
  if (p === 'B') {
    // Groups → direct prices, so every priced slot is visible/editable in the grid.
    let changed = false
    const conv = props.cells.map(c => {
      if (c.pricing_group_id) {
        const g = props.groups.find(x => x.id === c.pricing_group_id)
        changed = true
        return { ...c, direct_price: Number(g ? g.price : props.basePrice), pricing_group_id: null }
      }
      return c
    })
    if (changed) emit('update:cells', conv)
  } else if (p === 'A') {
    // Direct prices → groups (one group per distinct price), so the Groups view reflects
    // whatever was painted in the Slot Grid.
    if (!props.cells.some(c => c.direct_price != null)) return
    const newGroups = [...props.groups]
    const byPrice = new Map(newGroups.map(g => [Number(g.price), g.id]))
    let addedGroups = false
    const conv = props.cells.map(c => {
      if (c.direct_price == null) return c
      const price = Number(c.direct_price)
      let gid = byPrice.get(price)
      if (!gid) {
        const ng = {
          id: `tmp-g-${Date.now()}-${Math.round(price)}`,
          name: `Tier ${newGroups.length + 1}`,
          color: TIER_COLORS[newGroups.length % TIER_COLORS.length],
          price, sort_order: newGroups.length,
          start_time: '18:00', end_time: '22:00', days: '1,2,3,4,5',
        }
        newGroups.push(ng); byPrice.set(price, ng.id); gid = ng.id; addedGroups = true
      }
      return { ...c, pricing_group_id: gid, direct_price: null }
    })
    if (addedGroups) emit('update:groups', newGroups)
    emit('update:cells', conv)
    if (!activeGroupId.value && newGroups.length) activeGroupId.value = newGroups[0].id
  }
})

const DAY_ORDER = [1, 2, 3, 4, 5, 6, 0]

const priceTiers = computed(() => {
  return [...new Set(props.groups.map(g => Number(g.price) || 0))].sort((a, b) => b - a)
})
const directPriceTiers = computed(() => {
  return [...new Set(props.cells.filter(c => c.direct_price != null).map(c => Number(c.direct_price) || 0))].sort((a, b) => b - a)
})
function colorForTier(price, tiers) {
  if (!tiers.length) return '#f59e0b'
  const idx = tiers.indexOf(Number(price) || 0)
  if (idx === 0) return '#b91c1c'
  if (idx === 1) return '#ea580c'
  return '#eab308'
}
function groupColor(g) { return colorForTier(g.price, priceTiers.value) }
function directColor(price) { return colorForTier(price, directPriceTiers.value) }
const DAY_SHORT = { 0: 'SUN', 1: 'MON', 2: 'TUE', 3: 'WED', 4: 'THU', 5: 'FRI', 6: 'SAT' }

function hoursFor(dayIndex) {
  return props.hours.find(h => h.day_of_week === dayIndex) || { is_open: true, periods: [{ open_time: '08:00', close_time: '23:00' }] }
}
function minutesOf(t) { const [h, m] = t.split(':').map(Number); return h * 60 + m }
// Midnight as a CLOSE time means end-of-day (a 12 PM → 12 AM schedule is 12
// open hours, not an empty window).
function endMin(t) { const m = minutesOf(t); return m === 0 ? 24 * 60 : m }
function dayPeriods(h) {
  if (h.periods && h.periods.length) return h.periods
  if (h.open_time) return [{ open_time: h.open_time, close_time: h.close_time }]
  return []
}
function slotInOpen(dayIndex, slot) {
  const h = hoursFor(dayIndex)
  if (!h.is_open) return false
  const m = minutesOf(slot)
  const periods = dayPeriods(h)
  if (!periods.length) return true
  return periods.some(p => m >= minutesOf(p.open_time) && m < endMin(p.close_time))
}

const openWindow = computed(() => {
  const fmt = (m) => `${String(Math.floor(m / 60)).padStart(2, '0')}:${String(m % 60).padStart(2, '0')}`
  const openDays = props.hours.filter(h => h.is_open)
  if (!openDays.length) return { from: '08:00', to: '23:00' }
  let minFrom = 24 * 60, maxTo = 0
  for (const h of openDays) {
    for (const p of dayPeriods(h)) {
      minFrom = Math.min(minFrom, minutesOf(p.open_time))
      maxTo   = Math.max(maxTo,   endMin(p.close_time))
    }
  }
  if (minFrom === 24 * 60) return { from: '08:00', to: '23:00' }
  return { from: fmt(minFrom), to: fmt(maxTo) }
})

const slots = computed(() => {
  const result = []
  let m = minutesOf(openWindow.value.from)
  const end = minutesOf(openWindow.value.to)
  while (m < end) {
    const h = Math.floor(m / 60), mm = m % 60
    result.push(`${String(h).padStart(2, '0')}:${String(mm).padStart(2, '0')}`)
    m += props.slotDuration
  }
  return result
})

function cellFor(day, slot) {
  return props.cells.find(c => c.day_of_week === day && c.slot_time === slot)
}

function cellStyle(day, slot) {
  if (!slotInOpen(day, slot)) return { background: '#f8fafc', color: '#cbd5e1', cursor: 'default' }
  const c = cellFor(day, slot)
  if (props.pattern === 'B') {
    if (c && c.direct_price != null) { const col = directColor(c.direct_price); return { background: col + '22', borderLeft: `3px solid ${col}`, color: col } }
    return { background: '#fff', color: '#cbd5e1' }
  }
  if (!c) return { background: '#fff' }
  if (c.pricing_group_id) {
    const g = props.groups.find(x => x.id === c.pricing_group_id)
    if (g) { const col = groupColor(g); return { background: col + '22', borderLeft: `3px solid ${col}`, color: col } }
  }
  return {}
}

function cellLabel(day, slot) {
  const c = cellFor(day, slot)
  if (props.pattern === 'B') {
    if (c && c.direct_price != null) return c.direct_price
    return slotInOpen(day, slot) ? props.basePrice : ''
  }
  if (!c) return ''
  if (c.pricing_group_id) {
    const g = props.groups.find(x => x.id === c.pricing_group_id)
    return g ? g.price : ''
  }
  return ''
}

function isFaded(day, slot) {
  if (props.pattern !== 'B') return false
  const c = cellFor(day, slot)
  return slotInOpen(day, slot) && !(c && c.direct_price != null)
}

function paintCell(day, slot, mode) {
  if (!slotInOpen(day, slot)) return
  // Painting is pure clicks — no input/change event reaches the form root,
  // so flag the unsaved edit here. (The view-conversion watcher above stays
  // silent: switching Groups ↔ Slot Grid isn't a user edit.)
  markDirty()
  const existing = props.cells.find(c => c.day_of_week === day && c.slot_time === slot)
  const next = props.cells.filter(c => !(c.day_of_week === day && c.slot_time === slot))
  if (props.pattern === 'A') {
    if (!activeGroupId.value) return
    if (mode === 'erase') {
      if (existing && existing.pricing_group_id === activeGroupId.value) emit('update:cells', next)
      return
    }
    next.push({ id: `tmp-${day}-${slot}`, day_of_week: day, slot_time: slot, pricing_group_id: activeGroupId.value, direct_price: null })
  } else {
    const v = directPriceInput.value
    if (v === null || v === '' || isNaN(Number(v))) { priceInputError.value = true; priceErrorMsg.value = 'Please enter a price'; return }
    const price = Number(v)
    if (price < Number(props.basePrice)) { priceInputError.value = true; priceErrorMsg.value = 'Price cannot be lower than base price. Change the base price first.'; return }
    priceInputError.value = false
    priceErrorMsg.value = ''
    if (mode === 'erase') {
      if (price === Number(props.basePrice)) { emit('update:cells', next); return }
      if (existing && Number(existing.direct_price) === price) emit('update:cells', next)
      return
    }
    if (price === Number(props.basePrice)) { emit('update:cells', next); return }
    next.push({ id: `tmp-${day}-${slot}`, day_of_week: day, slot_time: slot, pricing_group_id: null, direct_price: price })
  }
  emit('update:cells', next)
}

const painting = ref(false)
const paintMode = ref('paint')
function onMouseDown(day, slot) {
  const existing = props.cells.find(c => c.day_of_week === day && c.slot_time === slot)
  if (props.pattern === 'A') {
    if (!activeGroupId.value) return
    paintMode.value = (existing && existing.pricing_group_id === activeGroupId.value) ? 'erase' : 'paint'
  } else {
    const v = directPriceInput.value
    if (v === null || v === '' || isNaN(Number(v))) { priceInputError.value = true; priceErrorMsg.value = 'Please enter a price'; return }
    const price = Number(v)
    if (price < Number(props.basePrice)) { priceInputError.value = true; priceErrorMsg.value = 'Price cannot be lower than base price. Change the base price first.'; return }
    const currentPrice = (existing && existing.direct_price != null) ? Number(existing.direct_price) : Number(props.basePrice)
    paintMode.value = (currentPrice === price) ? 'erase' : 'paint'
  }
  painting.value = true
  paintCell(day, slot, paintMode.value)
}
function onMouseEnter(day, slot) { if (painting.value) paintCell(day, slot, paintMode.value) }
function onMouseUp() { painting.value = false }

function addGroup() {
  markDirty()
  const colors = ['#0f766e', '#3b82f6', '#f59e0b', '#10b981', '#ef4444', '#06b6d4']
  const idx = props.groups.length % colors.length
  const next = [...props.groups, {
    id: `tmp-g-${Date.now()}`,
    name: `Group ${props.groups.length + 1}`,
    color: colors[idx],
    price: props.basePrice,
    sort_order: props.groups.length,
    start_time: '18:00',
    end_time: '22:00',
    days: '1,2,3,4,5',
  }]
  emit('update:groups', next)
  activeGroupId.value = next[next.length - 1].id
}

function updateGroup(id, patch) {
  markDirty()
  emit('update:groups', props.groups.map(g => g.id === id ? { ...g, ...patch } : g))
}

function removeGroup(id) {
  markDirty()
  emit('update:groups', props.groups.filter(g => g.id !== id))
  emit('update:cells', props.cells.filter(c => c.pricing_group_id !== id))
  if (activeGroupId.value === id) activeGroupId.value = null
}

function groupDays(g) {
  const raw = Array.isArray(g?.days) ? g.days : String(g?.days ?? '').split(',')
  return [...new Set(
    raw
      .map(d => String(d).trim().toLowerCase())
      .filter(Boolean)
      .map(d => (/^[0-6]$/.test(d) ? Number(d) : DAY_INDEX[d]))
      .filter(d => Number.isInteger(d)),
  )].sort((a, b) => a - b)
}
function groupHasDay(g, d) { return groupDays(g).includes(d) }
function toggleGroupDay(g, d) {
  const set = new Set(groupDays(g))
  if (set.has(d)) set.delete(d)
  else set.add(d)
  updateGroup(g.id, { days: [...set].sort((a, b) => a - b).join(',') })
}

function fillDay(day) {
  if (props.pattern === 'A' && !activeGroupId.value) return
  markDirty()
  const existing = props.cells.filter(c => c.day_of_week !== day)
  if (props.pattern === 'B') {
    const v = directPriceInput.value
    if (v === null || v === '' || isNaN(Number(v))) { priceInputError.value = true; priceErrorMsg.value = 'Please enter a price'; return }
    const price = Number(v)
    if (price < Number(props.basePrice)) { priceInputError.value = true; priceErrorMsg.value = 'Price cannot be lower than base price. Change the base price first.'; return }
    priceInputError.value = false
    priceErrorMsg.value = ''
    if (price === Number(props.basePrice)) { emit('update:cells', existing); return }
    const daySlots = slots.value.filter(s => slotInOpen(day, s))
    const additions = daySlots.map(slot => ({ id: `tmp-${day}-${slot}`, day_of_week: day, slot_time: slot, pricing_group_id: null, direct_price: price }))
    emit('update:cells', [...existing, ...additions])
    return
  }
  const daySlots = slots.value.filter(s => slotInOpen(day, s))
  const additions = daySlots.map(slot => ({ id: `tmp-${day}-${slot}`, day_of_week: day, slot_time: slot, pricing_group_id: activeGroupId.value, direct_price: null }))
  emit('update:cells', [...existing, ...additions])
}
</script>

<template>
  <div class="pg">
    <div class="pg-top">
      <div class="view-toggle">
        <button type="button" class="vt-btn" :class="{active: pattern==='A'}" @click="emit('update:pattern','A')">Groups</button>
        <button type="button" class="vt-btn" :class="{active: pattern==='B'}" @click="emit('update:pattern','B')">Slot Grid</button>
      </div>
      <button v-if="pattern==='A'" type="button" class="btn btn-secondary btn-sm" @click="addGroup"><Plus :size="14" />Add Group</button>
    </div>

    <div v-if="pattern==='A'" class="groups-list">
      <div v-for="g in groups" :key="g.id" class="group-card" :class="{active: activeGroupId===g.id}" @click="activeGroupId = g.id">
        <div class="gc-header">
          <div class="gc-title">
            <span class="gc-dot" :style="{background: groupColor(g)}"></span>
            <input class="gc-name-input" :value="g.name" @input="e => updateGroup(g.id, {name: e.target.value})" />
          </div>
          <button type="button" class="icon-del" @click.stop="removeGroup(g.id)"><Trash2 :size="16" /></button>
        </div>
        <div class="form-group">
          <label class="form-label">Price per Hour ({{ currency }})</label>
          <input type="number" :value="g.price" @input="e => updateGroup(g.id, {price: Number(e.target.value)})" />
        </div>
        <div class="grid grid-2">
          <div class="form-group">
            <label class="form-label">Start Time</label>
            <select :value="g.start_time" @change="e => updateGroup(g.id, {start_time: e.target.value})">
              <option v-for="t in ALL_TIMES" :key="t" :value="t">{{ fmt12(t) }}</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">End Time</label>
            <select :value="g.end_time" @change="e => updateGroup(g.id, {end_time: e.target.value})">
              <option v-for="t in ALL_TIMES" :key="t" :value="t">{{ fmt12(t) }}</option>
            </select>
          </div>
        </div>
        <div class="form-group" style="margin-bottom: 0;">
          <label class="form-label">Days</label>
          <div class="day-chips">
            <button v-for="d in DAY_ORDER" :key="d" type="button" class="day-chip"
                    :class="{selected: groupHasDay(g, d)}"
                    :style="groupHasDay(g, d) ? { background: groupColor(g), borderColor: groupColor(g), color: '#fff' } : {}"
                    @click="toggleGroupDay(g, d)">{{ DAY_NAME[d] }}</button>
          </div>
        </div>
      </div>
      <div v-if="!groups.length" class="empty-groups">No pricing groups yet. Click Add Group to define time-range pricing.</div>
      <div v-else class="pg-paint-hint">
        {{ activeGroupId ? 'Painting with the selected group — click cells in the grid below to apply it.' : 'Select a group above, then paint the grid below.' }}
      </div>
    </div>

    <div v-if="pattern==='B'" class="pg-toolbar">
      <div class="price-input">
        <label>Price ({{ currency }})</label>
        <input type="number" v-model.number="directPriceInput" placeholder="Enter price"
               :class="{'price-err': priceInputError}"
               @input="priceInputError = false"
               style="width: 140px;" />
        <span v-if="priceInputError" class="price-err-msg">{{ priceErrorMsg }}</span>
        <span v-else class="price-hint">Paint the base price ({{ basePrice }}) to erase</span>
      </div>
    </div>

    <div class="grid-wrap" @mouseup="onMouseUp" @mouseleave="onMouseUp">
      <div class="grid-head">
        <div></div>
        <div v-for="d in DAY_ORDER" :key="d" class="grid-day">
          {{ DAY_SHORT[d] }}
          <button type="button" class="fill-btn" @click="fillDay(d)" title="Fill whole day">fill</button>
        </div>
      </div>
      <div v-for="slot in slots" :key="slot" class="grid-row">
        <div class="grid-time">{{ fmt12(slot) }}</div>
        <div v-for="d in DAY_ORDER" :key="d" class="grid-cell"
             :class="{faded: isFaded(d, slot)}"
             :style="cellStyle(d, slot)"
             @mousedown.prevent="onMouseDown(d, slot)"
             @mouseenter="onMouseEnter(d, slot)">
          {{ cellLabel(d, slot) }}
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.pg { border: 1px solid var(--border); border-radius: 8px; padding: 14px; background: var(--surface, #ffffff); }

.pg-top { display: flex; justify-content: flex-end; align-items: center; gap: 10px; margin-bottom: 14px; }
.view-toggle { display: inline-flex; background: #f1f5f9; border-radius: 8px; padding: 3px; }
.vt-btn { padding: 6px 14px; font-size: 13px; font-weight: 500; border-radius: 6px; color: var(--text-muted); }
.vt-btn.active { background: var(--surface, #ffffff); color: var(--text); box-shadow: var(--shadow-sm); }

.pg-toolbar { display: flex; gap: 8px; align-items: center; margin-bottom: 12px; }
.tool { display: inline-flex; align-items: center; gap: 6px; padding: 6px 10px; font-size: 12px; border: 1px solid var(--border); border-radius: 6px; background: var(--surface, #ffffff); color: var(--text); }
.tool.active { background: var(--primary); border-color: var(--primary); color: #fff; }
.price-input { display: flex; align-items: center; gap: 8px; font-size: 13px; }
.price-input label { color: var(--text-muted); }
.price-err { border-color: #b91c1c !important; background: #fef2f2 !important; }
.price-err-msg { color: #b91c1c; font-size: 12px; }
.price-hint { color: var(--text-muted); font-size: 12px; }

.groups-list { display: flex; flex-direction: column; gap: 14px; }
.group-card { border: 1px solid var(--border); border-radius: 8px; padding: 18px; background: var(--surface, #ffffff); cursor: pointer; transition: border-color .12s, box-shadow .12s; }
.group-card.active { border-color: var(--primary); box-shadow: 0 0 0 2px rgba(15,118,110,.12); }
.pg-paint-hint { font-size: 12.5px; color: var(--text-muted); margin-top: 4px; }
.gc-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; }
.gc-title { display: flex; align-items: center; gap: 10px; flex: 1; }
.gc-dot { width: 12px; height: 12px; border-radius: 50%; }
.gc-name-input { width: auto; flex: 1; max-width: 240px; padding: 6px 10px; font-size: 15px; font-weight: 600; border: 1px solid transparent; background: transparent; }
.gc-name-input:hover, .gc-name-input:focus { border-color: var(--border); background: var(--surface, #ffffff); }
.icon-del { padding: 6px; border-radius: 6px; color: var(--error); }
.icon-del:hover { background: #fee2e2; }
.day-chips { display: flex; gap: 8px; flex-wrap: wrap; }
.day-chip { padding: 6px 14px; font-size: 13px; font-weight: 500; border: 1px solid var(--border); border-radius: 999px; background: var(--surface, #ffffff); color: var(--text-muted); transition: all 0.15s; }
.day-chip:hover:not(.selected) { border-color: var(--primary); color: var(--primary); }
.empty-groups { text-align: center; padding: 40px 20px; color: var(--text-muted); font-size: 14px; border: 1px dashed var(--border); border-radius: 8px; }

.grid-wrap { border: 1px solid var(--border); border-radius: 6px; overflow: auto; user-select: none; max-height: 480px; }
.grid-head, .grid-row { display: grid; grid-template-columns: 60px repeat(7, minmax(60px, 1fr)); }
.grid-head { background: var(--bg); position: sticky; top: 0; z-index: 2; }
.grid-day { padding: 8px; font-size: 12px; font-weight: 600; text-align: center; color: var(--text-muted); border-left: 1px solid var(--border); display: flex; flex-direction: column; align-items: center; gap: 2px; }
.fill-btn { font-size: 10px; color: var(--primary); background: none; padding: 1px 4px; border-radius: 3px; }
.fill-btn:hover { background: #ccfbf1; }
.grid-row { border-top: 1px solid var(--border); }
.grid-time { padding: 4px 8px; font-size: 11px; font-family: 'SF Mono', Monaco, monospace; color: var(--text-muted); background: var(--bg); text-align: right; position: sticky; left: 0; z-index: 1; }
.grid-cell { border-left: 1px solid var(--border); min-height: 26px; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 500; cursor: pointer; transition: opacity 0.1s; }
.grid-cell:hover { opacity: 0.75; }
.grid-cell.faded { color: #cbd5e1 !important; font-weight: 400; }
.grid-cell.faded:hover { color: var(--primary) !important; background: #f0fdfa !important; }
</style>
