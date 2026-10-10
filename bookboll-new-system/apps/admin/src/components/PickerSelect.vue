<script setup>
/**
 * PickerSelect — a custom (non-native) select dropdown.
 *  • v-model holds the chosen option's `value`.
 *  • options: [{ value, label, sub?, right?, disabled? }]
 *  • Shows a "Create new…" action INSIDE the menu (footer) when `createLabel` is set,
 *    so creating a new item lives with the choices rather than as an outside button.
 *
 *  <PickerSelect v-model="x" :options="opts" placeholder="Select…"
 *                create-label="Create a new add-on" @create="openModal" />
 */
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { ChevronDown, Check, Plus, Pencil, Trash2 } from 'lucide-vue-next'

const props = defineProps({
  modelValue: { default: '' },
  options:    { type: Array, default: () => [] },   // [{ value, label, sub, right, disabled }]
  placeholder:{ type: String, default: 'Select…' },
  createLabel:{ type: String, default: '' },
  emptyText:  { type: String, default: 'Nothing here yet' },
  editable:   { type: Boolean, default: false },    // show a pencil to edit each option
  deletable:  { type: Boolean, default: false },    // show a trash to delete each option
})
const emit = defineEmits(['update:modelValue', 'create', 'edit', 'delete'])

const open = ref(false)
const root = ref(null)
/* Option values are ints from the catalogue endpoints (/admin/resources,
   /admin/add-ons) but strings from the listing dashboard, which casts ids on
   the way out. A strict === left already-attached items looking unselected, so
   match on string form instead. */
const sameValue = (a, b) => a != null && b != null && String(a) === String(b)
const selected = computed(() => props.options.find(o => sameValue(o.value, props.modelValue)) || null)

function toggle() { open.value = !open.value }
function pick(o) { if (o.disabled) return; emit('update:modelValue', o.value); open.value = false }
function onCreate() { open.value = false; emit('create') }
function onEdit(o) { open.value = false; emit('edit', o.value) }
function onDelete(o) { open.value = false; emit('delete', o.value) }
function onDoc(e) { if (open.value && root.value && !root.value.contains(e.target)) open.value = false }
function onKey(e) { if (e.key === 'Escape') open.value = false }
onMounted(() => { document.addEventListener('click', onDoc, true); document.addEventListener('keydown', onKey) })
onUnmounted(() => { document.removeEventListener('click', onDoc, true); document.removeEventListener('keydown', onKey) })
</script>

<template>
  <div class="psel" ref="root">
    <button type="button" class="psel-trigger" :class="{ open, empty: !selected }" @click.stop="toggle">
      <span class="psel-value">{{ selected ? selected.label : placeholder }}</span>
      <ChevronDown :size="16" class="psel-caret" :class="{ rot: open }" />
    </button>

    <div v-if="open" class="psel-menu">
      <div class="psel-scroll">
        <div
          v-for="o in options" :key="o.value"
          class="psel-opt-row"
          :class="{ sel: sameValue(o.value, modelValue), disabled: o.disabled }"
        >
          <button type="button" class="psel-opt" :disabled="o.disabled" @click="pick(o)">
            <span class="psel-opt-main">
              <span class="psel-opt-label">{{ o.label }}</span>
              <span v-if="o.sub" class="psel-opt-sub">{{ o.sub }}</span>
            </span>
            <span v-if="o.right" class="psel-opt-right">{{ o.right }}</span>
            <Check v-if="sameValue(o.value, modelValue)" :size="15" class="psel-opt-check" />
          </button>
          <button v-if="editable" type="button" class="psel-opt-edit" title="Edit" @click.stop="onEdit(o)">
            <Pencil :size="14" />
          </button>
          <button v-if="deletable" type="button" class="psel-opt-del" title="Delete" @click.stop="onDelete(o)">
            <Trash2 :size="14" />
          </button>
        </div>
        <div v-if="!options.length" class="psel-empty">{{ emptyText }}</div>
      </div>
      <button v-if="createLabel" type="button" class="psel-create" @click="onCreate">
        <span class="psel-create-ico"><Plus :size="15" /></span>{{ createLabel }}
      </button>
    </div>
  </div>
