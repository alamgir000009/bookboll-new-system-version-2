<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import { selectedCity, selectedCountry, userCoords } from '../../lib/customerSession.js'
import { loadFacilities, loadLocations } from '../../customer/data.js'
import FacilityCard from '../components/FacilityCard.vue'
import RecruitBanner from '../components/RecruitBanner.vue'
import { Search, Building2, LayoutGrid, MapPin, SlidersHorizontal } from 'lucide-vue-next'
import { VENUE_CATEGORIES, subcategoriesFor } from '../../lib/venueCategories.js'
import { useSeo, faqLd } from '../../lib/seo.js'
import { slugifyCity, slugifySport } from '../../lib/cityPages.js'
import { isWhiteLabel, storefrontSortKey } from '../../lib/brand.js'
import { applyStorefrontSort } from '../../lib/storefrontSort.js'

// Venue-booking FAQ — apex marketplace only, mirrored as FAQPage JSON-LD below.
// Answers are product-accurate: payment options vary per venue, the deposit is part of
// the listed price (never a fee on top), and selected slots are held during checkout.
const faqs = computed(() => {
  if (isWhiteLabel.value) return []
  const city = selectedCity.value?.name
  return [
    { q: `How do I book a court or pitch${city ? ' in ' + city : ''} on Bookboll?`,
      a: 'Open a venue, pick a date and an available time slot, and confirm at checkout. Availability is live, and the calendar highlights each day\'s best available slot price.' },
    { q: 'How does payment work for venue bookings?',
      a: 'Payment options vary per venue: some take the full amount online, others take a deposit online with the rest paid on arrival at the venue. You always see the venue\'s options at checkout, and Bookboll adds no booking fees on top of the listed price.' },
    { q: 'Is the deposit an extra charge?',
      a: 'No — the deposit is part of the total price, not a fee on top. You pay that portion online to secure your slot and settle the remaining balance when you arrive at the venue.' },
    { q: 'What\'s included in the price?',
      a: 'The listed price covers the court or space for the time slots you book. Some venues also offer optional extras — like equipment rental — which are priced separately and only added if you choose them at checkout.' },
    { q: 'Is my time slot reserved while I pay?',
      a: 'Yes — the slots you select are held for you for a few minutes while you finish checkout, so nobody else can take them. If you navigate away, you can resume the same booking from where you left off.' },
    { q: `What kinds of venues can I book${city ? ' in ' + city : ''}?`,
      a: 'Padel, football, tennis, basketball, badminton and other courts and pitches — plus gyms, studios, halls and multi-use spaces. Browse by venue, or switch to the courts & spaces view to compare individual courts and prices.' },
    { q: 'How do I find venues near me?',
      a: 'Share your location and venues are sorted nearest first. You can also pick your city at the top and use the search and filters to narrow by sport or venue type.' },
    { q: 'Can I cancel or reschedule a venue booking?',
      a: 'Cancellation and refund terms are set by each venue, not by Bookboll — check the listing and our Terms page, and contact the venue directly if your plans change.' },
    { q: 'Do venue bookings earn rewards?',
      a: 'Yes — bookings on the Bookboll marketplace earn Bookboll Rewards points, which you can redeem at checkout for money off future venue bookings where redemption is available.' },
    { q: 'Will I get a booking confirmation?',
      a: 'Your confirmation appears in your account instantly. Depending on the venue\'s settings and your notification preferences, updates can also arrive by email, SMS or WhatsApp — you can manage your preferences from your account.' },
  ]
})

useSeo(() => {
  const city = selectedCity.value?.name
  return {
    title: city ? `Sports venues in ${city}` : 'Sports venues — book courts & pitches',
    description: `Browse and book sports courts, pitches and venues${city ? ' in ' + city : ''} by the hour on Bookboll.`,
    path: '/facilities',
    jsonLd: [faqLd(faqs.value)].filter(Boolean),
  }
})

// DEFAULT = grouped by venue: the page is "Venues in {city}", and one card per club
// (with "from" pricing) reads far cleaner than one card per court — a club with five
// padel courts otherwise floods the list with near-duplicates. The per-court view
// stays as the secondary toggle for people comparing individual courts and prices.
const view = ref('location')      // 'location' = per venue (default) · 'facility' = per court/space
const facilityList = ref([])
const locationList = ref([])
const loading = ref(true)
const q = ref('')

