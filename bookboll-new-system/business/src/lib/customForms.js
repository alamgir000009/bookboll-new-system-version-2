/**
 * customForms.js — shared model for OPTIONAL custom sign-up questions attached to a
 * membership program or an event (hosted game / tournament).
 *
 * A form is just an array of field objects:
 *   { id, type, label, required, options:[], placeholder }
 * Answers are a flat map keyed by field id:
 *   { <fieldId>: <value> }   (value is string | number | string[] for multi-select)
 *
 * No per-field DB tables — the form lives in `*.signup_form` (jsonb) and the answers in
 * `*.form_answers` (jsonb). See migration 20260626000000_custom_signup_forms.sql.
 */

// Every field type a "good form maker" offers. `hasOptions` → needs an options editor;
// `multi` → answer is an array.
export const FIELD_TYPES = [
  { key: 'short_text',   label: 'Short text',      hasOptions: false, multi: false },
  { key: 'long_text',    label: 'Paragraph',       hasOptions: false, multi: false },
  { key: 'number',       label: 'Number',          hasOptions: false, multi: false },
  { key: 'single_select',label: 'Single choice',   hasOptions: true,  multi: false },
  { key: 'multi_select', label: 'Multiple choice', hasOptions: true,  multi: true  },
  { key: 'dropdown',     label: 'Dropdown',        hasOptions: true,  multi: false },
  { key: 'yes_no',       label: 'Yes / No',        hasOptions: false, multi: false },
  { key: 'date',         label: 'Date',            hasOptions: false, multi: false },
  { key: 'email',        label: 'Email',           hasOptions: false, multi: false },
  { key: 'phone',        label: 'Phone',           hasOptions: false, multi: false },
]

export function fieldTypeDef(type) { return FIELD_TYPES.find(t => t.key === type) || FIELD_TYPES[0] }
export function typeHasOptions(type) { return !!fieldTypeDef(type).hasOptions }
export function typeIsMulti(type) { return !!fieldTypeDef(type).multi }

let _seq = 0
function uid() { return `f${Date.now().toString(36)}${(_seq++).toString(36)}` }

/** A fresh blank field of the given type, ready to edit in the builder. */
export function blankField(type = 'short_text') {
  return { id: uid(), type, label: '', required: false, options: typeHasOptions(type) ? ['Option 1'] : [], placeholder: '' }
}

/** Always work with a clean array (jsonb may come back as null/string in edge cases). */
export function asFields(form) {
  if (Array.isArray(form)) return form
  if (typeof form === 'string') { try { const p = JSON.parse(form); return Array.isArray(p) ? p : [] } catch { return [] } }
  return []
}

export function hasForm(form) { return asFields(form).length > 0 }

/** Builder-side validation before saving the form definition. */
export function validateBuilder(form) {
  const fields = asFields(form)
  for (const f of fields) {
    if (!String(f.label || '').trim()) return { ok: false, error: 'Every question needs a label.' }
    if (typeHasOptions(f.type)) {
      const opts = (f.options || []).map(o => String(o || '').trim()).filter(Boolean)
      if (!opts.length) return { ok: false, error: `"${f.label}" needs at least one option.` }
    }
  }
  return { ok: true }
}

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

function isEmpty(v) {
  if (Array.isArray(v)) return v.length === 0
  return v === null || v === undefined || String(v).trim() === ''
}

/** Validate a customer's answers against the form. Returns { ok, errors:{[id]:msg} }. */
export function validateAnswers(form, answers = {}) {
  const fields = asFields(form)
  const errors = {}
  for (const f of fields) {
    const v = answers[f.id]
    if (f.required && isEmpty(v)) { errors[f.id] = 'Required'; continue }
    if (isEmpty(v)) continue
    if (f.type === 'number' && isNaN(Number(v))) errors[f.id] = 'Enter a number'
    else if (f.type === 'email' && !EMAIL_RE.test(String(v).trim())) errors[f.id] = 'Enter a valid email'
  }
  return { ok: Object.keys(errors).length === 0, errors }
}

/** A default-empty answers object for a form (multi-selects start as []). */
export function blankAnswers(form) {
  const out = {}
  for (const f of asFields(form)) out[f.id] = typeIsMulti(f.type) ? [] : ''
  return out
}

/** Human-readable [{label, value}] for displaying a respondent's answers. */
export function summarizeAnswers(form, answers = {}) {
  return asFields(form).map(f => {
    let v = answers[f.id]
    if (Array.isArray(v)) v = v.join(', ')
    else if (f.type === 'yes_no') v = v === true || v === 'yes' || v === 'Yes' ? 'Yes' : (isEmpty(v) ? '' : 'No')
    return { id: f.id, label: f.label || '(question)', value: isEmpty(v) ? '—' : String(v) }
  })
}
