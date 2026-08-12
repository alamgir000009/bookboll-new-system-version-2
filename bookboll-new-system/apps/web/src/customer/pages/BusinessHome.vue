<script setup>
/**
 * BusinessHome.vue — the WHITE-LABEL home page, laid out like a professional
 * small-business website (hero → services with CTAs → about teaser → contact
 * strip → closing CTA), NOT like the marketplace browse rows.
 *
 * Hero strategy: the DEFAULT hero is always built from the site settings
 * (logo, hero background image, business name, tagline — all edited in
 * My Website → Logo & name), it is NOT part of the banners module. Custom
 * banners (My Website → Home banners) are EXTRA slides shown after it in
 * the slideshow; with none, the default hero renders as a plain section.
 */
import { ref, computed, onMounted, watch } from 'vue'
import { selectedCity, selectedCountry, isCustomer } from '../../lib/customerSession.js'
import { loadFacilities, loadPrograms } from '../../customer/data.js'
import { activeBrand, brandShows, brandPageEnabled } from '../../lib/brand.js'
import { visibleBanners, normalizeBanners } from '../../lib/heroBanners.js'
import { loadPublishedPosts, fmtPostDate } from '../../lib/blog.js'
import { useSeo } from '../../lib/seo.js'
import HeroSlideshow from '../components/HeroSlideshow.vue'
import FacilityCard from '../components/FacilityCard.vue'
import VisitUs from '../components/VisitUs.vue'
import ProgramCard from '../components/ProgramCard.vue'
import { CalendarCheck, Dumbbell, ArrowRight, Phone, Mail, MapPin, Clock, MessageCircle, Newspaper, Sparkles, Navigation } from 'lucide-vue-next'
import { supabase } from '../../lib/supabase.js'
import { fmt12 } from '../../lib/slots.js'

useSeo(() => ({ title: activeBrand.value?.name, noindex: true, path: '/' }))

const brand = computed(() => activeBrand.value || {})
const facilities = ref([])
const programs = ref([])
const blogPosts = ref([])
const loading = ref(true)

async function load() {
  loading.value = true
  const cityId = selectedCity.value?.id
  const [f, p] = await Promise.all([loadFacilities(cityId), loadPrograms(cityId)])
  facilities.value = f; programs.value = p
  loading.value = false
}
onMounted(async () => {
  load()
  blogPosts.value = await loadPublishedPosts({ limit: 3, ownerId: activeBrand.value?.ownerUserId || null })
})
watch(() => selectedCity.value?.id, load)

// The default hero's background image (My Website → Logo & name). Empty → brand gradient.
const heroImage = computed(() => String(brand.value.pages?.home?.hero_image || ''))
const heroBgStyle = computed(() => heroImage.value
  ? { backgroundImage: `linear-gradient(rgba(9,14,28,.48), rgba(9,14,28,.58)), url(${heroImage.value})`, backgroundSize: 'cover', backgroundPosition: 'center' }
  : {})

// The default hero as a slideshow slide — same content as the plain section below.
const defaultSlide = computed(() => normalizeBanners([{
  id: 'default-hero',
  heading: brand.value.name || '',
  subheading: brand.value.tagline || `Book your next session at ${brand.value.name} — courts, classes and more.`,
  image: heroImage.value,
  layout: 'center', show_logo: true,
  actions: [
    ...(brandShows('venues') ? ['book_venue'] : []),
    ...(brandShows('programs') ? ['get_membership'] : []),
  ],
  button_text: brandShows('venues') ? 'Book Now' : '',
}])[0])

// Hero: the default slide always leads; custom banners are EXTRA slides after it.
// (Banners saved by the old "editable default copy" seeding are filtered out —
// they'd duplicate the default slide exactly.)
const slides = computed(() => {
  const customs = visibleBanners(brand.value.banners || [], {
    signedIn: isCustomer.value, countryId: selectedCountry.value?.id, cityId: selectedCity.value?.id,
  }).filter(b => !(b.heading === (brand.value.name || '') && /^Book your next session at /.test(b.subheading || '')))
  return customs.length ? [defaultSlide.value, ...customs] : []
})

// About teaser — first ~220 chars of the About page text.
const aboutTeaser = computed(() => {
  const t = String(brand.value.pages?.about?.text || '').trim()
  if (!t) return ''
  return t.length > 220 ? t.slice(0, 220).replace(/\s+\S*$/, '') + '…' : t
})
const contact = computed(() => brand.value.pages?.contact || {})
const hasContact = computed(() => brandPageEnabled('contact'))
const waLink = computed(() => `https://wa.me/${String(contact.value.whatsapp || '').replace(/[^\d]/g, '')}`)


function initials(n) { return (n || '?').trim().split(' ').map(s => s[0]).slice(0, 2).join('').toUpperCase() }
</script>

