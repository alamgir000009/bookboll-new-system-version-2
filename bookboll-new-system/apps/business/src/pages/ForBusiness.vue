<script setup>
/**
 * ForBusiness.vue — the "Bookboll for Business" recruitment landing page.
 *
 * Entry point of the provider funnel: recruitment banner → THIS PAGE → /signup (account
 * creation) → website setup → first location + listing/program → provider dashboard.
 *
 * Rendered bare (meta.area = 'auth'), indexable, own useSeo (keyword-targeted at sports
 * facility owners: "booking software", "free booking website", court/padel/gym/academy terms).
 * Structured data: SoftwareApplication + FAQPage JSON-LD (passed to useSeo as an array —
 * stringified into one script tag). One keyword-rich h1; h2 per section; 12-question FAQ
 * accordion mirrored 1:1 in the FAQPage LD; crawl-links row (/venues /memberships /terms
 * /signup) above the footer. Colours are FIXED to the recruit-banner teal theme — do NOT
 * swap for --brand vars.
 *
 * Copy stays audited-accurate: loyalty is marketplace-only, deposits/wallet mechanics and the
 * memberships' Subscription-wallet requirement are disclosed, steps mirror the real funnel,
 * and CTAs are auth-aware (signed-in providers see "Open my dashboard").
 */
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { useSeo, faqLd } from '../lib/seo.js'
import { BRAND_APEX } from '../lib/brand.js'
import { authUser } from '../lib/auth.js'
import { adminLoginUrl, adminDashboardUrl } from '../lib/crossApp.js'
import { webBaseUrl } from '../lib/appBase.js'
import LanguageSwitcher from '../components/LanguageSwitcher.vue'
import BrandMark from '../components/BrandMark.vue'
import {
  Store, Globe, Check, ArrowRight, Zap, X as XIcon, Dumbbell, Goal, Dices, CalendarCheck,
  CreditCard, Tag, Users, UsersRound, BarChart3, Repeat, UserPlus, MapPin, Info,
  Code2, LineChart, Wallet, LayoutDashboard, Search, ChevronDown,
} from 'lucide-vue-next'

// Money-accuracy: every number below is verified in code — 15% default marketplace
// commission (lib/approvalState.js PLATFORM_COMMISSION), Premium $59/mo (lib/premium.js
// PREMIUM_PRICE), custom domain = one-time fee (Brand.vue DOMAIN_FEE; price not quoted here).
// Never promise refunds; commission applies to MARKETPLACE bookings only.
const FAQS = [
  { q: 'How much does Bookboll cost for a sports facility?',
    a: 'Getting started is free — no setup fees and no monthly fee for the core platform. Bookings from your own Bookboll website carry a 0% platform fee, and Bookboll only earns a commission on bookings that come through the Bookboll marketplace (15% by default worldwide, collected through the online payment). Optional extras: a custom domain (one-time fee) and a Premium plan ($59/month, or $49/month billed yearly — with a free trial to start) for advanced marketing and export features.' },
  { q: 'What is the Bookboll 2-in-1?',
    a: 'One free account gives you two sales channels: your own branded booking website (yourclub.bookboll.com) where bookings are commission-free, plus a listing on the Bookboll marketplace where players searching near you can discover and book your facility. Both channels share one dashboard, one calendar and one wallet.' },
  { q: 'Do I need my own website to use Bookboll?',
    a: 'No. Bookboll gives every business a free branded booking website on its own subdomain — with hourly court booking, live availability, memberships and online payments built in. Already have a website? Embed the Bookboll booking widget or add a booking button to it instead.' },
  { q: 'Can I use my own custom domain?',
    a: 'Yes. Your free site lives at yourbrand.bookboll.com, and you can connect a custom domain like yourclub.com at any time for a one-time fee — your logo, colours and languages stay yours either way.' },
  { q: 'How do payouts work?',
    a: 'You add a payout method — such as Stripe or the Bookboll wallet, depending on wallet type and country — to your Bookboll payout wallet, set up per country so each country pays out in its own currency. Venue bookings and membership income use separate wallets (Bookings wallet and Membership wallet). Your share of online payments is credited to your wallet; anything paid on arrival you collect directly at the venue.' },
  { q: 'How do online deposits work?',
    a: 'A deposit is part of the price, not an extra charge: the customer pays the deposit online to confirm the booking, it counts toward their total, and they pay only the remaining balance when they arrive. On the marketplace, the online payment (the deposit, or the commission on a full online payment) is also how Bookboll collects its fee — the balance on arrival is yours. On your own website, connect a payout method to your Bookings wallet so online deposits are paid to you.' },
  { q: 'What can I list on Bookboll?',
    a: 'Courts and pitches for hourly rental (padel, football, tennis, basketball, badminton, volleyball and more), full venues, gyms, classes and academy programs with recurring memberships, activity venues like escape rooms, billiards and bowling — plus games, hangouts and tournaments hosted at your venue.' },
  { q: 'Does Bookboll support gym memberships and subscriptions?',
    a: 'Yes. You can sell recurring membership plans for gyms, classes and academies — monthly or longer terms, with free trials or multi-month offers and automatic recurring billing. Programs need a payout method on your Membership wallet before they can go live.' },
  { q: 'Does Bookboll include marketing tools?',
    a: 'Yes. You can run WhatsApp and email campaigns to customers who opted in to marketing, customise notification templates, and add your own tracking (Meta Pixel, GA4, Google Tag Manager) to your branded website. Some advanced tools — like email campaigns, promo codes and homepage banners — are part of the Premium plan ($59/month, or $49/month billed yearly, with a free trial).' },
  { q: 'Can my team have their own accounts?',
    a: 'Yes. Invite staff with Owner, Manager or Staff roles, grant or revoke individual permissions, and scope each person to specific locations, listings or programs — so the front desk can run the calendar without touching payouts.' },
  { q: 'How long does it take to set up?',
    a: 'Most facilities go live the same day: create an account in about 2 minutes, add your venue and courts or programs in the dashboard, connect a payout method and share your link.' },
  { q: 'Do my customers need to download an app?',
    a: 'No. Your booking site and the Bookboll marketplace both run in the browser on any phone or computer — customers book in a few taps.' },
]

