<script setup>
/**
 * WebsiteEmbed.vue — "My Website → Embed & Widgets". Copy-paste snippets a provider drops
 * into their OWN existing website to surface their Bookboll storefront. Each widget is
 * FLOW-SCOPED via `?embed=<scope>` so it does exactly one job (a Venue-booking widget only
 * ever shows venues + booking, never the rest of the site). Only "Full site" is unrestricted.
 *
 * Layout: a row of widget-type tabs → options + code side-by-side → a large live preview at
 * the bottom. The preview resolves the provider by OWNER ID (`?bid=`), so it works today with
 * no DNS. Read-only page apart from auto-assigning a web address if the provider has none.
 */
import { ref, computed, onMounted, watch } from 'vue'
import { loadSiteSettings } from '../lib/siteSettings.js'
import { loadReferenceData } from '../lib/referenceData.js'
import { fetchSubscriptionsDashboard } from '../lib/subscriptions.js'
import { appBaseUrl } from '../lib/appBase.js'
import { useCurrentUser } from '../lib/currentUser.js'
import { usePremium, openUpgrade, PREMIUM_PRICE } from '../lib/premium.js'
import { brandHost, brandPreviewById, DEFAULT_PRIMARY } from '../lib/brand.js'
import {
  Code2, Copy, Check, Globe2, ExternalLink, Loader, AlertTriangle, Lock,
  LayoutTemplate, Dumbbell, GraduationCap, MousePointerClick, Sparkles, Monitor, Smartphone,
  CircleUserRound, LogIn, BookOpen,
} from 'lucide-vue-next'

const { currentOwnerUserId } = useCurrentUser()
const { isPremium } = usePremium()   // widgets are a Premium feature — free plan sees the upsell
const ownerId = computed(() => currentOwnerUserId.value)
const needsProvider = computed(() => !ownerId.value)

const loading = ref(true)
const brand = ref({ name: '', subdomain: '', primary: DEFAULT_PRIMARY, enabled: false, customDomain: '', domainActive: false })

// The provider's own memberships, for the single-program ("Program") widget picker.
// Scoped by owned locations (mirrors Listings.vue: class_programs has no reliable owner
// column across pages, so we filter to programs whose location belongs to this provider).
const programs = ref([])
const selectedProgramId = ref('')

async function loadPrograms() {
  programs.value = []
  if (!ownerId.value) return
  // reference-data is already scoped to this business's venues, and the
  // subscriptions dashboard returns the programmes that sit in them.
  const [ref, dash] = await Promise.all([loadReferenceData(), fetchSubscriptionsDashboard()])
  const ownedLocIds = new Set(ref.venues.map(l => String(l.id)))
  if (!ownedLocIds.size) return
  const all = dash.success ? (dash.data?.programs || []) : []
  const mine = all.filter(p => ownedLocIds.has(String(p.location_id)) && p.status === 'active')
  programs.value = mine
  // Keep a valid selection: default to the first program, clear if none.
  if (!mine.some(p => p.id === selectedProgramId.value)) {
    selectedProgramId.value = mine[0]?.id || ''
  }
}

async function load() {
  loading.value = true
  if (!ownerId.value) { loading.value = false; return }
  const { settings, username } = await loadSiteSettings()
  brand.value = {
    name: settings.brand_name || username || 'Your business',
    subdomain: String(settings.brand_subdomain || username || '').toLowerCase(),
    primary: settings.brand_primary || DEFAULT_PRIMARY,
    enabled: !!settings.brand_enabled,
    customDomain: settings.brand_custom_domain || '',
    domainActive: settings.brand_domain_status === 'active' && !!settings.brand_custom_domain,
  }
  await loadPrograms()
  loading.value = false
}
onMounted(load)
watch(ownerId, load)

const hasAddress = computed(() => !!brand.value.subdomain || brand.value.domainActive)

