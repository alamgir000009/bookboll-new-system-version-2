<script setup>
/**
 * Banners.vue — "My Website → Home banners". A dedicated editor for the home-page
 * hero slideshow, split out of the (busy) Site page.
 *
 * Super admin (no provider context) edits the Bookboll marketplace banners, stored in
 * platform_settings under the `hero_banners` key. A provider edits their own, stored on
 * their admin_users.hero_banners. Read live by the storefront (lib/brand.js → HeroSlideshow).
 */
import { ref, computed, onMounted, watch } from 'vue'
import { loadSiteSettings, saveSiteSettings } from '../lib/siteSettings.js'
import { loadReferenceData } from '../lib/referenceData.js'
import { fetchSubscriptionsDashboard } from '../lib/subscriptions.js'
import { fetchEventModerationDashboard } from '../lib/moderationEvents.js'
import { useCurrentUser } from '../lib/currentUser.js'
import { usePremium } from '../lib/premium.js'
import { useToast } from '../lib/toast.js'
import { uploadImage, imageUploadConfigured } from '../lib/imageUpload.js'
import { BANNER_ACTIONS, BANNER_FONTS, BANNER_TOKENS, LISTING_ACTION_PATH, applyTokens, blankBanner, normalizeBanners, bannerButtonText, defaultBanners, bannerButtons, bannerBgStyle } from '../lib/heroBanners.js'
import { selectedCity, selectedCountry, customer } from '../lib/customerSession.js'
import { sanitizeHtml } from '../lib/blog.js'
import MultiSelectDropdown from '../components/MultiSelectDropdown.vue'
import { Images, Plus, Trash2, ChevronUp, ChevronDown, ChevronRight, Upload, Image as ImageIcon, Loader, Eye, Hash, MapPin, Award } from 'lucide-vue-next'

const { currentOwnerUserId, isSuperAdmin, role: actorRole } = useCurrentUser()
const toast = useToast()

const ownerId = computed(() => currentOwnerUserId.value)
const isPlatformMode = computed(() => (isSuperAdmin.value || actorRole.value === 'platform_staff') && !ownerId.value)
const { isPremium, requirePremium } = usePremium()   // custom banners are a Premium feature for providers

const loading = ref(true)
const brandNameRef = ref('')
const saving = ref(false)
const banners = ref([])
const usingDefaults = ref(false)   // true when showing the built-in default (nothing saved yet)
// Button actions: the super-admin marketplace gets the full set; a provider's own site
// only offers the actions relevant to it (no events, loyalty/rewards or marketplace
// directory pages — those don't exist on a white-label site).
// Deep-link targets a banner button can point to (specific venue / membership / event).
// Provider mode = only THEIR listings; platform mode = the whole marketplace.
const venueOpts = ref([])     // [{ value, label }]
const programOpts = ref([])
const eventOpts = ref([])
function targetOptionsFor(action) {
  return action === 'venue' ? venueOpts.value : action === 'program' ? programOpts.value : action === 'event' ? eventOpts.value : []
}
const bannerActions = computed(() => {
  if (isPlatformMode.value) return BANNER_ACTIONS   // marketplace: full set
  // A provider's own site: relevant actions only (no events/rewards/marketplace directories),
  // and the specific-venue / specific-membership deep-links only when they have that content.
  const keys = new Set(['none', 'signin', 'book_venue', 'get_membership', 'blog', 'custom'])
  if (venueOpts.value.length) keys.add('venue')
  if (programOpts.value.length) keys.add('program')
  return BANNER_ACTIONS.filter(a => keys.has(a.key))
})
// Keep a banner valid if its chosen target action loses all options (or the action changes).
function onActionChange(b) { if (!LISTING_ACTION_PATH[b.action]) b.target = '' }
// Multi-CTA: `actions` drives the buttons; keep legacy `action` = first entry
// and clean up config the removed actions no longer need.
const actionOptions = computed(() => bannerActions.value
  .filter(a => a.key !== 'none')
  .map(a => ({ value: a.key, label: a.label })))
function setActions(b, keys) {
  b.actions = keys
  b.action = keys[0] || 'none'
  if (!keys.some(k => LISTING_ACTION_PATH[k])) b.target = ''
  if (!keys.includes('custom')) b.link = ''
}
// The (single) deep-link action currently selected, if any — one target field serves it.
function deepLinkAction(b) { return (b.actions || []).find(k => LISTING_ACTION_PATH[k]) || '' }
const audienceOptions = [{ k: 'all', label: 'Everyone' }, { k: 'out', label: 'Signed-out only' }, { k: 'in', label: 'Signed-in only' }]
const bannerImgInputs = ref({})
const bannerUploading = ref('')
const bannerIconInputs = ref({})
const bannerIconUploading = ref('')

