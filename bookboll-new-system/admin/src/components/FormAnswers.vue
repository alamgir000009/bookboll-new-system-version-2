<script setup>
/**
 * FormAnswers — read-only display of a respondent's answers to a custom sign-up form.
 * Props: fields (the questions), answers (the answer map). Renders a label/value list.
 * Used by the provider (Subscribers) and the event host (Game/Tournament detail).
 */
import { computed } from 'vue'
import { summarizeAnswers, hasForm } from '../lib/customForms.js'

const props = defineProps({
  fields: { type: Array, default: () => [] },
  answers: { type: Object, default: () => ({}) },
  title: { type: String, default: 'Sign-up answers' },
})
const rows = computed(() => summarizeAnswers(props.fields, props.answers || {}))
const show = computed(() => hasForm(props.fields) && Object.keys(props.answers || {}).length > 0)
</script>

<template>
  <div v-if="show" class="fa">
    <div class="fa-title">{{ title }}</div>
    <div v-for="r in rows" :key="r.id" class="fa-row">
      <span class="fa-q">{{ r.label }}</span>
      <span class="fa-a">{{ r.value }}</span>
    </div>
  </div>
</template>

<style scoped>
.fa { border: 1px solid var(--border, #e2e8f0); border-radius: 10px; padding: 10px 12px; background: var(--bg, #f8fafc); }
.fa-title { font-size: 11.5px; font-weight: 800; text-transform: uppercase; letter-spacing: .04em; color: var(--text-muted, #64748b); margin-bottom: 7px; }
.fa-row { display: flex; gap: 10px; padding: 4px 0; font-size: 13px; border-top: 1px solid var(--border, #eef2f6); }
.fa-row:first-of-type { border-top: none; }
.fa-q { color: var(--text-muted, #64748b); flex: 0 0 45%; min-width: 0; }
.fa-a { color: var(--text, #0f172a); font-weight: 600; flex: 1; min-width: 0; white-space: pre-wrap; word-break: break-word; }
</style>
