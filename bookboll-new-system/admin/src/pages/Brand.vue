<script setup>
/**
 * Brand.vue — "My Website": a provider configures their white-label customer site,
 * or (platform mode) Bookboll marketplace + admin chrome branding.
 *
 * Platform mode persists via Laravel /api/admin/settings; provider mode via
 * /api/admin/site-settings, scoped to the caller's own business.
 */
import { ref, reactive, computed, onMounted, watch } from 'vue'
import { useRoute } from 'vue-router'
import { BRAND_PALETTES } from '../lib/brandPalettes.js'
import { loadSiteSettings, saveSiteSettings, loadPublicSitePages } from '../lib/siteSettings.js'
import { WEBSITE_SECTIONS, readWebsiteSections, withWebsiteSections, websiteSectionMismatches } from '../lib/websiteSections.js'
import { appBaseUrl } from '../lib/appBase.js'
import { useCurrentUser } from '../lib/currentUser.js'
import { useToast } from '../lib/toast.js'
import { uploadImage, imageUploadConfigured } from '../lib/imageUpload.js'
import { brandHost, brandPreviewById, brandSiteUrl, wildcardDnsAvailable, loadPlatformBrand, applyBrandTheme, platformBrand, DEFAULT_PRIMARY, DEFAULT_ACCENT } from '../lib/brand.js'
import { usePremium } from '../lib/premium.js'
import { SUPPORTED_LOCALES } from '../lib/i18n.js'
import { fetchSettings, updateSettings } from '../lib/settings.js'
import MultiSelect from '../components/MultiSelect.vue'
import BrandMark from '../components/BrandMark.vue'
import { adminBrand, DEFAULT_ADMIN_PRIMARY, saveAdminBrand } from '../lib/adminBrand.js'
import {
  Globe2, Upload, Image as ImageIcon, Copy, ExternalLink, Check, X,
  Loader, Store, Dumbbell, Trophy, GraduationCap, Home, Newspaper, Plus, ArrowRight, CircleCheck,
  Search, Monitor, Smartphone, RefreshCw, Link2, ShieldCheck, CreditCard, Sparkles, Lock, Languages, LayoutDashboard, Settings, Images, MapPin, Mail,
} from 'lucide-vue-next'

const { currentOwnerUserId, currentUsername, isSuperAdmin, role: actorRole, allUsers } = useCurrentUser()
const { isPremium, requirePremium } = usePremium()
const toast = useToast()
const DOMAIN_FEE = 119

const loading = ref(true)
const saving = ref(false)
const uploading = ref(false)
const ownerId = computed(() => currentOwnerUserId.value)
// Editing the MAIN Bookboll marketplace brand is a platform-wide action —
// Bookboll HQ only (Super Admin + Platform Staff). Country Admins run their
// countries, not the one global marketplace brand, so they're excluded.
const isPlatformMode = computed(() => (isSuperAdmin.value || actorRole.value === 'platform_staff') && !ownerId.value)
const siteOrigin = computed(() => appBaseUrl())

function settingsError(res, fallback = 'Could not save.') {
  if (!res) return fallback
  if (res.errors && typeof res.errors === 'object') {
    const first = Object.values(res.errors).flat()[0]
    if (first) return String(first)
  }
  return res.message || fallback
}

const f = reactive({
  brand_enabled: false,
  brand_subdomain: '',
  brand_name: '',
  brand_tagline: '',
  brand_logo_url: '',
  brand_primary: DEFAULT_PRIMARY,
  brand_accent: DEFAULT_ACCENT,
  brand_show_venues: true,
  brand_show_events: true,
  brand_show_programs: true,
  brand_meta_title: '',
  brand_meta_description: '',
  brand_custom_domain: '',
  brand_domain_status: '',     // '' | 'pending' | 'active'
  brand_domain_paid_at: null,
  brand_languages: [],         // offered storefront languages ([] = all 17)
  brand_whitelabel: false,     // PREMIUM: hide the floating Bookboll badge
  // Admin-dashboard chrome branding (platform mode only) — separate from the
  // marketplace brand above. Stored as admin_logo_url / admin_primary keys.
  admin_logo_url: '',
  admin_primary: '',
})

// Accent-colour presets for the admin-dashboard branding section (platform mode).
const ADMIN_PRESETS = ['#0f766e', '#059669', '#2563eb', '#4f46e5', '#7c3aed', '#e11d48', '#d97706', '#334155']
const adminActivePrimary = computed(() => f.admin_primary || DEFAULT_ADMIN_PRIMARY)

// ── Publish / unpublish — ONE button, saved instantly (no separate Save step) ──
const publishing = ref(false)
async function setPublished(on) {
  if (!ownerId.value || publishing.value) return
  publishing.value = true
  const res = await saveSiteSettings({ brand_enabled: on })
  publishing.value = false
  if (!res.success) { toast.error(res.message || 'Could not update.'); return }
  f.brand_enabled = on
  // Keep the in-memory user cache in sync so the dashboard-wide publish
  // banner reacts immediately (it reads allUsers, not this form).
  const cached = allUsers.value.find(u => u.id === ownerId.value)
  if (cached) cached.brand_enabled = on
  toast.success(on ? `🎉 Your site is LIVE at ${liveHost.value}` : 'Site unpublished — only you can see it via preview.')
}

// ── White-label branding — a REAL toggle: premium-gated, saved the moment
//    it's flipped (no Save button involved). ──
const wlbSaving = ref(false)
async function toggleWhitelabel() {
  if (wlbSaving.value || !ownerId.value) return
  if (!f.brand_whitelabel && !isPremium.value) { requirePremium('whitelabel'); return }
  const next = !f.brand_whitelabel
  wlbSaving.value = true
  const res = await saveSiteSettings({ brand_whitelabel: next })
  wlbSaving.value = false
  if (!res.success) { toast.error(res.message || 'Could not update.'); return }
  f.brand_whitelabel = next
  toast.success(next ? 'Bookboll badge hidden — only the small footer credit remains.' : 'Bookboll badge is showing again.')
}
const username = ref('')
const previewDevice = ref('desktop')   // 'desktop' | 'mobile'
const iframeKey = ref(0)               // bump to reload the preview iframe
const websiteTab = ref('branding')
const websiteTabs = computed(() => [
  { key: 'branding', label: 'Branding', icon: Store },
  ...(!isPlatformMode.value ? [
    { key: 'sections', label: 'Sections', icon: LayoutDashboard },
    { key: 'content', label: 'Pages & content', icon: Newspaper },
  ] : []),
  { key: 'settings', label: 'Site settings', icon: Settings },
])
const route = useRoute()
watch([() => route.query.tab, websiteTabs], ([tab, tabs]) => {
  if (tabs.some(item => item.key === tab)) websiteTab.value = tab
}, { immediate: true })

const sectionGroups = [
  { name: 'Bookings & discovery', description: 'Help visitors find something to book.', keys: ['hero', 'listings', 'programs'] },
  { name: 'Your business', description: 'Introduce your business and help people reach you.', keys: ['about', 'blog', 'locations', 'contact'] },
]
const sectionTools = {
  hero: { icon: Images, to: '/banners', action: 'Edit banners' },
  listings: { icon: Dumbbell, to: '/listings', action: 'Manage listings' },
  programs: { icon: GraduationCap, to: '/memberships', action: 'Manage programs' },
  about: { icon: Store, page: 'about', action: 'Edit introduction' },
  blog: { icon: Newspaper, to: '/website-blog', action: 'Manage posts' },
  locations: { icon: MapPin, to: '/listings', action: 'Manage locations' },
  contact: { icon: Mail, page: 'contact', action: 'Edit contact details' },
}
const sectionCount = computed(() => WEBSITE_SECTIONS.filter(({ key }) => sections.value[key]).length)
const sectionByKey = key => WEBSITE_SECTIONS.find(section => section.key === key)
function sectionContentHint(key) {
  if (key === 'listings' && venuesCount.value === 0) return 'Add a listing for this section to appear.'
  if (key === 'programs' && programsCount.value === 0) return 'Add a program for this section to appear.'
  if (key === 'blog' && postsCount.value === 0) return 'Publish a post for this section to appear.'
  return ''
}

// How much content the provider has on their site — drives the "What shows on your site"
// section (each module shows live + a count, or a CTA to add the first one).
const venuesCount = ref(0)
const programsCount = ref(0)
const postsCount = ref(0)
async function loadModuleCounts() {
  if (!ownerId.value) { venuesCount.value = programsCount.value = postsCount.value = 0; return }
  try {
    const { counts } = await loadSiteSettings()
    venuesCount.value = counts.venues
    programsCount.value = counts.programs
    postsCount.value = counts.posts
  } catch { /* leave at 0 */ }
}

// Modules shown on the storefront, with their live state + where to add the first one.
const siteModules = computed(() => [
  { key: 'venues',   icon: Dumbbell,       name: 'Listings', count: venuesCount.value,
    desc: 'Customers browse your listings and book a time.', addTo: '/listings', addLabel: 'Add a listing' },
  { key: 'programs', icon: GraduationCap,  name: 'Memberships',   count: programsCount.value,
    desc: 'Gym, class & academy programs people subscribe to.', addTo: '/memberships', addLabel: 'Add a program' },
  { key: 'blog',     icon: Newspaper,      name: 'Blog',          count: postsCount.value,
    desc: 'Articles & updates that bring visitors to your site.', addTo: '/website-blog', addLabel: 'Write a post' },
])

