<script setup>
/**
 * Lightbox — full-screen photo viewer for storefront galleries.
 * Open with :images (array of URLs) + :start; emits 'close'.
 * Swipe or arrow between photos, double-tap / double-click to zoom 2x,
 * Esc / ✕ / backdrop tap to close. Teleports to <body> so it escapes
 * any transformed/overflow-clipped ancestor.
 */
import { ref, watch, onMounted, onBeforeUnmount, computed } from 'vue'
import { X, ChevronLeft, ChevronRight } from 'lucide-vue-next'

const props = defineProps({
  images: { type: Array, required: true },
  start: { type: Number, default: 0 },
  alt: { type: String, default: 'Photo' },
})
const emit = defineEmits(['close'])

const idx = ref(Math.min(Math.max(props.start, 0), props.images.length - 1))
watch(() => props.start, (v) => { idx.value = Math.min(Math.max(v, 0), props.images.length - 1) })
const count = computed(() => props.images.length)

const zoomed = ref(false)
function go(i) {
  if (!count.value) return
  zoomed.value = false
  idx.value = (i + count.value) % count.value
}

// swipe to navigate (ignore while zoomed so panning doesn't flip photos)
let touchX = null
function onTouchStart(e) { touchX = e.changedTouches[0].clientX }
function onTouchEnd(e) {
  if (touchX == null || zoomed.value || count.value < 2) { touchX = null; return }
  const dx = e.changedTouches[0].clientX - touchX
  touchX = null
  if (Math.abs(dx) > 40) go(idx.value + (dx < 0 ? 1 : -1))
}

let lastTap = 0
function onImgTap() {
  const now = Date.now()
  if (now - lastTap < 320) zoomed.value = !zoomed.value
  lastTap = now
}

function onKey(e) {
  if (e.key === 'Escape') emit('close')
  else if (e.key === 'ArrowRight') go(idx.value + 1)
  else if (e.key === 'ArrowLeft') go(idx.value - 1)
}
onMounted(() => {
  window.addEventListener('keydown', onKey)
  document.documentElement.style.overflow = 'hidden'   // freeze page scroll behind the viewer
})
onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKey)
  document.documentElement.style.overflow = ''
})
</script>

<template>
  <Teleport to="body">
    <div class="lbx" role="dialog" aria-modal="true" :aria-label="alt" @click.self="emit('close')">
      <button type="button" class="lbx-x" aria-label="Close photo viewer" @click="emit('close')"><X :size="22" /></button>
      <div v-if="count > 1" class="lbx-count">{{ idx + 1 }} / {{ count }}</div>

      <div class="lbx-stage" @touchstart.passive="onTouchStart" @touchend="onTouchEnd" @click.self="emit('close')">
        <img
          :src="images[idx]" :alt="`${alt} ${idx + 1} of ${count}`"
          class="lbx-img" :class="{ zoomed }"
          draggable="false"
          @click="onImgTap" @dblclick.prevent="zoomed = !zoomed"
        />
      </div>

      <button v-if="count > 1" type="button" class="lbx-nav lbx-prev" aria-label="Previous photo" @click="go(idx - 1)"><ChevronLeft :size="26" /></button>
      <button v-if="count > 1" type="button" class="lbx-nav lbx-next" aria-label="Next photo" @click="go(idx + 1)"><ChevronRight :size="26" /></button>

      <div v-if="count > 1" class="lbx-dots">
        <button v-for="(im, i) in images" :key="i" type="button" class="lbx-dot" :class="{ on: i === idx }" :aria-label="`Photo ${i + 1}`" @click="go(i)"></button>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.lbx { position: fixed; inset: 0; z-index: 400; background: rgba(8, 12, 20, .96); display: flex; align-items: center; justify-content: center; animation: lbxIn .18s ease; }
@keyframes lbxIn { from { opacity: 0; } to { opacity: 1; } }

.lbx-stage { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; overflow: hidden; }
.lbx-img { max-width: 100vw; max-height: 100dvh; object-fit: contain; user-select: none; -webkit-user-drag: none; transition: transform .22s ease; cursor: zoom-in; }
.lbx-img.zoomed { transform: scale(2); cursor: zoom-out; }

.lbx-x { position: absolute; top: max(14px, env(safe-area-inset-top)); inset-inline-end: 14px; z-index: 2; width: 42px; height: 42px; border-radius: 50%; border: none; background: rgba(255,255,255,.14); color: #fff; display: flex; align-items: center; justify-content: center; cursor: pointer; }
.lbx-x:hover { background: rgba(255,255,255,.25); }
.lbx-count { position: absolute; top: max(22px, env(safe-area-inset-top)); left: 50%; transform: translateX(-50%); z-index: 2; color: rgba(255,255,255,.85); font-size: 13.5px; font-weight: 700; font-variant-numeric: tabular-nums; }

.lbx-nav { position: absolute; top: 50%; transform: translateY(-50%); z-index: 2; width: 44px; height: 44px; border-radius: 50%; border: none; background: rgba(255,255,255,.14); color: #fff; display: flex; align-items: center; justify-content: center; cursor: pointer; }
.lbx-nav:hover { background: rgba(255,255,255,.25); }
.lbx-prev { inset-inline-start: 10px; }
.lbx-next { inset-inline-end: 10px; }
/* phones navigate by swipe — hide the arrow chrome */
@media (max-width: 760px) { .lbx-nav { display: none; } }

.lbx-dots { position: absolute; bottom: max(18px, env(safe-area-inset-bottom)); left: 50%; transform: translateX(-50%); z-index: 2; display: flex; gap: 8px; }
.lbx-dot { position: relative; width: 8px; height: 8px; border-radius: 999px; border: none; padding: 0; background: rgba(255,255,255,.4); cursor: pointer; transition: width .15s, background .15s; }
.lbx-dot.on { width: 20px; background: var(--surface, #ffffff); }
.lbx-dot::before { content: ''; position: absolute; inset: -10px; }
</style>
