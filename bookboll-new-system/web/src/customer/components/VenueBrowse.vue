<script setup>
/**
 * VenueBrowse — the venue picker used in the create-event flow. Mirrors the customer
 * Venues page (Facilities.vue) exactly: ONE clean search field with the filter icon
 * INSIDE it, a floating pill-filter panel (Category / Type / Sort) that closes on
 * outside tap, and the same FacilityCard look — but in "select" mode (emits the
 * chosen court instead of navigating).
 */
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import { Search, SlidersHorizontal } from 'lucide-vue-next'
import FacilityCard from './FacilityCard.vue'
import { VENUE_CATEGORIES } from '../../lib/venueCategories.js'

const props = defineProps({
  courts: { type: Array, default: () => [] },   // loadFacilities() shape
  selectedId: { type: String, default: '' },
  currency: { type: String, default: 'AED' },
  cityName: { type: String, default: '' },
})
const emit = defineEmits(['select'])

const q = ref('')

// ── Advanced filters (category / type / sort) — same pattern as Facilities.vue ──
// Options come from what is ACTUALLY in the loaded court list — not the full static
// taxonomy — so the pills never offer a category/type with zero results.
const showFilters = ref(false)
const fCat = ref('all')      // category key or 'all'
const fSub = ref('all')      // subcategory / type or 'all'
const fSort = ref('rec')     // 'rec' | 'price-asc' | 'price-desc'
const availCategories = computed(() => {
  const keys = new Set(props.courts.map(c => c.category || 'sports'))
  return VENUE_CATEGORIES.filter(c => keys.has(c.key))
})
const availTypes = computed(() => {
  if (fCat.value === 'all') return []
  return [...new Set(props.courts.filter(c => (c.category || 'sports') === fCat.value && c.sport).map(c => c.sport))].sort()
})
watch(fCat, () => { fSub.value = 'all' })
// A city/venue-list change can remove the currently-picked options — fall back to All.
watch(availCategories, (cats) => { if (fCat.value !== 'all' && !cats.some(c => c.key === fCat.value)) { fCat.value = 'all' } })
const activeFilters = computed(() => (fCat.value !== 'all' ? 1 : 0) + (fSub.value !== 'all' ? 1 : 0) + (fSort.value !== 'rec' ? 1 : 0))
function clearFilters() { fCat.value = 'all'; fSub.value = 'all'; fSort.value = 'rec' }
// Tapping anywhere outside the search+filter cluster closes the floating panel.
function onDocClick(e) { if (showFilters.value && !e.target.closest('.vb-search-wrap')) showFilters.value = false }
onMounted(() => document.addEventListener('click', onDocClick))
onBeforeUnmount(() => document.removeEventListener('click', onDocClick))

const filtered = computed(() => {
  const s = q.value.trim().toLowerCase()
  let arr = props.courts.filter(c => {
    if (s && !`${c.name || ''} ${c.pinText || ''} ${c.sport || ''}`.toLowerCase().includes(s)) return false
    if (fCat.value !== 'all' && (c.category || 'sports') !== fCat.value) return false
    if (fSub.value !== 'all' && c.sport !== fSub.value) return false
    return true
  })
  if (fSort.value === 'price-asc') arr = [...arr].sort((a, b) => (a.price ?? Infinity) - (b.price ?? Infinity))
  else if (fSort.value === 'price-desc') arr = [...arr].sort((a, b) => (b.price ?? -1) - (a.price ?? -1))
  return arr
})
</script>

