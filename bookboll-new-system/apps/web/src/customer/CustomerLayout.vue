<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { selectedCity, selectedCountry, customer, isCustomer, customerSignOut, customerTheme, toggleCustomerTheme, locationPrompted, markLocationPrompted } from '../lib/customerSession.js'
import { activeBrand, isWhiteLabel, brandShows, brandPageEnabled, platformBrand, marketplaceUrl } from '../lib/brand.js'
import { useStorefrontTracking } from '../lib/tracking.js'
import { isEmbedded, isScopedEmbed } from '../lib/embed.js'
import { slugifyCity } from '../lib/cityPages.js'
import { unreadMessages, refreshUnread } from '../lib/social.js'
import LocationGate from './components/LocationGate.vue'
import AuthGate from './components/AuthGate.vue'
import { ensureSignedIn } from '../lib/authGate.js'
import CheckoutResumeCard from './components/CheckoutResumeCard.vue'
import BracketUpdatePrompt from './components/BracketUpdatePrompt.vue'
import ExploreHub from './components/ExploreHub.vue'
import NotificationBell from './components/NotificationBell.vue'
import LanguageSwitcher from '../components/LanguageSwitcher.vue'
import BrandMark from '../components/BrandMark.vue'
import { adminLoginUrl } from '../lib/crossApp.js'
import { MapPin, ChevronDown, User, LogOut, Shield, Home, Building2, Dumbbell, UsersRound, Trophy, CalendarCheck, Moon, Sun, Settings, Newspaper, MessageCircle, Globe2, MapPinOff, Ticket, Info, Phone } from 'lucide-vue-next'

const router = useRouter()
const route = useRoute()
const showGate = ref(false)
const menuOpen = ref(false)

// FLOW pages: the visitor is mid-process (booking, joining a membership, hosting) — the
// page's own docked action bar owns the bottom edge, so the mobile bottom nav gets out
// of the way. Desktop side-nav is unaffected (CSS is phone-only).
const inFlow = computed(() => /^\/(listings|programs|games|tournaments)\/[^/]+/.test(route.path) || route.path === '/host-game')

// Dark mode marker: the invert filter lives on <html> (see the global style block —
// a filter anywhere lower breaks every position:fixed element), but it must only
// apply while THIS shell is mounted, never on the admin dashboard. cust-dark alone
// persists across areas; cust-shell scopes it to the storefront.
onMounted(() => document.documentElement.classList.add('cust-shell'))
onUnmounted(() => document.documentElement.classList.remove('cust-shell'))

// Storefront analytics/pixels. Runs ONLY here (the customer shell), so tags never load in the
// admin dashboard. Injects the ACTIVE brand's config: a provider's own tags on their site, the
// marketplace's tags on the apex — so a provider only ever measures their own storefront.
useStorefrontTracking()

// Embedded widget mode (Embed & Widgets snippets). `embedded` strips marketplace chrome
// (Explore hub, admin login); `scopedEmbed` (venues/programs widgets) also drops the global
// side-nav so the iframe stays locked to its one flow. Captured once at boot.
const embedded = isEmbedded()
const scopedEmbed = isScopedEmbed()

// "We don't serve here" prompt (white-label only): show when the visitor's chosen city
// isn't one this provider serves, or the provider has no listed cities at all.
const brandRegions = computed(() => activeBrand.value?.regions || null)
const servedCityIds = computed(() => new Set((brandRegions.value?.cities || []).map(c => c.id)))
const servedCount = computed(() => (brandRegions.value?.cities || []).length)
const servesNowhere = computed(() => isWhiteLabel.value && !!brandRegions.value && servedCount.value === 0)
const unservedHere = computed(() => isWhiteLabel.value && !!brandRegions.value && !!selectedCity.value && !servedCityIds.value.has(selectedCity.value.id))
const showLocPrompt = computed(() => servesNowhere.value || unservedHere.value)
const brandLabel = computed(() => activeBrand.value?.name || 'This site')
const bookbollCityUrl = computed(() => marketplaceUrl(selectedCity.value ? `/venues/${slugifyCity(selectedCity.value.name)}` : '/'))

// First visit (apex only): auto-open the location modal once, AFTER the homepage has painted,
// so the visitor can confirm/correct the auto-picked city. It's a non-blocking overlay (content
// stays behind it) and dismissible; we persist that we've prompted so it never re-opens for
// returning users or SEO crawlers. The location chip still opens it on demand any time.
onMounted(() => { if (!isWhiteLabel.value && !locationPrompted.value) showGate.value = true })