// Production address customers reach once the web address is live (custom domain → subdomain).
const prodBase = computed(() => brand.value.domainActive
  ? `https://${brand.value.customDomain}`
  : `https://${brandHost(brand.value.subdomain)}`)

// ── URL builders ────────────────────────────────────────────────────────────────
function qs(params) {
  return Object.entries(params)
    .filter(([, v]) => v != null && v !== '')
    .map(([k, v]) => `${k}=${encodeURIComponent(v)}`).join('&')
}
// The real address pasted into snippets.
function prodUrl(path, params = {}) { const q = qs(params); return `${prodBase.value}${path}${q ? '?' + q : ''}` }
// A live preview link that works NOW (same origin, resolves the provider by owner id).
function previewUrl(path, params = {}) {
  const origin = appBaseUrl()
  return `${origin}${path}?${qs({ bid: ownerId.value, ...params, preview: 1 })}`
}

// ── Widget catalogue ──────────────────────────────────────────────────────────────
const TYPES = [
  { id: 'full',     icon: LayoutTemplate,    title: 'Full site',       kind: 'iframe', scope: 'full',     path: '/',
    tagline: 'Your whole booking site, embedded.', restrict: 'Visitors can browse everything — venues, memberships and all.' },
  { id: 'venues',   icon: Dumbbell,          title: 'Venue booking',   kind: 'iframe', scope: 'venues',   path: '/facilities',
    tagline: 'Just venue booking.', restrict: 'Locked to venues & booking — no other pages are reachable.' },
  { id: 'programs', icon: GraduationCap,     title: 'Memberships',     kind: 'iframe', scope: 'programs', path: '/programs',
    tagline: 'Just memberships, classes & academies.', restrict: 'Locked to memberships & sign-up — no other pages are reachable.' },
  { id: 'program',  icon: BookOpen,          title: 'One membership',  kind: 'program', scope: 'programs', path: '/programs',
    tagline: 'Send visitors straight to one program.', restrict: 'Locked to this single membership — a button + iframe landing on its checkout.' },
  { id: 'account',  icon: CircleUserRound,   title: 'Customer account', kind: 'iframe', scope: 'account', path: '/account',
    tagline: 'Bookings, memberships & sign-in.', restrict: 'Customers sign in to view & manage their bookings and memberships — nothing else.' },
  { id: 'auth',     icon: LogIn,             title: 'Sign in / Sign up', kind: 'iframe', scope: 'auth',  path: '/account',
    tagline: 'A login / create-account box.', restrict: 'Just sign-in / create-account, with one Bookboll login that works across your site.' },
  { id: 'button',   icon: MousePointerClick, title: '“Book now” button', kind: 'button', scope: 'full',  path: '/',
    tagline: 'A button that opens your site.', restrict: 'Opens your booking site in a new tab.' },
  { id: 'widget',   icon: Sparkles,          title: 'Floating widget', kind: 'widget', scope: 'full',     path: '/',
    tagline: 'A launcher in the page corner.', restrict: 'A floating “Book now” button on every page; opens your site in a new tab.' },
]
const active = ref('full')
const cur = computed(() => TYPES.find(t => t.id === active.value) || TYPES[0])

// The FULL-site embed is free for everyone; the specialized widgets (scoped iframes,
// button, floating launcher) are Premium. `locked` = a Premium widget viewed on the free plan.
function isFreeWidget(t) { return t?.id === 'full' }
const locked = computed(() => !isPremium.value && !isFreeWidget(cur.value))

// Per-widget options
const frameHeight = ref(760)
const btnLabel = ref('Book now')
const widgetLabel = ref('Book now')
const widgetPos = ref('right')   // right | left
// Single-program ("One membership") widget options.
const progBtnLabel = ref('Join now')

// The chosen program (single-membership widget) + its public path (/programs/:id).
const selectedProgram = computed(() => programs.value.find(p => p.id === selectedProgramId.value) || null)
// uuid, not id — the public route resolves programs by uuid (a numeric id in an
// embed snippet pasted onto a provider's site would 404 for their visitors).
const programPath = computed(() => selectedProgram.value
  ? `/programs/${selectedProgram.value.uuid || selectedProgram.value.id}`
  : '/programs')

