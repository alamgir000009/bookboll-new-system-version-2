<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { selectedCity } from '../../lib/customerSession.js'
import { loadGames, gamesEnabled } from '../../customer/data.js'
import GameCard from '../components/GameCard.vue'
import { Ticket, Plus, Search, X } from 'lucide-vue-next'
import { useSeo } from '../../lib/seo.js'

const all = ref([])
const loading = ref(true)
const tab = ref('all')   // all | game | event | tournament
const q = ref('')        // search: title / sport / host / venue
const fSport = ref('')   // one sport chip at a time ('' = all)
const freeOnly = ref(false)
// Sport chips from the events actually open right now (max 8, most common first).
const sportChips = computed(() => {
  const counts = new Map()
  for (const g of all.value) {
    const sp = (g.sport || '').trim()
    if (sp) counts.set(sp, (counts.get(sp) || 0) + 1)
  }
  return [...counts.entries()].sort((a, b) => b[1] - a[1]).slice(0, 8).map(([sp]) => sp)
})
const filtersOn = computed(() => !!q.value.trim() || !!fSport.value || freeOnly.value)
function clearFilters() { q.value = ''; fSport.value = ''; freeOnly.value = false }

async function load() { loading.value = true; all.value = await loadGames(selectedCity.value?.id); loading.value = false }
onMounted(load)
watch(() => selectedCity.value?.id, load)

useSeo(() => {
  const city = selectedCity.value?.name
  return {
    title: city ? `Games & tournaments in ${city}` : 'Games, hangouts & tournaments',
    description: `Join pickup games, hangouts and tournaments${city ? ' in ' + city : ''} — or host your own — on Bookboll.`,
    path: '/games',
  }
})

const filtered = computed(() => {
  let list = tab.value === 'all' ? all.value : all.value.filter(g => g.game_type === tab.value)
  const query = q.value.trim().toLowerCase()
  if (query) list = list.filter(g =>
    (g.title || '').toLowerCase().includes(query) ||
    (g.sport || '').toLowerCase().includes(query) ||
    (g.hostName || g.host_name || '').toLowerCase().includes(query) ||
    (g.location_text || '').toLowerCase().includes(query) ||
    (g.venueName || '').toLowerCase().includes(query))
  if (fSport.value) list = list.filter(g => (g.sport || '').toLowerCase() === fSport.value.toLowerCase())
  if (freeOnly.value) list = list.filter(g => !(Number(g.price_per_person) > 0))
  return list
})

// Luma-style browsing: events grouped by DAY on a timeline ("Today · Sunday",
// "Tomorrow · Monday", then dated groups), flexible-date events last as "Anytime".
function dayLabel(d) {
  const today = new Date(); today.setHours(0, 0, 0, 0)
  const dt = new Date(d + 'T00:00:00')
  const diff = Math.round((dt - today) / 86400000)
  const weekday = dt.toLocaleDateString('en-US', { weekday: 'long' })
  if (diff === 0) return { main: 'Today', sub: weekday }
  if (diff === 1) return { main: 'Tomorrow', sub: weekday }
  return { main: dt.toLocaleDateString('en-US', { day: 'numeric', month: 'short' }), sub: weekday }
}
const grouped = computed(() => {
  const map = new Map()
  for (const g of filtered.value) {
    const key = g.game_date || '__flex'
    if (!map.has(key)) map.set(key, [])
    map.get(key).push(g)
  }
  const out = [...map.entries()].filter(([k]) => k !== '__flex')
    .sort((a, b) => (a[0] < b[0] ? -1 : 1))
    .map(([date, games]) => ({ date, ...dayLabel(date), games }))
  if (map.has('__flex')) out.push({ date: '', main: 'Anytime', sub: 'flexible date', games: map.get('__flex') })
  return out
})
</script>