// Geo targeting — countries + cities a banner may be limited to (empty = everywhere).
const countries = ref([])
const cities = ref([])
const countryOptions = computed(() => countries.value.map(c => ({ value: c.id, label: c.name })))
/** Cities offered for a banner: only those in the banner's selected countries (all if none chosen). */
function cityOptionsFor(b) {
  const sel = b.countries || []
  const list = sel.length ? cities.value.filter(c => sel.includes(c.country_id)) : cities.value
  return list.map(c => ({ value: c.id, label: c.name }))
}
/** Set a banner's countries, pruning any selected cities that no longer belong to a chosen country. */
function setCountries(b, ids) {
  b.countries = ids
  if (ids.length) {
    const allowed = new Set(cities.value.filter(c => ids.includes(c.country_id)).map(c => c.id))
    b.cities = (b.cities || []).filter(id => allowed.has(id))
  }
}

async function load() {
  loading.value = true
  // Countries + cities for the geo-targeting dropdowns (load once alongside the banners).
  if (!countries.value.length) {
    const ref = await loadReferenceData()
    countries.value = ref.countries
    cities.value = ref.cities
  }
  // The API returns the marketplace's banners for a platform admin, or the
  // provider's own — same endpoint either way.
  const { settings, username } = await loadSiteSettings()
  const saved = normalizeBanners(settings.hero_banners)
  let brandName = ''
  if (!isPlatformMode.value) {
    brandName = settings.brand_name || username || ''
    brandNameRef.value = brandName
  }
  // Marketplace with nothing saved → start from the editable default. Provider
  // sites start EMPTY: their default hero lives in site settings (My Website →
  // Logo & name), not here — banners added here are extra slides after it.
  if (saved.length) { banners.value = saved; usingDefaults.value = false }
  else if (isPlatformMode.value) { banners.value = defaultBanners({ platform: true, brandName }); usingDefaults.value = true }
  else { banners.value = []; usingDefaults.value = false }
  await loadTargets()
  loading.value = false
}
onMounted(load)
watch(ownerId, load)

// Deep-link target options: a provider sees only THEIR venues/programs; the marketplace
// (platform mode) sees everything. Events are marketplace-only (no events on provider sites).
async function loadTargets() {
  venueOpts.value = []; programOpts.value = []; eventOpts.value = []
  try {
    // Both sources are already scoped by the API: a platform admin gets
    // everything, a provider only what their own business owns.
    const [ref, dash] = await Promise.all([loadReferenceData(), fetchSubscriptionsDashboard()])
    venueOpts.value = ref.listings.map(l => ({ value: String(l.id), label: l.name }))
    programOpts.value = (dash.success ? (dash.data?.programs || []) : [])
      .map(p => ({ value: String(p.id), label: p.name }))
    if (isPlatformMode.value) {
      // Events are a marketplace-only mechanic, so providers get none.
      const ev = await fetchEventModerationDashboard()
      eventOpts.value = (ev.success ? (ev.data?.games || []) : [])
        .map(g => ({ value: String(g.id), label: g.title || g.name || 'Event' }))
    }
  } catch { /* best-effort — actions just won't list specific targets */ }
}

// Each banner is collapsed to a compact summary row by default; click to expand & edit.
const expandedIds = ref(new Set())
const isExpanded = (b) => expandedIds.value.has(b.id)
function toggleExpand(b) {
  const s = new Set(expandedIds.value)
  s.has(b.id) ? s.delete(b.id) : s.add(b.id)
  expandedIds.value = s
}
function addBanner() {
  const b = blankBanner()
  banners.value = [...banners.value, b]
  expandedIds.value = new Set([...expandedIds.value, b.id])   // open the new one for editing
}
function removeBanner(i) { banners.value = banners.value.filter((_, idx) => idx !== i) }
function moveBanner(i, dir) {
  const j = i + dir
  if (j < 0 || j >= banners.value.length) return
  const arr = [...banners.value];[arr[i], arr[j]] = [arr[j], arr[i]]; banners.value = arr
}
async function onBannerImage(e, b) {
  const file = e.target.files?.[0]; if (!file) return
  bannerUploading.value = b.id
  const r = await uploadImage(file)
  bannerUploading.value = ''
  if (r.error) { toast.error(r.error); return }
  b.image = r.url
}
async function onBannerIcon(e, b) {
  const file = e.target.files?.[0]; if (!file) return
  bannerIconUploading.value = b.id
  const r = await uploadImage(file)
  bannerIconUploading.value = ''
  if (r.error) { toast.error(r.error); return }
  b.icon = r.url
}
function actionPlaceholder(b) { return bannerButtonText({ ...b, button_text: '' }) || 'Button text' }

// ── Live preview helpers ──
const bannerFonts = BANNER_FONTS
const bannerTokens = BANNER_TOKENS
const tokCtx = computed(() => ({
  city: selectedCity.value?.name,
  country: selectedCountry.value?.name,
  name: (customer.value?.full_name || '').split(' ')[0] || '',
}))
function tok(s) { return applyTokens(s, tokCtx.value) }
function subHtml(b) { return b?.subheading ? sanitizeHtml(applyTokens(b.subheading, tokCtx.value)) : '' }
// The description used to be a rich-text editor, so old banners may hold HTML.
// The plain textarea shows just the text; the stored HTML survives untouched
// until the user actually edits the field (then it becomes plain text).
function plainSub(html) {
  const s = String(html || '')
  if (!/[<>]/.test(s)) return s
  const div = document.createElement('div')
  div.innerHTML = s
  return (div.textContent || '').trim()
}
function previewBg(b) { return bannerBgStyle(b) }
// Preset text colours (last chip = rainbow custom picker, like the listing form).
const BANNER_COLORS = ['#ffffff', '#0f172a', '#fde047', '#34d399', '#60a5fa', '#f472b6', '#f97316']
const sameColor = (a, c) => String(a || '').toLowerCase() === String(c || '').toLowerCase()
const isPresetColor = (v) => BANNER_COLORS.some(c => sameColor(v, c))
function previewBtnStyle(b) { return b.button_color ? { background: b.button_color, color: '#fff' } : {} }
function previewHasButton(b) { return bannerButtons(b).length > 0 }
const AUD_LABEL = { all: 'Everyone', in: 'Signed-in', out: 'Signed-out' }

