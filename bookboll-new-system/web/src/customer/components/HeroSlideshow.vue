<script setup>
/**
 * HeroSlideshow.vue — the customizable home hero. Renders a rotating set of banners
 * (already filtered for the current audience). Each banner: image OR colour bg, heading
 * + subheading (supports a {city} token), and an optional CTA button (preset or custom).
 */
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import { bannerLink, bannerButtonText, hasButton, applyTokens, bannerButtons, bannerBgStyle } from '../../lib/heroBanners.js'
import { sanitizeHtml } from '../../lib/blog.js'
import { selectedCountry, customer, isCustomer } from '../../lib/customerSession.js'
import { activeBrand, platformBrand } from '../../lib/brand.js'
import { Shield, Star, Flame, Crown, ArrowRight } from 'lucide-vue-next'

const props = defineProps({
  banners: { type: Array, default: () => [] },
  city: { type: String, default: '' },
  loyalty: { type: Object, default: null },     // system loyalty slide data (signed-in apex)
  customerName: { type: String, default: '' },
})

const TIER_ICON = {
  Bronze: Shield, Silver: Star, Gold: Flame, Platinum: Crown,
  Rookie: Shield, Legend: Flame, GOAT: Crown,
}
function tierIcon(n) { return TIER_ICON[n] || Shield }

const idx = ref(0)
const cur = computed(() => props.banners[idx.value] || props.banners[0] || null)
// Brand identity for banners with "show logo" on (provider site → their brand,
// marketplace → the platform brand).
const brandLogo = computed(() => activeBrand.value?.logo || platformBrand.value?.logo || '')
const brandMono = computed(() => {
  const n = activeBrand.value?.name || platformBrand.value?.name || 'BB'
  return n.trim().split(' ').map(w => w[0]).slice(0, 2).join('').toUpperCase()
})
const tokCtx = computed(() => ({
  city: props.city,
  country: selectedCountry.value?.name,
  name: isCustomer.value ? (customer.value?.full_name || '').split(' ')[0] : '',
}))
function tok(s) { return applyTokens(s, tokCtx.value) }
// Description is rich HTML (RichEditor) — resolve tokens then sanitize for safe v-html.
const subHtml = computed(() => cur.value?.subheading ? sanitizeHtml(applyTokens(cur.value.subheading, tokCtx.value)) : '')

let timer = null
function start() {
  stop()
  if (props.banners.length > 1) timer = setInterval(() => { idx.value = (idx.value + 1) % props.banners.length }, 6000)
}
function stop() { if (timer) { clearInterval(timer); timer = null } }
function go(i) { idx.value = (i + props.banners.length) % props.banners.length; start() }   // reset timer on manual nav
onMounted(start)
onBeforeUnmount(stop)
watch(() => props.banners.length, () => { idx.value = 0; start() })

// Background + text styling for the active slide.
// Image, colour, or colour-overlay-on-image; empty → brand gradient via CSS.
const slideStyle = computed(() => bannerBgStyle(cur.value || {}))
const btnStyle = computed(() => {
  const c = cur.value?.button_color
  return c ? { background: c, color: '#fff' } : {}
})
</script>

<template>
  <div v-if="cur" class="hs" @mouseenter="stop" @mouseleave="start">
    <!-- System loyalty slide (signed-in apex customers) -->
    <div v-if="cur.kind === 'loyalty' && loyalty" class="hs-loy" :style="{ '--tbg': loyalty.tier.bg }">
      <div class="hs-loy-badge" :style="{ color: loyalty.tier.color }"><component :is="tierIcon(loyalty.tier.name)" :size="30" /></div>
      <div class="hs-loy-info">
        <div class="hs-loy-hi">Hey {{ customerName || 'there' }}!</div>
        <div class="hs-loy-rank">You're a <strong>{{ loyalty.tier.name }}</strong></div>
        <div class="hs-loy-points">{{ loyalty.points.toLocaleString() }} points · {{ loyalty.lifetime.toLocaleString() }} earned all-time</div>
        <div v-if="loyalty.next" class="hs-loy-progress">
          <div class="hs-loy-bar"><div class="hs-loy-fill" :style="{ width: loyalty.progress + '%' }"></div></div>
          <div class="hs-loy-next">{{ (loyalty.next.threshold - loyalty.lifetime).toLocaleString() }} points to {{ loyalty.next.name }}</div>
        </div>
        <div v-else class="hs-loy-next"><Crown :size="14" /> You've reached the top rank!</div>
      </div>
      <router-link to="/rewards" class="hs-loy-link">My Rewards <ArrowRight :size="15" /></router-link>
    </div>
    <!-- Normal banner slide -->
    <div v-else class="hs-slide" :class="{ 'hs-img': !!cur.image, 'hs-plain': !cur.image && !cur.bg, 'hs-center': cur.layout === 'center' }" :style="slideStyle">
      <div v-if="cur.image" class="hs-scrim"></div>
      <div class="hs-content">
        <div class="hs-lead">
          <img v-if="cur.icon" :src="cur.icon" class="hs-icon" alt="" />
          <span v-else-if="cur.show_logo && brandLogo" class="hs-brandlogo" :style="{ backgroundImage: `url(${brandLogo})` }"></span>
          <span v-else-if="cur.show_logo" class="hs-brandmono">{{ brandMono }}</span>
          <div class="hs-body" :style="{ color: cur.text_color || '#fff', fontFamily: cur.font || undefined }">
            <h1 class="hs-h1" :style="{ color: cur.heading_color || cur.text_color || '#fff', fontFamily: cur.heading_font || cur.font || undefined }">{{ tok(cur.heading) || 'Welcome' }}</h1>
            <div v-if="subHtml" class="hs-sub" v-html="subHtml"></div>
          </div>
        </div>
        <div v-if="bannerButtons(cur).length" class="hs-btns">
          <router-link v-for="(btn, bi) in bannerButtons(cur)" :key="btn.key" :to="btn.to"
            class="hs-btn" :class="{ ghost: bi > 0 }" :style="bi === 0 ? btnStyle : undefined">
            {{ btn.text }}
          </router-link>
        </div>
      </div>
    </div>

    <!-- Controls (only when more than one banner) -->
    <template v-if="banners.length > 1">
      <button type="button" class="hs-arrow hs-prev" @click="go(idx - 1)" aria-label="Previous">‹</button>
      <button type="button" class="hs-arrow hs-next" @click="go(idx + 1)" aria-label="Next">›</button>
      <div class="hs-dots">
        <button v-for="(b, i) in banners" :key="b.id || i" type="button" class="hs-dot"
          :class="{ on: i === idx }" @click="go(i)" :aria-label="`Slide ${i + 1}`"></button>
      </div>
    </template>
  </div>