<template>
  <div>
    <!-- Coming soon (games tables not set up yet) -->
    <div v-if="gamesEnabled === false" class="g-soon">
      <div class="g-soon-emoji"><Ticket :size="48" /></div>
      <h1>Events are coming soon!</h1>
      <p>Soon you'll be able to join games near you — or host your own, set a price per person, and pick your players. Check back shortly!</p>
      <router-link to="/" class="g-soon-btn">Back to home</router-link>
    </div>

    <template v-else>
    <div class="g-head">
      <div class="ph"><h1><Ticket :size="24" /> Events in {{ selectedCity?.name }}</h1><p>Join a game, hangout or tournament near you — or host your own and set the price.</p></div>
      <router-link to="/host-game" class="g-host-btn"><Plus :size="18" /> Host an event</router-link>
    </div>

    <div class="g-tabs">
      <button :class="{ on: tab === 'all' }" @click="tab = 'all'">All</button>
      <button :class="{ on: tab === 'game' }" @click="tab = 'game'">Games</button>
      <button :class="{ on: tab === 'event' }" @click="tab = 'event'">Hangouts</button>
      <button :class="{ on: tab === 'tournament' }" @click="tab = 'tournament'">Tournaments</button>
    </div>

    <!-- Search + quick filters -->
    <div class="g-filter">
      <div class="g-search">
        <Search :size="16" />
        <input v-model="q" placeholder="Search events, sports, hosts or venues…" />
        <button v-if="q" type="button" class="g-search-x" aria-label="Clear search" @click="q = ''"><X :size="14" /></button>
      </div>
      <div v-if="sportChips.length > 1 || freeOnly || sportChips.length === 1" class="g-chiprow">
        <button v-for="sp in sportChips" :key="sp" type="button" class="g-chip" :class="{ on: fSport.toLowerCase() === sp.toLowerCase() }"
                @click="fSport = fSport.toLowerCase() === sp.toLowerCase() ? '' : sp">{{ sp }}</button>
        <button type="button" class="g-chip" :class="{ on: freeOnly }" @click="freeOnly = !freeOnly">Free</button>
        <button v-if="filtersOn" type="button" class="g-chip-clear" @click="clearFilters">Clear</button>
      </div>
    </div>

    <div v-if="loading" class="cmsg">Loading…</div>
    <div v-else-if="!filtered.length" class="cempty">
      <Ticket :size="40" />
      <span>{{ filtersOn ? 'Nothing matches your search or filters.' : `No open ${tab === 'all' ? 'games' : tab === 'event' ? 'hangouts' : tab + 's'} yet.` }}</span>
      <button v-if="filtersOn" type="button" class="cempty-cta" @click="clearFilters"><X :size="16" /> Clear filters</button>
      <router-link v-else to="/host-game" class="cempty-cta"><Plus :size="16" /> Be the first to host one</router-link>
    </div>
    <!-- Date-grouped timeline (events browse by "when", not by a flat grid) -->
    <div v-else class="g-timeline">
      <section v-for="grp in grouped" :key="grp.date || 'flex'" class="g-day">
        <div class="g-day-head">
          <span class="g-day-dot"></span>
          <b>{{ grp.main }}</b><span class="g-day-sub">{{ grp.sub }}</span>
        </div>
        <div class="cgrid">
          <GameCard v-for="g in grp.games" :key="g.id" :game="g" />
        </div>
      </section>
    </div>
    </template>
  </div>
</template>