<template>
  <div>
    <div class="vb-bar">
      <!-- ONE clean field: search with the filter toggle living INSIDE it -->
      <div class="vb-search-wrap">
        <div class="vb-csearch">
          <Search :size="17" />
          <input v-model="q" placeholder="Search venue or type…" />
          <button type="button" class="vb-ficon" :class="{ on: showFilters || activeFilters }" aria-label="Filters" @click="showFilters = !showFilters">
            <SlidersHorizontal :size="17" /><span v-if="activeFilters" class="vb-filter-n">{{ activeFilters }}</span>
          </button>
        </div>

        <!-- Floating filter panel: pill selections, closes on outside tap -->
        <div v-if="showFilters" class="vb-panel">
          <div class="vb-frow">
            <span class="vb-flabel">Category</span>
            <div class="vb-chips">
              <button type="button" :class="{ on: fCat === 'all' }" @click="fCat = 'all'">All</button>
              <button type="button" v-for="c in availCategories" :key="c.key" :class="{ on: fCat === c.key }" @click="fCat = c.key">{{ c.label }}</button>
            </div>
          </div>
          <div v-if="availTypes.length" class="vb-frow">
            <span class="vb-flabel">Type</span>
            <div class="vb-chips">
              <button type="button" :class="{ on: fSub === 'all' }" @click="fSub = 'all'">All</button>
              <button type="button" v-for="s in availTypes" :key="s" :class="{ on: fSub === s }" @click="fSub = s">{{ s }}</button>
            </div>
          </div>
          <div class="vb-frow">
            <span class="vb-flabel">Sort</span>
            <div class="vb-chips">
              <button type="button" :class="{ on: fSort === 'rec' }" @click="fSort = 'rec'">Recommended</button>
              <button type="button" :class="{ on: fSort === 'price-asc' }" @click="fSort = 'price-asc'">Price ↑</button>
              <button type="button" :class="{ on: fSort === 'price-desc' }" @click="fSort = 'price-desc'">Price ↓</button>
            </div>
          </div>
          <div class="vb-panel-foot">
            <button type="button" class="vb-clear" :disabled="!activeFilters" @click="clearFilters">Clear all</button>
            <button type="button" class="vb-done" @click="showFilters = false">Done</button>
          </div>
        </div>
      </div>
    </div>

    <div v-if="!courts.length" class="vb-msg">No bookable venues in {{ cityName || 'your city' }} yet.</div>
    <div v-else-if="!filtered.length" class="vb-msg">No venues match — try another search or category.</div>
    <div v-else class="vb-grid">
      <FacilityCard v-for="c in filtered" :key="c.id" :facility="{ ...c, currency }" selectable :selected="c.id === selectedId" @select="emit('select', c)" />
    </div>
  </div>
</template>

