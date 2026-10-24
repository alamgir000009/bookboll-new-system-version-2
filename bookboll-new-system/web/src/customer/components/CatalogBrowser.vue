<script setup>
import { computed, ref, watch, nextTick, onBeforeUnmount } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { Search, X, LayoutGrid, Building2, SlidersHorizontal, ArrowRight } from 'lucide-vue-next'
import FacilityCard from './FacilityCard.vue'
import { catalogCategories, filterCatalog, groupCatalogVenues, readBrowseQuery } from '../../lib/browseCatalog.js'
import { VENUE_CATEGORIES } from '../../lib/venueCategories.js'
import { slugifySport } from '../../lib/cityPages.js'
import { userCoords, setUserCoords } from '../../lib/customerSession.js'
import { storefrontSortKey, isWhiteLabel, isBrandPreview } from '../../lib/brand.js'
import { categoryClickCounts, recordCategoryClick } from '../categoryPopularity.js'
import { applyStorefrontSort, CUSTOMER_SORT_OPTIONS, sortLabel, hasCoordinates } from '../../lib/storefrontSort.js'

const props = defineProps({
  listings: { type: Array, default: () => [] },
  venues: { type: Array, default: () => [] },
  loading: Boolean,
  // Fallback only — each card carries its own currency from the API.
  currency: { type: String, default: '' },
  cityName: { type: String, default: '' },
  categoryBase: { type: String, default: '' },
  segmentCategory: { type: String, default: '' },
  segmentType: { type: String, default: '' },
})
const route = useRoute()
const router = useRouter()
const state = computed(() => readBrowseQuery(route.query))
const category = computed(() => props.categoryBase ? (props.segmentCategory || (props.segmentType ? props.listings.find(l => l.sport === props.segmentType)?.category || '' : '')) : state.value.category)
const type = computed(() => props.categoryBase ? props.segmentType : state.value.type)
const categories = computed(() => catalogCategories(props.listings, categoryClickCounts(props.listings)))
const groups = computed(() => VENUE_CATEGORIES.filter(c => props.listings.some(l => (l.category || 'sports') === c.key)))
const search = ref(state.value.q)
const showFilters = ref(false)
const categoryTrack = ref(null)
watch([category, type, () => props.loading], async () => {
  await nextTick()
  const track = categoryTrack.value
  const active = track?.querySelector('[aria-pressed="true"]')
  if (!track || !active) return
  const bounds = track.getBoundingClientRect(), item = active.getBoundingClientRect()
  if (item.left < bounds.left || item.right > bounds.right) track.scrollBy({ left: item.left - bounds.left - (bounds.width - item.width) / 2, behavior: 'auto' })
}, { immediate: true, flush: 'post' })
let searchTimer
watch(() => state.value.q, value => { search.value = value })
onBeforeUnmount(() => clearTimeout(searchTimer))
function queryWith(values) {
  const query = { ...route.query, q: search.value.trim(), ...values }
  for (const key of ['q', 'category', 'type', 'view', 'sort', 'listing_sort', 'venue_sort']) {
    if (!query[key] || (key.endsWith('sort') && query[key] === 'rec') || (key === 'view' && query[key] === 'listings')) delete query[key]
  }
  return query
}
function update(values, replace = false) {
  clearTimeout(searchTimer)
  router[replace ? 'replace' : 'push']({ path: route.path, query: queryWith(values) })
}
function searchInput() {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => update({ q: search.value.trim() }, true), 250)
}
function chooseCategory(c = null) {
  clearTimeout(searchTimer)
  if (c && (category.value !== c.category || type.value !== c.type)) recordCategoryClick(props.listings, c, 'catalog', { preview: isBrandPreview() })
  if (props.categoryBase) {
    const query = queryWith({ category: undefined, type: undefined })
    router.push({ path: props.categoryBase + (c ? '/' + (c.type ? slugifySport(c.type) : c.category) : ''), query })
  } else update({ category: c?.category, type: c?.type })
}
function reset() {
  search.value = ''
  clearTimeout(searchTimer)
  router.push({ path: props.categoryBase || route.path, query: queryWith({ q: undefined, category: undefined, type: undefined, sort: undefined, listing_sort: undefined, venue_sort: undefined }) })
}
const hasFilters = computed(() => !!(search.value || category.value || type.value || state.value.sort !== 'rec'))
const matchingListings = computed(() => filterCatalog(props.listings, { q: search.value, category: category.value, type: type.value }))
const matchingVenues = computed(() => groupCatalogVenues(matchingListings.value, props.venues))
const defaultSort = computed(() => storefrontSortKey(state.value.view === 'venues' ? 'venue' : 'space'))
const selectedSort = computed(() => state.value.sort.replace('price-', 'price_'))
const locating = ref(false), locationError = ref('')
function chooseSort(value) {
  locationError.value = ''
  update({ sort: undefined, [state.value.view === 'venues' ? 'venue_sort' : 'listing_sort']: value })
}
function chooseView(view) {
  // Preserve a legacy sort URL only for the view it belonged to.
  update({ view, sort: undefined, ...(route.query.sort ? { [state.value.view === 'venues' ? 'venue_sort' : 'listing_sort']: state.value.sort } : {}) })
}
function locate() {
  if (!navigator.geolocation) { locationError.value = 'Location is unavailable in this browser.'; return }
  locating.value = true; locationError.value = ''
  navigator.geolocation.getCurrentPosition(position => {
    setUserCoords({ lat: position.coords.latitude, lng: position.coords.longitude }); locating.value = false
  }, () => { locating.value = false; locationError.value = 'Could not access your location. Allow location access and try again, or choose another order.' }, { timeout: 10000, maximumAge: 60000 })
}
const results = computed(() => {
  const items = state.value.view === 'venues' ? matchingVenues.value : matchingListings.value
  const key = selectedSort.value === 'rec' || (selectedSort.value === 'nearest' && !hasCoordinates(userCoords.value)) ? defaultSort.value : selectedSort.value
  return applyStorefrontSort(items, { key, coords: userCoords.value })
})
const noun = computed(() => state.value.view === 'venues' ? 'venue' : 'listing')
const returnTo = computed(() => router.resolve({ path: route.path, query: queryWith({}) }).fullPath)
</script>

