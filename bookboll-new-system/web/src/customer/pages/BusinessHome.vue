<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { selectedCity, selectedCountry, isCustomer } from '../../lib/customerSession.js'
import { loadFacilities, loadPrograms } from '../../customer/data.js'
import { activeBrand, brandShows, brandPageEnabled, brandSectionEnabled } from '../../lib/brand.js'
import { visibleBanners, normalizeBanners } from '../../lib/heroBanners.js'
import { catalogCategories, sampleListingPhotos } from '../../lib/browseCatalog.js'
import { loadPublishedPosts, fmtPostDate } from '../../lib/blog.js'
import { useSeo } from '../../lib/seo.js'
import HeroSlideshow from '../components/HeroSlideshow.vue'
import ServiceRow from '../components/ServiceRow.vue'
import VenueCategoryCard from '../components/VenueCategoryCard.vue'
import VisitUs from '../components/VisitUs.vue'
import ProgramCard from '../components/ProgramCard.vue'
import { CalendarCheck, Dumbbell, ArrowRight, Phone, Mail, MessageCircle, Newspaper } from 'lucide-vue-next'

useSeo(() => ({ title: activeBrand.value?.name, noindex: true, path: '/' }))
const brand = computed(() => activeBrand.value || {})
const facilities = ref([])
const programs = ref([])
const blogPosts = ref([])
const loading = ref(true)
let request = 0
async function load() {
  const current = ++request
  loading.value = true
  const cityId = selectedCity.value?.id
  const [f, p] = await Promise.all([
    brandShows('venues') && brandSectionEnabled('listings') ? loadFacilities(cityId) : [],
    brandShows('programs') && brandSectionEnabled('programs') ? loadPrograms(cityId) : [],
  ])
  if (current !== request) return
  facilities.value = f
  programs.value = p
  loading.value = false
}
onMounted(async () => {
  load()
  if (brandShows('blog') && brandSectionEnabled('blog')) blogPosts.value = await loadPublishedPosts({ limit: 3 })
})
watch(() => selectedCity.value?.id, load)
const venueCategories = computed(() => [
  { key: 'all', label: 'All listings', count: facilities.value.length, photos: sampleListingPhotos(facilities.value), to: '/facilities' },
  ...catalogCategories(facilities.value).map(c => ({
    ...c, count: c.listings.length, photos: sampleListingPhotos(c.listings),
    to: { path: '/facilities', query: { category: c.category, ...(c.type ? { type: c.type } : {}) } },
  })),
])
const heroImage = computed(() => String(brand.value.pages?.home?.hero_image || ''))
const heroBgStyle = computed(() => heroImage.value
  ? { backgroundImage: `linear-gradient(90deg, rgba(9,14,28,.8), rgba(9,14,28,.3)), url(${heroImage.value})` }
  : {})
const defaultSlide = computed(() => normalizeBanners([{
  id: 'default-hero', heading: brand.value.name || '',
  subheading: brand.value.tagline || `Explore what's available and book with ${brand.value.name}.`,
  image: heroImage.value, layout: 'left', show_logo: true,
  actions: [...(brandShows('venues') ? ['book_venue'] : []), ...(brandShows('programs') ? ['get_membership'] : [])],
  button_text: brandShows('venues') ? 'Browse listings' : '',
}])[0])
// Preserve provider banners, including audience and city targeting.
const slides = computed(() => {
  const customs = visibleBanners(brand.value.banners || [], {
    signedIn: isCustomer.value, countryId: selectedCountry.value?.id, cityId: selectedCity.value?.id,
  }).filter(b => !(b.heading === (brand.value.name || '') && /^Book your next session at /.test(b.subheading || '')))
  return customs.length ? [defaultSlide.value, ...customs] : []
})
const aboutTeaser = computed(() => {
  const text = String(brand.value.pages?.about?.text || '').trim()
  return text.length > 220 ? text.slice(0, 220).replace(/\s+\S*$/, '') + '…' : text
})
const contact = computed(() => brand.value.pages?.contact || {})
const hasContact = computed(() => brandPageEnabled('contact'))
const waLink = computed(() => `https://wa.me/${String(contact.value.whatsapp || '').replace(/[^\d]/g, '')}`)
</script>