const TAG_OPEN = '<' + 'script>'
const TAG_CLOSE = '<' + '/script>'

function frameCode(t) {
  return `<iframe
  src="${prodUrl(t.path, { embed: t.scope })}"
  title="${t.title} — ${brand.value.name}"
  loading="lazy"
  style="width:100%;height:${frameHeight.value}px;border:0;border-radius:14px;box-shadow:0 1px 6px rgba(0,0,0,.10)">
</iframe>`
}
function buttonCode() {
  return `<a href="${prodUrl('/', { embed: 'full' })}" target="_blank" rel="noopener"
   style="display:inline-block;background:${brand.value.primary};color:#fff;
   font:700 15px/1 system-ui,sans-serif;padding:14px 24px;border-radius:10px;
   text-decoration:none">${btnLabel.value || 'Book now'}</a>`
}
function widgetCode() {
  const side = widgetPos.value === 'left' ? 'left:20px' : 'right:20px'
  return `${TAG_OPEN}
(function(){
  var a=document.createElement('a');
  a.href=${JSON.stringify(prodUrl('/', { embed: 'full' }))};
  a.target='_blank';a.rel='noopener';a.textContent=${JSON.stringify(widgetLabel.value || 'Book now')};
  a.style.cssText='position:fixed;${side};bottom:20px;z-index:99999;'
    +'background:${brand.value.primary};color:#fff;font:700 15px/1 system-ui,sans-serif;'
    +'padding:15px 24px;border-radius:999px;text-decoration:none;box-shadow:0 8px 24px rgba(0,0,0,.25)';
  document.body.appendChild(a);
})();
${TAG_CLOSE}`
}
// Single-program widget: a button that opens ONE membership's checkout, and a
// scoped iframe landing on the same page. Both reuse the shared URL + snippet logic;
// scope stays `programs` so the embedded flow is still locked to memberships only.
function programButtonCode() {
  const label = progBtnLabel.value || 'Join now'
  const title = selectedProgram.value?.name || 'Membership'
  return `<a href="${prodUrl(programPath.value, { embed: 'programs' })}" target="_blank" rel="noopener"
   title="${title} — ${brand.value.name}"
   style="display:inline-block;background:${brand.value.primary};color:#fff;
   font:700 15px/1 system-ui,sans-serif;padding:14px 24px;border-radius:10px;
   text-decoration:none">${label}</a>`
}
function programFrameCode() {
  const title = selectedProgram.value?.name || 'Membership'
  return `<iframe
  src="${prodUrl(programPath.value, { embed: 'programs' })}"
  title="${title} — ${brand.value.name}"
  loading="lazy"
  style="width:100%;height:${frameHeight.value}px;border:0;border-radius:14px;box-shadow:0 1px 6px rgba(0,0,0,.10)">
</iframe>`
}

const activeCode = computed(() => {
  const t = cur.value
  if (t.kind === 'button') return buttonCode()
  if (t.kind === 'widget') return widgetCode()
  if (t.kind === 'program') return programButtonCode()   // primary snippet for the copy button
  return frameCode(t)
})
// The single-program widget offers TWO snippets — a button and a scoped iframe.
const programIframeCode = computed(() => programFrameCode())

// Bottom preview: iframe types render their scoped flow; button/widget preview the destination.
const device = ref('desktop')
const previewSrc = computed(() => {
  const t = cur.value
  // Single-program widget previews the chosen membership, still scoped to `programs`.
  if (t.kind === 'program') return previewUrl(programPath.value, { embed: 'programs' })
  const scope = t.kind === 'iframe' ? t.scope : 'full'
  const path = t.kind === 'iframe' ? t.path : '/'
  return previewUrl(path, { embed: scope })
})

