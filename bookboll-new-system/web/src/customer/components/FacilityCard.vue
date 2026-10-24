<script setup>
import { computed } from 'vue'
import { Building2, MapPin, ChevronRight, Check } from 'lucide-vue-next'
import { userCoords } from '../../lib/customerSession.js'
import { haversineKm, formatDistance } from '../../lib/distance.js'
import { facilityPath } from '../../lib/cityPages.js'
import { durationLabel } from '../../lib/slots.js'
const props = defineProps({
  facility: { type: Object, required: true },
  variant: { type: String, default: 'listing' },   // 'listing' | 'location'
  selectable: { type: Boolean, default: false },    // create-event selection mode (no navigation)
  selected: { type: Boolean, default: false },
  returnTo: { type: String, default: '' },
  filteredMatches: Boolean,
})
const emit = defineEmits(['select'])
const img = computed(() => props.facility.photo || '')
const cur = computed(() => props.facility.currency || '')
const hasPrice = computed(() => props.facility.price != null && props.facility.price >= 0)
const pinLabel = computed(() => props.facility.pinText || props.facility.cityName || props.facility.name)
const isLocation = computed(() => props.variant === 'location')
// "X km away" — only when the visitor has shared their location AND the venue is pinned.
const distance = computed(() => {
  const u = userCoords.value
  if (!u || props.facility.lat == null || props.facility.lng == null) return ''
  return formatDistance(haversineKm(u.lat, u.lng, props.facility.lat, props.facility.lng))
})
// In browse mode the card is a link; in select mode it's a clickable div that emits.
const rootTag = computed(() => props.selectable ? 'div' : 'router-link')
const rootBind = computed(() => props.selectable
  ? { role: 'button', tabindex: 0, onClick: () => emit('select'), onKeydown: (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); emit('select') } } }
  // Listing cards open the bookable space; venue cards open the parent venue.
  // only the "Venues" (location) card opens the venue page first.
  : { to: { path: isLocation.value
      ? facilityPath(props.facility.locationId || props.facility.id, props.facility.venueName || props.facility.name)
      : `/listings/${props.facility.id}`, query: props.returnTo ? { from: props.returnTo } : {} } })
</script>

<template>
  <component :is="rootTag" v-bind="rootBind" class="fc" :class="{ 'fc-on': selectable && selected }">
    <div class="fc-img" :class="{ 'fc-noimg': !img }" :style="img ? { backgroundImage: `url(${img})` } : {}">
      <div v-if="!img" class="fc-img-fallback"><span>{{ (facility.name || '?').trim().charAt(0).toUpperCase() }}</span><Building2 :size="18" class="fc-img-glyph" /></div>
      <div v-if="selectable && selected" class="fc-check"><Check :size="16" /></div>
      <!-- Proximity pill (only when the visitor has shared their location). -->
      <span v-if="distance" class="fc-dist"><MapPin :size="11" /> {{ distance }}</span>
    </div>

    <div class="fc-body">
      <!-- Title -->
      <template v-if="isLocation">
        <span class="fc-kind">Venue<span v-if="facility.listingCount"> · {{ facility.listingCount }} {{ filteredMatches ? 'matching ' : '' }}{{ facility.listingCount === 1 ? 'listing' : 'listings' }}</span></span>
        <h3 class="fc-title"><span class="fc-name">{{ facility.name }}</span></h3>
        <div class="fc-meta">
          <div class="fc-sub"><span class="fc-ellipsis">{{ facility.sportsText || 'Various' }}</span></div>
        </div>
      </template>
      <template v-else>
        <span v-if="facility.sport" class="fc-kind">{{ facility.sport }}</span>
        <h3 class="fc-title"><span class="fc-name">{{ facility.name }}</span></h3>
        <!-- Show the venue alongside each listing so similar courts are distinguishable. -->
        <div class="fc-meta">
          <div class="fc-sub"><MapPin :size="12" /><span class="fc-ellipsis">{{ pinLabel }}</span></div>
        </div>
      </template>

      <!-- Price footer -->
      <div class="fc-price">
        <template v-if="hasPrice">
          <span class="fc-from">from</span>
          <span class="fc-amt">{{ cur }} {{ Number(facility.price).toLocaleString() }}</span>
          <span v-if="!isLocation && facility.slotMins" class="fc-per">/ {{ durationLabel(facility.slotMins) }}</span>
        </template>
        <span v-else class="fc-amt-na">Tap to see prices</span>
        <ChevronRight :size="16" class="fc-arrow" />
      </div>
    </div>
  </component>
</template>