// SoftwareApplication JSON-LD — describes the product itself; served alongside the FAQPage
// LD (useSeo stringifies the array into one valid JSON-LD script tag).
const APP_LD = {
  '@context': 'https://schema.org',
  '@type': 'SoftwareApplication',
  name: 'Bookboll for Business',
  applicationCategory: 'BusinessApplication',
  operatingSystem: 'Web',
  url: `https://business.${BRAND_APEX}/`,
  description: 'Booking software and a free branded website for sports venues, gyms and academies: hourly court rental, recurring memberships, online payments, marketing tools and marketplace exposure.',
  offers: {
    '@type': 'Offer',
    price: '0',
    priceCurrency: 'USD',
    description: 'Free to get started. 0% commission on bookings from your own website; commission applies to Bookboll marketplace bookings only (15% default).',
  },
  publisher: { '@type': 'Organization', name: 'Bookboll', url: `https://${BRAND_APEX}/` },
}

useSeo({
  exactTitle: 'Booking Software & Free Website for Sports Venues, Gyms & Academies | Bookboll for Business',
  description: 'Free booking software for sports venues, gyms and academies: your own branded booking website with 0% commission, memberships, online payments and marketing tools — plus new customers from the Bookboll marketplace (15% commission on marketplace bookings only).',
  path: '/',
  jsonLd: [APP_LD, faqLd(FAQS)],
})

const YEAR = new Date().getFullYear()
const SPORTS = ['Padel', 'Football', 'Basketball', 'Tennis', 'Badminton', 'Volleyball', 'Gyms', 'Academies', 'Classes']

const listTypes = [
  { icon: Goal, title: 'Court & pitch rental', sub: 'Padel, football, tennis, basketball & more', tags: ['Book by the hour', 'Live availability', 'Online payments'] },
  { icon: Dumbbell, title: 'Memberships', sub: 'Gyms, academies & classes', tags: ['Recurring plans', 'Free trials', 'Auto-billing'] },
  { icon: Dices, title: 'Activity venues', sub: 'Escape rooms, billiards, bowling & more', tags: ['Timed sessions', 'Group bookings', 'Add-ons'] },
]

const capabilities = [
  { icon: CalendarCheck, title: 'Court booking system', sub: 'Slots, availability & holds' },
  { icon: Repeat, title: 'Membership software', sub: 'Recurring plans & trials' },
  { icon: CreditCard, title: 'Online payments', sub: 'Cards, deposits & payouts' },
  { icon: Tag, title: 'Promo codes', sub: 'Targeted discounts & offers' },
  { icon: Code2, title: 'Embed & widgets', sub: 'Add booking to your existing site' },
  { icon: LineChart, title: 'Marketing pixels', sub: 'Meta Pixel, GA4 & Tag Manager' },
  { icon: Users, title: 'Customer CRM', sub: 'Know who books with you' },
  { icon: UsersRound, title: 'Team & permissions', sub: 'Staff roles & access' },
  { icon: BarChart3, title: 'Analytics dashboard', sub: 'Track your growth' },
]

// Mirrors the REAL funnel: signup wizard (account → optional website) → dashboard
// (location + listing/program) → payout wallet → live.
const steps = [
  { icon: UserPlus, title: 'Create your account', sub: 'Business name, email & your web address — 2 minutes.' },
  { icon: Globe, title: 'Set up your website', sub: 'Logo & colours — your address is already reserved. Skippable.', opt: true },
  { icon: MapPin, title: 'Add your first listing', sub: 'Venue, courts or programs — in your dashboard.' },
  { icon: Wallet, title: 'Get paid & go live', sub: 'Add a payout method, share your link.' },
]

// Scroll-reveal: gated behind `.fb-anim` (added only when JS runs AND motion is allowed),
// so no-JS / reduced-motion visitors always see the full page.
const root = ref(null)
let io = null
onMounted(() => {
  if (typeof window === 'undefined' || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return
  const el = root.value
  if (!el) return
  el.classList.add('fb-anim')
  io = new IntersectionObserver((entries) => {
    entries.forEach(e => { if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target) } })
  }, { threshold: 0.12 })
  el.querySelectorAll('.fb-reveal').forEach(n => io.observe(n))
})
onBeforeUnmount(() => { io?.disconnect() })
</script>