async function load() {
  loading.value = true
  const cid = selectedCity.value?.id
  const [f, l] = await Promise.all([loadFacilities(cid), loadLocations(cid)])
  facilityList.value = f
  // Marketplace rule: a venue only appears when it has ≥1 bookable listing — i.e. at
  // least one card in the "Courts & spaces" view (marketplace-flagged + approved + not
  // paused, per data.js listingVisible). Venues with no listings, or none approved yet,
  // are hidden from the Bookboll marketplace. White-label sites keep their own placement
  // rule (providers may show venues pre-approval on their own site).
  if (isWhiteLabel.value) {
    locationList.value = l
  } else {
    const bookable = new Set(f.map(x => x.locationId))
    locationList.value = l.filter(v => bookable.has(v.id))
  }
  loading.value = false
}
onMounted(load)
watch(() => selectedCity.value?.id, load)

const source = computed(() => view.value === 'facility' ? facilityList.value : locationList.value)

// Internal links to the city × sport landing pages (apex marketplace only).
const citySlug = computed(() => selectedCity.value ? slugifyCity(selectedCity.value.name) : '')
const popularSports = computed(() => {
  if (isWhiteLabel.value || !selectedCity.value) return []
  const tally = {}
  for (const f of facilityList.value) {
    if (!f.sport) continue
    const sl = slugifySport(f.sport)
    if (!tally[sl]) tally[sl] = { name: f.sport, slug: sl, count: 0 }
    tally[sl].count++
  }
  return Object.values(tally).sort((a, b) => b.count - a.count).slice(0, 8)
})

// ── Advanced filters (category / type / sort) ──
// Options come from what is ACTUALLY listed in the selected city — not the full
// static taxonomy — so the dropdowns never offer a category/type with zero results.
const showFilters = ref(false)
const fCat = ref('all')      // category key or 'all'
const fSub = ref('all')      // subcategory / type or 'all'
const fSort = ref('rec')     // 'rec' | 'price-asc' | 'price-desc'
const availCategories = computed(() => {
  const keys = new Set(facilityList.value.map(f => f.category).filter(Boolean))
  return VENUE_CATEGORIES.filter(c => keys.has(c.key))
})
const availTypes = computed(() => {
  if (fCat.value === 'all') return []
  return [...new Set(facilityList.value.filter(f => f.category === fCat.value && f.sport).map(f => f.sport))].sort()
})
watch(fCat, () => { fSub.value = 'all' })
// City change can remove the currently-picked options — fall back to All.
watch(availCategories, (cats) => { if (fCat.value !== 'all' && !cats.some(c => c.key === fCat.value)) { fCat.value = 'all' } })
const activeFilters = computed(() => (fCat.value !== 'all' ? 1 : 0) + (fSub.value !== 'all' ? 1 : 0) + (fSort.value !== 'rec' ? 1 : 0))
function clearFilters() { fCat.value = 'all'; fSub.value = 'all'; fSort.value = 'rec' }
// Tapping anywhere outside the search+filter cluster closes the floating panel.
function onDocClick(e) { if (showFilters.value && !e.target.closest('.fac-search-wrap')) showFilters.value = false }
onMounted(() => document.addEventListener('click', onDocClick))
onBeforeUnmount(() => document.removeEventListener('click', onDocClick))

const filtered = computed(() => {
  const s = q.value.trim().toLowerCase()
  const byVenue = view.value === 'location'
  let arr = source.value.filter(f => {
    if (s && !`${f.name} ${f.pinText || ''} ${f.sportsText || f.sport || ''}`.toLowerCase().includes(s)) return false
    if (fCat.value !== 'all' && !(byVenue ? (f.categories || []) : [f.category]).includes(fCat.value)) return false
    if (fSub.value !== 'all' && !(byVenue ? (f.subcats || []) : [f.sport]).includes(fSub.value)) return false
    return true
  })
  // Explicit price overrides win; otherwise "Recommended" = nearest first when the
  // visitor has shared their location, else the dashboard's configured sort for this view.
  if (fSort.value === 'price-asc') return [...arr].sort((a, b) => (a.price ?? Infinity) - (b.price ?? Infinity))
  if (fSort.value === 'price-desc') return [...arr].sort((a, b) => (b.price ?? -1) - (a.price ?? -1))
  return applyStorefrontSort(arr, { key: storefrontSortKey(byVenue ? 'venue' : 'space'), coords: userCoords.value })
})
</script>

