<script setup>
/**
 * CityVenues.vue — programmatic local-SEO landing page for a PLACE (city OR country).
 *   /venues/:place            → all venues in a city or country (hub)
 *   /venues/:place/:segment   → segment is a main category (sports/hangout/work) OR a venue
 *                              type (padel/bowling/…); the page detects place + segment.
 * Keyword H1 + intro + chips (cities for a country; categories/types) + grid + FAQ + breadcrumb + JSON-LD.
 */
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute } from 'vue-router'
import { loadPlaceVenues, priceRange, CATEGORY_HEADING, CATEGORY_NOUN } from '../../lib/cityPages.js'
import { useSeo, itemListLd, breadcrumbLd, faqLd } from '../../lib/seo.js'
import { isWhiteLabel } from '../../lib/brand.js'
import CatalogBrowser from '../components/CatalogBrowser.vue'
import RecruitBanner from '../components/RecruitBanner.vue'
import { ChevronRight, Building2, Dumbbell, Ticket } from 'lucide-vue-next'

const route = useRoute()
const data = ref({ place: null, mode: 'hub', segment: null, category: null, typeName: null, venues: [], allListings: [], sports: [], categories: [], cities: [] })
const loading = ref(true)

let loadId = 0
async function load() {
  const current = ++loadId
  loading.value = true
  const result = await loadPlaceVenues(route.params.city, route.params.segment || route.params.sport || null)
  if (current !== loadId) return
  data.value = result
  loading.value = false
}
onMounted(load)
watch([() => route.params.city, () => route.params.segment, () => route.params.sport], load)

const place = computed(() => data.value.place)
const isCountry = computed(() => place.value?.type === 'country')
const mode = computed(() => data.value.mode)             // 'hub' | 'category' | 'type'
const venues = computed(() => data.value.venues)
const range = computed(() => place.value ? priceRange(venues.value, place.value.currencyCode) : null)
const placePath = computed(() => place.value ? `/venues/${place.value.slug}` : '')

const subjectTitle = computed(() => {
  if (mode.value === 'type') return data.value.typeName
  if (mode.value === 'category') return data.value.category?.label
  return ''
})
const subjectNoun = computed(() => {
  if (mode.value === 'type') return `${(data.value.typeName || '').toLowerCase()} venues`
  if (mode.value === 'category') return CATEGORY_NOUN[data.value.category?.key] || 'venues'
  return 'venues'
})
const heading = computed(() => {
  if (!place.value) return 'Venue not found'
  if (mode.value === 'type') return `${data.value.typeName} in ${place.value.name}`
  if (mode.value === 'category') return `${CATEGORY_HEADING[data.value.category.key] || (data.value.category.label + ' venues')} in ${place.value.name}`
  return `Venues & listings in ${place.value.name}`
})
const intro = computed(() => {
  if (!place.value) return ''
  const n = venues.value.length
  const price = range.value ? ` from ${range.value.currency} ${range.value.from.toLocaleString()}` : ''
  if (!n) return `We don't have any ${subjectNoun.value} listed in ${place.value.name} just yet — check back soon.`
  return `Browse ${n} ${subjectNoun.value} in ${place.value.name}${price}. Compare prices, photos and availability, then book by the hour and pay online in seconds.`
})