<style scoped>
.g-soon { text-align: center; max-width: 460px; margin: 40px auto; background: linear-gradient(135deg,#eef2ff,#e0e7ff); border: 1.5px solid #c7d2fe; border-radius: 22px; padding: 44px 28px; }
.g-soon-emoji { color: #4f46e5; margin-bottom: 8px; }
.g-soon h1 { font-size: 26px; font-weight: 900; color: #312e81; margin: 12px 0 8px; }
.g-soon p { font-size: 15px; color: #4338ca; line-height: 1.6; margin: 0 0 22px; }
.g-soon-btn { display: inline-block; background: #4338ca; color: #fff; font-weight: 800; padding: 13px 24px; border-radius: 999px; text-decoration: none; }
.g-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
.ph h1 { font-size: 25px; font-weight: 900; color: #0f172a; margin: 0; display: flex; align-items: center; gap: 10px; }
.ph h1 svg { color: var(--brand); flex-shrink: 0; }
.ph p { font-size: 14.5px; color: var(--text-muted, #64748b); margin: 6px 0 0; }
.g-host-btn { display: inline-flex; align-items: center; gap: 7px; background: #059669; color: #fff; font-weight: 800; padding: 12px 18px; border-radius: 999px; text-decoration: none; font-size: 14.5px; white-space: nowrap; }
.g-host-btn:hover { background: #047857; }
.g-tabs { display: flex; gap: 8px; margin: 20px 0; flex-wrap: wrap; }
.g-tabs button { border: 1.5px solid var(--border, #e2e8f0); background: var(--surface, #ffffff); color: #475569; font-weight: 700; font-size: 14px; padding: 9px 18px; border-radius: 999px; cursor: pointer; }
.g-tabs button.on { background: #0f172a; color: #fff; border-color: #0f172a; }
.cgrid { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(420px, 100%), 1fr)); gap: 16px; }
/* In this GRID the shared card must fill its column — the fixed 450px width and
   the phone 88% cap are for the home page's horizontal scroll rails, and here
   they produced uneven card widths + giant gutters. */
.cgrid :deep(.gc) { width: 100%; max-width: none; }

/* Timeline rail: a dot + day label per group, cards indented along the line */
.g-timeline { display: flex; flex-direction: column; margin-top: 34px; }
.g-day { position: relative; margin-left: 5px; padding: 0 0 26px 22px; border-left: 2px solid #e8edf3; }
.g-day:last-child { border-left-color: transparent; }
.g-day-head { display: flex; align-items: baseline; gap: 8px; margin-bottom: 12px; }
.g-day-head b { font-size: 16px; font-weight: 800; color: #0f172a; }
.g-day-sub { font-size: 13.5px; font-weight: 600; color: var(--text-light, #94a3b8); }
.g-day-dot { position: absolute; left: -6px; top: 5px; width: 10px; height: 10px; border-radius: 50%; background: #cbd5e1; border: 2px solid #fff; box-shadow: 0 0 0 1.5px #e8edf3; }
.g-day:first-child .g-day-dot { background: var(--brand); }
@media (max-width: 760px) {
  .g-day { padding-left: 16px; padding-bottom: 20px; }
  .g-day-head b { font-size: 15px; }
}
.cmsg { text-align: center; padding: 50px; color: var(--text-light, #94a3b8); }
.cempty { display: flex; flex-direction: column; align-items: center; gap: 14px; padding: 60px; color: var(--text-light, #94a3b8); }
@media (max-width: 560px) { .cmsg, .cempty { padding: 40px 20px; } }
.cempty-cta { display: inline-flex; align-items: center; gap: 7px; background: #059669; color: #fff; font-weight: 800; padding: 11px 18px; border-radius: 999px; text-decoration: none; font-size: 14px; }

/* NOTE (dark mode): this shell implements .cust-dark via a global
   `filter: invert(1) hue-rotate(180deg)` on .cust (CustomerLayout.vue), not native
   re-theming. The light .g-soon gradient and white .g-tabs surfaces are inverted
   automatically into dark-appropriate tints, so NO explicit .cust-dark overrides are
   added here — adding them would double-invert and break the shell's dark look. */

/* Phone: lift the browse grid higher — stack the header, tame headings,
   keep tabs on one scrollable row, de-emphasize the secondary Host action,
   and tighten the empty/loading/coming-soon states. Mobile-only. */
@media (max-width: 760px) {
  /* header: stack title + full-width Host, shrink heading copy */
  .g-head { flex-direction: column; align-items: stretch; gap: 12px; }
  .ph h1 { font-size: 20px; }
  .ph h1 svg { width: 20px; height: 20px; }
  /* subtitle restates what the tabs + Host button already show — drop it on phones */
  .ph p { display: none; }
  /* Host is a secondary action here — ghost/outline so filled green reads as per-card Join */
  .g-host-btn { width: 100%; justify-content: center; background: transparent; color: var(--brand); border: 1.5px solid var(--brand); }
  .g-host-btn:hover { background: transparent; }
  /* empty state already has its own green Host CTA — avoid two competing CTAs
     (progressive enhancement: browsers without :has() just keep both) */
  .g-head:has(~ .cempty) .g-host-btn { display: none; }

  /* tabs: single horizontal-scroll row, tighter margins */
  .g-tabs { flex-wrap: nowrap; overflow-x: auto; -webkit-overflow-scrolling: touch; margin: 14px 0 12px; scrollbar-width: none; }
  .g-tabs::-webkit-scrollbar { display: none; }
  .g-tabs button { flex-shrink: 0; font-size: 14px; padding: 9px 16px; }

  /* empty / loading / coming-soon: reclaim vertical space */
  .cmsg, .cempty { padding: 32px 16px; }
  .cempty-cta { padding: 13px 18px; }
  .g-soon { margin: 24px auto; padding: 28px 20px; }
  .g-soon h1 { font-size: 22px; }
  .g-soon p { font-size: 14px; margin-bottom: 18px; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; }
}
.g-filter { margin-top: 14px; display: flex; flex-direction: column; gap: 10px; }
.g-search { display: flex; align-items: center; gap: 9px; background: var(--surface, #ffffff); border: 1.5px solid var(--border, #e2e8f0); border-radius: 999px; padding: 0 14px; height: 44px; }
.g-search svg { color: var(--text-light, #94a3b8); flex-shrink: 0; }
.g-search input { flex: 1; border: none; outline: none; background: transparent; font-size: 14px; color: var(--text, #0f172a); min-width: 0; }
.g-search:focus-within { border-color: var(--brand, #0d9488); }
.g-search-x { border: none; background: var(--bg, #f1f5f9); color: var(--text-muted, #64748b); border-radius: 50%; width: 24px; height: 24px; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; flex-shrink: 0; }
.g-chiprow { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
.g-chip { border: 1.5px solid var(--border, #e2e8f0); background: var(--surface, #ffffff); color: #475569; font-weight: 700; font-size: 12.5px; padding: 7px 13px; border-radius: 999px; cursor: pointer; }
.g-chip.on { border-color: var(--brand, #0d9488); background: color-mix(in srgb, var(--brand, #0d9488), transparent 90%); color: var(--brand, #0d9488); }
.g-chip-clear { border: none; background: none; color: var(--text-muted, #64748b); font-weight: 700; font-size: 12.5px; cursor: pointer; text-decoration: underline; }
</style>
