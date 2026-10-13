<script setup>
/**
 * InfoTip.vue — a small "info" icon that shows a nicely styled hover card
 * (replaces the default browser `title` tooltip).
 *
 * The card is teleported to <body> and uses fixed positioning, so it is never
 * clipped by parent containers with `overflow: hidden` (e.g. form section cards).
 * It auto-flips below the icon when there isn't room above it.
 *
 * Usage:  <InfoTip text="Explanation of this field" />
 *         <InfoTip>Rich <strong>slot</strong> content</InfoTip>
 */
import { ref, onBeforeUnmount } from 'vue'
import { Info } from 'lucide-vue-next'

defineProps({
  text:  { type: String, default: '' },
  width: { type: Number, default: 250 },
})

const anchor = ref(null)
const show = ref(false)
const placement = ref('above')   // 'above' | 'below'
const pos = ref({ top: 0, left: 0 })

function place() {
  const el = anchor.value
  if (!el) return
  const r = el.getBoundingClientRect()
  const below = r.top < 130          // not enough room above → flip below
  placement.value = below ? 'below' : 'above'
  pos.value = {
    top:  below ? r.bottom + 10 : r.top - 10,
    left: r.left + r.width / 2,
  }
}
function open() {
  place()
  show.value = true
  window.addEventListener('scroll', close, { capture: true, passive: true })
}
function close() {
  show.value = false
  window.removeEventListener('scroll', close, { capture: true })
}
onBeforeUnmount(close)
</script>

<template>
  <span
    ref="anchor"
    class="infotip"
    tabindex="0"
    @mouseenter="open" @mouseleave="close" @focus="open" @blur="close"
  >
    <Info :size="13" />
    <teleport to="body">
      <transition name="infotip-fade">
        <span
          v-if="show"
          class="infotip-pop"
          :class="placement"
          :style="{ top: pos.top + 'px', left: pos.left + 'px', maxWidth: width + 'px' }"
        >
          <slot>{{ text }}</slot>
        </span>
      </transition>
    </teleport>
  </span>
</template>

<style scoped>
.infotip {
  display: inline-flex; align-items: center; justify-content: center;
  color: var(--text-muted); cursor: help; margin-left: 5px; vertical-align: middle;
  border-radius: 50%;
}
.infotip:hover, .infotip:focus { color: var(--primary); outline: none; }
</style>

<!-- Popup lives at <body>, so its styles must be global (not scoped). -->
<style>
.infotip-pop {
  position: fixed; z-index: 9999;
  width: max-content;
  background: #0f172a; color: #fff;
  font-size: 12px; line-height: 1.55; font-weight: 400; text-align: left;
  padding: 9px 12px; border-radius: 9px;
  box-shadow: 0 10px 30px rgba(15, 23, 42, .3);
  pointer-events: none;
}
.infotip-pop.above { transform: translate(-50%, -100%); }
.infotip-pop.below { transform: translate(-50%, 0); }
.infotip-pop::after {
  content: ''; position: absolute; left: 50%; transform: translateX(-50%);
  border: 6px solid transparent;
}
.infotip-pop.above::after { top: 100%; border-top-color: #0f172a; }
.infotip-pop.below::after { bottom: 100%; border-bottom-color: #0f172a; }
.infotip-pop strong { font-weight: 700; }

.infotip-fade-enter-active, .infotip-fade-leave-active { transition: opacity .14s ease; }
.infotip-fade-enter-from, .infotip-fade-leave-to { opacity: 0; }
</style>
