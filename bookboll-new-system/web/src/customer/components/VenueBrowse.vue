<script setup>
/**
 * VenueBrowse — the venue picker used in the create-event flow. Same browsing UI as
 * the customer Venues page (CatalogBrowser.vue): search, the image category row, a
 * Listings / Venues toggle, sort and a filter panel — but driven by local state
 * instead of URL queries, and in "select" mode: cards emit the chosen court instead
 * of navigating, and tapping a venue narrows the list to that venue's courts.
 */
import { ref, computed, watch, nextTick } from 'vue'
import { Search, X, LayoutGrid, Building2, SlidersHorizontal } from 'lucide-vue-next'
import FacilityCard from './FacilityCard.vue'
import { catalogCategories, filterCatalog, groupCatalogVenues } from '../../lib/browseCatalog.js'
import { VENUE_CATEGORIES } from '../../lib/venueCategories.js'
import { userCoords, setUserCoords } from '../../lib/customerSession.js'
import { storefrontSortKey } from '../../lib/brand.js'
import { categoryClickCounts } from '../categoryPopularity.js'
import { applyStorefrontSort, CUSTOMER_SORT_OPTIONS, sortLabel, hasCoordinates } from '../../lib/storefrontSort.js'

const props = defineProps({
  courts: { type: Array, default: () => [] },   // loadFacilities() shape
  venues: { type: Array, default: () => [] },   // loadLocations() shape — names/photos for the Venues view
  selectedId: { type: String, default: '' },
  currency: { type: String, default: '' },
  cityName: { type: String, default: '' },
})
const emit = defineEmits(['select'])

const q = ref('')
const category = ref('')
const type = ref('')
const view = ref('listings')      // 'listings' | 'venues'
const sort = ref('rec')           // 'rec' = the storefront default for the current view
const showFilters = ref(false)
const venueFocus = ref('')        // locationId after tapping a venue card — narrows listings to it
const categoryTrack = ref(null)

// Options come from what is ACTUALLY in the loaded court list — never the full
// static taxonomy — so no chip or option leads to zero results.
const categories = computed(() => catalogCategories(props.courts, categoryClickCounts(props.courts)))
const groups = computed(() => VENUE_CATEGORIES.filter(c => props.courts.some(l => (l.category || 'sports') === c.key)))
const types = computed(() => category.value
  ? [...new Set(props.courts.filter(l => (l.category || 'sports') === category.value && l.sport).map(l => l.sport))].sort()
  : [])
// A city change can remove the picked options — fall back to All rather than an empty grid.
watch(groups, cats => { if (category.value && !cats.some(c => c.key === category.value)) { category.value = ''; type.value = '' } })
watch(() => props.courts, () => { if (venueFocus.value && !props.courts.some(l => String(l.locationId) === venueFocus.value)) venueFocus.value = '' })

// Keep the active chip in view when it changes (the row scrolls horizontally).
watch([category, type], async () => {
  await nextTick()
  const track = categoryTrack.value
  const active = track?.querySelector('[aria-pressed="true"]')
  if (!track || !active) return
  const bounds = track.getBoundingClientRect(), item = active.getBoundingClientRect()
  if (item.left < bounds.left || item.right > bounds.right) track.scrollBy({ left: item.left - bounds.left - (bounds.width - item.width) / 2, behavior: 'auto' })
}, { flush: 'post' })

function chooseCategory(c = null) {
  category.value = c?.category || ''
  type.value = c?.type || ''
}
function chooseView(next) { view.value = next }
function focusVenue(venue) {
  // A venue pick is a narrowing step, not the answer — the host still needs a court.
  venueFocus.value = String(venue.locationId || venue.id)
  view.value = 'listings'
}
function reset() {
  q.value = ''; category.value = ''; type.value = ''; sort.value = 'rec'; venueFocus.value = ''
}
const hasFilters = computed(() => !!(q.value || category.value || type.value || sort.value !== 'rec' || venueFocus.value))