<template>
  <div ref="root" class="fb">
    <!-- ── Top bar ─────────────────────────────────────────────────────────── -->
    <header class="fb-nav">
      <div class="fb-nav-in">
        <router-link to="/" class="fb-brand">
          <span class="fb-mark" data-no-translate><BrandMark /></span>
          <span class="fb-brand-name" data-no-translate>Bookboll</span>
          <span class="fb-brand-pill">for Business</span>
        </router-link>
        <nav class="fb-nav-actions">
          <router-link to="/pricing" class="fb-nav-signin">Pricing</router-link>
          <router-link to="/blog" class="fb-nav-signin">Blog</router-link>
          <LanguageSwitcher compact class="fb-lang" />
          <a v-if="authUser" :href="adminDashboardUrl()" class="fb-nav-cta"><LayoutDashboard :size="15" /> Open dashboard</a>
          <template v-else>
            <a :href="adminLoginUrl()" class="fb-nav-signin">Sign in</a>
            <router-link to="/signup" class="fb-nav-cta">Get started free</router-link>
          </template>
        </nav>
      </div>
    </header>

    <!-- ── Hero ────────────────────────────────────────────────────────────── -->
    <section class="fb-hero">
      <div class="fb-hero-grid">
        <div class="fb-hero-text">
          <div class="fb-badge"><Zap :size="15" /> For sports facility owners &amp; operators</div>
          <h1>Booking software &amp; a <span class="fb-hl">free website</span> for sports venues, gyms &amp; academies</h1>
          <p class="fb-hero-sub">Fill your courts and grow your memberships: take commission-free bookings on your own branded website, plus win new customers from the Bookboll marketplace.</p>
          <div class="fb-hero-cta">
            <a v-if="authUser" :href="adminDashboardUrl()" class="fb-btn-primary">Open my dashboard <ArrowRight :size="18" /></a>
            <template v-else>
              <router-link to="/signup" class="fb-btn-primary">Get started free <ArrowRight :size="18" /></router-link>
              <a :href="adminLoginUrl()" class="fb-btn-ghost">Sign in</a>
            </template>
          </div>
          <ul class="fb-trust">
            <li><Check :size="15" /> No setup fees</li>
            <li><Check :size="15" /> No monthly fees</li>
            <li><Check :size="15" /> 0% on your own site</li>
          </ul>
        </div>

        <!-- Product mock: YOUR booking website + YOUR club showing up on the Bookboll marketplace.
             data-no-translate: decorative micro-UI — translating its tiny labels/fake URLs would
             mangle names and overflow the mock cards. -->
        <div class="fb-hero-visual" aria-hidden="true" data-no-translate>
          <div class="fb-visual-glow"></div>

          <!-- Your branded booking website -->
          <div class="fb-mock fb-mock-site">
            <div class="fb-mock-head"><span class="fb-dot r"></span><span class="fb-dot y"></span><span class="fb-dot g"></span><b>yourclub.bookboll.com</b></div>
            <div class="fb-site-nav">
              <span class="fb-site-logo"></span><b>Your Padel Club</b>
              <span class="fb-site-links"><i></i><i></i></span>
              <span class="fb-site-book">Book</span>
            </div>
            <div class="fb-site-hero">
              <b>Book your court</b>
              <small>Padel · Tennis · Football</small>
            </div>
            <div class="fb-site-cards">
              <div class="fb-site-card"><span class="fb-site-img c1"></span><b>Court 1</b><small>150 /hr</small></div>
              <div class="fb-site-card"><span class="fb-site-img c2"></span><b>Court 2</b><small>150 /hr</small></div>
            </div>
            <div class="fb-mock-tag fb-tag-free">Your website · 0%</div>
          </div>

          <!-- The Bookboll marketplace -->
          <div class="fb-mock fb-mock-market">
            <div class="fb-bb-head">
              <span class="fb-bb-logo">B</span><b>Bookboll</b>
              <span class="fb-bb-loc"><MapPin :size="9" /> Dubai</span>
            </div>
            <div class="fb-mock-search"><Search :size="12" /> Padel near me</div>
            <div class="fb-mock-item"><span class="fb-mock-thumb t1"></span><span class="fb-mock-item-t"><b>Elite Courts</b><small>1.2 km · ★ 4.9</small></span></div>
            <div class="fb-mock-item hl"><span class="fb-mock-thumb t2"></span><span class="fb-mock-item-t"><b>Your Padel Club</b><small>2.0 km · New</small></span></div>
            <div class="fb-mock-item"><span class="fb-mock-thumb t3"></span><span class="fb-mock-item-t"><b>Arena Club</b><small>3.4 km · ★ 4.8</small></span></div>
            <div class="fb-mock-tag fb-tag-market">Bookboll marketplace</div>
          </div>

          <div class="fb-plus"><span>+</span></div>
        </div>
      </div>
    </section>

    <!-- ── The 2-in-1 ──────────────────────────────────────────────────────── -->
    <section class="fb-sec fb-twin fb-reveal">
      <div class="fb-eyebrow">Two products, one account</div>
      <h2 class="fb-h2">One platform, two ways to get booked</h2>
      <div class="fb-twin-grid">
        <div class="fb-twin-card">
          <div class="fb-twin-ico"><Globe :size="24" /></div>
          <h3>Your booking website</h3>
          <ul class="fb-twin-list">
            <li><Check :size="15" /> Free branded site — <b>yourclub.bookboll.com</b></li>
            <li><Check :size="15" /> Court booking, memberships &amp; online payments</li>
            <li><Check :size="15" /> Embed booking into a website you already have</li>
          </ul>
          <div class="fb-twin-pill fb-pill-green"><Check :size="14" /> 0% commission — you keep 100%</div>
          <div class="fb-twin-note">Want your own domain? Connect one anytime (one-time fee).</div>
        </div>
        <div class="fb-twin-join"><span>+</span></div>
        <div class="fb-twin-card">
          <div class="fb-twin-ico"><Store :size="24" /></div>
          <h3>The Bookboll marketplace</h3>
          <ul class="fb-twin-list">
            <li><Check :size="15" /> Get found by players searching near you</li>
            <li><Check :size="15" /> We do the marketing — you take the bookings</li>
            <li><Check :size="15" /> Marketplace loyalty rewards bring players back</li>
          </ul>
          <div class="fb-twin-pill fb-pill-teal"><Store :size="14" /> Commission only on sales we bring</div>
          <div class="fb-twin-note">Marketplace listings are quality-reviewed before going live.</div>
        </div>
      </div>
    </section>

    <!-- ── Pricing clarity ─────────────────────────────────────────────────── -->
    <section class="fb-sec fb-price fb-reveal">
      <div class="fb-price-card">
        <div class="fb-price-head">
          <h2 class="fb-h2">Free on your site.<br />Pay only when we bring the customer.</h2>
        </div>
        <div class="fb-price-rows">
          <div class="fb-price-row">
            <div class="fb-price-l"><Globe :size="18" /> Bookings from your own website</div>
            <div class="fb-price-r fb-free">0% platform fee</div>
          </div>
          <div class="fb-price-row">
            <div class="fb-price-l"><Store :size="18" /> Bookings from the Bookboll marketplace</div>
            <div class="fb-price-r fb-comm">Small platform fee</div>
          </div>
        </div>
        <div class="fb-price-note">
          <Info :size="16" />
          <span><b>Good to know:</b> marketplace bookings are always paid at least partly online — the online deposit (or the commission on a full online payment) is how Bookboll collects its fee; the balance is paid to you on arrival. On your own website you can also offer full pay-on-arrival and there's no fee — just connect a payout method to your <b>Bookings wallet</b>, or online deposits go to Bookboll as the platform fee. Membership programs need a <b>Subscription-wallet</b> payout method before going live. <router-link to="/terms" class="fb-tc-link">Provider terms apply</router-link>.</span>
        </div>
      </div>
    </section>


    <!-- ── What you can list ───────────────────────────────────────────────── -->
    <section class="fb-sec fb-list fb-reveal">
      <div class="fb-eyebrow">Built for your business</div>
      <h2 class="fb-h2">Courts, gyms &amp; academies — all in one</h2>
      <div class="fb-list-grid">
        <div v-for="t in listTypes" :key="t.title" class="fb-list-card">
          <div class="fb-list-ico"><component :is="t.icon" :size="26" /></div>
          <h3>{{ t.title }}</h3>
          <p>{{ t.sub }}</p>
          <ul class="fb-list-tags">
            <li v-for="tag in t.tags" :key="tag"><Check :size="13" /> {{ tag }}</li>
          </ul>
        </div>
      </div>
      <div class="fb-sports">
        <span v-for="s in SPORTS" :key="s" class="fb-sport-chip">{{ s }}</span>
        <span class="fb-sport-chip more">+ more</span>
      </div>
    </section>

    <!-- ── Capabilities ────────────────────────────────────────────────────── -->
    <section class="fb-sec fb-caps">
      <div class="fb-reveal">
      <div class="fb-eyebrow light">The same engine that powers Bookboll</div>
      <h2 class="fb-h2 light">Everything you need to run your facility</h2>
      <div class="fb-caps-grid">
        <div v-for="c in capabilities" :key="c.title" class="fb-cap">
          <div class="fb-cap-ico"><component :is="c.icon" :size="20" /></div>
          <div class="fb-cap-t">
            <b>{{ c.title }}</b>
            <small>{{ c.sub }}</small>
          </div>
        </div>
      </div>
      </div>
    </section>

    <!-- ── How it works ────────────────────────────────────────────────────── -->
    <section class="fb-sec fb-how fb-reveal">
      <div class="fb-eyebrow">Live in minutes</div>
      <h2 class="fb-h2">From sign-up to first booking</h2>
      <div class="fb-steps">
        <div v-for="(s, i) in steps" :key="s.title" class="fb-step">
          <div class="fb-step-num">{{ i + 1 }}</div>
          <div class="fb-step-ico"><component :is="s.icon" :size="22" /></div>
          <h3>{{ s.title }} <span v-if="s.opt" class="fb-step-opt">Optional</span></h3>
          <p>{{ s.sub }}</p>
        </div>
      </div>
      <div class="fb-how-cta">
        <a v-if="authUser" :href="adminDashboardUrl()" class="fb-btn-primary">Open my dashboard <ArrowRight :size="18" /></a>
        <router-link v-else to="/signup" class="fb-btn-primary">Get started free <ArrowRight :size="18" /></router-link>
      </div>
    </section>

    <!-- ── FAQ (matches the FAQPage JSON-LD above) ─────────────────────────── -->
    <section class="fb-sec fb-faq fb-reveal">
      <div class="fb-eyebrow">Questions, answered</div>
      <h2 class="fb-h2">Sports facility booking — FAQs</h2>
      <div class="fb-faq-list">
        <details v-for="f in FAQS" :key="f.q" class="fb-faq-item">
          <summary>{{ f.q }} <ChevronDown :size="17" class="fb-faq-caret" /></summary>
          <p>{{ f.a }}</p>
        </details>
      </div>
    </section>

    <!-- ── Final CTA ───────────────────────────────────────────────────────── -->
    <section class="fb-final">
      <div class="fb-final-in">
        <h2>Ready to fill your courts?</h2>
        <p>Free website. More players. One dashboard.</p>
        <div class="fb-final-cta">
          <a v-if="authUser" :href="adminDashboardUrl()" class="fb-btn-primary big">Open my dashboard <ArrowRight :size="18" /></a>
          <template v-else>
            <router-link to="/signup" class="fb-btn-primary big">Get started free <ArrowRight :size="18" /></router-link>
            <a :href="adminLoginUrl()" class="fb-btn-ghost">Already a partner? Sign in</a>
          </template>
        </div>
      </div>
    </section>

    <!-- ── Internal links (crawlability: marketplace hubs + terms + signup) ── -->
    <nav class="fb-crawl" aria-label="Explore Bookboll">
      <span class="fb-crawl-t">Explore Bookboll</span>
      <a :href="`${webBaseUrl()}/venues`">Sports venues by city</a>
      <a :href="`${webBaseUrl()}/memberships`">Gym &amp; academy memberships</a>
      <a :href="`${webBaseUrl()}/terms`">Terms</a>
      <router-link to="/signup">Create your business account</router-link>
    </nav>

    <!-- ── Footer ──────────────────────────────────────────────────────────── -->
    <footer class="fb-foot">
      <span>© {{ YEAR }} Bookboll — booking software for sports facilities</span>
      <div class="fb-foot-links">
        <a :href="webBaseUrl() + '/'">← Back to marketplace</a>
        <router-link to="/pricing">Pricing</router-link>
        <router-link to="/blog">Blog</router-link>
        <router-link to="/terms">Provider terms</router-link>
        <a v-if="authUser" :href="adminDashboardUrl()">Dashboard</a>
        <a v-else :href="adminLoginUrl()">Sign in</a>
      </div>
    </footer>
  </div>
