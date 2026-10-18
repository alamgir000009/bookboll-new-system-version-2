<script setup>
/**
 * ConnectPicker.vue — a searchable, multi-add dropdown used in the listing form
 * to connect add-ons / resources. Replaces the plain <select>:
 *   • type-to-filter search
 *   • rich rows (name, sub-line, right-hand meta, optional badge)
 *   • stays open after a pick so several can be added quickly
 *   • a pinned "Add new…" action at the bottom
 *   • click-outside / Esc to close
 *
 * Props : options [{ id, name, sub, right, badge, badgeClass }], placeholder,
 *         addNewLabel, icon (lucide component)
 * Emits : select(id), add-new
 */
import { ref, computed, nextTick, onMounted, onBeforeUnmount } from 'vue'
import { Search, Plus, ChevronDown } from 'lucide-vue-next'

const props = defineProps({
  options:     { type: Array,  default: () => [] },
  placeholder: { type: String, default: 'Search…' },
  addNewLabel: { type: String, default: 'Add new' },
  icon:        { type: [Object, Function], default: null },
})
const emit = defineEmits(['select', 'add-new'])

const open    = ref(false)
const query   = ref('')
const rootEl  = ref(null)
const inputEl = ref(null)

const filtered = computed(() => {
  const q = query.value.trim().toLowerCase()
  if (!q) return props.options
  return props.options.filter(o =>
    (o.name || '').toLowerCase().includes(q) ||
    (o.sub  || '').toLowerCase().includes(q) ||
    (o.right|| '').toLowerCase().includes(q)
  )
})

function openPanel() {
  open.value = true
  query.value = ''
  nextTick(() => inputEl.value?.focus())
}
function pick(o) {
  emit('select', o.id)
  query.value = ''
  nextTick(() => inputEl.value?.focus())   // keep open for quick multi-add
}
function addNew() { open.value = false; emit('add-new') }

function onDocClick(e) { if (rootEl.value && !rootEl.value.contains(e.target)) open.value = false }
onMounted(() => document.addEventListener('mousedown', onDocClick))
onBeforeUnmount(() => document.removeEventListener('mousedown', onDocClick))
</script>

<template>
  <div class="cp" ref="rootEl">
    <button type="button" class="cp-trigger" :class="{ open }" @click="open ? (open = false) : openPanel()">
      <component :is="icon" v-if="icon" :size="15" class="cp-trigger-icon" />
      <span class="cp-trigger-label">{{ placeholder }}</span>
      <ChevronDown :size="16" class="cp-caret" :class="{ rot: open }" />
    </button>

    <div v-if="open" class="cp-panel">
      <div class="cp-search">
        <Search :size="14" />
        <input ref="inputEl" v-model="query" :placeholder="placeholder" @keydown.esc.stop="open = false" />
      </div>

      <div class="cp-list">
        <button v-for="o in filtered" :key="o.id" type="button" class="cp-opt" @click="pick(o)">
          <div class="cp-opt-main">
            <div class="cp-opt-name">{{ o.name }}</div>
            <div v-if="o.sub" class="cp-opt-sub">{{ o.sub }}</div>
          </div>
          <span v-if="o.badge" class="cp-opt-badge" :class="o.badgeClass">{{ o.badge }}</span>
          <span v-if="o.right" class="cp-opt-right">{{ o.right }}</span>
          <span class="cp-opt-add"><Plus :size="15" /></span>
        </button>
        <div v-if="!filtered.length" class="cp-empty">
          {{ query ? 'No matches for “' + query + '”.' : 'Nothing available — create one below.' }}
        </div>
      </div>

      <button type="button" class="cp-addnew" @click="addNew"><Plus :size="15" />{{ addNewLabel }}</button>
    </div>
  </div>
</template>

<style scoped>
.cp { position: relative; width: 100%; margin-bottom: 12px; }

