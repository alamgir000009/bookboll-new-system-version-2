<script setup>
/**
 * ExploreHub — sitewide "Explore by city" footer block (SEO internal-link hub). Country-grouped,
 * collapsible; each city links to its venue hub + top sports, with keyword-rich anchors. Pulls
 * crawl equity to the /venues/{city}[/{sport}] landing pages from every page. Apex marketplace only.
 */
import { ref, onMounted } from 'vue'
import { loadDirectory } from '../../lib/cityPages.js'
import { isWhiteLabel } from '../../lib/brand.js'
import { businessHomeUrl } from '../../lib/crossApp.js'
import { ChevronDown } from 'lucide-vue-next'

const groups = ref([])
onMounted(async () => { if (!isWhiteLabel.value) groups.value = await loadDirectory() })
</script>

<template>
  <section v-if="!isWhiteLabel && groups.length" class="xh">
    <div class="xh-inner">
      <div class="xh-head">
        <h2>Explore Bookboll by city</h2>
        <span class="xh-all-links">
          <router-link to="/venues" class="xh-all">All venues</router-link>
          <router-link to="/memberships" class="xh-all">All memberships</router-link>
          <router-link to="/terms" class="xh-all">Terms</router-link>
          <a :href="businessHomeUrl('/')" class="xh-all xh-biz">For business →</a>
        </span>
      </div>
      <div class="xh-grid">
        <details v-for="g in groups" :key="g.country.slug" class="xh-country">
          <summary>{{ g.country.name }} <ChevronDown :size="15" class="xh-caret" /></summary>
          <div class="xh-cities">
            <div v-for="c in g.cities" :key="c.slug" class="xh-city">
              <div v-if="c.count" class="xh-city-main">
                <router-link :to="`/venues/${c.slug}`" class="xh-city-name">Sports venues in {{ c.name }}</router-link>
                <span v-for="s in c.topSports.slice(0, 8)" :key="s.slug" class="xh-sport">
                  <span class="xh-sep">|</span>
                  <router-link :to="`/venues/${c.slug}/${s.slug}`">{{ s.name }} in {{ c.name }}</router-link>
                </span>
              </div>
              <div v-if="c.memberships && c.memberships.length" class="xh-city-sub">
                <router-link :to="`/memberships/${c.slug}`" class="xh-city-name">Memberships in {{ c.name }}</router-link>
                <span v-for="m in c.memberships" :key="m.slug" class="xh-sport">
                  <span class="xh-sep">|</span>
                  <router-link :to="`/memberships/${c.slug}/${m.slug}`">{{ m.label }} in {{ c.name }}</router-link>
                </span>
              </div>
            </div>
            <router-link :to="`/venues/${g.country.slug}`" class="xh-country-link">All venues in {{ g.country.name }} →</router-link>
          </div>
        </details>
      </div>
    </div>
  </section>
</template>

<style scoped>
.xh { border-top: 1px solid var(--border, #e2e8f0); background: #f8fafc; margin-top: 36px; }
.xh-inner { max-width: 1100px; margin: 0 auto; padding: 30px 18px 14px; }
.xh-head { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-bottom: 10px; }
.xh-head h2 { font-size: 16px; font-weight: 800; color: #0f172a; margin: 0; }
.xh-all-links { display: inline-flex; gap: 14px; }
.xh-all { font-size: 13px; font-weight: 700; color: var(--brand); text-decoration: none; }
/* For-business uses the fixed recruit teal (a Bookboll-platform CTA, not the site brand). */
.xh-biz { color: #0f766e; }
.xh-city-sub { margin-top: 4px; }
.xh-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 4px 24px; }
.xh-country { border-bottom: 1px solid #eef2f6; }
.xh-country summary { display: flex; align-items: center; justify-content: space-between; cursor: pointer; list-style: none; padding: 11px 0; font-size: 14px; font-weight: 800; color: #0f172a; }
.xh-country summary::-webkit-details-marker { display: none; }
.xh-caret { color: var(--text-light, #94a3b8); transition: transform .15s; }
.xh-country[open] .xh-caret { transform: rotate(180deg); }
.xh-cities { padding: 0 0 12px; display: flex; flex-direction: column; gap: 8px; }
.xh-city { font-size: 12.5px; line-height: 1.7; color: var(--text-light, #94a3b8); }
.xh-city-name { font-weight: 700; color: #334155; text-decoration: none; }
.xh-city a:hover { color: var(--brand); text-decoration: underline; }
.xh-sport a { color: var(--text-muted, #64748b); text-decoration: none; }
.xh-sep { color: #cbd5e1; margin: 0 5px; }
.xh-country-link { font-size: 12.5px; font-weight: 700; color: var(--brand); text-decoration: none; margin-top: 2px; }

/* ---- Mobile polish (pass 2): tame the keyword-link wall, calm the chrome ---- */
@media (max-width: 760px) {
  /* Clamp each city's venue run and membership run to 2 lines each; all links
     stay in the DOM (SEO intact) and "All venues in {Country} →" is the catch-all. */
  .xh-city-main,
  .xh-city-sub {
    display: -webkit-box;
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 2;
    overflow: hidden;
  }
  .xh-city { line-height: 1.6; }
  /* Demote country rows below the section h2 and reach ~44px tap height. */
  .xh-country summary { font-weight: 700; padding: 13px 0; }
  /* One accent leads in the header: only the For-business CTA stays bold. */
  .xh-head { row-gap: 2px; }
  .xh-all-links { flex-wrap: wrap; gap: 10px 14px; }
  .xh-all { font-weight: 600; }
  .xh-biz { font-weight: 700; }
  /* Mute pipe separators that survive the clamp. */
  .xh-sep { margin: 0 4px; color: var(--border, #e2e8f0); }
}
</style>
