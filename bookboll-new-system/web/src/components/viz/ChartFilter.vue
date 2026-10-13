<script setup>
/**
 * ChartFilter — the per-chart filter control on analytics cards. Same slider
 * icon as the page FilterBar; opens a compact popover whose choices narrow
 * THIS chart only, applied ON TOP of the page filters. A dot on the icon
 * marks an active override; Reset returns the chart to the page filters.
 */
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { SlidersHorizontal, X } from 'lucide-vue-next'
import MultiSelect from '../MultiSelect.vue'

const props = defineProps({
  modelValue: { type: Object, default: () => ({}) },
  venueOptions: { type: Array, default: () => [] },
  statusOptions: { type: Array, default: () => [] },
  paymentOptions: { type: Array, default: () => [] },
  // Booking TYPE (venue / event seat / membership session) — pages pass only the
  // kinds that occur in their data (lib/bookingMeta.js kindOptions()).
  kindOptions: { type: Array, default: () => [] },
  // CHANNEL the booking was created through (marketplace / provider site / manual).
  channelOptions: { type: Array, default: () => [] },
  // which controls this chart offers — each chart declares what's relevant to it
  // (e.g. the status-breakdown chart must NOT offer a status filter)
  dims: { type: Array, default: () => ['range', 'venue', 'status', 'payment', 'kind', 'channel'] },
  // label for the no-override Period choice — charts that DON'T read the page
  // filters (membership charts) pass an honest label instead of the default
  followLabel: { type: String, default: 'Follow page filters' },
})
const emit = defineEmits(['update:modelValue'])

const RANGES = [
  { value: '', label: '' },   // label filled from followLabel below
  { value: 'today', label: 'Today' },
  { value: '7d', label: 'Last 7 days' },
  { value: '30d', label: 'Last 30 days' },
  { value: 'month', label: 'This month' },
  { value: '90d', label: 'Last 90 days' },
]

const open = ref(false)
const root = ref(null)
const mv = computed(() => props.modelValue || {})
const active = computed(() => !!(mv.value.range || mv.value.locations?.length || mv.value.statuses?.length || mv.value.payments?.length || mv.value.kinds?.length || mv.value.channels?.length))
const has = (d) => props.dims.includes(d)

function patch(k, v) { emit('update:modelValue', { ...mv.value, [k]: v }) }
function reset() { emit('update:modelValue', {}) }
// Outside click closes; MultiSelect's teleported menu stops mousedown, so
// picking an option never counts as "outside".
function onDoc(e) { if (open.value && root.value && !root.value.contains(e.target)) open.value = false }
onMounted(() => document.addEventListener('mousedown', onDoc))
onBeforeUnmount(() => document.removeEventListener('mousedown', onDoc))
</script>

<template>
  <div ref="root" class="cfx">
    <button type="button" class="cfx-btn" :class="{ on: active || open }" title="Filter this chart" @click="open = !open">
      <SlidersHorizontal :size="14" />
      <i v-if="active" class="cfx-dot"></i>
    </button>

    <div v-if="open" class="cfx-panel">
      <div class="cfx-head">
        <span>This chart only</span>
        <button type="button" class="cfx-x" @click="open = false"><X :size="14" /></button>
      </div>
      <div v-if="has('range')" class="cfx-f">
        <label>Period</label>
        <select :value="mv.range || ''" @change="patch('range', $event.target.value)">
          <option v-for="r in RANGES" :key="r.value" :value="r.value">{{ r.value === '' ? followLabel : r.label }}</option>
        </select>
      </div>
      <div v-if="has('venue') && venueOptions.length > 1" class="cfx-f">
        <label>Venue</label>
        <MultiSelect :model-value="mv.locations || []" placeholder="All venues" :options="venueOptions" @update:model-value="v => patch('locations', v)" />
      </div>
      <div v-if="has('status')" class="cfx-f">
        <label>Booking status</label>
        <MultiSelect :model-value="mv.statuses || []" placeholder="All statuses" :options="statusOptions" @update:model-value="v => patch('statuses', v)" />
      </div>
      <div v-if="has('payment')" class="cfx-f">
        <label>Payment status</label>
        <MultiSelect :model-value="mv.payments || []" placeholder="All payment statuses" :options="paymentOptions" @update:model-value="v => patch('payments', v)" />
      </div>
      <div v-if="has('kind') && kindOptions.length > 1" class="cfx-f">
        <label>Type</label>
        <MultiSelect :model-value="mv.kinds || []" placeholder="All types" :options="kindOptions" @update:model-value="v => patch('kinds', v)" />
      </div>
      <div v-if="has('channel') && channelOptions.length > 1" class="cfx-f">
        <label>Booked via</label>
        <MultiSelect :model-value="mv.channels || []" placeholder="All channels" :options="channelOptions" @update:model-value="v => patch('channels', v)" />
      </div>
      <button v-if="active" type="button" class="cfx-reset" @click="reset">Reset — follow page filters</button>
    </div>
  </div>
</template>

<style scoped>
.cfx { position: relative; }
.cfx-btn {
  position: relative; display: inline-flex; align-items: center; justify-content: center;
  width: 28px; height: 28px; border: 1px solid var(--border, #e2e8f0); border-radius: 8px;
  background: var(--surface, #ffffff); color: var(--text-muted, #64748b); cursor: pointer;
}
.cfx-btn:hover, .cfx-btn.on { border-color: var(--primary, #0f766e); color: var(--primary, #0f766e); }
.cfx-dot { position: absolute; top: -3px; right: -3px; width: 9px; height: 9px; border-radius: 50%; background: var(--primary, #0f766e); border: 2px solid var(--surface, #ffffff); }
.cfx-panel {
  position: absolute; right: 0; top: 34px; z-index: 60; width: 240px;
  background: var(--surface, #ffffff); border: 1px solid var(--border, #e2e8f0);
  border-radius: 12px; padding: 12px; box-shadow: 0 14px 40px rgba(15, 23, 42, .18);
  display: flex; flex-direction: column; gap: 10px;
}
.cfx-head { display: flex; align-items: center; justify-content: space-between; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: .05em; color: var(--text-light, #94a3b8); }
.cfx-x { background: none; border: none; color: var(--text-light, #94a3b8); cursor: pointer; padding: 2px; display: inline-flex; border-radius: 5px; }
.cfx-x:hover { background: var(--bg, #f1f5f9); color: var(--text, #0f172a); }
.cfx-f { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
.cfx-f label { font-size: 11px; font-weight: 600; color: var(--text-light, #94a3b8); text-transform: uppercase; letter-spacing: .05em; }
.cfx-f select {
  width: 100%; height: 36px; padding: 0 10px; border: 1px solid var(--border, #e2e8f0);
  border-radius: 8px; background: var(--surface, #ffffff); font-size: 13px; color: var(--text, #0f172a);
}
.cfx-reset { border: none; background: none; color: var(--primary, #0f766e); font-size: 12.5px; font-weight: 700; cursor: pointer; padding: 2px 0; text-align: left; }
.cfx-reset:hover { text-decoration: underline; }
</style>