<template>
  <div class="bz">
    <!-- ── HERO: full-bleed, edge to edge. The provider's custom banner
         slideshow when any exist, else the default brand hero. -->
    <div v-if="slides.length" class="bz-bleed bz-heroWrap">
      <HeroSlideshow :banners="slides" :city="selectedCity?.name" />
    </div>
    <section v-else class="bz-hero bz-bleed" :style="heroBgStyle">
      <div class="bz-hero-inner">
        <span v-if="brand.logo" class="bz-hero-logo" :style="{ backgroundImage: `url(${brand.logo})` }"></span>
        <span v-else class="bz-hero-mark">{{ initials(brand.name) }}</span>
        <h1 class="bz-hero-h1">{{ brand.name }}</h1>
        <p class="bz-hero-sub">{{ brand.tagline || `Book your next session at ${brand.name} — courts, classes and more.` }}</p>
        <div class="bz-hero-ctas">
          <router-link v-if="brandShows('venues') && facilities.length" to="/facilities" class="bz-btn light"><CalendarCheck :size="16" /> Book Now</router-link>
          <router-link v-if="brandShows('programs') && programs.length" to="/programs" class="bz-btn ghost"><Dumbbell :size="16" /> Join a Program</router-link>
        </div>
      </div>
    </section>

    <div v-if="loading" class="bz-loading">Loading…</div>
    <template v-else>
      <!-- ── About teaser — right below the hero, with its own CTAs ── -->
      <section v-if="aboutTeaser" class="bz-about">
        <div class="bz-about-txt">
          <h2 class="bz-h2">About {{ brand.name }}</h2>
          <p>{{ aboutTeaser }}</p>
          <div class="bz-about-ctas">
            <router-link v-if="brandShows('venues') && facilities.length" to="/facilities" class="bz-btn primary sm"><CalendarCheck :size="15" /> Book Now</router-link>
            <router-link to="/about" class="bz-btn ghost2 sm">Read More <ArrowRight :size="14" /></router-link>
          </div>
        </div>
        <span v-if="brand.logo" class="bz-about-logo" :style="{ backgroundImage: `url(${brand.logo})` }"></span>
      </section>

      <!-- ── Blog teaser ── -->
      <section v-if="blogPosts.length" class="bz-sec">
        <div class="bz-sec-head">
          <div><h2 class="bz-h2">News &amp; Updates</h2></div>
          <router-link to="/blog" class="bz-more head">All posts <ArrowRight :size="14" /></router-link>
        </div>
        <div class="bz-blog">
          <router-link v-for="p in blogPosts" :key="p.id" :to="`/blog/${p.slug}`" class="bz-post">
            <div class="bz-post-cover" :style="p.cover_image ? { backgroundImage: `url(${p.cover_image})` } : {}">
              <Newspaper v-if="!p.cover_image" :size="22" />
            </div>
            <div class="bz-post-body">
              <div class="bz-post-title">{{ p.title }}</div>
              <div class="bz-post-meta">{{ fmtPostDate(p.published_at) }}</div>
              <span class="bz-post-read">Read More <ArrowRight :size="13" /></span>
            </div>
          </router-link>
        </div>
      </section>

      <!-- ── Venues / booking ── -->
      <section v-if="brandShows('venues') && facilities.length" class="bz-sec">
        <div class="bz-sec-head">
          <div>
            <h2 class="bz-h2">Book a Court or Space</h2>
            <p class="bz-sec-sub">Live availability — reserve your slot in under a minute.</p>
          </div>
          <router-link to="/facilities" class="bz-btn primary sm"><CalendarCheck :size="15" /> Book Now</router-link>
        </div>
        <div class="bz-grid">
          <FacilityCard v-for="f in facilities.slice(0, 6)" :key="f.id" :facility="{ ...f, currency: selectedCountry?.currency_code || 'AED' }" />
        </div>
        <router-link v-if="facilities.length > 6" to="/facilities" class="bz-more">See all {{ facilities.length }} spaces <ArrowRight :size="14" /></router-link>
      </section>

      <!-- ── Memberships ── -->
      <section v-if="brandShows('programs') && programs.length" class="bz-sec alt">
        <div class="bz-sec-head">
          <div>
            <h2 class="bz-h2">Memberships &amp; Programs</h2>
            <p class="bz-sec-sub">Train with us every week — gym, classes and academies.</p>
          </div>
          <router-link to="/programs" class="bz-btn primary sm"><Dumbbell :size="15" /> Join</router-link>
        </div>
        <div class="bz-grid">
          <ProgramCard v-for="p in programs.slice(0, 6)" :key="p.id" :program="{ ...p, cityName: selectedCity?.name }" />
        </div>
      </section>

      <!-- ── Visit Us (shared component — also on /contact) ── -->
      <VisitUs />

      <!-- ── Contact Us: how to reach the business ── -->
      <section v-if="hasContact" class="bz-contact">
        <h2 class="bz-h2 center">Contact Us</h2>
        <div class="bz-ct-row">
          <a v-if="contact.phone" :href="`tel:${contact.phone}`" class="bz-ct"><Phone :size="17" /><span data-no-translate>{{ contact.phone }}</span></a>
          <a v-if="contact.whatsapp" :href="waLink" target="_blank" rel="noopener" class="bz-ct wa"><MessageCircle :size="17" /><span>WhatsApp us</span></a>
          <a v-if="contact.email" :href="`mailto:${contact.email}`" class="bz-ct"><Mail :size="17" /><span data-no-translate>{{ contact.email }}</span></a>
        </div>
        <router-link to="/contact" class="bz-btn ghost2 sm">Contact Us <ArrowRight :size="14" /></router-link>
      </section>

      <!-- ── Closing CTA band ── -->
      <section v-if="brandShows('venues') && facilities.length" class="bz-band">
        <Sparkles :size="20" />
        <div class="bz-band-txt">Ready to play? Slots fill up fast.</div>
        <router-link to="/facilities" class="bz-btn light">Book Now</router-link>
      </section>
    </template>
  </div>