// Copy
const copied = ref(false)
let copyTimer = null
async function copyCode() {
  try {
    await navigator.clipboard.writeText(activeCode.value)
    copied.value = true
    clearTimeout(copyTimer); copyTimer = setTimeout(() => { copied.value = false }, 1800)
  } catch { /* clipboard blocked — select manually */ }
}
// Second copy button for the single-program iframe snippet (button snippet uses copyCode above).
const copiedFrame = ref(false)
let copyFrameTimer = null
async function copyFrameCode() {
  try {
    await navigator.clipboard.writeText(programIframeCode.value)
    copiedFrame.value = true
    clearTimeout(copyFrameTimer); copyFrameTimer = setTimeout(() => { copiedFrame.value = false }, 1800)
  } catch { /* clipboard blocked — select manually */ }
}
</script>

<template>
  <div class="page">
    <div class="page-header">
      <div>
        <div class="page-title">Embed &amp; Widgets</div>
        <div class="page-subtitle">Add your booking experience to your own website. The full-site embed is free; scoped widgets are Premium.</div>
      </div>
      <a v-if="hasAddress" class="btn btn-secondary" :href="previewUrl('/', { embed: 'full' })" target="_blank" rel="noopener"><ExternalLink :size="15" /> Open my site</a>
    </div>

    <div v-if="needsProvider" class="em-pick">
      <Code2 :size="34" />
      <h3>Pick a provider</h3>
      <p>Embed codes belong to a provider business. Switch to a provider with the role switcher (top bar) to get theirs.</p>
    </div>

    <div v-else-if="loading" class="em-pick"><Loader :size="22" class="spin" /> Loading…</div>

    <template v-else>
      <!-- Address + status -->
      <div class="em-bar">
        <div class="em-addr"><Globe2 :size="15" /> <span>Your address: <code>{{ prodBase }}</code></span></div>
        <span v-if="!brand.enabled" class="em-flag em-flag-warn"><AlertTriangle :size="13" /> Site is OFF — turn it on in <router-link to="/brand">My Website</router-link></span>
        <span v-else-if="!brand.domainActive" class="em-flag"><AlertTriangle :size="13" /> Goes live once your web address / DNS is connected. Previews below work today.</span>
      </div>

      <!-- Widget type tabs. The full-site embed is free; the rest carry a Premium chip on the free plan. -->
      <div class="em-tabs">
        <button v-for="t in TYPES" :key="t.id" type="button" class="em-tab" :class="{ on: active === t.id }" @click="active = t.id">
          <component :is="t.icon" :size="18" />
          <span class="em-tab-t">{{ t.title }}<span v-if="!isPremium && !isFreeWidget(t)" class="prem-lock">Premium</span></span>
          <span class="em-tab-s">{{ t.tagline }}</span>
        </button>
      </div>

      <!-- Premium widget on the free plan → inline upsell instead of the builder -->
      <div v-if="locked" class="em-pick em-upsell">
        <span class="em-upsell-ic"><Lock :size="26" /></span>
        <h3>“{{ cur.title }}” is a Premium widget</h3>
        <p>Embedding your <strong>whole booking site</strong> is free. Scoped widgets like this — plus the “Book now” button and floating launcher — come with Premium for ${{ PREMIUM_PRICE }}/month.</p>
        <div class="em-upsell-actions">
          <button class="btn btn-secondary" @click="active = 'full'"><LayoutTemplate :size="15" /> Use the free full-site embed</button>
          <button class="btn btn-primary" @click="openUpgrade('widgets')"><Sparkles :size="15" /> See what Premium includes</button>
        </div>
      </div>

      <!-- Options (left) + code (right) — hidden when a Premium widget is locked -->
      <div v-if="!locked" class="em-config">
        <div class="em-opts">
          <div class="em-opts-head"><component :is="cur.icon" :size="16" /> {{ cur.title }}</div>
          <div class="em-restrict" :class="{ open: cur.scope === 'full' }">
            <component :is="cur.scope === 'full' ? Globe2 : Lock" :size="14" />
            <span>{{ cur.restrict }}</span>
          </div>

          <!-- single-program picker + button text + iframe height -->
          <template v-if="cur.kind === 'program'">
            <template v-if="programs.length">
              <label class="em-field">
                <span class="em-field-l">Membership</span>
                <select class="em-input" v-model="selectedProgramId">
                  <option v-for="p in programs" :key="p.id" :value="p.id">{{ p.name || 'Untitled membership' }}</option>
                </select>
              </label>
              <label class="em-field">
                <span class="em-field-l">Button text</span>
                <input class="em-input" v-model="progBtnLabel" maxlength="28" placeholder="Join now" />
              </label>
              <div class="em-demo"><a class="em-demo-btn" :style="{ background: brand.primary }">{{ progBtnLabel || 'Join now' }}</a></div>
              <label class="em-field">
                <span class="em-field-l">Iframe height <em>{{ frameHeight }}px</em></span>
                <input type="range" min="420" max="1100" step="20" v-model.number="frameHeight" />
              </label>
              <div class="em-hint">Drop the button anywhere, or embed the iframe to show this one membership’s checkout in-page. Both stay locked to this program.</div>
            </template>
            <div v-else class="em-hint em-empty">You have no live memberships yet. Create one under <router-link to="/memberships">Memberships</router-link>, then it’ll appear here.</div>
          </template>

          <!-- iframe height -->
          <template v-else-if="cur.kind === 'iframe'">
            <label class="em-field">
              <span class="em-field-l">Height <em>{{ frameHeight }}px</em></span>
              <input type="range" min="420" max="1100" step="20" v-model.number="frameHeight" />
            </label>
            <div class="em-hint">Set this to fit the space on your page. The widget scrolls inside if your content is taller.</div>
          </template>

          <!-- button label -->
          <template v-else-if="cur.kind === 'button'">
            <label class="em-field">
              <span class="em-field-l">Button text</span>
              <input class="em-input" v-model="btnLabel" maxlength="28" placeholder="Book now" />
            </label>
            <div class="em-demo"><a class="em-demo-btn" :style="{ background: brand.primary }">{{ btnLabel || 'Book now' }}</a></div>
          </template>

          <!-- widget label + position -->
          <template v-else>
            <label class="em-field">
              <span class="em-field-l">Button text</span>
              <input class="em-input" v-model="widgetLabel" maxlength="28" placeholder="Book now" />
            </label>
            <label class="em-field">
              <span class="em-field-l">Corner</span>
              <div class="em-seg">
                <button type="button" :class="{ on: widgetPos === 'left' }" @click="widgetPos = 'left'">Bottom-left</button>
                <button type="button" :class="{ on: widgetPos === 'right' }" @click="widgetPos = 'right'">Bottom-right</button>
              </div>
            </label>
            <div class="em-demo em-demo-corner" :class="widgetPos"><a class="em-demo-pill" :style="{ background: brand.primary }">{{ widgetLabel || 'Book now' }}</a></div>
          </template>
        </div>

        <!-- single-program widget: two snippets (button + scoped iframe), each with its own copy -->
        <div v-if="cur.kind === 'program'" class="em-codecol">
          <template v-if="programs.length">
            <div class="em-code-head">
              <span>Button — opens this membership in a new tab</span>
              <button class="btn btn-primary em-copy" @click="copyCode">
                <Check v-if="copied" :size="15" /><Copy v-else :size="15" /> {{ copied ? 'Copied' : 'Copy code' }}
              </button>
            </div>
            <pre class="em-code"><code>{{ activeCode }}</code></pre>
            <div class="em-code-head" style="margin-top: 14px;">
              <span>Iframe — embeds this membership’s checkout in-page</span>
              <button class="btn btn-primary em-copy" @click="copyFrameCode">
                <Check v-if="copiedFrame" :size="15" /><Copy v-else :size="15" /> {{ copiedFrame ? 'Copied' : 'Copy code' }}
              </button>
            </div>
            <pre class="em-code"><code>{{ programIframeCode }}</code></pre>
          </template>
          <div v-else class="em-hint">Add a live membership to generate its embed codes.</div>
        </div>

        <div v-else class="em-codecol">
          <div class="em-code-head">
            <span>Paste this into your site’s HTML</span>
            <button class="btn btn-primary em-copy" @click="copyCode">
              <Check v-if="copied" :size="15" /><Copy v-else :size="15" /> {{ copied ? 'Copied' : 'Copy code' }}
            </button>
          </div>
          <pre class="em-code"><code>{{ activeCode }}</code></pre>
        </div>
      </div>

      <!-- Large live preview -->
      <div v-if="!locked" class="em-preview">
        <div class="em-preview-head">
          <span class="em-preview-label"><component :is="cur.icon" :size="14" /> Live preview — {{ cur.title }}</span>
          <div class="em-preview-tools">
            <div class="em-device">
              <button :class="{ on: device === 'desktop' }" @click="device = 'desktop'" title="Desktop"><Monitor :size="14" /></button>
              <button :class="{ on: device === 'mobile' }" @click="device = 'mobile'" title="Mobile"><Smartphone :size="14" /></button>
            </div>
            <a class="em-open" :href="previewSrc" target="_blank" rel="noopener"><ExternalLink :size="13" /> Open</a>
          </div>
        </div>
        <div class="em-frame-wrap" :class="device">
          <iframe :key="previewSrc" :src="previewSrc" class="em-frame" title="Embed preview" loading="lazy"></iframe>
        </div>
        <div class="em-preview-note">
          <template v-if="cur.kind === 'iframe'">This is exactly what your embedded widget shows{{ cur.scope !== 'full' ? ' — locked to this flow only.' : '.' }}</template>
          <template v-else-if="cur.kind === 'program'">This is where your button + iframe land — this one membership’s checkout, locked to it.</template>
          <template v-else>This is the site your {{ cur.kind }} opens in a new tab.</template>
        </div>
      </div>

      <!-- No address yet -->
      <div v-if="!hasAddress" class="em-note">
        <AlertTriangle :size="15" />
        <span>Set up your free web address in <router-link to="/brand">My Website</router-link> to publish these embeds.</span>
      </div>
    </template>
  </div>