.cp-trigger {
  width: 100%; display: flex; align-items: center; gap: 9px;
  padding: 10px 12px; border: 1px solid var(--border); border-radius: 10px;
  background: var(--surface, #ffffff); cursor: pointer; transition: border-color .15s, box-shadow .15s;
  font-size: 13.5px; color: var(--text-muted); text-align: left;
}
.cp-trigger:hover { border-color: var(--border-strong, #cbd5e1); }
.cp-trigger.open { border-color: var(--primary, #0891b2); box-shadow: 0 0 0 3px rgba(8,145,178,.12); }
.cp-trigger-icon { color: var(--primary-ink, #0891b2); flex-shrink: 0; }
.cp-trigger-label { flex: 1; }
.cp-caret { color: var(--text-light, #94a3b8); transition: transform .18s; flex-shrink: 0; }
.cp-caret.rot { transform: rotate(180deg); }

.cp-panel {
  position: absolute; z-index: 40; top: calc(100% + 6px); left: 0; right: 0;
  background: var(--surface, #ffffff); border: 1px solid var(--border); border-radius: 12px;
  box-shadow: 0 12px 32px rgba(15,23,42,.16); overflow: hidden;
  display: flex; flex-direction: column; max-height: 320px;
  animation: cpDrop .14s ease;
}
@keyframes cpDrop { from { opacity: 0; transform: translateY(-4px); } to { opacity: 1; transform: translateY(0); } }

.cp-search {
  display: flex; align-items: center; gap: 8px; padding: 10px 12px;
  border-bottom: 1px solid var(--border); color: var(--text-light, #94a3b8); flex-shrink: 0;
}
.cp-search input { flex: 1; border: none; outline: none; font-size: 13.5px; color: var(--text); background: transparent; padding: 0; }

.cp-list { overflow-y: auto; padding: 6px; flex: 1; }
.cp-opt {
  width: 100%; display: flex; align-items: center; gap: 10px;
  padding: 9px 10px; border: none; background: transparent; border-radius: 8px;
  cursor: pointer; text-align: left; transition: background .12s;
}
.cp-opt:hover { background: var(--primary-soft, #f0fdfa); }
.cp-opt-main { flex: 1; min-width: 0; }
.cp-opt-name { font-size: 13.5px; font-weight: 600; color: var(--text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.cp-opt-sub { font-size: 11.5px; color: var(--text-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-top: 1px; }
.cp-opt-badge {
  font-size: 10px; font-weight: 600; padding: 1px 7px; border-radius: 6px; flex-shrink: 0;
  background: var(--bg, #f1f5f9); color: var(--text-secondary, #475569); border: 1px solid var(--border);
}
.cp-opt-badge.warn { background: var(--warning-bg, #fffbeb); color: var(--warning-ink, #b45309); border-color: var(--warning-border, #fde68a); }
.cp-opt-badge.danger { background: var(--danger-bg, #fef2f2); color: var(--danger-ink, #b91c1c); border-color: var(--danger-border, #fecaca); }
.cp-opt-right { font-size: 12.5px; font-weight: 600; color: var(--primary-ink, #0f766e); flex-shrink: 0; white-space: nowrap; }
.cp-opt-add {
  display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0;
  width: 24px; height: 24px; border-radius: 7px; background: var(--positive-bg, #ecfdf5); color: var(--primary-ink, #0f766e);
}
.cp-opt:hover .cp-opt-add { background: #0f766e; color: #fff; }

.cp-empty { padding: 18px 12px; text-align: center; font-size: 12.5px; color: var(--text-muted); }

.cp-addnew {
  display: flex; align-items: center; justify-content: center; gap: 6px;
  padding: 11px; border: none; border-top: 1px dashed var(--border);
  background: var(--surface-hover, #f8fafc); color: var(--primary-ink, #0891b2); font-size: 13px; font-weight: 600;
  cursor: pointer; flex-shrink: 0; transition: background .12s;
}
.cp-addnew:hover { background: var(--primary-soft, #f0fdfa); }
</style>