</template>

<style scoped>
.bz { display: flex; flex-direction: column; gap: 40px; padding-bottom: 10px; }
.bz-loading { text-align: center; color: var(--text-light, #94a3b8); padding: 40px 0; }

/* Buttons */
.bz-btn { display: inline-flex; align-items: center; gap: 8px; font-weight: 800; font-size: 14.5px; padding: 12px 24px; border-radius: 999px; text-decoration: none; transition: transform .12s, filter .12s; }
.bz-btn:hover { transform: translateY(-1px); }
.bz-btn.sm { padding: 10px 18px; font-size: 13.5px; }
.bz-btn.primary { background: var(--brand); color: #fff; }
.bz-btn.light { background: var(--surface, #ffffff); color: var(--brand); }
.bz-btn.ghost { background: rgba(255,255,255,.14); color: #fff; border: 1.5px solid rgba(255,255,255,.55); }
.bz-btn.ghost2 { background: var(--surface, #ffffff); color: var(--brand); border: 1.5px solid color-mix(in srgb, var(--brand) 35%, white); }

/* Full-bleed: stretch to the viewport edges from inside the centered column,
   and swallow the main container's top padding so the hero touches the header. */
.bz-bleed { margin-inline: calc(50% - 50vw); margin-top: -18px; }
/* The banner slideshow was designed as the marketplace's compact card
   (min-height 168px). As THIS page's hero it gets real hero proportions —
   scoped here so the marketplace card is untouched. */
.bz-heroWrap :deep(.hs) { border-radius: 0; margin-bottom: 0; }
.bz-heroWrap :deep(.hs-slide) { min-height: 420px; padding: 56px 84px; }
.bz-heroWrap :deep(.hs-h1) { font-size: 40px; line-height: 1.1; }
.bz-heroWrap :deep(.hs-sub) { font-size: 16.5px; margin-top: 12px; }

/* Default hero */
.bz-hero { background: linear-gradient(130deg, var(--brand) 0%, var(--brand-accent) 100%); padding: 72px 24px; text-align: center; color: #fff; }
.bz-hero-logo { display: inline-block; width: 72px; height: 72px; border-radius: 18px; background-size: cover; background-position: center; background-color: var(--surface, #ffffff); box-shadow: 0 6px 22px rgba(0,0,0,.18); margin-bottom: 16px; }
.bz-hero-mark { display: inline-flex; align-items: center; justify-content: center; width: 72px; height: 72px; border-radius: 18px; background: rgba(255,255,255,.16); font-size: 26px; font-weight: 900; margin-bottom: 16px; }
.bz-hero-h1 { font-size: 40px; font-weight: 900; margin: 0 0 8px; line-height: 1.12; color: #fff; }
.bz-hero-sub { font-size: 16px; opacity: .93; margin: 0 auto 22px; max-width: 560px; line-height: 1.55; }
.bz-hero-ctas { display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; }

/* Sections */
.bz-sec.alt { background: color-mix(in srgb, var(--brand) 4%, white); border-radius: 20px; padding: 24px 22px; }
.bz-sec-head { display: flex; align-items: flex-end; justify-content: space-between; gap: 14px; margin-bottom: 16px; flex-wrap: wrap; }
.bz-h2 { font-size: 23px; font-weight: 900; color: #0f172a; margin: 0; }
.bz-h2.center { text-align: center; }
.bz-sec-sub { font-size: 13.5px; color: var(--text-muted, #64748b); margin: 4px 0 0; }
.bz-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 14px; }
.bz-grid > * { width: 100% !important; }
.bz-more { display: inline-flex; align-items: center; gap: 6px; margin-top: 14px; font-size: 14px; font-weight: 800; color: var(--brand); text-decoration: none; }
.bz-more.head { margin-top: 0; }

/* About teaser */
.bz-about { display: flex; align-items: center; gap: 26px; background: var(--surface, #ffffff); border: 1px solid #e8edf3; border-radius: 20px; padding: 26px 28px; }
.bz-about-txt { flex: 1; min-width: 0; }
.bz-about-txt p { font-size: 14.5px; line-height: 1.7; color: #475569; margin: 10px 0 16px; }
.bz-about-ctas { display: flex; gap: 10px; flex-wrap: wrap; }
.bz-about-logo { width: 120px; height: 120px; border-radius: 24px; background-size: cover; background-position: center; flex-shrink: 0; box-shadow: 0 8px 26px rgba(15,23,42,.1); }

/* Blog teaser */
.bz-blog { display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 14px; }
.bz-post { display: flex; flex-direction: row; align-items: stretch; background: var(--surface, #ffffff); border: 1px solid #e8edf3; border-radius: 16px; overflow: hidden; text-decoration: none; transition: transform .12s, box-shadow .12s; }
.bz-post:hover { transform: translateY(-2px); box-shadow: 0 12px 28px rgba(15,23,42,.09); }
.bz-post-cover { width: 132px; min-height: 108px; flex-shrink: 0; background: color-mix(in srgb, var(--brand) 12%, white); background-size: cover; background-position: center; display: flex; align-items: center; justify-content: center; color: var(--brand); }
.bz-post-body { padding: 13px 15px; flex: 1; min-width: 0; display: flex; flex-direction: column; justify-content: center; }
.bz-post-title { font-size: 14.5px; font-weight: 800; color: #0f172a; line-height: 1.35; }
.bz-post-meta { font-size: 12px; color: var(--text-light, #94a3b8); margin: 5px 0 9px; }
.bz-post-read { display: inline-flex; align-items: center; gap: 5px; font-size: 12.5px; font-weight: 800; color: var(--brand); }

/* Contact strip */
.bz-contact { text-align: center; background: var(--surface, #ffffff); border: 1px solid #e8edf3; border-radius: 20px; padding: 26px 22px; }
.bz-ct-row { display: flex; justify-content: center; gap: 12px; flex-wrap: wrap; margin: 16px 0 18px; }
.bz-ct { display: inline-flex; align-items: center; gap: 8px; font-size: 13.5px; font-weight: 700; color: #334155; background: #f8fafc; border: 1px solid #e8edf3; border-radius: 999px; padding: 10px 16px; text-decoration: none; }
.bz-ct.wa { background: #dcfce7; border-color: #bbf7d0; color: #15803d; }
.bz-ct svg { color: var(--brand); flex-shrink: 0; }
.bz-ct.wa svg { color: #16a34a; }

/* Closing band */
.bz-band { display: flex; align-items: center; gap: 14px; background: linear-gradient(130deg, var(--brand), var(--brand-accent)); border-radius: 20px; padding: 22px 26px; color: #fff; flex-wrap: wrap; }
.bz-band-txt { flex: 1; font-size: 17px; font-weight: 800; min-width: 0; }

@media (max-width: 760px) {
  .bz { gap: 30px; }
  .bz-heroWrap :deep(.hs-slide) { min-height: 300px; padding: 34px 22px 46px; }
  .bz-heroWrap :deep(.hs-h1) { font-size: 26px; }
  .bz-heroWrap :deep(.hs-sub) { font-size: 14px; }
  /* Phone: marketplace-style horizontal slide rows — cards at their natural
     width with the next one peeking — instead of a mile-long 1-col stack. */
  .bz-grid { display: flex; overflow-x: auto; gap: 12px; padding: 2px 2px 8px;
    scroll-snap-type: x mandatory; scrollbar-width: none; }
  .bz-grid::-webkit-scrollbar { display: none; }
  .bz-grid > * { width: auto !important; max-width: 78vw; flex: 0 0 auto; scroll-snap-align: start; }
  .bz-hero { padding: 46px 18px; }
  .bz-hero-h1 { font-size: 27px; }
  .bz-hero-sub { font-size: 14px; }
  .bz-h2 { font-size: 19px; }
  .bz-about { flex-direction: column-reverse; text-align: center; padding: 22px 18px; }
  .bz-about-logo { width: 88px; height: 88px; }
  .bz-band { flex-direction: column; text-align: center; }
  .bz-btn { width: 100%; justify-content: center; }
  .bz-btn.sm { width: auto; }
  .bz-hero-ctas .bz-btn { width: auto; }
}
</style>