// Keyword FAQ — apex marketplace only (white-label sites must not carry Bookboll
// marketplace answers); mirrored as FAQPage JSON-LD. Money-accurate: options vary
// per venue, the deposit is part of the price, cancellation terms sit with the venue.
const faqs = computed(() => {
  if (isWhiteLabel.value || !place.value) return []
  const w = subjectNoun.value, c = place.value.name
  const price = range.value ? `${range.value.currency} ${range.value.from.toLocaleString()}` : 'competitive rates'
  return [
    { q: `How do I book ${w} in ${c}?`, a: `Pick a venue below, choose your date and an available time slot, and confirm online in a few taps. Availability is live and the confirmation appears in your account instantly.` },
    { q: `How much does it cost to book ${w} in ${c}?`, a: `Prices start from ${price} per slot and vary by venue and time. Each venue shows its live pricing, and Bookboll adds no booking fees on top of the listed price.` },
    { q: `Do I pay online or at the venue in ${c}?`, a: `Payment options vary per venue: some take the full amount online, others take a deposit online with the rest paid on arrival at the venue. When there's a deposit, it's part of the total price — never an extra fee.` },
    { q: `Can I cancel a booking in ${c}?`, a: `Cancellation and refund terms are set by each venue, not by Bookboll — check the listing and our Terms page, and contact the venue directly if your plans change.` },
    { q: `Can I host a game or tournament in ${c}?`, a: `Yes — you can host a pickup game, social hangout or tournament at these venues and let players join instantly or after your approval. Head to Events to get started.` },
    { q: `How do I join games other people are hosting in ${c}?`, a: `Browse player-hosted games and tournaments under Events. Some are instant join, others need the host to approve your request — and the price is paid to the host, on arrival or online when the host has enabled online tickets.` },
    { q: `How do I find ${w} near me in ${c}?`, a: `Share your location on the venues page and results are sorted nearest first. You can also browse the list below and check each venue's address before booking.` },
    { q: `Do bookings in ${c} earn rewards points?`, a: `Yes — bookings on the Bookboll marketplace earn rewards points you can redeem at checkout for money off future venue bookings where redemption is available.` },
  ]
})

// Breadcrumb chain (Home → Venues → [Country] → Place → [segment]).
const crumbs = computed(() => {
  if (!place.value) return []
  const out = [{ name: 'Home', path: '/' }, { name: 'All Venues', path: '/facilities' }]
  if (place.value.type === 'city' && place.value.countrySlug && place.value.countryName)
    out.push({ name: place.value.countryName, path: `/venues/${place.value.countrySlug}` })
  out.push({ name: place.value.name, path: placePath.value })
  if (subjectTitle.value) out.push({ name: subjectTitle.value, path: `${placePath.value}/${data.value.segment}` })
  return out
})
// Per-category recruitment banner: derive the category even on a type page.
const bannerCategory = computed(() =>
  data.value.category?.key || (mode.value === 'type' ? (venues.value[0]?.category || '') : ''))

useSeo(() => {
  if (!place.value) return { path: route.path, noindex: true, title: 'Venues' }
  const noindex = venues.value.length === 0    // don't index thin/empty pages
  const ld = [
    { '@context': 'https://schema.org', '@type': 'CollectionPage', name: heading.value, description: intro.value },
    breadcrumbLd(crumbs.value),
    itemListLd(venues.value.map(v => ({ name: v.name, path: `/listings/${v.id}` })), heading.value),
    faqLd(faqs.value),
  ].filter(Boolean)
  return { title: heading.value, description: intro.value, path: route.path, type: 'website', noindex, jsonLd: ld }
})
</script>

