<script setup>
import { ref, computed, onMounted, onUnmounted, nextTick } from 'vue'
import { ChevronDown, X, Check } from 'lucide-vue-next'

const props = defineProps({
  modelValue: { type: Array, default: () => [] },
  options: { type: Array, default: () => [] }, // [{ value, label }]
  placeholder: { type: String, default: 'All' },
  maxDisplay: { type: Number, default: 2 },
})
const emit = defineEmits(['update:modelValue'])

const open = ref(false)
const trigger = ref(null)
const dropdownStyle = ref({})

const selected = computed(() => props.modelValue)

async function openDropdown() {
  open.value = true
  await nextTick()
  positionDropdown()
}

function positionDropdown() {
  if (!trigger.value) return
  const rect = trigger.value.getBoundingClientRect()
  const spaceBelow = window.innerHeight - rect.bottom
  const dropH = Math.min(260, props.options.length * 36 + 8)
  const goUp = spaceBelow < dropH + 8 && rect.top > dropH + 8

  dropdownStyle.value = {
    position: 'fixed',
    left: rect.left + 'px',
    width: Math.max(rect.width, 180) + 'px',
    zIndex: 9999,
    ...(goUp
      ? { bottom: (window.innerHeight - rect.top + 4) + 'px', top: 'auto' }
      : { top: (rect.bottom + 4) + 'px', bottom: 'auto' }),
  }
}

function toggle(val) {
  const s = [...selected.value]
  const i = s.indexOf(val)
  if (i === -1) s.push(val)
  else s.splice(i, 1)
  emit('update:modelValue', s)
}

function clear() {
  emit('update:modelValue', [])
}

const displayLabel = computed(() => {
  if (!selected.value.length) return props.placeholder
  if (selected.value.length <= props.maxDisplay)
    return selected.value.map(v => props.options.find(o => o.value === v)?.label || v).join(', ')
  return `${selected.value.length} selected`
})

function onOutside(e) {
  if (!open.value) return
  if (trigger.value && trigger.value.contains(e.target)) return
  // check if click is inside the teleported dropdown
  const dd = document.getElementById('ms-teleport-' + uid)
  if (dd && dd.contains(e.target)) return
  open.value = false
}

// unique id per instance so teleport target can be identified
const uid = Math.random().toString(36).slice(2)

onMounted(() => {
  document.addEventListener('mousedown', onOutside)
  window.addEventListener('scroll', () => { if (open.value) positionDropdown() }, true)
  window.addEventListener('resize', () => { if (open.value) positionDropdown() })
})
onUnmounted(() => {
  document.removeEventListener('mousedown', onOutside)
})
</script>

<template>
  <div class="ms-wrap">
    <button
      ref="trigger"
      type="button"
      class="ms-trigger"
      :class="{ active: selected.length, open }"
      @click="open ? (open = false) : openDropdown()"
    >
      <span class="ms-label">{{ displayLabel }}</span>
      <span v-if="selected.length" class="ms-count" @click.stop="clear"><X :size="11" /></span>
      <ChevronDown :size="14" class="ms-chevron" :class="{ rotated: open }" />
    </button>

    <Teleport to="body">
      <!-- Stop clicks here: the menu is teleported to <body>, so without this a
           click on an option looks like an "outside click" to any parent panel
           (e.g. FilterBar) and closes it after every single selection. -->
      <div
        v-if="open"
        :id="'ms-teleport-' + uid"
        class="ms-dropdown"
        :style="dropdownStyle"
        @click.stop
        @mousedown.stop
      >
        <label
          v-for="opt in options"
          :key="opt.value"
          class="ms-option"
          :class="{ checked: selected.includes(opt.value) }"
          @mousedown.prevent="toggle(opt.value)"
        >
          <span class="ms-checkbox"><Check v-if="selected.includes(opt.value)" :size="10" /></span>
          <span class="ms-opt-label">{{ opt.label }}</span>
        </label>
      </div>
    </Teleport>
  </div>
</template>

<style scoped>
.ms-wrap { position: relative; display: inline-block; min-width: 130px; width: 100%; }

.ms-trigger {
  display: flex; align-items: center; gap: 6px;
  width: 100%; padding: 0 10px; height: 36px;
  background: var(--card); border: 1px solid var(--border);
  border-radius: 8px; cursor: pointer; font-size: 13px;
  color: var(--text-muted); transition: border-color .15s, box-shadow .15s;
  white-space: nowrap;
}
.ms-trigger:hover { border-color: var(--primary); }
.ms-trigger.active { border-color: var(--primary); color: var(--text); background: #eff6ff; }
.ms-trigger.open { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(59,130,246,.12); }

.ms-label { flex: 1; text-align: left; overflow: hidden; text-overflow: ellipsis; }
.ms-count {
  display: inline-flex; align-items: center; justify-content: center;
  width: 16px; height: 16px; border-radius: 50%;
  background: var(--primary); color: #fff; cursor: pointer; flex-shrink: 0;
}
.ms-count:hover { background: #1d4ed8; }
.ms-chevron { flex-shrink: 0; color: var(--text-muted); transition: transform .2s; }
.ms-chevron.rotated { transform: rotate(180deg); }
</style>

<style>
/* global — dropdown is teleported outside scoped component */
.ms-dropdown {
  max-height: 260px; overflow-y: auto;
  background: var(--surface, #ffffff); border: 1px solid var(--border, #e2e8f0);
  border-radius: 10px; box-shadow: 0 8px 32px rgba(0,0,0,.14);
  padding: 4px;
}

.ms-option {
  display: flex; align-items: center; gap: 8px;
  padding: 7px 10px; border-radius: 6px; cursor: pointer;
  font-size: 13px; color: #1e293b; transition: background .1s;
  user-select: none;
}
.ms-option:hover { background: #f8fafc; }
.ms-option.checked { background: #eff6ff; color: #1d4ed8; }

.ms-checkbox {
  width: 16px; height: 16px; border-radius: 4px; flex-shrink: 0;
  border: 1.5px solid #cbd5e1; display: flex; align-items: center;
  justify-content: center; background: var(--surface, #ffffff); transition: all .15s;
}
.ms-option.checked .ms-checkbox {
  background: #3b82f6; border-color: #3b82f6; color: #fff;
}
</style>
