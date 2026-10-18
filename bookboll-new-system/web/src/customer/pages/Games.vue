<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { selectedCity } from '../../lib/customerSession.js'
import { loadGames, gamesEnabled } from '../../customer/apiGames.js'
import GameCard from '../components/GameCard.vue'
import { CalendarDays, Plus, Search, X } from 'lucide-vue-next'
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
const filtersOn = computed(() => tab.value !== 'all' || !!q.value.trim() || !!fSport.value || freeOnly.value)
function clearFilters() { tab.value = 'all'; q.value = ''; fSport.value = ''; freeOnly.value = false }

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
  <div class="discovery">
    <header class="discover-head"><div><h1><CalendarDays :size="29" /> Events{{ selectedCity?.name ? ' in ' + selectedCity.name : '' }}</h1><p>A game after work. A weekend hangout. Find your next plan and people to share it with.</p></div><router-link to="/host-game" class="discover-action"><Plus :size="18" /> Host &amp; earn</router-link></header>
    <div class="discover-tools"><label class="discover-search"><Search :size="21" /><input v-model="q" type="search" aria-label="Search events" placeholder="Search by event, sport, host or venue" /></label><div class="discover-filters" aria-label="Event type"><button v-for="option in [{key:'all',label:'All events'},{key:'game',label:'Games'},{key:'event',label:'Hangouts'}]" :key="option.key" class="discover-filter" :class="{on:tab===option.key}" :aria-pressed="tab===option.key" @click="tab=option.key">{{ option.label }}</button></div><div v-if="sportChips.length" class="event-extra-filters"><label>Sport<select v-model="fSport"><option value="">All sports</option><option v-for="sp in sportChips" :key="sp">{{ sp }}</option></select></label><label class="event-free"><input v-model="freeOnly" type="checkbox" /> Free to join</label><button v-if="filtersOn" class="discover-text-button" @click="clearFilters">Reset filters</button></div></div>
    <div v-if="loading" class="discover-loading" role="status">Finding your next event…</div>
    <div v-else-if="gamesEnabled===false" class="discover-empty"><CalendarDays :size="36" /><h2>Events are unavailable right now</h2><p>Try again in a moment to find games and hangouts.</p><button class="discover-action" @click="load">Try again</button></div>
    <template v-else><div class="discover-result-head"><h2>{{ filtered.length ? filtered.length + ' upcoming events' : 'Upcoming events' }}</h2><p>Earliest dates first</p></div>
      <div v-if="!filtered.length" class="discover-empty"><CalendarDays :size="40" /><h2>{{ filtersOn ? 'No events match your search' : 'Make the next plan' }}</h2><p>{{ filtersOn ? 'Try another sport or clear your filters to see all events.' : 'No upcoming events here yet. Choose a place, set a price per person and invite others to join. Your event could be the next one.' }}</p><button v-if="filtersOn" class="discover-action" @click="clearFilters">Clear search &amp; filters</button><router-link v-else to="/host-game" class="discover-action"><Plus :size="17" /> Host an event &amp; earn</router-link></div>
      <div v-else class="event-timeline"><section v-for="grp in grouped" :key="grp.date || 'flex'" class="event-day"><header><h2>{{ grp.main }}</h2><p>{{ grp.sub }}</p></header><div class="event-grid"><GameCard v-for="game in grp.games" :key="game.id" :game="game" /></div></section></div>
    </template>
    <aside class="event-how"><h2>Good plans start with you</h2><div><p><b>Choose your activity</b>Host a game or bring people together for a hangout.</p><p><b>Set your price</b>See the booking costs and hosting fee before publishing.</p><p><b>Welcome your guests</b>Share your event and manage requests in one place.</p></div></aside>
  </div>
</template>
<style scoped>
.event-extra-filters { display: flex; align-items: center; flex-wrap: wrap; gap: 14px 24px; margin-top: 16px; color: var(--text-secondary); font-size: 13px; }.event-extra-filters label { display: flex; align-items: center; gap: 9px; }.event-extra-filters select { border: 1px solid var(--border); border-radius: 8px; padding: 9px 12px; background: var(--surface); color: var(--text); font: inherit; }.event-free input { accent-color: var(--brand); width: 17px; height: 17px; }
.event-day { display: grid; grid-template-columns: 110px minmax(0,1fr); gap: 24px; border-top: 1px solid var(--border); padding: 24px 0; }.event-day > header h2 { margin: 2px 0 5px; font-size: 21px; letter-spacing: -.02em; }.event-day > header p { color: var(--text-muted); font-size: 13px; margin: 0; }.event-grid { display: grid; grid-template-columns: repeat(2,minmax(0,1fr)); gap: 18px; }.event-grid :deep(.gc) { width: 100%; max-width: none; }
.event-how { border-top: 1px solid var(--border); margin: 30px 0 10px; padding-top: 26px; }.event-how h2 { font-size: 20px; margin: 0 0 8px; }.event-how > div { display: grid; grid-template-columns: repeat(3,1fr); gap: 28px; }.event-how p { font-size: 14px; line-height: 1.7; color: var(--text-secondary); }.event-how b { display: block; margin-bottom: 6px; color: var(--text); }
@media(max-width:1000px) { .event-grid { grid-template-columns: 1fr; } } @media(max-width:760px) { .event-day { grid-template-columns: 1fr; gap: 14px; }.event-day > header { display: flex; align-items: baseline; gap: 10px; }.event-how > div { grid-template-columns: 1fr; gap: 0; }.event-how { margin-top: 24px; } }
</style>