<template>
  <div class="bz">
    <h1 v-if="!brandSectionEnabled('hero')" class="bz-page-title">{{ brand.name }}</h1>
    <HeroSlideshow v-if="brandSectionEnabled('hero') && slides.length" class="bz-banners" :banners="slides" :city="selectedCity?.name" />
    <section v-else-if="brandSectionEnabled('hero')" class="bz-hero" :style="heroBgStyle">
      <div class="bz-hero-inner">
        <h1>{{ brand.name }}</h1>
        <p>{{ brand.tagline || `Explore what's available and book with ${brand.name}.` }}</p>
        <div class="bz-hero-actions">
          <router-link v-if="brandShows('venues')" to="/facilities" class="bz-btn light"><CalendarCheck :size="18" /> Browse listings</router-link>
          <router-link v-if="brandShows('programs')" to="/programs" class="bz-btn ghost"><Dumbbell :size="18" /> Memberships</router-link>
        </div>
      </div>
    </section>
    <div v-if="loading" class="bz-loading" role="status">Loading listings and memberships…</div>
    <template v-else>
      <ServiceRow v-if="brandShows('venues') && brandSectionEnabled('listings')" title="Explore our listings" subtitle="Choose a category, find your listing, then pick a time." show-subtitle view-all="/facilities">
        <VenueCategoryCard v-for="category in venueCategories" :key="category.key" :category="category" />
      </ServiceRow>
      <ServiceRow v-if="brandShows('programs') && brandSectionEnabled('programs')" title="Memberships & programs" subtitle="Find a membership that fits your routine." show-subtitle view-all="/programs">
        <ProgramCard v-for="program in programs.slice(0, 6)" :key="program.id" :program="{ ...program, cityName: selectedCity?.name }" />
        <div v-if="!programs.length" class="bz-empty"><Dumbbell :size="26" /><div><h3>Memberships are coming soon</h3><p>Passes, classes and programs will appear here as they become available.</p><router-link v-if="hasContact" to="/contact">Ask about memberships <ArrowRight :size="15" /></router-link></div></div>
      </ServiceRow>
      <section v-if="aboutTeaser && brandSectionEnabled('about')" class="bz-about">
        <div><h2 class="bz-h2">About {{ brand.name }}</h2><p>{{ aboutTeaser }}</p><router-link to="/about" class="bz-text-link">Read more <ArrowRight :size="16" /></router-link></div>
        <img v-if="brand.logo" :src="brand.logo" :alt="brand.name" loading="lazy" />
      </section>
      <section v-if="brandShows('blog') && brandSectionEnabled('blog')" class="bz-news">
        <div class="bz-section-head"><div><h2 class="bz-h2">News & updates</h2><p>The latest from {{ brand.name }}.</p></div><router-link to="/blog" class="bz-text-link">View all <ArrowRight :size="16" /></router-link></div>
        <div v-if="blogPosts.length" class="bz-blog">
          <router-link v-for="post in blogPosts" :key="post.id" :to="`/post/${post.slug}`" class="bz-post">
            <div class="bz-post-cover"><img v-if="post.cover_image" :src="post.cover_image" alt="" loading="lazy" /><Newspaper v-else :size="28" /></div>
            <div class="bz-post-body"><span>{{ fmtPostDate(post.published_at) }}</span><h3>{{ post.title }}</h3><span class="bz-text-link">Read more <ArrowRight :size="15" /></span></div>
          </router-link>
        </div>
        <div v-else class="bz-empty"><Newspaper :size="26" /><div><h3>More from us soon</h3><p>Check back for news, announcements and updates.</p></div></div>
      </section>
      <VisitUs />
      <section v-if="hasContact && brandSectionEnabled('contact')" class="bz-contact">
        <div><h2 class="bz-h2">How can we help?</h2><p>Get in touch with {{ brand.name }}.</p><router-link to="/contact" class="bz-text-link">Contact details <ArrowRight :size="16" /></router-link></div>
        <div class="bz-contact-actions">
          <a v-if="contact.whatsapp" :href="waLink" target="_blank" rel="noopener" class="bz-contact-link wa"><MessageCircle :size="20" /> WhatsApp us</a>
          <a v-if="contact.phone" :href="`tel:${contact.phone}`" class="bz-contact-link"><Phone :size="18" /><span data-no-translate>{{ contact.phone }}</span></a>
          <a v-if="contact.email" :href="`mailto:${contact.email}`" class="bz-contact-link"><Mail :size="18" /><span data-no-translate>{{ contact.email }}</span></a>
        </div>
      </section>
    </template>
  </div>
</template>