const matching = computed(() => filterCatalog(props.courts, { q: q.value, category: category.value, type: type.value }))
const matchingListings = computed(() => venueFocus.value ? matching.value.filter(l => String(l.locationId) === venueFocus.value) : matching.value)
const matchingVenues = computed(() => groupCatalogVenues(matching.value, props.venues))
const focusedVenue = computed(() => venueFocus.value ? matchingVenues.value.find(v => v.id === venueFocus.value) || props.venues.find(v => String(v.locationId || v.id) === venueFocus.value) : null)

const defaultSort = computed(() => storefrontSortKey(view.value === 'venues' ? 'venue' : 'space'))
const locating = ref(false), locationError = ref('')
function chooseSort(value) { locationError.value = ''; sort.value = value }
function locate() {
  if (!navigator.geolocation) { locationError.value = 'Location is unavailable in this browser.'; return }
  locating.value = true; locationError.value = ''
  navigator.geolocation.getCurrentPosition(position => {
    setUserCoords(position.coords.latitude, position.coords.longitude); locating.value = false
  }, () => { locating.value = false; locationError.value = 'Could not access your location. Allow location access and try again, or choose another order.' }, { timeout: 10000, maximumAge: 60000 })
}
const results = computed(() => {
  const items = view.value === 'venues' ? matchingVenues.value : matchingListings.value
  const key = sort.value === 'rec' || (sort.value === 'nearest' && !hasCoordinates(userCoords.value)) ? defaultSort.value : sort.value
  return applyStorefrontSort(items, { key, coords: userCoords.value })
})
const noun = computed(() => view.value === 'venues' ? 'venue' : 'listing')
</script>

