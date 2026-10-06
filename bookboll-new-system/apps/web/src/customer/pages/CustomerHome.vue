<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { selectedCity, selectedCountry, isCustomer, customer, loadCustomerLoyalty } from '../../lib/customerSession.js'
import { loadFacilities, loadPrograms } from '../../customer/data.js'
import { loadGames, gamesEnabled } from '../../customer/apiGames.js'
import { brandShows, isWhiteLabel, activeBrand, platformBrand } from '../../lib/brand.js'
import { useSeo, organizationLd, websiteLd, itemListLd, faqLd } from '../../lib/seo.js'
import { loadDirectory, slugifyCity, slugifySport } from '../../lib/cityPages.js'
import { membershipCategoryLabel } from '../../lib/membershipCategories.js'
import { loadPublishedPosts, fmtPostDate } from '../../lib/blog.js'
import ServiceRow from '../components/ServiceRow.vue'
import FacilityCard from '../components/FacilityCard.vue'
import ProgramCard from '../components/ProgramCard.vue'
import GameCard from '../components/GameCard.vue'
import HeroSlideshow from '../components/HeroSlideshow.vue'
import BusinessHome from './BusinessHome.vue'
import RecruitBanner from '../components/RecruitBanner.vue'
import { visibleBanners } from '../../lib/heroBanners.js'
import { loadCommunities } from '../../customer/community.js'
import { avatarColor } from '../../lib/avatarColor.js'
import { Dumbbell, Building2, Ticket, Plus, ArrowRight, Sparkles, MapPin, Newspaper, HelpCircle, CalendarCheck, UsersRound, Lock } from 'lucide-vue-next'

// Hero slideshow banners — provider's on a white-label site, else the marketplace's,
// filtered by audience (signed-in / signed-out / everyone) AND geo (country/city).
const heroBanners = computed(() => {
  const src = isWhiteLabel.value ? (activeBrand.value?.banners || []) : (platformBrand.value?.banners || [])
  return visibleBanners(src, { signedIn: isCustomer.value, countryId: selectedCountry.value?.id, cityId: selectedCity.value?.id })
})
// A system "loyalty" slide is shown FIRST for signed-in apex customers (rewards = platform-only).
const customerFirstName = computed(() => (customer.value?.full_name || '').split(' ')[0] || '')
const loyaltySlide = computed(() => (isCustomer.value && loyalty.value && !isWhiteLabel.value) ? { id: '__loyalty', kind: 'loyalty' } : null)
const slides = computed(() => loyaltySlide.value ? [loyaltySlide.value, ...heroBanners.value] : heroBanners.value)

const facilities = ref([])
const programs = ref([])
const games = ref([])
const communities = ref([])
const loyalty = ref(null)
const loading = ref(true)

// Popular cities (apex only) for the global discoverability section + ItemList JSON-LD.
const popularCities = ref([])
// Latest blog posts for the home "From the blog" row — the marketplace's on apex,
// or the provider's own on their white-label site.
const blogPosts = ref([])
async function load() {
  loading.value = true
  const cityId = selectedCity.value?.id
  const [f, p, g, cm] = await Promise.all([
    loadFacilities(cityId), loadPrograms(cityId), loadGames(cityId),
    isWhiteLabel.value ? Promise.resolve([]) : loadCommunities({ cityId }),   // communities = apex marketplace only
  ])
  facilities.value = f; programs.value = p; games.value = g; communities.value = cm
  loyalty.value = isCustomer.value ? await loadCustomerLoyalty() : null
  loading.value = false
}
onMounted(async () => {
  load()
  // Blog row: marketplace posts on apex, the provider's own on their white-label site.
  blogPosts.value = await loadPublishedPosts({ limit: 3 })
  if (!isWhiteLabel.value) {
    const dir = await loadDirectory()
    popularCities.value = dir.flatMap(g => g.cities.map(c => ({ ...c, country: g.country.name }))).sort((a, b) => b.count - a.count).slice(0, 12)
  }
})
watch(() => selectedCity.value?.id, load)

// Current-city sports for the "Browse by sport" chips (links to the city landing pages).
const citySlug = computed(() => selectedCity.value ? slugifyCity(selectedCity.value.name) : '')
const citySports = computed(() => {
  const t = {}
  for (const f of facilities.value) { if (!f.sport) continue; const sl = slugifySport(f.sport); if (!t[sl]) t[sl] = { name: f.sport, slug: sl } }
  return Object.values(t).slice(0, 10)
})
const cityMemberships = computed(() => {
  const t = {}
  for (const p of programs.value) { const k = p.category || 'academy'; if (!t[k]) t[k] = { key: k, label: membershipCategoryLabel(k) } }
  return Object.values(t)
})

