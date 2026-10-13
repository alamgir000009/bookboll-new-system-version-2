<script setup>
import { computed } from 'vue'
import { confirmState, resolveConfirm } from '../lib/confirm.js'
import { TriangleAlert, HelpCircle } from 'lucide-vue-next'

const open = computed(() => !!confirmState.value)
</script>

<template>
  <Teleport to="body">
    <Transition name="cd-fade">
      <div v-if="open" class="cd-backdrop" @click.self="resolveConfirm(false)">
        <div class="cd-panel" role="alertdialog" aria-modal="true">
          <div class="cd-icon" :class="confirmState.danger ? 'cd-icon-danger' : 'cd-icon-neutral'">
            <TriangleAlert v-if="confirmState.danger" :size="22" />
            <HelpCircle v-else :size="22" />
          </div>
          <div class="cd-title">{{ confirmState.title }}</div>
          <div v-if="confirmState.message" class="cd-message">{{ confirmState.message }}</div>
          <!-- With an extra (third) choice the buttons stack vertically: primary
               extra on top, then confirm, cancel last (CSS order handles it) —
               three side-by-side don't fit. Two-button dialogs are unchanged. -->
          <div class="cd-actions" :class="{ 'cd-actions-stack': confirmState.extraText }">
            <button v-if="confirmState.extraText" class="cd-btn cd-confirm cd-extra" @click="resolveConfirm('extra')" autofocus>
              {{ confirmState.extraText }}
            </button>
            <button class="cd-btn cd-cancel" @click="resolveConfirm(false)">{{ confirmState.cancelText }}</button>
            <button
              class="cd-btn"
              :class="confirmState.danger ? 'cd-confirm-danger' : 'cd-confirm'"
              @click="resolveConfirm(true)"
              :autofocus="!confirmState.extraText"
            >{{ confirmState.confirmText }}</button>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.cd-backdrop {
  position: fixed; inset: 0;
  background: rgba(15, 23, 42, 0.5);
  display: flex; align-items: center; justify-content: center;
  z-index: 4500; padding: 20px;
}
.cd-panel {
  background: var(--surface, #ffffff);
  border-radius: 16px;
  padding: 26px 24px 20px;
  width: 100%; max-width: 420px;
  box-shadow: 0 24px 60px rgba(15, 23, 42, 0.28);
  text-align: center;
}
.cd-icon {
  width: 52px; height: 52px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  margin: 0 auto 14px;
}
.cd-icon-danger  { background: #fef2f2; color: #dc2626; }
.cd-icon-neutral { background: #ecfeff; color: #0891b2; }
.cd-title { font-size: 17px; font-weight: 700; color: #0f172a; margin-bottom: 6px; }
.cd-message { font-size: 13.5px; color: var(--text-muted, #64748b); line-height: 1.5; margin-bottom: 22px; }
.cd-actions { display: flex; gap: 10px; }
/* Three-choice variant: stacked, extra (primary) → confirm → cancel */
.cd-actions-stack { flex-direction: column; }
.cd-actions-stack .cd-extra { order: 1; }
.cd-actions-stack .cd-confirm-danger, .cd-actions-stack .cd-confirm:not(.cd-extra) { order: 2; }
.cd-actions-stack .cd-cancel { order: 3; }
.cd-btn {
  flex: 1; padding: 10px 16px; border-radius: 9px;
  font-size: 14px; font-weight: 600; cursor: pointer; border: 1px solid transparent;
  transition: all 0.15s;
}
.cd-cancel { background: var(--surface, #ffffff); border-color: var(--border, #e2e8f0); color: #334155; }
.cd-cancel:hover { background: #f8fafc; }
.cd-confirm { background: #0f766e; color: #fff; }
.cd-confirm:hover { background: #0d635c; }
.cd-confirm-danger { background: #dc2626; color: #fff; }
.cd-confirm-danger:hover { background: #b91c1c; }

.cd-fade-enter-active, .cd-fade-leave-active { transition: opacity 0.18s ease; }
.cd-fade-enter-from, .cd-fade-leave-to { opacity: 0; }
.cd-fade-enter-active .cd-panel { animation: cd-pop 0.2s ease; }
@keyframes cd-pop { from { transform: scale(0.94); opacity: 0; } to { transform: scale(1); opacity: 1; } }
</style>