</template>

<style scoped>
.spin { animation: emspin 1s linear infinite; } @keyframes emspin { to { transform: rotate(360deg); } }
.em-pick { display: flex; flex-direction: column; align-items: center; gap: 10px; text-align: center; padding: 60px 24px; color: var(--text-muted); background: var(--surface, #ffffff); border: 1px solid var(--border); border-radius: 16px; }
.em-upsell-ic { width: 56px; height: 56px; border-radius: 16px; background: linear-gradient(135deg, #fbbf24, #f59e0b); color: #fff; display: inline-flex; align-items: center; justify-content: center; }
.em-upsell-actions { display: flex; gap: 10px; margin-top: 6px; flex-wrap: wrap; justify-content: center; }
.em-pick h3 { margin: 4px 0 0; color: var(--text); font-size: 17px; }
.em-pick p { max-width: 440px; margin: 0; font-size: 13.5px; line-height: 1.5; }

/* address + status bar */
.em-bar { display: flex; align-items: center; flex-wrap: wrap; gap: 10px 16px; background: var(--surface, #ffffff); border: 1px solid var(--border); border-radius: 12px; padding: 10px 14px; margin-bottom: 14px; }
.em-addr { display: flex; align-items: center; gap: 8px; font-size: 13.5px; color: var(--text); }
.em-addr code { background: #f1f5f9; padding: 1px 7px; border-radius: 6px; font-size: 12.5px; }
.em-flag { display: inline-flex; align-items: center; gap: 6px; font-size: 12.3px; color: var(--text-muted); }
.em-flag a { color: var(--primary); font-weight: 700; }
.em-flag-warn { color: #92400e; }

/* tabs */
.em-tabs { display: grid; grid-template-columns: repeat(auto-fill, minmax(155px, 1fr)); gap: 10px; margin-bottom: 16px; }
.em-tab { display: flex; flex-direction: column; align-items: flex-start; gap: 4px; text-align: left; padding: 13px 14px; border: 1.5px solid var(--border); background: var(--surface, #ffffff); border-radius: 14px; cursor: pointer; transition: border-color .12s, box-shadow .12s, transform .12s; }
.em-tab:hover { border-color: var(--primary); transform: translateY(-1px); }
.em-tab.on { border-color: var(--primary); box-shadow: 0 0 0 3px color-mix(in srgb, var(--primary) 16%, transparent); }
.em-tab > svg { color: var(--primary); }
.em-tab-t { font-size: 13.5px; font-weight: 800; color: var(--text); }
.em-tab-s { font-size: 11.5px; color: var(--text-muted); line-height: 1.35; }

/* config: options | code side by side */
.em-config { display: grid; grid-template-columns: 320px 1fr; gap: 16px; margin-bottom: 16px; align-items: start; }
.em-opts { background: var(--surface, #ffffff); border: 1px solid var(--border); border-radius: 14px; padding: 16px; }
.em-opts-head { display: flex; align-items: center; gap: 8px; font-size: 14px; font-weight: 800; color: var(--text); }
.em-opts-head svg { color: var(--primary); }
.em-restrict { display: flex; align-items: flex-start; gap: 8px; margin: 12px 0 4px; padding: 10px 12px; border-radius: 10px; font-size: 12.3px; line-height: 1.45; background: #eff6ff; color: #1e40af; }
.em-restrict.open { background: #f0fdf4; color: #166534; }
.em-restrict svg { flex-shrink: 0; margin-top: 1px; }
.em-field { display: block; margin-top: 14px; }
.em-field-l { display: flex; justify-content: space-between; font-size: 12.5px; font-weight: 700; color: var(--text-muted); margin-bottom: 6px; }
.em-field-l em { font-style: normal; color: var(--primary); font-weight: 800; }
.em-input { width: 100%; height: 38px; padding: 0 12px; border: 1px solid var(--border); border-radius: 9px; font-size: 14px; color: var(--text); box-sizing: border-box; }
.em-input:focus { outline: none; border-color: var(--primary); }
select.em-input { background: var(--surface, #ffffff); cursor: pointer; appearance: auto; }
.em-field input[type=range] { width: 100%; accent-color: var(--primary); }
.em-hint { font-size: 11.8px; color: var(--text-muted); margin-top: 8px; line-height: 1.45; }
.em-hint.em-empty { margin-top: 14px; }
.em-hint a { color: var(--primary); font-weight: 700; }
.em-seg { display: inline-flex; border: 1px solid var(--border); border-radius: 9px; overflow: hidden; }
.em-seg button { padding: 8px 12px; font-size: 12.5px; font-weight: 700; background: var(--surface, #ffffff); color: var(--text-muted); border: none; cursor: pointer; }
.em-seg button.on { background: var(--primary); color: #fff; }
.em-demo { margin-top: 14px; padding: 18px; border: 1px dashed var(--border); border-radius: 10px; background: #f8fafc; display: flex; justify-content: center; position: relative; min-height: 64px; align-items: center; }
.em-demo-btn { color: #fff; font-weight: 700; font-size: 14px; padding: 12px 22px; border-radius: 10px; }
.em-demo-corner { min-height: 90px; }
.em-demo-pill { position: absolute; bottom: 12px; color: #fff; font-weight: 700; font-size: 13px; padding: 11px 18px; border-radius: 999px; box-shadow: 0 6px 16px rgba(0,0,0,.2); }
.em-demo-corner.left .em-demo-pill { left: 14px; }
.em-demo-corner.right .em-demo-pill { right: 14px; }

.em-codecol { min-width: 0; }
.em-code-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; font-size: 12.5px; font-weight: 700; color: var(--text-muted); margin-bottom: 8px; }
.em-copy { padding: 8px 14px; }
.em-code { margin: 0; background: #0f172a; color: var(--border, #e2e8f0); border-radius: 12px; padding: 14px 16px; overflow-x: auto; font-size: 12.3px; line-height: 1.55; max-height: 230px; }
.em-code code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; white-space: pre; }

/* big bottom preview */
.em-preview { background: var(--surface, #ffffff); border: 1px solid var(--border); border-radius: 16px; padding: 14px; }
.em-preview-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 10px; flex-wrap: wrap; }
.em-preview-label { display: inline-flex; align-items: center; gap: 7px; font-size: 13px; font-weight: 800; color: var(--text); }
.em-preview-label svg { color: var(--primary); }
.em-preview-tools { display: flex; align-items: center; gap: 8px; }
.em-device { display: inline-flex; border: 1px solid var(--border); border-radius: 8px; overflow: hidden; }
.em-device button { width: 34px; height: 32px; display: flex; align-items: center; justify-content: center; background: var(--surface, #ffffff); color: var(--text-muted); border: none; cursor: pointer; }
.em-device button.on { background: var(--primary); color: #fff; }
.em-open { display: inline-flex; align-items: center; gap: 5px; font-size: 12.5px; font-weight: 700; color: var(--text-muted); text-decoration: none; }
.em-open:hover { color: var(--primary); }
.em-frame-wrap { background: #f1f5f9; border: 1px solid var(--border); border-radius: 12px; padding: 12px; display: flex; justify-content: center; }
.em-frame-wrap.desktop .em-frame { width: 100%; height: 680px; }
.em-frame-wrap.mobile .em-frame { width: 390px; max-width: 100%; height: 680px; border-radius: 22px; box-shadow: 0 6px 24px rgba(0,0,0,.14); }
.em-frame { border: 1px solid var(--border); border-radius: 10px; background: var(--surface, #ffffff); }
.em-preview-note { text-align: center; font-size: 12px; color: var(--text-muted); margin-top: 10px; }

.em-note { display: flex; gap: 9px; align-items: flex-start; font-size: 12.8px; line-height: 1.5; color: #92400e; background: #fffbeb; border: 1px solid #fde68a; border-radius: 12px; padding: 10px 13px; margin-top: 14px; }
.em-note :deep(svg) { flex-shrink: 0; margin-top: 1px; }
.em-note a { color: #92400e; font-weight: 800; }

@media (max-width: 980px) {
  .em-config { grid-template-columns: 1fr; }
}

/* phones (360px-first) */
@media (max-width: 600px) {
  /* iOS zoom + tap target */
  .em-input { font-size: 16px; height: 44px; }
  /* address bar: long domains must break, never push the body sideways */
  .em-addr { min-width: 0; flex-wrap: wrap; }
  .em-addr code { word-break: break-all; overflow-wrap: anywhere; }
  /* comfortable touch targets on segmented controls */
  .em-seg button { padding: 12px 14px; min-height: 44px; }
  .em-device button { width: 44px; height: 40px; }
  /* upsell actions: stacked full-width buttons */
  .em-upsell-actions { flex-direction: column; width: 100%; }
  .em-upsell-actions .btn { width: 100%; justify-content: center; min-height: 44px; }
  /* preview shouldn't eat two viewport heights */
  .em-frame-wrap { padding: 8px; }
  .em-frame-wrap.desktop .em-frame,
  .em-frame-wrap.mobile .em-frame { height: 520px; }
  /* tab cards: steady 2-col between 344-600px */
  .em-tabs { grid-template-columns: 1fr 1fr; gap: 8px; }
  /* code header: let the Copy button wrap under the label when tight */
  .em-code-head { flex-wrap: wrap; }
}
@media (max-width: 380px) {
  .em-tabs { grid-template-columns: 1fr; }
}
</style>
