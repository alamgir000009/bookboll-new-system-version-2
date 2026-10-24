<script setup>
import { ref, onMounted, watch, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { selectedCountry } from '../../lib/customerSession.js'
import { loadFacility } from '../../customer/data.js'
import { useSeo, sportsActivityLocationLd } from '../../lib/seo.js'
import { cityForId, slugifySport, facilityPath, slugifyCity } from '../../lib/cityPages.js'
import { isWhiteLabel } from '../../lib/brand.js'
import { ArrowLeft, MapPin, Building2, ChevronRight, Navigation, Images, CalendarCheck } from 'lucide-vue-next'
import Lightbox from '../components/Lightbox.vue'
import FacilityCard from '../components/FacilityCard.vue'
import ExpandableDescription from '../components/ExpandableDescription.vue'
import BrowseBreadcrumb from '../components/BrowseBreadcrumb.vue'
import { browseReturnPath } from '../../lib/browseCatalog.js'

const route = useRoute()
const router = useRouter()
const data = ref(null)
const loading = ref(true)
const cityInfo = ref(null)   // { name, slug } for "more {sport} in {city}" backlinks
const cur = computed(() => data.value?.location?.currency
  || data.value?.location?.currency_override
  || selectedCountry.value?.currency_code
  || '')

// Venue photo gallery (first = cover). activePhoto drives the hero; thumbnails switch it.
const activePhoto = ref(0)
const gallery = computed(() => [...new Set([...(data.value?.photos || []), data.value?.photo, ...(data.value?.listings || []).flatMap(l => [l.photo, ...(l.photos || [])])].filter(Boolean))])
const heroPhoto = computed(() => gallery.value[activePhoto.value] || gallery.value[0] || '')
const lightbox = ref(false)   // full-screen photo viewer

const selectedType = ref('')
const listingsHeading = ref(null)
function showListings() {
  listingsHeading.value?.scrollIntoView({ block: 'start', behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' })
  listingsHeading.value?.focus({ preventScroll: true })
}
const types = computed(() => [...new Set((data.value?.listings || []).map(l => l.sport_category).filter(Boolean))])
const visibleListings = computed(() => (data.value?.listings || [])
  .filter(l => !selectedType.value || l.sport_category === selectedType.value)
  .map(l => ({ ...l, photo: l.photo || l.photos?.[0], sport: l.sport_category, price: l.startPrice, slotMins: l.slot_duration || 60, pinText: data.value.location.name, locationId: data.value.location.id, currency: cur.value })))
async function load() {
  loading.value = true
  activePhoto.value = 0
  selectedType.value = ''
  data.value = await loadFacility(route.params.id)
  const loc = data.value?.location
  const cityName = loc?.city_name || loc?.city || ''
  cityInfo.value = cityName
    ? { name: cityName, slug: slugifyCity(cityName) }
    : (loc?.city_id ? await cityForId(loc.city_id, cityName) : null)
  loading.value = false
  // Canonical slug URL: /facilities/<id>/<name-slug>. Old bare-id links (and
  // stale slugs after a rename) are silently replaced — the id did the lookup.
  const canonical = data.value?.location?.name ? facilityPath(route.params.id, data.value.location.name) : ''
  if (canonical && route.path !== canonical && !route.path.startsWith('/ar/')) {
    router.replace({ path: canonical, query: route.query })
  }
}
onMounted(load)
watch(() => route.params.id, load)

// Distinct sports at this venue → links to the city × sport landing pages (marketplace only).
const venueSports = computed(() => {
  if (isWhiteLabel.value || !cityInfo.value) return []
  return [...new Set((data.value?.listings || []).map(l => l.sport_category).filter(Boolean))]
    .map(name => ({ name, slug: slugifySport(name) }))
})

useSeo(() => {
  const loc = data.value?.location
  if (!loc) return { path: route.path }
  const city = loc.city_name || loc.city || ''
  const sports = [...new Set((data.value?.listings || []).map(l => l.sport_category).filter(Boolean))].join(', ')
  const canonicalPath = facilityPath(route.params.id, loc.name)
  return {
    title: `${loc.name}${city ? ' — ' + city : ''}`,
    description: `Browse listings at ${loc.name}${city ? ' in ' + city : ''}${sports ? ' (' + sports + ')' : ''} and choose a time to book.`,
    image: data.value?.photo,
    path: canonicalPath,
    type: 'place',
    jsonLd: sportsActivityLocationLd({
      name: loc.name, description: loc.description, image: data.value?.photo,
      address: loc.address, city, country: selectedCountry.value?.name,
      lat: loc.latitude, lng: loc.longitude, priceRange: '$$',
      url: `https://bookboll.com${canonicalPath}`,
    }),
  }
})

function openMaps() { if (data.value?.mapsUrl) window.open(data.value.mapsUrl, '_blank', 'noopener') }
</script>

<template>
  <div class="venue-detail">
    <BrowseBreadcrumb :current="data?.location?.name || ''" />
    <div v-if="loading" class="venue-message" role="status">Loading venue…</div>
    <div v-else-if="!data" class="venue-message">Venue not found. <router-link to="/facilities">Browse all venues</router-link></div>
    <template v-else>
      <header class="venue-header">
        <div><span class="venue-label"><Building2 :size="15" /> Venue</span><h1>{{ data.location.name }}</h1><p v-if="data.location.address"><MapPin :size="16" />{{ data.location.address }}</p></div>
        <div class="venue-header-actions"><button v-if="data.listings.length" class="venue-book" @click="showListings">View listings <ChevronRight :size="16" /></button><button v-if="data.mapsUrl" class="venue-directions" @click="openMaps"><Navigation :size="16" /> Get directions</button></div>
      </header>
      <div v-if="gallery.length" class="venue-gallery" :class="{ 'venue-gallery-multi': gallery.length > 1 }">
        <button class="venue-cover" @click="lightbox = true" :aria-label="`View photos of ${data.location.name}`"><img :src="heroPhoto" :alt="data.location.name" /><span><Images :size="16" /> View {{ gallery.length }} {{ gallery.length === 1 ? 'photo' : 'photos' }}</span></button>
        <div v-if="gallery.length > 1" class="venue-preview"><button v-for="(photo, i) in gallery.slice(1, 3)" :key="photo" :aria-label="`View venue photo ${i + 2}`" @click="activePhoto = i + 1; lightbox = true"><img :src="photo" alt="" loading="lazy" /></button></div>
      </div>
      <div class="venue-overview"><div class="venue-facts"><span><Building2 :size="18" /><strong>{{ data.listings.length }}</strong> {{ data.listings.length === 1 ? 'bookable listing' : 'bookable listings' }}</span><span v-if="types.length"><CalendarCheck :size="18" /><strong>{{ types.length }}</strong> {{ types.length === 1 ? 'activity' : 'activities' }}</span></div><section v-if="data.location.description" class="venue-about"><h2>About the venue</h2><ExpandableDescription :text="data.location.description" id="venue-description" /></section></div>
      <section class="venue-listings" aria-label="Listings at this venue">
        <div class="venue-section-heading"><div><h2 ref="listingsHeading" tabindex="-1">Choose a listing <span>{{ data.listings.length }}</span></h2><p>Compare listings, then pick a time that works for you.</p></div></div>
        <div v-if="types.length > 1" class="venue-types" aria-label="Filter venue listings"><button :aria-pressed="!selectedType" :class="{ active: !selectedType }" @click="selectedType = ''">All listings</button><button v-for="type in types" :key="type" :aria-pressed="selectedType === type" :class="{ active: selectedType === type }" @click="selectedType = type">{{ type }}</button></div>
        <div v-if="visibleListings.length" class="venue-grid"><FacilityCard v-for="listing in visibleListings" :key="listing.id" :facility="listing" :return-to="browseReturnPath(route.query.from)" /></div>
        <p v-else class="venue-message">No listings are available to book here yet.</p>
      </section>
      <div v-if="cityInfo && !isWhiteLabel" class="venue-more"><h2>Keep exploring</h2><router-link :to="browseReturnPath(route.query.from)"><ArrowLeft :size="16" /> Back to results</router-link><router-link :to="`/venues/${cityInfo.slug}`">All listings in {{ cityInfo.name }} <ChevronRight :size="16" /></router-link><router-link v-for="sport in venueSports" :key="sport.slug" :to="`/venues/${cityInfo.slug}/${sport.slug}`">{{ sport.name }} in {{ cityInfo.name }} <ChevronRight :size="16" /></router-link></div>
      <Lightbox v-if="lightbox" :images="gallery" :start="activePhoto" :alt="data.location.name" @close="lightbox = false" />
    </template>
  </div>
</template>

<style scoped>
.venue-detail { min-width: 0; }
.venue-header { display: flex; justify-content: space-between; align-items: center; gap: 20px; margin-bottom: 24px; }
.venue-label { display: flex; align-items: center; gap: 6px; color: var(--brand-ink, var(--brand)); font-size: 13px; font-weight: 600; margin-bottom: 8px; }
.venue-header h1 { font-size: clamp(25px, 3vw, 34px); font-weight: 700; letter-spacing: -.6px; margin-bottom: 10px; }
.venue-header p { display: flex; align-items: flex-start; gap: 6px; font-size: 14px; color: var(--text-muted); max-width: 650px; }
.venue-header p svg { flex-shrink: 0; margin-top: 2px; }
.venue-directions { display: flex; align-items: center; gap: 8px; min-height: 44px; padding: 12px 16px; border: 1px solid var(--border); border-radius: 12px; background: var(--surface); font-weight: 600; white-space: nowrap; }
.venue-gallery { margin-bottom: 30px; }
.venue-cover { display: block; position: relative; width: 100%; height: 280px; overflow: hidden; border-radius: 18px; background: var(--border); }
.venue-cover > img { width: 100%; height: 100%; object-fit: cover; }
.venue-cover > span { position: absolute; inset-inline-end: 14px; bottom: 14px; display: flex; align-items: center; gap: 8px; padding: 10px 14px; color: var(--text); background: var(--surface); border-radius: 10px; font-size: 13px; font-weight: 600; }
.venue-thumbnails { display: flex; gap: 10px; overflow-x: auto; padding: 5px 3px; margin-top: 8px; }
.venue-thumbnails button { width: 74px; height: 54px; border-radius: 9px; overflow: hidden; flex-shrink: 0; }
.venue-thumbnails img { width: 100%; height: 100%; object-fit: cover; }
.venue-thumbnails button.active { outline: 2px solid var(--brand); outline-offset: 2px; }
.venue-section-heading { margin-bottom: 20px; }
.venue-section-heading h2 { font-size: 23px; font-weight: 700; display: flex; align-items: center; gap: 10px; }
.venue-section-heading h2 span { font-size: 13px; background: var(--border); border-radius: 8px; padding: 4px 8px; }
.venue-section-heading p { color: var(--text-muted); font-size: 14px; margin-top: 6px; }
.venue-types { display: flex; gap: 8px; overflow-x: auto; margin-bottom: 18px; padding-block: 3px; }
.venue-types button { min-height: 44px; padding: 10px 18px; border: 1px solid var(--border); border-radius: 11px; white-space: nowrap; font-weight: 600; background: var(--surface); }
.venue-types button.active { background: var(--brand); border-color: var(--brand); color: white; }
.venue-grid { display: grid; grid-template-columns: repeat(3,minmax(0,1fr)); gap: 18px; }
.venue-grid :deep(.fc) { width: 100%; }
.venue-more { border-top: 1px solid var(--border); margin-top: 36px; padding-top: 22px; display: flex; flex-wrap: wrap; gap: 8px 20px; }
.venue-more h2 { width: 100%; font-size: 20px; margin-bottom: 6px; }
.venue-more a { display: flex; align-items: center; gap: 6px; font-size: 14px; color: var(--brand-ink, var(--brand)); min-height: 44px; }
.venue-message { text-align: center; padding: 44px 16px; color: var(--text-muted); }
.venue-detail button:focus-visible, .venue-detail a:focus-visible { outline: 2px solid var(--brand); outline-offset: 3px; }
@media(max-width:1024px) { .venue-grid { grid-template-columns: repeat(2,minmax(0,1fr)); } }
@media(max-width:760px) {
  .venue-header { flex-direction: column; align-items: flex-start; gap: 12px; margin-bottom: 18px; }
  .venue-header h1 { font-size: 25px; }
  .venue-cover { height: 190px; border-radius: 14px; }
  .venue-thumbnails { display: none; }
  .venue-gallery { margin-bottom: 24px; }
  .venue-section-heading h2 { font-size: 20px; }
  .venue-grid { grid-template-columns: 1fr; gap: 12px; }
  .venue-grid :deep(.fc) { display: grid; grid-template-columns: clamp(132px, 36%, 220px) minmax(0,1fr); }
  .venue-grid :deep(.fc-img) { height: 100%; min-height: 145px; max-height: none; aspect-ratio: auto; }
  .venue-grid :deep(.fc-body) { padding: 12px; }
  .venue-grid :deep(.fc-price) { flex-wrap: wrap; }
  .venue-grid :deep(.fc-title) { font-size: 14px; }
}

.venue-header { align-items: flex-start; margin-bottom: 26px; }
.venue-header > div:first-child { max-width: 820px; }
.venue-header h1 { line-height: 1.2; font-size: clamp(25px,3vw,36px); letter-spacing: -.8px; }
.venue-label { color: var(--text-muted); font-size: 12px; margin-bottom: 10px; }
.venue-header-actions { display: flex; align-items: center; flex-direction: column; gap: 8px; flex-shrink: 0; }
.venue-book { display: flex; align-items: center; justify-content: center; gap: 12px; padding: 13px 20px; min-height: 46px; border-radius: 10px; background: var(--brand); color: white; font-size: 14px; font-weight: 650; width: 100%; }
.venue-directions { font-size: 13px; border: 0; background: transparent; color: var(--brand-ink, var(--brand)); }
.venue-gallery { display: grid; gap: 10px; margin-bottom: 0; border-radius: 18px; overflow: hidden; }
.venue-gallery-multi { grid-template-columns: minmax(0,2fr) minmax(0,1fr); }
.venue-cover { height: 350px; border-radius: 0; }
.venue-cover > span { background: #101827db; color: white; border: 1px solid #ffffff40; }
.venue-preview { display: flex; flex-direction: column; gap: 10px; min-width: 0; max-height: 350px; }
.venue-preview button { flex: 1; min-height: 0; background: var(--bg); }
.venue-preview img { width: 100%; height: 100%; object-fit: cover; display: block; }
.venue-overview { margin-bottom: 32px; }
.venue-facts { display: flex; flex-wrap: wrap; gap: 18px 32px; padding: 22px 0; border-bottom: 1px solid var(--border); }
.venue-facts > span { display: flex; align-items: center; gap: 7px; color: var(--text-muted); font-size: 14px; }
.venue-facts svg { color: var(--brand-ink, var(--brand)); margin-inline-end: 2px; }
.venue-facts strong { color: var(--text); font-weight: 650; }
.venue-about { max-width: 760px; padding-top: 24px; }
.venue-about h2 { font-size: 20px; font-weight: 650; margin-bottom: 12px; }
.venue-section-heading h2 { font-size: 25px; letter-spacing: -.5px; scroll-margin-top: calc(var(--customer-sticky-offset, 90px) + 14px); }
.venue-section-heading h2:focus { outline: none; }
.venue-types { scrollbar-width: none; gap: 10px; margin-bottom: 24px; }
.venue-types button { font-size: 13px; padding: 10px 16px; }
.venue-types button.active { background: color-mix(in srgb, var(--brand) 10%, var(--surface)); border-color: var(--brand); color: var(--brand-ink, var(--brand)); }
.venue-grid { gap: 22px; }
@media(max-width:760px) {
  .venue-header { gap: 18px; margin-bottom: 18px; }
  .venue-header h1 { font-size: 25px; letter-spacing: -.5px; }
  .venue-header-actions { flex-direction: row; flex-wrap: wrap; width: 100%; gap: 12px; }
  .venue-book { width: auto; padding: 12px 16px; font-size: 13px; }
  .venue-directions { padding: 12px 0; font-size: 12px; }
  .venue-gallery { display: none; }
  .venue-facts { font-size: 12px; gap: 18px; padding: 18px 0; }
  .venue-facts > span { font-size: 12px; }
  .venue-overview { margin-bottom: 26px; }
  .venue-section-heading h2 { font-size: 22px; }
  .venue-section-heading p { font-size: 13px; line-height: 1.6; }
  .venue-grid { gap: 14px; }
}

</style>