</template>

<style scoped>
/* FIXED teal theme — do NOT swap for --brand vars (must not follow super-admin marketplace theming). */
.fb { --ink:#0f172a; --teal:#14b8a6; --teal-2:#2dd4bf; --teal-lite:#5eead4; --green:#34d399;
  background:var(--surface, #ffffff); color:var(--ink); min-height:100vh;
  font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;
  -webkit-font-smoothing:antialiased; }
.fb h1,.fb h2,.fb h3 { margin:0; }
.fb p { margin:0; }

/* ── Nav ── */
.fb-nav { position:sticky; top:0; z-index:30; background:rgba(15,23,42,.82); backdrop-filter:blur(10px); border-bottom:1px solid rgba(148,163,184,.16); }
.fb-nav-in { max-width:1120px; margin:0 auto; padding:13px 22px; display:flex; align-items:center; justify-content:space-between; }
.fb-brand { display:flex; align-items:center; gap:9px; text-decoration:none; }
.fb-mark { width:30px; height:30px; border-radius:9px; background:#16a39b; color:#fff; font-weight:900; font-size:17px; display:flex; align-items:center; justify-content:center; }
.fb-brand-name { color:#fff; font-weight:900; font-size:18px; letter-spacing:-.01em; }
.fb-brand-pill { color:var(--teal-lite); font-weight:800; font-size:11.5px; background:rgba(20,184,166,.16); padding:3px 9px; border-radius:999px; }
.fb-nav-actions { display:flex; align-items:center; gap:14px; }
.fb-nav-signin { color:#cbd5e1; font-weight:700; font-size:14px; text-decoration:none; }
.fb-nav-signin:hover { color:#fff; }
.fb-nav-cta { display:inline-flex; align-items:center; gap:7px; background:var(--teal); color:#04201c; font-weight:800; font-size:14px; padding:9px 17px; border-radius:999px; text-decoration:none; white-space:nowrap; }
.fb-nav-cta:hover { background:var(--teal-2); }
/* Language switcher, restyled for the dark sticky nav */
.fb-lang :deep(.lang-trigger) { background:rgba(255,255,255,.08); border-color:rgba(148,163,184,.35); color:var(--border, #e2e8f0); }
.fb-lang :deep(.lang-trigger:hover) { border-color:var(--teal-lite); color:#fff; }

/* ── Hero ── */
.fb-hero { background:radial-gradient(1200px 500px at 80% -10%, rgba(20,184,166,.22), transparent 60%), linear-gradient(135deg,#0f172a,#134e4a); color:#fff; padding:64px 22px 76px; }
.fb-hero-grid { max-width:1120px; margin:0 auto; display:grid; grid-template-columns:1.02fr .98fr; gap:48px; align-items:center; }
.fb-badge { display:inline-flex; align-items:center; gap:7px; font-size:12.5px; font-weight:800; color:var(--teal-lite); background:rgba(20,184,166,.16); padding:6px 13px; border-radius:999px; }
/* 40px (was 46) — the keyword-rich h1 is longer; keeps it to ~3 lines in its column. */
.fb-hero-text h1 { color:#fff; font-size:40px; line-height:1.12; font-weight:900; letter-spacing:-.02em; margin:16px 0 14px; }
.fb-hl { color:var(--teal-lite); }
.fb-hero-sub { font-size:16.5px; color:#cbd5e1; line-height:1.55; max-width:500px; }
.fb-hero-cta { display:flex; gap:12px; margin:26px 0 18px; flex-wrap:wrap; }
.fb-btn-primary { display:inline-flex; align-items:center; gap:8px; background:var(--teal); color:#04201c; font-weight:800; font-size:15.5px; padding:14px 26px; border-radius:999px; text-decoration:none; box-shadow:0 8px 24px rgba(20,184,166,.32); transition:background .16s ease, transform .16s ease, box-shadow .16s ease; }
.fb-btn-primary:hover { background:var(--teal-2); transform:translateY(-2px); box-shadow:0 12px 28px rgba(20,184,166,.42); }
.fb-btn-primary.big { padding:16px 32px; font-size:16.5px; }
.fb-btn-ghost { display:inline-flex; align-items:center; gap:8px; color:var(--border, #e2e8f0); font-weight:800; font-size:15px; padding:14px 22px; border-radius:999px; text-decoration:none; border:1.5px solid rgba(148,163,184,.4); transition:border-color .16s ease, color .16s ease; }
.fb-btn-ghost:hover { border-color:var(--teal-lite); color:#fff; }
.fb-trust { list-style:none; padding:0; margin:0; display:flex; flex-wrap:wrap; gap:8px 20px; }
.fb-trust li { display:flex; align-items:center; gap:7px; font-size:13.5px; color:#cbd5e1; font-weight:700; }
.fb-trust svg { color:var(--green); }

/* ── Hero visual (CSS product mock; logical insets so RTL mirrors cleanly) ── */
.fb-hero-visual { position:relative; height:360px; }
.fb-visual-glow { position:absolute; inset:10% 6%; background:radial-gradient(closest-side, rgba(20,184,166,.35), transparent 72%); filter:blur(6px); }
.fb-mock { position:absolute; background:var(--surface, #ffffff); border-radius:16px; box-shadow:0 30px 70px rgba(0,0,0,.45); overflow:hidden; }
.fb-mock-tag { position:absolute; font-size:10px; font-weight:800; padding:4px 9px; border-radius:999px; box-shadow:0 4px 12px rgba(0,0,0,.25); z-index:3; }
.fb-tag-free { top:44px; inset-inline-end:-8px; background:var(--green); color:#04201c; }
.fb-tag-market { top:-9px; inset-inline-start:14px; background:#0f172a; color:var(--teal-lite); border:1px solid rgba(94,234,212,.4); }
.fb-plus { position:absolute; top:47%; left:50%; transform:translate(-58%,-50%); width:42px; height:42px; border-radius:50%; background:linear-gradient(135deg,var(--teal-2),var(--teal)); color:#04201c; font-weight:900; font-size:24px; display:flex; align-items:center; justify-content:center; box-shadow:0 8px 20px rgba(0,0,0,.35); z-index:2; }

/* Your booking website — browser chrome + nav + hero + court cards */
.fb-mock-site { width:302px; top:6px; inset-inline-start:4px; transform:rotate(-2deg); }
.fb-mock-head { display:flex; align-items:center; gap:5px; padding:8px 12px; background:#f1f5f9; border-bottom:1px solid var(--border, #e2e8f0); }
.fb-mock-head b { margin-inline-start:6px; font-size:10.5px; color:var(--text-muted, #64748b); font-weight:700; }
.fb-dot { width:8px; height:8px; border-radius:50%; background:#cbd5e1; }
.fb-dot.r { background:#fca5a5; } .fb-dot.y { background:#fcd34d; } .fb-dot.g { background:#86efac; }
.fb-site-nav { display:flex; align-items:center; gap:7px; padding:8px 12px; border-bottom:1px solid #f1f5f9; }
.fb-site-logo { width:14px; height:14px; border-radius:5px; background:linear-gradient(135deg,var(--teal-2),#0f766e); flex-shrink:0; }
.fb-site-nav b { font-size:11px; font-weight:800; color:#0f172a; white-space:nowrap; }
.fb-site-links { display:flex; gap:6px; margin-inline-start:auto; }
.fb-site-links i { width:16px; height:4px; border-radius:99px; background:var(--border, #e2e8f0); }
.fb-site-book { font-size:9.5px; font-weight:800; color:#04201c; background:var(--teal); border-radius:999px; padding:3px 9px; }
.fb-site-hero { margin:10px 12px 0; height:64px; border-radius:10px; background:linear-gradient(120deg,#0f766e,#134e4a 55%,#0f172a); display:flex; flex-direction:column; justify-content:center; gap:3px; padding:0 12px; box-sizing:border-box; }
.fb-site-hero b { color:#fff; font-size:13px; font-weight:900; }
.fb-site-hero small { color:#99f6e4; font-size:9.5px; font-weight:700; }
.fb-site-cards { display:flex; gap:9px; padding:10px 12px 12px; }
.fb-site-card { flex:1; border:1px solid var(--border, #e2e8f0); border-radius:10px; padding:6px; display:flex; flex-direction:column; gap:2px; }
.fb-site-img { height:34px; border-radius:7px; }
.fb-site-img.c1 { background:linear-gradient(135deg,#2dd4bf,#0e7490); }
.fb-site-img.c2 { background:linear-gradient(135deg,#60a5fa,#3730a3); }
.fb-site-card b { font-size:10px; font-weight:800; color:#0f172a; margin-top:3px; }
.fb-site-card small { font-size:9px; font-weight:800; color:#0f766e; }

/* The Bookboll marketplace — app header + search + results (your club highlighted) */
.fb-mock-market { width:216px; inset-inline-end:0; bottom:2px; transform:rotate(2deg); }
.fb-bb-head { display:flex; align-items:center; gap:6px; padding:9px 11px; border-bottom:1px solid #f1f5f9; }
.fb-bb-logo { width:16px; height:16px; border-radius:5px; background:linear-gradient(135deg,var(--teal-2),var(--teal)); color:#04201c; font-size:10px; font-weight:900; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.fb-bb-head b { font-size:11px; font-weight:900; color:#0f172a; }
.fb-bb-loc { display:inline-flex; align-items:center; gap:3px; margin-inline-start:auto; font-size:8.5px; font-weight:800; color:#475569; background:#f1f5f9; border-radius:999px; padding:3px 7px; }
.fb-bb-loc svg { color:var(--teal); }
.fb-mock-search { display:flex; align-items:center; gap:6px; background:#f1f5f9; color:var(--text-muted, #64748b); font-size:10.5px; font-weight:700; padding:7px 10px; border-radius:8px; margin:9px 10px 7px; }
.fb-mock-search svg { color:var(--teal); flex-shrink:0; }
.fb-mock-item { display:flex; align-items:center; gap:8px; padding:6px; margin:0 10px 5px; border-radius:9px; border:1px solid transparent; }
.fb-mock-item:last-of-type { margin-bottom:11px; }
.fb-mock-item.hl { background:#f0fdfa; border-color:rgba(20,184,166,.45); }
.fb-mock-thumb { width:26px; height:26px; border-radius:7px; flex-shrink:0; }
.fb-mock-thumb.t1 { background:linear-gradient(135deg,#38bdf8,#1d4ed8); }
.fb-mock-thumb.t2 { background:linear-gradient(135deg,var(--teal-2),#0f766e); }
.fb-mock-thumb.t3 { background:linear-gradient(135deg,#fbbf24,#ea580c); }
.fb-mock-item-t { display:flex; flex-direction:column; min-width:0; }
.fb-mock-item-t b { font-size:11px; font-weight:800; color:#0f172a; white-space:nowrap; }
.fb-mock-item.hl .fb-mock-item-t b { color:#0f766e; }
.fb-mock-item-t small { font-size:9.5px; color:var(--text-light, #94a3b8); font-weight:600; }

/* ── Shared section ── */
.fb-sec { max-width:1120px; margin:0 auto; padding:68px 22px; scroll-margin-top:70px; }
.fb-eyebrow { text-align:center; font-size:12.5px; font-weight:900; letter-spacing:.09em; text-transform:uppercase; color:var(--teal); }
.fb-eyebrow.light { color:var(--teal-lite); }
.fb-h2 { text-align:center; font-size:32px; font-weight:900; letter-spacing:-.02em; margin:10px 0 0; line-height:1.18; }
.fb-h2.light { color:#fff; }

/* Scroll-reveal (gated behind .fb-anim so no-JS / reduced-motion always sees content) */
.fb-anim .fb-reveal { opacity:0; transform:translateY(18px); transition:opacity .55s ease, transform .55s ease; }
.fb-anim .fb-reveal.in { opacity:1; transform:none; }

/* ── Twin (2-in-1) ── */
.fb-twin-grid { display:grid; grid-template-columns:1fr auto 1fr; gap:18px; align-items:stretch; margin-top:40px; }
.fb-twin-card { background:var(--surface, #ffffff); border:1.5px solid var(--border, #e2e8f0); border-radius:20px; padding:30px 28px; display:flex; flex-direction:column; transition:border-color .16s ease, box-shadow .16s ease; }
.fb-twin-card:hover { border-color:var(--teal); box-shadow:0 16px 40px rgba(15,23,42,.07); }
.fb-twin-ico { width:54px; height:54px; border-radius:15px; background:#0f172a; color:var(--teal-lite); display:flex; align-items:center; justify-content:center; margin-bottom:16px; }
.fb-twin-card h3 { font-size:22px; font-weight:900; }
.fb-twin-list { list-style:none; padding:0; margin:14px 0 18px; display:flex; flex-direction:column; gap:10px; flex:1; }
.fb-twin-list li { display:flex; align-items:flex-start; gap:9px; font-size:14.5px; font-weight:600; color:#334155; line-height:1.45; }
.fb-twin-list svg { color:var(--teal); flex-shrink:0; margin-top:2px; }
.fb-twin-pill { display:inline-flex; align-items:center; gap:7px; font-size:13px; font-weight:800; padding:9px 14px; border-radius:999px; align-self:flex-start; }
.fb-pill-green { background:#ecfdf5; color:#047857; }
.fb-pill-teal { background:#f0fdfa; color:#0f766e; }
.fb-twin-note { font-size:12px; color:var(--text-light, #94a3b8); margin-top:12px; line-height:1.5; }
.fb-twin-join { display:flex; align-items:center; justify-content:center; }
.fb-twin-join span { width:46px; height:46px; border-radius:50%; background:linear-gradient(135deg,var(--teal-2),var(--teal)); color:#04201c; font-weight:900; font-size:26px; display:flex; align-items:center; justify-content:center; box-shadow:0 8px 20px rgba(20,184,166,.3); }

/* ── Pricing ── */
.fb-price { padding-top:0; }
.fb-price-card { background:linear-gradient(135deg,#0f172a,#134e4a); border-radius:24px; padding:44px 40px; color:#fff; }
.fb-price-head .fb-h2 { color:#fff; text-align:start; }
.fb-price-rows { margin:28px 0 22px; display:flex; flex-direction:column; gap:12px; }
.fb-price-row { display:flex; align-items:center; justify-content:space-between; gap:16px; background:rgba(255,255,255,.05); border:1px solid rgba(148,163,184,.18); border-radius:14px; padding:16px 20px; flex-wrap:wrap; }
.fb-price-l { display:flex; align-items:center; gap:11px; font-size:15.5px; font-weight:700; color:var(--border, #e2e8f0); }
.fb-price-l svg { color:var(--teal-lite); flex-shrink:0; }
.fb-price-r { font-size:16px; font-weight:900; white-space:nowrap; }
.fb-free { color:var(--green); }
.fb-comm { color:var(--teal-lite); }
.fb-price-note { display:flex; align-items:flex-start; gap:10px; font-size:12.5px; line-height:1.6; color:#cbd5e1; background:rgba(20,184,166,.1); border:1px solid rgba(20,184,166,.25); border-radius:14px; padding:13px 15px; }
.fb-price-note svg { color:var(--teal-lite); flex-shrink:0; margin-top:1px; }
.fb-price-note b { color:#fff; }
.fb-tc { color:var(--text-light, #94a3b8); }
.fb-tc-link { color:var(--teal-lite); font-weight:700; text-decoration:underline; text-underline-offset:2px; }
.fb-tc-link:hover { color:#fff; }

/* ── What you can list ── */
.fb-list-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:20px; margin-top:40px; }
.fb-list-card { background:var(--surface, #ffffff); border:1.5px solid var(--border, #e2e8f0); border-radius:20px; padding:28px 26px; transition:border-color .16s ease, box-shadow .16s ease, transform .16s ease; }
.fb-list-card:hover { border-color:var(--teal); box-shadow:0 16px 40px rgba(15,23,42,.07); transform:translateY(-2px); }
.fb-list-ico { width:56px; height:56px; border-radius:16px; background:linear-gradient(135deg,#0f172a,#134e4a); color:var(--teal-lite); display:flex; align-items:center; justify-content:center; margin-bottom:16px; }
.fb-list-card h3 { font-size:20px; font-weight:900; }
.fb-list-card p { font-size:14px; color:var(--text-muted, #64748b); line-height:1.5; margin:7px 0 16px; }
.fb-list-tags { list-style:none; padding:0; margin:0; display:flex; flex-direction:column; gap:8px; }
.fb-list-tags li { display:flex; align-items:center; gap:8px; font-size:13.5px; font-weight:700; color:#334155; }
.fb-list-tags svg { color:var(--teal); flex-shrink:0; }
.fb-sports { display:flex; flex-wrap:wrap; justify-content:center; gap:8px; margin-top:28px; }
.fb-sport-chip { font-size:12.5px; font-weight:800; color:#0f766e; background:#f0fdfa; border:1px solid #99f6e4; border-radius:999px; padding:6px 14px; }
.fb-sport-chip.more { color:var(--text-muted, #64748b); background:#f8fafc; border-color:var(--border, #e2e8f0); }

/* ── Capabilities (dark) ── */
.fb-caps { max-width:none; background:linear-gradient(135deg,#0f172a,#134e4a); margin-top:0; }
.fb-caps-grid { max-width:1000px; margin:40px auto 0; display:grid; grid-template-columns:repeat(3,1fr); gap:16px; }
.fb-cap { display:flex; align-items:center; gap:14px; background:rgba(255,255,255,.05); border:1px solid rgba(148,163,184,.16); border-radius:16px; padding:18px 18px; }
.fb-cap:hover { border-color:rgba(94,234,212,.45); background:rgba(20,184,166,.08); }
.fb-cap-ico { width:44px; height:44px; border-radius:12px; background:rgba(20,184,166,.16); color:var(--teal-lite); display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.fb-cap-t { display:flex; flex-direction:column; gap:2px; min-width:0; }
.fb-cap-t b { font-size:15px; font-weight:800; color:#fff; }
.fb-cap-t small { font-size:12.5px; color:var(--text-light, #94a3b8); }

/* ── How it works ── */
.fb-steps { display:grid; grid-template-columns:repeat(4,1fr); gap:18px; margin-top:44px; position:relative; }
.fb-steps::before { content:''; position:absolute; top:44px; left:6%; right:6%; border-top:2px dashed #cbd5e1; }
.fb-step { position:relative; z-index:1; background:var(--surface, #ffffff); border:1.5px solid var(--border, #e2e8f0); border-radius:18px; padding:26px 22px 22px; transition:border-color .16s ease, box-shadow .16s ease; }
.fb-step:hover { border-color:var(--teal); box-shadow:0 12px 30px rgba(15,23,42,.06); }
.fb-step-num { position:absolute; top:-14px; inset-inline-start:22px; width:30px; height:30px; border-radius:50%; background:linear-gradient(135deg,var(--teal-2),var(--teal)); color:#04201c; font-weight:900; font-size:14.5px; display:flex; align-items:center; justify-content:center; box-shadow:0 6px 16px rgba(20,184,166,.3); }
.fb-step-ico { width:46px; height:46px; border-radius:13px; background:#f0fdfa; color:#0f766e; display:flex; align-items:center; justify-content:center; margin:8px 0 14px; }
.fb-step h3 { font-size:16.5px; font-weight:900; display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.fb-step-opt { font-size:10px; font-weight:800; text-transform:uppercase; letter-spacing:.04em; color:var(--text-muted, #64748b); background:#f1f5f9; border-radius:999px; padding:2px 8px; }
.fb-step p { font-size:13.5px; color:var(--text-muted, #64748b); line-height:1.5; margin-top:6px; }
.fb-how-cta { text-align:center; margin-top:40px; }

/* ── FAQ ── */
.fb-faq { padding-top:0; }
.fb-faq-list { max-width:760px; margin:36px auto 0; display:flex; flex-direction:column; gap:10px; }
/* Quiet divider-list FAQ (matches the marketplace's redesigned FAQ) */
.fb-faq-item { border-bottom:1px solid var(--border, #e8edf3); }
.fb-faq-item:last-child { border-bottom:none; }
.fb-faq-item summary { display:flex; align-items:center; justify-content:space-between; gap:14px; list-style:none; cursor:pointer; padding:14px 2px; font-size:14px; font-weight:600; color:#334155; }
.fb-faq-item summary:hover { color:var(--teal); }
.fb-faq-item[open] summary { color:#0f172a; }
.fb-faq-item summary::-webkit-details-marker { display:none; }
.fb-faq-caret { color:var(--text-light, #94a3b8); flex-shrink:0; transition:transform .18s; }
.fb-faq-item[open] .fb-faq-caret { transform:rotate(180deg); color:var(--teal); }
.fb .fb-faq-item p { font-size:13.5px; color:var(--text-muted, #64748b); line-height:1.65; margin:0; padding:0 24px 15px 2px; }

/* ── Final CTA ── */
.fb-final { background:radial-gradient(900px 400px at 50% 120%, rgba(20,184,166,.25), transparent 60%), linear-gradient(135deg,#0f172a,#134e4a); color:#fff; padding:80px 22px; text-align:center; }
.fb-final-in { max-width:680px; margin:0 auto; }
.fb-final h2 { color:#fff; font-size:36px; font-weight:900; letter-spacing:-.02em; }
.fb-final p { font-size:17px; color:#cbd5e1; margin-top:12px; }
.fb-final-cta { display:flex; gap:14px; justify-content:center; align-items:center; margin-top:30px; flex-wrap:wrap; }

/* ── Internal links row (subtle, blends into the dark footer band) ── */
.fb-crawl { background:#0b1220; border-bottom:1px solid rgba(148,163,184,.14); padding:15px 22px; display:flex; flex-wrap:wrap; align-items:center; justify-content:center; gap:8px 20px; font-size:12.5px; }
.fb-crawl-t { color:var(--text-muted, #64748b); font-weight:800; font-size:11px; text-transform:uppercase; letter-spacing:.07em; }
.fb-crawl a { color:var(--text-light, #94a3b8); text-decoration:none; font-weight:700; }
.fb-crawl a:hover { color:var(--teal-lite); }

/* ── Footer ── */
.fb-foot { background:#0b1220; color:var(--text-light, #94a3b8); padding:22px; display:flex; align-items:center; justify-content:space-between; gap:14px; flex-wrap:wrap; font-size:13px; }
.fb-foot-links { display:flex; gap:20px; }
.fb-foot-links a { color:#cbd5e1; text-decoration:none; font-weight:700; }
.fb-foot-links a:hover { color:#fff; }

/* ── Responsive ── */
@media (max-width:900px) {
  .fb-hero-grid { grid-template-columns:1fr; gap:36px; }
  /* Scale the mock collage so the two cards don't collide once the grid stacks. */
  .fb-hero-visual { height:300px; max-width:460px; margin:0 auto; width:100%; }
  .fb-mock-site { width:252px; }
  .fb-mock-market { width:178px; }
  .fb-site-hero { height:52px; }
  .fb-site-img { height:26px; }
  .fb-hero-text h1 { font-size:36px; }
  .fb-twin-grid { grid-template-columns:1fr; }
  .fb-twin-join span { transform:rotate(90deg); }
  .fb-list-grid { grid-template-columns:1fr 1fr; }
  .fb-list-card:last-child { grid-column:1 / -1; }
  .fb-caps-grid { grid-template-columns:1fr 1fr; }
  .fb-steps { grid-template-columns:1fr 1fr; gap:24px 18px; }
  .fb-steps::before { display:none; }
}
@media (max-width:720px) {
  .fb-hero-text h1 { font-size:32px; }
}

@media (max-width:560px) {
  .fb-hero { padding:44px 18px 56px; }
  /* Pass 2: converge the type scale — the page h1 leads (only remaining 900), all
     other headings step down to 800 so nothing competes with it. */
  .fb-hero-text h1 { font-size:22px; line-height:1.2; }
  .fb-hero-sub { font-size:14.5px; }
  .fb-sec { padding:50px 18px; }
  .fb-h2 { font-size:17px; font-weight:800; }
  .fb-final h2 { font-size:18px; font-weight:800; }
  .fb-twin-card h3, .fb-list-card h3 { font-size:16px; font-weight:800; }
  .fb-step h3 { font-size:15.5px; font-weight:800; }
  .fb-eyebrow { font-weight:800; letter-spacing:.05em; font-size:12px; }
  /* Kickers that merely restate the heading below them add a redundant row per section. */
  .fb-faq .fb-eyebrow, .fb-list .fb-eyebrow { display:none; }
  /* Desktop-oriented forced break in the pricing heading. */
  .fb-price-head br { display:none; }
  .fb-list-grid, .fb-caps-grid, .fb-steps { grid-template-columns:1fr; }
  /* Tighter heading→content rhythm under the smaller phone headings. */
  .fb-twin-grid, .fb-list-grid, .fb-caps-grid, .fb-steps, .fb-faq-list { margin-top:24px; }
  .fb-how-cta { margin-top:28px; }
  .fb-price-card { padding:30px 22px; }
  /* Money-disclosure note keeps every word, but demoted from an outlined tinted box to a
     plain footnote so the pricing card has ONE inner visual level (the two rows). */
  .fb-price-note { background:transparent; border:none; padding:2px 0 0; font-size:12.5px; }
  .fb-price-l { font-size:14.5px; }
  .fb-brand-pill { display:none; }
  .fb-btn-primary, .fb-btn-ghost { width:100%; justify-content:center; box-sizing:border-box; }
  .fb-btn-primary, .fb-btn-primary.big { font-size:15px; }
  .fb-hero-cta, .fb-final-cta { width:100%; }
  .fb-faq-item summary { font-size:14px; }
  .fb-step-opt { font-size:11px; letter-spacing:0; }
  /* Shrink the decorative hero collage ~20% (aria-hidden CSS illustration). */
  .fb-hero-visual { height:238px; max-width:360px; }
  .fb-mock-site { width:218px; }
  .fb-mock-market { width:152px; }
  .fb-site-hero { height:44px; }
  .fb-site-img { height:22px; }
  .fb-plus { width:34px; height:34px; font-size:19px; }
  /* Calm the sports chip cloud; the full list stays in the "Which sports" FAQ below. */
  .fb-sport-chip { font-size:12px; padding:5px 11px; }
  .fb-sport-chip:nth-child(n+7):not(.more) { display:none; }
  /* Header CTA up to ~44px touch height on phones. */
  .fb-nav-cta { padding:11px 14px; }
  /* Footer links were a single unwrappable flex line (~330px+) — let them wrap. */
  .fb-foot-links { flex-wrap:wrap; gap:10px 16px; }
}
@media (max-width:400px) {
  /* Narrow phones (344-400px): slim the sticky bar so it never overflows sideways.
     "Sign in" stays reachable via hero, final CTA and footer links. */
  .fb-nav-in { padding:11px 12px; }
  .fb-nav-actions { gap:8px; }
  .fb-nav-signin { display:none; }
  .fb-brand-name { font-size:16px; }
  .fb-nav-cta { padding:11px 12px; font-size:12.5px; }
}
</style>

