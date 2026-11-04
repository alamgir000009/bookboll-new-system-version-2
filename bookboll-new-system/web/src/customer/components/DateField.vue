<script setup>
import { ref, computed, onMounted, onUnmounted, nextTick, watch } from 'vue'
import CalendarPicker from './CalendarPicker.vue'
import { Calendar, ChevronDown } from 'lucide-vue-next'

const props = defineProps({
  modelValue: { type: String, default: '' },
  priceFor: { type: Function, default: null },   // (dateStr) => cheapest price | null
  currency: { type: String, default: '' },
})
const emit = defineEmits(['update:modelValue', 'month'])

const open = ref(false)
const root = ref(null)
const popRef = ref(null)
const popStyle = ref({})

const label = computed(() => props.modelValue
  ? new Date(props.modelValue + 'T00:00:00').toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric' })
  : 'Select a date')

// Place the popup so it's never cut off: open below if there's room, otherwise above,
// otherwise pin to the viewport and let it scroll. Anchored with position:fixed so no
// ancestor's overflow can clip it.
function reposition() {
  const trig = root.value?.querySelector('.df-trigger')
  const pop = popRef.value
  if (!trig || !pop) return
  const r = trig.getBoundingClientRect()
  const vw = document.documentElement.clientWidth, vh = window.innerHeight, margin = 8
  const popW = pop.offsetWidth || 380
  const popH = pop.offsetHeight || 400
  const spaceBelow = vh - r.bottom - margin
  const spaceAbove = r.top - margin
  let top, maxHeight = ''
  if (spaceBelow >= popH || spaceBelow >= spaceAbove) {
    top = r.bottom + margin
    if (popH > spaceBelow) maxHeight = Math.max(220, vh - top - margin) + 'px'
  } else if (popH <= spaceAbove) {
    top = r.top - popH - margin
  } else {
    top = margin
    maxHeight = (spaceAbove + r.height) + 'px'   // tallest fit, pinned near the top
  }
  const left = Math.max(margin, Math.min(r.left, vw - popW - margin))
  popStyle.value = {
    position: 'fixed', top: top + 'px', left: left + 'px', transform: 'none',
    maxHeight: maxHeight || '', overflowY: maxHeight ? 'auto' : '',
  }
}

function toggle() { open.value = !open.value }
function onPick(v) { emit('update:modelValue', v); open.value = false }
function onMonth(m) { emit('month', m); nextTick(reposition) }   // 5↔6 week rows change height
function onDocClick(e) { if (open.value && root.value && !root.value.contains(e.target)) open.value = false }
function onKey(e) { if (e.key === 'Escape') open.value = false }

watch(open, (o) => {
  if (o) {
    nextTick(reposition)
    window.addEventListener('scroll', reposition, true)
    window.addEventListener('resize', reposition)
  } else {
    window.removeEventListener('scroll', reposition, true)
    window.removeEventListener('resize', reposition)
  }
})
onMounted(() => { document.addEventListener('mousedown', onDocClick); document.addEventListener('keydown', onKey) })
onUnmounted(() => {
  document.removeEventListener('mousedown', onDocClick); document.removeEventListener('keydown', onKey)
  window.removeEventListener('scroll', reposition, true); window.removeEventListener('resize', reposition)
})
</script>

<template>
  <div class="df" ref="root">
    <button type="button" class="df-trigger" :class="{ open }" @click="toggle">
      <Calendar :size="16" class="df-cal" />
      <span class="df-label">{{ label }}</span>
      <ChevronDown :size="16" class="df-chev" :class="{ rot: open }" />
    </button>
    <div v-if="open" class="df-pop" ref="popRef" :style="popStyle">
      <CalendarPicker :model-value="modelValue" :price-for="priceFor" :currency="currency" @update:model-value="onPick" @month="onMonth" />
    </div>
  </div>
</template>

<style scoped>
.df { position: relative; display: inline-block; max-width: 100%; }
.df-trigger { display: inline-flex; align-items: center; gap: 9px; border: 1.5px solid var(--border, #e2e8f0); border-radius: 12px; background: var(--surface, #ffffff); padding: 12px 16px; cursor: pointer; color: var(--text, #0f172a); font-size: 15px; font-weight: 700; min-width: 250px; max-width: 100%; }
.df-trigger:hover, .df-trigger.open { border-color: var(--brand-accent); }
.df-cal { color: var(--brand-ink, var(--brand)); flex-shrink: 0; }
.df-label { flex: 1; text-align: left; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.df-chev { color: var(--text-light, #94a3b8); transition: transform .15s; flex-shrink: 0; }
.df-chev.rot { transform: rotate(180deg); }
/* Positioned via JS (position:fixed) so it can never be clipped by a short page. */
.df-pop { z-index: 1000; box-shadow: 0 16px 40px rgba(15,23,42,.18); border-radius: 16px; }

/* Phone: JS reposition() clamps only the LEFT edge (assumes ~380px pop width), so on
   narrow viewports (360/344px) an over-wide CalendarPicker could push the popup's right
   edge past the screen and cause body-level horizontal scroll. Cap the fixed popup at
   viewport width minus the 8px margins the JS already uses and scroll inside instead. */
@media (max-width: 760px) {
  .df-pop { width: min(380px, calc(100% - 16px)); max-width: calc(100% - 16px); overflow-x: auto; }
}
</style>