// Unread DM badge — poll lightly while signed in on the apex marketplace (social = apex only).
let unreadTimer = null
function startUnread() {
  if (unreadTimer) return
  if (isCustomer.value && !isWhiteLabel.value) {
    refreshUnread(customer.value.id)
    unreadTimer = setInterval(() => refreshUnread(customer.value?.id), 30000)
  }
}
onMounted(startUnread)
watch(() => customer.value?.id, () => { unreadMessages.value = 0; startUnread() })
onUnmounted(() => { if (unreadTimer) clearInterval(unreadTimer) })

function onGateDone() { showGate.value = false; markLocationPrompted() }
function onGateDismiss() { showGate.value = false; markLocationPrompted() }
function initials(n) { return (n || '?').trim().split(' ').map(s => s[0]).slice(0, 2).join('').toUpperCase() }
function signOut() { customerSignOut(); menuOpen.value = false; router.push('/') }
</script>

<template>
  <div class="cust" :class="{ 'cust-embed': embedded, 'cust-embed-scoped': scopedEmbed, 'cust-inflow': inFlow }">
    <!-- Header -->
    <header class="cust-header">
      <div class="cust-header-inner">
        <div class="cust-logohead">
          <router-link to="/" class="cust-logo" data-no-translate>
            <span v-if="isWhiteLabel ? activeBrand.logo : platformBrand.logo" class="cust-logo-img" :style="{ backgroundImage: `url(${isWhiteLabel ? activeBrand.logo : platformBrand.logo})` }"></span>
            <span v-else-if="isWhiteLabel" class="cust-logo-mark">{{ initials(activeBrand.name) }}</span>
            <span v-else class="cust-logo-mark"><BrandMark /></span>
            <span class="cust-logo-name">{{ isWhiteLabel ? activeBrand.name : (platformBrand.name || 'Bookboll') }}</span>
          </router-link>
          <!-- Free white-label sites: attribution right in the header (premium
               white-label keeps only the footer credit) -->
          <a v-if="isWhiteLabel && !activeBrand.whitelabelBranding" class="cust-pow-head"
             :href="marketplaceUrl()" target="_blank" rel="noopener" data-no-translate>
            Powered by <b>{{ platformBrand.name || 'Bookboll' }}</b>
          </a>
        </div>

        <button class="cust-city" @click="showGate = true" :title="$t('header.changeLocation')">
          <MapPin :size="15" />
          <span class="cust-city-name">{{ selectedCity?.name || $t('header.setLocation') }}</span>
          <ChevronDown :size="14" />
        </button>

        <div class="cust-header-right">
          <LanguageSwitcher compact />
          <button class="cust-theme" @click="toggleCustomerTheme" :title="customerTheme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'" :aria-label="customerTheme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'">
            <Sun v-if="customerTheme === 'dark'" :size="18" />
            <Moon v-else :size="18" />
          </button>
          <router-link v-if="isCustomer && !isWhiteLabel" to="/messages" class="cust-msgs" title="Messages" aria-label="Messages">
            <MessageCircle :size="18" />
            <span v-if="unreadMessages" class="cust-msgs-badge">{{ unreadMessages > 9 ? '9+' : unreadMessages }}</span>
          </router-link>
          <NotificationBell v-if="isCustomer && !isWhiteLabel" />
          <template v-if="isCustomer">
            <div class="cust-acct" @click="menuOpen = !menuOpen">
              <div class="cust-avatar" :class="{ img: customer.avatar_url }" :style="customer.avatar_url ? { backgroundImage: `url(${customer.avatar_url})` } : {}" data-no-translate><span v-if="!customer.avatar_url">{{ initials(customer.full_name) }}</span></div>
              <span class="cust-acct-name" data-no-translate>{{ (customer.full_name || '').split(' ')[0] }}</span>
            </div>
            <div v-if="menuOpen" class="cust-menu" @mouseleave="menuOpen = false">
              <router-link v-if="!isWhiteLabel" :to="`/u/${customer.id}`" class="cust-menu-item" @click="menuOpen=false"><User :size="15" />{{ $t('account.myProfile') }}</router-link>
              <router-link v-if="!isWhiteLabel" to="/rewards" class="cust-menu-item" @click="menuOpen=false"><Trophy :size="15" />{{ $t('account.myRewards') }}</router-link>
              <router-link to="/my-bookings" class="cust-menu-item" @click="menuOpen=false"><CalendarCheck :size="15" />{{ $t('account.myBookings') }}</router-link>
              <router-link to="/my-memberships" class="cust-menu-item" @click="menuOpen=false"><Dumbbell :size="15" />My Memberships</router-link>
              <router-link v-if="!isWhiteLabel" to="/my-games" class="cust-menu-item" @click="menuOpen=false"><Ticket :size="15" />{{ $t('account.myEvents') }}</router-link>
              <router-link v-if="!isWhiteLabel" to="/my-communities" class="cust-menu-item" @click="menuOpen=false"><UsersRound :size="15" />My Communities</router-link>
              <router-link to="/account" class="cust-menu-item" @click="menuOpen=false"><Settings :size="15" />{{ $t('account.settings') }}</router-link>
              <button class="cust-menu-item" @click="signOut"><LogOut :size="15" />{{ $t('account.signOut') }}</button>
            </div>
          </template>
          <button v-else type="button" class="cust-signin" @click="ensureSignedIn()"><User :size="16" />{{ $t('account.signIn') }}</button>
        </div>
      </div>


      <!-- White-label: traditional business top navigation (marketplace keeps its side nav) -->
      <nav v-if="isWhiteLabel && !scopedEmbed" class="cust-topnav" aria-label="Site">
        <router-link to="/" class="cust-topnav-link" exact-active-class="on">{{ $t('nav.home') }}</router-link>
        <router-link v-if="brandShows('venues')" to="/facilities" class="cust-topnav-link" active-class="on">{{ $t('nav.venues') }}</router-link>
        <router-link v-if="brandShows('programs')" to="/programs" class="cust-topnav-link" active-class="on">{{ $t('nav.memberships') }}</router-link>
        <router-link v-if="brandShows('blog')" to="/blog" class="cust-topnav-link" active-class="on">Blog</router-link>
        <router-link v-if="brandPageEnabled('about')" to="/about" class="cust-topnav-link" active-class="on">About</router-link>
        <router-link v-if="brandPageEnabled('contact')" to="/contact" class="cust-topnav-link" active-class="on">Contact</router-link>
      </nav>
    </header>

    <!-- Floating side navigation (hidden in flow-scoped embeds so the widget can't wander) -->
    <nav v-if="!scopedEmbed && !isWhiteLabel" class="cust-sidenav">
      <router-link to="/" class="cust-sidenav-link" active-class="active" exact><Home :size="23" /><span>{{ $t('nav.home') }}</span></router-link>
      <router-link v-if="brandShows('venues')" to="/facilities" class="cust-sidenav-link" active-class="active"><Building2 :size="23" /><span>{{ $t('nav.venues') }}</span></router-link>
      <router-link v-if="isWhiteLabel && brandShows('venues')" to="/locations" class="cust-sidenav-link" active-class="active"><MapPin :size="23" /><span>Locations</span></router-link>
      <router-link v-if="brandShows('events')" to="/games" class="cust-sidenav-link" active-class="active"><Ticket :size="23" /><span>{{ $t('nav.events') }}</span></router-link>
      <router-link v-if="!isWhiteLabel" to="/communities" class="cust-sidenav-link" active-class="active"><UsersRound :size="23" /><span>Community</span></router-link>
      <router-link v-if="brandShows('programs')" to="/programs" class="cust-sidenav-link" active-class="active"><Dumbbell :size="23" /><span>{{ $t('nav.memberships') }}</span></router-link>
      <router-link v-if="brandShows('blog')" to="/blog" class="cust-sidenav-link cust-nav-blog" active-class="active"><Newspaper :size="23" /><span>Blog</span></router-link>
      <router-link v-if="isWhiteLabel && brandPageEnabled('about')" to="/about" class="cust-sidenav-link" active-class="active"><Info :size="23" /><span>About</span></router-link>
      <router-link v-if="isWhiteLabel && brandPageEnabled('contact')" to="/contact" class="cust-sidenav-link" active-class="active"><Phone :size="23" /><span>Contact</span></router-link>
    </nav>

    <main class="cust-main">
      <!-- White-label: this provider doesn't serve the visitor's city → point to Bookboll. -->
      <div v-if="showLocPrompt" class="cns">
        <div class="cns-ico"><MapPinOff :size="22" /></div>
        <div class="cns-body">
          <div class="cns-title">
            <template v-if="servesNowhere">{{ brandLabel }} has no services listed yet</template>
            <template v-else>{{ brandLabel }} doesn’t have any services in {{ selectedCity?.name }}<template v-if="selectedCountry?.name">, {{ selectedCountry.name }}</template></template>
          </div>
          <div class="cns-sub">
            <template v-if="servesNowhere">Browse thousands of venues and classes on Bookboll instead.</template>
            <template v-else-if="servedCount">Pick a city {{ brandLabel }} operates in, or find services in {{ selectedCity?.name }} on Bookboll’s main site.</template>
            <template v-else>Find services in {{ selectedCity?.name }} on Bookboll’s main site.</template>
          </div>
        </div>
        <div class="cns-actions">
          <button v-if="!servesNowhere && servedCount" type="button" class="cns-btn-ghost" @click="showGate = true"><MapPin :size="15" /> Choose a city</button>
          <a class="cns-btn" :href="bookbollCityUrl"><Globe2 :size="15" /> {{ servesNowhere ? 'Browse Bookboll' : `Find ${selectedCity?.name || 'services'} on Bookboll` }}</a>
        </div>
      </div>

      <router-view />
    </main>

    <ExploreHub v-if="!embedded" />

    <footer class="cust-footer">
      <div class="cust-footer-inner">
        <span v-if="isWhiteLabel" class="cust-foot-brand">© {{ activeBrand.name }}
          <a class="cust-pow" :href="marketplaceUrl()" target="_blank" rel="noopener" data-no-translate>
            <span v-if="platformBrand.logo" class="cust-pow-logo" :style="{ backgroundImage: `url(${platformBrand.logo})` }"></span>
            <span v-else class="cust-pow-mark"><BrandMark /></span>
            Powered by <b>{{ platformBrand.name || 'Bookboll' }}</b>
          </a>
        </span>
        <span v-else>© {{ platformBrand.name || 'Bookboll' }}</span>
        <a v-if="!embedded" :href="adminLoginUrl()" class="cust-admin-link"><Shield :size="13" /> {{ $t('footer.adminLogin') }}</a>
      </div>
    </footer>

    <!-- Free provider sites carry a visible, clickable Bookboll badge; premium
         white-label sites keep only the small footer credit above. -->
    <a v-if="isWhiteLabel && !activeBrand.whitelabelBranding && !embedded" class="cust-powbadge"
       :href="marketplaceUrl()" target="_blank" rel="noopener" data-no-translate>
      <span v-if="platformBrand.logo" class="cust-pow-logo" :style="{ backgroundImage: `url(${platformBrand.logo})` }"></span>
      <span v-else class="cust-pow-mark">BB</span>
      <span>Powered by <b>{{ platformBrand.name || 'Bookboll' }}</b></span>
    </a>

    <LocationGate :open="showGate" @done="onGateDone" @dismiss="onGateDismiss" />
    <AuthGate />
    <CheckoutResumeCard />
    <!-- Tournament hosts: auto-popup when a finished game still needs its result -->
    <BracketUpdatePrompt />
  </div>
</template>

<style scoped>
.cust { min-height: 100vh; display: flex; flex-direction: column; background: #f8fafc; }

.cust-header { position: sticky; top: 0; z-index: 100; background: var(--surface, #ffffff); border-bottom: 1px solid var(--border, #e2e8f0); box-shadow: 0 1px 3px rgba(0,0,0,.04); }
.cust-header-inner { max-width: 1100px; margin: 0 auto; padding: 12px 18px; display: flex; align-items: center; gap: 14px; }
.cust-logohead { display: flex; flex-direction: column; min-width: 0; }
.cust-logo { display: flex; align-items: center; gap: 9px; text-decoration: none; }
/* Header attribution — sits under the business name, aligned past the logo mark */
.cust-pow-head { font-size: 10.5px; font-weight: 600; color: var(--text-light, #94a3b8); text-decoration: none; padding-inline-start: 45px; margin-top: -3px; white-space: nowrap; }
.cust-pow-head b { color: var(--text-muted, #64748b); font-weight: 800; }
.cust-pow-head:hover, .cust-pow-head:hover b { color: var(--brand); }
.cust-logo-mark { width: 36px; height: 36px; border-radius: 10px; background: linear-gradient(135deg, var(--brand-accent), var(--brand)); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 14px; }
.cust-logo-img { width: 36px; height: 36px; border-radius: 10px; background-size: cover; background-position: center; background-color: var(--surface, #ffffff); box-shadow: 0 0 0 1px var(--border, #e2e8f0); }
.cust-logo-name { font-size: 19px; font-weight: 800; color: #0f172a; }

.cust-city { display: flex; align-items: center; gap: 6px; padding: 8px 12px; border: 1.5px solid var(--border, #e2e8f0); border-radius: 999px; background: #f8fafc; cursor: pointer; font-size: 13.5px; font-weight: 600; color: #334155; }
.cust-city:hover { border-color: var(--brand-accent); color: var(--brand); }
.cust-city-name { max-width: 120px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

.cust-header-right { margin-inline-start: auto; position: relative; display: flex; align-items: center; gap: 10px; }
.cust-theme { display: flex; align-items: center; justify-content: center; width: 40px; height: 40px; border-radius: 50%; border: 1.5px solid var(--border, #e2e8f0); background: var(--surface, #ffffff); color: #334155; cursor: pointer; }
.cust-theme:hover { border-color: var(--brand-accent); color: var(--brand); }
.cust-msgs { position: relative; display: flex; align-items: center; justify-content: center; width: 40px; height: 40px; border-radius: 50%; border: 1.5px solid var(--border, #e2e8f0); background: var(--surface, #ffffff); color: #334155; cursor: pointer; text-decoration: none; }
.cust-msgs:hover { border-color: var(--brand-accent); color: var(--brand); }
.cust-msgs-badge { position: absolute; top: -3px; right: -3px; min-width: 17px; height: 17px; padding: 0 4px; border-radius: 999px; background: #ef4444; color: #fff; font-size: 10px; font-weight: 800; display: flex; align-items: center; justify-content: center; }
.cust-menu-badge { margin-left: auto; min-width: 18px; height: 18px; padding: 0 5px; border-radius: 999px; background: #ef4444; color: #fff; font-size: 10px; font-weight: 800; display: inline-flex; align-items: center; justify-content: center; }
.cust-signin { display: flex; align-items: center; gap: 6px; padding: 9px 16px; border-radius: 999px; background: #0f172a; color: #fff; text-decoration: none; font-size: 14px; font-weight: 700; border: none; cursor: pointer; font-family: inherit; }
.cust-signin:hover { background: #1e293b; }
.cust-acct { display: flex; align-items: center; gap: 8px; cursor: pointer; padding: 4px; }
.cust-avatar { width: 34px; height: 34px; border-radius: 50%; background: linear-gradient(135deg,#f59e0b,#d97706); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 13px; }
.cust-avatar.img { background-size: cover; background-position: center; }
.cust-acct-name { font-size: 14px; font-weight: 700; color: #0f172a; }
.cust-menu { position: absolute; top: 48px; inset-inline-end: 0; background: var(--surface, #ffffff); border: 1px solid var(--border, #e2e8f0); border-radius: 12px; box-shadow: 0 12px 32px rgba(0,0,0,.14); overflow: hidden; min-width: 180px; }
.cust-menu-item { display: flex; align-items: center; gap: 10px; width: 100%; padding: 12px 16px; font-size: 14px; color: #334155; text-decoration: none; background: none; border: none; cursor: pointer; text-align: left; }
.cust-menu-item:hover { background: #f1f5f9; }

/* White-label top navigation — traditional business navbar under the header row */
.cust-topnav { display: flex; justify-content: center; gap: 2px; padding: 0 16px; border-top: 1px solid #f1f5f9; overflow-x: auto; scrollbar-width: none; }
.cust-topnav::-webkit-scrollbar { display: none; }
.cust-topnav-link { padding: 12px 16px; font-size: 14px; font-weight: 700; color: #475569; text-decoration: none; white-space: nowrap; border-bottom: 2.5px solid transparent; transition: color .12s, border-color .12s; }
.cust-topnav-link:hover { color: var(--brand); }
.cust-topnav-link.on { color: var(--brand); border-bottom-color: var(--brand); }
@media (max-width: 767px) {
  .cust-topnav { justify-content: flex-start; padding: 0 6px; }
  .cust-topnav-link { padding: 11px 13px; font-size: 13.5px; }
}
/* The white-label hero bleeds to the viewport edges — clip any sub-pixel excess. */
.cust { overflow-x: clip; }

/* Floating side navigation card (anchored near the top-left). */
.cust-sidenav {
  position: fixed; inset-inline-start: 22px; top: 96px;
  z-index: 90; display: flex; flex-direction: column; gap: 6px; width: 120px;
  background: var(--surface, #ffffff); border: 1px solid #eef2f6; border-radius: 22px; padding: 13px;
  box-shadow: 0 20px 48px rgba(15,23,42,.13), 0 2px 6px rgba(15,23,42,.05);
}
.cust-sidenav-link {
  display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 9px;
  width: 100%; box-sizing: border-box; padding: 17px 8px; border-radius: 16px;
  font-size: 12.5px; font-weight: 700; color: var(--text-muted, #64748b); text-decoration: none;
  text-align: center; line-height: 1.15; transition: background .14s, color .14s, transform .14s;
}
.cust-sidenav-link:hover { background: #f1f5f9; color: #0f172a; transform: translateY(-1px); }
.cust-sidenav-link.active { background: color-mix(in srgb, var(--brand) 12%, white); color: var(--brand); }
.cust-sidenav-link svg { stroke-width: 2; }

.cust-main { flex: 1; max-width: 1100px; width: 100%; margin: 0 auto; padding: 18px; box-sizing: border-box; }

/* "We don't serve here" prompt */
.cns { display: flex; align-items: center; gap: 14px; background: #fff7ed; border: 1px solid #fed7aa; border-radius: 16px; padding: 16px 18px; margin-bottom: 18px; flex-wrap: wrap; }
.cns-ico { width: 44px; height: 44px; border-radius: 12px; background: #ffedd5; color: #c2410c; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.cns-body { flex: 1; min-width: 200px; }
.cns-title { font-size: 15.5px; font-weight: 800; color: #7c2d12; }
.cns-sub { font-size: 13px; color: #9a3412; margin-top: 3px; line-height: 1.5; }
.cns-actions { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.cns-btn { display: inline-flex; align-items: center; gap: 6px; padding: 10px 16px; border-radius: 10px; background: #0f172a; color: #fff; font-size: 13.5px; font-weight: 800; text-decoration: none; white-space: nowrap; }
.cns-btn:hover { background: #1e293b; }
.cns-btn-ghost { display: inline-flex; align-items: center; gap: 6px; padding: 10px 14px; border-radius: 10px; background: var(--surface, #ffffff); border: 1.5px solid #fed7aa; color: #9a3412; font-size: 13.5px; font-weight: 700; cursor: pointer; white-space: nowrap; }
.cns-btn-ghost:hover { border-color: #fdba74; }

/* Wide enough: reserve a left gutter so centered content clears the floating card. */
@media (min-width: 768px) {
  /* Symmetric gutters reserve room for the floating nav on the left AND keep the body
     content centred in the middle of the viewport (equal space both sides). */
  .cust { padding-inline: 164px; }
  /* Full-bleed the chrome bands across the whole viewport (escape the gutters). */
  .cust-header, .cust-footer { margin-inline: -164px; width: calc(100% + 328px); }
  /* Header content pinned to the corners; footer content stays centred with the body. */
  .cust-header-inner { max-width: none; padding-inline: 28px; }
}
/* Narrow screens: dock it to the bottom as a horizontal floating bar. */
@media (max-width: 767px) {
  .cust-sidenav {
    inset-inline-start: 50%; top: auto; bottom: 12px; transform: translateX(-50%);
    width: auto; flex-direction: row; gap: 4px; border-radius: 16px; padding: 8px;
    max-width: calc(100vw - 24px); overflow-x: auto;
  }
  .cust-sidenav-link { width: auto; min-width: 58px; padding: 9px 10px; gap: 5px; font-size: 11px; }
  .cust-nav-blog { display: none; }   /* keep the floating bottom bar to the core tabs on mobile */

  /* Header: keep it to ONE tidy row that always fits. The logo flexes/truncates, the
     action cluster shrinks, and lower-priority text/controls collapse to icons. */
  .cust-header-inner { padding: 10px 12px; gap: 8px; }
  .cust-logohead { min-width: 0; flex-shrink: 1; }
  .cust-logo { min-width: 0; flex-shrink: 1; }
  .cust-pow-head { font-size: 9.5px; padding-inline-start: 0; margin-top: 0; }
  .cust-logo-name { font-size: 16px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .cust-city { flex-shrink: 0; padding: 7px 9px; gap: 4px; }
  .cust-city-name { max-width: 64px; font-size: 12.5px; }
  .cust-header-right { gap: 4px; flex-shrink: 0; }
  .cust-theme, .cust-msgs { width: 36px; height: 36px; }
  .cust-acct-name { display: none; }            /* avatar is enough on mobile */
  .cust-signin { padding: 8px 12px; font-size: 13px; }

  /* Calm chrome: mute the two bordered icon rings this file owns so the city chip
     and avatar lead; icons stay tappable at 36px. */
  .cust-theme, .cust-msgs { border-color: transparent; background: transparent; }

  /* Tap targets: keep the visual circles small but extend the invisible hit area
     toward the 44px guideline (pure CSS, no markup change). */
  .cust-theme, .cust-msgs { position: relative; }
  .cust-theme::after, .cust-msgs::after { content: ''; position: absolute; inset: -6px; }

  /* Type-scale floor: unread badges up from 10px to the 11px minimum. */
  .cust-msgs-badge, .cust-menu-badge { font-size: 11px; min-width: 18px; height: 18px; }

  /* "We don't serve here" banner: slimmer card, helper copy clamped to 2 lines
     (title + both CTAs stay fully intact), CTAs stacked full-width so a long
     city name can never overflow the amber card. */
  .cns { padding: 14px; gap: 10px; }
  .cns-ico { width: 36px; height: 36px; }
  .cns-sub { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
  .cns-actions { width: 100%; flex-direction: column; align-items: stretch; gap: 8px; }
  .cns-btn, .cns-btn-ghost { white-space: normal; justify-content: center; text-align: center; }

  /* Clear the floating bottom nav bar so nothing hides behind it. */
  .cust-main { padding-bottom: 88px; }
  /* Flow pages (booking / membership signup): drop the bottom nav — the visitor
     is mid-process and the page's own action bar owns the bottom edge. */
  .cust-inflow .cust-sidenav { display: none; }
  /* Flow pages dock their action bar flush to the viewport bottom — reserve room so
     the last content (and any error text) never hides behind it. */
  .cust-inflow .cust-main { padding-bottom: calc(92px + env(safe-area-inset-bottom, 0px)); }
  /* The footer's last line must clear BOTH kinds of floating bottom chrome: the
     nav pill (browse pages, up to ~76px) and the docked action bars (flow pages,
     up to ~90px incl. safe area) — 116px covers the tallest with breathing room. */
  /* Extra specificity: the BASE .cust-footer-inner rule sits later in this file,
     so a same-specificity media rule loses on source order (the original 84px
     clearance silently never applied — the "footer hides behind the bars" bug). */
  .cust-footer .cust-footer-inner { padding-bottom: calc(116px + env(safe-area-inset-bottom, 0px)); }
}
/* Very small phones (360/344px): drop the brand wordmark, keep the logo mark, and
   tighten the header row so up to 5 right-cluster controls + the city chip always
   fit without horizontal overflow. The city label collapses to its pin+chevron chip
   (the chip's title attribute keeps it accessible and it still opens the gate). */
@media (max-width: 380px) {
  .cust-logo-name { display: none; }
  .cust-header-inner { padding: 10px 8px; gap: 6px; }
  .cust-header-right { gap: 2px; }
  .cust-theme, .cust-msgs { width: 32px; height: 32px; }
  .cust-avatar { width: 30px; height: 30px; }
  .cust-acct { padding: 2px; }
  .cust-city { flex-shrink: 1; min-width: 0; padding: 6px 8px; }
  .cust-city-name { display: none; }
}

.cust-footer { border-top: 1px solid var(--border, #e2e8f0); background: var(--surface, #ffffff); }
.cust-footer-inner { max-width: 1100px; margin: 0 auto; padding: 18px; display: flex; align-items: center; justify-content: space-between; font-size: 13px; color: var(--text-light, #94a3b8); }
.cust-admin-link { display: flex; align-items: center; gap: 5px; color: var(--text-muted, #64748b); text-decoration: none; font-weight: 600; }
.cust-admin-link:hover { color: var(--brand); }
.cust-foot-brand { display: inline-flex; gap: 10px; align-items: center; flex-wrap: wrap; }
/* Footer credit — logo + clickable, present on every white-label site */
.cust-pow { display: inline-flex; align-items: center; gap: 6px; color: var(--text-muted, #64748b); text-decoration: none; font-size: 12.5px; }
.cust-pow:hover { color: var(--brand); }
.cust-pow b { color: #334155; }
.cust-pow:hover b { color: var(--brand); }
.cust-pow-logo { width: 18px; height: 18px; border-radius: 5px; background-size: cover; background-position: center; flex-shrink: 0; }
.cust-pow-mark { width: 18px; height: 18px; border-radius: 5px; background: linear-gradient(135deg, #14b8a6, #0f766e); color: #fff; font-size: 9px; font-weight: 900; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }
/* Floating badge — free provider sites only */
.cust-powbadge { position: fixed; bottom: 14px; inset-inline-end: 14px; z-index: 60;
  display: inline-flex; align-items: center; gap: 7px; padding: 8px 13px; border-radius: 999px;
  background: #0f172a; color: #cbd5e1; font-size: 12.5px; font-weight: 600; text-decoration: none;
  box-shadow: 0 6px 22px rgba(15,23,42,.28); }
.cust-powbadge b { color: #fff; }
.cust-powbadge:hover { background: #1e293b; }
@media (max-width: 767px) { .cust-powbadge { bottom: 10px; inset-inline-end: 10px; padding: 7px 11px; font-size: 12px; } }
.cust-powered { color: #cbd5e1; font-size: 12px; }

/* Embedded widget tweaks. Scoped embeds drop the side-nav, so reclaim its gutter and let the
   content use the full iframe width. Both embed modes slim the chrome. */
.cust-embed .cust-main { padding-top: 14px; }
@media (min-width: 768px) {
  .cust.cust-embed-scoped { padding-inline: 0; }
  .cust.cust-embed-scoped .cust-header,
  .cust.cust-embed-scoped .cust-footer { margin-inline: 0; width: 100%; }
}
</style>

<!-- Global (non-scoped) dark theme for the customer storefront.
     Smart-invert: invert + hue-rotate(180) flips light↔dark while preserving the
     brand hues (teal/amber). Photos & hero images are re-inverted back to normal.
     Only the customer `.cust` shell is themed, so admin/auth are untouched. -->
<style>
/* Full-bleed the Explore hub band across the viewport (its inner stays centred with the
   body) so its top border reaches the edges despite the symmetric nav gutter on .cust. */
@media (min-width: 768px) {
  .cust .xh { margin-inline: -164px; width: calc(100% + 328px); }
}
/* CRITICAL: the invert filter must sit on the ROOT element. A CSS filter on any
   lower element (like .cust) makes that element the containing block for every
   position:fixed descendant — the bottom nav, docked action bars and modal overlays
   all stop pinning to the viewport and fall to the bottom of the DOCUMENT (the
   "disappearing bottom navbar in dark mode" bug). The root element is exempt.
   .cust-shell is added by CustomerLayout on mount so the admin area never inverts.
   Backgrounds are set LIGHT here because the root filter inverts them to dark. */
/* Dark palette works through the invert: the PAGE is pure white pre-invert →
   FULL BLACK in dark mode; cards (--surface/--card, off-white pre-invert) invert
   to a lighter charcoal that floats on the black page; muted text/icons and
   borders use darker pre-invert grays so they invert BRIGHTER and stay readable.
   Set on the ROOT so body-teleported modals inherit the same palette. */
html.cust-dark.cust-shell {
  filter: invert(1) hue-rotate(180deg);
  background: #ffffff;               /* page → pure black */
  --surface: #e4e7eb;                /* cards → lighter charcoal (~#191c1e) */
  --card: #e4e7eb;
  --bg: #f2f3f5;                     /* secondary surfaces → near-black inset */
  --border: #c4cbd3;                 /* borders → visible slate (~#2c343b) */
  --text-muted: #33404d;             /* muted text → light gray (~#b2bfcc) */
  --text-light: #45525f;             /* light text → readable gray (~#a0adba) */
}
html.cust-dark.cust-shell body { background: #ffffff; }
html.cust-dark .cust { background: #ffffff; }
html.cust-dark .cust img,
html.cust-dark .cust video,
html.cust-dark .cust .fc-img,
html.cust-dark .cust .pc-img,
html.cust-dark .cust .ccard-img,
html.cust-dark .cust .gc-cover,
html.cust-dark .cust .gd-gallery-img,
html.cust-dark .cust .ld-hero,
html.cust-dark .cust .fd-hero,
html.cust-dark .cust .pd-hero { filter: invert(1) hue-rotate(180deg); }

/* Event poster (GameDetail gd-top): the <img> is counter-inverted by the global img
   rule above, so it already shows natural colors. The zoom pill overlays that natural
   photo, so counter-invert it too — it must stay a dark pill, not flip to light. */
html.cust-dark .cust .gd-poster-zoom { filter: invert(1) hue-rotate(180deg); }

/* Community covers are CSS background-images (not <img>), so the global img rule can't
   reach them — they'd show up smart-inverted (wrong colours). Counter-invert the whole
   cover ONLY when it actually carries a photo: net-zero inversion renders the card/hero
   exactly as designed (natural photo, dark legibility overlay, white text/badges). The
   placeholder-gradient covers (no photo) are left to invert to a fitting dark tint. */
html.cust-dark .cust .cm-card-cover.cm-has-cover,
html.cust-dark .cust .cm-mine-av.cm-has-cover,
html.cust-dark .cust .pp-comm-cover.pp-has-cover,
html.cust-dark .cust .cd-hero.cd-has-cover { filter: invert(1) hue-rotate(180deg); }

/* User photos rendered as CSS background-images (avatars, galleries, post images,
   composer previews) — same story: counter-invert so uploads show true colours in
   dark mode. Avatars only when they carry a photo (.img) so letter-fallbacks still
   invert with their gradient. */
html.cust-dark .cust .pp-avatar.img,
html.cust-dark .cust .pp-req-av.img,
html.cust-dark .cust .pp-photo,
html.cust-dark .cust .pp-post-img,
html.cust-dark .cust .pp-compose-img,
html.cust-dark .cust .acc-avatar.img,
html.cust-dark .cust .acc-gimg,
html.cust-dark .cust .cd-post-av.img,
html.cust-dark .cust .cd-member-av.img,
html.cust-dark .cust .cd-composer-pic { filter: invert(1) hue-rotate(180deg); }

/* Saturated / dark brand banners & cards: keep their real colors in dark mode
   (counter-invert so they show true brand colors, not the muddy inverted ones).
   Light-tinted info banners are intentionally left to invert — they become a nice
   dark tint that suits dark mode. */
html.cust-dark .cust .gc-blank,
html.cust-dark .cust .gd-cover-fb,
html.cust-dark .cust .td-head,
html.cust-dark .cust .welcome,
html.cust-dark .cust .host-card,
html.cust-dark .cust .loy,
html.cust-dark .cust .recruit,
html.cust-dark .cust .hs,
html.cust-dark .cust .rw-hero { filter: invert(1) hue-rotate(180deg); }

/* The hero banner shows EXACTLY the colours the provider configured (so it matches the
   dashboard preview). It's counter-inverted above; its inner icon <img> must then drop
   the global img re-invert, otherwise it'd be inverted a third time. */
html.cust-dark .cust .hs img { filter: none; }
</style>
