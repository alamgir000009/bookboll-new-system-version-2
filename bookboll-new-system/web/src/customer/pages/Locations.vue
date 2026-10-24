<script setup>
/**
 * Locations.vue — customer-facing "Our locations" for a provider's white-label storefront.
 * Lists the brand's venues; clicking one reveals its weekly schedule + the listings/services
 * inside, each linking to its booking page. Brand-scoped via loadBrandLocations() (apex uses
 * the /venues directory instead, so this is a white-label-only page).
 */
import { ref, computed, onMounted } from 'vue'
import { loadBrandLocations } from '../../customer/data.js'
import { selectedCountry } from '../../lib/customerSession.js'
import { activeBrand, isWhiteLabel } from '../../lib/brand.js'
import { useSeo } from '../../lib/seo.js'
import { MapPin, Clock, ChevronDown, ArrowRight, Search, ExternalLink, Building2, Ticket } from 'lucide-vue-next'

useSeo(() => ({ title: `Our locations${activeBrand.value?.name ? ' · ' + activeBrand.value.name : ''}`, path: '/locations' }))

const locations = ref([])
const loading = ref(true)
const openId = ref(null)
const q = ref('')

const currency = computed(() => selectedCountry.value?.currency_code || '')
const filtered = computed(() => {
  const s = q.value.trim().toLowerCase()
  if (!s) return locations.value
  return locations.value.filter(l =>
    l.name.toLowerCase().includes(s) || (l.cityName || '').toLowerCase().includes(s) || (l.sportsText || '').toLowerCase().includes(s))
})
function toggle(id) { openId.value = openId.value === id ? null : id }

async function load() {
  loading.value = true
  locations.value = await loadBrandLocations()
  loading.value = false
  if (filtered.value.length === 1) openId.value = filtered.value[0].id   // auto-open a single venue
}
onMounted(load)
</script>

<template>
  <div class="loc cust-page">
    <header class="loc-hero">
      <h1>Our locations</h1>
      <p>Explore {{ isWhiteLabel && activeBrand ? activeBrand.name + '’s' : 'our' }} venues, see opening hours and browse available listings.</p>
    </header>

    <div v-if="locations.length > 3" class="loc-search">
      <Search :size="16" />
      <input v-model="q" placeholder="Search by venue, city or sport…" />
    </div>

    <div v-if="loading" class="loc-empty">Loading locations…</div>
    <div v-else-if="!locations.length" class="loc-empty">
      <Building2 :size="30" />
      <div>No locations to show yet.</div>
    </div>
    <div v-else-if="!filtered.length" class="loc-empty">No venues match “{{ q }}”.</div>

    <div v-else class="loc-list">
      <div v-for="l in filtered" :key="l.id" class="loc-card" :class="{ open: openId === l.id }">
        <button type="button" class="loc-head" :aria-expanded="openId === l.id" :aria-controls="`location-${l.id}`" @click="toggle(l.id)">
          <div class="loc-thumb" :style="l.photo ? { backgroundImage: `url(${l.photo})` } : {}">
            <Building2 v-if="!l.photo" :size="22" />
          </div>
          <div class="loc-main">
            <div class="loc-name">{{ l.name }}</div>
            <div class="loc-meta">
              <span v-if="l.address || l.cityName"><MapPin :size="12" /> {{ [l.address, l.cityName].filter(Boolean).join(', ') }}</span>
            </div>
            <div class="loc-tags">
              <span v-if="l.sportsText" class="loc-tag">{{ l.sportsText }}</span>
              <span class="loc-tag muted">{{ l.listings.length }} {{ l.listings.length === 1 ? 'listing' : 'listings' }}</span>
              <span v-if="l.price != null" class="loc-tag price">from {{ currency }} {{ l.price }}</span>
            </div>
          </div>
          <ChevronDown :size="20" class="loc-chev" />
        </button>

        <div v-if="openId === l.id" :id="`location-${l.id}`" class="loc-body">
          <div class="loc-cols">
            <!-- Schedule -->
            <div class="loc-block">
              <div class="loc-block-h"><Clock :size="14" /> Opening hours</div>
              <div v-if="l.hours && l.hours.length" class="loc-sched">
                <div v-for="d in l.hours" :key="d.day" class="loc-srow" :class="{ closed: !d.open }">
                  <span class="loc-day">{{ d.day }}</span>
                  <span class="loc-time">{{ d.label }}</span>
                </div>
              </div>
              <div v-else class="loc-nohours">Hours not set.</div>
              <a v-if="l.mapsUrl" :href="l.mapsUrl" target="_blank" rel="noopener" class="loc-maps"><MapPin :size="13" /> Get directions <ExternalLink :size="12" /></a>
            </div>

            <!-- Listings / services -->
            <div class="loc-block">
              <div class="loc-block-h"><Ticket :size="14" /> Available to book</div>
              <div v-if="l.listings.length" class="loc-svc-list">
                <router-link v-for="s in l.listings" :key="s.id" :to="`/listings/${s.id}`" class="loc-svc">
                  <div class="loc-svc-thumb" :style="s.photo ? { backgroundImage: `url(${s.photo})` } : {}"></div>
                  <div class="loc-svc-main">
                    <div class="loc-svc-name">{{ s.name }}</div>
                    <div class="loc-svc-sub">
                      <span v-if="s.sport">{{ s.sport }}</span>
                      <span v-if="s.price != null" class="loc-svc-price">from {{ currency }} {{ s.price }}</span>
                    </div>
                  </div>
                  <ArrowRight :size="16" class="loc-svc-go" />
                </router-link>
              </div>
              <div v-else class="loc-nohours">No bookable listings here yet.</div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.loc { max-width: 920px; margin: 0 auto; }
