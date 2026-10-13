<script setup>
/**
 * DonutChart — part-to-whole with a value-bearing legend (identity is never
 * color-alone: every slice is named AND numbered in the legend, which also
 * satisfies the palette relief rule for low-contrast hues). 2px surface gaps
 * between slices; hovering a slice or legend row highlights both and swaps
 * the center readout to that slice.
 */
import { ref, computed } from 'vue'

const props = defineProps({
  slices: { type: Array, default: () => [] },   // [{ label, value, color }]
  centerLabel: { type: String, default: 'Total' },
})

const clean = computed(() => props.slices.filter(s => Number(s.value) > 0))
const total = computed(() => clean.value.reduce((a, s) => a + Number(s.value), 0))

const R = 52, SW = 18, C = 2 * Math.PI * R
const GAP = 2.5   // px of surface between slices
const segs = computed(() => {
  let acc = 0
  const multi = clean.value.length > 1
  return clean.value.map((s, i) => {
    const frac = Number(s.value) / total.value
    const len = Math.max(0, frac * C - (multi ? GAP : 0))
    const seg = { ...s, i, frac, dasharray: `${len} ${C - len}`, offset: -(acc * C) - (multi ? GAP / 2 : 0) }
    acc += frac
    return seg
  })
})

const hoverI = ref(-1)
const center = computed(() => {
  if (hoverI.value >= 0) {
    const s = clean.value[hoverI.value]
    if (s) return { v: Number(s.value).toLocaleString(), l: s.label }
  }
  return { v: total.value.toLocaleString(), l: props.centerLabel }
})
const pct = (s) => `${Math.round((Number(s.value) / total.value) * 100)}%`
</script>

<template>
  <div class="dc">
    <div v-if="!total" class="dc-empty">No data for this period</div>
    <template v-else>
      <div class="dc-plot">
        <svg viewBox="0 0 140 140" class="dc-svg">
          <circle v-for="s in segs" :key="s.i" cx="70" cy="70" :r="R" fill="none"
            :stroke="s.color" :stroke-width="hoverI === s.i ? SW + 4 : SW"
            :stroke-dasharray="s.dasharray" :stroke-dashoffset="s.offset"
            transform="rotate(-90 70 70)" class="dc-seg"
            @pointerenter="hoverI = s.i" @pointerleave="hoverI = -1">
            <title>{{ s.label }}: {{ Number(s.value).toLocaleString() }} ({{ pct(s) }})</title>
          </circle>
        </svg>
        <div class="dc-center">
          <b>{{ center.v }}</b>
          <span>{{ center.l }}</span>
        </div>
      </div>
      <div class="dc-legend">
        <div v-for="s in segs" :key="'l' + s.i" class="dc-row" :class="{ on: hoverI === s.i }"
          @pointerenter="hoverI = s.i" @pointerleave="hoverI = -1">
          <i :style="{ background: s.color }"></i>
          <span class="dc-lab">{{ s.label }}</span>
          <b>{{ Number(s.value).toLocaleString() }}</b>
          <em>{{ pct(s) }}</em>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
.dc { display: flex; align-items: center; gap: 16px; }
.dc-plot { position: relative; width: 132px; flex-shrink: 0; }
.dc-svg { display: block; width: 100%; height: auto; }
.dc-seg { transition: stroke-width .12s; cursor: pointer; }
.dc-center { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; pointer-events: none; text-align: center; }
.dc-center b { font-size: 19px; font-weight: 800; color: var(--text, #0f172a); line-height: 1.1; }
.dc-center span { font-size: 10.5px; color: var(--text-muted, #64748b); max-width: 74px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.dc-legend { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 3px; }
.dc-row { display: flex; align-items: center; gap: 8px; padding: 4px 8px; border-radius: 7px; cursor: default; }
.dc-row.on { background: var(--bg, #f1f5f9); }
.dc-row i { width: 10px; height: 10px; border-radius: 3px; flex-shrink: 0; }
.dc-lab { flex: 1; min-width: 0; font-size: 12.5px; color: var(--text, #0f172a); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; text-transform: capitalize; }
.dc-row b { font-size: 12.5px; color: var(--text, #0f172a); font-variant-numeric: tabular-nums; }
.dc-row em { font-style: normal; font-size: 11px; color: var(--text-light, #94a3b8); width: 34px; text-align: right; font-variant-numeric: tabular-nums; }
.dc-empty { width: 100%; padding: 34px 0; text-align: center; font-size: 13px; color: var(--text-muted, #64748b); }
</style>