<style scoped>
.vb-bar { display: flex; align-items: center; gap: 8px; margin-bottom: 14px; flex-wrap: wrap; }
/* One clean field (same as Facilities.vue): search input with the filter icon INSIDE it */
.vb-search-wrap { position: relative; flex: 1; min-width: 200px; max-width: 480px; }
.vb-csearch { display: flex; align-items: center; gap: 8px; background: var(--surface, #ffffff); border: 1.5px solid var(--border, #e2e8f0); border-radius: 10px; padding: 6px 6px 6px 13px; color: var(--text-light, #94a3b8); width: 100%; }
.vb-csearch input { flex: 1; min-width: 0; border: none; outline: none; font-size: 14px; color: #0f172a; background: transparent; }
.vb-ficon { display: inline-flex; align-items: center; gap: 4px; flex-shrink: 0; border: none; background: none; color: var(--text-muted, #64748b); cursor: pointer; padding: 7px 8px; border-radius: 8px; }
.vb-ficon:hover { color: var(--brand); background: #f1f5f9; }
.vb-ficon.on { color: var(--brand); background: color-mix(in srgb, var(--brand) 10%, white); }
.vb-filter-n { background: var(--brand); color: #fff; font-size: 10.5px; font-weight: 800; min-width: 16px; height: 16px; border-radius: 999px; display: inline-flex; align-items: center; justify-content: center; padding: 0 4px; }
/* Floating filter panel (anchored under the field, dashboard-style shadowed card)
   with PILL selections; closes on outside tap via the document listener. */
.vb-panel { position: absolute; top: calc(100% + 6px); inset-inline-start: 0; z-index: 50; width: min(480px, calc(100vw - 32px)); background: var(--surface, #ffffff); border: 1px solid var(--border, #e2e8f0); border-radius: 12px; padding: 14px 16px; box-shadow: 0 12px 32px rgba(0, 0, 0, .14); display: flex; flex-direction: column; gap: 12px; }
.vb-frow { display: flex; align-items: flex-start; gap: 12px; }
.vb-flabel { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: var(--text-light, #94a3b8); width: 70px; flex-shrink: 0; padding-top: 7px; }
.vb-chips { display: flex; flex-wrap: wrap; gap: 7px; flex: 1; min-width: 0; }
.vb-chips button { border: 1.5px solid var(--border, #e2e8f0); background: var(--surface, #ffffff); color: #475569; font-weight: 700; font-size: 13px; padding: 6px 13px; border-radius: 999px; cursor: pointer; }
.vb-chips button:hover { border-color: var(--brand-accent); }
.vb-chips button.on { background: var(--brand); border-color: var(--brand); color: #fff; }
.vb-panel-foot { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding-top: 12px; border-top: 1px solid #eef2f6; }
.vb-clear { background: none; border: none; color: #dc2626; font-weight: 700; font-size: 13px; cursor: pointer; padding: 2px; }
.vb-clear:disabled { color: #cbd5e1; cursor: default; }
.vb-clear:not(:disabled):hover { text-decoration: underline; }
.vb-done { background: var(--brand); color: #fff; border: none; border-radius: 999px; font-weight: 800; font-size: 13px; padding: 8px 18px; cursor: pointer; }
.vb-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 16px; }
.vb-grid :deep(.fc) { width: auto; }
.vb-msg { color: var(--text-light, #94a3b8); background: #f8fafc; border-radius: 12px; padding: 22px; text-align: center; font-size: 14px; }

/* ── Mobile (≤760px): same behavior as Facilities.vue — the panel becomes a floating
   sheet above the bottom nav; 16px input stops iOS zoom; ≥44px tap targets. ── */
@media (max-width: 760px) {
  .vb-bar { margin-bottom: 10px; }
  /* Search field takes the whole row */
  .vb-search-wrap { max-width: none; flex-basis: 100%; }
  .vb-csearch { min-height: 44px; }
  .vb-csearch input { font-size: 16px; }
  .vb-ficon { min-height: 40px; min-width: 40px; justify-content: center; }

  /* Filter panel becomes a floating sheet above the bottom nav (the app's mobile pattern) */
  .vb-panel { position: fixed; top: auto; inset-inline: 12px; bottom: 96px; width: auto; z-index: 120; }

  /* Stack each label above its chip group; each chip row becomes a single-line
     horizontal scroller so the open panel stays ~3 compact rows tall */
  .vb-frow { flex-direction: column; gap: 6px; }
  .vb-flabel { width: auto; padding-top: 0; }
  .vb-chips { gap: 8px; flex-wrap: nowrap; overflow-x: auto; -webkit-overflow-scrolling: touch; scrollbar-width: none; margin: 0 -16px; padding: 0 16px; }
  .vb-chips::-webkit-scrollbar { display: none; }
  .vb-chips button { min-height: 40px; padding: 9px 14px; font-size: 14px; flex-shrink: 0; white-space: nowrap; }
  .vb-clear, .vb-done { min-height: 44px; }
  .vb-clear { padding: 10px 4px; }

  /* 2-up grid with a shorter card image ≈ triples venues above the fold. Price + title stay visible. */
  .vb-grid { grid-template-columns: 1fr 1fr; gap: 10px; }
  /* Cover scales 16:9 with the card (min 96px at 375px) instead of stretching into a thin strip on wider phones. */
  .vb-grid :deep(.fc-img) { height: auto; aspect-ratio: 16 / 9; min-height: 96px; }
  .vb-grid :deep(.fc-img-fallback span) { font-size: 30px; }
  .vb-grid :deep(.fc-body) { padding: 10px 11px 11px; }
}
</style>