<template>
  <section class="vb" aria-label="Choose a venue">
    <div class="vb-controls">
      <div class="vb-search" role="search">
        <Search :size="19" aria-hidden="true" />
        <input v-model="q" type="search" aria-label="Search listings and venues" placeholder="Search by name, activity or venue" />
        <button v-if="q" type="button" aria-label="Clear search" class="vb-clear-search" @click="q = ''"><X :size="18" /></button>
      </div>

      <div v-if="categories.length > 1" ref="categoryTrack" class="vb-categories" aria-label="Activity categories">
        <button type="button" class="vb-category" :class="{ active: !category && !type }" :aria-pressed="!category && !type" @click="chooseCategory()">
          <span class="vb-category-image vb-all-icon"><LayoutGrid :size="20" /></span><span>All venues</span>
        </button>
        <button v-for="c in categories" :key="c.key" type="button" class="vb-category" :class="{ active: type === c.type && category === c.category }" :aria-pressed="type === c.type && category === c.category" @click="chooseCategory(c)">
          <span class="vb-category-image"><img v-if="c.listings.find(l => l.photo)" :src="c.listings.find(l => l.photo).photo" alt="" loading="lazy" /><Building2 v-else :size="20" /></span>
          <span>{{ c.label }}</span>
        </button>
      </div>
    </div>

    <div class="vb-toolbar">
      <div class="vb-view" role="group" aria-label="View results as">
        <button type="button" :class="{ active: view === 'listings' }" :aria-pressed="view === 'listings'" @click="chooseView('listings')"><LayoutGrid :size="16" /> Listings <span>{{ matchingListings.length }}</span></button>
        <button type="button" :class="{ active: view === 'venues' }" :aria-pressed="view === 'venues'" @click="chooseView('venues')"><Building2 :size="16" /> Venues <span>{{ matchingVenues.length }}</span></button>
      </div>
      <label class="vb-sort">Sort by<select :value="sort" @change="chooseSort($event.target.value)"><option value="rec">Default: {{ sortLabel(defaultSort) }}</option><option v-for="option in CUSTOMER_SORT_OPTIONS" :key="option.key" :value="option.key">{{ option.label }}</option></select></label>
      <button type="button" class="vb-filter-toggle" :aria-expanded="showFilters" aria-controls="vb-filters" @click="showFilters = !showFilters"><SlidersHorizontal :size="16" /> Filters<span v-if="category || type || sort !== 'rec'" class="vb-filter-dot"></span></button>
    </div>

    <div v-if="sort === 'nearest' && !hasCoordinates(userCoords)" class="vb-location" role="status"><p>{{ locationError || 'Share your location to see the closest results. Until then, the default order is shown.' }}</p><button type="button" :disabled="locating" @click="locate">{{ locating ? 'Finding your location…' : 'Use my location' }}</button></div>

    <div v-if="showFilters" id="vb-filters" class="vb-filters">
      <label>Category
        <select :value="category" @change="chooseCategory($event.target.value ? { category: $event.target.value, type: '' } : null)">
          <option value="">All categories</option><option v-for="c in groups" :key="c.key" :value="c.key">{{ c.label }}</option>
        </select>
      </label>
      <label v-if="types.length">Type
        <select :value="type" @change="type = $event.target.value">
          <option value="">All types</option><option v-for="t in types" :key="t" :value="t">{{ t }}</option>
        </select>
      </label>
      <button type="button" class="vb-done" @click="showFilters = false">Done</button>
    </div>

    <div class="vb-results-head">
      <div aria-live="polite">
        <h2>{{ results.length }} {{ noun }}{{ results.length === 1 ? '' : 's' }}<template v-if="cityName"> in {{ cityName }}</template></h2>
        <p>{{ view === 'listings' ? 'Open a listing to choose your date & time.' : 'Tap a venue to see the listings you can book there.' }}</p>
      </div>
      <button v-if="hasFilters" type="button" class="vb-reset" @click="reset">Clear filters <X :size="14" /></button>
    </div>
    <div v-if="focusedVenue && view === 'listings'" class="vb-selection"><Building2 :size="14" /> Showing {{ focusedVenue.name }}<button type="button" aria-label="Show all venues" @click="venueFocus = ''"><X :size="15" /></button></div>

    <div v-if="!courts.length" class="vb-empty"><Search :size="28" /><h3>No bookable venues yet</h3><p>There are no bookable listings in {{ cityName || 'this city' }} yet. Try a different city using the location selector.</p></div>
    <div v-else-if="results.length" class="vb-grid">
      <FacilityCard v-for="item in results" :key="item.id" :variant="view === 'venues' ? 'location' : 'listing'" :filtered-matches="!!(q || category || type)" selectable :selected="view === 'listings' && item.id === selectedId" :facility="{ ...item, currency: item.currency || currency, cityName: item.cityName || cityName }" @select="view === 'venues' ? focusVenue(item) : emit('select', item)" />
    </div>
    <div v-else class="vb-empty"><Search :size="28" /><h3>No {{ noun }}s found</h3><p>Try another activity or a shorter search.</p><button type="button" @click="reset">Show all listings and venues</button></div>
  </section>
</template>