// ── Simple site pages (About / Contact) — live once they have content ─────────
const pageModal = ref('')        // '' | 'about' | 'contact'
const pagesSaving = ref(false)
const pages = ref({
  home: { hero_image: '' },   // homepage default-hero background (edited next to the logo)
  about: { text: '', hero_title: '', hero_sub: '', hero_image: '' },
  contact: { phone: '', whatsapp: '', email: '', address: '', maps_url: '', hours: '', hero_title: '', hero_sub: '', hero_image: '' },
})
const sections = ref(readWebsiteSections())
const sectionsSaving = ref(false)
const sectionsVerification = ref('checking') // checking | applied | mismatch | unavailable
const sectionsMismatched = ref([])
const sectionsStatus = computed(() => {
  if (sectionsDirty.value) return 'Unsaved changes'
  if (sectionsVerification.value === 'checking') return 'Checking website…'
  if (sectionsVerification.value === 'applied') return 'Saved and applied'
  if (sectionsVerification.value === 'mismatch') return 'Saved · not applied to website'
  return 'Saved · website check unavailable'
})
const sectionsDirty = computed(() => {
  const saved = readWebsiteSections(pages.value)
  return WEBSITE_SECTIONS.some(({ key }) => sections.value[key] !== saved[key])
})
async function checkWebsiteSections() {
  const checkingOwnerId = ownerId.value
  if (!checkingOwnerId || isPlatformMode.value) return false
  sectionsVerification.value = 'checking'
  const publicSite = await loadPublicSitePages(checkingOwnerId)
  if (ownerId.value !== checkingOwnerId) return false
  if (!publicSite.success) {
    sectionsVerification.value = 'unavailable'
    return false
  }
  sectionsMismatched.value = websiteSectionMismatches(pages.value, publicSite.pages)
  sectionsVerification.value = sectionsMismatched.value.length ? 'mismatch' : 'applied'
  return sectionsVerification.value === 'applied'
}
// Read back the persisted flags before marking the draft saved. A backend that
// strips unknown page fields must not report a successful visibility change.
async function confirmSectionsSaved(nextPages) {
  const stored = await loadSiteSettings()
  const expected = readWebsiteSections(nextPages)
  const actual = readWebsiteSections(stored.settings?.site_pages)
  if (!stored.success || WEBSITE_SECTIONS.some(({ key }) => actual[key] !== expected[key])) {
    toast.error('Could not verify your section settings. Your changes are still here — please try saving again.')
    return false
  }
  pages.value = withWebsiteSections(pages.value, expected)
  iframeKey.value++
  if (!await checkWebsiteSections()) {
    toast.error(sectionsVerification.value === 'mismatch'
      ? 'Settings saved, but the website has not applied them. See Website sections for details.'
      : 'Settings saved. Could not verify the public website — please check again.')
    return false
  }
  return true
}
async function saveSections() {
  if (!ownerId.value || sectionsSaving.value || saving.value || pagesSaving.value || heroBgUploading.value) return
  sectionsSaving.value = true
  const nextPages = withWebsiteSections(pages.value, { ...sections.value })
  try {
    const res = await saveSiteSettings({ site_pages: nextPages })
    if (!res.success) { toast.error(res.message || 'Could not save sections.'); return }
    if (await confirmSectionsSaved(nextPages)) toast.success('Website sections saved.')
  } finally { sectionsSaving.value = false }
}
// Page-banner image upload (shared by both page modals)
const heroUploading = ref(false)
const heroInput = ref(null)
async function onHeroPick(e) {
  const file = e.target?.files?.[0]
  if (!file || !pageModal.value) return
  heroUploading.value = true
  const { url, error } = await uploadImage(file, 'brand')
  heroUploading.value = false
  if (error) { toast.error(error); return }
  pages.value[pageModal.value].hero_image = url
  if (heroInput.value) heroInput.value.value = ''
}
// Homepage hero background — lives with the logo/name because the DEFAULT hero
// is built entirely from these site settings (logo + background + name + tagline);
// the Home banners module only adds EXTRA slides after it. Saves instantly.
const heroBgInput = ref(null)
const heroBgUploading = ref(false)
async function onHeroBgPick(e) {
  if (pagesSaving.value || sectionsSaving.value || saving.value) return
  const file = e.target?.files?.[0]
  if (!file) return
  heroBgUploading.value = true
  const { url, error } = await uploadImage(file, 'brand')
  heroBgUploading.value = false
  if (error) { toast.error(error); return }
  pages.value.home.hero_image = url
  if (heroBgInput.value) heroBgInput.value.value = ''
  await saveHomeHero('Hero background saved — it\'s live on your homepage.')
}
async function removeHeroBg() {
  if (pagesSaving.value || sectionsSaving.value || saving.value || heroBgUploading.value) return
  pages.value.home.hero_image = ''
  await saveHomeHero('Hero background removed — your homepage uses your brand colors again.')
}
async function saveHomeHero(okMsg) {
  if (!ownerId.value) return
  pagesSaving.value = true
  try {
    const res = await saveSiteSettings({ site_pages: pages.value })
    if (!res.success) toast.error(res.message || 'Could not save.')
    else toast.success(okMsg)
  } finally { pagesSaving.value = false }
}

const aboutLive = computed(() => !!pages.value.about.text.trim())
const contactLive = computed(() => ['phone', 'whatsapp', 'email', 'address', 'maps_url', 'hours']
  .some(k => String(pages.value.contact[k] || '').trim()))
async function savePages() {
  if (!ownerId.value || pagesSaving.value || sectionsSaving.value || saving.value || heroBgUploading.value) return
  pagesSaving.value = true
  const res = await saveSiteSettings({ site_pages: pages.value })
  pagesSaving.value = false
  if (!res.success) { toast.error(res.message || 'Could not save.'); return }
  toast.success('Page saved — it\'s live on your site.')
  pageModal.value = ''
}

// Home hero banners now live on their own page (Banners.vue, /banners).

// ── load ────────────────────────────────────────────────────────────────────────
async function load() {
  loading.value = true
  sectionsVerification.value = 'checking'
  sectionsMismatched.value = []
  // Marketplace (platform) mode → Laravel settings (brand_* / admin_*).
  if (isPlatformMode.value) {
    const res = await fetchSettings()
    if (!res?.success) {
      toast.error(settingsError(res, 'Could not load branding.'))
      loading.value = false
      return
    }
    const m = res.data?.settings || {}
    f.brand_name     = m.brand_name    || 'Bookboll'
    f.brand_tagline  = m.brand_tagline || ''
    f.brand_logo_url = m.brand_logo_url || ''
    f.brand_primary  = m.brand_primary || DEFAULT_PRIMARY
    f.brand_accent   = m.brand_accent  || DEFAULT_ACCENT
    f.brand_meta_title       = m.brand_meta_title || ''
    f.brand_meta_description = m.brand_meta_description || ''
    f.admin_logo_url = m.admin_logo_url || ''
    f.admin_primary  = m.admin_primary || ''
    loading.value = false
    return
  }
  if (!ownerId.value) { loading.value = false; return }
  const { settings: data, username: uname } = await loadSiteSettings()
  if (data) {
    username.value = uname || ''
    f.brand_enabled   = !!data.brand_enabled
    f.brand_subdomain = (data.brand_subdomain || '').toLowerCase()
    // No web address chosen yet → auto-assign one derived from their username /
    // business name (and persist it), so every provider has a working address.
    // The API already falls back to the username, so an address always exists.
    if (!f.brand_subdomain) f.brand_subdomain = (uname || '').toLowerCase()
    f.brand_name      = data.brand_name || uname || ''
    f.brand_tagline   = data.brand_tagline || ''
    f.brand_logo_url  = data.brand_logo_url || ''
    f.brand_primary   = data.brand_primary || DEFAULT_PRIMARY
    f.brand_accent    = data.brand_accent || DEFAULT_ACCENT
    f.brand_show_venues   = data.brand_show_venues   !== false
    f.brand_show_events   = data.brand_show_events   !== false
    f.brand_show_programs = data.brand_show_programs !== false
    f.brand_meta_title       = data.brand_meta_title       || ''
    f.brand_meta_description = data.brand_meta_description || ''
    f.brand_custom_domain    = data.brand_custom_domain    || ''
    f.brand_domain_status    = data.brand_domain_status    || ''
    f.brand_domain_paid_at   = data.brand_domain_paid_at   || null
    f.brand_languages = (Array.isArray(data.brand_languages) ? data.brand_languages : [])
      .filter(c => SUPPORTED_LOCALES.some(l => l.code === c))
    f.brand_whitelabel = !!data.brand_whitelabel
    const sp = data.site_pages || {}
    const hero = (h) => ({ hero_title: String(h?.hero_title || ''), hero_sub: String(h?.hero_sub || ''), hero_image: String(h?.hero_image || '') })
    pages.value = {
      ...sp,
      home: { ...sp.home, hero_image: String(sp.home?.hero_image || '') },
      about: { ...sp.about, text: String(sp.about?.text || ''), ...hero(sp.about) },
      contact: {
        ...sp.contact,
        phone: String(sp.contact?.phone || ''), whatsapp: String(sp.contact?.whatsapp || ''),
        email: String(sp.contact?.email || ''), address: String(sp.contact?.address || ''),
        maps_url: String(sp.contact?.maps_url || ''), hours: String(sp.contact?.hours || ''),
        ...hero(sp.contact),
      },
    }
    sections.value = readWebsiteSections(pages.value)
    await checkWebsiteSections()
  }
  await loadModuleCounts()
  loading.value = false
}
onMounted(load)
watch(ownerId, load)

// ── web address (LOCKED) ─────────────────────────────────────────────────────────
// The subdomain is permanently bound to the business username, chosen once at signup —
// it is NOT editable here (avoids link rot on shared links/QRs and marketplace confusion).
// The only alternative address is a paid custom domain (block below). ensureProviderSubdomain
// (on load) still auto-fills it for accounts created before usernames existed.
const canSave = computed(() => isPlatformMode.value || !!ownerId.value)
const liveHost = computed(() => brandHost(f.brand_subdomain || username.value))

// ── storefront languages ─────────────────────────────────────────────────────
// Which languages the site's language switcher offers. None selected = all 17.
// English can't be removed — it's the source language every page starts from.
const langAll = computed(() => f.brand_languages.length === 0)
const langOptions = SUPPORTED_LOCALES.filter(l => l.code !== 'en').map(l => ({ value: l.code, label: l.label }))
// Compact pill labels (the two Chinese variants need disambiguating; the rest read fine as codes).
const LANG_SHORT = { 'zh-Hans': '中文', 'zh-Hant': '繁中' }
const langShort = (c) => LANG_SHORT[c] || c.toUpperCase()

// ── logo upload ───────────────────────────────────────────────────────────────
const logoInput = ref(null)
async function onLogoPick(e) {
  const file = e.target?.files?.[0]
  if (!file) return
  uploading.value = true
  const { url, error } = await uploadImage(file, 'brand')
  uploading.value = false
  if (error) { toast.error(error); return }
  f.brand_logo_url = url
  if (logoInput.value) logoInput.value.value = ''
}

// Admin-dashboard logo upload (platform mode). The whole card saves INSTANTLY on
// every change — upload, colour pick, remove, reset — so the dashboard chrome
// updates the moment you act (no separate Save step to forget).
const adminLogoInput = ref(null)
const adminUploading = ref(false)
let adminSaveTimer = null
function persistAdminBrand({ quiet = false } = {}) {
  clearTimeout(adminSaveTimer)
  adminSaveTimer = setTimeout(async () => {
    const ok = await saveAdminBrand({ logo: f.admin_logo_url, primary: f.admin_primary })
    if (!ok) toast.error('Could not save the dashboard branding.')
    else if (!quiet) toast.success('Dashboard branding updated.')
  }, 350)   // debounced: the custom colour input fires while dragging
}
async function onAdminLogoPick(e) {
  const file = e.target?.files?.[0]
  if (!file) return
  adminUploading.value = true
  const { url, error } = await uploadImage(file, 'brand')
  adminUploading.value = false
  if (error) { toast.error(error); return }
  f.admin_logo_url = url
  if (adminLogoInput.value) adminLogoInput.value.value = ''
  persistAdminBrand()
}
function removeAdminLogo() { f.admin_logo_url = ''; persistAdminBrand() }
function pickAdminPrimary(c) { f.admin_primary = c; persistAdminBrand() }
function resetAdminPrimary() { f.admin_primary = ''; persistAdminBrand() }

