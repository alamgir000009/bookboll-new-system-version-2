<script setup>
import { ref } from 'vue'
import { ChevronLeft, ChevronRight, ArrowRight, Info } from 'lucide-vue-next'

defineProps({
  title:   { type: String, required: true },
  subtitle:{ type: String, default: '' },
  viewAll: { type: String, default: '' },   // route path for "View all"
  icon:    { type: [Object, Function], default: null },   // optional Lucide icon component
})
const track = ref(null)
function scrollBy(dir) {
  if (!track.value) return
  // In RTL the scroll axis is reversed, so flip the sign so "next" always moves to the
  // next card visually regardless of direction.
  const rtl = getComputedStyle(track.value).direction === 'rtl'
  const amount = Math.round(track.value.clientWidth * 0.8) * (rtl ? -1 : 1)
  track.value.scrollBy({ left: dir * amount, behavior: 'smooth' })
}
</script>

<template>
  <section class="sr">
    <div class="sr-head">
      <div>
        <h2 class="sr-title">
          <component :is="icon" v-if="icon" :size="20" class="sr-title-ico" />{{ title }}
          <span v-if="subtitle" class="sr-info" tabindex="0" :aria-label="subtitle">
            <Info :size="15" />
            <span class="sr-tip">{{ subtitle }}</span>
          </span>
        </h2>
      </div>
      <div class="sr-head-right">
        <router-link v-if="viewAll" :to="viewAll" class="sr-viewall">View all <ArrowRight :size="15" /></router-link>
        <div class="sr-arrows">
          <button class="sr-arrow" type="button" aria-label="Previous" @click="scrollBy(-1)"><ChevronLeft :size="18" /></button>
          <button class="sr-arrow" type="button" aria-label="Next" @click="scrollBy(1)"><ChevronRight :size="18" /></button>
        </div>
      </div>
    </div>
    <div ref="track" class="sr-track">
      <slot />
    </div>
  </section>
</template>

<style scoped>
.sr { margin-bottom: 30px; }
.sr-head { display: flex; align-items: flex-end; justify-content: space-between; gap: 12px; margin-bottom: 14px; }
.sr-title { font-size: 21px; font-weight: 800; color: #0f172a; margin: 0; display: flex; align-items: center; gap: 9px; }
.sr-title-ico { color: var(--brand); flex-shrink: 0; }
/* subtitle is now an info tooltip next to the title */
.sr-info { position: relative; display: inline-flex; align-items: center; color: var(--text-light, #94a3b8); cursor: help; }
.sr-info:hover, .sr-info:focus-visible { color: var(--brand); outline: none; }
/* display:none while idle so the (invisible) bubble never adds to page width / horizontal
   scroll on narrow screens. Width is clamped to the viewport so it stays on-screen when shown. */
.sr-tip { position: absolute; top: calc(100% + 9px); left: 50%; transform: translateX(-50%); z-index: 30; width: max-content; max-width: min(240px, calc(100vw - 28px)); background: #0f172a; color: #fff; font-size: 12.5px; font-weight: 500; line-height: 1.45; text-align: left; padding: 8px 11px; border-radius: 9px; box-shadow: 0 10px 26px rgba(15,23,42,.28); pointer-events: none; display: none; }
.sr-tip::before { content: ''; position: absolute; bottom: 100%; left: 50%; transform: translateX(-50%); border: 5px solid transparent; border-bottom-color: #0f172a; }
.sr-info:hover .sr-tip, .sr-info:focus-visible .sr-tip { display: block; }
.sr-head-right { display: flex; align-items: center; gap: 12px; flex-shrink: 0; }
.sr-viewall { display: flex; align-items: center; gap: 5px; font-size: 14px; font-weight: 700; color: var(--brand); text-decoration: none; white-space: nowrap; }
.sr-viewall:hover { text-decoration: underline; }
.sr-arrows { display: flex; gap: 6px; }
.sr-arrow { width: 34px; height: 34px; border-radius: 50%; border: 1.5px solid var(--border, #e2e8f0); background: var(--surface, #ffffff); color: #475569; display: flex; align-items: center; justify-content: center; cursor: pointer; }
.sr-arrow:hover { border-color: var(--brand-accent); color: var(--brand); }
/* RTL: flip the chevron glyphs so ‹ / › point the correct way for right-to-left scrolling. */
[dir="rtl"] .sr-arrow svg { transform: scaleX(-1); }

.sr-track { display: flex; gap: 14px; overflow-x: auto; padding: 4px 2px 10px; scroll-snap-type: x mandatory; scrollbar-width: none; }
/* Event cards opt out of stretching on grid pages (align-self:start); in this
   slider they MUST fill the track height, or one taller card in the row leaves
   a hollow strip under all the shorter ones (reads as uneven section spacing). */
.sr-track > :deep(.gc) { align-self: stretch; }
.sr-track::-webkit-scrollbar { display: none; }
.sr-track > * { scroll-snap-align: start; flex: 0 0 auto; }

/* ---- Mobile (phone) tightening — presentation only, matches storefront's 760px convention ---- */
@media (max-width: 760px) {
  /* #4: hide arrows across the whole phone breakpoint (was 640px, causing a 641–760px seam) */
  .sr-arrows { display: none; }
  /* Section rhythm: the home page (its only consumer) zeroes this and spaces
     rows with a flex gap instead; this stays as the standalone fallback. */
  .sr { margin-bottom: 26px; }
  /* #5: top-align header so a wrapped multi-line title lines up with the View-all link */
  .sr-head { margin-bottom: 10px; align-items: flex-start; gap: 10px; }
  /* 360px: the plain wrapper div around .sr-title is the actual flex child of .sr-head, and
     its default min-width:auto ignores the title's own min-width:0 — so a long unbroken title
     could still push the row past the viewport. Let the wrapper shrink and allow the title
     text to break anywhere as a last resort. */
  .sr-head > div:first-child { min-width: 0; }
  .sr-title { overflow-wrap: anywhere; }
  /* #3 + #5: smaller, still-clear heading; min-width:0 lets it wrap/shrink instead of crowding.
     Pass 2: 17px keeps section headings inside the 16-17px band so stacked rows don't compete
     with the page h1; position:relative anchors the tooltip bubble to the heading (see below). */
  .sr-title { font-size: 17px; gap: 7px; min-width: 0; position: relative; }
  /* Pass 2: shrink the brand icon to match the 17px heading so the words lead, not the accent
     (CSS width/height override the SVG's :size="20" attributes — no prop change). */
  .sr-title-ico { width: 17px; height: 17px; }
  /* #1: subtitle tooltip must open on plain :focus — :focus-visible often doesn't fire on tap */
  .sr-info:focus .sr-tip { display: block; }
  /* Pass 2: re-anchor the tooltip bubble to the heading, not the Info icon. Centering on the
     icon could push up to half the bubble off-screen when a wrapped title lands the icon near
     the right edge of a 375px viewport. Spanning the title width can never clip; the caret is
     hidden since it no longer points at the icon. */
  .sr-info { position: static; }
  .sr-tip { left: 0; right: 0; width: auto; max-width: none; transform: none; }
  .sr-tip::before { display: none; }
  /* #6: trim track padding while preserving the scroll-snap peek that hints the row scrolls */
  .sr-track { gap: 12px; padding: 2px 2px 8px; }
}
</style>
