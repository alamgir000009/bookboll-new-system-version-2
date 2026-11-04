<script setup>
/**
 * EmptyState.vue — consistent "nothing here yet" panel for list/table pages.
 *
 * Usage:
 *   <EmptyState :icon="Ticket" title="No promocodes yet"
 *     message="Create your first discount code to start a campaign."
 *     action-label="New Promocode" @action="openNew" />
 *
 * Omit action-label to show just the message (e.g. for filtered-empty results).
 */
import { Inbox } from 'lucide-vue-next'

defineProps({
  icon:        { type: [Object, Function], default: null },
  title:       { type: String, default: 'Nothing here yet' },
  message:     { type: String, default: '' },
  actionLabel: { type: String, default: '' },
  compact:     { type: Boolean, default: false },
})
defineEmits(['action'])
</script>

<template>
  <div class="empty-state" :class="{ compact }">
    <div class="empty-icon">
      <component :is="icon || Inbox" :size="compact ? 24 : 30" />
    </div>
    <div class="empty-title">{{ title }}</div>
    <p v-if="message" class="empty-message">{{ message }}</p>
    <button v-if="actionLabel" class="btn btn-primary empty-action" @click="$emit('action')">
      {{ actionLabel }}
    </button>
    <slot />
  </div>
</template>

<style scoped>
.empty-state {
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  text-align: center; padding: 56px 24px; gap: 6px;
}
.empty-state.compact { padding: 32px 20px; }
.empty-icon {
  width: 60px; height: 60px; border-radius: 16px;
  display: flex; align-items: center; justify-content: center;
  background: var(--bg-subtle, #f1f5f9); color: var(--text-light, #94a3b8); margin-bottom: 8px;
}
.empty-state.compact .empty-icon { width: 48px; height: 48px; border-radius: 13px; }
.empty-title { font-size: 16px; font-weight: 700; color: #0f172a; }
.empty-message { font-size: 13.5px; color: var(--text-muted, #64748b); line-height: 1.5; margin: 0; max-width: 360px; }
.empty-action { margin-top: 12px; }
</style>
