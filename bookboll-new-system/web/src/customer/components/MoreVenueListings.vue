<script setup>
import { computed, ref, watch, onBeforeUnmount } from 'vue'
import { loadFacility } from '../data.js'
import { facilityPath } from '../../lib/cityPages.js'
import FacilityCard from './FacilityCard.vue'
import { ArrowRight } from 'lucide-vue-next'

const props = defineProps({
  venue: { type: Object, required: true },
  listingId: { type: [String, Number], required: true },
  currency: { type: String, required: true },
  returnTo: { type: String, default: '/facilities' },
})
const facility = ref(null)
const loading = ref(false)
const failed = ref(false)
let request = 0
const venueLink = computed(() => ({ path: facilityPath(props.venue.id, props.venue.name), query: { from: props.returnTo } }))
const suggestions = computed(() => (facility.value?.listings || [])
  .filter(listing => String(listing.id) !== String(props.listingId))
  .map(listing => ({
    ...listing,
    photo: listing.photo || listing.photos?.[0] || '',
    sport: listing.sport_category,
    price: listing.startPrice,
    slotMins: listing.slot_duration || 60,
    pinText: props.venue.name,
    locationId: props.venue.id,
    currency: facility.value?.location?.currency_override || props.currency,
  })))
async function load() {
  const current = ++request
  facility.value = null
  loading.value = true
  failed.value = false
  try {
    const result = await loadFacility(props.venue.id)
    if (current !== request) return
    facility.value = result
    failed.value = !Array.isArray(result?.listings)
  } catch {
    if (current === request) failed.value = true
  } finally {
    if (current === request) loading.value = false
  }
}
watch(() => props.venue.id, load, { immediate: true })
onBeforeUnmount(() => { request++ })
</script>

<template>
  <section v-if="loading || failed || suggestions.length" class="listing-related" aria-labelledby="more-venue-listings-title">
    <div class="related-heading">
      <div><h2 id="more-venue-listings-title">More listings from this venue</h2><p>{{ venue.name }}</p></div>
      <router-link :to="venueLink">View venue <ArrowRight :size="16" /></router-link>
    </div>
    <p v-if="loading" class="related-status" role="status">Loading more listings…</p>
    <div v-else-if="failed" class="related-status" role="status">Other listings couldn’t load. <button type="button" @click="load">Try again</button></div>
    <div v-else class="related-grid" aria-label="Other listings at this venue">
      <FacilityCard v-for="listing in suggestions.slice(0, 6)" :key="listing.id" :facility="listing" :return-to="returnTo" />
    </div>
  </section>
</template>

<style scoped>
.listing-related { margin-top: 36px; padding-top: 30px; border-top: 1px solid var(--border); }
.related-heading { display: flex; justify-content: space-between; align-items: flex-start; gap: 18px; margin-bottom: 20px; }
.related-heading > div { min-width: 0; }
.related-heading h2 { font-size: 24px; font-weight: 650; line-height: 1.3; letter-spacing: -.4px; margin: 0 0 8px; }
.related-heading p { color: var(--text-muted); font-size: 13px; line-height: 1.6; margin: 0; }
.related-heading a { display: flex; align-items: center; gap: 7px; flex-shrink: 0; min-height: 44px; color: var(--brand-ink, var(--brand)); font-size: 13px; font-weight: 600; text-decoration: none; }
.related-grid { display: grid; grid-template-columns: repeat(3, minmax(0,1fr)); gap: 18px; }
.related-grid :deep(.fc) { width: 100%; }
.related-status { color: var(--text-muted); font-size: 14px; line-height: 1.6; }
.related-status button { color: var(--brand-ink, var(--brand)); font-weight: 600; min-height: 44px; padding-inline: 10px; }
.listing-related a:focus-visible, .listing-related button:focus-visible { outline: 2px solid var(--brand-ink, var(--brand)); outline-offset: 3px; }
@media (min-width: 761px) and (max-width: 1000px) { .related-grid { grid-template-columns: repeat(2, minmax(0,1fr)); } }
@media (max-width: 760px) {
  .listing-related { margin-top: 28px; padding-top: 24px; }
  .related-heading { flex-direction: column; gap: 2px; margin-bottom: 12px; }
  .related-heading h2 { font-size: 21px; }
  .related-grid { display: flex; gap: 14px; overflow-x: auto; scroll-snap-type: x mandatory; padding: 3px 3px 12px; margin-inline: -3px; }
  .related-grid :deep(.fc) { flex: 0 0 min(280px, calc(100vw - 68px)); scroll-snap-align: start; }
}
</style>
