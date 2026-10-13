<script setup>
/** CityDirectory.vue — /venues: the crawl-spine directory of every country → city → sport. */
import { ref, computed, onMounted } from 'vue'
import { loadDirectory } from '../../lib/cityPages.js'
import { useSeo, itemListLd } from '../../lib/seo.js'
import { MapPin } from 'lucide-vue-next'

const groups = ref([])
const loading = ref(true)
onMounted(async () => { groups.value = await loadDirectory(); loading.value = false })

const allCities = computed(() => groups.value.flatMap(g => g.cities.map(c => ({ name: `${c.name}`, path: `/venues/${c.slug}` }))))

useSeo(() => ({
  title: 'Sports venues by city',
  description: 'Browse and book sports courts, pitches and venues by city — padel, football, tennis and more across every city on Bookboll.',
  path: '/venues',
  jsonLd: [
    { '@context': 'https://schema.org', '@type': 'CollectionPage', name: 'Sports venues by city' },
    itemListLd(allCities.value, 'Cities on Bookboll'),
  ].filter(Boolean),
}))
</script>

<template>
  <div class="dir">
    <header class="dir-hero">
      <h1>Sports venues by city</h1>
      <p>Find courts, pitches and venues to book by the hour<span class="dir-hero-extra"> &mdash; pick your city to get started</span>.</p>
    </header>

    <div v-if="loading" class="cmsg">Loading…</div>
    <div v-else-if="!groups.length" class="cmsg">No cities listed yet.</div>

    <div v-else class="dir-groups">
      <section v-for="g in groups" :key="g.country.slug" class="dir-country">
        <h2><router-link :to="`/venues/${g.country.slug}`">{{ g.country.name }}</router-link></h2>
        <div class="dir-cities">
          <div v-for="c in g.cities" :key="c.slug" class="dir-city">
            <router-link :to="`/venues/${c.slug}`" class="dir-city-name"><MapPin :size="14" /> {{ c.name }} <span class="dir-count">{{ c.count }}</span></router-link>
            <div class="dir-sports">
              <router-link v-for="s in c.topSports" :key="s.slug" :to="`/venues/${c.slug}/${s.slug}`" class="dir-sport">{{ s.name }}<span class="dir-sport-loc"> in {{ c.name }}</span></router-link>
            </div>
          </div>
        </div>
      </section>
    </div>
  </div>
</template>

<style scoped>
.dir { max-width: 1100px; margin: 0 auto; }
.dir-hero { padding: 26px 0 18px; }
.dir-hero h1 { font-size: 30px; font-weight: 900; color: #0f172a; margin: 0; }
.dir-hero p { font-size: 15px; color: var(--text-muted, #64748b); margin: 8px 0 0; }
.cmsg { text-align: center; padding: 50px; color: var(--text-light, #94a3b8); }
.dir-groups { display: flex; flex-direction: column; gap: 26px; }
.dir-country h2 { font-size: 18px; font-weight: 900; color: #0f172a; margin: 0 0 12px; padding-bottom: 8px; border-bottom: 2px solid #eef2f6; }
.dir-country h2 a { color: inherit; text-decoration: none; }
.dir-country h2 a:hover { color: var(--brand); }
.dir-cities { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 16px; }
.dir-city { background: var(--surface, #ffffff); border: 1px solid var(--border, #e2e8f0); border-radius: 14px; padding: 14px 16px; }
.dir-city-name { display: flex; align-items: center; gap: 6px; font-size: 15px; font-weight: 800; color: #0f172a; text-decoration: none; }
.dir-city-name:hover { color: var(--brand); }
.dir-count { font-size: 11px; font-weight: 700; color: var(--text-light, #94a3b8); background: #f1f5f9; padding: 1px 7px; border-radius: 999px; margin-left: auto; }
.dir-sports { display: flex; flex-wrap: wrap; gap: 4px 10px; margin-top: 9px; }
.dir-sport { font-size: 12.5px; color: var(--text-muted, #64748b); text-decoration: none; }
.dir-sport:hover { color: var(--brand); text-decoration: underline; }

/* ---- Mobile (pass 2: calm, first-glance) ---- */
@media (max-width: 760px) {
  .dir-hero { padding: 18px 0 12px; }
  .dir-hero h1 { font-size: 21px; line-height: 1.2; }
  .dir-hero p { font-size: 14px; margin-top: 6px; }
  .dir-hero-extra { display: none; }
  .dir-country h2 { font-size: 16px; font-weight: 800; margin-bottom: 10px; padding-bottom: 6px; }
  .dir-groups { gap: 18px; }
  .dir-cities { gap: 10px; }
  .dir-city { padding: 12px 14px; }
  .dir-sport { font-size: 13px; }
  .dir-sport-loc { display: none; }
  .dir-sport:nth-child(n+7) { display: none; }
  .cmsg { padding: 32px 16px; }
}
</style>