// Marketplace FAQ — apex only (a provider's white-label site must not carry Bookboll
// marketplace answers; those pages are noindex anyway). Every answer is product-accurate:
// payment options vary per venue, the deposit is PART of the listed price (never a fee on
// top), free trials are once per business, rewards are a marketplace-only mechanic,
// hosted-event money goes to the HOST, and cancellation terms sit with the venue/host.
const faqs = computed(() => {
  if (isWhiteLabel.value) return []
  return [
    { q: 'What is Bookboll?',
      a: 'Bookboll is a sports booking marketplace. Book padel, football, tennis and other courts by the hour, join gyms, classes and academies with an online membership, and find local games and tournaments to play in — all with one free account.' },
    { q: 'How do I book a sports venue on Bookboll?',
      a: 'Pick a venue, choose a date and an available time slot, and confirm at checkout. Availability is live, your selected slots are held for you while you finish the booking, and the confirmation appears in your account instantly.' },
    { q: 'Do I pay online or at the venue?',
      a: 'Payment options vary per venue: some take the full amount online, while others take a deposit online with the rest paid on arrival at the venue. Whichever a venue offers, you see the options at checkout before you confirm.' },
    { q: 'Is the deposit an extra fee?',
      a: 'No. When a venue asks for a deposit, it is part of the total listed price — you pay that portion online to secure the slot and settle the remaining balance at the venue. It is never charged on top of the price.' },
    { q: 'Does Bookboll add booking fees for customers?',
      a: 'No. You pay the venue\'s listed price — Bookboll doesn\'t add service or booking fees on top at checkout.' },
    { q: 'How do free trials on memberships work?',
      a: 'Intro offers vary per program — a free first session, a free trial period, or a discounted rate on longer plans — and each program\'s page shows exactly what it includes. With a free trial, your first charge only happens when the trial ends. Free trials are limited to one per business.' },
    { q: 'What are Bookboll Rewards points?',
      a: 'Bookings on the Bookboll marketplace earn rewards points, and higher tiers earn them faster. You can redeem points at checkout for money off venue bookings where redemption is available — as a default, every 10 points takes 1 off in your local currency.' },
    { q: 'Can I play even if I don\'t have a group?',
      a: 'Yes. Browse player-hosted games, hangouts and tournaments near you. Some events are instant join — your spot is confirmed the moment you join — while others need the host to approve your request first; each event page says which. You can also host your own game at any bookable venue.' },
    { q: 'How do I pay for a hosted game or tournament?',
      a: 'The price of a hosted event is paid to the host — usually on arrival, or online when the host has enabled online tickets. Bookboll connects you with the event, but the host runs it and collects the payment.' },
    { q: 'How do I check in for my gym or class membership?',
      a: 'Active memberships come with a digital Membership Pass — a QR code plus a short code, found under Pass in your account. Show it at the venue and staff scan it to record your visit.' },
    { q: 'Can I cancel or change a booking?',
      a: 'Cancellation and refund terms are agreed between you and the venue or host, so they aren\'t the same everywhere. Check the listing and our Terms page, and contact the venue directly if your plans change.' },
    { q: 'Do I need an account to book?',
      a: 'Yes — a free Bookboll account, created with just your email during checkout. It keeps your bookings, memberships, rewards points and games together in one place.' },
  ]
})

useSeo(() => {
  const b = isWhiteLabel.value ? activeBrand.value : platformBrand.value
  const brand = b?.name || 'Bookboll'
  const city = selectedCity.value?.name
  // A provider/marketplace custom SEO title/description (My Website) wins when set.
  const metaTitle = b?.metaTitle
  const metaDescription = b?.metaDescription
  return {
    exactTitle: metaTitle || undefined,
    title: metaTitle ? undefined : (city ? `Book sports venues, classes & events in ${city}` : null),
    description: metaDescription || `${brand} — reserve sports courts and pitches by the hour, join games and tournaments, and sign up for classes and memberships${city ? ' in ' + city : ''}.`,
    path: '/',
    jsonLd: [
      organizationLd(), websiteLd(),
      itemListLd(popularCities.value.map(c => ({ name: `Sports venues in ${c.name}`, path: `/venues/${c.slug}` })), 'Popular cities'),
      faqLd(faqs.value),
    ].filter(Boolean),
  }
})
watch(() => customer.value?.id, async () => { loyalty.value = isCustomer.value ? await loadCustomerLoyalty() : null })
</script>

