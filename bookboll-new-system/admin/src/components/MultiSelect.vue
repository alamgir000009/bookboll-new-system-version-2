<script setup>
import { ref, computed, onMounted, onUnmounted, nextTick } from 'vue'
import { ChevronDown, Search } from 'lucide-vue-next'
import { adminBrandVars } from '../lib/adminBrand.js'
const props = defineProps({
  modelValue: { type: Array, default: () => [] }, options: { type: Array, default: () => [] },
  placeholder: { type: String, default: 'All' }, maxDisplay: { type: Number, default: 2 }, disabled: Boolean,
})
const emit = defineEmits(['update:modelValue'])
const open = ref(false), trigger = ref(null), searchInput = ref(null), panel = ref(null), query = ref(''), dropdownStyle = ref({})
const uid = 'filter-' + Math.random().toString(36).slice(2)
const selected = computed(() => props.modelValue || [])
const matches = computed(() => props.options.filter(o => String(o.label).toLocaleLowerCase().includes(query.value.trim().toLocaleLowerCase())))
const allMatchesSelected = computed(() => matches.value.length > 0 && matches.value.every(o => selected.value.includes(o.value)))
const someMatchesSelected = computed(() => matches.value.some(o => selected.value.includes(o.value)))
const displayLabel = computed(() => !selected.value.length ? props.placeholder : selected.value.length > props.maxDisplay ? selected.value.length + ' selected' : selected.value.map(v => props.options.find(o => o.value === v)?.label || v).join(', '))
function positionDropdown() {
  if (!open.value || !trigger.value) return
  const r = trigger.value.getBoundingClientRect(), width = Math.min(Math.max(r.width, 260), window.innerWidth - 24)
  const below = window.innerHeight - r.bottom - 12, above = r.top - 12
  const up = below < 280 && above > below
  dropdownStyle.value = { position: 'fixed', zIndex: 10050, width: width + 'px', left: Math.max(12, Math.min(r.left, window.innerWidth - width - 12)) + 'px', maxHeight: Math.max(120, Math.min(360, up ? above : below)) + 'px', ...(up ? { bottom: (window.innerHeight - r.top + 4) + 'px' } : { top: (r.bottom + 4) + 'px' }) }
}
async function toggleOpen() {
  if (open.value) { close(); return }
  query.value = ''; open.value = true
  await nextTick(); positionDropdown(); searchInput.value?.focus()
}
function close(focus = true) { open.value = false; if (focus) trigger.value?.focus() }
function toggle(value) {
  emit('update:modelValue', selected.value.includes(value) ? selected.value.filter(v => v !== value) : [...selected.value, value])
}
function toggleAllMatches(checked) {
  const values = new Set(matches.value.map(o => o.value))
  emit('update:modelValue', checked ? [...new Set([...selected.value, ...values])] : selected.value.filter(value => !values.has(value)))
}
function outside(e) { if (open.value && !trigger.value?.contains(e.target) && !panel.value?.contains(e.target)) close(false) }
onMounted(() => { document.addEventListener('pointerdown', outside); document.addEventListener('focusin', outside); window.addEventListener('scroll', positionDropdown, true); window.addEventListener('resize', positionDropdown) })
onUnmounted(() => { document.removeEventListener('pointerdown', outside); document.removeEventListener('focusin', outside); window.removeEventListener('scroll', positionDropdown, true); window.removeEventListener('resize', positionDropdown) })
</script>
<template>
  <div class="ms-wrap">
    <button ref="trigger" type="button" class="ms-trigger ms-btn" :class="{ active: selected.length, open }" :disabled="disabled" :aria-label="selected.length ? placeholder + ': ' + displayLabel : placeholder" :aria-expanded="open" :aria-controls="uid" @click="toggleOpen" @keydown.down.prevent="!open && toggleOpen()">
      <span class="ms-label">{{ displayLabel }}</span><ChevronDown :size="16" />
    </button>
    <Teleport to="body">
      <div v-if="open" :id="uid" ref="panel" v-dialog="close" aria-label="Filter options" class="ms-dropdown" :style="[adminBrandVars, dropdownStyle]" @click.stop @mousedown.stop @keydown.esc.stop.prevent="close()">
        <div class="ms-search"><Search :size="16" /><input ref="searchInput" v-model="query" type="search" :aria-label="'Search ' + placeholder.toLowerCase()" placeholder="Search options…" /></div>
        <div class="ms-options">
          <label v-for="opt in matches" :key="opt.value" class="ms-option" :class="{ checked: selected.includes(opt.value) }"><input type="checkbox" :checked="selected.includes(opt.value)" @change="toggle(opt.value)" /><span>{{ opt.label }}</span></label>
          <p v-if="!matches.length" class="ms-empty">{{ query ? 'No matching options.' : 'No options available.' }}</p>
        </div>
        <div class="ms-footer">
          <label class="ms-option ms-select-all"><input type="checkbox" :checked="allMatchesSelected" :indeterminate="someMatchesSelected && !allMatchesSelected" :disabled="!matches.length" @change="toggleAllMatches($event.target.checked)" /><span>{{ query.trim() ? 'Select all matches' : 'Select all' }}</span><small>{{ matches.length }}</small></label>
          <div class="ms-footer-actions"><span aria-live="polite">{{ selected.length }} selected</span><button type="button" :disabled="!selected.length" @click="emit('update:modelValue', [])">Clear all</button><button type="button" @click="close()">Done</button></div>
        </div>
      </div>
    </Teleport>
  </div>