<style scoped>
.bz { display: flex; flex-direction: column; gap: 40px; padding-bottom: 12px; }
.bz .sr { margin-bottom: 0; }
.bz-page-title { margin: 0; font-size: clamp(24px, 3vw, 34px); line-height: 1.2; color: var(--text); }
.bz-hero { display: flex; align-items: center; min-height: 270px; padding: 40px; border-radius: 20px; background: color-mix(in srgb, var(--brand) 28%, #101927); color: #fff; background-size: cover; background-position: center; }
.bz-hero-inner { max-width: 600px; }
.bz-hero h1 { color: #fff; font-size: clamp(28px, 3.5vw, 42px); font-weight: 700; line-height: 1.12; letter-spacing: -.8px; margin: 0 0 14px; overflow-wrap: anywhere; }
.bz-hero p { color: #fff; font-size: 16px; line-height: 1.7; max-width: 520px; margin: 0 0 24px; }
.bz-hero-actions { display: flex; gap: 10px; flex-wrap: wrap; }
.bz-btn { min-height: 46px; display: inline-flex; align-items: center; justify-content: center; gap: 9px; padding: 12px 18px; border-radius: 10px; text-decoration: none; font-size: 14px; font-weight: 650; }
.bz-btn.light { background: #fff; color: #172132; }
.bz-btn.ghost { border: 1px solid #ffffff80; color: #fff; background: #0002; }
.bz-banners { margin: 0; border-radius: 20px; }
.bz-banners :deep(.hs-slide) { min-height: 270px; padding: 36px 40px 48px; }
.bz-banners :deep(.hs-h1) { font-size: clamp(28px, 3.5vw, 42px); line-height: 1.15; }
.bz-banners :deep(.hs-sub) { font-size: 16px; }
.bz-loading { color: var(--text-muted); padding-block: 24px; }
.bz-h2 { font-size: 23px; font-weight: 700; letter-spacing: -.4px; margin: 0; color: var(--text); }
.bz-section-head { display: flex; align-items: center; justify-content: space-between; gap: 20px; margin-bottom: 20px; }
.bz-section-head p, .bz-contact p { color: var(--text-muted); margin: 6px 0 0; font-size: 14px; line-height: 1.6; }
.bz-text-link { display: inline-flex; align-items: center; gap: 8px; min-height: 44px; color: var(--brand-ink, var(--brand)); font-weight: 650; font-size: 14px; }
.bz-empty { width: 100%; display: flex; align-items: flex-start; gap: 16px; padding: 22px; background: var(--surface); border: 1px solid var(--border); border-radius: 14px; }
.bz-empty > svg { color: var(--brand-ink, var(--brand)); flex-shrink: 0; margin-top: 3px; }
.bz-empty h3 { font-size: 16px; margin: 0 0 6px; }
.bz-empty p { color: var(--text-muted); font-size: 14px; line-height: 1.6; }
.bz-empty a { display: inline-flex; gap: 6px; align-items: center; color: var(--brand-ink, var(--brand)); min-height: 44px; margin-top: 4px; font-size: 14px; font-weight: 600; }
.bz-about { display: flex; align-items: center; justify-content: space-between; gap: 40px; padding-block: 30px; border-block: 1px solid var(--border); }
.bz-about > div { max-width: 720px; }
.bz-about p { color: var(--text-secondary); font-size: 15px; line-height: 1.8; margin: 12px 0 4px; }
.bz-about > img { width: 96px; height: 96px; object-fit: contain; border-radius: 16px; flex-shrink: 0; }
.bz-blog { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 18px; }
.bz-post { border: 1px solid var(--border); border-radius: 14px; overflow: hidden; background: var(--surface); }
.bz-post-cover { aspect-ratio: 16/9; display: grid; place-items: center; background: var(--bg); color: var(--brand-ink, var(--brand)); }
.bz-post-cover img { width: 100%; height: 100%; object-fit: cover; }
.bz-post-body { padding: 16px; }
.bz-post-body > span:first-child { font-size: 12px; color: var(--text-muted); }
.bz-post h3 { font-size: 17px; line-height: 1.5; margin: 6px 0; }
.bz-contact { display: flex; align-items: center; justify-content: space-between; gap: 28px; border-top: 1px solid var(--border); padding-top: 32px; }
.bz-contact-actions { display: flex; gap: 10px; flex-wrap: wrap; max-width: 60%; }
.bz-contact-link { display: inline-flex; align-items: center; gap: 9px; min-height: 46px; padding: 12px 16px; border: 1px solid var(--border); border-radius: 10px; background: var(--surface); color: var(--text); font-size: 14px; }
.bz-contact-link span { overflow-wrap: anywhere; min-width: 0; }
.bz-contact-link svg { flex-shrink: 0; }
.bz-contact-link.wa { color: var(--success-ink, #15803d); background: var(--success-bg, #dcfce7); border-color: var(--success-border, #bbf7d0); }
.bz a { text-decoration: none; }
.bz a:focus-visible { outline: 2px solid var(--brand-ink, var(--brand)); outline-offset: 3px; }
@media (max-width: 767px) {
  .bz { gap: 28px; }
  .bz-hero { min-height: 230px; padding: 26px 22px; border-radius: 16px; }
  .bz-hero p { font-size: 14px; margin-bottom: 20px; }
  .bz-hero h1 { font-size: 29px; }
  .bz-banners :deep(.hs-slide) { min-height: 230px; padding: 26px 22px 44px; }
  .bz-banners :deep(.hs-sub) { font-size: 14px; }
  .bz-h2 { font-size: 21px; }
  .bz-about { padding-block: 22px; gap: 16px; }
  .bz-about > img { display: none; }
  .bz-blog { display: flex; overflow-x: auto; scroll-snap-type: x mandatory; gap: 14px; }
  .bz-post { flex: 0 0 82%; scroll-snap-align: start; }
  .bz-section-head { align-items: flex-start; gap: 12px; }
  .bz-section-head > a { flex-shrink: 0; }
  .bz-contact { align-items: flex-start; flex-direction: column; gap: 14px; padding-top: 24px; }
  .bz-contact-actions { width: 100%; max-width: none; }
  .bz-contact-link { flex: 1 1 auto; justify-content: center; }
  .bz-empty { padding: 18px; gap: 12px; }
}
</style>
