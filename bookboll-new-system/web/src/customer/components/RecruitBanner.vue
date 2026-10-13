<script setup>
/**
 * RecruitBanner — provider-recruitment CTA, tailored per main venue category
 * (sports / hangout / work) so it reads right on every kind of landing page. Shared across
 * the venue browse + city landing pages. Hidden on white-label sites (marketplace-only CTA).
 */
import { computed } from 'vue'
import { selectedCity } from '../../lib/customerSession.js'
import { isWhiteLabel } from '../../lib/brand.js'
import { adminLoginUrl, businessHomeUrl } from '../../lib/crossApp.js'
import { Store, Check, ArrowRight } from 'lucide-vue-next'

const props = defineProps({
  cityName: { type: String, default: '' },
  category: { type: String, default: '' },   // '' | 'sports' | 'hangout' | 'work'
})
const where = computed(() => props.cityName || selectedCity.value?.name || 'your city')

const COPY = {
  sports:  { badge: 'For venue owners', heading: 'Own a court, pitch or venue?',            audience: 'players',  cta: 'List your venue' },
  hangout: { badge: 'For venue owners', heading: 'Run an entertainment or hangout venue?',  audience: 'guests',   cta: 'List your venue' },
  work:    { badge: 'For space owners', heading: 'Own a studio or workspace?',              audience: 'people',   cta: 'List your space' },
  membership: { badge: 'For operators', heading: 'Run a gym, academy or class?',           audience: 'members',  cta: 'List your program' },
  business: { badge: 'For businesses', heading: 'Do you own a business?',                  audience: 'customers', cta: 'Try Bookboll for businesses',
              body: "Create your own website and list your services on Bookboll's platform as well — take bookings, programs and payments all in one place." },
  '':      { badge: 'For venue owners', heading: 'Own a venue or space?',                   audience: 'customers', cta: 'List your space' },
}
const c = computed(() => COPY[props.category] || COPY[''])
</script>

<template>
  <section v-if="!isWhiteLabel" class="recruit">
    <div class="recruit-text">
      <div class="recruit-badge"><Store :size="15" /> {{ c.badge }}</div>
      <h2>{{ c.heading }}</h2>
      <p>{{ c.body || `List on Bookboll and get discovered by ${c.audience} near you — manage bookings, programs and payments all in one place.` }}</p>
      <ul class="recruit-perks">
        <li><Check :size="15" /> Reach {{ c.audience }} searching in {{ where }}</li>
        <li><Check :size="15" /> Take bookings &amp; subscriptions online</li>
        <li><Check :size="15" /> Free to get started</li>
      </ul>
    </div>
    <div class="recruit-cta">
      <a :href="businessHomeUrl('/')" class="recruit-btn">{{ c.cta }} <ArrowRight :size="18" /></a>
      <a :href="adminLoginUrl()" class="recruit-signin">Already a partner? Sign in</a>
    </div>
  </section>
</template>

<style scoped>
/* NOTE: the recruit banner uses FIXED teal colors on purpose — it must NOT follow the
   site brand color (super-admin marketplace theming). Do not swap these for --brand vars. */
/* margin-bottom keeps whatever follows (FAQ / SEO blocks / blog) clearly separated on every page */
.recruit { display: flex; align-items: center; justify-content: space-between; gap: 28px; margin-top: 32px; margin-bottom: 36px; padding: 28px 30px; border-radius: 20px; background: linear-gradient(135deg, #0f172a, #134e4a); color: #fff; position: relative; overflow: hidden; flex-wrap: wrap; }
.recruit::after { content: ''; position: absolute; inset-inline-end: -50px; top: -50px; width: 220px; height: 220px; background: #14b8a6; opacity: .14; border-radius: 50%; }
.recruit-text { flex: 1; min-width: 260px; z-index: 1; }
.recruit-badge { display: inline-flex; align-items: center; gap: 6px; font-size: 12.5px; font-weight: 800; color: #5eead4; background: rgba(20,184,166,.16); padding: 5px 12px; border-radius: 999px; }
.recruit-text h2 { font-size: 24px; font-weight: 900; margin: 12px 0 8px; color: #fff; }
.recruit-text p { font-size: 14.5px; color: #cbd5e1; line-height: 1.55; margin: 0 0 14px; max-width: 520px; }
.recruit-perks { list-style: none; padding: 0; margin: 0; display: flex; flex-wrap: wrap; gap: 8px 18px; }
.recruit-perks li { display: flex; align-items: center; gap: 7px; font-size: 13.5px; color: var(--border, #e2e8f0); font-weight: 600; }
.recruit-perks svg { color: #34d399; flex-shrink: 0; }
.recruit-cta { display: flex; flex-direction: column; align-items: stretch; gap: 10px; z-index: 1; }
.recruit-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; background: #14b8a6; color: #04201c; font-weight: 800; font-size: 15.5px; padding: 14px 26px; border-radius: 999px; text-decoration: none; white-space: nowrap; }
.recruit-btn:hover { background: #2dd4bf; }
.recruit-signin { text-align: center; color: var(--text-light, #94a3b8); font-size: 13px; font-weight: 600; text-decoration: none; }
.recruit-signin:hover { color: #cbd5e1; }

/* Mobile: stack columns, kill fixed min-widths and nowrap so nothing exceeds a ~344px viewport */
@media (max-width: 760px) {
  .recruit { flex-direction: column; align-items: stretch; gap: 18px; padding: 22px 16px; }
  .recruit-text, .recruit-cta { flex: none; width: 100%; min-width: 0; }
  .recruit-text h2 { font-size: 20px; }
  .recruit-text p { max-width: none; }
  .recruit-perks { flex-direction: column; flex-wrap: nowrap; gap: 8px; }
  .recruit-perks li { align-items: flex-start; min-width: 0; }
  .recruit-perks svg { margin-top: 2px; }
  .recruit-btn { white-space: normal; width: 100%; padding: 13px 18px; }
}
</style>