<template>
  <!-- A provider's white-label site gets the business-website layout, not the
       marketplace browse rows (see BusinessHome.vue). -->
  <BusinessHome v-if="isWhiteLabel" />
  <div v-else class="home">
    <!-- Customizable hero slideshow (super admin → marketplace; provider → their site).
         Filtered by audience (signed-in / signed-out / everyone) + geo (country/city).
         A system "loyalty" slide is prepended FIRST for signed-in apex customers
         (rewards are a platform mechanic, not shown on white-label sites). When there
         are no slides at all, the default welcome hero below shows instead. -->
    <HeroSlideshow v-if="slides.length" :banners="slides" :city="selectedCity?.name"
      :loyalty="loyalty" :customer-name="customerFirstName" />

    <!-- Default welcome banner — only when there are no slides at all. -->
    <div v-if="!slides.length" class="welcome">
      <div class="welcome-text">
        <h1 v-if="isWhiteLabel">Welcome to {{ activeBrand?.name }}<span>.</span></h1>
        <h1 v-else>Play more. Hangout. <span>Near you.</span></h1>
        <p v-if="isWhiteLabel">Your home for booking in {{ selectedCity?.name || 'your city' }}.</p>
        <p v-else>Book courts, join games, and find classes in {{ selectedCity?.name || 'your city' }}.</p>
      </div>
      <router-link v-if="!isCustomer" to="/account" class="welcome-cta"><Sparkles :size="16" /> {{ isWhiteLabel ? 'Sign in' : 'Sign in to earn rewards' }}</router-link>
    </div>

    <div v-if="loading" class="home-loading">Loading what's near you…</div>

    <template v-else>
      <!-- Facilities -->
      <ServiceRow v-if="brandShows('venues')" title="Book a Venue" :icon="CalendarCheck" subtitle="Courts, pitches & venues near you" viewAll="/facilities">
        <FacilityCard v-for="f in facilities.slice(0, 10)" :key="f.id" :facility="{ ...f, currency: selectedCountry?.currency_code || 'AED' }" />
        <router-link v-if="facilities.length" to="/for-business" class="recruit-end">
          <div class="recruit-end-ico"><Plus :size="22" /></div>
          <div class="recruit-end-t">List your venue</div>
          <div class="recruit-end-d">Own a court or venue? Get discovered.</div>
        </router-link>
        <div v-else class="sr-recruit">
          <Building2 :size="38" />
          <span>No venues in {{ selectedCity?.name }} yet.</span>
          <router-link to="/for-business" class="sr-recruit-cta"><Plus :size="16" /> List your venue</router-link>
        </div>
      </ServiceRow>

      <!-- Games -->
      <ServiceRow v-if="brandShows('events')" title="Events" :icon="Ticket" subtitle="Games, casual events — or host your own & set the price" viewAll="/games">
        <template v-if="gamesEnabled !== false">
          <GameCard v-for="g in games.slice(0, 10)" :key="g.id" :game="g" />
          <router-link to="/host-game" class="host-card">
            <div class="host-plus"><Plus :size="26" /></div>
            <div class="host-title">Host &amp; Earn</div>
            <div class="host-sub">Pick a venue, set price per person, invite players</div>
          </router-link>
        </template>
        <div v-else class="empty-card soon"><Ticket :size="28" /><span>Events are coming soon!</span></div>
      </ServiceRow>

      <!-- Programs -->
      <ServiceRow v-if="brandShows('programs')" title="Memberships" :icon="Dumbbell" subtitle="Gyms, training & memberships you can subscribe to" viewAll="/programs">
        <ProgramCard v-for="p in programs.slice(0, 10)" :key="p.id" :program="{ ...p, cityName: selectedCity?.name }" />
        <router-link v-if="programs.length" to="/for-business" class="recruit-end">
          <div class="recruit-end-ico"><Plus :size="22" /></div>
          <div class="recruit-end-t">List your programs</div>
          <div class="recruit-end-d">Run a membership program? Fill it up.</div>
        </router-link>
        <div v-else class="sr-recruit">
          <Dumbbell :size="38" />
          <span>No memberships in {{ selectedCity?.name }} yet.</span>
          <router-link to="/for-business" class="sr-recruit-cta"><Plus :size="16" /> List your programs</router-link>
        </div>
      </ServiceRow>

      <!-- Provider recruitment — main marketplace only (self-hidden on white-label sites). -->
      <RecruitBanner category="business" />

      <!-- Communities (apex marketplace only) -->
      <ServiceRow v-if="!isWhiteLabel" title="Communities" :icon="UsersRound" subtitle="Find your people — join a club, follow its updates and play in its meetups" viewAll="/communities">
        <router-link v-for="cm in communities.slice(0, 10)" :key="cm.id" :to="`/communities/${cm.id}`" class="comm-card">
          <div class="comm-top">
            <span class="comm-av" :style="(cm.avatar_image || cm.cover_image) ? { backgroundImage: `url(${cm.avatar_image || cm.cover_image})` } : { background: avatarColor(cm.name) }">
              <template v-if="!cm.avatar_image && !cm.cover_image">{{ (cm.name || '?')[0].toUpperCase() }}</template>
            </span>
            <span v-if="cm.is_private" class="comm-lock"><Lock :size="11" /> Private</span>
          </div>
          <div class="comm-name">{{ cm.name }}</div>
          <div class="comm-meta">
            <span v-if="cm.sport" class="comm-sport">{{ cm.sport }}</span>
            <span class="comm-count"><UsersRound :size="12" /> {{ cm.memberCount }} member{{ cm.memberCount === 1 ? '' : 's' }}</span>
          </div>
          <span class="comm-cta">View community <ArrowRight :size="13" /></span>
        </router-link>
        <router-link to="/communities/new" class="host-card comm-start">
          <div class="host-plus"><Plus :size="26" /></div>
          <div class="host-title">Start a community</div>
          <div class="host-sub">Your club, your people — post updates, chat and host meetups</div>
        </router-link>
      </ServiceRow>

      <!-- From the blog — latest posts (marketplace's on apex, provider's on their site) + View all.
           Shown BELOW the "For businesses" banner. -->
      <section v-if="blogPosts.length" class="bloghome">
        <div class="bh-head">
          <div>
            <h2 class="bh-title"><Newspaper :size="20" class="bh-title-ico" /> From the blog</h2>
            <p class="bh-sub">Tips, guides and stories for players, hosts and venue owners</p>
          </div>
          <router-link to="/blog" class="bh-viewall">View all <ArrowRight :size="15" /></router-link>
        </div>
        <div class="bh-grid">
          <router-link v-for="p in blogPosts" :key="p.id" :to="`/blog/${p.slug}`" class="bh-card">
            <div class="bh-cover" :style="p.cover_image ? { backgroundImage: `url(${p.cover_image})` } : {}">
              <span v-if="!p.cover_image" class="bh-cover-ph"><Newspaper :size="24" /></span>
            </div>
            <div class="bh-body">
              <div v-if="p.tags?.length" class="bh-tags"><span v-for="t in p.tags.slice(0, 2)" :key="t" class="bh-tag">{{ t }}</span></div>
              <h3 class="bh-card-title">{{ p.title }}</h3>
              <p v-if="p.excerpt" class="bh-excerpt">{{ p.excerpt }}</p>
              <div class="bh-foot">
                <span class="bh-meta">{{ p.author_name }}<span v-if="p.published_at"> · {{ fmtPostDate(p.published_at) }}</span></span>
                <span class="bh-read">Read <ArrowRight :size="14" /></span>
              </div>
            </div>
          </router-link>
        </div>
      </section>

      <!-- Marketplace FAQ (apex only — mirrors the FAQPage JSON-LD above).
           Sits directly under the blog row per design. -->
      <section v-if="faqs.length" class="home-faq">
        <h2><HelpCircle :size="17" /> Frequently asked questions</h2>
        <details v-for="(f, i) in faqs" :key="i" class="home-faq-item">
          <summary>{{ f.q }}</summary>
          <p>{{ f.a }}</p>
        </details>
      </section>

      <!-- Explore the current city: venues + memberships in one cohesive panel.
           The heading carries the city, so chip labels stay short (clean UI); the
           keyword-rich "{sport} in {city}" anchors live in the sitewide footer hub. -->
      <section v-if="!isWhiteLabel && (citySports.length || cityMemberships.length)" class="explore">
        <h2 class="explore-h"><MapPin :size="17" /> Explore {{ selectedCity?.name }}</h2>
        <div class="explore-rows">
          <div v-if="citySports.length" class="explore-row">
            <span class="explore-label">Venues</span>
            <div class="explore-chips">
              <router-link v-for="s in citySports" :key="s.slug" :to="`/venues/${citySlug}/${s.slug}`" class="chip" :aria-label="`${s.name} in ${selectedCity?.name}`">{{ s.name }}</router-link>
              <router-link :to="`/venues/${citySlug}`" class="chip chip-all">All venues <ArrowRight :size="13" /></router-link>
            </div>
          </div>
          <div v-if="cityMemberships.length" class="explore-row">
            <span class="explore-label">Memberships</span>
            <div class="explore-chips">
              <router-link v-for="m in cityMemberships" :key="m.key" :to="`/memberships/${citySlug}/${m.key}`" class="chip" :aria-label="`${m.label} in ${selectedCity?.name}`">{{ m.label }}</router-link>
              <router-link :to="`/memberships/${citySlug}`" class="chip chip-all">All memberships <ArrowRight :size="13" /></router-link>
            </div>
          </div>
        </div>
      </section>

      <!-- Popular cities across the platform (global discoverability) -->
      <section v-if="!isWhiteLabel && popularCities.length" class="cities">
        <div class="cities-head">
          <h2>Browse by city</h2>
          <router-link to="/venues" class="cities-all">All cities <ArrowRight :size="14" /></router-link>
        </div>
        <div class="cities-grid">
          <router-link v-for="c in popularCities" :key="c.slug" :to="`/venues/${c.slug}`" class="city">
            <span class="city-main">
              <span class="city-name">{{ c.name }}</span>
              <span class="city-country">{{ c.country }}</span>
            </span>
            <span v-if="c.count" class="city-count">{{ c.count }}</span>
          </router-link>
        </div>
      </section>
    </template>
  </div>
