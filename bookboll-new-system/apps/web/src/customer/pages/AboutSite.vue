<script setup>
/** AboutSite.vue — provider About page with an optional hero banner
 *  (title / subtitle / image set in My Website → About us). */
import { computed } from 'vue'
import { activeBrand } from '../../lib/brand.js'
import { useSeo } from '../../lib/seo.js'
import { CalendarCheck } from 'lucide-vue-next'
import VisitUs from '../components/VisitUs.vue'

useSeo(() => ({ title: `About ${activeBrand.value?.name || ''}`, noindex: true, path: '/about' }))
const brand = computed(() => activeBrand.value || {})
const pg = computed(() => brand.value.pages?.about || {})
const heroTitle = computed(() => pg.value.hero_title || `About ${brand.value.name}`)
const paras = computed(() => String(pg.value.text || '').split(/\n{2,}|\n/).map(t => t.trim()).filter(Boolean))
</script>

<template>
  <div class="ab">
    <section class="ab-hero" :class="{ img: pg.hero_image }"
      :style="pg.hero_image ? { backgroundImage: `linear-gradient(rgba(10,20,30,.45), rgba(10,20,30,.55)), url(${pg.hero_image})` } : {}">
      <span v-if="!pg.hero_image && brand.logo" class="ab-hero-logo" :style="{ backgroundImage: `url(${brand.logo})` }"></span>
      <h1 class="ab-hero-h1">{{ heroTitle }}</h1>
      <p v-if="pg.hero_sub || brand.tagline" class="ab-hero-sub">{{ pg.hero_sub || brand.tagline }}</p>
    </section>

    <div class="ab-body">
      <p v-for="(t, i) in paras" :key="i">{{ t }}</p>
    </div>

    <!-- Locations + opening hours (shared with home & contact) -->
    <div class="ab-visit"><VisitUs /></div>

    <div class="ab-cta-band">
      <div class="ab-cta-txt">Come play with us — book your first session today.</div>
      <router-link to="/facilities" class="ab-cta"><CalendarCheck :size="16" /> Book Now</router-link>
    </div>
  </div>
</template>

<style scoped>
.ab { max-width: 760px; margin: 0 auto; padding-bottom: 24px; }
/* Full-bleed hero: viewport edge to edge, flush under the nav (swallows the
   main container's 18px top padding), content centered in a readable column. */
.ab-hero { margin-inline: calc(50% - 50vw); margin-top: -18px; background: linear-gradient(130deg, var(--brand), var(--brand-accent)); padding: 84px 24px; text-align: center; color: #fff; margin-bottom: 34px; background-size: cover; background-position: center; }
.ab-hero-logo { display: inline-block; width: 72px; height: 72px; border-radius: 18px; background-size: cover; background-position: center; background-color: var(--surface, #ffffff); box-shadow: 0 6px 20px rgba(0,0,0,.18); margin-bottom: 16px; }
.ab-hero-h1 { font-size: 38px; font-weight: 900; margin: 0 0 10px; color: #fff; line-height: 1.12; }
.ab-hero-sub { font-size: 16px; opacity: .93; margin: 0 auto; max-width: 560px; line-height: 1.6; }
.ab-body p { font-size: 15.5px; line-height: 1.85; color: #334155; margin: 0 0 18px; }
.ab-body p:first-of-type { font-size: 17px; color: #1e293b; }
.ab-visit { margin-top: 30px; }
.ab-cta-band { display: flex; align-items: center; gap: 14px; background: color-mix(in srgb, var(--brand) 6%, white); border: 1px solid color-mix(in srgb, var(--brand) 18%, white); border-radius: 16px; padding: 18px 22px; margin-top: 26px; flex-wrap: wrap; }
.ab-cta-txt { flex: 1; font-size: 15px; font-weight: 800; color: #0f172a; min-width: 0; }
.ab-cta { display: inline-flex; align-items: center; gap: 8px; background: var(--brand); color: #fff; font-weight: 800; font-size: 14.5px; padding: 12px 24px; border-radius: 999px; text-decoration: none; }
@media (max-width: 760px) {
  .ab-hero { padding: 54px 18px; margin-bottom: 24px; }
  .ab-hero-h1 { font-size: 26px; }
  .ab-hero-sub { font-size: 14px; }
  .ab-visit { margin-top: 30px; }
.ab-cta-band { flex-direction: column; text-align: center; }
  .ab-cta { width: 100%; justify-content: center; }
}
</style>
