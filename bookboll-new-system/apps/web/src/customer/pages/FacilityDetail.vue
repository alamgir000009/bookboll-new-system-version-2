<script setup>
import { ref, onMounted, watch, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { selectedCountry } from '../../lib/customerSession.js'
import { loadFacility } from '../../customer/data.js'
import { useSeo, sportsActivityLocationLd } from '../../lib/seo.js'
import { cityForId, slugifySport, facilityPath } from '../../lib/cityPages.js'
import { isWhiteLabel } from '../../lib/brand.js'
import { ArrowLeft, MapPin, Building2, Users, ChevronRight, Navigation, Expand } from 'lucide-vue-next'
import Lightbox from '../components/Lightbox.vue'

const route = useRoute()
const router = useRouter()
const data = ref(null)
const loading = ref(true)
const cityInfo = ref(null)   // { name, slug } for "more {sport} in {city}" backlinks
const cur = computed(() => data.value?.location?.currency_override || selectedCountry.value?.currency_code || 'AED')

// Venue photo gallery (first = cover). activePhoto drives the hero; thumbnails switch it.
const activePhoto = ref(0)
const gallery = computed(() => data.value?.photos?.length ? data.value.photos : (data.value?.photo ? [data.value.photo] : []))
const heroPhoto = computed(() => gallery.value[activePhoto.value] || gallery.value[0] || '')
const lightbox = ref(false)   // full-screen photo viewer

async function load() {
  loading.value = true
  activePhoto.value = 0
  data.value = await loadFacility(route.params.id)
  cityInfo.value = data.value?.location?.city_id ? await cityForId(data.value.location.city_id) : null
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
    description: `Book courts and pitches at ${loc.name}${city ? ' in ' + city : ''}${sports ? ' (' + sports + ')' : ''}. Reserve by the hour on Bookboll.`,
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
  <div>
    <router-link to="/facilities" class="cback"><ArrowLeft :size="16" /> All venues</router-link>
    <div v-if="loading" class="cmsg">Loading…</div>
    <div v-else-if="!data" class="cmsg">Venue not found.</div>
    <template v-else>
      <div class="fd-hero" :class="{ 'has-photo': heroPhoto }" :style="heroPhoto ? { backgroundImage: `url(${heroPhoto})` } : {}"
        :role="heroPhoto ? 'button' : undefined" :aria-label="heroPhoto ? 'View photos full screen' : undefined"
        @click="heroPhoto && (lightbox = true)">
        <div v-if="!heroPhoto" class="fd-hero-fallback"><Building2 :size="48" /></div>
        <span v-else class="fd-hero-expand"><Expand :size="15" /></span>
      </div>
      <div v-if="gallery.length > 1" class="fd-thumbs">
        <button v-for="(p, i) in gallery" :key="i" type="button" class="fd-thumb"
          :class="{ on: i === activePhoto }" :style="{ backgroundImage: `url(${p})` }"
          @click="activePhoto = i" :aria-label="`Photo ${i + 1}`"></button>
      </div>
      <h1 class="fd-name">{{ data.location.name }}</h1>
      <button v-if="data.location.address" type="button" class="fd-addr fd-addr-link" @click="openMaps" :title="data.mapsUrl ? 'Open in Google Maps' : ''">
        <MapPin :size="16" /><span>{{ data.location.address }}</span><Navigation v-if="data.mapsUrl" :size="14" class="fd-addr-go" />
      </button>

      <h2 class="fd-h2">Courts & pitches</h2>
      <div v-if="!data.listings.length" class="cempty"><Building2 :size="34" /><span>No bookable courts listed yet.</span></div>
      <div v-else class="fd-courts">
        <router-link v-for="l in data.listings" :key="l.id" :to="`/listings/${l.id}`" class="ccard">
          <div class="ccard-img" :style="l.photo ? { backgroundImage: `url(${l.photo})` } : {}">
            <div v-if="!l.photo" class="ccard-img-fallback"><Building2 :size="28" /></div>
          </div>
          <div class="ccard-body">
            <div class="ccard-name">{{ l.name }}</div>
            <div class="ccard-meta">
              <span v-if="l.sport_category" class="ccard-tag">{{ l.sport_category }}</span>
              <span class="ccard-cap"><Users :size="12" /> up to {{ l.capacity || 1 }}</span>
            </div>
            <div class="ccard-foot">
              <span class="ccard-price">
                <template v-if="l.startPrice"><span class="ccard-from">from</span> {{ cur }} {{ Number(l.startPrice).toLocaleString() }}<span class="ccard-per"> / {{ l.slot_duration || 60 }} mins</span></template>
                <span v-else class="ccard-na">See prices</span>
              </span>
              <ChevronRight :size="16" class="ccard-arrow" />
            </div>
          </div>
        </router-link>
      </div>

      <div v-if="data.listings.length" class="fd-book">
        <div class="fd-book-txt">
          <strong>Ready to play?</strong>
          <span>Open any court above to see live availability and book your slot online.</span>
        </div>
      </div>

      <!-- Discover more in this city (internal links to the local landing pages) -->
      <div v-if="cityInfo" class="fd-more">
        <h2 class="fd-h2">More in {{ cityInfo.name }}</h2>
        <div class="fd-more-links">
          <router-link v-for="s in venueSports" :key="s.slug" :to="`/venues/${cityInfo.slug}/${s.slug}`" class="fd-more-link">
            {{ s.name }} venues in {{ cityInfo.name }} <ChevronRight :size="14" />
          </router-link>
          <router-link :to="`/venues/${cityInfo.slug}`" class="fd-more-link fd-more-all">
            All venues in {{ cityInfo.name }} <ChevronRight :size="14" />
          </router-link>
        </div>
      </div>
      <Lightbox v-if="lightbox" :images="gallery" :start="activePhoto" :alt="data.location.name" @close="lightbox = false" />
    </template>
  </div>
</template>

<style scoped>
.cback { display: inline-flex; align-items: center; gap: 6px; color: var(--brand); text-decoration: none; font-weight: 700; font-size: 14px; margin-bottom: 16px; }
.cmsg { text-align: center; padding: 50px; color: var(--text-light, #94a3b8); }
.cempty { display: flex; flex-direction: column; align-items: center; gap: 12px; padding: 40px; color: var(--text-light, #94a3b8); }
.fd-hero { height: 240px; border-radius: 20px; background-size: cover; background-position: center; background-color: var(--brand-accent); position: relative; }
.fd-hero.has-photo { cursor: zoom-in; }
.fd-hero-fallback { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; color: rgba(255,255,255,.85); }
.fd-hero-expand { position: absolute; bottom: 10px; inset-inline-end: 10px; width: 32px; height: 32px; border-radius: 10px; background: rgba(8,12,20,.55); color: #fff; display: flex; align-items: center; justify-content: center; pointer-events: none; }
.fd-thumbs { display: flex; gap: 8px; margin-top: 10px; overflow-x: auto; padding-bottom: 2px; }
.fd-thumb { flex: 0 0 auto; width: 78px; height: 58px; border-radius: 10px; background-size: cover; background-position: center; border: 2px solid transparent; cursor: pointer; padding: 0; opacity: .75; transition: border-color .15s, opacity .15s; }
.fd-thumb:hover { opacity: 1; }
.fd-thumb.on { border-color: var(--brand); opacity: 1; }
.fd-name { font-size: 28px; font-weight: 900; color: #0f172a; margin: 18px 0 8px; }
.fd-addr { display: flex; align-items: center; gap: 8px; color: var(--text-muted, #64748b); font-size: 14.5px; margin-bottom: 4px; }
.fd-addr-link { border: none; background: none; padding: 0; font: inherit; cursor: pointer; text-align: left; }
.fd-addr-link:hover { color: var(--brand); }
.fd-addr-go { color: var(--brand); }
.fd-h2 { font-size: 19px; font-weight: 800; color: #0f172a; margin: 26px 0 14px; }

.fd-courts { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 16px; }
.ccard { display: block; background: var(--surface, #ffffff); border: 1px solid var(--border, #e2e8f0); border-radius: 14px; overflow: hidden; text-decoration: none; transition: transform .12s, box-shadow .12s; }
.ccard:hover { transform: translateY(-3px); box-shadow: 0 12px 28px rgba(15,23,42,.12); }
/* width:100% is explicit so the cover always spans the fluid card (1-col on phones). */
.ccard-img { width: 100%; height: 130px; background-size: cover; background-position: center; background-color: var(--border, #e2e8f0); position: relative; }
.ccard-img-fallback { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; color: var(--text-light, #94a3b8); }
.ccard-body { padding: 12px 14px 13px; }
.ccard-name { font-size: 15.5px; font-weight: 800; color: #0f172a; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ccard-meta { display: flex; align-items: center; gap: 8px; margin-top: 6px; }
.ccard-tag { font-size: 11.5px; font-weight: 700; color: var(--brand); background: color-mix(in srgb, var(--brand) 18%, white); padding: 3px 9px; border-radius: 999px; text-transform: capitalize; }
.ccard-cap { display: flex; align-items: center; gap: 4px; font-size: 12px; color: var(--text-light, #94a3b8); }
.ccard-foot { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-top: 11px; padding-top: 10px; border-top: 1px solid var(--border, #e2e8f0); }
.ccard-price { font-size: 14.5px; font-weight: 800; color: #0f172a; white-space: nowrap; }
.ccard-from { font-size: 11px; font-weight: 600; color: var(--text-light, #94a3b8); }
.ccard-per { font-size: 11px; font-weight: 600; color: var(--text-light, #94a3b8); }
.ccard-na { font-size: 13px; font-weight: 700; color: var(--brand); }
.ccard-arrow { color: var(--text-muted, #64748b); flex-shrink: 0; }
.ccard:hover .ccard-arrow { color: var(--brand); }

.fd-book { display: flex; align-items: center; justify-content: space-between; gap: 16px; background: color-mix(in srgb, var(--brand) 8%, white); border: 1.5px solid #99f6e4; border-radius: 16px; padding: 18px 20px; margin-top: 24px; flex-wrap: wrap; }
.fd-book-txt { display: flex; flex-direction: column; gap: 3px; }
.fd-book-txt strong { font-size: 16px; color: #0f172a; }
.fd-book-txt span { font-size: 13.5px; color: #475569; }
.fd-book-btn { display: inline-flex; align-items: center; gap: 8px; background: var(--brand); color: #fff; font-weight: 800; padding: 12px 20px; border-radius: 999px; text-decoration: none; font-size: 15px; }
.fd-more { margin-top: 28px; }
.fd-more-links { display: flex; flex-wrap: wrap; gap: 8px; }
.fd-more-link { display: inline-flex; align-items: center; gap: 4px; font-size: 13.5px; font-weight: 700; color: #334155; background: var(--surface, #ffffff); border: 1.5px solid var(--border, #e2e8f0); border-radius: 999px; padding: 8px 14px; text-decoration: none; }
.fd-more-link:hover { border-color: var(--brand-accent); color: var(--brand); }
.fd-more-all { background: color-mix(in srgb, var(--brand) 8%, white); border-color: #99f6e4; color: var(--brand); }

/* ── Mobile (phone) — lift the court list (the primary action) toward the fold ── */
@media (max-width: 760px) {
  /* Trim the pre-hero run-up */
  .cback { margin-bottom: 10px; }

  /* Proper 16:9 photo proportions (not a thin strip), still compact.
     220px cap clears the ~202px 16:9 height at 360px wide (200px clipped it). */
  .fd-hero { height: auto; aspect-ratio: 16 / 9; max-height: 220px; border-radius: 14px; }

  /* Long unbroken address tokens must wrap, not push the page sideways */
  .fd-addr span { min-width: 0; overflow-wrap: anywhere; }

  /* Slimmer thumbnail strip (still horizontally scrollable) */
  .fd-thumbs { margin-top: 8px; }
  .fd-thumb { width: 64px; height: 48px; }

  /* Tighter heading run-up to the court grid */
  .fd-name { font-size: 22px; margin: 12px 0 6px; }
  .fd-h2 { font-size: 17px; margin: 18px 0 10px; }

  /* Single-column court list, denser cards → more tappable courts per screen */
  .fd-courts { grid-template-columns: 1fr; gap: 12px; }
  .ccard-img { height: 108px; }
  .ccard-body { padding: 10px 12px 11px; }

  /* Hide the non-actionable "Ready to play?" filler panel (no CTA, holds no action) */
  .fd-book { display: none; }

  /* Calmer SEO backlink cluster with ≥44px tap targets */
  .fd-more-links { gap: 8px; }
  .fd-more-link { padding: 13px 15px; font-size: 13px; min-height: 44px; }
}
</style>
