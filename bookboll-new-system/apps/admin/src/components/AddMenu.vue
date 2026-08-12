<script setup>
/**
 * AddMenu — a single "Add" button that opens a polished dropdown of create options
 * (icon + title + short description per item). Replaces rows of competing buttons,
 * which is especially valuable on mobile. Reusable anywhere a "+ New X / + New Y"
 * cluster would otherwise crowd a header.
 *
 *   <AddMenu :items="[{ key, label, desc, icon }]" @select="onSelect" />
 */
import { ref, onMounted, onUnmounted } from 'vue'
import { Plus, ChevronDown } from 'lucide-vue-next'

const props = defineProps({
  label:   { type: String, default: 'Add' },
  items:   { type: Array,  default: () => [] },   // [{ key, label, desc, icon }]
  variant: { type: String, default: 'secondary' },// 'secondary' | 'primary'
  size:    { type: String, default: '' },          // '' | 'sm'
  align:   { type: String, default: 'left' },      // 'left' | 'right'
})
const emit = defineEmits(['select'])

const open = ref(false)
const root = ref(null)
function toggle() { open.value = !open.value }
function pick(key) { open.value = false; emit('select', key) }
function onDoc(e) { if (open.value && root.value && !root.value.contains(e.target)) open.value = false }
function onKey(e) { if (e.key === 'Escape') open.value = false }
onMounted(() => { document.addEventListener('click', onDoc, true); document.addEventListener('keydown', onKey) })
onUnmounted(() => { document.removeEventListener('click', onDoc, true); document.removeEventListener('keydown', onKey) })
</script>

<template>
  <div class="am" ref="root">
    <button
      type="button"
      class="am-trigger btn"
      :class="[variant === 'primary' ? 'btn-primary' : 'btn-secondary', { 'btn-sm': size === 'sm', on: open }]"
      @click.stop="toggle"
    >
      <Plus :size="size === 'sm' ? 14 : 16" />
      <span>{{ label }}</span>
      <ChevronDown :size="14" class="am-caret" :class="{ rot: open }" />
    </button>

    <!-- Phone: dim the page behind the sheet-style menu (hidden on desktop). -->
    <div v-if="open" class="am-backdrop" @click.stop="open = false"></div>
    <div v-if="open" class="am-menu" :class="align">
      <button v-for="it in items" :key="it.key" type="button" class="am-item" @click="pick(it.key)">
        <span class="am-ico"><component :is="it.icon" :size="17" /></span>
        <span class="am-text">
          <span class="am-label">{{ it.label }}</span>
          <span v-if="it.desc" class="am-desc">{{ it.desc }}</span>
        </span>
      </button>
    </div>
  </div>
</template>

<style scoped>
.am { position: relative; display: inline-block; }
.am-trigger { gap: 6px; }
.am-trigger.on.btn-secondary { border-color: var(--primary); color: var(--primary); }
.am-caret { opacity: .7; transition: transform .15s; }
.am-caret.rot { transform: rotate(180deg); }

.am-menu {
  position: absolute; top: calc(100% + 6px); z-index: 60; min-width: 250px;
  background: var(--surface, #ffffff); border: 1px solid var(--border); border-radius: 14px;
  box-shadow: var(--shadow-lg); padding: 6px; animation: amIn .13s ease;
}
.am-menu.left { left: 0; }
.am-menu.right { right: 0; }
@keyframes amIn { from { opacity: 0; transform: translateY(-4px); } to { opacity: 1; transform: none; } }

.am-item {
  display: flex; align-items: flex-start; gap: 12px; width: 100%;
  padding: 11px 12px; border-radius: 10px; text-align: left; cursor: pointer;
  background: none; border: none; transition: background .12s;
}
.am-item:hover { background: var(--bg); }
.am-ico {
  width: 36px; height: 36px; border-radius: 9px; flex-shrink: 0;
  background: #ecfdf5; color: var(--primary);
  display: flex; align-items: center; justify-content: center;
}
.am-text { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.am-label { font-size: 14px; font-weight: 700; color: var(--text); }
.am-desc { font-size: 12px; color: var(--text-muted); line-height: 1.4; }

.am-backdrop { display: none; }

/* Phones: an absolute dropdown gets crushed by whatever container it happens
   to sit in (venue headers, page toolbars) and bleeds over the content behind
   it. Present it as a fixed bottom sheet with a dimmed backdrop instead. */
@media (max-width: 600px) {
  .am, .am-trigger { width: 100%; }
  .am-trigger { justify-content: center; }
  .am-backdrop { display: block; position: fixed; inset: 0; z-index: 4990; background: rgba(15, 23, 42, .45); animation: amFade .15s ease; }
  .am-menu {
    position: fixed; left: 10px; right: 10px; bottom: 10px; top: auto;
    min-width: 0; z-index: 5000; max-height: 70dvh; overflow-y: auto;
    -webkit-overflow-scrolling: touch;
    padding-bottom: calc(6px + env(safe-area-inset-bottom));
    animation: amSheet .2s cubic-bezier(.22, 1, .36, 1);
  }
  /* The .left/.right align classes (left:0 / right:0) outrank the plain
     .am-menu rule above on specificity — re-pin both edges for the sheet. */
  .am-menu.left, .am-menu.right { left: 10px; right: 10px; }
  .am-item { padding: 13px 12px; }
}
@keyframes amFade { from { opacity: 0; } to { opacity: 1; } }
@keyframes amSheet { from { transform: translateY(24px); opacity: .5; } to { transform: none; opacity: 1; } }
</style>
