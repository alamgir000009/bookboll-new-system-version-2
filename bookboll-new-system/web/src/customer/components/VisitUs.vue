<script setup>
/**
 * VisitUs.vue — "Visit Us" section for a provider's white-label site: the
 * business's active venues, each with address, a compact schedule line
 * (derived from listing hours; falls back to the provider's typed hours)
 * and a Directions link. Used on the business home page AND /contact.
 */
import { ref, computed, onMounted } from 'vue'
import { loadBrandLocations } from '../../customer/data.js'
import { activeBrand, brandShows, brandSectionEnabled } from '../../lib/brand.js'
import { fmt12 } from '../../lib/slots.js'
import { facilityPath } from '../../lib/cityPages.js'
import { CalendarCheck, MapPin, Clock, Navigation } from 'lucide-vue-next'

const brand = computed(() => activeBrand.value || {})
const contact = computed(() => brand.value.pages?.contact || {})
const venues = ref([])

// One compact line from the venue's weekly hours: how many days it opens and the
// widest window across them ("Daily · 9:00 AM – 10:00 PM"). Falls back to the
// hours the provider typed on their Contact page when no schedule is set.
function scheduleLine(hours) {
  const openDays = (hours || []).filter(d => d.open && d.from && d.to)
  if (!openDays.length) return contact.value.hours || ''
  const from = openDays.map(d => d.from).sort()[0]
  const to = openDays.map(d => d.to).sort().slice(-1)[0]
  const span = openDays.length >= 7 ? 'Daily' : `${openDays.length} days/week`
  return `${span} · ${fmt12(from)} – ${fmt12(to)}`
}

onMounted(async () => {
  if (!brand.value.ownerUserId || !brandSectionEnabled('locations')) return
  const list = await loadBrandLocations()
  venues.value = list.map(loc => ({
    ...loc,
    google_maps_url: loc.mapsUrl || '',
    sched: scheduleLine(loc.hours),
  }))
})
</script>

<template>
  <section v-if="brandSectionEnabled('locations') && venues.length" class="vu">
    <div class="vu-head">
      <div>
        <h2 class="vu-h2">Our locations</h2>
        <p class="vu-sub">Our location{{ venues.length > 1 ? 's' : '' }} and opening hours.</p>
      </div>
      <router-link v-if="brandShows('venues')" to="/facilities" class="vu-book"><CalendarCheck :size="15" /> Book Now</router-link>
    </div>
    <div class="vu-grid">
      <div v-for="v in venues.slice(0, 4)" :key="v.id" class="vu-card">
        <span class="vu-ico"><MapPin :size="18" /></span>
        <div class="vu-body">
          <router-link v-if="brandShows('venues')" :to="{ path: facilityPath(v.id, v.name), query: { from: '/facilities?view=venues' } }" class="vu-name vu-venue-link">{{ v.name }}</router-link>
          <div v-else class="vu-name">{{ v.name }}</div>
          <div v-if="v.address" class="vu-addr">{{ v.address }}</div>
          <div v-if="v.sched" class="vu-hours"><Clock :size="13" /> {{ v.sched }}</div>
        </div>
        <a v-if="v.google_maps_url" :href="v.google_maps_url" target="_blank" rel="noopener" class="vu-dir"><Navigation :size="14" /> Directions</a>
      </div>
    </div>
  </section>
</template>

<style scoped>
.vu { background: color-mix(in srgb, var(--brand) 4%, var(--surface, white)); border-radius: 20px; padding: 24px 22px; }
.vu-head { display: flex; align-items: flex-end; justify-content: space-between; gap: 14px; margin-bottom: 16px; flex-wrap: wrap; }
.vu-h2 { font-size: 23px; font-weight: 900; color: var(--text, #0f172a); margin: 0; }
.vu-sub { font-size: 13.5px; color: var(--text-muted, #64748b); margin: 4px 0 0; }
.vu-book { display: inline-flex; align-items: center; gap: 8px; background: var(--brand); color: #fff; font-weight: 800; font-size: 13.5px; padding: 10px 18px; border-radius: 999px; text-decoration: none; }
.vu-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 300px), 1fr)); gap: 12px; }
.vu-card { display: grid; grid-template-columns: 40px minmax(0, 1fr); align-items: start; gap: 10px 12px; background: var(--surface, #ffffff); border: 1px solid var(--border, #e8edf3); border-radius: 14px; padding: 15px 16px; }
.vu-ico { width: 40px; height: 40px; border-radius: 12px; background: color-mix(in srgb, var(--brand) 10%, var(--surface, white)); color: var(--brand-ink, var(--brand)); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.vu-body { flex: 1; min-width: 0; }
.vu-name { font-size: 14.5px; font-weight: 800; color: var(--text, #0f172a); }
.vu-venue-link { display: inline-flex; min-height: 44px; align-items: center; text-decoration: none; }
.vu-venue-link:hover { text-decoration: underline; color: var(--brand-ink, var(--brand)); }
.vu-addr { font-size: 12.5px; color: var(--text-muted, #64748b); margin-top: 2px; }
.vu-hours { display: inline-flex; align-items: center; gap: 5px; font-size: 12.5px; font-weight: 700; color: var(--brand-ink, var(--brand)); margin-top: 6px; }
.vu-dir { grid-column: 2; display: inline-flex; align-items: center; gap: 6px; min-height: 44px; font-size: 13px; font-weight: 650; color: var(--brand-ink, var(--brand)); text-decoration: none; }
@media (max-width: 760px) { .vu { padding: 18px 16px; } .vu-h2 { font-size: 19px; } }
</style>