<template>
  <section class="catalog" aria-label="Browse venues and listings" :aria-busy="loading">
    <div class="catalog-controls">
      <form class="catalog-search" role="search" @submit.prevent="update({ q: search.trim() }, true)">
        <Search :size="21" aria-hidden="true" />
        <input v-model="search" type="search" aria-label="Search listings and venues" :placeholder="isWhiteLabel ? 'Search listings and venues' : 'Search by name, activity or venue'" @input="searchInput" />
        <button v-if="search" type="button" aria-label="Clear search" class="catalog-clear-search" @click="search = ''; update({ q: '' }, true)"><X :size="18" /></button>
        <button type="submit" class="catalog-search-submit">Search <ArrowRight :size="16" /></button>
      </form>

      <div ref="categoryTrack" class="catalog-categories" aria-label="Activity categories">
        <button type="button" class="catalog-category" :class="{ active: !category && !type }" :aria-pressed="!category && !type" @click="chooseCategory()">
          <span class="catalog-category-image catalog-all-icon"><LayoutGrid :size="22" /></span><span>{{ isWhiteLabel ? 'All listings' : 'All Venues' }}</span>
        </button>
        <button v-for="c in categories" :key="c.key" type="button" class="catalog-category" :class="{ active: type === c.type && category === c.category }" :aria-pressed="type === c.type && category === c.category" @click="chooseCategory(c)">
          <span class="catalog-category-image"><img v-if="c.listings.find(l => l.photo)" :src="c.listings.find(l => l.photo).photo" alt="" loading="lazy" /><Building2 v-else :size="22" /></span>
          <span>{{ c.label }}</span>
        </button>
      </div>
    </div>

    <div class="catalog-toolbar">
      <div class="catalog-view" role="group" aria-label="View results as">
        <button type="button" :class="{ active: state.view === 'listings' }" :aria-pressed="state.view === 'listings'" @click="chooseView('listings')"><LayoutGrid :size="17" /> Listings <span v-if="!loading">{{ matchingListings.length }}</span></button>
        <button type="button" :class="{ active: state.view === 'venues' }" :aria-pressed="state.view === 'venues'" @click="chooseView('venues')"><Building2 :size="17" /> Venues <span v-if="!loading">{{ matchingVenues.length }}</span></button>
      </div>
      <label class="catalog-sort">Sort by<select :value="selectedSort" @change="chooseSort($event.target.value)"><option value="rec">Default: {{ sortLabel(defaultSort) }}</option><option v-for="option in CUSTOMER_SORT_OPTIONS" :key="option.key" :value="option.key">{{ option.label }}</option></select></label>
      <button type="button" class="catalog-filter-toggle" :aria-expanded="showFilters" aria-controls="catalog-filters" @click="showFilters = !showFilters"><SlidersHorizontal :size="17" /> Filters<span v-if="state.sort !== 'rec'" class="catalog-filter-dot"></span></button>
    </div>

    <div v-if="selectedSort === 'nearest' && !hasCoordinates(userCoords)" class="catalog-location" role="status"><p>{{ locationError || 'Share your location to see the closest results. Until then, the default order is shown.' }}</p><button type="button" :disabled="locating" @click="locate">{{ locating ? 'Finding your location…' : 'Use my location' }}</button></div>

    <div v-if="showFilters" id="catalog-filters" class="catalog-filters">
      <label>Category
        <select :value="category" @change="chooseCategory($event.target.value ? { category: $event.target.value, type: '' } : null)">
          <option value="">All categories</option><option v-for="c in groups" :key="c.key" :value="c.key">{{ c.label }}</option>
        </select>
      </label>
      <button type="button" class="catalog-done" @click="showFilters = false">Done</button>
    </div>

    <div class="catalog-results-head">
      <div aria-live="polite"><h2>{{ loading ? 'Finding listings…' : `${results.length} ${noun}${results.length === 1 ? '' : 's'}` }}<template v-if="cityName && !loading"> in {{ cityName }}</template></h2><p>{{ state.view === 'listings' ? 'Compare listings, then choose a time that works for you.' : 'Explore each venue and the listings you can book there.' }}</p></div>
      <button v-if="hasFilters" type="button" class="catalog-reset" @click="reset">Clear filters <X :size="14" /></button>
    </div>
    <div v-if="loading" class="catalog-grid" aria-label="Loading results"><div v-for="n in 6" :key="n" class="catalog-skeleton"></div></div>
    <div v-else-if="results.length" class="catalog-grid">
      <FacilityCard v-for="item in results" :key="item.id" :variant="state.view === 'venues' ? 'location' : 'listing'" :filtered-matches="!!(search || category || type)" :return-to="returnTo" :facility="{ ...item, currency: item.currency || currency, cityName: item.cityName || cityName }" />
    </div>
    <div v-else class="catalog-empty"><Search :size="30" /><h3>No {{ noun }}s found</h3><p>{{ hasFilters ? 'Try another activity or a shorter search.' : 'There are no bookable listings here yet. Try a different city using the location selector.' }}</p><button v-if="hasFilters" type="button" @click="reset">Show all listings and venues</button></div>
  </section>
