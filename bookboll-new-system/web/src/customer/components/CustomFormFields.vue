<script setup>
/**
 * CustomFormFields — renders a custom sign-up form for the customer to answer.
 * Props: fields (the questions), modelValue (answers map), errors ({id:msg}).
 * v-model:modelValue holds the answers.
 */
import { asFields, typeIsMulti } from '../../lib/customForms.js'

const props = defineProps({
  fields: { type: Array, default: () => [] },
  modelValue: { type: Object, default: () => ({}) },
  errors: { type: Object, default: () => ({}) },
})
const emit = defineEmits(['update:modelValue'])

const list = () => asFields(props.fields)
function set(id, val) { emit('update:modelValue', { ...props.modelValue, [id]: val }) }
function toggleMulti(id, opt, checked) {
  const cur = Array.isArray(props.modelValue[id]) ? props.modelValue[id] : []
  set(id, checked ? [...cur, opt] : cur.filter(x => x !== opt))
}
const isChecked = (id, opt) => Array.isArray(props.modelValue[id]) && props.modelValue[id].includes(opt)
</script>

<template>
  <div class="cff">
    <div v-for="f in list()" :key="f.id" class="cff-field" :class="{ err: errors[f.id] }">
      <label class="cff-label">{{ f.label }}<span v-if="f.required" class="cff-req">*</span></label>

      <!-- text-like -->
      <input v-if="f.type==='short_text'" class="cff-input" :value="modelValue[f.id] || ''" @input="set(f.id, $event.target.value)" :placeholder="f.placeholder || ''" />
      <textarea v-else-if="f.type==='long_text'" class="cff-input cff-area" :value="modelValue[f.id] || ''" @input="set(f.id, $event.target.value)" :placeholder="f.placeholder || ''" rows="3"></textarea>
      <input v-else-if="f.type==='number'" type="number" class="cff-input" :value="modelValue[f.id] ?? ''" @input="set(f.id, $event.target.value)" :placeholder="f.placeholder || ''" />
      <input v-else-if="f.type==='email'" type="email" class="cff-input" :value="modelValue[f.id] || ''" @input="set(f.id, $event.target.value)" :placeholder="f.placeholder || 'you@example.com'" />
      <input v-else-if="f.type==='phone'" type="tel" class="cff-input" :value="modelValue[f.id] || ''" @input="set(f.id, $event.target.value)" :placeholder="f.placeholder || ''" />
      <input v-else-if="f.type==='date'" type="date" class="cff-input" :value="modelValue[f.id] || ''" @input="set(f.id, $event.target.value)" />

      <!-- dropdown -->
      <select v-else-if="f.type==='dropdown'" class="cff-input" :value="modelValue[f.id] || ''" @change="set(f.id, $event.target.value)">
        <option value="">Select…</option>
        <option v-for="(o, j) in (f.options || [])" :key="j" :value="o">{{ o }}</option>
      </select>

      <!-- yes/no -->
      <div v-else-if="f.type==='yes_no'" class="cff-chips">
        <button type="button" class="cff-chip" :class="{ on: modelValue[f.id]==='Yes' }" @click="set(f.id, 'Yes')">Yes</button>
        <button type="button" class="cff-chip" :class="{ on: modelValue[f.id]==='No' }" @click="set(f.id, 'No')">No</button>
      </div>

      <!-- single choice (radio) -->
      <div v-else-if="f.type==='single_select'" class="cff-opts">
        <label v-for="(o, j) in (f.options || [])" :key="j" class="cff-opt">
          <input type="radio" :name="'r'+f.id" :checked="modelValue[f.id]===o" @change="set(f.id, o)" /><span>{{ o }}</span>
        </label>
      </div>

      <!-- multiple choice (checkboxes) -->
      <div v-else-if="f.type==='multi_select'" class="cff-opts">
        <label v-for="(o, j) in (f.options || [])" :key="j" class="cff-opt">
          <input type="checkbox" :checked="isChecked(f.id, o)" @change="toggleMulti(f.id, o, $event.target.checked)" /><span>{{ o }}</span>
        </label>
      </div>

      <div v-if="errors[f.id]" class="cff-err">{{ errors[f.id] }}</div>
    </div>
  </div>
</template>

<style scoped>
.cff { display: flex; flex-direction: column; gap: 14px; }
.cff-label { display: block; font-size: 13.5px; font-weight: 700; color: var(--text, #0f172a); margin-bottom: 6px; }
.cff-req { color: var(--danger-ink, #dc2626); margin-left: 3px; }
.cff-input { width: 100%; min-height: 42px; border: 1.5px solid var(--border, #e2e8f0); border-radius: 10px; padding: 10px 12px; font-size: 14px; color: var(--text, #0f172a); background: var(--surface, #ffffff); box-sizing: border-box; }
.cff-input:focus { outline: none; border-color: var(--brand, #0f766e); }
.cff-area { resize: vertical; }
.cff-field.err .cff-input { border-color: #dc2626; }
.cff-chips, .cff-opts { display: flex; flex-wrap: wrap; gap: 8px; }
.cff-opts { flex-direction: column; gap: 7px; }
.cff-chip { padding: 9px 18px; border: 1.5px solid var(--border, #e2e8f0); border-radius: 999px; background: var(--surface, #ffffff); font-size: 14px; font-weight: 700; color: var(--text-secondary, #475569); cursor: pointer; }
.cff-chip.on { border-color: var(--brand, #0f766e); background: color-mix(in srgb, var(--brand, #0f766e) 10%, var(--surface, white)); color: var(--brand, #0f766e); }
.cff-opt { display: flex; align-items: center; gap: 9px; font-size: 14px; color: var(--text, #0f172a); cursor: pointer; }
.cff-opt input { width: 17px; height: 17px; accent-color: var(--brand, #0f766e); }
.cff-err { font-size: 12px; color: var(--danger-ink, #dc2626); margin-top: 5px; font-weight: 600; }

/* Phone (customer-shell breakpoint) */
@media (max-width: 760px) {
  .cff-input { font-size: 16px; min-height: 44px; }
  .cff-chip { min-height: 44px; padding: 10px 18px; }
  .cff-label, .cff-opt span, .cff-err { overflow-wrap: anywhere; }
  .cff-opt { align-items: flex-start; }
  .cff-opt input { flex: 0 0 auto; margin-top: 2px; }
}
</style>