<template>
  <div>
    <div class="ph">
      <h1><Building2 :size="24" /> Venues in {{ selectedCity?.name }}</h1>
      <p>{{ view === 'location' ? 'Find a venue near you — open one to see its courts and prices.' : 'Every bookable court and space, with live prices.' }}</p>
    </div>

    <div v-if="popularSports.length" class="fac-popular">
      <span class="fac-popular-l">Popular in {{ selectedCity?.name }}:</span>
      <router-link v-for="s in popularSports" :key="s.slug" :to="`/venues/${citySlug}/${s.slug}`" class="fac-popular-chip">{{ s.name }}</router-link>
      <router-link :to="`/venues/${citySlug}`" class="fac-popular-chip fac-popular-all">All venues →</router-link>
    </div>

    <div class="fac-bar">
      <!-- ONE clean field: search with the filter toggle living INSIDE it -->
      <div class="fac-search-wrap">
        <div class="csearch fac-csearch">
          <Search :size="17" />
          <input v-model="q" :placeholder="view === 'location' ? 'Search venue or sport…' : 'Search court, venue or sport…'" />
          <button type="button" class="fac-ficon" :class="{ on: showFilters || activeFilters }" aria-label="Filters" @click="showFilters = !showFilters">
            <SlidersHorizontal :size="17" /><span v-if="activeFilters" class="fac-filter-n">{{ activeFilters }}</span>
          </button>
        </div>

        <!-- Floating filter panel: pill selections, closes on outside tap -->
        <div v-if="showFilters" class="fac-panel">
          <div class="fac-frow">
            <span class="fac-flabel">Category</span>
            <div class="fac-chips">
              <button :class="{ on: fCat === 'all' }" @click="fCat = 'all'">All</button>
              <button v-for="c in availCategories" :key="c.key" :class="{ on: fCat === c.key }" @click="fCat = c.key">{{ c.label }}</button>
            </div>
          </div>
          <div v-if="availTypes.length" class="fac-frow">
            <span class="fac-flabel">Type</span>
            <div class="fac-chips">
              <button :class="{ on: fSub === 'all' }" @click="fSub = 'all'">All</button>
              <button v-for="s in availTypes" :key="s" :class="{ on: fSub === s }" @click="fSub = s">{{ s }}</button>
            </div>
          </div>
          <div class="fac-frow">
            <span class="fac-flabel">Sort</span>
            <div class="fac-chips">
              <button :class="{ on: fSort === 'rec' }" @click="fSort = 'rec'">Recommended</button>
              <button :class="{ on: fSort === 'price-asc' }" @click="fSort = 'price-asc'">Price ↑</button>
              <button :class="{ on: fSort === 'price-desc' }" @click="fSort = 'price-desc'">Price ↓</button>
            </div>
          </div>
          <div class="fac-panel-foot">
            <button class="fac-clear" :disabled="!activeFilters" @click="clearFilters">Clear all</button>
            <button class="fac-done" @click="showFilters = false">Done</button>
          </div>
        </div>
      </div>
      <div class="fac-toggle">
        <button :class="{ on: view === 'location' }" @click="view = 'location'"><MapPin :size="15" /> Venues</button>
        <button :class="{ on: view === 'facility' }" @click="view = 'facility'"><LayoutGrid :size="15" /> Courts &amp; spaces</button>
      </div>
    </div>

    <div v-if="loading" class="cmsg">Loading…</div>
    <div v-else-if="!filtered.length" class="cempty"><Building2 :size="36" /><span>No {{ view === 'facility' ? 'spaces' : 'venues' }} found.</span></div>
    <div v-else class="cgrid">
      <FacilityCard v-for="f in filtered" :key="f.id" :variant="view === 'location' ? 'location' : 'listing'" :facility="{ ...f, currency: selectedCountry?.currency_code || 'AED', cityName: selectedCity?.name }" />
    </div>

    <!-- Provider recruitment — straight after the listings, BEFORE the SEO sections -->
    <RecruitBanner :city-name="selectedCity?.name" />

    <!-- Venue-booking FAQ (apex only — mirrors the FAQPage JSON-LD) -->
    <section v-if="faqs.length" class="fac-faq">
      <h2>Frequently asked questions</h2>
      <details v-for="(f, i) in faqs" :key="i" class="fac-faq-item">
        <summary>{{ f.q }}</summary>
        <p>{{ f.a }}</p>
      </details>
    </section>
  </div>
</template>

