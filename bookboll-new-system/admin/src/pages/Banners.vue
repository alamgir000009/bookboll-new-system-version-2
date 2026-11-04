<script setup>
/**
 * Banners.vue — "My Website → Home banners". A dedicated editor for the home-page
 * hero slideshow, split out of the (busy) Site page.
 *
 * Super admin (no provider context) edits the Bookboll marketplace banners, stored in
 * platform_settings under the `hero_banners` key. A provider edits their own, stored on
 * their admin_users.hero_banners. Read live by the storefront (lib/brand.js → HeroSlideshow).
 */
import { ref, computed, onMounted, watch, nextTick } from 'vue'
import { loadSiteSettings, saveSiteSettings } from '../lib/siteSettings.js'
import { loadReferenceData } from '../lib/referenceData.js'
import { fetchSubscriptionsDashboard } from '../lib/subscriptions.js'
import { fetchEventModerationDashboard } from '../lib/moderationEvents.js'
import { useCurrentUser } from '../lib/currentUser.js'
import { usePremium } from '../lib/premium.js'
import { useToast } from '../lib/toast.js'
import { uploadImage, imageUploadConfigured } from '../lib/imageUpload.js'
import { BANNER_ACTIONS, BANNER_FONTS, BANNER_TOKENS, LISTING_ACTION_PATH, applyTokens, normalizeBanners, bannerButtonText, defaultBanners, bannerButtons, bannerBgStyle } from '../lib/heroBanners.js'
import { selectedCity, selectedCountry, customer } from '../lib/customerSession.js'
import { sanitizeHtml } from '../lib/blog.js'
import { brandButtonText } from '../lib/brandColors.js'
import { BANNER_STARTERS, createBannerDraft, bannerDraftError } from '../lib/bannerEditor.js'
import { markDirty, clearDirty } from '../lib/unsavedGuard.js'
import MultiSelectDropdown from '../components/MultiSelectDropdown.vue'
import { Images, Plus, Trash2, ChevronUp, ChevronDown, ChevronRight, Upload, Image as ImageIcon, Loader, Eye, Hash, MapPin, Award, Check, ArrowRight, Undo2 } from 'lucide-vue-next'

const { currentOwnerUserId, isSuperAdmin, role: actorRole } = useCurrentUser()
const toast = useToast()

const ownerId = computed(() => currentOwnerUserId.value)
const isPlatformMode = computed(() => (isSuperAdmin.value || actorRole.value === 'platform_staff') && !ownerId.value)
const { isPremium, requirePremium } = usePremium()   // custom banners are a Premium feature for providers