<template>
  <div class="cv">
    <div v-if="loading" class="cmsg">Loading…</div>
    <div v-else-if="!place" class="cv-empty"><Building2 :size="34" /><p>We couldn't find that location.</p><router-link to="/venues" class="cv-link">Browse all venues</router-link></div>

    <template v-else>
      <!-- Breadcrumb -->
      <nav class="cv-crumbs" aria-label="Breadcrumb">
        <template v-for="(cr, i) in crumbs" :key="cr.path">
          <ChevronRight v-if="i" :size="13" />
          <router-link v-if="i < crumbs.length - 1" :to="cr.path">{{ cr.name }}</router-link>
          <span v-else aria-current="page">{{ cr.name }}</span>
        </template>
      </nav>

      <h1 class="cv-h1">{{ heading }}</h1>
      <p class="cv-intro">Compare listings and explore venues in {{ place.name }}.{{ subjectTitle ? ' Browse ' + subjectTitle.toLowerCase() + ' below.' : ' Choose an activity to narrow your search.' }}</p>

      <!-- City chips (country places: drill down into a city) -->
      <div v-if="isCountry && data.cities.length" class="cv-chips">
        <router-link v-for="c in data.cities" :key="c.slug" :to="`/venues/${c.slug}`" class="cv-chip cv-chip-cat">
          {{ c.name }} <span class="cv-chip-n">{{ c.count }}</span>
        </router-link>
      </div>

      <CatalogBrowser :listings="data.allListings" :currency="place.currencyCode" :city-name="place.name" :category-base="placePath" :segment-category="data.category?.key || ''" :segment-type="mode === 'type' ? data.typeName || '' : ''" />

      <!-- Cross-links -->
      <div class="cv-cross">
        <router-link to="/programs" class="cv-cross-card"><Dumbbell :size="20" /><div><b>Classes & memberships</b><span>Academies and training in {{ place.name }}</span></div><ChevronRight :size="16" /></router-link>
        <router-link to="/games" class="cv-cross-card"><Ticket :size="20" /><div><b>Games & tournaments</b><span>Join or host an event in {{ place.name }}</span></div><ChevronRight :size="16" /></router-link>
      </div>

      <RecruitBanner :city-name="place.name" :category="bannerCategory" />

      <!-- FAQ (apex only — mirrors the FAQPage JSON-LD) -->
      <section v-if="faqs.length" class="cv-faq">
        <h2>Frequently asked questions</h2>
        <details v-for="(f, i) in faqs" :key="i" class="cv-faq-item">
          <summary>{{ f.q }}</summary>
          <p>{{ f.a }}</p>
        </details>
      </section>
    </template>
  </div>
</template>