.loc-hero { margin-bottom: 18px; }
.loc-hero h1 { font-size: 26px; font-weight: 900; color: var(--text, #0f172a); margin: 0; }
.loc-hero p { font-size: 14.5px; color: var(--text-muted, #64748b); margin: 6px 0 0; }
.loc-search { display: flex; align-items: center; gap: 9px; background: var(--surface, #ffffff); border: 1px solid var(--border, #e2e8f0); border-radius: 12px; padding: 10px 14px; color: var(--text-light, #94a3b8); margin-bottom: 16px; }
.loc-search input { flex: 1; border: none; outline: none; font: inherit; font-size: 15px; background: transparent; color: var(--text, #0f172a); }
.loc-empty { display: flex; flex-direction: column; align-items: center; gap: 10px; padding: 40px 16px; color: var(--text-light, #94a3b8); font-size: 14.5px; text-align: center; }

.loc-list { display: flex; flex-direction: column; gap: 12px; }
.loc-card { border: 1px solid var(--border, #e2e8f0); border-radius: 16px; background: var(--surface, #ffffff); overflow: hidden; transition: border-color .12s, box-shadow .12s; }
.loc-card.open { border-color: var(--brand-accent, #14b8a6); box-shadow: 0 10px 30px rgba(15,23,42,.07); }
.loc-head { width: 100%; display: flex; align-items: center; gap: 15px; padding: 16px 18px; background: none; border: none; cursor: pointer; text-align: left; }
.loc-head:hover { background: var(--canvas, #f8fafc); }
.loc-thumb { width: 60px; height: 60px; border-radius: 13px; background: var(--bg, #f1f5f9); background-size: cover; background-position: center; color: var(--brand, #0f766e); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.loc-main { flex: 1; min-width: 0; }
.loc-name { font-size: 17px; font-weight: 800; color: var(--text, #0f172a); }
.loc-meta { font-size: 13px; color: var(--text-muted, #64748b); margin-top: 2px; }
.loc-meta span { display: inline-flex; align-items: center; gap: 5px; }
.loc-meta svg { color: var(--text-light, #94a3b8); }
.loc-tags { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
.loc-tag { font-size: 11.5px; font-weight: 700; color: var(--text-secondary, #334155); background: var(--bg, #f1f5f9); border-radius: 999px; padding: 3px 10px; }
.loc-tag.muted { color: var(--text-muted, #64748b); }
.loc-tag.price { color: var(--brand, #0f766e); background: color-mix(in srgb, var(--brand, #0f766e) 10%, var(--surface, white)); }
.loc-chev { color: var(--text-light, #94a3b8); flex-shrink: 0; transition: transform .18s; }
.loc-card.open .loc-chev { transform: rotate(180deg); }

.loc-body { padding: 4px 18px 20px; border-top: 1px solid var(--border, #eef2f6); }
.loc-cols { display: grid; grid-template-columns: minmax(0, .85fr) minmax(0, 1.15fr); gap: 24px; margin-top: 16px; }
.loc-block-h { display: flex; align-items: center; gap: 7px; font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: .04em; color: var(--text-muted, #64748b); margin-bottom: 10px; }
.loc-block-h svg { color: var(--brand, #0f766e); }
.loc-sched { display: flex; flex-direction: column; gap: 1px; }
.loc-srow { display: flex; justify-content: space-between; gap: 12px; font-size: 13px; padding: 4px 0; border-bottom: 1px dashed var(--border, #f1f5f9); }
.loc-day { font-weight: 800; color: var(--text-secondary, #334155); }
.loc-time { color: var(--text, #0f172a); font-weight: 600; }
.loc-srow.closed .loc-day, .loc-srow.closed .loc-time { color: var(--text-light, #94a3b8); font-weight: 600; }
.loc-nohours { font-size: 13px; color: var(--text-light, #94a3b8); }
.loc-maps { display: inline-flex; align-items: center; gap: 5px; margin-top: 10px; font-size: 13px; font-weight: 700; color: var(--brand, #0f766e); text-decoration: none; }
.loc-maps:hover { text-decoration: underline; }

.loc-svc-list { display: flex; flex-direction: column; gap: 8px; }
.loc-svc { display: flex; align-items: center; gap: 12px; padding: 9px 11px; border: 1px solid var(--border, #e2e8f0); border-radius: 12px; text-decoration: none; background: var(--surface, #ffffff); }
.loc-svc:hover { border-color: var(--brand-accent, #14b8a6); background: var(--canvas, #f8fafc); }
.loc-svc-thumb { width: 46px; height: 46px; border-radius: 10px; background: var(--bg, #f1f5f9); background-size: cover; background-position: center; flex-shrink: 0; }
.loc-svc-main { flex: 1; min-width: 0; }
.loc-svc-name { font-size: 14.5px; font-weight: 700; color: var(--text, #0f172a); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.loc-svc-sub { display: flex; gap: 10px; font-size: 12.5px; color: var(--text-muted, #64748b); margin-top: 2px; }
.loc-svc-price { color: var(--brand, #0f766e); font-weight: 700; }
.loc-svc-go { color: #cbd5e1; flex-shrink: 0; }
.loc-svc:hover .loc-svc-go { color: var(--brand, #0f766e); }

@media (max-width: 760px) {
  .loc-cols { grid-template-columns: 1fr; gap: 20px; }
  .loc-hero h1 { font-size: 22px; }

  /* Pass 2: calm, first-glance mobile polish */
  .loc-hero { margin-bottom: 12px; }
  .loc-hero p { display: none; } /* restates h1 + cards; no facts lost */
  .loc-search input { font-size: 16px; } /* iOS zoom floor */

  .loc-head { padding: 12px 14px; gap: 12px; }
  .loc-body { padding: 4px 14px 16px; }
  .loc-name { font-size: 16px; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }

  /* Max ~2 chips, one line: hide count chip (restated in expanded list), clamp sport list */
  .loc-tag { font-size: 12px; max-width: 60%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .loc-tag.muted { display: none; }

  /* 4/3 thumbnails, >=48px tall */
  .loc-thumb { width: 72px; height: auto; aspect-ratio: 4 / 3; }
  .loc-svc-thumb { width: 64px; height: auto; aspect-ratio: 4 / 3; }

  /* Flatten card-inside-card: tint instead of inner border */
  .loc-svc { border: none; background: var(--canvas, #f8fafc); }
  .loc-svc:hover { background: var(--bg, #f1f5f9); }

  /* 360px pass: comfortable tap target for the directions link */
  .loc-maps { padding: 10px 0; margin-top: 4px; min-height: 44px; align-items: center; }

  /* 360px pass: long sport/type names truncate instead of colliding with the price */
  .loc-svc-sub { flex-wrap: wrap; row-gap: 2px; }
  .loc-svc-sub span:first-child { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 100%; }
}
</style>
