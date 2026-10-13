<template>
  <div v-if="total > 0" class="pagination-bar">
    <div class="pagination-info">
      {{ rangeStart }}–{{ rangeEnd }} of {{ total }}
    </div>
    <div class="pagination-controls">
      <select class="per-page-select" :value="perPage" @change="$emit('update:perPage', Number($event.target.value))">
        <option v-for="n in perPageOpts" :key="n" :value="n">{{ n }} / page</option>
      </select>
      <button class="pg-btn" :disabled="page <= 1" @click="$emit('goTo', 1)">«</button>
      <button class="pg-btn" :disabled="page <= 1" @click="$emit('goTo', page - 1)">‹</button>
      <template v-for="(p, i) in visiblePages" :key="p">
        <span v-if="i > 0 && p - visiblePages[i - 1] > 1" class="pg-ellipsis">…</span>
        <button class="pg-btn" :class="{ active: p === page }" @click="$emit('goTo', p)">{{ p }}</button>
      </template>
      <button class="pg-btn" :disabled="page >= totalPages" @click="$emit('goTo', page + 1)">›</button>
      <button class="pg-btn" :disabled="page >= totalPages" @click="$emit('goTo', totalPages)">»</button>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { PER_PAGE_OPTS } from '../lib/usePagination.js'

const props = defineProps({
  page: { type: Number, required: true },
  perPage: { type: Number, required: true },
  totalPages: { type: Number, required: true },
  total: { type: Number, required: true },
  visiblePages: { type: Array, required: true },
})
defineEmits(['update:perPage', 'goTo'])

const perPageOpts = PER_PAGE_OPTS
const rangeStart = computed(() => props.total === 0 ? 0 : (props.page - 1) * props.perPage + 1)
const rangeEnd = computed(() => Math.min(props.page * props.perPage, props.total))
</script>

<style scoped>
.pagination-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 12px 0 4px;
  gap: 12px;
  flex-wrap: wrap;
}
.pagination-info {
  font-size: 13px;
  color: var(--text-muted, #6b7280);
}
.pagination-controls {
  display: flex;
  align-items: center;
  gap: 4px;
}
.per-page-select {
  height: 30px;
  padding: 0 8px;
  border: 1px solid var(--border, #e5e7eb);
  border-radius: 6px;
  font-size: 12px;
  background: var(--bg, #fff);
  color: var(--text, #111827);
  cursor: pointer;
  margin-right: 6px;
}
.pg-btn {
  min-width: 30px;
  height: 30px;
  padding: 0 6px;
  border: 1px solid var(--border, #e5e7eb);
  border-radius: 6px;
  background: var(--bg, #fff);
  color: var(--text, #111827);
  font-size: 13px;
  cursor: pointer;
  transition: background 0.15s, border-color 0.15s;
}
.pg-btn:hover:not(:disabled):not(.active) {
  background: var(--bg-hover, #f3f4f6);
}
.pg-btn.active {
  background: #0f172a;
  color: #fff;
  border-color: #0f172a;
  font-weight: 600;
}
.pg-btn:disabled {
  opacity: 0.35;
  cursor: default;
}
.pg-ellipsis {
  padding: 0 4px;
  font-size: 13px;
  color: var(--text-muted, #6b7280);
}

/* On phones, let the controls wrap instead of overflowing off-screen. */
@media (max-width: 600px) {
  .pagination-bar { justify-content: center; }
  .pagination-controls { flex-wrap: wrap; justify-content: center; }
  .per-page-select { margin-right: 0; }
}
</style>