</template>

<style scoped>
.hs { position: relative; border-radius: 20px; overflow: hidden; margin-bottom: 26px; }
.hs-slide { position: relative; min-height: 168px; display: flex; align-items: center; background-size: cover; background-position: center; padding: 26px 60px; }
.hs-plain, .hs-slide:not(.hs-img):not([style*="background"]) { background: linear-gradient(135deg, var(--brand-accent), var(--brand)); }
.hs-slide.hs-plain { background: linear-gradient(135deg, var(--brand-accent), var(--brand)); }
.hs-scrim { position: absolute; inset: 0; background: linear-gradient(90deg, rgba(0,0,0,.55), rgba(0,0,0,.15)); }
[dir="rtl"] .hs-scrim { background: linear-gradient(270deg, rgba(0,0,0,.55), rgba(0,0,0,.15)); }
/* Text on the left, button on the right (saves vertical space). */
.hs-content { position: relative; z-index: 1; width: 100%; display: flex; align-items: center; justify-content: space-between; gap: 24px; }
.hs-lead { display: flex; align-items: center; gap: 16px; min-width: 0; }
.hs-icon { width: 60px; height: 60px; border-radius: 14px; object-fit: cover; flex-shrink: 0; background: rgba(255,255,255,.18); }
.hs-body { max-width: 680px; min-width: 0; }
.hs-h1 { font-size: 28px; font-weight: 900; margin: 0; line-height: 1.12; }
.hs-sub { font-size: 15px; margin: 9px 0 0; opacity: .94; line-height: 1.5; }
.hs-sub :deep(p) { margin: 0; }
.hs-sub :deep(p + p) { margin-top: 6px; }
.hs-sub :deep(a) { color: inherit; text-decoration: underline; }
.hs-sub :deep(ul), .hs-sub :deep(ol) { margin: 4px 0 0; padding-inline-start: 20px; }
.hs-btns { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; flex-shrink: 0; }
.hs-btn { display: inline-flex; align-items: center; gap: 8px; flex-shrink: 0; background: var(--surface, #ffffff); color: var(--brand); font-weight: 800; padding: 13px 24px; border-radius: 999px; text-decoration: none; font-size: 15px; white-space: nowrap; }
.hs-btn.ghost { background: rgba(255,255,255,.14); color: #fff; border: 1.5px solid rgba(255,255,255,.6); }
/* Centered layout — mirrors the default business hero */
.hs-center .hs-content { flex-direction: column; justify-content: center; text-align: center; gap: 18px; }
.hs-center .hs-lead { flex-direction: column; align-items: center; gap: 12px; }
.hs-center .hs-btns { justify-content: center; }
.hs-brandlogo { width: 72px; height: 72px; border-radius: 18px; background-size: cover; background-position: center; background-color: var(--surface, #ffffff); box-shadow: 0 6px 22px rgba(0,0,0,.18); flex-shrink: 0; }
.hs-brandmono { width: 72px; height: 72px; border-radius: 18px; background: rgba(255,255,255,.16); color: #fff; font-size: 26px; font-weight: 900; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }
.hs-btn:hover { opacity: .94; }

/* System loyalty slide */
.hs-loy { position: relative; min-height: 168px; overflow: hidden; display: flex; align-items: center; gap: 18px; background: linear-gradient(135deg, #0f172a, #1e293b); color: #fff; padding: 22px 60px; }
.hs-loy::after { content: ''; position: absolute; inset-inline-end: -40px; top: -40px; width: 180px; height: 180px; background: var(--tbg); opacity: .15; border-radius: 50%; }
.hs-loy-badge { width: 70px; height: 70px; border-radius: 20px; background: var(--tbg); display: flex; align-items: center; justify-content: center; flex-shrink: 0; box-shadow: 0 6px 20px rgba(0,0,0,.3); z-index: 1; }
.hs-loy-info { flex: 1; min-width: 0; z-index: 1; }
.hs-loy-hi { font-size: 14px; opacity: .85; }
.hs-loy-rank { font-size: 22px; font-weight: 800; margin: 2px 0; }
.hs-loy-rank strong { color: #fbbf24; }
.hs-loy-points { font-size: 13px; opacity: .8; }
.hs-loy-progress { margin-top: 10px; max-width: 360px; }
.hs-loy-bar { height: 8px; border-radius: 999px; background: rgba(255,255,255,.15); overflow: hidden; }
.hs-loy-fill { height: 100%; background: linear-gradient(90deg, #fbbf24, #f59e0b); border-radius: 999px; }
.hs-loy-next { font-size: 12px; opacity: .85; margin-top: 6px; display: inline-flex; align-items: center; gap: 5px; }
.hs-loy-link { display: inline-flex; align-items: center; gap: 5px; color: #fff; text-decoration: none; font-weight: 700; font-size: 13.5px; background: rgba(255,255,255,.12); padding: 9px 16px; border-radius: 999px; flex-shrink: 0; z-index: 1; white-space: nowrap; }
.hs-loy-link:hover { background: rgba(255,255,255,.2); }
@media (max-width: 560px) {
  /* badge + rank on row 1 (info fills the width so "You're a Rookie" stays on one
     line), then "My Rewards" on its own full-width row — it was crushing the info
     column into an ugly 3-line wrap. */
  .hs-loy { padding: 18px 18px 34px; gap: 14px; flex-wrap: wrap; align-items: center; min-height: 0; }
  .hs-loy-badge { width: 54px; height: 54px; border-radius: 15px; }
  .hs-loy-hi { font-size: 13px; }
  .hs-loy-rank { font-size: 19px; }
  .hs-loy-points { font-size: 12px; }
  .hs-loy-progress { max-width: none; margin-top: 8px; }
  .hs-loy-link { flex: 0 0 100%; justify-content: center; margin-top: 2px; font-size: 14px; }
}

.hs-arrow { position: absolute; top: 50%; transform: translateY(-50%); width: 36px; height: 36px; border-radius: 50%; border: none; background: rgba(255,255,255,.85); color: #0f172a; font-size: 22px; line-height: 1; cursor: pointer; display: flex; align-items: center; justify-content: center; z-index: 2; }
.hs-arrow:hover { background: var(--surface, #ffffff); }
.hs-prev { inset-inline-start: 12px; }
.hs-next { inset-inline-end: 12px; }
/* RTL: flip the ‹ / › glyphs to match the reversed reading direction. */
[dir="rtl"] .hs-arrow { transform: translateY(-50%) scaleX(-1); }
.hs-dots { position: absolute; bottom: 12px; left: 50%; transform: translateX(-50%); display: flex; gap: 7px; z-index: 2; }
.hs-dot { width: 8px; height: 8px; border-radius: 50%; border: none; background: rgba(255,255,255,.5); cursor: pointer; padding: 0; }
.hs-dot.on { background: var(--surface, #ffffff); width: 22px; border-radius: 999px; }

@media (max-width: 560px) {
  .hs { margin-bottom: 16px; }
  /* Extra bottom padding keeps the absolute dot row (bottom:12px) clear of the CTA. */
  .hs-slide { padding: 22px 20px 36px; }
  .hs-content { flex-direction: column; align-items: flex-start; gap: 14px; }
  .hs-lead { gap: 12px; }
  .hs-icon { width: 46px; height: 46px; border-radius: 12px; }
  .hs-h1 { font-size: 22px; line-height: 1.2; }
  /* Provider-authored rich HTML: clamp to ~3 lines so long copy can't turn the hero into a text tower.
     overflow-wrap keeps long unbroken strings (URLs/emails) from forcing horizontal overflow. */
  .hs-sub { font-size: 14px; display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 3; overflow: hidden; overflow-wrap: anywhere; }
  /* Long provider-authored CTA labels wrap instead of overflowing the slide. */
  .hs-btn { white-space: normal; max-width: 100%; text-align: center; justify-content: center; }
  .hs-arrow { display: none; }
  /* Dots are the only in-hero nav on phones — enlarge the tap area, keep visuals unchanged. */
  .hs-dots { gap: 10px; }
  .hs-dot { position: relative; }
  .hs-dot::before { content: ''; position: absolute; inset: -10px; }
}
</style>