</template>

<style scoped>
.psel { position: relative; width: 100%; }
.psel-trigger {
  display: flex; align-items: center; gap: 8px; width: 100%; height: 38px;
  padding: 0 10px 0 12px; border: 1px solid var(--border); border-radius: 8px;
  background: var(--surface, #ffffff); color: var(--text); font-size: 13.5px; cursor: pointer; text-align: left;
}
.psel-trigger:hover { border-color: #cbd5e1; }
.psel-trigger.open { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(15,118,110,.12); }
.psel-trigger.empty .psel-value { color: var(--text-light); }
.psel-value { flex: 1; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.psel-caret { color: var(--text-muted); flex-shrink: 0; transition: transform .15s; }
.psel-caret.rot { transform: rotate(180deg); }

.psel-menu {
  position: absolute; top: calc(100% + 6px); left: 0; right: 0; z-index: 60;
  background: var(--surface, #ffffff); border: 1px solid var(--border); border-radius: 12px;
  box-shadow: var(--shadow-lg); overflow: hidden; animation: pselIn .12s ease;
}
@keyframes pselIn { from { opacity: 0; transform: translateY(-4px); } to { opacity: 1; transform: none; } }
.psel-scroll { max-height: 248px; overflow-y: auto; padding: 5px; }
.psel-opt-row { display: flex; align-items: stretch; gap: 2px; border-radius: 8px; }
.psel-opt-row.sel { background: #f0fdfa; }
.psel-opt-row.disabled { opacity: .4; }
.psel-opt {
  flex: 1; min-width: 0;
  display: flex; align-items: center; gap: 10px;
  padding: 9px 10px; border-radius: 8px; background: none; border: none;
  text-align: left; cursor: pointer; transition: background .1s;
}
.psel-opt-row:not(.sel) .psel-opt:hover:not(:disabled) { background: var(--bg); }
.psel-opt:disabled { cursor: not-allowed; }
.psel-opt-edit {
  flex-shrink: 0; display: inline-flex; align-items: center; justify-content: center;
  width: 32px; border: none; background: none; color: var(--text-muted);
  border-radius: 8px; cursor: pointer; transition: background .1s, color .1s;
}
.psel-opt-edit:hover { background: var(--bg); color: var(--primary); }
.psel-opt-del {
  flex-shrink: 0; display: inline-flex; align-items: center; justify-content: center;
  width: 32px; border: none; background: none; color: var(--text-muted);
  border-radius: 8px; cursor: pointer; transition: background .1s, color .1s;
}
.psel-opt-del:hover { background: #fee2e2; color: var(--error, #dc2626); }
.psel-opt-main { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 1px; }
.psel-opt-label { font-size: 13.5px; font-weight: 600; color: var(--text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.psel-opt-sub { font-size: 11.5px; color: var(--text-muted); text-transform: capitalize; }
.psel-opt-right { font-size: 12.5px; font-weight: 700; color: #0f766e; flex-shrink: 0; }
.psel-opt-check { color: var(--primary); flex-shrink: 0; }
.psel-empty { padding: 14px 12px; text-align: center; font-size: 12.5px; color: var(--text-muted); }

.psel-create {
  display: flex; align-items: center; gap: 9px; width: 100%;
  padding: 11px 12px; border: none; border-top: 1px solid var(--border);
  background: #f8fafc; color: var(--primary); font-size: 13.5px; font-weight: 700;
  cursor: pointer; transition: background .12s;
}
.psel-create:hover { background: #f0fdfa; }
.psel-create-ico { display: inline-flex; align-items: center; justify-content: center; width: 24px; height: 24px; border-radius: 7px; background: #ccfbf1; }
</style>
