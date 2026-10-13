<script setup>
/**
 * VisitUs.vue — "Visit Us" section for a provider's white-label site: the
 * business's active venues, each with address, a compact schedule line
 * (derived from listing hours; falls back to the provider's typed hours)
 * and a Directions link. Used on the business home page AND /contact.
 */
import { ref, computed, onMounted } from 'vue'
import { loadBrandLocations } from '../../customer/data.js'
import { activeBrand, brandShows } from '../../lib/brand.js'
import { fmt12 } from '../../lib/slots.js'
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
  if (!brand.value.ownerUserId) return
  const list = await loadBrandLocations()
  venues.value = list.map(loc => ({
    ...loc,
    google_maps_url: loc.mapsUrl || '',
    sched: scheduleLine(loc.hours),
  }))
})
</script>

<template>
  <section v-if="venues.length" class="vu">
    <div class="vu-head">
      <div>
        <h2 class="vu-h2">Visit Us</h2>
        <p class="vu-sub">Our location{{ venues.length > 1 ? 's' : '' }} and opening hours.</p>
      </div>
      <router-link v-if="brandShows('venues')" to="/facilities" class="vu-book"><CalendarCheck :size="15" /> Book Now</router-link>
    </div>
    <div class="vu-grid">
      <div v-for="v in venues.slice(0, 4)" :key="v.id" class="vu-card">
        <span class="vu-ico"><MapPin :size="18" /></span>
        <div class="vu-body">
          <div class="vu-name">{{ v.name }}</div>
          <div v-if="v.address" class="vu-addr">{{ v.address }}</div>
          <div v-if="v.sched" class="vu-hours"><Clock :size="13" /> {{ v.sched }}</div>
        </div>
        <a v-if="v.google_maps_url" :href="v.google_maps_url" target="_blank" rel="noopener" class="vu-dir"><Navigation :size="14" /> Directions</a>
      </div>
    </div>
  </section>
</template>

<style scoped>
.vu { background: color-mix(in srgb, var(--brand) 4%, white); border-radius: 20px; padding: 24px 22px; }
.vu-head { display: flex; align-items: flex-end; justify-content: space-between; gap: 14px; margin-bottom: 16px; flex-wrap: wrap; }
.vu-h2 { font-size: 23px; font-weight: 900; color: #0f172a; margin: 0; }
.vu-sub { font-size: 13.5px; color: var(--text-muted, #64748b); margin: 4px 0 0; }
.vu-book { display: inline-flex; align-items: center; gap: 8px; background: var(--brand); color: #fff; font-weight: 800; font-size: 13.5px; padding: 10px 18px; border-radius: 999px; text-decoration: none; }
.vu-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 12px; }
.vu-card { display: flex; align-items: flex-start; gap: 12px; background: var(--surface, #ffffff); border: 1px solid #e8edf3; border-radius: 14px; padding: 15px 16px; }
.vu-ico { width: 40px; height: 40px; border-radius: 12px; background: color-mix(in srgb, var(--brand) 10%, white); color: var(--brand); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.vu-body { flex: 1; min-width: 0; }
.vu-name { font-size: 14.5px; font-weight: 800; color: #0f172a; }
.vu-addr { font-size: 12.5px; color: var(--text-muted, #64748b); margin-top: 2px; }
.vu-hours { display: inline-flex; align-items: center; gap: 5px; font-size: 12.5px; font-weight: 700; color: var(--brand); margin-top: 6px; }
.vu-dir { display: inline-flex; align-items: center; gap: 5px; font-size: 12.5px; font-weight: 800; color: var(--brand); text-decoration: none; white-space: nowrap; flex-shrink: 0; margin-top: 2px; }
@media (max-width: 760px) { .vu { padding: 18px 16px; } .vu-h2 { font-size: 19px; } }
</style>
