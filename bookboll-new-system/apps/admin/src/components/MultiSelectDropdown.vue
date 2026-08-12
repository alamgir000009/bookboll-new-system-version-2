<script setup>
/**
 * MultiSelectDropdown — a compact checkbox dropdown for multi-select.
 * v-model is an array of selected values. Empty = "all" (shown via allLabel).
 */
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { ChevronDown, Check } from 'lucide-vue-next'

const props = defineProps({
  modelValue: { type: Array, default: () => [] },
  options: { type: Array, default: () => [] },   // [{ value, label }]
  allLabel: { type: String, default: 'All' },
  disabled: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue'])

const open = ref(false)
const root = ref(null)

const label = computed(() => {
  const sel = props.modelValue || []
  if (!sel.length) return props.allLabel
  if (sel.length === 1) return props.options.find(o => o.value === sel[0])?.label || '1 selected'
  return `${sel.length} selected`
})

function toggle(v) {
  const set = new Set(props.modelValue || [])
  set.has(v) ? set.delete(v) : set.add(v)
  emit('update:modelValue', [...set])
}
function clear() { emit('update:modelValue', []) }

function onDocClick(e) { if (root.value && !root.value.contains(e.target)) open.value = false }
onMounted(() => document.addEventListener('click', onDocClick))
onBeforeUnmount(() => document.removeEventListener('click', onDocClick))
</script>

<template>
  <div class="ms" ref="root">
    <button type="button" class="ms-btn" :class="{ on: open }" :disabled="disabled" @click="open = !open">
      <span class="ms-label" :class="{ muted: !(modelValue && modelValue.length) }">{{ label }}</span>
      <ChevronDown :size="15" />
    </button>
    <div v-if="open" class="ms-panel">
      <button v-if="modelValue && modelValue.length" type="button" class="ms-clear" @click="clear">Clear · show {{ allLabel.toLowerCase() }}</button>
      <div v-if="!options.length" class="ms-empty">No options</div>
      <label v-for="o in options" :key="o.value" class="ms-opt">
        <input type="checkbox" :checked="(modelValue || []).includes(o.value)" @change="toggle(o.value)" />
        <span class="ms-opt-l">{{ o.label }}</span>
        <Check v-if="(modelValue || []).includes(o.value)" :size="14" class="ms-chk" />
      </label>
    </div>
  </div>
</template>

<style scoped>
.ms { position: relative; }
.ms-btn { width: 100%; display: flex; align-items: center; justify-content: space-between; gap: 8px; height: 40px; padding: 0 12px; border: 1px solid var(--border, #e2e8f0); border-radius: 9px; background: var(--surface, #ffffff); font-size: 14px; color: var(--text, #0f172a); cursor: pointer; }
.ms-btn:hover:not(:disabled) { border-color: #14b8a6; }
.ms-btn.on { border-color: #14b8a6; box-shadow: 0 0 0 3px rgba(15,118,110,.12); }
.ms-btn:disabled { opacity: .55; cursor: not-allowed; }
.ms-label { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-weight: 600; }
.ms-label.muted { color: var(--text-muted, #94a3b8); font-weight: 500; }
.ms-panel { position: absolute; z-index: 30; top: 44px; left: 0; right: 0; max-height: 240px; overflow-y: auto; background: var(--surface, #ffffff); border: 1px solid var(--border, #e2e8f0); border-radius: 10px; box-shadow: 0 12px 32px rgba(15,23,42,.16); padding: 6px; }
.ms-clear { width: 100%; text-align: left; background: none; border: none; color: #0f766e; font-size: 12.5px; font-weight: 700; padding: 7px 8px; cursor: pointer; border-radius: 7px; }
.ms-clear:hover { background: #f0fdfa; }
.ms-empty { font-size: 12.5px; color: var(--text-muted, #94a3b8); padding: 10px 8px; }
.ms-opt { display: flex; align-items: center; gap: 9px; padding: 8px; border-radius: 7px; cursor: pointer; font-size: 13.5px; color: var(--text, #0f172a); }
.ms-opt:hover { background: #f1f5f9; }
.ms-opt input { width: 16px; height: 16px; accent-color: #0f766e; flex-shrink: 0; }
.ms-opt-l { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.ms-chk { color: #0f766e; flex-shrink: 0; }
</style>