<style scoped>
/* Visually the same system as CatalogBrowser.vue, sized for the host-flow column. */
.vb { min-width: 0; }
.vb-controls { padding-bottom: 16px; border-bottom: 1px solid var(--border); }
.vb-search { display: flex; align-items: center; gap: 10px; border: 1px solid var(--border-strong, #cbd5e1); border-radius: 12px; padding: 4px 6px 4px 14px; color: var(--text-muted); background: var(--surface); }
.vb-search:focus-within { outline: 2px solid var(--brand); outline-offset: 2px; }
.vb-search input { min-width: 0; flex: 1; width: 0; padding: 10px 0; font-size: 15px; border: 0; box-shadow: none; border-radius: 0; background: transparent; color: var(--text); }
.vb-search input:focus { outline: 0; }
.vb-search input::-webkit-search-cancel-button { display: none; }
.vb-search > svg { flex-shrink: 0; }
.vb-clear-search { display: grid; place-items: center; width: 40px; height: 40px; flex-shrink: 0; color: var(--text-muted); }
.vb-categories { display: flex; overflow-x: auto; gap: 6px; padding: 16px 2px 2px; scrollbar-width: thin; scrollbar-color: var(--border) transparent; }
.vb-category { display: flex; align-items: center; flex-direction: column; gap: 7px; padding: 10px 12px; min-width: 86px; max-width: 125px; flex: 0 0 auto; font-size: 12px; font-weight: 600; color: var(--text-muted); border: 1px solid transparent; border-radius: 12px; background: none; cursor: pointer; }
.vb-category-image { width: 44px; height: 44px; display: grid; place-items: center; border-radius: 13px; overflow: hidden; background: var(--bg); }
.vb-category-image img { width: 100%; height: 100%; object-fit: cover; }
.vb-category.active { color: var(--brand-ink, var(--brand)); border-color: color-mix(in srgb, var(--brand) 30%, var(--surface)); background: color-mix(in srgb, var(--brand) 5%, var(--surface)); }
.vb-category:hover { color: var(--text); }
.vb-all-icon { color: var(--brand-ink, var(--brand)); background: color-mix(in srgb, var(--brand) 9%, var(--surface)); }
.vb-toolbar { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin: 16px 0 14px; }
.vb-view { display: flex; padding: 3px; gap: 3px; background: var(--surface); border: 1px solid var(--border); border-radius: 12px; }
.vb-view button { display: flex; align-items: center; justify-content: center; gap: 7px; min-height: 40px; padding: 8px 14px; font-weight: 700; font-size: 13px; border-radius: 9px; color: var(--text-muted); background: none; border: none; cursor: pointer; }
.vb-view button.active { color: var(--brand-ink, var(--brand)); background: color-mix(in srgb, var(--brand) 8%, var(--surface)); }
.vb-view button span { font-size: 12px; font-weight: 500; color: var(--text-muted); }
.vb-view button.active span { color: var(--brand-ink, var(--brand)); }
.vb-sort { display: flex; align-items: center; gap: 8px; margin-inline-start: auto; color: var(--text-muted); font-size: 13px; white-space: nowrap; }
.vb-sort select { min-height: 40px; min-width: 0; max-width: 220px; padding: 8px 10px; border: 1px solid var(--border); border-radius: 8px; background: var(--surface); color: var(--text); font-size: 13px; }
.vb-filter-toggle { display: flex; align-items: center; gap: 6px; min-height: 40px; border: 1px solid var(--border); border-radius: 10px; padding: 8px 14px; background: var(--surface); color: var(--text); font-weight: 600; font-size: 13px; cursor: pointer; }
.vb-filter-dot { width: 6px; height: 6px; border-radius: 50%; background: var(--brand); }
.vb-location { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; background: var(--surface); border: 1px solid var(--border); border-radius: 10px; padding: 14px; margin-bottom: 14px; color: var(--text-muted); font-size: 13px; }
.vb-location p { flex: 1; margin: 0; min-width: 200px; }
.vb-location button { min-height: 40px; color: var(--brand-ink, var(--brand)); font-weight: 600; background: none; border: none; cursor: pointer; }
.vb-filters { display: flex; align-items: end; flex-wrap: wrap; gap: 14px; background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 14px; margin-bottom: 14px; }
.vb-filters label { flex: 1; min-width: 150px; font-size: 13px; font-weight: 600; color: var(--text); }
.vb-filters select { display: block; width: 100%; margin-top: 6px; min-height: 40px; padding: 8px 10px; border: 1px solid var(--border); border-radius: 8px; background: var(--surface); color: var(--text); font-size: 13px; }
.vb-done { display: flex; justify-content: center; align-items: center; background: var(--brand); color: white; padding: 10px 18px; border-radius: 9px; font-weight: 700; min-height: 40px; border: none; cursor: pointer; }
.vb-results-head { display: flex; align-items: start; justify-content: space-between; gap: 12px; margin-bottom: 14px; }
.vb-results-head h2 { font-size: 17px; font-weight: 700; margin: 0 0 4px; color: var(--text); }
.vb-results-head p { font-size: 13px; color: var(--text-muted); margin: 0; }
.vb-reset { display: flex; align-items: center; gap: 6px; color: var(--brand-ink, var(--brand)); font-size: 13px; min-height: 40px; flex-shrink: 0; background: none; border: none; cursor: pointer; }
.vb-selection { display: inline-flex; align-items: center; gap: 8px; background: color-mix(in srgb, var(--brand) 9%, var(--surface)); color: var(--brand-ink, var(--brand)); border-radius: 9px; padding-inline-start: 12px; margin: -4px 0 14px; font-size: 13px; font-weight: 600; }
.vb-selection button { min-width: 40px; min-height: 40px; display: grid; place-items: center; background: none; border: none; color: inherit; cursor: pointer; }
.vb-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 16px; }
.vb-grid :deep(.fc) { width: auto; }
.vb-empty { text-align: center; display: flex; align-items: center; flex-direction: column; gap: 10px; padding: 40px 20px; border: 1px dashed var(--border); border-radius: 16px; }
.vb-empty > svg { color: var(--text-muted); }
.vb-empty h3 { margin: 0; font-size: 16px; color: var(--text); }
.vb-empty p { max-width: 380px; color: var(--text-muted); font-size: 14px; margin: 0; }
.vb-empty button { color: var(--brand-ink, var(--brand)); font-weight: 700; padding: 10px; background: none; border: none; cursor: pointer; }
.vb button:focus-visible { outline: 2px solid var(--brand); outline-offset: 3px; }

/* ── Mobile (≤760px): same behaviour as CatalogBrowser — full-width search, scrolling
   chip row, stacked toolbar; 16px input stops iOS zoom; ≥44px tap targets. ── */
@media (max-width: 760px) {
  .vb-controls { padding-bottom: 12px; }
  .vb-search { gap: 8px; padding: 4px 4px 4px 12px; min-height: 44px; }
  .vb-search input { font-size: 16px; }
  .vb-categories { margin-inline-end: -16px; gap: 4px; padding-top: 12px; padding-inline-end: 16px; scrollbar-width: none; }
  .vb-category { min-width: 80px; max-width: 108px; padding: 9px 8px; }
  .vb-category-image { width: 40px; height: 40px; border-radius: 12px; }
  .vb-toolbar { flex-wrap: wrap; gap: 8px; margin: 14px 0 12px; }
  .vb-view { flex: 1; gap: 0; }
  .vb-view button { flex: 1; gap: 5px; font-size: 13px; padding: 9px 8px; min-height: 44px; }
  .vb-sort { order: 3; width: 100%; margin: 0; }
  .vb-sort select { flex: 1; max-width: none; font-size: 16px; min-height: 44px; }
  .vb-filter-toggle { padding: 12px; font-size: 12px; min-height: 44px; }
  .vb-filters select, .vb-done, .vb-location button, .vb-reset { min-height: 44px; }
  .vb-results-head h2 { font-size: 15px; }
  .vb-results-head p { font-size: 12px; }
  /* 2-up grid with a shorter card image ≈ triples venues above the fold. */
  .vb-grid { grid-template-columns: 1fr 1fr; gap: 10px; }
  .vb-grid :deep(.fc-img) { height: auto; aspect-ratio: 16 / 9; min-height: 96px; }
  .vb-grid :deep(.fc-img-fallback span) { font-size: 30px; }
  .vb-grid :deep(.fc-body) { padding: 10px 11px 11px; }
}
@media (max-width: 370px) { .vb-view button > svg { display: none; } }
</style>
