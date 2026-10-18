<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { selectedCity, selectedCountry } from '../../lib/customerSession.js'
import { loadFacilities, loadLocations } from '../../customer/data.js'
import CatalogBrowser from '../components/CatalogBrowser.vue'
import RecruitBanner from '../components/RecruitBanner.vue'
import { ChevronRight } from 'lucide-vue-next'
import { useSeo, faqLd } from '../../lib/seo.js'
import { isWhiteLabel, activeBrand } from '../../lib/brand.js'

// Venue-booking FAQ — apex marketplace only, mirrored as FAQPage JSON-LD below.
// Answers are product-accurate: payment options vary per venue, the deposit is part of
// the listed price (never a fee on top), and selected slots are held during checkout.
const faqs = computed(() => {
  if (isWhiteLabel.value) return []
  const city = selectedCity.value?.name
  return [
    { q: `How do I book a court or pitch${city ? ' in ' + city : ''} on Bookboll?`,
      a: 'Open a venue, pick a date and an available time slot, and confirm at checkout. Availability is live, and the calendar highlights each day\'s best available slot price.' },
    { q: 'How does payment work for venue bookings?',
      a: 'Payment options vary per venue: some take the full amount online, others take a deposit online with the rest paid on arrival at the venue. You always see the venue\'s options at checkout, and Bookboll adds no booking fees on top of the listed price.' },
    { q: 'Is the deposit an extra charge?',
      a: 'No — the deposit is part of the total price, not a fee on top. You pay that portion online to secure your slot and settle the remaining balance when you arrive at the venue.' },
    { q: 'What\'s included in the price?',
      a: 'The listed price covers the court or space for the time slots you book. Some venues also offer optional extras — like equipment rental — which are priced separately and only added if you choose them at checkout.' },
    { q: 'Is my time slot reserved while I pay?',
      a: 'Yes — the slots you select are held for you for a few minutes while you finish checkout, so nobody else can take them. If you navigate away, you can resume the same booking from where you left off.' },
    { q: `What kinds of venues can I book${city ? ' in ' + city : ''}?`,
      a: 'Padel, football, tennis, basketball, badminton and other courts and pitches — plus gyms, studios, halls and multi-use spaces. Browse by venue, or switch to Listings to compare individual courts and prices.' },
    { q: 'How do I find venues near me?',
      a: 'Choose Closest first in the sort menu, then share your location. You can also pick your city at the top and use the search and filters to narrow by sport or venue type.' },
    { q: 'Can I cancel or reschedule a venue booking?',
      a: 'Cancellation and refund terms are set by each venue, not by Bookboll — check the listing and our Terms page, and contact the venue directly if your plans change.' },
    { q: 'Do venue bookings earn rewards?',
      a: 'Yes — bookings on the Bookboll marketplace earn Bookboll Rewards points, which you can redeem at checkout for money off future venue bookings where redemption is available.' },
    { q: 'Will I get a booking confirmation?',
      a: 'Your confirmation appears in your account instantly. Depending on the venue\'s settings and your notification preferences, updates can also arrive by email, SMS or WhatsApp — you can manage your preferences from your account.' },
  ]
})

useSeo(() => {
  const city = selectedCity.value?.name
  if (isWhiteLabel.value) return { title: `Listings · ${activeBrand.value.name}`, description: `Browse listings and book with ${activeBrand.value.name}.`, path: '/facilities', noindex: true }
  return {
    title: city ? `Sports venues in ${city}` : 'Sports venues — book courts & pitches',
    description: `Browse and book sports courts, pitches and venues${city ? ' in ' + city : ''} by the hour on Bookboll.`,
    path: '/facilities',
    jsonLd: [faqLd(faqs.value)].filter(Boolean),
  }
})

const facilityList = ref([])
const locationList = ref([])
const loading = ref(true)
let loadId = 0
async function load() {
  const current = ++loadId
  loading.value = true
  const cid = selectedCity.value?.id
  const [listings, venues] = await Promise.all([loadFacilities(cid), loadLocations(cid)])
  if (current !== loadId) return
  facilityList.value = listings
  locationList.value = venues
  loading.value = false
}
onMounted(load)
watch(() => selectedCity.value?.id, load)
</script>

<template>
  <div class="browse-page">
    <nav class="browse-crumbs" aria-label="Breadcrumb"><router-link to="/">Home</router-link><ChevronRight :size="14" /><span aria-current="page">{{ isWhiteLabel ? 'Listings' : 'Venues & listings' }}</span></nav>
    <header class="browse-heading">
      <h1>{{ isWhiteLabel ? 'Explore our listings' : 'Find your next place to play' }}</h1>
      <p v-if="isWhiteLabel">Browse by category, compare listings and book with {{ activeBrand.name }}.</p>
      <p v-else>Book courts, studios and spaces{{ selectedCity?.name ? ` in ${selectedCity.name}` : ' near you' }}.</p>
    </header>
    <CatalogBrowser :listings="facilityList" :venues="locationList" :loading="loading" :currency="selectedCountry?.currency_code || 'AED'" :city-name="selectedCity?.name || ''" />
    <RecruitBanner :city-name="selectedCity?.name" />
    <section v-if="faqs.length" class="browse-faq">
      <h2>Questions about booking</h2>
      <details v-for="f in faqs" :key="f.q"><summary>{{ f.q }}</summary><p>{{ f.a }}</p></details>
    </section>
  </div>
</template>

<style scoped>
.browse-page { min-width: 0; }
.browse-crumbs { display: flex; align-items: center; gap: 7px; font-size: 12px; color: var(--text-muted); margin-bottom: 20px; }
.browse-crumbs a { padding-block: 8px; }
.browse-crumbs a:hover { color: var(--brand-ink, var(--brand)); }
.browse-heading { margin-bottom: 24px; }
.browse-heading h1 { font-size: clamp(25px, 3vw, 34px); font-weight: 700; letter-spacing: -.7px; margin: 0 0 9px; }
.browse-heading p { font-size: 15px; color: var(--text-muted); max-width: 600px; line-height: 1.6; }
.browse-faq { margin: 40px 0 20px; max-width: 760px; }
.browse-faq h2 { font-size: 21px; font-weight: 700; margin-bottom: 12px; }
.browse-faq details { border-bottom: 1px solid var(--border); }
.browse-faq summary { padding: 16px 0; font-size: 14px; font-weight: 600; cursor: pointer; }
.browse-faq p { color: var(--text-muted); font-size: 14px; line-height: 1.7; padding-bottom: 16px; }
@media(max-width:760px) {
  .browse-crumbs { margin-bottom: 10px; }
  .browse-heading { margin-bottom: 18px; }
  .browse-heading p { font-size: 14px; }
}
</style>