</template>

<style scoped>
.home { padding-top: 4px; }

/* Welcome */
.welcome { display: flex; align-items: center; justify-content: space-between; gap: 16px; background: linear-gradient(135deg, var(--brand-accent), var(--brand)); border-radius: 20px; padding: 26px 24px; margin-bottom: 26px; color: #fff; flex-wrap: wrap; }
.welcome-text h1 { font-size: 30px; font-weight: 900; margin: 0; line-height: 1.1; color: #fff; }
.welcome-text h1 span { color: #fbbf24; }
.welcome-text p { font-size: 15px; margin: 8px 0 0; opacity: .92; }
.welcome-cta { display: inline-flex; align-items: center; gap: 8px; background: var(--surface, #ffffff); color: var(--brand); font-weight: 800; padding: 13px 20px; border-radius: 999px; text-decoration: none; font-size: 15px; }

.home-loading { text-align: center; padding: 60px; color: var(--text-light, #94a3b8); }

/* extra cards in the sliders */
/* End-of-row provider recruit card (replaces the redundant "See all" card) */
.recruit-end { width: 180px; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 7px; padding: 20px 16px; background: var(--surface, #ffffff); border: 2px dashed #cbd5e1; border-radius: 16px; text-decoration: none; text-align: center; transition: border-color .12s, background .12s; }
.recruit-end:hover { border-color: #10b981; background: #f0fdf4; }
.recruit-end-ico { width: 44px; height: 44px; border-radius: 50%; background: #ecfdf5; color: #059669; display: flex; align-items: center; justify-content: center; }
.recruit-end-t { font-size: 14.5px; font-weight: 800; color: #065f46; }
.recruit-end-d { font-size: 12px; color: var(--text-light, #94a3b8); font-weight: 600; line-height: 1.35; }
.empty-card { width: 280px; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 10px; background: var(--surface, #ffffff); border: 1px solid var(--border, #e2e8f0); border-radius: 16px; color: var(--text-light, #94a3b8); padding: 30px; text-align: center; font-size: 14px; font-weight: 600; }
.empty-card.soon { background: linear-gradient(135deg,#eef2ff,#e0e7ff); border-color: #c7d2fe; color: #4338ca; }
.host-card { width: 230px; display: flex; flex-direction: column; gap: 6px; padding: 20px; background: linear-gradient(135deg, #10b981, #059669); border: none; border-radius: 16px; text-decoration: none; transition: transform .12s, box-shadow .12s; }
.host-card:hover { transform: translateY(-3px); box-shadow: 0 14px 30px rgba(5,150,105,.32); }
.host-plus { width: 46px; height: 46px; border-radius: 14px; background: rgba(255,255,255,.22); color: #fff; display: flex; align-items: center; justify-content: center; margin-bottom: 6px; }
.host-title { font-size: 18px; font-weight: 800; color: #fff; }
.host-sub { font-size: 13px; color: rgba(255,255,255,.92); line-height: 1.4; }

/* Communities row — compact club cards (avatar-led, text-first like GameCard) */
.comm-card { width: 250px; display: flex; flex-direction: column; background: var(--surface, #ffffff); border: 1px solid #e8edf3; border-radius: 16px; padding: 16px; text-decoration: none; transition: transform .12s, box-shadow .12s; box-shadow: 0 1px 2px rgba(15,23,42,.05), 0 5px 16px rgba(15,23,42,.07); }
.comm-card:hover { transform: translateY(-3px); box-shadow: 0 12px 26px rgba(15,23,42,.10); }
.comm-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 8px; }
.comm-av { width: 52px; height: 52px; border-radius: 50%; background-size: cover; background-position: center; color: #fff; font-size: 20px; font-weight: 900; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.comm-lock { display: inline-flex; align-items: center; gap: 4px; font-size: 10.5px; font-weight: 800; text-transform: uppercase; letter-spacing: .04em; color: #92400e; background: #fef3c7; padding: 3px 9px; border-radius: 999px; }
.comm-name { font-size: 15.5px; font-weight: 800; color: #0f172a; margin-top: 10px; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.comm-meta { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-top: 6px; }
.comm-sport { font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: .04em; color: var(--brand); background: color-mix(in srgb, var(--brand) 10%, white); padding: 3px 9px; border-radius: 999px; }
.comm-count { display: inline-flex; align-items: center; gap: 4px; font-size: 12.5px; color: var(--text-muted, #64748b); font-weight: 600; }
.comm-cta { display: inline-flex; align-items: center; gap: 4px; font-size: 13px; font-weight: 800; color: var(--brand); margin-top: auto; padding-top: 12px; }
.comm-start { background: linear-gradient(135deg, #0d9488, #0f766e); }
.comm-start:hover { box-shadow: 0 14px 30px rgba(13,148,136,.32); }

/* Empty row → low-key centered recruit (muted icon + line, then a green CTA) */
.sr-recruit { flex: 1 0 100%; width: 100%; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 13px; padding: 40px 20px; color: var(--text-light, #94a3b8); text-align: center; }
.sr-recruit > span { font-size: 14.5px; font-weight: 600; }
.sr-recruit-cta { display: inline-flex; align-items: center; gap: 7px; background: #059669; color: #fff; font-weight: 800; padding: 11px 20px; border-radius: 999px; text-decoration: none; font-size: 14.5px; }

/* From the blog — latest posts row. margin-top separates it from the recruit
   banner above (the banner carries no bottom margin of its own). */
.bloghome { margin-top: 48px; margin-bottom: 48px; }
.bh-head { display: flex; align-items: flex-end; justify-content: space-between; gap: 12px; margin-bottom: 14px; }
.bh-title { font-size: 21px; font-weight: 800; color: #0f172a; margin: 0; display: flex; align-items: center; gap: 9px; }
.bh-title-ico { color: var(--brand); flex-shrink: 0; }
.bh-sub { font-size: 13.5px; color: var(--text-muted, #64748b); margin: 3px 0 0; }
.bh-viewall { display: flex; align-items: center; gap: 5px; font-size: 14px; font-weight: 700; color: var(--brand); text-decoration: none; white-space: nowrap; flex-shrink: 0; }
.bh-viewall:hover { text-decoration: underline; }
.bh-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; }
.bh-card { display: flex; flex-direction: column; background: var(--surface, #ffffff); border: 1px solid var(--border, #e2e8f0); border-radius: 16px; overflow: hidden; text-decoration: none; transition: transform .12s, box-shadow .12s; box-shadow: 0 1px 2px rgba(15,23,42,.05), 0 5px 16px rgba(15,23,42,.07); }
.bh-card:hover { transform: translateY(-2px); box-shadow: 0 12px 30px rgba(15,23,42,.1); }
.bh-cover { height: 150px; background: linear-gradient(135deg, var(--brand-accent), var(--brand)); background-size: cover; background-position: center; display: flex; align-items: center; justify-content: center; color: rgba(255,255,255,.9); }
.bh-body { padding: 14px 16px 16px; display: flex; flex-direction: column; flex: 1; }
.bh-tags { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 7px; }
.bh-tag { font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: .04em; color: var(--brand); background: color-mix(in srgb, var(--brand) 12%, white); padding: 2px 8px; border-radius: 999px; }
.bh-card-title { font-size: 16.5px; font-weight: 800; color: #0f172a; margin: 0 0 6px; line-height: 1.3; }
.bh-excerpt { font-size: 13.5px; color: var(--text-muted, #64748b); line-height: 1.5; margin: 0 0 13px; flex: 1; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.bh-foot { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
.bh-meta { font-size: 12px; color: var(--text-light, #94a3b8); font-weight: 600; }
.bh-read { display: inline-flex; align-items: center; gap: 5px; font-size: 12.5px; font-weight: 800; color: var(--brand); flex-shrink: 0; }
@media (max-width: 860px) { .bh-grid { grid-template-columns: 1fr 1fr; } .bh-grid > :nth-child(3) { display: none; } }
@media (max-width: 560px) { .bh-grid { grid-template-columns: 1fr; } .bh-grid > :nth-child(n+3) { display: none; } }

/* Global discoverability sections (browse by sport / by city) */
/* Explore current city — quiet ghost-chip rows, no outer card (matches the FAQ's
   footnote register: navigation aids, not a feature block) */
.explore { margin: 48px 0 48px; }
.explore-h { display: flex; align-items: center; gap: 8px; font-size: 18px; font-weight: 800; color: #0f172a; margin: 0 0 14px; }
.explore-h svg { color: var(--brand); }
.explore-rows { display: flex; flex-direction: column; gap: 16px; }
.explore-row { display: flex; flex-direction: column; gap: 8px; }
.explore-label { font-size: 11px; font-weight: 800; letter-spacing: .06em; text-transform: uppercase; color: var(--text-light, #94a3b8); }
.explore-chips { display: flex; flex-wrap: wrap; gap: 7px; align-items: center; }
.chip { display: inline-flex; align-items: center; gap: 5px; font-size: 12.5px; font-weight: 600; color: var(--text-muted, #64748b); background: none; border: 1px solid var(--border, #e8edf3); border-radius: 999px; padding: 6px 13px; text-decoration: none; transition: border-color .12s, color .12s; }
.chip:not(.chip-all) { text-transform: capitalize; }
.chip:hover { border-color: var(--brand-accent); color: var(--brand); }
.chip-all { border: none; padding: 6px 4px; color: var(--brand); font-weight: 700; }
.chip-all svg { transition: transform .12s; }
.chip-all:hover svg { transform: translateX(2px); }

/* Browse by city — same ghost pills: name + muted country + count, no card boxes */
.cities { margin: 48px 0 48px; }
.cities-head { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; margin-bottom: 12px; }
.cities-head h2 { font-size: 18px; font-weight: 800; color: #0f172a; margin: 0; }
.cities-all { display: inline-flex; align-items: center; gap: 5px; font-size: 13.5px; font-weight: 700; color: var(--brand); text-decoration: none; }
.cities-all svg { transition: transform .12s; }
.cities-all:hover svg { transform: translateX(2px); }
.cities-grid { display: flex; flex-wrap: wrap; gap: 8px; }
.city { display: inline-flex; align-items: center; gap: 8px; background: none; border: 1px solid var(--border, #e8edf3); border-radius: 999px; padding: 7px 14px; text-decoration: none; transition: border-color .12s; }
.city:hover { border-color: var(--brand-accent); }
.city:hover .city-name { color: var(--brand); }
.city-main { display: inline-flex; align-items: baseline; gap: 6px; min-width: 0; }
.city-name { font-size: 13px; font-weight: 700; color: #334155; line-height: 1.2; transition: color .12s; }
.city-country { font-size: 11.5px; color: var(--text-light, #94a3b8); white-space: nowrap; }
.city-count { font-size: 11.5px; font-weight: 700; color: var(--text-light, #94a3b8); flex-shrink: 0; }

/* For-businesses banner joins the same 48px section rhythm on the home page */
.home .recruit { margin-top: 0; margin-bottom: 48px; }

/* Marketplace FAQ (apex only) — quiet divider list, no cards: the answers are
   reference content, not a feature, so it should read like a footnote section. */
.home-faq { margin: 48px 0 48px; max-width: 720px; }
.home-faq h2 { display: flex; align-items: center; gap: 8px; font-size: 18px; font-weight: 800; color: #0f172a; margin: 0 0 4px; }
.home-faq h2 svg { color: var(--brand); flex-shrink: 0; }
.home-faq-item { border-bottom: 1px solid var(--border, #e8edf3); }
.home-faq-item:last-child { border-bottom: none; }
.home-faq-item summary { display: flex; align-items: center; justify-content: space-between; gap: 14px; list-style: none; padding: 13px 2px; font-size: 14px; font-weight: 600; color: #334155; cursor: pointer; }
.home-faq-item summary::-webkit-details-marker { display: none; }
.home-faq-item summary::after { content: '+'; font-size: 17px; font-weight: 400; color: var(--text-light, #94a3b8); flex-shrink: 0; transition: transform .15s; }
.home-faq-item[open] summary { color: #0f172a; }
.home-faq-item[open] summary::after { transform: rotate(45deg); color: var(--brand); }
.home-faq-item summary:hover { color: var(--brand); }
.home-faq-item p { font-size: 13.5px; color: var(--text-muted, #64748b); line-height: 1.65; margin: 0; padding: 0 24px 14px 2px; }
.sr-recruit-cta:hover { background: #047857; }

/* ── Phone (≤760px): reclaim vertical space above the first browse row so the
   primary action ("Book a venue") rises toward the fold. CSS-only, mobile-only;
   no markup/logic changes, desktop untouched, dark-mode-safe (gradient kept). ── */
@media (max-width: 760px) {
  /* Welcome hero — the single biggest thing pushing browse rows below the fold */
  .welcome { padding: 18px 16px; margin-bottom: 16px; border-radius: 16px; gap: 10px; }
  .welcome-text h1 { font-size: 22px; }
  .welcome-text p { font-size: 13.5px; margin-top: 6px; }
  .welcome-cta { width: 100%; justify-content: center; padding: 12px 16px; }

  /* ── ONE section rhythm, guaranteed equal ──────────────────────────────────
     Per-section margins can never look even: sibling margins collapse, and each
     component (hero 26px, recruit banner 32/36px, rows 28px) brought its own.
     So on the phone the page becomes a flex column with a single gap, and every
     direct child's own vertical margins are zeroed — equal spacing by
     construction, whichever sections happen to render. */
  .home { display: flex; flex-direction: column; gap: 28px; }
  .home > * { margin-top: 0 !important; margin-bottom: 0 !important; }
  /* Extra air ONLY between the lower content sections (For businesses, blog,
     FAQ, Explore, Browse by city) — the browse rows above keep the tight 28px.
     Flex gap + margin add up, so each of these breaks reads ~52px. */
  .home > .bloghome, .home > .home-faq,
  .home > .explore, .home > .cities { margin-top: 24px !important; }

  /* ── De-clutter ────────────────────────────────────────────────────────────
     One recruitment message is enough on a phone: the big "For businesses"
     banner stays; the little "List your venue/programs" cards at the end of
     each card row go — they repeated the same pitch three times mid-browse. */
  .recruit-end { display: none; }
  /* Calm the banner itself: heading at section scale (not competing with the
     page h1) and the 4-perk list folded away — the /for-business page has it. */
  :deep(.recruit-text h2) { font-size: 19px; }
  :deep(.recruit-perks) { display: none; }

  /* Normalize section headings to one 16-17px level (page h1 stays 22px) */
  .bh-title { font-size: 17px; }
  .bh-sub { display: none; } /* restates what the title + cards already show */
  .explore-h { font-size: 16px; margin-bottom: 10px; }
  .cities-head h2 { font-size: 16px; }
  .home-faq h2 { font-size: 17px; }

  /* Blog cards: intentional 16:9 covers (was a ~2.3:1 letterbox strip at 1-col),
     card-title scale + tags up to the 11px floor */
  .bh-cover { height: auto; aspect-ratio: 16 / 9; }
  .bh-card-title { font-size: 16px; }
  .bh-tag { font-size: 11px; letter-spacing: 0; }

  /* Host & Earn promo card sits in the games slider — match real card-title scale */
  .host-title { font-size: 16px; }
  .host-sub { font-size: 12.5px; }

  /* Empty-row recruit state: same message + CTA, tighter footprint */
  .sr-recruit { padding: 24px 16px; gap: 10px; }

  /* Cities: two-up + cap the phone list at 8 (full list still on /venues) */
  .cities-grid { grid-template-columns: 1fr 1fr; gap: 8px; }
  .cities-grid > .city:nth-child(n+9) { display: none; }

  /* Comfortable tap targets on the explore chips (≥44px tall) */
  .chip { padding: 12px 14px; font-size: 13.5px; }
  .explore-chips { gap: 8px; }
  .sr-recruit-cta { padding: 13px 20px; }

  /* Blog head: let "View all" wrap under the title on very narrow phones
     (long auto-translated labels + nowrap link could overflow at 344px) */
  .bh-head { flex-wrap: wrap; }
}

/* ── Very narrow phones (≤380px, incl. 344px foldables): 2-up city cards get too
   cramped (~70-85px left for the name after pin + padding + count badge), so go
   1-col and cap the list at 6 (full list still on /venues). Mobile-only. ── */
@media (max-width: 380px) {
  .cities-grid { grid-template-columns: 1fr; }
  .cities-grid > .city:nth-child(n+7) { display: none; }
}
</style>