async function save() {
  saving.value = true
  const clean = normalizeBanners(banners.value)
  const res = await saveSiteSettings({ hero_banners: clean })
  saving.value = false
  if (!res.success) { toast.error(res.message || 'Could not save.'); return }
  if (isPlatformMode.value) { toast.success('Marketplace banners saved.'); return }
  toast.success('Home banners saved.')
}
</script>

<template>
  <div class="page">
    <div class="page-header">
      <div>
        <div class="page-title">Home banners</div>
        <div class="page-subtitle">{{ isPlatformMode ? 'The slideshow at the top of the Bookboll marketplace home page.' : 'Extra slides for your homepage hero. Your default hero (logo, background image, name & tagline) is set in My Website → Logo & name — banners added here rotate after it.' }}</div>
      </div>
      <button class="wl-btn wl-btn-primary" :disabled="saving || loading" @click="save">
        <Loader v-if="saving" :size="15" class="spin" /> {{ saving ? 'Saving…' : 'Save banners' }}
      </button>
    </div>

    <!-- Non-provider staff with no provider context -->
    <div v-if="!ownerId && !isPlatformMode" class="bn-page-empty">
      <Images :size="34" />
      <h3>Pick a provider</h3>
      <p>Home banners belong to a provider business. Switch to a provider with the role switcher (top bar) to edit theirs.</p>
    </div>

    <div v-else-if="loading" class="bn-page-empty"><Loader :size="22" class="spin" /> Loading…</div>

    <div v-else class="bn-layout">
     <div class="bn-wrap">
      <div class="wl-card bn-help">
        <div class="wl-card-title"><Images :size="14" style="vertical-align:-2px" /> How it works</div>
        <div class="wl-card-sub">Add one or more banners. Each rotates on a slideshow. Choose <strong>who sees each</strong> (everyone / signed-in / signed-out), give it an image or colour, and an optional button. Use <code>{city}</code> in the text and it’s swapped for the visitor’s city. With no banners, the default welcome banner shows.</div>
      </div>

      <!-- System loyalty slide — shown automatically, first, to signed-in customers. Not editable. -->
      <div v-if="isPlatformMode" class="bn-sys">
        <div class="bn-sys-badge"><Award :size="20" /></div>
        <div class="bn-sys-body">
          <div class="bn-sys-title">Rewards banner <span class="bn-sys-tag">Automatic · first slide</span></div>
          <div class="bn-sys-sub">Signed-in customers see their loyalty card (“Hey {name}! You’re a Rookie… My Rewards”) as the first slide, before your banners below. It’s generated per customer, so it isn’t editable here.</div>
        </div>
      </div>

      <div v-if="usingDefaults" class="bn-defaults-note">Showing the current <strong>default</strong> banner — edit it and click <strong>Save banners</strong> to customize, or add more.</div>
      <div v-else-if="!banners.length" class="bn-empty">No banners yet — the default welcome banner shows. Add one to customize.</div>

      <!-- Add-at-top button (mirrors the one at the bottom) -->
      <div v-if="banners.length" class="bn-actions bn-actions-top">
        <button class="wl-btn wl-btn-soft" @click="requirePremium('banners') && addBanner()"><Plus :size="15" /> Add banner<span v-if="!isPremium" class="prem-lock">Premium</span></button>
      </div>

      <div v-for="(b, i) in banners" :key="b.id" class="bn-card" :class="{ collapsed: !isExpanded(b) }">
        <div class="bn-head" @click="toggleExpand(b)">
          <button type="button" class="bn-caret" :title="isExpanded(b) ? 'Collapse' : 'Expand to edit'">
            <ChevronDown v-if="isExpanded(b)" :size="16" /><ChevronRight v-else :size="16" />
          </button>
          <span class="bn-num">Banner {{ i + 1 }}</span>
          <span v-if="!isExpanded(b)" class="bn-head-summary">
            <span class="bn-sum-swatch" :style="previewBg(b)"></span>
            <span class="bn-sum-title">{{ tok(b.heading) || 'Untitled banner' }}</span>
            <span class="bn-sum-aud">{{ AUD_LABEL[b.audience] }}</span>
          </span>
          <div class="bn-head-actions" @click.stop>
            <button class="wl-icon-btn" :disabled="i === 0" @click="moveBanner(i, -1)" title="Move up"><ChevronUp :size="15" /></button>
            <button class="wl-icon-btn" :disabled="i === banners.length - 1" @click="moveBanner(i, 1)" title="Move down"><ChevronDown :size="15" /></button>
            <button class="wl-icon-btn bn-del" @click="removeBanner(i)" title="Delete"><Trash2 :size="15" /></button>
          </div>
        </div>

        <div v-show="isExpanded(b)" class="bn-body">
        <!-- Live preview -->
        <div class="wl-label bn-prev-label"><Eye :size="13" /> Preview</div>
        <div class="bn-prev" :class="{ plain: !b.image && !b.bg }" :style="previewBg(b)">
          <div v-if="b.image" class="bn-prev-scrim"></div>
          <span class="bn-prev-tag">{{ AUD_LABEL[b.audience] }}</span>
          <div class="bn-prev-content" :class="{ center: b.layout === 'center' }">
            <div class="bn-prev-lead">
              <img v-if="b.icon" :src="b.icon" class="bn-prev-icon" alt="" />
              <span v-else-if="b.show_logo" class="bn-prev-logo">{{ (brandNameRef || 'BB').trim().split(' ').map(w => w[0]).slice(0, 2).join('').toUpperCase() }}</span>
              <div class="bn-prev-body" :style="{ color: b.text_color || '#fff', fontFamily: b.font || undefined }">
                <div class="bn-prev-h1" :style="{ color: b.heading_color || b.text_color || '#fff', fontFamily: b.heading_font || b.font || undefined }">{{ tok(b.heading) || 'Your heading' }}</div>
                <div v-if="subHtml(b)" class="bn-prev-sub" v-html="subHtml(b)"></div>
              </div>
            </div>
            <div v-if="previewHasButton(b)" class="bn-prev-btns">
              <span v-for="(btn, bi) in bannerButtons(b)" :key="btn.key" class="bn-prev-btn" :class="{ ghost: bi > 0 }" :style="bi === 0 ? previewBtnStyle(b) : undefined">{{ bi === 0 && b.button_text ? b.button_text : btn.text }}</span>
            </div>
          </div>
        </div>

        <!-- 1 · WORDS -->
        <label class="wl-field"><span class="wl-label">Heading</span>
          <input class="wl-input" v-model="b.heading" maxlength="90" placeholder="Play more. Hangout. Near you." /></label>
        <label class="wl-field"><span class="wl-label">Description <em>(optional)</em></span>
          <textarea class="wl-input bn-subarea" rows="2" maxlength="180" :value="plainSub(b.subheading)"
            @input="b.subheading = $event.target.value" placeholder="Book courts, join games and find classes in {city}."></textarea>
        </label>

        <!-- 2 · LOOK -->
        <div class="wl-field">
          <span class="wl-label">Text layout</span>
          <div class="bn-pills">
            <button type="button" class="bn-pill" :class="{ on: b.layout !== 'center' }" @click="b.layout = 'left'">Left</button>
            <button type="button" class="bn-pill" :class="{ on: b.layout === 'center' }" @click="b.layout = 'center'">Centered</button>
          </div>
        </div>
        <div class="wl-field">
          <span class="wl-label">Heading colour &amp; font</span>
          <div class="bn-colorrow">
            <button v-for="c in BANNER_COLORS" :key="c" type="button" class="bn-csw" :class="{ on: sameColor(b.heading_color, c) }" :style="{ background: c }" :title="c" @click="b.heading_color = c"></button>
            <label class="bn-csw bn-csw-custom" :class="{ on: b.heading_color && !isPresetColor(b.heading_color) }" title="Custom colour">
              <input type="color" :value="b.heading_color || '#ffffff'" @input="b.heading_color = $event.target.value" />
            </label>
            <button v-if="b.heading_color" type="button" class="bn-hs-reset" @click="b.heading_color = ''" title="Use the description colour">Reset</button>
          </div>
          <select class="wl-input bn-hs-font" v-model="b.heading_font" :style="{ fontFamily: b.heading_font || b.font || undefined }">
            <option value="">Font: same as description</option>
            <option v-for="ff in bannerFonts" :key="ff.label" :value="ff.key">{{ ff.label }}</option>
          </select>
        </div>
        <div class="wl-field">
          <span class="wl-label">Description colour &amp; font</span>
          <div class="bn-colorrow">
            <button v-for="c in BANNER_COLORS" :key="c" type="button" class="bn-csw" :class="{ on: sameColor(b.text_color || '#ffffff', c) }" :style="{ background: c }" :title="c" @click="b.text_color = c"></button>
            <label class="bn-csw bn-csw-custom" :class="{ on: b.text_color && !isPresetColor(b.text_color) }" title="Custom colour">
              <input type="color" :value="b.text_color || '#ffffff'" @input="b.text_color = $event.target.value" />
            </label>
            <button v-if="b.text_color && !sameColor(b.text_color, '#ffffff')" type="button" class="bn-hs-reset" @click="b.text_color = '#ffffff'" title="Back to white">Reset</button>
          </div>
          <select class="wl-input bn-hs-font" v-model="b.font" :style="{ fontFamily: b.font || undefined }">
            <option v-for="ff in bannerFonts" :key="ff.label" :value="ff.key">{{ ff.label }}</option>
          </select>
        </div>

        <div class="wl-field">
          <span class="wl-label">Background <em v-if="b.image">(the colour tints the image as an overlay)</em></span>
          <div class="bn-bg">
            <div class="bn-img" :style="previewBg(b)">
              <span v-if="!b.image && !b.bg" class="bn-img-ph"><ImageIcon :size="18" /></span>
            </div>
            <div class="bn-bg-controls">
              <input :ref="el => (bannerImgInputs[b.id] = el)" type="file" accept="image/*" hidden @change="e => onBannerImage(e, b)" />
              <button class="wl-btn wl-btn-soft" :disabled="bannerUploading === b.id" @click="bannerImgInputs[b.id]?.click()">
                <Loader v-if="bannerUploading === b.id" :size="14" class="spin" /><Upload v-else :size="14" />
                {{ b.image ? 'Replace image' : 'Upload image' }}
              </button>
              <button v-if="b.image" class="wl-btn wl-btn-ghost" @click="b.image = ''">Remove</button>
              <span class="bn-or">{{ b.image ? 'overlay colour' : 'or colour' }}</span>
              <input type="color" class="wl-swatch" v-model="b.bg" title="Colour" />
              <button v-if="b.bg" class="wl-btn wl-btn-ghost" @click="b.bg = ''">Clear</button>
            </div>
          </div>
          <div v-if="!imageUploadConfigured()" class="bn-warn">Image upload isn’t configured (VITE_IMGBB_KEY) — use a colour, or add the key.</div>
        </div>

        <div class="wl-field">
          <span class="wl-label">Icon <em>(optional · shown beside the title — e.g. your logo)</em></span>
          <div class="bn-bg">
            <div class="bn-icon-prev" :style="b.icon ? { backgroundImage: `url(${b.icon})` } : {}">
              <span v-if="!b.icon" class="bn-img-ph"><ImageIcon :size="16" /></span>
            </div>
            <div class="bn-bg-controls">
              <input :ref="el => (bannerIconInputs[b.id] = el)" type="file" accept="image/*" hidden @change="e => onBannerIcon(e, b)" />
              <button class="wl-btn wl-btn-soft" :disabled="bannerIconUploading === b.id" @click="bannerIconInputs[b.id]?.click()">
                <Loader v-if="bannerIconUploading === b.id" :size="14" class="spin" /><Upload v-else :size="14" />
                {{ b.icon ? 'Replace icon' : 'Upload icon' }}
              </button>
              <button v-if="b.icon" class="wl-btn wl-btn-ghost" @click="b.icon = ''">Remove</button>
            </div>
          </div>
        </div>

        <div class="bn-row2">
          <label class="wl-field"><span class="wl-label">Buttons <span class="opt">pick one or more — first is the primary</span></span>
            <MultiSelectDropdown :model-value="b.actions" :options="actionOptions" all-label="No buttons"
              @update:model-value="keys => setActions(b, keys)" />
          </label>
          <label v-if="b.actions.length" class="wl-field"><span class="wl-label">1st button text</span>
            <input class="wl-input" v-model="b.button_text" :placeholder="actionPlaceholder(b)" maxlength="30" /></label>
          <label v-if="b.actions.length" class="wl-field bn-color-field"><span class="wl-label">Colour</span>
            <input type="color" class="wl-swatch" v-model="b.button_color" /></label>
        </div>

        <!-- Deep-link target: which specific venue / membership / event that button opens. -->
        <label v-if="deepLinkAction(b)" class="wl-field">
          <span class="wl-label">{{ deepLinkAction(b) === 'venue' ? 'Which venue' : deepLinkAction(b) === 'program' ? 'Which membership' : 'Which event' }}</span>
          <select class="wl-input" v-model="b.target">
            <option value="">Select…</option>
            <option v-for="o in targetOptionsFor(deepLinkAction(b))" :key="o.value" :value="o.value">{{ o.label }}</option>
          </select>
          <span v-if="!targetOptionsFor(deepLinkAction(b)).length" class="bn-warn">You don’t have any to link yet — add one first.</span>
        </label>
        <label v-if="b.actions.includes('custom')" class="wl-field"><span class="wl-label">Custom link</span>
          <input class="wl-input" v-model="b.link" placeholder="/facilities or https://example.com" spellcheck="false" /></label>

        <!-- 4 · WHO SEES IT -->
        <div class="wl-field">
          <span class="wl-label">Show to</span>
          <div class="bn-pills">
            <button v-for="o in audienceOptions" :key="o.k" type="button" class="bn-pill" :class="{ on: b.audience === o.k }" @click="b.audience = o.k">{{ o.label }}</button>
          </div>
        </div>
        <div class="wl-field">
          <span class="wl-label"><MapPin :size="12" style="vertical-align:-1px" /> Show in <em>(leave empty to show everywhere)</em></span>
          <div class="bn-geo">
            <div class="bn-geo-col">
              <span class="bn-geo-l">Countries</span>
              <MultiSelectDropdown :model-value="b.countries" :options="countryOptions" all-label="All countries"
                @update:model-value="v => setCountries(b, v)" />
            </div>
            <div class="bn-geo-col">
              <span class="bn-geo-l">Cities</span>
              <MultiSelectDropdown :model-value="b.cities" :options="cityOptionsFor(b)" all-label="All cities"
                @update:model-value="v => b.cities = v" />
            </div>
          </div>
        </div>
        </div>
      </div>

      <div class="bn-actions">
        <button class="wl-btn wl-btn-soft" @click="requirePremium('banners') && addBanner()"><Plus :size="15" /> Add banner<span v-if="!isPremium" class="prem-lock">Premium</span></button>
        <button class="wl-btn wl-btn-primary" :disabled="saving" @click="save">
          <Loader v-if="saving" :size="15" class="spin" /> {{ saving ? 'Saving…' : 'Save banners' }}
        </button>
      </div>
     </div>

      <!-- Right-side guidance: text shortcuts (tokens) -->
      <aside class="bn-aside">
        <div class="wl-card">
          <div class="wl-card-title"><Hash :size="14" style="vertical-align:-2px" /> Text shortcuts</div>
          <div class="wl-card-sub">Type any of these in the heading or description — they’re swapped for live values on your site.</div>
          <div class="bn-tok" v-for="t in bannerTokens" :key="t.code">
            <code class="bn-tok-code">{{ t.code }}</code>
            <span class="bn-tok-desc">{{ t.desc }}</span>
          </div>
          <div class="wl-card-sub bn-aside-tip">Pick a <strong>Font</strong>, set a <strong>text colour</strong>, and use the description toolbar to <strong>bold</strong>, colour or link words.</div>
        </div>
      </aside>
    </div>
  </div>
