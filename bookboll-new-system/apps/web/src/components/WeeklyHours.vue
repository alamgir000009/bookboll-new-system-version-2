<script setup>
import { computed, ref } from 'vue'
import { Copy, X as XIcon, Sun, Plus as PlusIcon, Pencil, TriangleAlert as AlertTriangle, CalendarClock, CalendarPlus } from 'lucide-vue-next'
import { fmt12, fmtRange12 } from '../lib/slots'
import { markDirty } from '../lib/unsavedGuard.js'

const props = defineProps({
  modelValue: { type: Array, required: true },
  parentHours: { type: Array, default: () => [] },
  showInherit: { type: Boolean, default: false },
  slotDuration: { type: Number, default: 30 },
})
const emit = defineEmits(['update:modelValue', 'edit-location-schedule', 'create-special-day'])

const view = ref('grid')

const DAY_ORDER = [1, 2, 3, 4, 5, 6, 0]
const DAY_LABELS = { 0: 'Sunday', 1: 'Monday', 2: 'Tuesday', 3: 'Wednesday', 4: 'Thursday', 5: 'Friday', 6: 'Saturday' }
const DAY_SHORT  = { 0: 'SUN', 1: 'MON', 2: 'TUE', 3: 'WED', 4: 'THU', 5: 'FRI', 6: 'SAT' }

