<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { ChevronLeft, ChevronRight } from 'lucide-vue-next'

const props = defineProps({
  modelValue: { type: String, default: '' },   // 'YYYY-MM-DD'
  maxMonths: { type: Number, default: 12 },     // how many months ahead are bookable
  priceFor: { type: Function, default: null },  // (dateStr) => cheapest available price | null
  currency: { type: String, default: '' },
})
const emit = defineEmits(['update:modelValue', 'month'])

function ymd(d) { const z = n => String(n).padStart(2, '0'); return `${d.getFullYear()}-${z(d.getMonth() + 1)}-${z(d.getDate())}` }

const today = new Date(); today.setHours(0, 0, 0, 0)
const todayStr = ymd(today)
const minMonth = new Date(today.getFullYear(), today.getMonth(), 1)
const maxMonth = new Date(today.getFullYear(), today.getMonth() + props.maxMonths, 1)

// The month currently shown (1st of month).
const start = props.modelValue ? new Date(props.modelValue + 'T00:00:00') : new Date(today)
const view = ref(new Date(start.getFullYear(), start.getMonth(), 1))

const monthLabel = computed(() => view.value.toLocaleDateString('en-US', { month: 'long', year: 'numeric' }))
const canPrev = computed(() => view.value > minMonth)
const canNext = computed(() => view.value < maxMonth)
function prev() { if (canPrev.value) view.value = new Date(view.value.getFullYear(), view.value.getMonth() - 1, 1) }
function next() { if (canNext.value) view.value = new Date(view.value.getFullYear(), view.value.getMonth() + 1, 1) }

// Tell the parent which month is shown so it can load that month's prices.
onMounted(() => emit('month', view.value))
watch(view, v => emit('month', v))

const fmtMoney = (n) => Number(n).toLocaleString()

const weeks = computed(() => {
  const y = view.value.getFullYear(), m = view.value.getMonth()
  const startDow = new Date(y, m, 1).getDay()
  const daysInMonth = new Date(y, m + 1, 0).getDate()
  const cells = []
  for (let i = 0; i < startDow; i++) cells.push(null)
  for (let d = 1; d <= daysInMonth; d++) {
    const ds = ymd(new Date(y, m, d))
    const past = ds < todayStr
    const price = (props.priceFor && !past) ? props.priceFor(ds) : null
    cells.push({ day: d, date: ds, past, isToday: ds === todayStr, selected: ds === props.modelValue, price, tier: '' })
  }
  // Colour relative to THIS month: green = cheapest tier, yellow = mid, orange = priciest.
  const prices = cells.filter(c => c && c.price != null).map(c => Number(c.price))
  if (prices.length) {
    const min = Math.min(...prices), max = Math.max(...prices)
    for (const c of cells) {
      if (!c || c.price == null) continue
      if (max === min) { c.tier = 'low'; continue }
      const f = (Number(c.price) - min) / (max - min)
      c.tier = f < 1 / 3 ? 'low' : f < 2 / 3 ? 'mid' : 'high'
    }
  }
  while (cells.length % 7 !== 0) cells.push(null)
  const w = []; for (let i = 0; i < cells.length; i += 7) w.push(cells.slice(i, i + 7))
  return w
})

function pick(c) { if (c && !c.past) emit('update:modelValue', c.date) }
const WEEKDAYS = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa']
</script>

<template>
  <div class="cal">
    <div class="cal-head">
      <button type="button" class="cal-nav" :disabled="!canPrev" @click="prev"><ChevronLeft :size="18" /></button>
      <div class="cal-title">{{ monthLabel }}</div>
      <button type="button" class="cal-nav" :disabled="!canNext" @click="next"><ChevronRight :size="18" /></button>
    </div>
    <div class="cal-grid cal-wd">
      <div v-for="w in WEEKDAYS" :key="w" class="cal-wd-cell">{{ w }}</div>
    </div>
    <div v-for="(week, wi) in weeks" :key="wi" class="cal-grid">
      <template v-for="(c, ci) in week" :key="ci">
        <button v-if="c" type="button" class="cal-day"
          :class="{ today: c.isToday, selected: c.selected, past: c.past, 'has-price': c.price != null }"
          :disabled="c.past" @click="pick(c)">
          <span class="cal-num">{{ c.day }}</span>
          <span v-if="c.price != null" class="cal-price" :class="'price-' + c.tier">{{ currency }} {{ fmtMoney(c.price) }}</span>
        </button>
        <span v-else class="cal-empty"></span>
      </template>
    </div>
  </div>
</template>

<style scoped>
.cal { border: 1px solid var(--border, #e2e8f0); border-radius: 16px; padding: 14px 16px 14px; background: var(--surface, #ffffff); width: 380px; max-width: 92vw; }
.cal-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
.cal-title { font-size: 15.5px; font-weight: 800; color: #0f172a; }
.cal-nav { width: 34px; height: 34px; border-radius: 9px; border: 1.5px solid var(--border, #e2e8f0); background: var(--surface, #ffffff); color: #475569; display: flex; align-items: center; justify-content: center; cursor: pointer; }
.cal-nav:hover:not(:disabled) { border-color: var(--brand-accent); color: var(--brand); }
.cal-nav:disabled { opacity: .35; cursor: not-allowed; }
.cal-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; }
.cal-wd { margin-bottom: 4px; }
.cal-wd-cell { text-align: center; font-size: 11px; font-weight: 700; color: var(--text-light, #94a3b8); padding: 4px 0; }
.cal-day { display: flex; flex-direction: column; align-items: center; justify-content: flex-start; gap: 1px; min-height: 46px; padding: 4px 1px; border: none; background: none; border-radius: 10px; cursor: pointer; }
.cal-day:hover:not(:disabled):not(.selected) { background: color-mix(in srgb, var(--brand) 8%, white); }
.cal-num { font-size: 13.5px; font-weight: 700; color: #0f172a; line-height: 1.1; }
.cal-day.today { box-shadow: inset 0 0 0 1.5px #99f6e4; }
.cal-day.selected { background: var(--brand); }
.cal-day.selected .cal-num { color: #fff; }
.cal-day.selected .cal-price { color: #fff !important; }
.cal-day.past { cursor: not-allowed; }
.cal-day.past .cal-num { color: #cbd5e1; }
.cal-price { font-size: 9.5px; font-weight: 800; line-height: 1.05; white-space: nowrap; }
.price-low { color: #16a34a; }   /* green — cheapest */
.price-mid { color: #d97706; }   /* amber — mid */
.price-high { color: #ea580c; }  /* orange — priciest */
.cal-empty { min-height: 46px; }

/* ── Mobile (≈375px): full-width calendar, 44px tap targets, shorter footprint ── */
@media (max-width: 760px) {
  .cal { width: 100%; max-width: 100%; padding: 10px 10px; }
  .cal-head { margin-bottom: 8px; }
  .cal-nav { width: 44px; height: 44px; border-radius: 11px; }
  .cal-grid { gap: 3px; }
  .cal-wd-cell { font-size: 12px; }
  .cal-num { font-size: 15px; }
  .cal-price { font-size: 11px; max-width: 100%; overflow: hidden; text-overflow: ellipsis; }
  .cal-day { min-height: 44px; min-width: 0; }
  .cal-empty { min-height: 44px; min-width: 0; }
}
</style>