<style scoped>
.fc { display: flex; flex-direction: column; width: 260px; background: var(--surface, #ffffff); border: 1px solid var(--border, #e2e8f0); border-radius: 16px; overflow: hidden; text-decoration: none; }
.fc:hover { border-color: var(--brand); }
.fc:focus-visible { outline: 3px solid var(--brand); outline-offset: 3px; }
.fc-kind { display: block; font-size: 12px; font-weight: 600; color: var(--brand-ink, var(--brand)); margin-bottom: 6px; }
.fc-name { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; line-height: 1.4; }
.fc[role="button"] { cursor: pointer; }
.fc-on { border-color: var(--brand); box-shadow: 0 0 0 3px color-mix(in srgb, var(--brand) 18%, white); }
/* width:100% pins the cover to the card edge: with an auto inline size, the mobile
   aspect-ratio + max-height pair below lets some engines transfer the 160px height
   cap back to the width (cover renders ~284px inside a wider full-width tile =
   uncovered strip on the card's right). A definite width means aspect-ratio only
   ever derives the HEIGHT, in every engine. */
.fc-img { width: 100%; aspect-ratio: 16 / 9; background-size: cover; background-position: center; background-color: var(--border, #e2e8f0); position: relative; }
.fc-img.fc-noimg { background: var(--brand); }
.fc-img-fallback { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; color: #fff; }
.fc-img-fallback span { font-size: 44px; font-weight: 900; color: rgba(255,255,255,.95); line-height: 1; }
.fc-img-glyph { position: absolute; right: 10px; bottom: 10px; color: rgba(255,255,255,.55); }
.fc-check { position: absolute; top: 8px; right: 8px; width: 28px; height: 28px; border-radius: 50%; background: var(--brand); color: #fff; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 6px rgba(15,118,110,.4); }
.fc-body { padding: 17px; min-width: 0; flex: 1; display: flex; flex-direction: column; }
.fc-ellipsis { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; min-width: 0; }

/* Title (listing name, or location name as map link) */
.fc-title { display: flex; align-items: center; gap: 5px; font-size: 16px; font-weight: 700; color: var(--text); max-width: 100%; margin: 0; }
.fc-title-pin { color: var(--brand-ink, var(--brand)); flex-shrink: 0; }
.fc-title-link { border: none; background: none; padding: 0; font-family: inherit; cursor: pointer; text-align: left; max-width: 100%; }
.fc-title-link:hover { color: var(--brand-ink, var(--brand)); }
.fc-title-link:hover .fc-ellipsis { text-decoration: underline; }

/* Secondary line (venue pin for listings, sports for locations) + distance chip */
.fc-meta { display: flex; align-items: center; gap: 6px; margin-top: 6px; margin-bottom: auto; max-width: 100%; }
.fc-sub { display: flex; align-items: center; gap: 4px; font-size: 13px; color: var(--text-muted); flex: 1; min-width: 0; }
.fc-sub svg { flex-shrink: 0; }
.fc-dist { position: absolute; right: 10px; bottom: 10px; display: inline-flex; align-items: center; gap: 4px; font-size: 11px; font-weight: 800; color: #fff; background: rgba(15,23,42,.72); backdrop-filter: blur(4px); border-radius: 999px; padding: 4px 9px; }
.fc-dist svg { flex-shrink: 0; }
.fc-sub-link { border: none; background: none; padding: 0; font-family: inherit; cursor: pointer; text-align: left; }
.fc-sub-link:hover { color: var(--brand-ink, var(--brand)); }
.fc-sub-link:hover .fc-ellipsis { text-decoration: underline; }

/* Price footer */
.fc-price { display: flex; align-items: baseline; gap: 6px; margin-top: 11px; padding-top: 11px; border-top: 1px solid var(--border, #e2e8f0); }
.fc-from { font-size: 12px; font-weight: 500; color: var(--text-muted, #64748b); }
.fc-amt { font-size: 17px; font-weight: 700; color: var(--text); }
.fc-per { font-size: 12px; font-weight: 500; color: var(--text-muted, #64748b); }
.fc-amt-na { font-size: 13.5px; font-weight: 700; color: var(--brand-ink, var(--brand)); }
.fc-arrow { margin-left: auto; align-self: center; color: var(--text-muted, #64748b); flex-shrink: 0; }
.fc:hover .fc-arrow { color: var(--brand-ink, var(--brand)); }

/* Mobile: on phones every grid parent collapses this card to a full-width 1-col
   tile, so the tall 150px photo + roomy body lets only ~2-3 venues show per screen.
   Shrink the image and tighten the body so more cards (= tap targets) fit above the
   fold, while bumping the essential text (title/price) for the wider phone card.
   NOTE: .fc width is intentionally left alone — see FacilityCard consumers (home rail). */
@media (max-width: 760px) {
  /* Proportional 16:9 crop instead of a fixed height: full-width grid tiles were
     rendering the photo as a ~2.9:1 letterbox strip. Rail cards (260px) stay dense
     (~146px tall); full-width tiles cap at 160px — a deliberate banner crop. */
  .fc-img { height: auto; aspect-ratio: 16 / 9; max-height: 160px; }
  .fc-img-fallback span { font-size: 36px; font-weight: 800; }
  /* Hide the decorative corner glyph: it stacked under the distance pill (same
     corner) and the monogram letter already signals "no photo". */
  .fc-img-glyph { display: none; }
  .fc-body { padding: 11px 13px 12px; }
  /* Quieter price row on the 2-up browse grid — it's a hint, not a headline */
  .fc-price { margin-top: 9px; padding-top: 9px; }
  .fc-amt { font-size: 14px; }
  .fc-from, .fc-per { font-size: 11px; }
  .fc-amt-na { font-size: 12.5px; }
  /* Enlarge the map-link title's hit area so it doesn't butt against the price row
     or misfire against the card's own tap target (CSS padding only — no markup change). */
  .fc-title-link { padding: 4px 0; }
  .fc-title { font-size: 15.5px; }
  .fc-sub { font-size: 13px; }
}
</style>