</template>
<style scoped>
.ms-wrap { position:relative; min-width:0; width:100%; }
.ms-trigger { display:flex; align-items:center; gap:8px; width:100%; min-height:40px; padding:8px 12px; border:1px solid var(--border); border-radius:8px; background:var(--surface); color:var(--text); font:inherit; font-size:14px; cursor:pointer; }
.ms-trigger.active,.ms-trigger.open { border-color:var(--primary); }
.ms-trigger:focus-visible { outline:2px solid var(--primary); outline-offset:2px; }
.ms-trigger:disabled { opacity:.55; cursor:not-allowed; }
.ms-label { flex:1; min-width:0; text-align:start; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.ms-trigger svg { flex-shrink:0; color:var(--text-muted); }
@media(max-width:640px) { .ms-trigger { min-height:44px; font-size:16px; } }
</style>
<style>
.ms-dropdown { display:flex; flex-direction:column; overflow:hidden; color:var(--text); background:var(--surface); border:1px solid var(--border); border-radius:12px; box-shadow:0 12px 40px #0003; }
.ms-search { display:flex; align-items:center; gap:8px; padding:10px 12px; border-bottom:1px solid var(--border); flex-shrink:0; }
.ms-search input { width:100%; min-width:0; font-size:16px; padding:8px; color:var(--text); background:var(--bg); border:1px solid var(--border); border-radius:6px; }
.ms-options { overflow:auto; overscroll-behavior:contain; padding:4px; min-height:0; }
.ms-option { display:flex; align-items:center; gap:10px; min-height:44px; padding:8px 10px; border-radius:6px; font-size:14px; cursor:pointer; color:var(--text); }
.ms-option:hover,.ms-option.checked { background:var(--primary-soft, var(--bg)); }
.ms-option input { flex:none; width:18px; height:18px; accent-color:var(--primary); }
.ms-option span { overflow-wrap:anywhere; }
.ms-select-all { flex-shrink:0; border-radius:0; border-bottom:1px solid var(--border); font-weight:600; }
.ms-select-all small { margin-inline-start:auto; color:var(--text-muted); font-weight:400; }
.ms-select-all:has(input:disabled) { opacity:.55; cursor:default; }
.ms-empty { padding:12px; color:var(--text-muted); font-size:14px; }
.ms-footer { border-top:1px solid var(--border); padding:0 4px; flex-shrink:0; font-size:12px; }
.ms-footer-actions { display:flex; align-items:center; gap:8px; padding:6px; }
.ms-footer-actions > span { flex:1; color:var(--text-muted); }
.ms-footer button { min-height:44px; padding:6px 8px; color:var(--primary-ink, var(--primary)); font-weight:600; background:transparent; border:0; cursor:pointer; }
.ms-footer button:disabled { opacity:.45; cursor:default; }
</style>