<style scoped>
.ph h1 { font-size: 25px; font-weight: 900; color: #0f172a; margin: 0; display: flex; align-items: center; gap: 10px; }
.ph h1 svg { color: var(--brand); flex-shrink: 0; }
.ph p { font-size: 14.5px; color: var(--text-muted, #64748b); margin: 6px 0 18px; }
.fac-popular { display: flex; align-items: center; flex-wrap: wrap; gap: 7px; margin: -8px 0 18px; }
.fac-popular-l { font-size: 12.5px; font-weight: 700; color: var(--text-light, #94a3b8); margin-right: 2px; }
.fac-popular-chip { font-size: 12.5px; font-weight: 700; color: #334155; background: var(--surface, #ffffff); border: 1.5px solid var(--border, #e2e8f0); border-radius: 999px; padding: 5px 12px; text-decoration: none; }
.fac-popular-chip:hover { border-color: var(--brand-accent); color: var(--brand); }
.fac-popular-all { color: var(--brand); border-color: #99f6e4; background: color-mix(in srgb, var(--brand) 8%, white); }
.fac-bar { display: flex; align-items: center; gap: 8px; margin-bottom: 20px; flex-wrap: wrap; }
/* One clean field (dashboard style): search input with the filter icon INSIDE it */
.fac-search-wrap { position: relative; flex: 1; min-width: 200px; max-width: 480px; }
.csearch { display: flex; align-items: center; gap: 8px; background: var(--surface, #ffffff); border: 1.5px solid var(--border, #e2e8f0); border-radius: 10px; padding: 8px 13px; color: var(--text-light, #94a3b8); flex: 1; min-width: 170px; max-width: 360px; }
.csearch input { flex: 1; min-width: 0; border: none; outline: none; font-size: 14px; color: #0f172a; background: transparent; }
.fac-csearch { max-width: none; width: 100%; padding: 6px 6px 6px 13px; }
.fac-ficon { display: inline-flex; align-items: center; gap: 4px; flex-shrink: 0; border: none; background: none; color: var(--text-muted, #64748b); cursor: pointer; padding: 7px 8px; border-radius: 8px; }
.fac-ficon:hover { color: var(--brand); background: #f1f5f9; }
.fac-ficon.on { color: var(--brand); background: color-mix(in srgb, var(--brand) 10%, white); }
.fac-filter-n { background: var(--brand); color: #fff; font-size: 10.5px; font-weight: 800; min-width: 16px; height: 16px; border-radius: 999px; display: inline-flex; align-items: center; justify-content: center; padding: 0 4px; }
/* Floating filter panel (anchored under the field, dashboard-style shadowed card)
   with PILL selections; closes on outside tap via the document listener. */
.fac-panel { position: absolute; top: calc(100% + 6px); inset-inline-start: 0; z-index: 50; width: min(480px, calc(100vw - 32px)); background: var(--surface, #ffffff); border: 1px solid var(--border, #e2e8f0); border-radius: 12px; padding: 14px 16px; box-shadow: 0 12px 32px rgba(0, 0, 0, .14); display: flex; flex-direction: column; gap: 12px; }
.fac-frow { display: flex; align-items: flex-start; gap: 12px; }
.fac-flabel { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: var(--text-light, #94a3b8); width: 70px; flex-shrink: 0; padding-top: 7px; }
.fac-chips { display: flex; flex-wrap: wrap; gap: 7px; flex: 1; min-width: 0; }
.fac-chips button { border: 1.5px solid var(--border, #e2e8f0); background: var(--surface, #ffffff); color: #475569; font-weight: 700; font-size: 13px; padding: 6px 13px; border-radius: 999px; cursor: pointer; }
.fac-chips button:hover { border-color: var(--brand-accent); }
.fac-chips button.on { background: var(--brand); border-color: var(--brand); color: #fff; }
.fac-panel-foot { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding-top: 12px; border-top: 1px solid #eef2f6; }
.fac-clear { background: none; border: none; color: #dc2626; font-weight: 700; font-size: 13px; cursor: pointer; padding: 2px; }
.fac-clear:disabled { color: #cbd5e1; cursor: default; }
.fac-clear:not(:disabled):hover { text-decoration: underline; }
.fac-done { background: var(--brand); color: #fff; border: none; border-radius: 999px; font-weight: 800; font-size: 13px; padding: 8px 18px; cursor: pointer; }
.fac-toggle { display: flex; gap: 3px; background: #f1f5f9; border-radius: 10px; padding: 3px; margin-left: auto; }
.fac-toggle button { display: inline-flex; align-items: center; gap: 6px; border: none; background: transparent; color: var(--text-muted, #64748b); font-weight: 700; font-size: 13px; padding: 7px 12px; border-radius: 8px; cursor: pointer; white-space: nowrap; }
.fac-toggle button.on { background: var(--surface, #ffffff); color: var(--brand); box-shadow: 0 1px 3px rgba(15,23,42,.1); }
.cgrid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 16px; }
.cgrid :deep(.fc) { width: auto; }
.cmsg { text-align: center; padding: 50px; color: var(--text-light, #94a3b8); }
.cempty { display: flex; flex-direction: column; align-items: center; gap: 12px; padding: 60px; color: var(--text-light, #94a3b8); }
/* FAQ — same look as the city landing pages' FAQ */
.fac-faq { margin: 44px 0 16px; max-width: 760px; }
.fac-faq h2 { font-size: 20px; font-weight: 800; color: #0f172a; margin: 0 0 12px; }
/* Quiet divider-list FAQ (same treatment as the homepage) */
.fac-faq-item { border-bottom: 1px solid var(--border, #e8edf3); }
.fac-faq-item:last-child { border-bottom: none; }
.fac-faq-item summary { display: flex; align-items: center; justify-content: space-between; gap: 14px; list-style: none; padding: 13px 2px; font-size: 14px; font-weight: 600; color: #334155; cursor: pointer; }
.fac-faq-item summary::-webkit-details-marker { display: none; }
.fac-faq-item summary::after { content: '+'; font-size: 17px; font-weight: 400; color: var(--text-light, #94a3b8); flex-shrink: 0; transition: transform .15s; }
.fac-faq-item[open] summary { color: #0f172a; }
.fac-faq-item[open] summary::after { transform: rotate(45deg); color: var(--brand); }
.fac-faq-item summary:hover { color: var(--brand); }
.fac-faq-item p { font-size: 13.5px; color: var(--text-muted, #64748b); line-height: 1.65; margin: 0; padding: 0 24px 14px 2px; }
/* Provider recruitment banner now lives in components/RecruitBanner.vue */

/* ── Mobile tuning (≤760px): tighten the browse header/toolbar so the By-space/By-venue
   toggle and first venue card come up with minimal scroll; comfortable 44px tap targets;
   no horizontal body overflow (popular chips scroll in their own strip). ── */
@media (max-width: 760px) {
  /* Filter panel becomes a floating sheet above the bottom nav (the app's mobile
     pattern); pills grow to comfortable tap sizes; 16px input stops iOS zoom */
  .fac-panel { position: fixed; top: auto; inset-inline: 12px; bottom: 96px; width: auto; z-index: 120; }
  .fac-csearch input { font-size: 16px; }
  .fac-chips button { padding: 9px 14px; font-size: 13.5px; }

  /* Header band — shrink oversized heading; hide the subtitle (it restates the
     h1 + the By-space/By-venue toggle) so the first venue card comes up sooner */
  .ph h1 { font-size: 20px; margin: 0 0 12px; }
  .ph h1 svg { width: 20px; height: 20px; }
  .ph p { display: none; }

  /* Popular-in-city row → single horizontal scroll strip (no wrap, no body overflow) */
  .fac-popular { flex-wrap: nowrap; overflow-x: auto; -webkit-overflow-scrolling: touch; scrollbar-width: none; margin: -4px -16px 12px; padding: 0 16px 2px; }
  .fac-popular::-webkit-scrollbar { display: none; }
  .fac-popular-l { display: none; }
  .fac-popular-chip, .fac-popular-all { flex-shrink: 0; white-space: nowrap; }

  /* Toolbar — search on its own full row; Filters + view toggle share a clean second row */
  .fac-bar { margin-bottom: 12px; }
  .csearch { max-width: none; width: 100%; flex-basis: 100%; min-height: 44px; }
  .csearch input { font-size: 16px; }
  .fac-filter { min-height: 44px; }
  .fac-toggle { margin-left: 0; flex: 1; }
  .fac-toggle button { flex: 1; justify-content: center; min-height: 44px; padding: 10px 12px; font-size: 14px; }

  /* Filter panel — stack each label above its chip group; each chip row becomes a
     single-line horizontal scroller so the open panel stays ~3 compact rows tall */
  .fac-frow { flex-direction: column; gap: 6px; }
  .fac-flabel { width: auto; padding-top: 0; }
  .fac-chips { gap: 8px; flex-wrap: nowrap; overflow-x: auto; -webkit-overflow-scrolling: touch; scrollbar-width: none; margin: 0 -16px; padding: 0 16px; }
  .fac-chips::-webkit-scrollbar { display: none; }
  .fac-chips button { min-height: 40px; padding: 9px 14px; font-size: 14px; flex-shrink: 0; white-space: nowrap; }

  /* Browse grid — denser 2-up so more venues fit per screen (price stays visible) */
  .cgrid { grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 12px; }

  /* States — don't over-pad on a narrow viewport */
  .cmsg { padding: 36px 16px; }
  .cempty { padding: 40px 16px; }

  /* FAQ — section heading must not compete with the page h1 (20px) */
  .fac-faq { margin-top: 34px; }
  .fac-faq h2 { font-size: 17px; margin-bottom: 10px; }
}
</style>