// ── save ──────────────────────────────────────────────────────────────────────
async function save() {
  if (saving.value || sectionsSaving.value || pagesSaving.value || heroBgUploading.value) return
  // Marketplace (platform) mode → Laravel settings + dual-write Supabase.
  if (isPlatformMode.value) {
    saving.value = true
    const rows = [
      { key: 'brand_name',     value: f.brand_name?.trim() || 'Bookboll' },
      { key: 'brand_tagline',  value: f.brand_tagline?.trim() || '' },
      { key: 'brand_logo_url', value: f.brand_logo_url || '' },
      { key: 'brand_primary',  value: f.brand_primary || DEFAULT_PRIMARY },
      { key: 'brand_accent',   value: f.brand_accent || DEFAULT_ACCENT },
      { key: 'brand_meta_title',       value: f.brand_meta_title?.trim() || '' },
      { key: 'brand_meta_description', value: f.brand_meta_description?.trim() || '' },
      { key: 'admin_logo_url', value: f.admin_logo_url || '' },
      { key: 'admin_primary',  value: f.admin_primary || '' },
    ]
    const res = await updateSettings(rows)
    saving.value = false
    if (!res?.success) {
      toast.error(settingsError(res, 'Could not save.'))
      return
    }
    // Re-apply live so the change reflects immediately (logo/name/colors in the
    // header + the marketplace theme) without needing a full page reload.
    await loadPlatformBrand()
    applyBrandTheme(platformBrand.value.primary, platformBrand.value.accent)
    // Admin-dashboard chrome updates live via the shared ref (sidebar/rail recolour).
    adminBrand.value = { logo: f.admin_logo_url || '', primary: f.admin_primary || '' }
    toast.success('Branding saved.')
    return
  }
  if (!ownerId.value) return
  saving.value = true
  // NOTE: brand_subdomain is deliberately NOT in the patch — the web address is locked
  // to the business username (set at signup / by ensureProviderSubdomain).
  const patch = {
    brand_enabled: f.brand_enabled,
    brand_name: f.brand_name?.trim() || null,
    brand_tagline: f.brand_tagline?.trim() || null,
    brand_logo_url: f.brand_logo_url || null,
    brand_primary: f.brand_primary || null,
    brand_accent: f.brand_accent || null,
    brand_show_venues: f.brand_show_venues,
    brand_show_events: f.brand_show_events,
    brand_show_programs: f.brand_show_programs,
    brand_meta_title: f.brand_meta_title?.trim() || null,
    brand_meta_description: f.brand_meta_description?.trim() || null,
    brand_whitelabel: !!f.brand_whitelabel,
  }
  // Languages go in the same save — there is no pre-migration column to dodge.
  patch.brand_languages = f.brand_languages?.length ? f.brand_languages : []
  const nextPages = sectionsDirty.value ? withWebsiteSections(pages.value, { ...sections.value }) : null
  if (nextPages) patch.site_pages = nextPages
  const res = await saveSiteSettings(patch)
  if (!res.success) { saving.value = false; toast.error(res.message || 'Could not save.'); return }
  if (nextPages && !await confirmSectionsSaved(nextPages)) { saving.value = false; return }
  saving.value = false
  toast.success('White-label site saved.')
}

// The address a customer would actually type: the custom domain once it's verified,
// otherwise the provider's own subdomain.
const liveUrl = computed(() => `https://${f.brand_domain_status === 'active' && f.brand_custom_domain ? f.brand_custom_domain : liveHost.value}`)

async function copyLink() {
  try { await navigator.clipboard.writeText(liveUrl.value); toast.success('Link copied.') }
  catch { toast.error('Could not copy — select and copy manually.') }
}
// The live preview resolves the provider by OWNER ID, so it always shows the right
// site even before a web address is chosen/saved (and never depends on wildcard DNS).
const previewById = computed(() => brandPreviewById(ownerId.value))
// Once the site is published AND wildcard subdomains resolve (i.e. not localhost /
// a dev tunnel), opening it should take the provider to their REAL address — handing
// them a `?bid=` test link there is confusing and not shareable. Everywhere else the
// preview URL is the only thing that loads, so keep it.
const canOpenLive = computed(() => !isPlatformMode.value && !!f.brand_enabled && wildcardDnsAvailable())
const openSiteLabel = computed(() => canOpenLive.value ? 'Open site' : 'Open preview')
function openPreview() {
  window.open(brandSiteUrl({
    slug: f.brand_subdomain || username.value,
    ownerId: ownerId.value,
    published: canOpenLive.value,
    customDomain: f.brand_domain_status === 'active' ? f.brand_custom_domain : '',
  }), '_blank')
}
function openMarketplace() { window.open(siteOrigin.value + '/', '_blank') }

// Custom domain setup moved to its own page (My Website → Custom domain, /website-domain).
// f.brand_custom_domain/status stay loaded here for the address CTA + SEO snippet.

// ── Preview iframe ──────────────────────────────────────────────────────────────
const iframeSrc = computed(() => isPlatformMode.value ? (siteOrigin.value + '/?preview=1') : previewById.value)
function reloadPreview() { iframeKey.value++ }