// Open/close time dropdowns: 15-minute increments so schedules can align to
// 15 / 30 / 45 / 60-minute slot boundaries.
const TIMES = Array.from({ length: 96 }, (_, i) => {
  const h = Math.floor(i / 4), m = (i % 4) * 15
  return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`
})

// ── Slots-grid granularity (paint open/closed at 15/30/60-min resolution) ─────
const GRID_STEPS = [
  { label: '1 hour', step: 60 },
  { label: '30 min', step: 30 },
  { label: '15 min', step: 15 },
]
const gridStep = ref(60)
const colsPerHour = computed(() => Math.round(60 / gridStep.value)) // 1 | 2 | 4

// 24 hour rows; each has `colsPerHour` slot cells (e.g. :00,:15,:30,:45)
const hourGroups = computed(() =>
  Array.from({ length: 24 }, (_, h) => ({
    label: `${String(h).padStart(2, '0')}:00`,
    slots: Array.from({ length: colsPerHour.value }, (_, c) => {
      const mins = h * 60 + c * gridStep.value
      return `${String(Math.floor(mins / 60)).padStart(2, '0')}:${String(mins % 60).padStart(2, '0')}`
    }),
  }))
)
// Minute labels shown in the sub-header (e.g. :00 :15 :30 :45)
const subLabels = computed(() =>
  Array.from({ length: colsPerHour.value }, (_, c) => `:${String(c * gridStep.value).padStart(2, '0')}`)
)
// CSS column template shared by header / sub-header / body
const gridColsStyle = computed(() => ({
  gridTemplateColumns: `56px repeat(${7 * colsPerHour.value}, minmax(0, 1fr))`,
}))

// ── helpers ──────────────────────────────────────────────────────────────────
function minutesOf(t) { const [h, m] = t.split(':').map(Number); return h * 60 + m }
// Close/END times: midnight means end-of-day, so '00:00' (or '24:00') = 1440.
// Without this, a 12:00 PM → 12:00 AM day computes as an empty window and the
// whole day reads closed.
function endMin(t) { const m = minutesOf(t); return m === 0 ? 24 * 60 : m }
function toStr(mins) {
  return `${String(Math.floor(mins / 60)).padStart(2, '0')}:${String(mins % 60).padStart(2, '0')}`
}
// Write-back for END times: 1440 round-trips to '00:00' so the value stays a
// valid option in the time dropdowns (shown as 12:00 AM).
function toEndStr(mins) { return mins >= 24 * 60 ? '00:00' : toStr(mins) }

/** Normalise a raw DB row (single open/close) into the periods model */
function normRow(r) {
  if (r && r.periods) return r
  const periods = (r && r.is_open) ? [{ open_time: r.open_time || '09:00', close_time: r.close_time || '23:00' }] : []
  return { ...r, periods }
}

// ── reactive rows ─────────────────────────────────────────────────────────────
const rows = computed(() => DAY_ORDER.map(i => {
  const raw = props.modelValue.find(r => r.day_of_week === i)
  if (raw) return normRow(raw)
  return { day_of_week: i, is_open: false, periods: [] }
}))

function parentFor(dayIndex) {
  const raw = props.parentHours.find(r => r.day_of_week === dayIndex)
  return raw ? normRow(raw) : null
}

// True only when the parent (location) actually has at least one open day configured.
const parentHasSchedule = computed(() =>
  props.parentHours.some(r => { const n = normRow(r); return n.is_open && n.periods?.length })
)

/** The day's own row. Listings keep their own hours — they are no longer synced to
 *  the location; the location only restricts which slots can be opened. */
function effectiveRow(dayIndex) {
  return rows.value.find(x => x.day_of_week === dayIndex) || null
}

// ── mutations ─────────────────────────────────────────────────────────────────
function updateDay(dayIndex, fn) {
  // Grid cells and the 24h/Clone/Clear buttons mutate via clicks — no native
  // input/change event bubbles to the form root, so flag the edit here.
  markDirty()
  const next = rows.value.map(r =>
    r.day_of_week === dayIndex ? fn({ ...r, periods: r.periods.map(p => ({ ...p })) }) : r
  )
  emit('update:modelValue', next)
}

function toggleOpen(dayIndex, checked) {
  updateDay(dayIndex, r => ({
    ...r,
    is_open: checked,
    periods: checked ? (r.periods.length ? r.periods : [{ open_time: '09:00', close_time: '23:00' }]) : [],
  }))
}

function addPeriod(dayIndex) {
  updateDay(dayIndex, r => {
    const last = r.periods[r.periods.length - 1]
    const newStart = last ? Math.min(endMin(last.close_time) + 30, 23 * 60 + 30) : 9 * 60
    const newEnd   = Math.min(newStart + 60, 24 * 60 - 30)
    return { ...r, is_open: true, periods: [...r.periods, { open_time: toStr(newStart), close_time: toStr(newEnd) }] }
  })
}

function removePeriod(dayIndex, pIdx) {
  updateDay(dayIndex, r => {
    const periods = r.periods.filter((_, i) => i !== pIdx)
    return { ...r, is_open: periods.length > 0, periods }
  })
}

function updatePeriod(dayIndex, pIdx, key, value) {
  updateDay(dayIndex, r => ({
    ...r,
    periods: r.periods.map((p, i) => i === pIdx ? { ...p, [key]: value } : p),
  }))
}

function clone(dayIndex) {
  const src = rows.value.find(r => r.day_of_week === dayIndex)
  if (!src) return
  markDirty()
  emit('update:modelValue', DAY_ORDER.map(i => ({
    ...src, day_of_week: i, periods: src.periods.map(p => ({ ...p })),
  })))
}

function setTwentyFour(dayIndex) {
  // close '00:00' = midnight at the END of the day → a true 24-hour window
  updateDay(dayIndex, r => ({ ...r, is_open: true, periods: [{ open_time: '00:00', close_time: '00:00' }] }))
}

function clearDay(dayIndex) {
  updateDay(dayIndex, r => ({ ...r, is_open: false, periods: [] }))
}

// ── grid helpers ──────────────────────────────────────────────────────────────
function slotIsOpen(dayIndex, slot) {
  const row = effectiveRow(dayIndex)
  if (!row || !row.is_open || !row.periods?.length) return false
  const m = minutesOf(slot)
  return row.periods.some(p => m >= minutesOf(p.open_time) && m < endMin(p.close_time))
}

// Is the LOCATION open at this slot? Only meaningful when the listing inherits a
// parent (location) schedule; with no parent context nothing is location-locked.
function slotLocationOpen(dayIndex, slot) {
  if (!props.showInherit) return true
  const p = parentFor(dayIndex)
  if (!p || !p.is_open || !p.periods?.length) return false
  const m = minutesOf(slot)
  return p.periods.some(per => m >= minutesOf(per.open_time) && m < endMin(per.close_time))
}
// A slot the listing can't use because its location is closed then.
function slotLocked(dayIndex, slot) {
  return props.showInherit && !slotLocationOpen(dayIndex, slot)
}
// Clicking a location-closed slot opens this explainer modal (with both fixes).
const lockModal = ref(false)
function requestEditLocation() { lockModal.value = false; emit('edit-location-schedule') }
function requestSpecialDay()   { lockModal.value = false; emit('create-special-day') }

function toggleSlot(dayIndex, slot) {
  // Can't open a listing while its location is closed — explain the two ways to
  // fix it (location schedule / special day) instead of toggling the slot.
  if (slotLocked(dayIndex, slot)) { lockModal.value = true; return }

  const m      = minutesOf(slot)
  const slotEnd = m + gridStep.value
  const wasOpen = slotIsOpen(dayIndex, slot)

  updateDay(dayIndex, row => {
    let periods = row.periods.map(p => ({ ...p }))
    const base  = { ...row }

    if (wasOpen) {
      // Remove this 30-min window from periods
      const next = []
      for (const p of periods) {
        const pS = minutesOf(p.open_time), pE = endMin(p.close_time)
        if (m >= pE || slotEnd <= pS)              next.push(p)
        else if (m <= pS && slotEnd >= pE)         { /* fully removed */ }
        else if (m <= pS)                          next.push({ open_time: toStr(slotEnd), close_time: p.close_time })
        else if (slotEnd >= pE)                    next.push({ open_time: p.open_time, close_time: toStr(m) })
        else { next.push({ open_time: p.open_time, close_time: toStr(m) }); next.push({ open_time: toStr(slotEnd), close_time: p.close_time }) }
      }
      const clean = next.filter(p => endMin(p.close_time) > minutesOf(p.open_time))
      return { ...base, is_open: clean.length > 0, periods: clean }
    } else {
      // Add slot and merge adjacent periods
      const all = [...periods, { open_time: toStr(m), close_time: toStr(slotEnd) }]
      all.sort((a, b) => minutesOf(a.open_time) - minutesOf(b.open_time))
      const merged = []
      for (const p of all) {
        if (!merged.length || minutesOf(p.open_time) > endMin(merged[merged.length - 1].close_time)) {
          merged.push({ ...p })
        } else {
          merged[merged.length - 1].close_time = toEndStr(Math.max(
            endMin(merged[merged.length - 1].close_time),
            endMin(p.close_time),
          ))
        }
      }
      return { ...base, is_open: true, periods: merged }
    }
  })
}

// ── display helpers ───────────────────────────────────────────────────────────
function dayTimesSummary(row) {
  const eff = effectiveRow(row.day_of_week)
  if (!eff || !eff.is_open || !eff.periods?.length) return 'Closed'
  if (eff.periods.length > 1) return `${eff.periods.length} periods`
  return fmtRange12(eff.periods[0].open_time, eff.periods[0].close_time)
}
</script>

<template>
  <div class="wh-shell">
    <!-- Warn when this listing's location has no schedule yet -->
    <div v-if="showInherit && !parentHasSchedule" class="wh-no-parent">
      <AlertTriangle :size="15" />
      <div class="wh-np-body">
        <span>This location has no weekly schedule yet, so every time is locked. Set the location's opening hours first, then paint this listing's hours inside them.</span>
        <button type="button" class="wh-lock-edit" @click="$emit('edit-location-schedule')"><Pencil :size="12" />Edit location schedule</button>
      </div>
    </div>

    <div class="view-toggle">
      <button type="button" class="vt-btn" :class="{ active: view === 'hours' }" @click="view = 'hours'">Hours</button>
      <button type="button" class="vt-btn" :class="{ active: view === 'grid' }"  @click="view = 'grid'">Slots Grid</button>
    </div>

    <!-- ═══════════════════════════════  HOURS VIEW  ════════════════════════════ -->
    <div v-if="view === 'hours'" class="hours-list">
      <div v-for="r in rows" :key="r.day_of_week" class="hour-row" :class="{ closed: !effectiveRow(r.day_of_week)?.is_open }">

        <!-- Row head -->
        <div class="hour-row-head">
          <label class="day-check">
            <input type="checkbox"
              :checked="effectiveRow(r.day_of_week)?.is_open"
              @change="e => toggleOpen(r.day_of_week, e.target.checked)" />
            <span class="day-name">{{ DAY_LABELS[r.day_of_week] }}</span>
          </label>

          <div class="row-head-right">
            <div class="row-actions">
              <button type="button" class="mini-btn" @click="setTwentyFour(r.day_of_week)"><Sun :size="12" />24h</button>
              <button type="button" class="mini-btn" @click="clone(r.day_of_week)"><Copy :size="12" />Clone</button>
            </div>
          </div>
        </div>

        <!-- Open day → period editor -->
        <div v-if="effectiveRow(r.day_of_week)?.is_open" class="periods-list">
          <div v-for="(p, pIdx) in r.periods" :key="pIdx" class="period-row">
            <select :value="p.open_time" @change="e => updatePeriod(r.day_of_week, pIdx, 'open_time', e.target.value)">
              <option v-for="t in TIMES" :key="t" :value="t">{{ fmt12(t) }}</option>
            </select>
            <span class="to">to</span>
            <select :value="p.close_time" @change="e => updatePeriod(r.day_of_week, pIdx, 'close_time', e.target.value)">
              <option v-for="t in TIMES" :key="t" :value="t">{{ fmt12(t) }}</option>
            </select>
            <button v-if="r.periods.length > 1" type="button" class="period-remove" @click="removePeriod(r.day_of_week, pIdx)" title="Remove this period">
              <XIcon :size="13" />
            </button>
          </div>

          <!-- Adds another OPEN time range to the same day (the gap between two
               ranges acts as the break, e.g. 9 AM–12 PM and 4 PM–11 PM). -->
          <button type="button" class="add-period-btn" @click="addPeriod(r.day_of_week)" title="e.g. open 9 AM–12 PM and again 4 PM–11 PM">
            <PlusIcon :size="13" />Add another time range
          </button>
        </div>

        <div v-else class="closed-label">Closed</div>
      </div>
    </div>

    <!-- ═══════════════════════════════  GRID VIEW  ═════════════════════════════ -->
    <div v-else class="grid-scroll-wrap">

      <!-- Grid resolution selector -->
      <div class="grid-step-bar">
        <span class="grid-step-label">Slot resolution</span>
        <div class="grid-step-toggle">
          <button v-for="g in GRID_STEPS" :key="g.step" type="button"
            :class="{ active: gridStep === g.step }" @click="gridStep = g.step">{{ g.label }}</button>
        </div>
      </div>

    <div class="grid-view">

      <!-- Day headers (each spans colsPerHour slot columns) -->
      <div class="grid-head" :style="gridColsStyle">
        <div class="grid-corner"></div>
        <!-- Kept intentionally light: just the on/off switch + day name. The exact hours are
             already visible in the grid itself, and the three per-day actions are icon-only,
             fading in on hover (tooltips carry the labels). -->
        <div v-for="r in rows" :key="r.day_of_week" class="grid-day-head" :style="{ gridColumn: `span ${colsPerHour}` }">
          <div class="day-toggle">
            <label class="switch">
              <input type="checkbox"
                :checked="effectiveRow(r.day_of_week)?.is_open"
                @change="e => toggleOpen(r.day_of_week, e.target.checked)" />
              <span class="slider"></span>
            </label>
            <div class="day-short" :title="dayTimesSummary(r)">{{ DAY_SHORT[r.day_of_week] }}</div>
          </div>
          <div class="day-actions">
            <button type="button" class="tiny" @click="setTwentyFour(r.day_of_week)" title="Open 24 hours"><Sun :size="11" /></button>
            <button type="button" class="tiny" @click="clone(r.day_of_week)" title="Copy this day to all days"><Copy :size="11" /></button>
            <button type="button" class="tiny" @click="clearDay(r.day_of_week)" title="Clear this day"><XIcon :size="11" /></button>
          </div>
        </div>
      </div>

      <!-- Sub-header: minute labels (:00 :15 :30 :45 depending on resolution) -->
      <div class="grid-sub-head" :style="gridColsStyle">
        <div class="grid-corner-sm"></div>
        <template v-for="r in rows" :key="r.day_of_week">
          <div v-for="(lbl, ci) in subLabels" :key="r.day_of_week + lbl" class="sub-label" :class="{ 'sub-half': ci > 0 }">{{ lbl }}</div>
        </template>
      </div>

      <!-- Body: 24 hour rows × 7 days × colsPerHour cells -->
      <div class="grid-body">
        <div v-for="group in hourGroups" :key="group.label" class="slot-row" :style="gridColsStyle">
          <div class="time-label">{{ fmt12(group.label) }}</div>
          <template v-for="r in rows" :key="r.day_of_week">
            <div
              v-for="(slot, ci) in group.slots" :key="r.day_of_week + slot"
              class="slot-cell"
              :class="{
                'slot-half': ci > 0,
                open:        slotIsOpen(r.day_of_week, slot),
                locked:      slotLocked(r.day_of_week, slot),
              }"
              :title="slotLocked(r.day_of_week, slot) ? 'Location is closed at this time — edit the location schedule to open it' : ''"
              @click="toggleSlot(r.day_of_week, slot)"
            ></div>
          </template>
        </div>
      </div>

      <div class="grid-legend">
        <span class="dot open"></span> Open
        <span class="dot avail"></span> {{ showInherit ? 'Available' : 'Closed' }}
        <template v-if="showInherit">
          <span class="dot locked"></span> Location closed
        </template>
        <span class="grid-hint">Click to toggle. Syncs with Hours view.</span>
      </div>
    </div><!-- end grid-view -->
    </div><!-- end grid-scroll-wrap -->

    <!-- Location-closed slot → explain the two ways to make it bookable -->
    <div v-if="lockModal" class="wh-modal-backdrop" @click.self="lockModal = false">
      <div class="wh-modal" role="dialog" aria-modal="true">
        <button type="button" class="wh-modal-x" @click="lockModal = false" aria-label="Close"><XIcon :size="18" /></button>
        <div class="wh-modal-ic"><CalendarClock :size="24" /></div>
        <h3 class="wh-modal-title">That time is outside the location's hours</h3>
        <p class="wh-modal-text">A listing can only take bookings while its location is open. To use this time, either extend the location's weekly schedule, or add a one-off Special Day for a specific date.</p>
        <div class="wh-modal-actions">
          <button type="button" class="wh-modal-btn primary" @click="requestEditLocation"><Pencil :size="15" />Edit location schedule</button>
          <button type="button" class="wh-modal-btn" @click="requestSpecialDay"><CalendarPlus :size="15" />Add a Special Day</button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.wh-shell { display: flex; flex-direction: column; gap: 14px; }
.wh-no-parent { display: flex; align-items: flex-start; gap: 8px; padding: 10px 12px; background: #fff7ed; border: 1px solid #fed7aa; border-radius: 9px; font-size: 12.5px; color: #9a3412; line-height: 1.5; }
.wh-no-parent svg { flex-shrink: 0; margin-top: 1px; }
.wh-np-body { display: flex; flex-direction: column; align-items: flex-start; gap: 8px; }

/* "Edit location schedule" button on the no-parent banner */
.wh-lock-edit { display: inline-flex; align-items: center; gap: 5px; padding: 5px 11px; font-size: 12px; font-weight: 600; border: 1px solid var(--primary); color: var(--primary); background: #f0fdfa; border-radius: 7px; white-space: nowrap; cursor: pointer; }
.wh-lock-edit:hover { background: var(--primary); color: #fff; }

/* Location-closed slot explainer modal */
.wh-modal-backdrop { position: fixed; inset: 0; z-index: 5000; background: rgba(15, 23, 42, .5); display: flex; align-items: center; justify-content: center; padding: 20px; }
.wh-modal { position: relative; width: 100%; max-width: 420px; background: var(--surface, #ffffff); border-radius: 16px; padding: 26px 24px 22px; box-shadow: 0 24px 60px rgba(0, 0, 0, .28); text-align: center; }
.wh-modal-x { position: absolute; top: 12px; right: 12px; display: inline-flex; padding: 5px; border-radius: 7px; color: var(--text-muted); background: none; border: none; cursor: pointer; }
.wh-modal-x:hover { background: #f1f5f9; color: var(--text); }
.wh-modal-ic { width: 52px; height: 52px; border-radius: 14px; background: #f0fdfa; color: var(--primary); display: inline-flex; align-items: center; justify-content: center; margin-bottom: 14px; }
.wh-modal-title { font-size: 17px; font-weight: 700; margin: 0 0 8px; color: var(--text); }
.wh-modal-text { font-size: 13.5px; line-height: 1.55; color: var(--text-muted); margin: 0 0 20px; }
.wh-modal-actions { display: flex; flex-direction: column; gap: 9px; }
.wh-modal-btn { display: inline-flex; align-items: center; justify-content: center; gap: 7px; padding: 11px 16px; font-size: 13.5px; font-weight: 600; border-radius: 10px; border: 1px solid var(--border); background: var(--surface, #ffffff); color: var(--text); cursor: pointer; transition: all .15s; }
.wh-modal-btn:hover { background: #f8fafc; border-color: #cbd5e1; }
.wh-modal-btn.primary { background: var(--primary); border-color: var(--primary); color: #fff; }
.wh-modal-btn.primary:hover { background: var(--primary-dark, #0d9488); }
.view-toggle { align-self: flex-end; display: inline-flex; background: #f1f5f9; border-radius: 8px; padding: 3px; }
.vt-btn { padding: 6px 14px; font-size: 13px; font-weight: 500; border-radius: 6px; color: var(--text-muted); }
.vt-btn.active { background: var(--surface, #ffffff); color: var(--text); box-shadow: var(--shadow-sm); }

/* ── HOURS VIEW ─────────────────────────────────────────────────── */
.hours-list { display: flex; flex-direction: column; gap: 8px; }
.hour-row { background: var(--surface, #ffffff); border: 1px solid var(--border); border-radius: 10px; overflow: hidden; transition: box-shadow 0.15s; }
.hour-row:not(.closed):hover { box-shadow: 0 1px 6px rgba(0,0,0,.06); }
.hour-row.closed { background: #f8fafc; }

.hour-row-head { display: flex; align-items: center; justify-content: space-between; padding: 11px 14px; gap: 10px; }
.row-head-right { display: flex; align-items: center; gap: 10px; }
.day-check { display: flex; align-items: center; gap: 10px; cursor: pointer; }
.day-check input { width: auto; accent-color: var(--primary); }
.day-name { font-weight: 600; font-size: 14px; }
.inherit-badge { display: inline-flex; align-items: center; gap: 4px; font-size: 11px; font-weight: 600; color: #0369a1; background: #e0f2fe; border: 1px solid #bae6fd; border-radius: 5px; padding: 2px 8px; }
.row-actions { display: flex; gap: 6px; }
.mini-btn { display: inline-flex; align-items: center; gap: 5px; padding: 5px 10px; font-size: 12px; border: 1px solid var(--border); border-radius: 6px; background: var(--surface, #ffffff); color: var(--text); white-space: nowrap; }
.mini-btn:hover { background: var(--bg); }

.inherit-body { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px; padding: 0 14px 12px; }
.inherit-hint { display: flex; align-items: center; gap: 6px; font-size: 13px; color: #0369a1; }
.inherit-hint strong { font-family: 'SF Mono', Monaco, monospace; font-size: 12px; }
.customize-btn { display: inline-flex; align-items: center; gap: 5px; padding: 5px 12px; font-size: 12px; font-weight: 500; border: 1px solid var(--primary); color: var(--primary); border-radius: 7px; background: #f0fdfa; }
.customize-btn:hover { background: var(--primary); color: #fff; }

.periods-list { padding: 0 14px 14px; display: flex; flex-direction: column; gap: 8px; }
.reset-inherit-row { display: flex; }
.reset-inherit-btn { font-size: 12px; color: var(--text-muted); text-decoration: underline; background: none; border: none; padding: 2px 0; cursor: pointer; }
.reset-inherit-btn:hover { color: var(--primary); }

.period-row { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.period-row select { width: 100px; padding: 7px 10px; font-size: 13px; }
.to { color: var(--text-muted); font-size: 13px; flex-shrink: 0; }
.period-remove { display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 6px; background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; flex-shrink: 0; }
.period-remove:hover { background: #fee2e2; }
.add-period-btn { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; font-size: 12px; border: 1px dashed var(--border); border-radius: 6px; color: var(--primary); background: transparent; align-self: flex-start; margin-top: 2px; }
.add-period-btn:hover { background: #f0fdfa; border-color: var(--primary); }
.closed-label { padding: 8px 14px 12px; font-size: 13px; color: var(--text-muted); font-style: italic; }

/* ── GRID VIEW ──────────────────────────────────────────────────── */
.grid-scroll-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; border-radius: 10px; }
.grid-view { border: 1px solid var(--border); border-radius: 10px; background: var(--surface, #ffffff); overflow: hidden; min-width: 760px; }

/* Resolution selector */
.grid-step-bar { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
.grid-step-label { font-size: 12px; font-weight: 600; color: var(--text-muted); }
.grid-step-toggle { display: inline-flex; background: #f1f5f9; border-radius: 8px; padding: 3px; }
.grid-step-toggle button { padding: 5px 12px; border-radius: 6px; font-size: 12.5px; font-weight: 500; color: var(--text-muted); border: none; background: none; cursor: pointer; }
.grid-step-toggle button.active { background: var(--surface, #ffffff); color: var(--primary); box-shadow: var(--shadow-sm); }

/* Header + sub-header + body share a column template (set inline per resolution) */
.grid-head, .grid-sub-head, .slot-row {
  display: grid;
  grid-template-columns: 56px repeat(14, minmax(0, 1fr));
}

.grid-head { border-bottom: 1px solid var(--border); }
.grid-corner { background: #fafafa; border-right: 1px solid var(--border); }
.grid-day-head {
  grid-column: span 2;
  padding: 9px 6px;
  border-left: 2px solid var(--border);
  display: flex; flex-direction: column; gap: 6px; align-items: center; text-align: center;
  background: var(--surface, #ffffff);
}
.day-toggle { display: flex; align-items: center; gap: 6px; }
.day-short { font-size: 11.5px; font-weight: 800; letter-spacing: .06em; color: var(--primary); }
.day-actions { display: flex; gap: 2px; justify-content: center; opacity: .35; transition: opacity .12s; }
.grid-day-head:hover .day-actions { opacity: 1; }
.tiny { display: inline-flex; align-items: center; justify-content: center; width: 20px; height: 18px; border-radius: 4px; background: transparent; color: var(--text-muted); }
.tiny:hover { background: var(--bg); color: var(--text); }

.switch { position: relative; width: 28px; height: 16px; flex-shrink: 0; }
.switch input { opacity: 0; width: 0; height: 0; }
.switch .slider { position: absolute; inset: 0; background: #cbd5e1; border-radius: 999px; transition: .2s; cursor: pointer; }
.switch .slider::before { content: ''; position: absolute; width: 12px; height: 12px; top: 2px; left: 2px; background: var(--surface, #ffffff); border-radius: 50%; transition: .2s; }
.switch input:checked + .slider { background: var(--primary); }
.switch input:checked + .slider::before { transform: translateX(12px); }

/* Sub-header */
.grid-sub-head { border-bottom: 1px solid var(--border); background: #fafafa; }
.grid-corner-sm { border-right: 1px solid var(--border); background: #fafafa; }
.sub-label { padding: 3px 0; font-size: 9px; font-weight: 600; color: var(--text-muted); text-align: center; letter-spacing: .04em; border-left: 2px solid var(--border); }
.sub-half { border-left: 1px dashed #d1d5db; }

/* Body */
.grid-body { max-height: 440px; overflow-y: auto; }
.slot-row { border-bottom: 1px solid #f0f4f8; }
.slot-row:last-child { border-bottom: none; }
.time-label {
  padding: 0 6px; font-size: 10px; font-family: 'SF Mono', Monaco, monospace;
  color: var(--text-muted); text-align: right; border-right: 1px solid var(--border);
  display: flex; align-items: center; justify-content: flex-end; min-height: 20px; white-space: nowrap;
}
.slot-cell {
  min-height: 20px; border-left: 2px solid var(--border); cursor: pointer; transition: background .1s;
  background: #f8fafc;
}
.slot-cell.slot-half { border-left: 1px dashed #d1d5db; }
.slot-cell.open { background: var(--primary); }
.slot-cell.open:hover { background: var(--primary-dark, #0d9488); }
.slot-cell:not(.open):not(.locked):hover { background: #e8fdf8; }
/* Location is closed at this time → same base as Available, with diagonal
   stripes to read as "blocked". Still clickable (opens the explainer modal).
   Listed after .open so out-of-bounds open slots also read as blocked. */
.slot-cell.locked {
  background-color: #f8fafc;
  background-image: repeating-linear-gradient(45deg, transparent 0, transparent 5px, rgba(100, 116, 139, .28) 5px, rgba(100, 116, 139, .28) 7px);
  cursor: pointer;
}
.slot-cell.locked:hover { background-color: #eef2f6; }

/* Legend */
.grid-legend { display: flex; align-items: center; gap: 10px; padding: 9px 14px; border-top: 1px solid var(--border); background: #fafafa; font-size: 12px; color: var(--text-muted); flex-wrap: wrap; }
.dot { width: 12px; height: 12px; border-radius: 3px; flex-shrink: 0; }
.dot.open { background: var(--primary); }
.dot.avail { background: var(--border, #e2e8f0); }
.dot.locked { background-color: var(--border, #e2e8f0); background-image: repeating-linear-gradient(45deg, transparent 0, transparent 2px, rgba(100, 116, 139, .55) 2px, rgba(100, 116, 139, .55) 3px); }
.grid-hint { margin-left: auto; color: var(--text-light); font-size: 11px; }

@media (max-width: 900px) {
  .hour-row-head { flex-wrap: wrap; }
  .grid-day-head { padding: 6px 3px; }
  .day-times { display: none; }
}
</style>
