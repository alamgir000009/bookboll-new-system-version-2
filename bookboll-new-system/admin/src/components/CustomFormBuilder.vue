<script setup>
/**
 * CustomFormBuilder — admin builder for optional sign-up questions on a program/event.
 * v-model:fields = array of { id, type, label, required, options[], placeholder }.
 */
import { Plus, Trash2, ChevronUp, ChevronDown, GripVertical, HelpCircle } from 'lucide-vue-next'
import { FIELD_TYPES, blankField, asFields, typeHasOptions } from '../lib/customForms.js'

const props = defineProps({ fields: { type: Array, default: () => [] } })
const emit = defineEmits(['update:fields'])

function commit(next) { emit('update:fields', next) }
const list = () => asFields(props.fields)

function addField() { commit([...list(), blankField('short_text')]) }
function removeField(i) { const a = [...list()]; a.splice(i, 1); commit(a) }
function move(i, dir) {
  const a = [...list()]; const j = i + dir
  if (j < 0 || j >= a.length) return
  ;[a[i], a[j]] = [a[j], a[i]]; commit(a)
}
function patch(i, p) { const a = list().map((f, idx) => idx === i ? { ...f, ...p } : f); commit(a) }
function onTypeChange(i, type) {
  const p = { type }
  if (typeHasOptions(type) && !(list()[i].options || []).length) p.options = ['Option 1']
  patch(i, p)
}
function addOption(i) { patch(i, { options: [...(list()[i].options || []), `Option ${(list()[i].options || []).length + 1}`] }) }
function setOption(i, j, val) { const o = [...(list()[i].options || [])]; o[j] = val; patch(i, { options: o }) }
function removeOption(i, j) { const o = [...(list()[i].options || [])]; o.splice(j, 1); patch(i, { options: o }) }
</script>

<template>
  <div class="cfb">
    <div v-if="!list().length" class="cfb-empty">
      <HelpCircle :size="18" />
      <span>No questions yet — sign-up just collects contact &amp; payment. Add a question to ask customers something specific (optional).</span>
    </div>

    <div v-for="(f, i) in list()" :key="f.id" class="cfb-card">
      <div class="cfb-card-head">
        <span class="cfb-grip"><GripVertical :size="15" /></span>
        <span class="cfb-num">Q{{ i + 1 }}</span>
        <select class="cfb-type" :value="f.type" @change="onTypeChange(i, $event.target.value)">
          <option v-for="t in FIELD_TYPES" :key="t.key" :value="t.key">{{ t.label }}</option>
        </select>
        <label class="cfb-req"><input type="checkbox" :checked="f.required" @change="patch(i, { required: $event.target.checked })" /> Required</label>
        <span class="cfb-spacer"></span>
        <button type="button" class="cfb-ic" title="Move up" :disabled="i===0" @click="move(i, -1)"><ChevronUp :size="15" /></button>
        <button type="button" class="cfb-ic" title="Move down" :disabled="i===list().length-1" @click="move(i, 1)"><ChevronDown :size="15" /></button>
        <button type="button" class="cfb-ic danger" title="Delete question" @click="removeField(i)"><Trash2 :size="15" /></button>
      </div>

      <input class="cfb-label" :value="f.label" @input="patch(i, { label: $event.target.value })" placeholder="Question label (e.g. T-shirt size)" maxlength="120" />

      <!-- Options editor for choice types -->
      <div v-if="typeHasOptions(f.type)" class="cfb-opts">
        <div v-for="(o, j) in (f.options || [])" :key="j" class="cfb-opt">
          <span class="cfb-opt-dot"></span>
          <input :value="o" @input="setOption(i, j, $event.target.value)" placeholder="Option" maxlength="80" />
          <button type="button" class="cfb-ic danger" title="Remove option" @click="removeOption(i, j)"><Trash2 :size="13" /></button>
        </div>
        <button type="button" class="cfb-addopt" @click="addOption(i)"><Plus :size="13" /> Add option</button>
      </div>
    </div>

    <button type="button" class="cfb-add" @click="addField"><Plus :size="15" /> Add question</button>
  </div>
</template>

<style scoped>
.cfb { display: flex; flex-direction: column; gap: 10px; }
.cfb-empty { display: flex; align-items: flex-start; gap: 9px; font-size: 12.5px; color: var(--text-muted); background: var(--bg, #f8fafc); border: 1px dashed var(--border); border-radius: 10px; padding: 12px 14px; line-height: 1.45; }
.cfb-card { border: 1px solid var(--border); border-radius: 12px; padding: 12px 13px; background: var(--surface, #ffffff); }
.cfb-card-head { display: flex; align-items: center; gap: 8px; margin-bottom: 9px; }
.cfb-grip { color: var(--text-light, #cbd5e1); cursor: grab; }
.cfb-num { font-size: 12px; font-weight: 800; color: var(--primary-ink, #0f766e); background: var(--primary-soft, #f0fdfa); border-radius: 6px; padding: 2px 7px; }
.cfb-type { height: 32px; border: 1px solid var(--border); border-radius: 8px; font-size: 13px; padding: 0 8px; background: var(--surface, #ffffff); color: var(--text); }
.cfb-req { display: inline-flex; align-items: center; gap: 5px; font-size: 12.5px; color: var(--text-muted); font-weight: 600; cursor: pointer; white-space: nowrap; }
.cfb-spacer { flex: 1; }
.cfb-ic { width: 30px; height: 30px; border: 1px solid var(--border); background: var(--surface, #ffffff); border-radius: 7px; color: var(--text-muted); cursor: pointer; display: inline-flex; align-items: center; justify-content: center; }
.cfb-ic:hover:not(:disabled) { border-color: var(--brand-accent, #14b8a6); color: var(--text); }
.cfb-ic:disabled { opacity: .4; cursor: not-allowed; }
.cfb-ic.danger:hover { border-color: var(--danger-border, #fecaca); color: var(--danger-ink, #dc2626); background: var(--danger-bg, #fef2f2); }
.cfb-label { width: 100%; height: 38px; border: 1px solid var(--border); border-radius: 8px; padding: 0 11px; font-size: 14px; font-weight: 600; color: var(--text); box-sizing: border-box; }
.cfb-label:focus { outline: none; border-color: var(--brand-accent, #14b8a6); }
.cfb-opts { margin-top: 9px; display: flex; flex-direction: column; gap: 6px; padding-left: 4px; }
.cfb-opt { display: flex; align-items: center; gap: 8px; }
.cfb-opt-dot { width: 10px; height: 10px; border: 2px solid var(--border-strong, #cbd5e1); border-radius: 50%; flex-shrink: 0; }
.cfb-opt input { flex: 1; height: 32px; border: 1px solid var(--border); border-radius: 7px; padding: 0 10px; font-size: 13px; color: var(--text); }
.cfb-opt input:focus { outline: none; border-color: var(--brand-accent, #14b8a6); }
.cfb-addopt { align-self: flex-start; display: inline-flex; align-items: center; gap: 5px; font-size: 12.5px; font-weight: 700; color: var(--primary-ink, #0f766e); background: none; border: none; cursor: pointer; padding: 2px 4px; }
.cfb-add { display: inline-flex; align-items: center; justify-content: center; gap: 7px; height: 40px; border: 1.5px dashed var(--border); border-radius: 10px; background: var(--bg, #f8fafc); color: var(--text); font-size: 13.5px; font-weight: 700; cursor: pointer; }
.cfb-add:hover { border-color: var(--brand-accent, #14b8a6); color: var(--primary-ink, #0f766e); }
</style>