const loading = ref(true)
const brandNameRef = ref('')
const saving = ref(false)
const banners = ref([])
const savedSnapshot = ref('[]')
const loadError = ref('')
const showStarters = ref(false)
const editorSteps = ref({})
const editorErrors = ref({})
const removedBanner = ref(null)
const starters = computed(() => BANNER_STARTERS.filter(item => !item.platformOnly || isPlatformMode.value))
const draftSnapshot = computed(() => JSON.stringify(normalizeBanners(banners.value)))
const dirty = computed(() => draftSnapshot.value !== savedSnapshot.value)
const busy = computed(() => saving.value || !!bannerUploading.value || !!bannerIconUploading.value)
watch(dirty, changed => { if (!loading.value) changed ? markDirty() : clearDirty() })
const stepFor = b => editorSteps.value[b.id] || 'message'
const editSteps = [{ key: 'message', label: 'Message & button' }, { key: 'design', label: 'Appearance' }, { key: 'audience', label: 'Who sees it' }]
function beginAdd() { showStarters.value = true }
async function chooseStarter(key) {
  if (!requirePremium('banners')) return
  const b = createBannerDraft(key, isPlatformMode.value)
  if (!b) return
  banners.value = [...banners.value, b]
  expandedIds.value = new Set([b.id])
  editorSteps.value[b.id] = 'message'
  showStarters.value = false
  await nextTick()
  document.getElementById(`banner-heading-${b.id}`)?.focus()
}
function undoRemove() {
  if (!removedBanner.value) return
  const { banner, index } = removedBanner.value
  banners.value.splice(index, 0, banner)
  removedBanner.value = null
}
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
  loadError.value = ''
  try {
  // Countries + cities for the geo-targeting dropdowns (load once alongside the banners).
  if (!countries.value.length) {
    const ref = await loadReferenceData()
    countries.value = ref.countries
    cities.value = ref.cities
  }
  // The API returns the marketplace's banners for a platform admin, or the
  // provider's own — same endpoint either way.
  const response = await loadSiteSettings()
  if (!response.success) throw new Error('Could not load your banners. Try again before making changes.')
  const { settings, username } = response
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
  savedSnapshot.value = draftSnapshot.value
  expandedIds.value = new Set()
  removedBanner.value = null
  await loadTargets()
  } catch (error) { loadError.value = error.message || 'Could not load your banners.' }
  finally { loading.value = false }
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
    // The stored target is pasted straight into /programs/<target>, and that
    // route resolves by uuid — so the option value has to be the uuid.
    programOpts.value = (dash.success ? (dash.data?.programs || []) : [])
      .map(p => ({ value: String(p.uuid || p.id), label: p.name }))
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
  expandedIds.value = isExpanded(b) ? new Set() : new Set([b.id])
}
function removeBanner(i) {
  removedBanner.value = { banner: banners.value[i], index: i }
  banners.value = banners.value.filter((_, idx) => idx !== i)
}
function moveBanner(i, dir) {
  const j = i + dir
  if (j < 0 || j >= banners.value.length) return
  const arr = [...banners.value];[arr[i], arr[j]] = [arr[j], arr[i]]; banners.value = arr
}
async function onBannerImage(e, b) {
  const file = e.target.files?.[0]; if (!file) return
  bannerUploading.value = b.id
  const r = await uploadImage(file, 'banners')
  bannerUploading.value = ''
  if (r.error) { toast.error(r.error); return }
  b.image = r.url
}
async function onBannerIcon(e, b) {
  const file = e.target.files?.[0]; if (!file) return
  bannerIconUploading.value = b.id
  const r = await uploadImage(file, 'banners')
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
function previewBtnStyle(b) { return b.button_color ? { background: b.button_color, color: brandButtonText(b.button_color) } : {} }
function previewHasButton(b) { return bannerButtons(b).length > 0 }
const AUD_LABEL = { all: 'Everyone', in: 'Signed-in', out: 'Signed-out' }

async function save() {
  if (busy.value || loading.value || loadError.value) return
  editorErrors.value = Object.fromEntries(banners.value.map(b => [b.id, bannerDraftError(b)]).filter(([, error]) => error))
  const invalid = banners.value.find(b => editorErrors.value[b.id])
  if (invalid) {
    expandedIds.value = new Set([invalid.id])
    editorSteps.value[invalid.id] = 'message'
    await nextTick()
    document.getElementById(`banner-heading-${invalid.id}`)?.focus()
    return
  }
  saving.value = true
  const clean = normalizeBanners(banners.value)
  let res
  try { res = await saveSiteSettings({ hero_banners: clean }) }
  catch { res = { success: false, message: 'Could not save your banners. Please try again.' } }
  finally { saving.value = false }
  if (!res.success) { toast.error(res.message || 'Could not save.'); return }
  savedSnapshot.value = JSON.stringify(clean)
  usingDefaults.value = false
  removedBanner.value = null
  dirty.value ? markDirty() : clearDirty()
  if (isPlatformMode.value) { toast.success('Marketplace banners saved.'); return }
  toast.success('Home banners saved.')
}
</script>

<template>
  <div class="page">
    <div class="page-header">
      <div>
        <h1 class="page-title">Home banners</h1>
        <div class="page-subtitle">{{ isPlatformMode ? 'The slideshow at the top of the Bookboll marketplace home page.' : 'Guide visitors to a booking, a membership or your latest news.' }}</div>
      </div>
      <button class="wl-btn wl-btn-primary" :disabled="busy || loading || !!loadError || (!dirty && !usingDefaults)" @click="save">
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

    <div v-else-if="loadError" class="bn-page-empty" role="alert"><p>{{ loadError }}</p><button class="wl-btn wl-btn-primary" @click="load">Try again</button></div>
    <div v-else class="bn-layout">
     <div class="bn-wrap">
      <div v-if="!isPlatformMode" class="bn-welcome">
        <span class="bn-welcome-icon"><Images :size="23" /></span>
        <div><strong>Your welcome slide comes first</strong><p>Your logo, name and tagline are already on your homepage. Extra banners rotate after it.</p></div>
        <router-link to="/brand?tab=branding">Edit welcome slide<ArrowRight :size="14" /></router-link>
      </div>
      <!-- System loyalty slide — shown automatically, first, to signed-in customers. Not editable. -->
      <div v-if="isPlatformMode" class="bn-sys">
        <div class="bn-sys-badge"><Award :size="20" /></div>
        <div class="bn-sys-body">
          <div class="bn-sys-title">Rewards banner <span class="bn-sys-tag">Automatic · first slide</span></div>
          <div class="bn-sys-sub">Signed-in customers see their loyalty card (“Hey {name}! You’re a Rookie… My Rewards”) as the first slide, before your banners below. It’s generated per customer, so it isn’t editable here.</div>
        </div>
      </div>

      <div class="bn-collection-heading"><div><h2>{{ banners.length ? 'Your banner slides' : 'Add your first banner' }}</h2><p>{{ banners.length ? 'Shown in this order. Open a slide to edit it.' : 'Choose a starting point, make it yours, then save to go live.' }}</p></div><button v-if="banners.length && !showStarters" type="button" class="wl-btn wl-btn-soft" @click="beginAdd"><Plus :size="16" />Add banner</button></div>
      <div v-if="!isPremium && !isPlatformMode" class="bn-plan-note">Extra banner slides are included with Premium. Your welcome slide is included on Free.</div>
      <section v-if="!banners.length || showStarters" class="bn-starters" aria-label="Choose a banner starting point">
        <button v-for="starter in starters" :key="starter.key" type="button" @click="chooseStarter(starter.key)">
          <span class="bn-starter-art" :style="{ background: starter.color }"><span></span><span></span><span v-if="starter.key !== 'announcement' && starter.key !== 'blank'"></span></span>
          <strong>{{ starter.title }}</strong><span>{{ starter.description }}</span><span class="bn-starter-choose">{{ !isPremium && !isPlatformMode ? 'Explore Premium' : 'Use this starting point' }}<ArrowRight :size="14" /></span>
        </button>
        <button v-if="showStarters && banners.length" type="button" class="bn-starters-cancel" @click="showStarters = false">Cancel</button>
      </section>
      <div v-if="usingDefaults" class="bn-defaults-note">These are your current default slides. Edit them or add another, then save.</div>
      <div v-if="removedBanner" class="bn-undo" role="status">Banner removed from your draft.<button type="button" @click="undoRemove"><Undo2 :size="14" />Undo</button></div>
      <div v-for="(b, i) in banners" :key="b.id" class="bn-card" :class="{ collapsed: !isExpanded(b) }">
        <div class="bn-head">
          <button type="button" class="bn-expand" :aria-expanded="isExpanded(b)" :aria-controls="`banner-editor-${b.id}`" @click="toggleExpand(b)">
          <span class="bn-caret">
            <ChevronDown v-if="isExpanded(b)" :size="16" /><ChevronRight v-else :size="16" />
          </span>
          <span class="bn-num">Banner {{ i + 1 }}</span>
          <span v-if="!isExpanded(b)" class="bn-head-summary">
            <span class="bn-sum-swatch" :style="previewBg(b)"></span>
            <span class="bn-sum-title">{{ tok(b.heading) || 'Untitled banner' }}</span>
            <span class="bn-sum-aud">{{ AUD_LABEL[b.audience] }}</span>
          </span>
          </button>
          <div class="bn-head-actions" @click.stop>
            <button class="wl-icon-btn" :disabled="i === 0" @click="moveBanner(i, -1)" title="Move up"><ChevronUp :size="15" /></button>
            <button class="wl-icon-btn" :disabled="i === banners.length - 1" @click="moveBanner(i, 1)" title="Move down"><ChevronDown :size="15" /></button>
            <button class="wl-icon-btn bn-del" @click="removeBanner(i)" title="Delete"><Trash2 :size="15" /></button>
          </div>
        </div>

        <div v-show="isExpanded(b)" :id="`banner-editor-${b.id}`" class="bn-body">
        <!-- Live preview -->
        <div class="bn-preview-heading"><span class="wl-label bn-prev-label"><Eye :size="14" /> Banner preview</span><span class="bn-preview-audience">{{ AUD_LABEL[b.audience] }}</span></div>
        <div class="bn-prev" :class="{ plain: !b.image && !b.bg }" :style="previewBg(b)">
          <div v-if="b.image" class="bn-prev-scrim"></div>
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

        <nav class="bn-editor-nav" aria-label="Banner editing steps"><button v-for="(editStep, stepIndex) in editSteps" :key="editStep.key" type="button" :aria-current="stepFor(b) === editStep.key ? 'step' : undefined" @click="editorSteps[b.id] = editStep.key"><span>{{ stepIndex + 1 }}</span>{{ editStep.label }}</button></nav>
        <p v-if="editorErrors[b.id]" class="bn-validation" role="alert">{{ editorErrors[b.id] }}</p>
        <div class="bn-editor-grid" :class="`step-${stepFor(b)}`">
        <section v-show="stepFor(b) === 'message'" class="bn-editor-section bn-copy">
        <h2>Content</h2>
        <label class="wl-field"><span class="wl-label">Heading</span>
          <input :id="`banner-heading-${b.id}`" class="wl-input" v-model="b.heading" maxlength="90" placeholder="Play more. Hangout. Near you." /></label>
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
        </section>
        <section v-show="stepFor(b) === 'design'" class="bn-editor-section bn-appearance">
        <h2>Appearance</h2>
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
          <div v-if="!imageUploadConfigured()" class="bn-warn">Image upload isn’t available right now — use a colour, or try again.</div>
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

        </section>
        <section v-show="stepFor(b) === 'message'" class="bn-editor-section bn-buttons">
        <h2>Buttons &amp; destination</h2>
        <div class="bn-row2">
          <label class="wl-field"><span class="wl-label">Buttons <span class="opt">pick one or more — first is the primary</span></span>
            <MultiSelectDropdown :model-value="b.actions" :options="actionOptions" all-label="No buttons"
              @update:model-value="keys => setActions(b, keys)" />
          </label>
          <label v-if="b.actions.length" class="wl-field"><span class="wl-label">1st button text</span>
            <input class="wl-input" v-model="b.button_text" :placeholder="actionPlaceholder(b)" maxlength="60" /></label>
          <label v-if="b.actions.length" class="wl-field bn-color-field"><span class="wl-label">Colour</span>
            <input type="color" class="wl-swatch" v-model="b.button_color" aria-label="Primary button colour" /></label>
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

        </section>
        <section v-show="stepFor(b) === 'audience'" class="bn-editor-section bn-audience">
        <h2>Choose your audience</h2><p class="wl-card-sub">Show this banner to everyone, or choose specific visitors and locations.</p>
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
        </section>
        </div>
        <div class="bn-editor-footer"><span>Changes go live when you save banners.</span><button v-if="stepFor(b) !== 'audience'" type="button" class="wl-btn wl-btn-soft" @click="editorSteps[b.id] = stepFor(b) === 'message' ? 'design' : 'audience'">{{ stepFor(b) === 'message' ? 'Next: appearance' : 'Next: audience' }}<ArrowRight :size="15" /></button><button v-else type="button" class="wl-btn wl-btn-ghost" @click="toggleExpand(b)"><Check :size="15" />Done editing</button></div>
        </div>
      </div>

      <div v-if="banners.length || dirty" class="bn-savebar"><span role="status">{{ dirty || usingDefaults ? 'Unsaved changes' : 'All changes saved' }}</span><button type="button" class="wl-btn wl-btn-primary" :disabled="busy || (!dirty && !usingDefaults)" @click="save"><Loader v-if="saving" :size="15" class="spin" /><Check v-else :size="15" />{{ saving ? 'Saving…' : 'Save banners' }}</button></div>
     </div>

      <!-- Right-side guidance: text shortcuts (tokens) -->
      <aside class="bn-aside"><details class="wl-card"><summary>Personalise text with shortcuts</summary>
          <div class="wl-card-title"><Hash :size="14" style="vertical-align:-2px" /> Text shortcuts</div>
          <div class="wl-card-sub">Type any of these in the heading or description — they’re swapped for live values on your site.</div>
          <div class="bn-tok" v-for="t in bannerTokens" :key="t.code">
            <code class="bn-tok-code">{{ t.code }}</code>
            <span class="bn-tok-desc">{{ t.desc }}</span>
          </div>
          <div class="wl-card-sub bn-aside-tip">Use the preview to check your wording, colours and buttons. Changes go live when you save your banners.</div>
        </details>
      </aside>
    </div>
  </div>
</template>

<style scoped>
.bn-layout { display: grid; grid-template-columns: minmax(0, 1fr); align-items: start; gap: 24px; }
.bn-wrap { min-width: 0; width: 100%; display: flex; flex-direction: column; gap: 16px; }
.bn-aside { min-width: 0; }.bn-aside summary { cursor:pointer; font-size:13px; font-weight:650; color:var(--text); }.bn-aside .wl-card-title { display:none; }
.bn-tok { display: flex; align-items: baseline; gap: 10px; padding: 8px 0; border-top: 1px solid var(--border, #f1f5f9); }
.bn-tok-code { font-family: ui-monospace, monospace; font-size: 12.5px; font-weight: 700; color: var(--primary-ink, #0f766e); background: var(--primary-soft, #f0fdfa); border: 1px solid var(--primary-border, #99f6e4); border-radius: 6px; padding: 2px 7px; white-space: nowrap; }
.bn-tok-desc { font-size: 12.5px; color: var(--text-muted); }
.bn-aside-tip { margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--border, #f1f5f9); }
@media (max-width: 1100px) { .bn-layout { grid-template-columns: minmax(0, 1fr); } .bn-aside { width: 100%; position: static; } }
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
.wl-icon-btn:hover:not(:disabled) { border-color: #14b8a6; color: var(--primary-ink, #0f766e); }
.wl-icon-btn:disabled { opacity: .45; cursor: not-allowed; }
.wl-btn { display: inline-flex; align-items: center; gap: 8px; height: 40px; padding: 0 16px; border-radius: 9px; font-size: 13.5px; font-weight: 700; cursor: pointer; border: 1px solid transparent; }
.wl-btn:disabled { opacity: .55; cursor: not-allowed; }
.wl-btn-primary { background: #0f766e; color: #fff; }
.wl-btn-primary:hover:not(:disabled) { background: #0d5e58; }
.wl-btn-soft { background: var(--primary-soft, #f0fdfa); color: var(--primary-ink, #0f766e); border-color: var(--primary-border, #99f6e4); }
.wl-btn-ghost { background: var(--surface, #ffffff); color: var(--text-muted); border-color: var(--border); }

/* Banner cards */
.bn-empty { font-size: 13px; color: var(--text-muted); padding: 4px 2px; }
.bn-defaults-note { font-size: 12.5px; color: var(--primary-ink, #0f766e); background: var(--primary-soft, #f0fdfa); border: 1px solid var(--primary-border, #99f6e4); border-radius: 10px; padding: 9px 13px; }

/* System (loyalty) banner — read-only note */
.bn-sys { display: flex; gap: 13px; align-items: flex-start; border: 1px dashed var(--border-strong, #cbd5e1); background: linear-gradient(135deg, var(--surface-hover, #f8fafc), var(--bg, #f1f5f9)); border-radius: 12px; padding: 14px 16px; }
.bn-sys-badge { width: 40px; height: 40px; border-radius: 11px; background: #0f172a; color: #fbbf24; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.bn-sys-title { font-size: 13.5px; font-weight: 800; color: var(--text); display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.bn-sys-tag { font-size: 10.5px; font-weight: 800; text-transform: uppercase; letter-spacing: .04em; color: var(--primary-ink, #0f766e); background: var(--primary-soft, #ccfbf1); border-radius: 999px; padding: 2px 8px; }
.bn-sys-sub { font-size: 12.5px; color: var(--text-muted); margin-top: 3px; line-height: 1.5; }

/* Geo targeting (country + city multiselects) */
.bn-geo { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.bn-geo-col { min-width: 0; }
.bn-geo-l { display: block; font-size: 11.5px; font-weight: 600; color: var(--text-muted); margin-bottom: 5px; }
@media (max-width: 560px) { .bn-geo { grid-template-columns: 1fr; } }
.bn-icon-prev { width: 56px; height: 56px; border-radius: 12px; background-size: cover; background-position: center; background-color: var(--bg, #f1f5f9); border: 1px solid var(--border); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.bn-card { container-type: inline-size; border: 1px solid var(--border); border-radius: 14px; padding: 22px; background: var(--surface, #ffffff); min-width: 0; }
.bn-card.collapsed { padding: 10px 12px; }
.bn-head { display: flex; align-items: center; gap: 12px; user-select: none; }
.bn-expand { flex: 1; min-width: 0; display: flex; align-items: center; gap: 10px; min-height: 40px; padding: 0; border: 0; background: transparent; text-align: start; cursor: pointer; }
.bn-expand:focus-visible, .bn-pill:focus-visible { outline: 2px solid var(--primary); outline-offset: 4px; border-radius: 6px; }
.bn-card:not(.collapsed) .bn-head { margin-bottom: 4px; }
.bn-caret { border: none; background: transparent; padding: 0; display: inline-flex; align-items: center; color: var(--text-light, #94a3b8); cursor: pointer; flex-shrink: 0; }
.bn-head:hover .bn-caret { color: var(--text); }
.bn-num { font-size: 13.5px; font-weight: 800; color: var(--text); flex-shrink: 0; }
.bn-head-actions { display: flex; gap: 4px; margin-left: auto; }
/* Collapsed summary row */
.bn-head-summary { display: flex; align-items: center; gap: 9px; min-width: 0; flex: 1; }
.bn-sum-swatch { width: 34px; height: 22px; border-radius: 5px; border: 1px solid var(--border); background-size: cover; background-position: center; flex-shrink: 0; }
.bn-sum-title { font-size: 13px; font-weight: 600; color: var(--text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; min-width: 0; }
.bn-sum-aud { font-size: 11px; font-weight: 600; color: var(--text-muted); background: var(--bg); border: 1px solid var(--border); border-radius: 6px; padding: 3px 8px; flex-shrink: 0; }
.bn-body { margin-top: 12px; }
.bn-actions-top { margin-bottom: 2px; }
.bn-del:hover:not(:disabled) { color: var(--danger-ink, #dc2626); border-color: var(--danger-border, #fecaca); }
.bn-pills { display: flex; gap: 6px; flex-wrap: wrap; }
.bn-pill { border: 1.5px solid var(--border); background: var(--surface, #ffffff); color: var(--text-muted); font-size: 12.5px; font-weight: 700; padding: 7px 12px; border-radius: 999px; cursor: pointer; }
.bn-pill.on { border-color: var(--brand, #14b8a6); background: color-mix(in srgb, var(--brand, #14b8a6) 10%, white); color: var(--primary-ink, #0f766e); }
/* Live preview (matches the storefront HeroSlideshow look) */
.bn-preview-heading { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin: 16px 0 10px; }
.bn-prev-label { display: flex; align-items: center; gap: 7px; margin: 0; }
.bn-preview-audience { font-size: 11px; font-weight: 600; color: var(--text-muted); padding: 4px 8px; background: var(--bg); border-radius: 6px; }
.bn-prev { position: relative; border-radius: 12px; overflow: hidden; min-height: 180px; display: flex; align-items: center; padding: 28px; background-size: cover; background-position: center; }
.bn-prev.plain { background: linear-gradient(135deg, var(--brand-accent, #14b8a6), var(--brand, #0f766e)); }
.bn-prev-scrim { position: absolute; inset: 0; background: linear-gradient(90deg, rgba(0,0,0,.55), rgba(0,0,0,.15)); }
/* Text left, button right — mirrors the storefront HeroSlideshow. */
.bn-prev-content { position: relative; z-index: 1; width: 100%; display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 22px; }
.bn-prev-lead { display: flex; align-items: center; gap: 14px; min-width: 0; flex: 1 1 350px; }
.bn-prev-icon { width: 52px; height: 52px; border-radius: 12px; object-fit: cover; flex-shrink: 0; background: rgba(255,255,255,.18); }
.bn-prev-body { min-width: 0; }
.bn-prev-h1 { font-size: 26px; font-weight: 750; line-height: 1.2; letter-spacing: -.5px; overflow-wrap: anywhere; }
.bn-prev-sub { font-size: 13.5px; margin-top: 8px; opacity: .94; line-height: 1.45; }
.bn-prev-btns { display: flex; gap: 8px; flex-wrap: wrap; max-width: 100%; }
.bn-prev-btn { display: inline-flex; align-items: center; justify-content: center; min-height: 42px; max-width: 100%; box-sizing: border-box; background: #ffffff; color: #142132; font-weight: 650; font-size: 13px; padding: 11px 18px; border-radius: 999px; white-space: normal; overflow-wrap: anywhere; text-align: center; }
.bn-prev-btn.ghost { background: rgba(255,255,255,.14); color: #fff; border: 1.5px solid rgba(255,255,255,.6); }
.bn-prev-content.center { flex-direction: column; text-align: center; gap: 12px; }
.bn-prev-content.center .bn-prev-lead { flex-direction: column; align-items: center; flex-basis: auto; }
.bn-prev-content.center .bn-prev-btns { justify-content: center; }
.bn-prev-logo { width: 44px; height: 44px; border-radius: 12px; background: rgba(255,255,255,.18); color: #fff; font-size: 16px; font-weight: 900; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }
.bn-layoutrow { display: flex; gap: 18px; margin-top: 10px; flex-wrap: wrap; }
.bn-check { display: inline-flex; align-items: center; gap: 7px; font-size: 13px; font-weight: 600; color: var(--text); cursor: pointer; }
.bn-check input { width: 15px; height: 15px; accent-color: var(--primary, #0f766e); }

.bn-bg { display: flex; gap: 12px; align-items: flex-start; }
.bn-img { width: 110px; height: 66px; border-radius: 9px; background-size: cover; background-position: center; background-color: var(--bg, #f1f5f9); border: 1px solid var(--border); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.bn-img-ph { color: var(--text-light, #94a3b8); }
.bn-bg-controls { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.bn-or { font-size: 12px; color: var(--text-muted); }
.bn-hs-reset { flex-shrink: 0; font-size: 12px; font-weight: 700; color: var(--primary-ink); background: none; border: 1px solid var(--border); border-radius: 7px; cursor: pointer; padding: 5px 10px; }
.bn-hs-reset:hover { background: var(--primary-soft, #f0fdfa); border-color: var(--primary); }
.bn-subarea { resize: vertical; min-height: 58px; line-height: 1.45; }
.bn-hs-font { margin-top: 8px; }

/* Preset colour chips + rainbow custom picker (same pattern as the listing form) */
.bn-colorrow { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.bn-csw { width: 30px; height: 30px; border-radius: 50%; border: 2px solid var(--border); padding: 0; cursor: pointer; flex-shrink: 0; }
.bn-csw.on { box-shadow: 0 0 0 2px var(--card, #fff), 0 0 0 4px var(--primary); border-color: transparent; }
.bn-csw-custom { position: relative; overflow: hidden; display: inline-flex; background-image: conic-gradient(#ef4444, #f59e0b, #10b981, #3b82f6, #a855f7, #ef4444); }
.bn-csw-custom input { position: absolute; inset: 0; opacity: 0; cursor: pointer; }
.bn-hs-font { flex: 1; min-width: 160px; }
.bn-warn { font-size: 11.5px; color: var(--warning-ink, #b45309); margin-top: 8px; }
.bn-row2 { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 0 12px; align-items: end; }
.bn-row2 > .wl-field { min-width: 0; }
.bn-row2 > :first-child { grid-column: 1 / -1; }
.bn-editor-grid { display:grid; gap:24px 32px; margin-top:24px; }
.bn-editor-grid.step-message { grid-template-columns:minmax(0,1fr) minmax(0,1fr); }
.bn-editor-grid.step-design, .bn-editor-grid.step-audience { grid-template-columns:minmax(0,1fr); }
.bn-editor-section { min-width:0; }.bn-editor-section h2 { font-size:16px; margin:0; color:var(--text); }
.bn-buttons { border-inline-start:1px solid var(--border); padding-inline-start:28px; }
.bn-appearance { display:grid; grid-template-columns:minmax(0,1fr) minmax(0,1fr); gap:18px 28px; }.bn-appearance h2 { grid-column:1/-1; }.bn-appearance .wl-field { margin-top:0; }.bn-appearance .bn-bg { flex-wrap:wrap; }
@container(max-width:660px) {
  .bn-editor-grid.step-message, .bn-appearance { grid-template-columns:minmax(0,1fr); }
  .bn-buttons { padding-inline-start:0; border-inline-start:0; border-top:1px solid var(--border); padding-top:20px; }
  .bn-prev-content { flex-direction:column; align-items:flex-start; }.bn-prev-lead { flex-basis:auto; }.bn-prev-content.center { align-items:center; }
}
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
.bn-welcome { display:flex; gap:16px; align-items:center; padding:22px; background:var(--surface); border:1px solid var(--border); border-radius:12px; }
.bn-welcome-icon { display:flex; padding:13px; border-radius:10px; color:var(--primary-ink); background:var(--primary-soft); }.bn-welcome div { flex:1; min-width:0; }.bn-welcome strong { font-size:15px; }.bn-welcome p { font-size:13px; line-height:1.6; color:var(--text-muted); margin:5px 0 0; max-width:65ch; }
.bn-welcome a { display:flex; align-items:center; gap:6px; color:var(--primary-ink); font-size:13px; text-decoration:none; font-weight:650; }
.bn-collection-heading { display:flex; align-items:center; justify-content:space-between; gap:16px; margin-top:8px; }.bn-collection-heading h2 { font-size:19px; margin:0; }.bn-collection-heading p { font-size:13px; color:var(--text-muted); margin:6px 0 0; }
.bn-plan-note { font-size:12px; color:var(--text-muted); }
.bn-starters { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:14px; }
.bn-starters > button { display:flex; flex-direction:column; align-items:stretch; text-align:start; padding:14px; border:1px solid var(--border); border-radius:12px; background:var(--surface); color:var(--text); font:inherit; cursor:pointer; min-width:0; }
.bn-starters > button:hover { border-color:var(--primary); }.bn-starters strong { font-size:14px; margin:14px 0 6px; }.bn-starters > button > span:not(.bn-starter-art) { font-size:12px; line-height:1.5; }.bn-starters > button > span:nth-of-type(2) { color:var(--text-muted); }
.bn-starter-art { height:86px; border-radius:7px; padding:20px 15px; display:flex; flex-direction:column; gap:7px; justify-content:center; }.bn-starter-art span { height:5px; width:75%; background:#ffffffd9; border-radius:2px; }.bn-starter-art span:nth-child(2) { width:90%; background:#ffffff80; height:3px; }.bn-starter-art span:nth-child(3) { width:42px; height:12px; margin-top:3px; }
.bn-starters .bn-starter-choose { display:flex; align-items:center; justify-content:space-between; gap:8px; margin-top:auto; padding-top:16px; color:var(--primary-ink); font-weight:650; }
.bn-starters > .bn-starters-cancel { grid-column:1/-1; padding:8px; border:0; color:var(--text-muted); align-items:center; background:none; }
.bn-editor-nav { display:flex; gap:6px; margin-top:24px; padding:6px; border-radius:10px; background:var(--bg); }
.bn-editor-nav button { flex:1; display:flex; align-items:center; justify-content:center; gap:8px; min-height:44px; padding:8px; border:0; background:none; color:var(--text-muted); border-radius:7px; font:inherit; font-size:13px; cursor:pointer; }.bn-editor-nav button[aria-current=step] { background:var(--surface); color:var(--primary-ink); box-shadow:0 1px 4px #12332f12; font-weight:700; }.bn-editor-nav button span { font-size:11px; border:1px solid currentColor; border-radius:50%; width:20px; height:20px; display:grid; place-items:center; flex-shrink:0; }
.bn-editor-footer, .bn-savebar { display:flex; align-items:center; justify-content:space-between; gap:16px; padding-top:20px; margin-top:24px; border-top:1px solid var(--border); }.bn-editor-footer > span, .bn-savebar > span { font-size:12px; color:var(--text-muted); }.bn-savebar { margin:0; }
.bn-undo { display:flex; align-items:center; gap:12px; padding:12px 16px; background:var(--primary-soft); border-radius:8px; font-size:13px; }.bn-undo button { display:flex; align-items:center; gap:6px; border:0; background:none; color:var(--primary-ink); font:inherit; font-weight:700; cursor:pointer; }
.bn-validation { padding:12px; border-radius:8px; background:var(--danger-bg); color:var(--danger-ink); font-size:13px; }
.bn-layout :is(button,a,summary):focus-visible { outline:2px solid var(--primary); outline-offset:3px; }
@media(max-width:600px) { .bn-welcome { flex-wrap:wrap; padding:16px; }.bn-welcome a { width:100%; min-height:40px; }.bn-welcome-icon { padding:9px; }.bn-starters { grid-template-columns:1fr 1fr; gap:10px; }.bn-starters > button { padding:12px; }.bn-starter-art { height:70px; padding:12px; }.bn-editor-nav { gap:2px; padding:4px; }.bn-editor-nav button { flex-direction:column; font-size:11px; gap:5px; }.bn-editor-footer { flex-wrap:wrap; }.bn-editor-footer .wl-btn { width:100%; justify-content:center; }.bn-collection-heading { flex-wrap:wrap; } }
@media(max-width:360px) { .bn-starters { grid-template-columns:1fr; }.bn-head { gap:6px; }.bn-expand { flex-wrap:wrap; gap:6px; }.bn-head-summary { flex-basis:100%; }.bn-head-actions { gap:3px; } }
</style>