</template>

<style scoped>
.catalog { min-width: 0; }
.catalog-sort { display:flex; align-items:center; gap:8px; margin-inline-start:auto; color:var(--text-muted); font-size:13px; white-space:nowrap; }
.catalog-sort select { min-height:44px; min-width:0; max-width:240px; padding:10px; border:1px solid var(--border); border-radius:8px; background:var(--surface); color:var(--text); font-size:14px; }
.catalog-location { display:flex; align-items:center; gap:16px; flex-wrap:wrap; background:var(--surface); border:1px solid var(--border); border-radius:10px; padding:16px; margin-bottom:20px; color:var(--text-muted); font-size:14px; }
.catalog-location p { flex:1; margin:0; min-width:200px; }
.catalog-location button { min-height:44px; color:var(--brand-ink, var(--brand)); font-weight:600; }
@media(max-width:760px) { .catalog .catalog-toolbar { flex-wrap:wrap; } .catalog-sort { order:3; width:100%; margin:0; } .catalog-sort select { flex:1; max-width:none; font-size:16px; } }
.catalog-controls { padding-bottom: 20px; border-bottom: 1px solid var(--border); }
.catalog-search { display: flex; align-items: center; gap: 12px; border: 1px solid var(--border-strong, #cbd5e1); border-radius: 13px; padding: 6px 7px 6px 16px; color: var(--text-muted); background: var(--surface); }
.catalog-search:focus-within { outline: 2px solid var(--brand); outline-offset: 2px; }
.catalog-search input { min-width: 0; flex: 1; width: 0; padding: 10px 0; font-size: 16px; border: 0; box-shadow: none; border-radius: 0; background: transparent; }
.catalog-search input:focus { outline: 0; }
.catalog-search input::-webkit-search-cancel-button { display: none; }
.catalog-search > svg { flex-shrink: 0; }
.catalog-search-submit, .catalog-done { display: flex; justify-content: center; align-items: center; gap: 10px; background: var(--brand); color: white; padding: 12px 18px; border-radius: 9px; font-weight: 700; min-height: 44px; }
.catalog-clear-search { display: grid; place-items: center; width: 44px; height: 44px; flex-shrink: 0; }
.catalog-categories { display: flex; overflow-x: auto; gap: 8px; padding: 20px 2px 4px; scrollbar-width: thin; scrollbar-color: var(--border) transparent; }
.catalog-category { display: flex; align-items: center; flex-direction: column; gap: 8px; padding: 12px 14px; min-width: 94px; max-width: 135px; flex: 0 0 auto; font-size: 12px; font-weight: 600; color: var(--text-muted); border: 1px solid transparent; border-radius: 12px; }
.catalog-category-image { width: 48px; height: 48px; display: grid; place-items: center; border-radius: 14px; overflow: hidden; background: var(--bg); }
.catalog-category-image img { width: 100%; height: 100%; object-fit: cover; }
.catalog-category.active { color: var(--brand-ink, var(--brand)); border-color: color-mix(in srgb, var(--brand) 30%, var(--surface)); background: color-mix(in srgb, var(--brand) 5%, var(--surface)); }
.catalog-category:hover { color: var(--text); }
.catalog-all-icon { color: var(--brand-ink, var(--brand)); background: color-mix(in srgb, var(--brand) 9%, var(--surface)); }
.catalog-toolbar { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin: 24px 0 20px; }
.catalog-view { display: flex; padding: 4px; gap: 4px; background: var(--surface); border: 1px solid var(--border); border-radius: 13px; }
.catalog-view button { display: flex; align-items: center; justify-content: center; gap: 8px; min-height: 44px; padding: 10px 20px; font-weight: 700; font-size: 14px; border-radius: 10px; color: var(--text-muted); }
.catalog-view button.active { color: var(--brand-ink, var(--brand)); background: color-mix(in srgb, var(--brand) 8%, var(--surface)); }
.catalog-view button.active span { color: var(--brand-ink, var(--brand)); }
.catalog-view button span { font-size: 12px; font-weight: 500; color: var(--text-muted); }
.catalog-filter-toggle { display: flex; align-items: center; gap: 7px; min-height: 44px; border: 1px solid var(--border); border-radius: 11px; padding: 10px 16px; background: var(--surface); font-weight: 600; }
.catalog-filter-dot { width: 6px; height: 6px; border-radius: 50%; background: var(--brand); }
.catalog-filters { display: flex; align-items: end; flex-wrap: wrap; gap: 16px; background: var(--surface); border: 1px solid var(--border); border-radius: 13px; padding: 16px; margin-bottom: 20px; }
.catalog-filters label { flex: 1; min-width: 150px; font-size: 13px; font-weight: 600; }
.catalog-filters select { margin-top: 6px; min-height: 44px; }
.catalog-results-head { display: flex; align-items: start; justify-content: space-between; gap: 12px; margin-bottom: 20px; }
.catalog-results-head h2 { font-size: 19px; font-weight: 700; margin: 0 0 5px; }
.catalog-results-head p { font-size: 13px; color: var(--text-muted); }
.catalog-reset { display: flex; align-items: center; gap: 6px; color: var(--brand-ink, var(--brand)); font-size: 13px; min-height: 44px; flex-shrink: 0; }
.catalog-selection { display: inline-flex; align-items: center; gap: 8px; background: color-mix(in srgb, var(--brand) 9%, var(--surface)); color: var(--brand-ink, var(--brand)); border-radius: 9px; padding-inline-start: 12px; margin: -8px 0 16px; font-size: 13px; font-weight: 600; }
.catalog-selection button { min-width: 44px; min-height: 44px; display: grid; place-items: center; }
.catalog-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 22px 18px; }
.catalog-grid :deep(.fc) { width: 100%; }
.catalog-skeleton { height: 280px; border-radius: 15px; background: var(--border); opacity: .5; }
.catalog-empty { text-align: center; display: flex; align-items: center; flex-direction: column; gap: 12px; padding: 48px 20px; border: 1px dashed var(--border); border-radius: 16px; }
.catalog-empty > svg { color: var(--text-muted); }
.catalog-empty p { max-width: 380px; color: var(--text-muted); font-size: 14px; }
.catalog-empty button { color: var(--brand-ink, var(--brand)); font-weight: 700; padding: 12px; }
.catalog button:focus-visible { outline: 2px solid var(--brand); outline-offset: 3px; }
@media(max-width:1024px) { .catalog-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media(max-width:760px) {
  .catalog-controls { padding: 0 0 14px; }
  .catalog-search { gap: 8px; padding: 4px 4px 4px 12px; }
  .catalog-search input { font-size: 16px; }
  .catalog-search-submit { width: 44px; padding: 12px; font-size: 0; gap: 0; flex-shrink: 0; }
  .catalog-categories { margin-inline-end: -16px; gap: 4px; padding-top: 14px; padding-inline-end: 16px; scrollbar-width: none; }
  .catalog-category { min-width: 83px; max-width: 110px; padding: 10px 9px; font-size: 12px; }
  .catalog-category-image { width: 42px; height: 42px; border-radius: 12px; }
  .catalog-toolbar { gap: 8px; margin: 18px 0; }
  .catalog-view { flex: 1; gap: 0; }
  .catalog-view button { flex: 1; gap: 5px; font-size: 13px; padding: 9px 8px; }
  .catalog-filter-toggle { padding: 12px; font-size: 12px; }
  .catalog-results-head { margin-bottom: 15px; position: relative; }
  .catalog-results-head > div { width: 100%; }
  .catalog-results-head h2 { padding-inline-end: 90px; }
  .catalog-reset { position: absolute; inset-inline-end: 0; top: -12px; font-size: 12px; }
  .catalog-results-head h2 { font-size: 17px; }
  .catalog-results-head p { max-width: none; font-size: 12px; }
  .catalog-grid { grid-template-columns: 1fr; gap: 12px; }
  .catalog-grid :deep(.fc) { display: grid; grid-template-columns: clamp(132px, 36%, 220px) minmax(0, 1fr); border-radius: 14px; }
  .catalog-grid :deep(.fc-img) { height: 100%; min-height: 152px; max-height: none; aspect-ratio: auto; border-radius: 0; }
  .catalog-grid :deep(.fc-body) { padding: 12px; }
  .catalog-grid :deep(.fc-title) { font-size: 14px; }
  .catalog-grid :deep(.fc-sub) { font-size: 12px; }
  .catalog-grid :deep(.fc-price) { flex-wrap: wrap; gap: 4px; margin-top: 10px; padding-top: 9px; }
  .catalog-grid :deep(.fc-amt) { font-size: 15px; }
  .catalog-skeleton { height: 156px; }
}
@media(max-width:370px) {
  .catalog-view button > svg { display: none; }
}
</style>
