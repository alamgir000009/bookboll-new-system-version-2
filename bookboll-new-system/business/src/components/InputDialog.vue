<script setup>
import { ref, computed, watch, nextTick } from 'vue'
import { inputState, resolveInput } from '../lib/inputPrompt.js'
import { X } from 'lucide-vue-next'

const open = computed(() => !!inputState.value)
const value = ref('')
const showError = ref(false)
const fieldEl = ref(null)

watch(open, async (v) => {
  if (v) {
    value.value = inputState.value.initial || ''
    showError.value = false
    await nextTick()
    fieldEl.value?.focus()
  }
})

const invalid = computed(() => inputState.value?.required && !value.value.trim())

function submit() {
  if (invalid.value) { showError.value = true; fieldEl.value?.focus(); return }
  resolveInput(value.value.trim())
}
function cancel() { resolveInput(null) }
</script>

<template>
  <Teleport to="body">
    <Transition name="idlg-fade">
      <div v-if="open" class="idlg-backdrop" @click.self="cancel">
        <div class="idlg-panel" role="dialog" aria-modal="true">
          <div class="idlg-header">
            <div class="idlg-title">{{ inputState.title }}</div>
            <button class="idlg-close" @click="cancel"><X :size="18" /></button>
          </div>
          <p v-if="inputState.message" class="idlg-message">{{ inputState.message }}</p>
          <label v-if="inputState.label" class="idlg-label">{{ inputState.label }}</label>
          <textarea
            v-if="inputState.multiline"
            ref="fieldEl"
            v-model="value"
            :class="{ 'input-invalid': showError && invalid }"
            :placeholder="inputState.placeholder"
            rows="3"
            @input="showError = false"
          ></textarea>
          <input
            v-else
            ref="fieldEl"
            v-model="value"
            :class="{ 'input-invalid': showError && invalid }"
            :placeholder="inputState.placeholder"
            @input="showError = false"
            @keyup.enter="submit"
          />
          <div v-if="showError && invalid" class="field-error-text">This field is required.</div>
          <div class="idlg-actions">
            <button class="idlg-btn idlg-cancel" @click="cancel">{{ inputState.cancelText }}</button>
            <button class="idlg-btn idlg-submit" @click="submit">{{ inputState.confirmText }}</button>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.idlg-backdrop {
  position: fixed; inset: 0;
  background: rgba(15, 23, 42, 0.5);
  display: flex; align-items: center; justify-content: center;
  z-index: 4500; padding: 20px;
}
.idlg-panel {
  background: var(--surface, #ffffff); border-radius: 14px; padding: 22px;
  width: 100%; max-width: 440px;
  box-shadow: 0 24px 60px rgba(15, 23, 42, 0.28);
}
.idlg-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; margin-bottom: 10px; }
.idlg-title { font-size: 16px; font-weight: 700; color: #0f172a; }
.idlg-close { color: var(--text-light, #94a3b8); border: none; background: none; cursor: pointer; padding: 2px; border-radius: 6px; }
.idlg-close:hover { color: #0f172a; background: #f1f5f9; }
.idlg-message { font-size: 13px; color: var(--text-muted, #64748b); line-height: 1.5; margin: 0 0 14px; }
.idlg-label { display: block; font-size: 13px; font-weight: 500; color: #334155; margin-bottom: 6px; }
.idlg-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 18px; }
.idlg-btn { padding: 9px 18px; border-radius: 9px; font-size: 14px; font-weight: 600; cursor: pointer; border: 1px solid transparent; }
.idlg-cancel { background: var(--surface, #ffffff); border-color: var(--border, #e2e8f0); color: #334155; }
.idlg-cancel:hover { background: #f8fafc; }
.idlg-submit { background: #0f766e; color: #fff; }
.idlg-submit:hover { background: #0d635c; }

.idlg-fade-enter-active, .idlg-fade-leave-active { transition: opacity 0.18s ease; }
.idlg-fade-enter-from, .idlg-fade-leave-to { opacity: 0; }
</style>
