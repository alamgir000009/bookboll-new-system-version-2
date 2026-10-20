<script setup>
import { toasts, dismissToast } from '../lib/toast.js'
import { CircleCheck, CircleX, Info, TriangleAlert, X } from 'lucide-vue-next'

const icons = { success: CircleCheck, error: CircleX, info: Info, warning: TriangleAlert }
</script>

<template>
  <Teleport to="body">
    <div class="toast-host">
      <TransitionGroup name="toast">
        <div v-for="t in toasts" :key="t.id" class="toast" :class="`toast-${t.type}`" @click="dismissToast(t.id)">
          <component :is="icons[t.type] || Info" :size="17" class="toast-icon" />
          <span class="toast-msg">{{ t.message }}</span>
          <button class="toast-close" @click.stop="dismissToast(t.id)" aria-label="Close"><X :size="14" /></button>
        </div>
      </TransitionGroup>
    </div>
  </Teleport>
</template>

<style scoped>
.toast-host {
  position: fixed;
  top: 18px;
  right: 18px;
  /* The toast is the top layer, above every dialog in the app.
   *
   * It sat at 4000, under the upgrade modal's backdrop at 4300, so a checkout
   * error reported by the server was rendered behind the dialog that caused
   * it — the one moment the message matters most. This clears everything the
   * app uses: dialogs (4300-5000), InfoTip (9999) and the MultiSelect
   * dropdown (10050). Nothing should be given a higher value than this. */
  z-index: 100000;
  display: flex;
  flex-direction: column;
  gap: 10px;
  pointer-events: none;
  max-width: min(92vw, 380px);
}
.toast {
  pointer-events: auto;
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 12px 14px;
  border-radius: 10px;
  background: var(--surface, #ffffff);
  border: 1px solid var(--border, #e2e8f0);
  box-shadow: 0 10px 30px rgba(15, 23, 42, 0.14);
  font-size: 13.5px;
  font-weight: 500;
  color: var(--text, #0f172a);
  cursor: pointer;
  border-left-width: 4px;
}
.toast-icon { flex-shrink: 0; }
.toast-msg { flex: 1; line-height: 1.35; }
.toast-close { flex-shrink: 0; color: var(--text-light, #94a3b8); padding: 2px; border: none; background: none; cursor: pointer; border-radius: 5px; }
.toast-close:hover { color: var(--text, #0f172a); background: var(--bg, #f1f5f9); }

.toast-success { border-left-color: #059669; }
.toast-success .toast-icon { color: var(--positive-ink, #059669); }
.toast-error   { border-left-color: #dc2626; }
.toast-error   .toast-icon { color: var(--danger-ink, #dc2626); }
.toast-info    { border-left-color: #0891b2; }
.toast-info    .toast-icon { color: var(--info-ink, #0891b2); }
.toast-warning { border-left-color: #d97706; }
.toast-warning .toast-icon { color: var(--warning-ink, #d97706); }

/* Slide-in from the right, fade-out */
.toast-enter-active { transition: all 0.28s cubic-bezier(0.21, 1.02, 0.73, 1); }
.toast-leave-active { transition: all 0.2s ease; }
.toast-enter-from { opacity: 0; transform: translateX(40px); }
.toast-leave-to   { opacity: 0; transform: translateX(40px); }
.toast-move { transition: transform 0.2s ease; }
</style>