<style scoped>
.cv { max-width: 1100px; margin: 0 auto; }
.cmsg { text-align: center; padding: 50px; color: var(--text-light, #94a3b8); }
.cv-empty { text-align: center; color: var(--text-light, #94a3b8); padding: 44px; display: flex; flex-direction: column; align-items: center; gap: 10px; }
.cv-link { color: var(--brand-ink, var(--brand)); font-weight: 700; text-decoration: none; }
.cv-crumbs { display: flex; align-items: center; gap: 6px; font-size: 12.5px; color: var(--text-light, #94a3b8); margin: 4px 0 14px; flex-wrap: wrap; }
.cv-crumbs a { color: var(--text-muted, #64748b); text-decoration: none; font-weight: 600; }
.cv-crumbs a:hover { color: var(--brand-ink, var(--brand)); }
.cv-crumbs span { color: var(--text, #0f172a); font-weight: 700; }
.cv-h1 { font-size: 30px; font-weight: 700; color: var(--text, #0f172a); margin: 0 0 8px; }
.cv-intro { font-size: 15.5px; color: var(--text-secondary, #475569); line-height: 1.6; margin: 0 0 18px; max-width: 760px; }
.cv-chips { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 22px; }
.cv-chip { display: inline-flex; align-items: center; gap: 6px; padding: 7px 14px; border-radius: 999px; border: 1.5px solid var(--border, #e2e8f0); background: var(--surface, #ffffff); color: var(--text-secondary, #334155); font-weight: 700; font-size: 13.5px; text-decoration: none; }
.cv-chip:hover { border-color: var(--brand-accent); color: var(--brand-ink, var(--brand)); }
.cv-chip.on { background: var(--brand); border-color: var(--brand); color: #fff; }
.cv-chip-cat { background: color-mix(in srgb, var(--brand) 8%, var(--surface, white)); border-color: var(--success-border, #99f6e4); color: var(--brand-ink, var(--brand)); }
.cv-chip-cat.on { background: var(--brand); border-color: var(--brand); color: #fff; }
.cv-chip-n { font-size: 11px; opacity: .7; }
.cv-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 16px; justify-items: stretch; }
.cv-grid :deep(.fc) { width: 100%; box-sizing: border-box; }
.cv-cross { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin: 28px 0; }
.cv-cross-card { display: flex; align-items: center; gap: 14px; background: var(--surface, #ffffff); border: 1px solid var(--border, #e2e8f0); border-radius: 14px; padding: 16px 18px; text-decoration: none; color: var(--text, #0f172a); }
.cv-cross-card:hover { border-color: var(--brand-accent); }
.cv-cross-card svg:first-child { color: var(--brand-ink, var(--brand)); flex-shrink: 0; }
.cv-cross-card div { flex: 1; display: flex; flex-direction: column; }
.cv-cross-card b { font-size: 15px; font-weight: 800; }
.cv-cross-card span { font-size: 12.5px; color: var(--text-muted, #64748b); }
.cv-cross-card > svg:last-child { color: var(--text-light, #94a3b8); }
.cv-faq { margin: 8px 0 24px; max-width: 720px; }
.cv-faq h2 { font-size: 20px; font-weight: 800; color: var(--text, #0f172a); margin: 0 0 12px; }
/* Quiet divider-list FAQ (same treatment as the homepage) */
.cv-faq-item { border-bottom: 1px solid var(--border, #e8edf3); }
.cv-faq-item:last-child { border-bottom: none; }
.cv-faq-item summary { display: flex; align-items: center; justify-content: space-between; gap: 14px; list-style: none; padding: 13px 2px; font-size: 14px; font-weight: 600; color: var(--text-secondary, #334155); cursor: pointer; }
.cv-faq-item summary::-webkit-details-marker { display: none; }
.cv-faq-item summary::after { content: '+'; font-size: 17px; font-weight: 400; color: var(--text-light, #94a3b8); flex-shrink: 0; transition: transform .15s; }
.cv-faq-item[open] summary { color: var(--text, #0f172a); }
.cv-faq-item[open] summary::after { transform: rotate(45deg); color: var(--brand-ink, var(--brand)); }
.cv-faq-item summary:hover { color: var(--brand-ink, var(--brand)); }
.cv-faq-item p { font-size: 13.5px; color: var(--text-muted, #64748b); line-height: 1.65; margin: 0; padding: 0 24px 14px 2px; }

/* Mobile — compress the SEO chrome so the first venue card is reachable with minimal scroll.
   Color-agnostic (sizes/spacing/overflow only) to avoid deepening the existing dark-mode gap. */
@media (max-width: 760px) {
  /* Breadcrumb: one quiet line that scrolls sideways instead of wrapping above the h1. */
  .cv-crumbs {
    margin: 2px 0 10px; font-size: 12px;
    flex-wrap: nowrap; overflow-x: auto; white-space: nowrap;
    -webkit-overflow-scrolling: touch; scrollbar-width: none;
  }
  .cv-crumbs::-webkit-scrollbar { display: none; }
  .cv-crumbs a, .cv-crumbs span { flex: 0 0 auto; }
  .cv-h1 { font-size: 22px; margin: 0 0 6px; }
  .cv-intro {
    font-size: 14px; line-height: 1.5; margin: 0 0 12px;
    display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden;
  }
  /* Each chip row becomes a single sideways scroller instead of wrapping into a tall block. */
  .cv-chips {
    flex-wrap: nowrap; overflow-x: auto; -webkit-overflow-scrolling: touch;
    margin-bottom: 12px; scrollbar-width: none;
  }
  .cv-chips::-webkit-scrollbar { display: none; }
  /* Stacked chip rows (cities + categories + types) read as one filter unit, not three bars. */
  .cv-chips + .cv-chips { margin-top: -6px; }
  .cv-chip { flex: 0 0 auto; min-height: 40px; padding: 9px 14px; font-size: 14px; }
  .cv-grid { grid-template-columns: 1fr; gap: 12px; }
  .cv-cross { grid-template-columns: 1fr; gap: 10px; margin: 18px 0; }
  .cv-cross-card { padding: 13px 14px; }
  .cv-faq { margin: 8px 0 20px; }
  .cv-faq h2 { font-size: 17px; margin: 0 0 8px; }
  .cv-faq-item summary { padding: 12px 2px; }
}
</style>