</template>

<style scoped>
.bn-layout { display: flex; align-items: flex-start; gap: 20px; }
.bn-wrap { flex: 1; min-width: 0; max-width: 720px; display: flex; flex-direction: column; gap: 14px; }
.bn-aside { width: 260px; flex-shrink: 0; position: sticky; top: 18px; }
.bn-tok { display: flex; align-items: baseline; gap: 10px; padding: 8px 0; border-top: 1px solid #f1f5f9; }
.bn-tok-code { font-family: ui-monospace, monospace; font-size: 12.5px; font-weight: 700; color: #0f766e; background: #f0fdfa; border: 1px solid #99f6e4; border-radius: 6px; padding: 2px 7px; white-space: nowrap; }
.bn-tok-desc { font-size: 12.5px; color: var(--text-muted); }
.bn-aside-tip { margin-top: 12px; padding-top: 12px; border-top: 1px solid #f1f5f9; }
@media (max-width: 920px) { .bn-layout { flex-direction: column; } .bn-aside { width: 100%; position: static; } }
.bn-page-empty { display: flex; flex-direction: column; align-items: center; gap: 8px; text-align: center; padding: 60px 20px; color: var(--text-muted); }
.bn-page-empty h3 { margin: 6px 0 0; color: var(--text); }
.bn-page-empty p { max-width: 360px; font-size: 13.5px; }

.wl-card { background: var(--surface, #ffffff); border: 1px solid var(--border); border-radius: 14px; padding: 18px; }
.wl-card-title { font-size: 14.5px; font-weight: 800; color: var(--text); }
.wl-card-sub { font-size: 12.5px; color: var(--text-muted); margin-top: 4px; line-height: 1.55; }
.wl-card-sub code { background: var(--bg); padding: 1px 5px; border-radius: 5px; font-size: 11.5px; }

.wl-field { display: block; margin-top: 14px; }
.wl-label { display: block; font-size: 12.5px; font-weight: 700; color: var(--text-muted); margin-bottom: 6px; }
.wl-label em { font-weight: 500; font-style: normal; color: var(--text-light, #94a3b8); }
.wl-input { width: 100%; height: 40px; padding: 0 12px; border: 1px solid var(--border); border-radius: 9px; font-size: 14px; color: var(--text); background: var(--surface, #ffffff); box-sizing: border-box; }
.wl-input:focus { outline: none; border-color: #14b8a6; box-shadow: 0 0 0 3px rgba(15,118,110,.12); }
/* input.wl-swatch beats any global input[type=color] width:100% rule — without
   this the colour pickers render as ugly full-width bars. */
input.wl-swatch { width: 44px !important; max-width: 44px; height: 40px; padding: 0; border: 1px solid var(--border); border-radius: 9px; background: none; cursor: pointer; flex-shrink: 0; }
.wl-icon-btn { width: 32px; height: 32px; border: 1px solid var(--border); background: var(--surface, #ffffff); border-radius: 8px; color: var(--text-muted); cursor: pointer; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.wl-icon-btn:hover:not(:disabled) { border-color: #14b8a6; color: #0f766e; }
.wl-icon-btn:disabled { opacity: .45; cursor: not-allowed; }
.wl-btn { display: inline-flex; align-items: center; gap: 8px; height: 40px; padding: 0 16px; border-radius: 9px; font-size: 13.5px; font-weight: 700; cursor: pointer; border: 1px solid transparent; }
.wl-btn:disabled { opacity: .55; cursor: not-allowed; }
.wl-btn-primary { background: #0f766e; color: #fff; }
.wl-btn-primary:hover:not(:disabled) { background: #0d5e58; }
.wl-btn-soft { background: #f0fdfa; color: #0f766e; border-color: #99f6e4; }
.wl-btn-ghost { background: var(--surface, #ffffff); color: var(--text-muted); border-color: var(--border); }

/* Banner cards */
.bn-empty { font-size: 13px; color: var(--text-muted); padding: 4px 2px; }
.bn-defaults-note { font-size: 12.5px; color: #0f766e; background: #f0fdfa; border: 1px solid #99f6e4; border-radius: 10px; padding: 9px 13px; }

/* System (loyalty) banner — read-only note */
.bn-sys { display: flex; gap: 13px; align-items: flex-start; border: 1px dashed #cbd5e1; background: linear-gradient(135deg, #f8fafc, #f1f5f9); border-radius: 12px; padding: 14px 16px; }
.bn-sys-badge { width: 40px; height: 40px; border-radius: 11px; background: #0f172a; color: #fbbf24; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.bn-sys-title { font-size: 13.5px; font-weight: 800; color: var(--text); display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.bn-sys-tag { font-size: 10.5px; font-weight: 800; text-transform: uppercase; letter-spacing: .04em; color: #0f766e; background: #ccfbf1; border-radius: 999px; padding: 2px 8px; }
.bn-sys-sub { font-size: 12.5px; color: var(--text-muted); margin-top: 3px; line-height: 1.5; }

/* Geo targeting (country + city multiselects) */
.bn-geo { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.bn-geo-col { min-width: 0; }
.bn-geo-l { display: block; font-size: 11.5px; font-weight: 600; color: var(--text-muted); margin-bottom: 5px; }
@media (max-width: 560px) { .bn-geo { grid-template-columns: 1fr; } }
.bn-icon-prev { width: 56px; height: 56px; border-radius: 12px; background-size: cover; background-position: center; background-color: #f1f5f9; border: 1px solid var(--border); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.bn-card { border: 1px solid var(--border); border-radius: 12px; padding: 16px; background: var(--surface, #ffffff); }
.bn-card.collapsed { padding: 10px 12px; }
.bn-head { display: flex; align-items: center; gap: 8px; cursor: pointer; user-select: none; }
.bn-card:not(.collapsed) .bn-head { margin-bottom: 4px; }
.bn-caret { border: none; background: transparent; padding: 0; display: inline-flex; align-items: center; color: var(--text-light, #94a3b8); cursor: pointer; flex-shrink: 0; }
.bn-head:hover .bn-caret { color: var(--text); }
.bn-num { font-size: 13.5px; font-weight: 800; color: var(--text); flex-shrink: 0; }
.bn-head-actions { display: flex; gap: 4px; margin-left: auto; }
/* Collapsed summary row */
.bn-head-summary { display: flex; align-items: center; gap: 9px; min-width: 0; flex: 1; }
.bn-sum-swatch { width: 34px; height: 22px; border-radius: 5px; border: 1px solid var(--border); background-size: cover; background-position: center; flex-shrink: 0; }
.bn-sum-title { font-size: 13px; font-weight: 600; color: var(--text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; min-width: 0; }
.bn-sum-aud { font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .03em; color: var(--text-muted); background: var(--bg); border: 1px solid var(--border); border-radius: 999px; padding: 2px 8px; flex-shrink: 0; }
.bn-body { margin-top: 12px; }
.bn-actions-top { margin-bottom: 2px; }
.bn-del:hover:not(:disabled) { color: #dc2626; border-color: #fecaca; }
.bn-pills { display: flex; gap: 6px; flex-wrap: wrap; }
.bn-pill { border: 1.5px solid var(--border); background: var(--surface, #ffffff); color: var(--text-muted); font-size: 12.5px; font-weight: 700; padding: 7px 12px; border-radius: 999px; cursor: pointer; }
.bn-pill.on { border-color: var(--brand, #14b8a6); background: color-mix(in srgb, var(--brand, #14b8a6) 10%, white); color: var(--brand, #0f766e); }
/* Live preview (matches the storefront HeroSlideshow look) */
.bn-prev-label { display: flex; align-items: center; gap: 5px; margin: 14px 0 6px; }
.bn-prev { position: relative; border-radius: 14px; overflow: hidden; min-height: 124px; display: flex; align-items: center; padding: 22px 22px; background-size: cover; background-position: center; }
.bn-prev.plain { background: linear-gradient(135deg, var(--brand-accent, #14b8a6), var(--brand, #0f766e)); }
.bn-prev-scrim { position: absolute; inset: 0; background: linear-gradient(90deg, rgba(0,0,0,.55), rgba(0,0,0,.15)); }
.bn-prev-tag { position: absolute; top: 10px; right: 10px; z-index: 2; font-size: 10.5px; font-weight: 800; text-transform: uppercase; letter-spacing: .04em; color: #fff; background: rgba(15,23,42,.6); backdrop-filter: blur(4px); border-radius: 999px; padding: 3px 9px; }
/* Text left, button right — mirrors the storefront HeroSlideshow. */
.bn-prev-content { position: relative; z-index: 1; width: 100%; display: flex; align-items: center; justify-content: space-between; gap: 16px; }
.bn-prev-lead { display: flex; align-items: center; gap: 14px; min-width: 0; }
.bn-prev-icon { width: 52px; height: 52px; border-radius: 12px; object-fit: cover; flex-shrink: 0; background: rgba(255,255,255,.18); }
.bn-prev-body { min-width: 0; }
.bn-prev-h1 { font-size: 21px; font-weight: 900; line-height: 1.15; }
.bn-prev-sub { font-size: 13.5px; margin-top: 8px; opacity: .94; line-height: 1.45; }
.bn-prev-btns { display: flex; gap: 8px; flex-wrap: wrap; flex-shrink: 0; }
.bn-prev-btn { flex-shrink: 0; display: inline-block; background: var(--surface, #ffffff); color: var(--brand, #0f766e); font-weight: 800; font-size: 13px; padding: 9px 18px; border-radius: 999px; white-space: nowrap; }
.bn-prev-btn.ghost { background: rgba(255,255,255,.14); color: #fff; border: 1.5px solid rgba(255,255,255,.6); }
.bn-prev-content.center { flex-direction: column; text-align: center; gap: 12px; }
.bn-prev-content.center .bn-prev-lead { flex-direction: column; align-items: center; }
.bn-prev-content.center .bn-prev-btns { justify-content: center; }
.bn-prev-logo { width: 44px; height: 44px; border-radius: 12px; background: rgba(255,255,255,.18); color: #fff; font-size: 16px; font-weight: 900; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }
.bn-layoutrow { display: flex; gap: 18px; margin-top: 10px; flex-wrap: wrap; }
.bn-check { display: inline-flex; align-items: center; gap: 7px; font-size: 13px; font-weight: 600; color: var(--text); cursor: pointer; }
.bn-check input { width: 15px; height: 15px; accent-color: var(--primary, #0f766e); }

.bn-bg { display: flex; gap: 12px; align-items: flex-start; }
.bn-img { width: 110px; height: 66px; border-radius: 9px; background-size: cover; background-position: center; background-color: #f1f5f9; border: 1px solid var(--border); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.bn-img-ph { color: var(--text-light, #94a3b8); }
.bn-bg-controls { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.bn-or { font-size: 12px; color: var(--text-muted); }
.bn-hs-reset { flex-shrink: 0; font-size: 12px; font-weight: 700; color: var(--primary); background: none; border: 1px solid var(--border); border-radius: 7px; cursor: pointer; padding: 5px 10px; }
.bn-hs-reset:hover { background: #f0fdfa; border-color: var(--primary); }
.bn-subarea { resize: vertical; min-height: 58px; line-height: 1.45; }
.bn-hs-font { margin-top: 8px; }

/* Preset colour chips + rainbow custom picker (same pattern as the listing form) */
.bn-colorrow { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.bn-csw { width: 30px; height: 30px; border-radius: 50%; border: 2px solid var(--border); padding: 0; cursor: pointer; flex-shrink: 0; }
.bn-csw.on { box-shadow: 0 0 0 2px var(--card, #fff), 0 0 0 4px var(--primary); border-color: transparent; }
.bn-csw-custom { position: relative; overflow: hidden; display: inline-flex; background-image: conic-gradient(#ef4444, #f59e0b, #10b981, #3b82f6, #a855f7, #ef4444); }
.bn-csw-custom input { position: absolute; inset: 0; opacity: 0; cursor: pointer; }
.bn-hs-font { flex: 1; min-width: 160px; }
.bn-warn { font-size: 11.5px; color: #b45309; margin-top: 8px; }
.bn-row2 { display: grid; grid-template-columns: 1fr 1fr auto; gap: 10px; align-items: end; }
.bn-color-field { margin-top: 14px; }
.bn-actions { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
@media (max-width: 560px) { .bn-row2 { grid-template-columns: 1fr; } }

/* Phone (≤600px, incl. 344–360px): mobile-only tightening — desktop untouched. */
@media (max-width: 600px) {
  /* 16px font on inputs/selects prevents iOS Safari auto-zoom on focus. */
  .wl-input, select.wl-input, .bn-hs-font { font-size: 16px; }
  /* Live preview: stack heading above the button so neither squeezes the other. */
  .bn-prev { padding: 16px 14px; }
  .bn-prev-content { flex-direction: column; align-items: flex-start; gap: 10px; }
  .bn-prev-btn { white-space: normal; }
  .bn-prev-h1 { font-size: 18px; }
  /* Touch targets. */
  .wl-btn { height: 44px; }
  .wl-icon-btn { width: 38px; height: 38px; }
  /* Collapsed head row: drop the audience pill so the title keeps ellipsis room. */
  .bn-sum-aud { display: none; }
  /* Background/icon rows: let the controls wrap below the thumbnail. */
  .bn-bg { flex-wrap: wrap; }
  /* Add banner + Save banners: wrap instead of touching/overflowing at 344–360px. */
  .bn-actions { flex-wrap: wrap; }
  .bn-actions .wl-btn { flex: 1 1 auto; justify-content: center; }
  /* Slightly tighter card padding for narrow field width. */
  .bn-card { padding: 12px; }
}
</style>