// ── SEO snippet preview ─────────────────────────────────────────────────────────
const seoTitle = computed(() => f.brand_meta_title?.trim() || f.brand_name?.trim() || (isPlatformMode.value ? 'Bookboll' : 'Your business'))
const seoDesc = computed(() => f.brand_meta_description?.trim() || f.brand_tagline?.trim() || 'Book courts, join games and find classes near you.')
const seoUrl = computed(() => isPlatformMode.value
  ? siteOrigin.value.replace(/^https?:\/\//, '')
  : (f.brand_domain_status === 'active' && f.brand_custom_domain ? f.brand_custom_domain : liveHost.value))

const previewNav = computed(() => [
  { icon: Home, label: 'Home' },
  f.brand_show_venues   && { icon: Dumbbell, label: 'Venues' },
  f.brand_show_events   && { icon: Trophy, label: 'Events' },
  f.brand_show_programs && { icon: GraduationCap, label: 'Memberships' },
].filter(Boolean))
function initials(n) { return (n || '?').trim().split(' ').map(s => s[0]).slice(0, 2).join('').toUpperCase() }
</script>

<template>
  <div class="page website-page">
    <div class="page-header">
      <div>
        <h1 class="page-title">{{ isPlatformMode ? 'Bookboll Marketplace' : 'My Website' }}</h1>
        <div class="page-subtitle">{{ isPlatformMode ? 'Manage the marketplace brand and appearance.' : 'Make your website feel like your business.' }}</div>
      </div>
      <div v-if="!loading && (ownerId || isPlatformMode)" class="wl-head-actions">
        <button v-if="!isPlatformMode" class="wl-btn" :class="f.brand_enabled ? 'wl-btn-ghost' : 'wl-btn-publish'" :disabled="publishing" @click="setPublished(!f.brand_enabled)">
          <Loader v-if="publishing" :size="15" class="spin" /><Globe2 v-else-if="!f.brand_enabled" :size="15" />
          {{ publishing ? '…' : (f.brand_enabled ? 'Unpublish' : 'Publish my site') }}
        </button>
        <button class="wl-btn wl-btn-primary" :disabled="saving || sectionsSaving || pagesSaving || heroBgUploading || !canSave" @click="save">
          <Loader v-if="saving" :size="15" class="spin" /><Check v-else :size="15" />
          {{ saving ? 'Saving…' : 'Save changes' }}
        </button>
      </div>
    </div>

    <!-- Non-super-admin staff with no provider context -->
    <div v-if="!ownerId && !isPlatformMode" class="wl-empty">
      <Globe2 :size="34" />
      <h3>Pick a provider to brand</h3>
      <p>White-label sites belong to a provider business. Switch to a provider with the role switcher (top bar) to set up their site.</p>
    </div>

    <div v-else-if="loading" class="wl-empty"><Loader :size="22" class="spin" /> Loading…</div>

    <div v-else class="wl-layout">
      <nav class="wl-tabs" aria-label="Website settings sections">
        <button v-for="tab in websiteTabs" :key="tab.key" type="button" :aria-pressed="websiteTab === tab.key"
          :class="{ active: websiteTab === tab.key }" @click="websiteTab = tab.key">
          <component :is="tab.icon" :size="17" />{{ tab.label }}
          <span v-if="tab.key === 'sections' && sectionsVerification === 'mismatch'" class="wl-tab-alert" title="Website settings need attention" aria-label="Needs attention">!</span>
        </button>
      </nav>
      <!-- ── Settings ── -->
      <div class="wl-col wl-settings" :class="`wl-tab-${websiteTab}`">
        <!-- Marketplace note (platform mode) -->
        <div v-if="isPlatformMode" v-show="websiteTab === 'branding'" class="wl-card">
          <div class="wl-card-title">Public marketplace</div>
          <div class="wl-card-sub">This is the main Bookboll site every customer sees by default. Your changes here apply to the whole marketplace.</div>
          <div class="wl-linkrow">
            <Globe2 :size="16" />
            <span class="wl-link">{{ siteOrigin }}</span>
            <button class="wl-icon-btn" title="Open site" @click="openMarketplace"><ExternalLink :size="15" /></button>
          </div>
        </div>

        <!-- Your website — status, address, publish, branding, domain. One clean
             column: rows separated by hairlines, no boxes-inside-boxes. -->
        <div v-if="!isPlatformMode" v-show="websiteTab === 'branding'" class="wl-card wl-site">
          <div class="wl-site-head">
            <div class="wl-card-title">Your website</div>
            <span class="wl-state" :class="{ on: f.brand_enabled }"><span class="wl-state-dot"></span>{{ f.brand_enabled ? 'Live' : 'Draft' }}</span>
          </div>

          <!-- The address, front and center -->
          <div class="wl-addr" data-no-translate>
            <Globe2 :size="16" class="wl-addr-ico" />
            <span class="wl-addr-host">{{ liveHost }}</span>
            <button class="wl-icon-btn" title="Copy link" @click="copyLink"><Copy :size="15" /></button>
            <button class="wl-icon-btn" :title="openSiteLabel" @click="openPreview"><ExternalLink :size="15" /></button>
          </div>

          <!-- Publishing lives with Save at the top & bottom of the page; the card
               only signals the state (Live/Draft pill + this hint while draft). -->
          <div v-if="!f.brand_enabled" class="wl-pub-hint">Only you can see it until you publish (top-right button). The preview always works, and you can unpublish anytime.</div>
          <div class="wl-hr"></div>

          <!-- Custom domain → its own page (right under the site address it replaces) -->
          <router-link to="/website-domain" class="wl-row wl-row-link">
            <div class="wl-row-txt">
              <div class="wl-row-name"><Link2 :size="14" /> {{ f.brand_custom_domain || 'Use your own domain' }}</div>
              <div class="wl-row-desc">{{ f.brand_custom_domain ? (f.brand_domain_status === 'active' ? 'Connected — manage it' : 'Pending DNS — finish setup') : 'e.g. book.myclub.com' }}</div>
            </div>
            <ArrowRight :size="15" class="wl-row-arrow" />
          </router-link>

          <div class="wl-hr"></div>

          <!-- White-label branding (Premium) — instant toggle -->
          <div class="wl-row">
            <div class="wl-row-txt">
              <div class="wl-row-name">White-label branding <em class="wl-row-name-sub">(remove Bookboll branding)</em> <span class="prem-lock">Premium</span></div>
              <div class="wl-row-desc">{{ f.brand_whitelabel ? 'Bookboll branding removed — only the small footer credit shows.' : 'Hide the “Powered by Bookboll” badge (a small footer credit remains).' }}</div>
            </div>
            <label class="wl-switch" :class="{ busy: wlbSaving }" @click.prevent="toggleWhitelabel">
              <input type="checkbox" :checked="f.brand_whitelabel" readonly />
              <span class="wl-slider"></span>
            </label>
          </div>
        </div>

        <!-- Identity -->
        <div v-show="websiteTab === 'branding'" class="wl-card wl-identity">
          <div class="wl-card-title">Logo &amp; name</div>

          <div class="wl-logo-row">
            <div class="wl-logo-prev" :class="{ img: f.brand_logo_url }" :style="f.brand_logo_url ? { backgroundImage: `url(${f.brand_logo_url})` } : {}">
              <span v-if="!f.brand_logo_url">{{ initials(f.brand_name) }}</span>
            </div>
            <div class="wl-logo-actions">
              <button class="wl-btn wl-btn-soft" :disabled="uploading" @click="logoInput?.click()">
                <Loader v-if="uploading" :size="15" class="spin" /><Upload v-else :size="15" />
                {{ uploading ? 'Uploading…' : (f.brand_logo_url ? 'Replace logo' : 'Upload logo') }}
              </button>
              <button v-if="f.brand_logo_url" class="wl-btn wl-btn-ghost" @click="f.brand_logo_url = ''">Remove</button>
              <input ref="logoInput" type="file" accept="image/*" hidden @change="onLogoPick" />
              <div v-if="!imageUploadConfigured()" class="wl-warn">Image upload isn’t available right now.</div>
            </div>
          </div>

          <!-- Homepage hero background — the default hero uses logo + this image +
               name + tagline; extra banners come from Home banners. -->
          <div v-if="!isPlatformMode" class="wl-herobg">
            <div class="wl-herobg-prev" :class="{ img: pages.home.hero_image }"
                 :style="pages.home.hero_image ? { backgroundImage: `url(${pages.home.hero_image})` } : {}">
              <span v-if="!pages.home.hero_image">Brand colors</span>
            </div>
            <div class="wl-logo-actions">
              <div class="wl-herobg-label">Homepage hero background <em>(optional)</em></div>
              <div class="wl-herobg-hint">Shown behind your name &amp; tagline at the top of your homepage. Without one, your brand colors are used.</div>
              <div class="wl-herobg-btns">
                <button class="wl-btn wl-btn-soft" :disabled="heroBgUploading || pagesSaving || sectionsSaving || saving" @click="heroBgInput?.click()">
                  <Loader v-if="heroBgUploading" :size="15" class="spin" /><Upload v-else :size="15" />
                  {{ heroBgUploading ? 'Uploading…' : (pages.home.hero_image ? 'Replace image' : 'Upload image') }}
                </button>
                <button v-if="pages.home.hero_image" class="wl-btn wl-btn-ghost" :disabled="heroBgUploading || pagesSaving || sectionsSaving || saving" @click="removeHeroBg">Remove</button>
              </div>
              <input ref="heroBgInput" type="file" accept="image/*" hidden @change="onHeroBgPick" />
            </div>
          </div>

          <label class="wl-field">
            <span class="wl-label">{{ isPlatformMode ? 'Marketplace name' : 'Business name' }}</span>
            <input class="wl-input" v-model="f.brand_name" :placeholder="isPlatformMode ? 'e.g. Bookboll' : 'e.g. Cairo Elite Sports'" maxlength="60" />
          </label>
          <label class="wl-field">
            <span class="wl-label">Tagline <em>(optional)</em></span>
            <input class="wl-input" v-model="f.brand_tagline" placeholder="e.g. Book courts &amp; join games in Cairo" maxlength="90" />
          </label>
        </div>

        <!-- Colors -->
        <div v-show="websiteTab === 'branding'" class="wl-card wl-palette">
          <div class="wl-card-title">Brand colors</div>
          <template v-if="!isPlatformMode">
            <p class="wl-card-sub">Try Qatar’s burgundy, desert sands or oasis greens. You can also use your own colours.</p>
            <div class="wl-brand-presets" aria-label="Brand colour palettes">
              <button v-for="palette in BRAND_PALETTES" :key="palette.name" type="button"
                :aria-pressed="f.brand_primary === palette.primary && f.brand_accent === palette.accent"
                @click="f.brand_primary = palette.primary; f.brand_accent = palette.accent">
                <span :style="{ background: palette.primary }"></span><span :style="{ background: palette.accent }"></span>{{ palette.name }}
              </button>
            </div>
          </template>
          <div class="wl-colors">
            <div class="wl-color">
              <span class="wl-label">Primary</span>
              <div class="wl-color-row">
                <input type="color" class="wl-swatch" v-model="f.brand_primary" />
                <input class="wl-input wl-hex" v-model="f.brand_primary" spellcheck="false" maxlength="7" />
              </div>
            </div>
            <div class="wl-color">
              <span class="wl-label">Accent</span>
              <div class="wl-color-row">
                <input type="color" class="wl-swatch" v-model="f.brand_accent" />
                <input class="wl-input wl-hex" v-model="f.brand_accent" spellcheck="false" maxlength="7" />
              </div>
            </div>
          </div>
          <button class="wl-btn wl-btn-ghost wl-reset" @click="f.brand_primary = DEFAULT_PRIMARY; f.brand_accent = DEFAULT_ACCENT">Reset to Bookboll teal</button>
        </div>

        <!-- ── Admin dashboard branding (platform mode) — the INTERNAL back-office
             chrome, separate from the marketplace brand above. -->
        <div v-if="isPlatformMode" v-show="websiteTab === 'branding'" class="wl-card wl-admincard">
          <div class="wl-card-title"><LayoutDashboard :size="14" style="vertical-align:-2px" /> Admin dashboard</div>
          <div class="wl-card-sub">The logo &amp; accent colour of the internal dashboard your team uses — separate from the customer marketplace above. Changes here apply instantly.</div>

          <!-- Dark preview of the dashboard chrome -->
          <div class="wl-admin-prev" :style="{ '--adm': adminActivePrimary }">
            <div class="wl-admin-mark" :class="{ img: f.admin_logo_url }"
                 :style="f.admin_logo_url ? { backgroundImage: `url(${f.admin_logo_url})` } : {}">
              <BrandMark v-if="!f.admin_logo_url" />
            </div>
            <div class="wl-admin-txt"><b>Bookboll</b><span>Admin Portal</span></div>
            <span class="wl-admin-btn">Button</span>
          </div>

          <!-- Logo -->
          <div class="wl-logo-row" style="margin-top:14px;">
            <div class="wl-logo-prev" :class="{ img: f.admin_logo_url }"
                 :style="f.admin_logo_url ? { backgroundImage: `url(${f.admin_logo_url})` } : { background: `linear-gradient(135deg, color-mix(in srgb, ${adminActivePrimary}, #fff 18%), ${adminActivePrimary})` }">
              <BrandMark v-if="!f.admin_logo_url" />
            </div>
            <div class="wl-logo-actions">
              <button class="wl-btn wl-btn-soft" :disabled="adminUploading" @click="adminLogoInput?.click()">
                <Loader v-if="adminUploading" :size="15" class="spin" /><Upload v-else :size="15" />
                {{ adminUploading ? 'Uploading…' : (f.admin_logo_url ? 'Replace logo' : 'Upload logo') }}
              </button>
              <button v-if="f.admin_logo_url" class="wl-btn wl-btn-ghost" @click="removeAdminLogo">Remove</button>
              <input ref="adminLogoInput" type="file" accept="image/*" hidden @change="onAdminLogoPick" />
              <div class="wl-admin-hint">Square image. Empty = the built-in Bookboll mark.</div>
            </div>
          </div>

          <!-- Accent colour: presets + custom -->
          <div class="wl-admin-colors">
            <span class="wl-label">Accent colour</span>
            <div class="wl-admin-swatches">
              <button v-for="c in ADMIN_PRESETS" :key="c" type="button" class="wl-admin-sw"
                      :class="{ on: adminActivePrimary.toLowerCase() === c.toLowerCase() }"
                      :style="{ background: c }" @click="pickAdminPrimary(c)">
                <Check v-if="adminActivePrimary.toLowerCase() === c.toLowerCase()" :size="14" />
              </button>
              <label class="wl-admin-custom" title="Custom colour">
                <input type="color" :value="adminActivePrimary" @input="f.admin_primary = $event.target.value; persistAdminBrand({ quiet: true })" @change="persistAdminBrand()" />
                <span :style="{ background: adminActivePrimary }"></span>
              </label>
            </div>
          </div>
          <button class="wl-btn wl-btn-ghost wl-reset" @click="resetAdminPrimary">Reset dashboard colour</button>
        </div>

        <!-- Home banners moved to their own page (My Website → Home banners / /banners) -->

        <section v-if="!isPlatformMode" v-show="websiteTab === 'sections'" class="wl-card wl-sections" aria-labelledby="website-sections-title">
          <div class="wl-sections-heading"><div><h2 id="website-sections-title" class="wl-card-title">Build your homepage</h2><p class="wl-card-sub">Choose what to show, then add or edit its content.</p></div><span>{{ sectionCount }} of {{ WEBSITE_SECTIONS.length }} enabled</span></div>
          <div class="wl-section-groups">
          <section v-for="group in sectionGroups" :key="group.name" class="wl-section-group">
            <header><h3>{{ group.name }}</h3><p>{{ group.description }}</p></header>
            <div class="wl-section-list">
              <div v-for="key in group.keys" :key="key" class="wl-section-row" :class="{ 'is-hidden': !sections[key] }">
                <span class="wl-section-icon"><component :is="sectionTools[key].icon" :size="20" /></span>
                <span class="wl-section-copy">
                  <label class="wl-section-name" :for="`section-switch-${key}`" :id="`section-name-${key}`">{{ sectionByKey(key).name }}</label>
                  <span class="wl-section-desc" :id="`section-desc-${key}`">{{ sectionByKey(key).description }}</span>
                  <span v-if="sections[key] && sectionContentHint(key)" class="wl-section-content-hint">{{ sectionContentHint(key) }}</span>
                  <router-link v-if="sectionTools[key].to" :to="sectionTools[key].to" class="wl-section-edit">{{ sectionTools[key].action }}<ArrowRight :size="12" /></router-link>
                  <button v-else type="button" class="wl-section-edit" @click="pageModal = sectionTools[key].page">{{ sectionTools[key].action }}<ArrowRight :size="12" /></button>
                </span>
                <label class="wl-section-control" :for="`section-switch-${key}`">
                  <span class="wl-section-state">{{ sections[key] ? 'Enabled' : 'Hidden' }}</span>
                  <span class="wl-section-switch">
                    <input :id="`section-switch-${key}`" type="checkbox" role="switch" v-model="sections[key]"
                      :aria-labelledby="`section-name-${key}`" :aria-describedby="`section-desc-${key}`"
                      :disabled="sectionsSaving || saving || pagesSaving || heroBgUploading" />
                    <span aria-hidden="true"></span>
                  </span>
                </label>
              </div>
            </div>
          </section>
          </div>
          <p class="wl-hint">Hidden sections keep their content. Enabled sections appear once you add content. These switches do not remove your booking pages or venue details.</p>
          <div v-if="sectionsVerification === 'mismatch' || sectionsVerification === 'unavailable'" class="wl-section-notice" role="alert">
            <strong>{{ sectionsVerification === 'mismatch' ? 'Your website has not applied these settings' : 'We could not check your website' }}</strong>
            <p v-if="sectionsVerification === 'mismatch'">Your choices are saved, but these sections still have different visibility on the website: {{ sectionsMismatched.map(section => section.name).join(', ') }}. Bookboll needs to update the website settings connection.</p>
            <p v-else>Your choices are saved. Check again to confirm what visitors see.</p>
            <button type="button" class="wl-btn wl-btn-ghost" :disabled="sectionsSaving || saving" @click="checkWebsiteSections">Check website again</button>
          </div>
          <div class="wl-section-actions">
            <span role="status">{{ sectionsStatus }}</span>
            <button type="button" class="wl-btn wl-btn-primary" :disabled="!sectionsDirty || sectionsSaving || saving || pagesSaving || heroBgUploading" @click="saveSections">
              <Loader v-if="sectionsSaving" :size="15" class="spin" /><Check v-else :size="15" />
              {{ sectionsSaving ? 'Saving…' : 'Save sections' }}
            </button>
          </div>
        </section>

        <!-- Content management is separate from homepage visibility. -->
        <div v-if="!isPlatformMode" v-show="websiteTab === 'content'" class="wl-card wl-content">
          <div class="wl-card-title"><Sparkles :size="14" style="vertical-align:-2px" /> Website content</div>
          <div class="wl-card-sub">Manage your pages, listings and updates. Use Website sections to control their homepage visibility.</div>
          <div class="wl-mods">
            <!-- Simple pages: live as soon as they have content, edited right here -->
            <div class="wl-mod2" :class="{ on: aboutLive }">
              <span class="wl-mod-ico"><Newspaper :size="16" /></span>
              <div class="wl-mod-body">
                <div class="wl-mod-name">About us
                  <span v-if="aboutLive" class="wl-mod-on"><CircleCheck :size="12" /> Page ready</span>
                  <span v-else class="wl-mod-off">Not showing yet</span>
                </div>
                <div class="wl-mod-desc">A few lines about your business.</div>
              </div>
              <button type="button" class="wl-mod-add" :class="{ ghost: aboutLive }" @click="pageModal = 'about'">
                <Plus v-if="!aboutLive" :size="13" /> {{ aboutLive ? 'Edit' : 'Write it' }}
              </button>
            </div>
            <div class="wl-mod2" :class="{ on: contactLive }">
              <span class="wl-mod-ico"><Store :size="16" /></span>
              <div class="wl-mod-body">
                <div class="wl-mod-name">Contact us
                  <span v-if="contactLive" class="wl-mod-on"><CircleCheck :size="12" /> Page ready</span>
                  <span v-else class="wl-mod-off">Not showing yet</span>
                </div>
                <div class="wl-mod-desc">Phone, WhatsApp, email &amp; directions.</div>
              </div>
              <button type="button" class="wl-mod-add" :class="{ ghost: contactLive }" @click="pageModal = 'contact'">
                <Plus v-if="!contactLive" :size="13" /> {{ contactLive ? 'Edit' : 'Add details' }}
              </button>
            </div>
            <div v-for="m in siteModules" :key="m.key" class="wl-mod2" :class="{ on: m.count > 0 }">
              <span class="wl-mod-ico"><component :is="m.icon" :size="16" /></span>
              <div class="wl-mod-body">
                <div class="wl-mod-name">
                  {{ m.name }}
                  <span v-if="m.count > 0" class="wl-mod-on"><CircleCheck :size="12" /> {{ m.count }} added</span>
                  <span v-else class="wl-mod-off">Not showing yet</span>
                </div>
                <div class="wl-mod-desc">{{ m.desc }}</div>
              </div>
              <router-link :to="m.addTo" class="wl-mod-add" :class="{ ghost: m.count > 0 }">
                <Plus :size="13" /> {{ m.count > 0 ? 'Add more' : m.addLabel }}
              </router-link>
            </div>
          </div>
        </div>

        <!-- Languages (provider storefront only) — one dropdown + compact code pills -->
        <div v-if="!isPlatformMode" v-show="websiteTab === 'settings'" class="wl-card">
          <div class="wl-card-title"><Languages :size="14" style="vertical-align:-2px" /> Site languages</div>
          <div class="wl-card-sub">Pick the languages your site offers — leave empty to offer all {{ SUPPORTED_LOCALES.length }}. Everything is translated automatically.</div>
          <div class="wl-lang-row" data-no-translate>
            <MultiSelect v-model="f.brand_languages" :options="langOptions" placeholder="All languages (default)" :max-display="2" />
          </div>
          <div class="wl-lang-minis" data-no-translate>
            <span class="wl-lang-mini locked" title="English is always available">EN</span>
            <span v-for="c in f.brand_languages" :key="c" class="wl-lang-mini">{{ langShort(c) }}</span>
            <span v-if="langAll" class="wl-lang-mini all">+ all {{ SUPPORTED_LOCALES.length - 1 }} more</span>
          </div>
        </div>

        <!-- SEO -->
        <div v-show="websiteTab === 'settings'" class="wl-card">
          <div class="wl-card-title"><Search :size="14" style="vertical-align:-2px" /> Search engine listing (SEO)</div>
          <div class="wl-card-sub">How your {{ isPlatformMode ? 'marketplace' : 'site' }} appears in Google. Leave blank to use your name &amp; tagline.</div>
          <label class="wl-field">
            <span class="wl-label">Meta title <em>{{ (f.brand_meta_title || '').length }}/60</em></span>
            <input class="wl-input" v-model="f.brand_meta_title" maxlength="60" placeholder="e.g. Cairo Elite Sports — Book courts &amp; classes" />
          </label>
          <label class="wl-field">
            <span class="wl-label">Meta description <em>{{ (f.brand_meta_description || '').length }}/160</em></span>
            <textarea class="wl-input wl-textarea" v-model="f.brand_meta_description" maxlength="160" rows="2" placeholder="A short sentence describing your site for search results."></textarea>
          </label>
          <div class="seo-snippet">
            <div class="seo-snip-url">{{ seoUrl }}</div>
            <div class="seo-snip-title">{{ seoTitle }}</div>
            <div class="seo-snip-desc">{{ seoDesc }}</div>
          </div>
        </div>

        <!-- Custom domain now lives on its own page: My Website → Custom domain (/website-domain) -->

        <div v-show="websiteTab === 'branding' || websiteTab === 'settings'" class="wl-save">
          <button v-if="!isPlatformMode" class="wl-btn" :class="f.brand_enabled ? 'wl-btn-ghost' : 'wl-btn-publish'" :disabled="publishing" @click="setPublished(!f.brand_enabled)">
            <Loader v-if="publishing" :size="16" class="spin" /><Globe2 v-else-if="!f.brand_enabled" :size="16" />
            {{ publishing ? '…' : (f.brand_enabled ? 'Unpublish site' : 'Publish my site') }}
          </button>
          <button class="wl-btn wl-btn-primary" :disabled="saving || sectionsSaving || pagesSaving || heroBgUploading || !canSave" @click="save">
            <Loader v-if="saving" :size="16" class="spin" /><Check v-else :size="16" />
            {{ saving ? 'Saving…' : 'Save changes' }}
          </button>
        </div>
      </div>

      <!-- ── Live preview (real storefront, at the bottom) ── -->
      <section class="wl-preview-bottom" aria-labelledby="site-preview-title">
        <div class="wl-preview-head">
          <div>
            <h2 id="site-preview-title" class="wl-preview-label">Live preview</h2>
            <p class="wl-preview-description">Your saved {{ isPlatformMode ? 'marketplace' : 'website' }}. Save changes, then refresh to see the latest version.</p>
          </div>
          <div class="wl-preview-tools">
            <div class="wl-device-toggle">
              <button type="button" :class="{ on: previewDevice === 'desktop' }" :aria-pressed="previewDevice === 'desktop'" @click="previewDevice = 'desktop'" title="Desktop preview"><Monitor :size="16" /><span>Desktop</span></button>
              <button type="button" :class="{ on: previewDevice === 'mobile' }" :aria-pressed="previewDevice === 'mobile'" @click="previewDevice = 'mobile'" title="Mobile preview"><Smartphone :size="16" /><span>Mobile</span></button>
            </div>
            <button class="wl-icon-btn" title="Reload preview" @click="reloadPreview"><RefreshCw :size="15" /></button>
            <button class="wl-icon-btn" :title="isPlatformMode ? 'Open site' : openSiteLabel" @click="isPlatformMode ? openMarketplace() : openPreview()"><ExternalLink :size="15" /></button>
          </div>
        </div>
        <div class="wl-frame-wrap" :class="previewDevice">
          <iframe :key="iframeKey" :src="iframeSrc" class="wl-frame" title="Storefront preview" loading="eager"></iframe>
        </div>
      </section>
    </div>

    <!-- About page editor — one box of text, that's the whole setup -->
    <div v-if="pageModal === 'about'" class="modal-backdrop" @click.self="pageModal = ''">
      <div class="modal wl-page-modal" v-dialog="() => pageModal = ''">
        <div class="modal-header"><div class="modal-title">About us</div>
          <button class="wl-icon-btn" @click="pageModal = ''" aria-label="Close"><X :size="16" /></button></div>
        <div class="modal-body">
          <p class="wl-pm-hint">Tell customers who you are — 2 or 3 short paragraphs is perfect. It goes live the moment you save.</p>
          <textarea v-model="pages.about.text" rows="8" aria-label="About your business" class="wl-input wl-textarea"
            placeholder="We're a family-run sports club in Doha, open since 2015…"></textarea>

          <!-- Page banner (optional) — shared shape for both page modals -->
          <div class="wl-pm-hero">
            <div class="wl-pm-hero-head">Page banner <em>optional — a colored banner shows by default</em></div>
            <div class="wl-pm-grid">
              <label class="wl-field"><span class="wl-label">Banner title</span><input class="wl-input" v-model="pages[pageModal].hero_title" :placeholder="pageModal === 'about' ? `About ${f.brand_name || 'us'}` : 'Get in Touch'" maxlength="60" /></label>
              <label class="wl-field"><span class="wl-label">Banner subtitle</span><input class="wl-input" v-model="pages[pageModal].hero_sub" placeholder="One friendly line under the title" maxlength="120" /></label>
            </div>
            <div class="wl-pm-hero-img">
              <span v-if="pages[pageModal].hero_image" class="wl-pm-hero-thumb" :style="{ backgroundImage: `url(${pages[pageModal].hero_image})` }"></span>
              <button type="button" class="wl-btn wl-btn-soft" :disabled="heroUploading" @click="heroInput?.click()">
                <Loader v-if="heroUploading" :size="14" class="spin" /><Upload v-else :size="14" />
                {{ heroUploading ? 'Uploading…' : (pages[pageModal].hero_image ? 'Replace image' : 'Add background image') }}
              </button>
              <button v-if="pages[pageModal].hero_image" type="button" class="wl-btn wl-btn-ghost" @click="pages[pageModal].hero_image = ''">Remove</button>
              <input ref="heroInput" type="file" accept="image/*" hidden @change="onHeroPick" />
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" @click="pageModal = ''">Cancel</button>
          <button class="btn btn-primary" :disabled="pagesSaving" @click="savePages"><Check :size="14" />{{ pagesSaving ? 'Saving…' : 'Save' }}</button>
        </div>
      </div>
    </div>

    <!-- Contact page editor — fill any field; empty ones simply don't show -->
    <div v-if="pageModal === 'contact'" class="modal-backdrop" @click.self="pageModal = ''">
      <div class="modal wl-page-modal" v-dialog="() => pageModal = ''">
        <div class="modal-header"><div class="modal-title">Contact us</div>
          <button class="wl-icon-btn" @click="pageModal = ''" aria-label="Close"><X :size="16" /></button></div>
        <div class="modal-body">
          <p class="wl-pm-hint">Fill in what you have — empty fields just don't appear on the page.</p>
          <div class="wl-pm-grid">
            <label class="wl-field"><span class="wl-label">Phone</span><input class="wl-input" v-model="pages.contact.phone" placeholder="+974 5555 5555" /></label>
            <label class="wl-field"><span class="wl-label">WhatsApp</span><input class="wl-input" v-model="pages.contact.whatsapp" placeholder="+974 5555 5555" /></label>
            <label class="wl-field"><span class="wl-label">Email</span><input class="wl-input" v-model="pages.contact.email" placeholder="hello@yourclub.com" /></label>
            <label class="wl-field"><span class="wl-label">Opening hours</span><input class="wl-input" v-model="pages.contact.hours" placeholder="Daily 7 AM – 11 PM" /></label>
            <label class="wl-field wl-pm-wide"><span class="wl-label">Address</span><input class="wl-input" v-model="pages.contact.address" placeholder="Street, area, city" /></label>
            <label class="wl-field wl-pm-wide"><span class="wl-label">Google Maps link <em>(optional)</em></span><input class="wl-input" v-model="pages.contact.maps_url" placeholder="https://maps.app.goo.gl/…" /></label>
          </div>

          <!-- Page banner (optional) — shared shape for both page modals -->
          <div class="wl-pm-hero">
            <div class="wl-pm-hero-head">Page banner <em>optional — a colored banner shows by default</em></div>
            <div class="wl-pm-grid">
              <label class="wl-field"><span class="wl-label">Banner title</span><input class="wl-input" v-model="pages[pageModal].hero_title" :placeholder="pageModal === 'about' ? `About ${f.brand_name || 'us'}` : 'Get in Touch'" maxlength="60" /></label>
              <label class="wl-field"><span class="wl-label">Banner subtitle</span><input class="wl-input" v-model="pages[pageModal].hero_sub" placeholder="One friendly line under the title" maxlength="120" /></label>
            </div>
            <div class="wl-pm-hero-img">
              <span v-if="pages[pageModal].hero_image" class="wl-pm-hero-thumb" :style="{ backgroundImage: `url(${pages[pageModal].hero_image})` }"></span>
              <button type="button" class="wl-btn wl-btn-soft" :disabled="heroUploading" @click="heroInput?.click()">
                <Loader v-if="heroUploading" :size="14" class="spin" /><Upload v-else :size="14" />
                {{ heroUploading ? 'Uploading…' : (pages[pageModal].hero_image ? 'Replace image' : 'Add background image') }}
              </button>
              <button v-if="pages[pageModal].hero_image" type="button" class="wl-btn wl-btn-ghost" @click="pages[pageModal].hero_image = ''">Remove</button>
              <input ref="heroInput" type="file" accept="image/*" hidden @change="onHeroPick" />
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" @click="pageModal = ''">Cancel</button>
          <button class="btn btn-primary" :disabled="pagesSaving" @click="savePages"><Check :size="14" />{{ pagesSaving ? 'Saving…' : 'Save' }}</button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.wl-empty { display: flex; flex-direction: column; align-items: center; gap: 10px; text-align: center; padding: 60px 24px; color: var(--text-muted); background: var(--surface, #ffffff); border: 1px solid var(--border); border-radius: 16px; }
.wl-empty h3 { margin: 4px 0 0; color: var(--text); font-size: 17px; }
.wl-empty p { max-width: 420px; margin: 0; font-size: 13.5px; line-height: 1.5; }
.spin { animation: wlspin 1s linear infinite; }
@keyframes wlspin { to { transform: rotate(360deg); } }

.wl-layout { display: flex; flex-direction: column; gap: 18px; }
.wl-col { min-width: 0; }
/* Settings cards flow into balanced columns — fills the horizontal space and
   roughly halves the page height instead of one tall 760px stack. */
.wl-settings { width: 100%; columns: 360px 3; column-gap: 16px; }
.wl-settings > .wl-card { break-inside: avoid; margin: 0 0 16px; }
.wl-settings > .wl-save { break-inside: avoid; column-span: all; margin-top: 2px; }

/* SEO snippet preview */
.wl-input.wl-textarea { height: auto; min-height: 100px; padding: 10px 12px; resize: vertical; line-height: 1.45; font-family: inherit; }
.seo-snippet { margin-top: 14px; padding: 12px 14px; border: 1px solid var(--border); border-radius: 10px; background: var(--bg); }
.seo-snip-url { font-size: 12px; color: var(--text-muted); }
.seo-snip-title { font-size: 16px; color: var(--primary-ink); font-weight: 500; margin-top: 2px; line-height: 1.3; }
.seo-snip-desc { font-size: 12.5px; color: var(--text-secondary); margin-top: 3px; line-height: 1.45; }

/* Custom domain */
.dom-row { display: flex; align-items: center; gap: 8px; margin-top: 14px; padding: 10px 12px; background: var(--bg); border: 1px solid var(--border); border-radius: 10px; }
.dom-badge { display: inline-flex; align-items: center; gap: 4px; font-size: 11.5px; font-weight: 700; padding: 3px 9px; border-radius: 999px; }
.dom-badge.ok { background: var(--positive-bg, #dcfce7); color: var(--positive-ink, #15803d); }
.dom-badge.pending { background: var(--info-bg, #dbeafe); color: var(--info-ink, #1d4ed8); }
.dns-box { margin-top: 12px; padding: 12px 14px; border: 1px dashed var(--border); border-radius: 10px; background: var(--surface, #ffffff); }
.dns-title { font-size: 12.5px; font-weight: 600; color: var(--text); margin-bottom: 8px; }
.dns-rec { display: flex; gap: 10px; font-size: 13px; padding: 3px 0; }
.dns-rec span { width: 52px; color: var(--text-muted); font-size: 12px; }
.dns-rec b { font-family: 'SF Mono', Monaco, monospace; font-size: 12.5px; color: var(--text); }
.wl-save-inline { margin-top: 12px; }

/* Live preview (real iframe) */
.wl-preview-bottom { background: var(--surface, #ffffff); border: 1px solid var(--border); border-radius: 16px; padding: 16px; }
.wl-preview-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; gap: 10px; flex-wrap: wrap; }
.wl-preview-tools { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.wl-preview-description { margin: 6px 0 0; max-width: 65ch; font-size: 13px; line-height: 1.5; color: var(--text-muted); }
.wl-preview-head .wl-preview-label { margin: 0; }
.wl-preview-tools .wl-icon-btn { width: 40px; height: 40px; }
.wl-device-toggle { display: inline-flex; border: 1px solid var(--border); border-radius: 8px; overflow: hidden; }
.wl-device-toggle button { min-width: 40px; min-height: 40px; padding: 8px 12px; gap: 7px; display: flex; align-items: center; justify-content: center; background: var(--surface, #ffffff); color: var(--text-muted); border: none; cursor: pointer; font-size: 12px; font-weight: 600; }
.wl-device-toggle button.on { background: var(--primary); color: #fff; }
.wl-frame-wrap { background: var(--bg, #f1f5f9); border: 1px solid var(--border); border-radius: 12px; padding: 12px; display: flex; justify-content: center; }
.wl-frame-wrap.desktop .wl-frame { width: 100%; height: clamp(680px, 78vh, 960px); }
.wl-frame-wrap.mobile .wl-frame { width: 390px; max-width: 100%; height: 844px; border-radius: 22px; box-shadow: 0 6px 24px rgba(0,0,0,.12); }
.wl-frame { display: block; min-width: 0; box-sizing: border-box; border: 1px solid var(--border); border-radius: 10px; background: var(--surface, #ffffff); }
@media (max-width: 480px) { .wl-device-toggle button span { display: none; } }

.wl-card { background: var(--surface, #ffffff); border: 1px solid var(--border); border-radius: 14px; padding: 18px; }
.wl-card-title { font-size: 14.5px; font-weight: 800; color: var(--text); }
.wl-card-sub { font-size: 12.5px; color: var(--text-muted); margin-top: 2px; }
.wl-sections h2 { margin: 0; }
.wl-sections > p { line-height: 1.6; }
.wl-section-list { margin-top: 14px; }
.wl-section-row { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 12px 0; border-bottom: 1px solid var(--border); cursor: pointer; }
.wl-section-copy { display: grid; gap: 4px; min-width: 0; }
.wl-section-name { font-size: 13.5px; font-weight: 700; color: var(--text); }
.wl-section-desc { font-size: 12px; line-height: 1.5; color: var(--text-muted); }
.wl-section-control { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
.wl-section-state { font-size: 11.5px; color: var(--text-muted); }
.wl-section-switch { position: relative; display: grid; place-items: center; width: 44px; height: 44px; }
.wl-section-switch input { position: absolute; inset: 0; width: 100%; height: 100%; margin: 0; opacity: 0; cursor: pointer; }
.wl-section-switch > span { pointer-events: none; width: 42px; height: 24px; border-radius: 20px; background: var(--text-muted); position: relative; }
.wl-section-switch > span::before { content: ''; position: absolute; top: 3px; left: 3px; width: 18px; height: 18px; border-radius: 50%; background: #fff; transition: transform .15s; }
.wl-section-switch input:checked + span { background: #0f766e; }
.wl-section-switch input:checked + span::before { transform: translateX(18px); }
.wl-section-switch input:focus-visible + span { outline: 2px solid var(--text); outline-offset: 4px; }
.wl-section-switch input:disabled + span { opacity: .5; }
.wl-section-actions { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-top: 16px; }
.wl-section-actions > span { font-size: 12px; color: var(--text-muted); }
.wl-section-notice { margin-top: 14px; padding: 14px; border: 1px solid var(--border); border-inline-start: 3px solid #d97706; border-radius: 8px; background: var(--bg); color: var(--text); font-size: 12.5px; line-height: 1.6; }
.wl-section-notice p { margin: 5px 0 10px; color: var(--text-muted); }
@media (max-width: 480px) { .wl-section-row { gap: 10px; } .wl-section-state { display: none; } }

.wl-status { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.wl-switch { position: relative; display: inline-block; width: 46px; height: 26px; flex-shrink: 0; }
.wl-switch input { opacity: 0; width: 0; height: 0; }
.wl-slider { position: absolute; inset: 0; background: #cbd5e1; border-radius: 999px; transition: .2s; }
.wl-slider::before { content: ''; position: absolute; height: 20px; width: 20px; left: 3px; top: 3px; background: #fff; border-radius: 50%; transition: .2s; }
.wl-switch input:checked + .wl-slider { background: #0f766e; }
.wl-switch input:checked + .wl-slider::before { transform: translateX(20px); }

.wl-linkrow { display: flex; align-items: center; gap: 8px; margin-top: 14px; padding: 10px 12px; background: var(--bg); border: 1px solid var(--border); border-radius: 10px; color: var(--text-muted); }
.wl-link { flex: 1; min-width: 0; font-weight: 700; color: var(--text); font-size: 13.5px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.wl-icon-btn { width: 32px; height: 32px; border: 1px solid var(--border); background: var(--surface, #ffffff); border-radius: 8px; color: var(--text-muted); cursor: pointer; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.wl-icon-btn:hover { border-color: #14b8a6; color: var(--primary-ink, #0f766e); }
.wl-hint { font-size: 12px; color: var(--text-muted); margin-top: 10px; line-height: 1.5; }
.wl-hint code { background: var(--bg); padding: 1px 5px; border-radius: 5px; font-size: 11.5px; }

.wl-sub-wrap { display: flex; align-items: stretch; margin-top: 12px; }
.wl-sub-locked { flex: 1; display: flex; align-items: center; gap: 8px; padding: 10px 14px; background: var(--bg, #f8fafc); border: 1.5px solid var(--border, #e2e8f0); border-right: none; border-radius: 10px 0 0 10px; font-size: 14px; font-weight: 700; color: var(--text, #0f172a); }
.wl-sub-locked svg { color: var(--text-light, #94a3b8); flex-shrink: 0; }
.wl-sub-input { border-top-right-radius: 0; border-bottom-right-radius: 0; text-align: right; }
.wl-sub-suffix { display: flex; align-items: center; padding: 0 12px; background: var(--bg); border: 1px solid var(--border); border-left: none; border-radius: 0 9px 9px 0; font-size: 13px; color: var(--text-muted); font-weight: 600; }
.wl-sub-status { display: flex; align-items: center; gap: 5px; font-size: 12.5px; margin-top: 8px; color: var(--text-muted); }
.wl-sub-status.ok { color: var(--primary-ink, #0f766e); }
.wl-sub-status.taken, .wl-sub-status.invalid { color: var(--danger-ink, #dc2626); }

.wl-field { display: block; margin-top: 14px; }
.wl-label { display: block; font-size: 12.5px; font-weight: 700; color: var(--text-muted); margin-bottom: 6px; }
.wl-label em { font-weight: 500; font-style: normal; color: var(--text-light, #94a3b8); }
.wl-input { width: 100%; height: 40px; padding: 0 12px; border: 1px solid var(--border); border-radius: 9px; font-size: 14px; color: var(--text); background: var(--surface, #ffffff); box-sizing: border-box; }
.wl-input:focus { outline: none; border-color: #14b8a6; box-shadow: 0 0 0 3px rgba(15,118,110,.12); }

.wl-logo-row { display: flex; gap: 14px; align-items: center; margin-top: 14px; }
.wl-logo-prev { width: 64px; height: 64px; border-radius: 14px; background: linear-gradient(135deg,#14b8a6,#0f766e); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 18px; flex-shrink: 0; }
.wl-logo-prev.img { background-size: cover; background-position: center; }
.wl-logo-actions { display: flex; flex-direction: column; gap: 8px; align-items: flex-start; }

/* Homepage hero background picker (sits under the logo row) */
.wl-herobg { display: flex; gap: 14px; align-items: flex-start; margin-top: 16px; padding-top: 16px; border-top: 1px dashed var(--border); }
.wl-herobg-prev { width: 108px; height: 64px; border-radius: 10px; flex-shrink: 0; background: linear-gradient(130deg, var(--primary, #0f766e), #14b8a6); color: rgba(255,255,255,.85); display: flex; align-items: center; justify-content: center; font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; }
.wl-herobg-prev.img { background-size: cover; background-position: center; }
.wl-herobg-label { font-size: 13px; font-weight: 700; color: var(--text); }
.wl-herobg-label em { font-weight: 500; color: var(--text-muted); font-style: normal; }
.wl-herobg-hint { font-size: 12px; color: var(--text-muted); line-height: 1.45; }
.wl-herobg-btns { display: flex; gap: 8px; flex-wrap: wrap; }
.wl-warn { font-size: 11.5px; color: var(--warning-ink, #b45309); }

/* Collapse-proof: minmax(0,1fr) tracks + min-width:0 so the hex input can
   never blow the grid past the (narrow, multi-column) card width; the swatch
   is hard-locked to 44px with the native color-well chrome stripped. */
.wl-colors { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; margin-top: 14px; }
.wl-color { min-width: 0; }
.wl-color-row { display: flex; gap: 8px; align-items: center; min-width: 0; }
.wl-swatch { width: 44px; min-width: 44px; max-width: 44px; height: 40px; flex: 0 0 44px; padding: 0; border: 1px solid var(--border); border-radius: 9px; background: none; cursor: pointer; appearance: none; -webkit-appearance: none; }
.wl-swatch::-webkit-color-swatch-wrapper { padding: 3px; }
.wl-swatch::-webkit-color-swatch { border: none; border-radius: 6px; }
.wl-swatch::-moz-color-swatch { border: none; border-radius: 6px; }
.wl-hex { flex: 1 1 0; width: auto; min-width: 0; text-transform: lowercase; font-variant-numeric: tabular-nums; }
.wl-reset { margin-top: 14px; }

/* ── Admin-dashboard branding card (platform mode) ── */
.wl-logo-prev :deep(.bb-mark), .wl-admin-mark :deep(.bb-mark) { width: 66%; height: 66%; }
.wl-admin-prev { display: flex; align-items: center; gap: 11px; margin-top: 12px; padding: 12px 14px; border-radius: 12px; background: #0f172a; }
.wl-admin-mark { width: 38px; height: 38px; border-radius: 10px; display: flex; align-items: center; justify-content: center; color: #fff; overflow: hidden; flex-shrink: 0; background: linear-gradient(135deg, color-mix(in srgb, var(--adm), #fff 18%), var(--adm)); }
.wl-admin-mark.img { background-size: cover; background-position: center; }
.wl-admin-txt { flex: 1; min-width: 0; display: flex; flex-direction: column; line-height: 1.25; }
.wl-admin-txt b { color: #fff; font-size: 14px; font-weight: 800; }
.wl-admin-txt span { color: var(--text-light, #94a3b8); font-size: 10.5px; }
.wl-admin-btn { background: var(--adm); color: #fff; font-size: 12px; font-weight: 700; padding: 7px 13px; border-radius: 8px; }
.wl-admin-hint { font-size: 11.5px; color: var(--text-muted); }
.wl-admin-colors { margin-top: 16px; }
.wl-admin-swatches { display: flex; flex-wrap: wrap; gap: 9px; align-items: center; margin-top: 8px; }
.wl-admin-sw { width: 32px; height: 32px; border-radius: 9px; border: 2px solid transparent; cursor: pointer; display: flex; align-items: center; justify-content: center; color: #fff; box-shadow: inset 0 0 0 1px rgba(0,0,0,.08); }
.wl-admin-sw.on { border-color: var(--text, #0f172a); }
.wl-admin-custom { position: relative; width: 32px; height: 32px; border-radius: 9px; border: 1px dashed var(--border, #cbd5e1); cursor: pointer; display: flex; align-items: center; justify-content: center; overflow: hidden; }
.wl-admin-custom input[type=color] { position: absolute; inset: 0; opacity: 0; cursor: pointer; }
.wl-admin-custom span { width: 20px; height: 20px; border-radius: 6px; box-shadow: inset 0 0 0 1px rgba(0,0,0,.12); }

.wl-auto { display: flex; flex-direction: column; gap: 4px; margin-top: 10px; }
.wl-auto-row { display: flex; align-items: center; gap: 11px; padding: 10px 0; border-top: 1px solid var(--border, #f1f5f9); }
.wl-mod { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 12px 0; border-top: 1px solid var(--border, #f1f5f9); }
.wl-mod:first-of-type { margin-top: 6px; }
.wl-mod-info { display: flex; align-items: center; gap: 11px; min-width: 0; }
.wl-mod-ico { width: 34px; height: 34px; border-radius: 9px; background: var(--primary-soft, #f0fdfa); color: var(--primary-ink, #0f766e); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.wl-mod-name { font-size: 13.5px; font-weight: 700; color: var(--text); }
.wl-mod-desc { font-size: 12px; color: var(--text-muted); }

/* Publish state badge */
.wl-live { display: inline-flex; align-items: center; margin-left: 8px; font-size: 10.5px; font-weight: 800; text-transform: uppercase; letter-spacing: .04em; padding: 2px 8px; border-radius: 999px; background: var(--bg, #f1f5f9); color: var(--text-muted, #64748b); vertical-align: 2px; }
.wl-live.on { background: var(--positive-bg, #dcfce7); color: var(--positive-ink, #15803d); }

/* "What shows on your site" — actionable module rows */
/* Site languages — dropdown + compact code pills */
.wl-lang-row { margin-top: 12px; max-width: 320px; }
.wl-lang-minis { display: flex; flex-wrap: wrap; gap: 5px; margin-top: 10px; }
.wl-lang-mini { font-size: 10.5px; font-weight: 800; letter-spacing: .03em; color: var(--primary-ink); background: var(--primary-soft, #f0fdfa); border: 1px solid var(--primary-border, #99f6e4); border-radius: 6px; padding: 2px 7px; }
.wl-lang-mini.locked { color: var(--text-muted, #64748b); background: var(--bg, #f1f5f9); border-color: var(--border, #e2e8f0); }
.wl-lang-mini.all { color: var(--text-muted, #64748b); background: transparent; border-style: dashed; }

/* Own-domain recruitment CTA */
.wl-domain-cta { display: flex; align-items: center; gap: 11px; margin-top: 14px; padding: 11px 13px; border: 1.5px dashed var(--border-strong, #cbd5e1); border-radius: 12px; text-decoration: none; color: var(--text); transition: border-color .12s, background .12s; }
.wl-domain-cta:hover { border-color: var(--primary); background: var(--primary-soft, #f0fdfa); }
.wl-domain-cta-ico { width: 32px; height: 32px; border-radius: 9px; background: var(--primary-soft, #f0fdfa); color: var(--primary-ink); display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }
.wl-domain-cta-txt { flex: 1; display: flex; flex-direction: column; gap: 1px; min-width: 0; }
.wl-domain-cta-txt b { font-size: 13.5px; font-weight: 800; }
.wl-domain-cta-txt small { font-size: 12px; color: var(--text-muted); }
.wl-domain-cta > svg:last-child { color: var(--text-light, #94a3b8); flex-shrink: 0; }

.wl-mods { display: flex; flex-direction: column; gap: 8px; margin-top: 12px; }
.wl-mod2 { display: flex; align-items: center; gap: 12px; padding: 12px; border: 1px solid var(--border); border-radius: 12px; background: var(--bg); }
.wl-mod2.on { background: var(--primary-soft, #f0fdfa); border-color: var(--primary-border, #99f6e4); }
.wl-mod-body { flex: 1; min-width: 0; }
.wl-mod-on { display: inline-flex; align-items: center; gap: 3px; margin-left: 8px; font-size: 11px; font-weight: 800; color: var(--positive-ink, #15803d); }
.wl-mod-off { margin-left: 8px; font-size: 11px; font-weight: 700; color: var(--text-light, #94a3b8); }
.wl-mod-add { flex-shrink: 0; display: inline-flex; align-items: center; gap: 4px; padding: 8px 13px; border-radius: 9px; background: var(--primary, #0f766e); color: #fff; font-size: 12.5px; font-weight: 800; text-decoration: none; white-space: nowrap; }
.wl-mod-add:hover { filter: brightness(.94); }
.wl-mod-add.ghost { background: var(--surface, #ffffff); color: var(--text-muted); border: 1px solid var(--border); }
.wl-mod-add.ghost:hover { border-color: var(--primary, #0f766e); color: var(--primary-ink, #0f766e); filter: none; }

.wl-btn { display: inline-flex; align-items: center; gap: 8px; height: 40px; padding: 0 16px; border-radius: 9px; font-size: 13.5px; font-weight: 700; cursor: pointer; border: 1px solid transparent; }
.wl-btn:disabled { opacity: .55; cursor: not-allowed; }
.wl-btn-primary { background: #0f766e; color: #fff; }
.wl-btn-primary:hover:not(:disabled) { background: #0d5e58; }
.wl-btn-soft { background: var(--primary-soft, #f0fdfa); color: var(--primary-ink, #0f766e); border-color: var(--primary-border, #99f6e4); }
.wl-btn-ghost { background: var(--surface, #ffffff); color: var(--text-muted); border-color: var(--border); }
.wl-save { display: flex; justify-content: flex-end; gap: 10px; }
.wl-head-actions { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
/* Publish is green (go-live) so it never blurs with the teal Save beside it */
.wl-btn-publish { display: inline-flex; align-items: center; gap: 7px; background: #16a34a; border: 1px solid #16a34a; color: #fff; }
.wl-btn-publish:hover { background: #15803d; }

/* ── Live preview ── */
.wl-preview-wrap { position: sticky; top: 18px; background: var(--surface, #ffffff); border: 1px solid var(--border); border-radius: 16px; padding: 16px; }
.wl-preview-label { font-size: 12px; font-weight: 800; color: var(--text-muted); text-transform: uppercase; letter-spacing: .04em; margin-bottom: 10px; }
.wl-mock { border: 1px solid var(--border); border-radius: 14px; overflow: hidden; }
.wl-mock-header { display: flex; align-items: center; justify-content: space-between; padding: 12px 14px; border-bottom: 1px solid #eef2f6; }
.wl-mock-logo { display: flex; align-items: center; gap: 8px; }
.wl-mock-logo-mark { width: 30px; height: 30px; border-radius: 8px; background: linear-gradient(135deg, var(--ba), var(--bp)); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 12px; }
.wl-mock-logo-img { width: 30px; height: 30px; border-radius: 8px; background-size: cover; background-position: center; box-shadow: 0 0 0 1px var(--border); }
.wl-mock-name { font-weight: 800; font-size: 15px; color: var(--text, #0f172a); }
.wl-mock-pill { font-size: 11.5px; font-weight: 700; color: var(--text-secondary, #475569); border: 1.5px solid var(--border, #e2e8f0); border-radius: 999px; padding: 4px 10px; }
.wl-mock-nav { display: flex; gap: 4px; padding: 0 10px; border-bottom: 1px solid #eef2f6; }
.wl-mock-navlink { display: flex; align-items: center; gap: 5px; padding: 9px 10px; font-size: 12px; font-weight: 700; color: var(--text-light, #94a3b8); border-bottom: 3px solid transparent; }
.wl-mock-navlink.active { color: var(--bp); border-bottom-color: var(--ba); }
.wl-mock-body { padding: 14px; background: var(--surface-hover, #f8fafc); }
.wl-mock-hero { border-radius: 12px; padding: 18px; background: linear-gradient(135deg, var(--bp), var(--ba)); color: #fff; }
.wl-mock-hero-title { font-size: 18px; font-weight: 900; }
.wl-mock-hero-sub { font-size: 12.5px; opacity: .92; margin-top: 3px; }
.wl-mock-cta { margin-top: 12px; background: var(--surface, #ffffff); color: var(--bp); border: none; border-radius: 9px; padding: 9px 16px; font-weight: 800; font-size: 13px; cursor: default; }
.wl-mock-cards { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 12px; }
.wl-mock-card { background: var(--surface, #ffffff); border: 1px solid var(--border, #e2e8f0); border-radius: 12px; padding: 12px; }
.wl-mock-card-img { height: 52px; border-radius: 8px; background: color-mix(in srgb, var(--bp) 12%, white); color: var(--bp); display: flex; align-items: center; justify-content: center; }
.wl-mock-card-tag { display: inline-block; font-size: 10.5px; font-weight: 800; color: var(--bp); background: color-mix(in srgb, var(--bp) 14%, white); padding: 3px 8px; border-radius: 999px; }
.wl-mock-card-name { font-size: 13px; font-weight: 800; color: var(--text, #0f172a); margin-top: 8px; }
.wl-mock-card-price { font-size: 12px; font-weight: 700; color: var(--bp); margin-top: 2px; }
.wl-mock-card-link { font-size: 12px; font-weight: 800; color: var(--bp); margin-top: 6px; }
.wl-preview-note { text-align: center; font-size: 12px; color: var(--text-muted); margin-top: 10px; }

@media (max-width: 900px) {
  .wl-grid { grid-template-columns: 1fr; }
  .wl-preview-wrap { position: static; }
}

/* ── Phone (≤600px) — mobile-only adjustments, desktop untouched ── */
@media (max-width: 600px) {
  /* iOS zoom guard: inputs must be ≥16px on phones (.wl-hex inherits) */
  .wl-input, .wl-textarea { font-size: 16px; }

  /* Regain width on narrow cards */
  .wl-card { padding: 14px; }
  .wl-preview-bottom { padding: 12px; }

  /* Colors: 2-up grid too cramped for swatch + hex at 344–375px */
  .wl-colors { grid-template-columns: 1fr; }

  /* Module rows: let the nowrap Add button drop to its own full-width line */
  .wl-mod2 { flex-wrap: wrap; }
  .wl-mod-add { width: 100%; justify-content: center; min-height: 44px; }

  /* Tap targets ≥44px; primary Save full-width and easy to reach */
  .wl-btn { height: 44px; }
  .wl-save .wl-btn-primary { width: 100%; justify-content: center; }
  .wl-icon-btn { width: 40px; height: 40px; }

  /* Preview frame: cap height relative to the phone viewport */
  .wl-frame-wrap.desktop .wl-frame, .wl-frame-wrap.mobile .wl-frame { height: 70vh; }
}

/* Simple-page editors */
.wl-page-modal { max-width: 560px; width: 100%; }
.wl-pm-hint { font-size: 13px; color: var(--text-muted); margin: 0 0 12px; line-height: 1.5; }
.wl-pm-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0 12px; }
.wl-pm-grid .wl-field { margin-top: 10px; min-width: 0; }
.wl-pm-wide { grid-column: 1 / -1; }
@media (max-width: 600px) { .wl-pm-grid { grid-template-columns: 1fr; } }
.wl-pm-hero { margin-top: 16px; padding-top: 14px; border-top: 1px dashed var(--border); }
.wl-pm-hero-head { font-size: 12.5px; font-weight: 700; color: var(--text-muted); }
.wl-pm-hero-head em { font-weight: 500; font-style: normal; color: var(--text-light, #94a3b8); margin-left: 5px; }
.wl-pm-hero-img { display: flex; align-items: center; gap: 10px; margin-top: 12px; flex-wrap: wrap; }
.wl-pm-hero-thumb { width: 76px; height: 44px; border-radius: 8px; background-size: cover; background-position: center; box-shadow: 0 0 0 1px var(--border); flex-shrink: 0; }

/* White-label branding row */
.wl-wlb { display: flex; align-items: center; gap: 12px; margin-top: 14px; padding: 12px 14px; background: var(--bg); border: 1px solid var(--border); border-radius: 10px; }
.wl-wlb-txt { flex: 1; min-width: 0; }
.wl-wlb-name { font-size: 13.5px; font-weight: 800; color: var(--text); display: flex; align-items: center; gap: 8px; }
.wl-wlb-desc { font-size: 12px; color: var(--text-muted); margin-top: 3px; line-height: 1.5; }
.wl-switch.busy { opacity: .5; pointer-events: none; }
.wl-wlb .prem-lock { font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: .05em; color: var(--warning-ink, #92400e); background: var(--warning-bg, #fef3c7); border-radius: 999px; padding: 2px 8px; }

/* ── "Your website" card: clean single column ── */
.wl-site-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
.wl-state { display: inline-flex; align-items: center; gap: 6px; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: .05em; padding: 4px 11px; border-radius: 999px; background: var(--bg, #f1f5f9); color: var(--text-muted, #64748b); }
.wl-state.on { background: var(--positive-bg, #dcfce7); color: var(--positive-ink, #15803d); }
.wl-state-dot { width: 7px; height: 7px; border-radius: 50%; background: #94a3b8; }
.wl-state.on .wl-state-dot { background: #22c55e; }
.wl-addr { display: flex; align-items: center; gap: 9px; margin-top: 14px; padding: 11px 13px; background: var(--bg); border: 1px solid var(--border); border-radius: 11px; }
.wl-addr-ico { color: var(--primary-ink); flex-shrink: 0; }
.wl-addr-host { flex: 1; min-width: 0; font-weight: 800; font-size: 14px; color: var(--text); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.wl-publish { width: 100%; justify-content: center; margin-top: 12px; height: 44px; font-size: 14.5px; }
.wl-pub-hint { font-size: 12px; color: var(--text-muted); margin-top: 8px; text-align: center; line-height: 1.5; }
.wl-live-strip { display: flex; align-items: center; gap: 8px; margin-top: 12px; padding: 10px 13px; background: var(--positive-bg, #f0fdf4); border: 1px solid var(--positive-border, #bbf7d0); border-radius: 10px; font-size: 13px; font-weight: 600; color: var(--positive-ink, #166534); }
.wl-live-strip svg { flex-shrink: 0; }
.wl-live-strip span { flex: 1; min-width: 0; }
.wl-unpub { flex-shrink: 0; background: none; border: none; font-size: 12.5px; font-weight: 800; color: var(--positive-ink, #16a34a); text-decoration: underline; cursor: pointer; padding: 4px; }
.wl-hr { height: 1px; background: var(--bg, #f1f5f9); margin: 14px 0; }
.wl-row { display: flex; align-items: center; gap: 12px; }
.wl-row-txt { flex: 1; min-width: 0; }
.wl-row-name { display: flex; align-items: center; gap: 7px; font-size: 13.5px; font-weight: 800; color: var(--text); flex-wrap: wrap; }
.wl-row-name-sub { font-style: normal; font-weight: 600; color: var(--text-muted); font-size: 12px; }
.wl-row-name svg { color: var(--primary-ink); }
.wl-row-desc { font-size: 12px; color: var(--text-muted); margin-top: 2px; line-height: 1.45; }
.wl-row-link { text-decoration: none; border-radius: 10px; margin: -6px; padding: 6px; }
.wl-row-link:hover { background: var(--bg); }
.wl-row-arrow { color: var(--text-light, #94a3b8); flex-shrink: 0; }
.wl-sections-heading { display:flex; align-items:start; justify-content:space-between; flex-wrap:wrap; gap:12px; padding-bottom:22px; }
.wl-brand-presets { display:flex; flex-wrap:wrap; gap:8px; margin:16px 0 20px; }
.wl-brand-presets button { display:flex; align-items:center; gap:4px; min-height:44px; padding:9px 12px; border:1px solid var(--border); border-radius:7px; background:var(--surface); color:var(--text); font:inherit; font-size:13px; cursor:pointer; }
.wl-brand-presets button[aria-pressed=true] { border-color:var(--primary); box-shadow:0 0 0 1px var(--primary); }
.wl-brand-presets button:focus-visible { outline:2px solid var(--primary); outline-offset:3px; }
.wl-brand-presets button span { width:12px; height:20px; border-radius:3px; }.wl-brand-presets button span + span { margin-right:5px; }
.wl-sections-heading > span { font-size:12px; color:var(--primary-ink); background:var(--primary-soft); padding:6px 10px; border-radius:6px; }
.wl-sections { container-type:inline-size; }
.wl-section-groups { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:28px; }
@container(max-width:780px) { .wl-section-groups { grid-template-columns:minmax(0,1fr); } }
.wl-section-group header { padding:14px 16px; background:var(--bg); border-radius:8px; }
.wl-section-group h3 { font-size:14px; margin:0; color:var(--text); }.wl-section-group header p { margin:4px 0 0; font-size:12px; color:var(--text-muted); }
.wl-sections .wl-section-row { padding:20px 0; gap:16px; cursor:default; align-items:start; }
.wl-section-icon { flex-shrink:0; padding:9px; background:var(--primary-soft); color:var(--primary-ink); border-radius:8px; display:flex; }
.wl-section-row.is-hidden .wl-section-icon { background:var(--bg); color:var(--text-muted); }
.wl-section-edit { display:inline-flex; align-items:center; gap:6px; align-self:start; border:0; padding:5px 0; margin-top:3px; background:none; color:var(--primary-ink); font:inherit; font-size:12px; font-weight:650; cursor:pointer; text-decoration:none; }
.wl-section-edit:hover { text-decoration:underline; }.wl-section-edit:focus-visible { outline:2px solid var(--primary); outline-offset:2px; }
.wl-section-content-hint { font-size:12px; color:var(--text-muted); line-height:1.5; }
.wl-section-control { min-height:44px; cursor:pointer; flex-shrink:0; }.wl-sections .wl-section-state { font-size:12px; min-width:48px; }
.wl-sections .wl-section-actions { padding-top:20px; border-top:1px solid var(--border); }
@media(max-width:600px) { .wl-sections .wl-section-row { gap:10px; }.wl-section-icon { display:none; }.wl-sections .wl-section-state { display:block; min-width:0; font-size:11px; }.wl-section-control { flex-direction:column-reverse; gap:5px; }.wl-section-edit { min-height:40px; } }
</style>
