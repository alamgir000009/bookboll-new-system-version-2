/**
 * brand.js — provider white-label resolution + theming.
 *
 * A provider can run a white-labeled copy of the customer storefront on their own
 * subdomain (e.g. mybusiness.bookboll.com) that shows ONLY their own venues,
 * listings, membership programs and events, with their own logo, name and colors.
 *
 * How the brand is detected (in order):
 *   1. `?b=<slug>` query param — dev/testing + a fallback that works before
 *      wildcard DNS is configured (and as a shareable link anywhere).
 *   2. The first label of the hostname — `mybusiness.bookboll.com` → `mybusiness`
 *      (skips the apex, `www`, `app`, `admin`, and handles `*.localhost`).
 *
 * Resolution looks the slug up in `admin_users` and, on a hit, (a) scopes the
 * customer data layer to that owner and (b) applies the brand theme via CSS vars.
 * The public/apex site (no slug, or no match) is completely unchanged — every
 * default equals today's hardcoded Bookboll look.
 */
import { ref, computed } from 'vue'
import { supabase } from './supabase.js'
import { appBaseUrl } from './appBase.js'
import { setBrandScope, loadServedRegions } from '../customer/data.js'
import { normalizeBanners } from './heroBanners.js'
import { countPublishedPosts } from './blog.js'

// The apex the white-label subdomains live under (display + canonical links).
export const BRAND_APEX = 'bookboll.com'
// Platform defaults — the white-label theme falls back to these.
export const DEFAULT_PRIMARY = '#0f766e'
export const DEFAULT_ACCENT  = '#14b8a6'

const RESERVED_SUBS = new Set(['www', 'app', 'admin', 'api', 'business', 'staging', 'dev'])

/** Turn a free-text name into a valid subdomain label (a-z, 0-9, hyphens, ≤30). */
export function slugify(s) {
  return (s || '').toString().toLowerCase().trim()
    .replace(/['']/g, '')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 30)
    .replace(/-+$/g, '')
}

/**
 * The effective white-label web-address slug for a provider row — ALWAYS non-empty for
 * a real provider, so every provider automatically has a `<slug>.bookboll.com` address
 * without having to set one up manually. Order: an explicit brand subdomain → their
 * username → the slugified business / brand name → a stable id-based fallback.
 */
export function providerSlug(u) {
  if (!u) return ''
  const explicit = (u.brand_subdomain || u.username || '').toLowerCase()
  if (explicit) return explicit
  const derived = slugify(u.business_name || u.brand_name || '')
  if (derived && derived.length >= 3) return derived
  const id = String(u.id || '').replace(/-/g, '').slice(0, 8)
  return id ? `biz-${id}` : ''
}

// { ownerUserId, slug, name, tagline, logo, primary, accent } — null until resolved.
export const activeBrand = ref(null)
export const isWhiteLabel = computed(() => !!activeBrand.value)

// The PLATFORM brand — the main Bookboll marketplace (apex site, no white-label).
// Editable by the super admin in My Website; stored as platform_settings keys.
export const platformBrand = ref({
  name: 'Bookboll', tagline: '', logo: '', primary: DEFAULT_PRIMARY, accent: DEFAULT_ACCENT,
  metaTitle: '', metaDescription: '',
  sortSpace: 'recommended', sortVenue: 'recommended',
  banners: [],
  tracking: { metaPixel: '', ga4: '', gtm: '', head: '' },   // marketplace (apex) analytics/pixels
})

// Load the marketplace branding from platform_settings (best-effort → defaults on any error).
export async function loadPlatformBrand() {
  try {
    const { data } = await supabase.from('platform_settings').select('key, value')
      .in('key', ['brand_name', 'brand_tagline', 'brand_logo_url', 'brand_primary', 'brand_accent', 'brand_meta_title', 'brand_meta_description', 'storefront_sort_space', 'storefront_sort_venue', 'hero_banners', 'tracking_meta_pixel', 'tracking_ga4', 'tracking_gtm', 'tracking_head'])
    const m = {}
    for (const row of (data || [])) m[row.key] = row.value
    platformBrand.value = {
      name:    m.brand_name    || 'Bookboll',
      tagline: m.brand_tagline || '',
      logo:    m.brand_logo_url || '',
      primary: m.brand_primary || DEFAULT_PRIMARY,
      accent:  m.brand_accent  || DEFAULT_ACCENT,
      metaTitle:       m.brand_meta_title || '',
      metaDescription: m.brand_meta_description || '',
      sortSpace: m.storefront_sort_space || 'recommended',
      sortVenue: m.storefront_sort_venue || 'recommended',
      banners: normalizeBanners(m.hero_banners),
      tracking: {
        metaPixel: m.tracking_meta_pixel || '',
        ga4:       m.tracking_ga4 || '',
        gtm:       m.tracking_gtm || '',
        head:      m.tracking_head || '',
      },
    }
  } catch { /* keep defaults */ }
  return platformBrand.value
}

// Set <meta name="description"> directly (storefront first paint). Per-page useSeo can
// still override on navigation.
function setMetaDescription(desc) {
  if (typeof document === 'undefined' || !desc) return
  let tag = document.querySelector('meta[name="description"]')
  if (!tag) { tag = document.createElement('meta'); tag.setAttribute('name', 'description'); document.head.appendChild(tag) }
  tag.setAttribute('content', desc)
}

// Point the browser-tab favicon at a brand's uploaded logo. Falls back to the
// bundled Bookboll logo (public/logo.svg) when the brand has no custom logo, so
// the tab always shows a real mark instead of the framework default.
export function setFavicon(url) {
  if (typeof document === 'undefined') return
  const href = url || '/logo.svg?v=3'   // version bump busts stale favicon caches
  let link = document.querySelector('link[rel="icon"]')
  if (!link) { link = document.createElement('link'); link.setAttribute('rel', 'icon'); document.head.appendChild(link) }
  // A raster upload (png/jpg) shouldn't advertise image/svg+xml.
  if (/\.svg(\?|$)/i.test(href)) link.setAttribute('type', 'image/svg+xml')
  else link.removeAttribute('type')
  link.setAttribute('href', href)
}

function isLocalHost(host) {
  return host === 'localhost' || host.endsWith('.localhost') || host === '127.0.0.1' || /^\d+\.\d+\.\d+\.\d+$/.test(host)
}

// Public dev tunnels (Cloudflare quick tunnel / ngrok) used to preview the dev server on a
// phone. The whole tunnel host is a single apex — its first label is a random tunnel id, NOT a
// white-label subdomain — so we treat it like the apex marketplace (custom platform theme + ?b=
// convenience), never as a provider slug.
function isDevTunnel(host) {
  return /\.trycloudflare\.com$/.test(host) || /\.ngrok(-free)?\.(app|io)$/.test(host) || /\.ngrok\.app$/.test(host)
}

/** Dashboard "live preview" flag (?preview=1) — resolve the brand even if not yet
 *  enabled/published, so a provider can preview their site while setting it up. */
export function isBrandPreview() {
  if (typeof window === 'undefined') return false
  return new URLSearchParams(window.location.search).get('preview') === '1'
}

/** Dashboard live-preview only: a provider's owner id passed as `?bid=<id>`, so the
 *  preview resolves the right provider even before they've chosen/saved a web address. */
export function detectBrandOwnerId() {
  if (typeof window === 'undefined') return ''
  return (new URLSearchParams(window.location.search).get('bid') || '').trim()
}

/** Detect the brand slug from the URL, or '' if this is the plain platform site. */
export function detectBrandSlug() {
  if (typeof window === 'undefined') return ''
  const host = window.location.hostname || ''
  const params = new URLSearchParams(window.location.search)
  const q = (params.get('b') || '').trim().toLowerCase()
  // `?b=<slug>` is a DEV/localhost-only convenience. On any real host it is IGNORED, so a
  // provider's scoped storefront — and its analytics/pixels + custom <head> code — can never be
  // force-loaded on the apex origin via https://bookboll.com/?b=<slug> (which would leak
  // marketplace traffic into a provider's tracking and expose the apex origin to a provider's
  // scripts). In production, white-label sites resolve by SUBDOMAIN only (needs wildcard DNS).
  if (q && (isLocalHost(host) || isDevTunnel(host))) return q
  // A dev tunnel is the apex itself (its subdomain is a random tunnel id, not a provider slug).
  if (isDevTunnel(host)) return ''

  const parts = host.split('.')
  // apex length: localhost is 1 label, bookboll.com is 2.
  const baseLen = isLocalHost(host) ? 1 : 2
  if (parts.length > baseLen) {
    const sub = (parts[0] || '').toLowerCase()
    if (sub && !RESERVED_SUBS.has(sub)) return sub
  }
  return ''
}

// Find the owner row for a slug. Post-migration: matches an ENABLED brand_subdomain
// (opt-in). Pre-migration (brand_* columns absent): falls back to a provider_admin
// whose @username matches, so scoping is testable before the SQL is applied.
// `allowDisabled` (dashboard "live preview" only) resolves the brand even when it
// isn't enabled/published yet, so a provider can preview their site while setting it up.
async function lookupBrand(slug, allowDisabled = false) {
  const rich = await supabase.from('admin_users')
    .select('id, username, business_name, role, brand_enabled, brand_subdomain, brand_name, brand_tagline, brand_logo_url, brand_primary, brand_accent, brand_meta_title, brand_meta_description')
    .or(`brand_subdomain.ilike.${slug},username.ilike.${slug}`)
  if (!rich.error) {
    const rows = rich.data || []
    let r = rows.find(x => (x.brand_subdomain || '').toLowerCase() === slug && x.brand_enabled)
    if (!r && allowDisabled) {
      r = rows.find(x => (x.brand_subdomain || '').toLowerCase() === slug)
        || rows.find(x => (x.username || '').toLowerCase() === slug)
    }
    if (r) return r
    return null   // columns exist but no enabled brand for this slug → opt-in respected
  }
  // brand_* columns not migrated yet → username fallback (dev only)
  const min = await supabase.from('admin_users').select('id, username, role').eq('role', 'provider_admin').ilike('username', slug)
  const row = (min.data || [])[0]
  return row ? { ...row, brand_enabled: true } : null
}

// Resolve a provider directly by owner id — used only by the dashboard live preview
// (`?bid=`), so it works even for a provider who hasn't picked/saved a web address yet.
async function lookupBrandById(id) {
  const r = await supabase.from('admin_users')
    .select('id, username, business_name, role, brand_enabled, brand_subdomain, brand_name, brand_tagline, brand_logo_url, brand_primary, brand_accent, brand_meta_title, brand_meta_description')
    .eq('id', id).maybeSingle()
  return (!r.error && r.data) ? r.data : null
}

// Which storefront sections a provider's white-label site shows. AUTO-DERIVED from the
// content they actually have, not manual toggles:
//   • venues   → they have at least one listing visible on their own site
//   • programs → they have at least one membership program visible on their own site
//   • events   → NEVER (events, like loyalty/profiles, are a marketplace-only mechanic)
// (`show_on_provider_site !== false` mirrors the storefront's own white-label visibility.)
async function loadBrandModules(ownerId) {
  let venues = false, programs = false
  const { data: locs } = await supabase.from('locations').select('id').eq('owner_user_id', ownerId)
  const locIds = (locs || []).map(l => l.id)
  if (locIds.length) {
    const { data: ls } = await supabase.from('listings').select('show_on_provider_site').in('location_id', locIds)
    venues = (ls || []).some(l => l.show_on_provider_site !== false)
  }
  const { data: ps } = await supabase.from('class_programs').select('show_on_provider_site').eq('owner_user_id', ownerId)
  programs = (ps || []).some(p => p.show_on_provider_site !== false)
  // Blog module: shown only once the provider has published at least one of their own posts.
  const blog = (await countPublishedPosts()) > 0
  return { venues, events: false, programs, blog }
}

// The provider's home hero banners (best-effort — column may not be migrated yet).
async function loadBrandBanners(ownerId) {
  const r = await supabase.from('admin_users').select('hero_banners').eq('id', ownerId).maybeSingle()
  return (!r.error && r.data) ? normalizeBanners(r.data.hero_banners) : []
}

// The provider's storefront sort choices (best-effort — columns may not be migrated yet).
async function loadBrandSort(ownerId) {
  const r = await supabase.from('admin_users').select('sort_space, sort_venue').eq('id', ownerId).maybeSingle()
  const d = (!r.error && r.data) ? r.data : {}
  return { space: d.sort_space || 'recommended', venue: d.sort_venue || 'recommended' }
}

// The provider's simple content pages (About / Contact) — best-effort (the
// site_pages column may not be migrated yet). A page is live once it has content.
async function loadBrandPages(ownerId) {
  const r = await supabase.from('admin_users').select('site_pages').eq('id', ownerId).maybeSingle()
  const pg = (!r.error && r.data?.site_pages) || {}
  const hero = (h) => ({ hero_title: String(h?.hero_title || ''), hero_sub: String(h?.hero_sub || ''), hero_image: String(h?.hero_image || '') })
  return {
    // Homepage default-hero background (set next to the logo in My Website) —
    // the default hero itself is built from site settings, not the banners module.
    home: { hero_image: String(pg.home?.hero_image || '') },
    about: { text: String(pg.about?.text || ''), ...hero(pg.about) },
    contact: {
      phone: String(pg.contact?.phone || ''), whatsapp: String(pg.contact?.whatsapp || ''),
      email: String(pg.contact?.email || ''), address: String(pg.contact?.address || ''),
      maps_url: String(pg.contact?.maps_url || ''), hours: String(pg.contact?.hours || ''),
      ...hero(pg.contact),
    },
  }
}
// Premium white-label: the badge only hides when the flag is on AND the owner
// is actually on the premium plan RIGHT NOW (lapsed premium → badge returns).
async function loadBrandWhitelabel(ownerId) {
  try {
    const r = await supabase.from('admin_users').select('brand_whitelabel, plan').eq('id', ownerId).maybeSingle()
    return !r.error && !!r.data?.brand_whitelabel && r.data?.plan === 'premium'
  } catch { return false }
}

/** Is a simple content page live on the active white-label site? */
export function brandPageEnabled(key) {
  const pg = activeBrand.value?.pages
  if (!pg) return false
  if (key === 'about') return !!pg.about.text.trim()
  if (key === 'contact') return ['phone', 'whatsapp', 'email', 'address', 'maps_url', 'hours']
    .some(k => String(pg.contact[k] || '').trim())
  return false
}

// Which languages this provider's storefront offers (best-effort — the brand_languages
// column may not be migrated yet). [] = no restriction → all supported languages. The
// switcher + autoTranslate keep English available regardless (it's the source language).
async function loadBrandLanguages(ownerId) {
  const r = await supabase.from('admin_users').select('brand_languages').eq('id', ownerId).maybeSingle()
  const arr = (!r.error && Array.isArray(r.data?.brand_languages)) ? r.data.brand_languages : []
  return arr.filter(c => typeof c === 'string' && c)
}

// The provider's analytics/pixels (best-effort — tracking_* columns may not be migrated yet).
// Loaded separately so a missing column never breaks brand resolution. These fire ONLY on this
// provider's own storefront (see lib/tracking.js), never on the marketplace or another site.
async function loadBrandTracking(ownerId) {
  const empty = { metaPixel: '', ga4: '', gtm: '', head: '' }
  const r = await supabase.from('admin_users').select('tracking_meta_pixel, tracking_ga4, tracking_gtm, tracking_head').eq('id', ownerId).maybeSingle()
  if (r.error || !r.data) return empty
  return {
    metaPixel: r.data.tracking_meta_pixel || '',
    ga4:       r.data.tracking_ga4 || '',
    gtm:       r.data.tracking_gtm || '',
    head:      r.data.tracking_head || '',
  }
}

/** Configured fallback sort key for a storefront view ('space' | 'venue').
 *  White-label → that provider's choice; apex → the platform default. Used by the
 *  storefront when the visitor has NOT shared their location (else: nearest first). */
export function storefrontSortKey(view) {
  const b = activeBrand.value
  if (b?.sort) return view === 'venue' ? b.sort.venue : b.sort.space
  return view === 'venue' ? platformBrand.value.sortVenue : platformBrand.value.sortSpace
}

/** Is a storefront module visible? Always true on the plain platform site; on a
 *  white-label site it follows the provider's choices. `mod` = venues|events|programs. */
export function brandShows(mod) {
  const b = activeBrand.value
  if (!b) return true
  return b.modules ? b.modules[mod] !== false : true
}

// Map a customer route path to the storefront module that gates it (or null = always on).
export function moduleForPath(path) {
  const p = path || ''
  if (p.startsWith('/facilities') || p.startsWith('/listings/')) return 'venues'
  if (p.startsWith('/games') || p.startsWith('/tournaments') || p.startsWith('/host-game')) return 'events'
  if (p.startsWith('/programs')) return 'programs'
  if (p.startsWith('/blog')) return 'blog'
  return null
}

/** Absolute URL to the MAIN Bookboll marketplace (apex), from anywhere — used by the
 *  white-label "we don't serve here, browse Bookboll" prompts. In dev/preview the brand is
 *  scoped via `?b=`, so the same origin without it IS the apex; on a real subdomain
 *  (`sub.bookboll.com`) we strip the brand label to reach the root domain. */
export function marketplaceUrl(path = '') {
  if (typeof window === 'undefined') return path || '/'
  const { hostname, protocol } = window.location
  // Strip the brand label ONLY on a real white-label subdomain of the apex —
  // any other host (dev, Pages preview under a subpath) is the marketplace itself.
  if (hostname.endsWith('.' + BRAND_APEX)) {
    return `${protocol}//${BRAND_APEX}${path || '/'}`
  }
  return `${appBaseUrl()}${path || '/'}`
}

/** Apply a brand's colors as CSS variables on <html>. Soft tints are derived in CSS
 *  via color-mix, so we only set the two base colors here. */
export function applyBrandTheme(primary, accent) {
  if (typeof document === 'undefined') return
  const root = document.documentElement
  root.style.setProperty('--brand', primary || DEFAULT_PRIMARY)
  root.style.setProperty('--brand-accent', accent || DEFAULT_ACCENT)
  root.classList.add('cust-branded')
}

/** Resolve the active brand from the URL once at app start. Best-effort: any failure
 *  leaves the plain platform site intact. */
export async function resolveBrand() {
  // Apply the editable marketplace (platform) brand — logo, name, colors, meta.
  // Used for the apex AND as the fallback for any host/slug that ISN'T a real
  // provider white-label (a Vercel/Netlify preview host, a staging domain, an
  // unknown subdomain): those must still show the branded marketplace, never the
  // hardcoded defaults with the super admin's customization missing.
  async function applyPlatformBrand() {
    await loadPlatformBrand()
    applyBrandTheme(platformBrand.value.primary, platformBrand.value.accent)
    const ptitle = platformBrand.value.metaTitle || (platformBrand.value.name !== 'Bookboll' ? platformBrand.value.name : '')
    if (ptitle) document.title = ptitle
    setMetaDescription(platformBrand.value.metaDescription || platformBrand.value.tagline)
    setFavicon(platformBrand.value.logo)   // super-admin's uploaded marketplace logo
  }
  try {
    const slug = detectBrandSlug()
    const preview = isBrandPreview()
    // Dashboard live preview can target a provider by owner id (`?bid=`) so it resolves
    // even before they've chosen/saved a web address. Preview-only (needs `preview=1`).
    const bid = preview ? detectBrandOwnerId() : ''
    if (!slug && !bid) {
      // Apex = the main Bookboll marketplace. Apply the (editable) platform brand.
      await applyPlatformBrand()
      return null
    }
    const row = bid ? await lookupBrandById(bid) : await lookupBrand(slug, preview)
    // Slug/bid present but no provider matched (e.g. a preview/staging host whose
    // first label isn't a real white-label): fall back to the marketplace brand so
    // the super admin's logo/colors still apply, instead of the unthemed default.
    if (!row) { await applyPlatformBrand(); return null }
    const brandSlug = providerSlug(row) || (slug || '').toLowerCase()
    const brand = {
      ownerUserId: row.id,
      slug: brandSlug,
      name: row.brand_name || row.business_name || row.username || 'Bookboll',
      tagline: row.brand_tagline || '',
      logo: row.brand_logo_url || '',
      primary: row.brand_primary || DEFAULT_PRIMARY,
      accent: row.brand_accent || DEFAULT_ACCENT,
      metaTitle: row.brand_meta_title || '',
      metaDescription: row.brand_meta_description || '',
      modules: await loadBrandModules(row.id),
      sort: await loadBrandSort(row.id),
      banners: await loadBrandBanners(row.id),
      regions: await loadServedRegions(brandSlug),
      tracking: await loadBrandTracking(row.id), // their own analytics/pixels (storefront only)
      languages: await loadBrandLanguages(row.id), // offered storefront languages ([] = all)
      pages: await loadBrandPages(row.id),       // About / Contact simple pages
      whitelabelBranding: await loadBrandWhitelabel(row.id), // premium: hide the big badge
    }
    activeBrand.value = brand
    setBrandScope(brand.ownerUserId)
    applyBrandTheme(brand.primary, brand.accent)
    document.title = brand.metaTitle || brand.name || 'Bookboll'
    setMetaDescription(brand.metaDescription || brand.tagline)
    setFavicon(brand.logo)   // provider's own logo on their white-label site
    return brand
  } catch {
    return null   // never block the storefront on a branding error
  }
}

/** Canonical shareable address for a slug (what a provider gives their customers). */
export function brandHost(slug) { return `${(slug || '').toLowerCase()}.${BRAND_APEX}` }

/** A link that actually works in the CURRENT environment: the real subdomain in
 *  production, or the `?b=` fallback in dev / before wildcard DNS exists. Pass
 *  `{ preview: true }` for the dashboard live-preview (resolves the brand even if it
 *  isn't enabled/published yet, via the `preview=1` flag). */
export function brandPreviewUrl(slug, { preview = false } = {}) {
  const s = (slug || '').toLowerCase()
  // Dev / local: load the public web app with ?b=. appBaseUrl() prefers VITE_WEB_URL
  // so this works from the admin app (port 5174 → 5173) as well.
  if (typeof window !== 'undefined' && isLocalHost(window.location.hostname)) {
    return `${appBaseUrl()}/?b=${encodeURIComponent(s)}${preview ? '&preview=1' : ''}`
  }
  return `https://${brandHost(s)}${preview ? '?preview=1' : ''}`
}

/** Dashboard live-preview URL that resolves a provider by OWNER ID on the public web app. */
export function brandPreviewById(ownerId) {
  return `${appBaseUrl()}/?bid=${encodeURIComponent(ownerId || '')}&preview=1`
}

/**
 * Make sure a provider has a saved white-label subdomain, auto-deriving one from their
 * username / business name when missing, so every provider automatically gets a working
 * `<slug>.bookboll.com` address without manual setup. Adds a short suffix on collision.
 * Best-effort (a missing brand_subdomain column just leaves the derived slug unsaved —
 * the dashboard live preview still works via owner id). Returns the effective slug.
 */
export async function ensureProviderSubdomain(user) {
  const explicit = (user?.brand_subdomain || '').toLowerCase()
  if (explicit) return explicit
  let base = providerSlug(user)
  if (!base || !user?.id) return base || ''
  try {
    const { data, error } = await supabase.from('admin_users').select('id').ilike('brand_subdomain', base)
    if (error) return base   // can't check (column not migrated) → use the derived slug as-is
    if ((data || []).some(r => r.id !== user.id)) {
      base = `${base}-${String(user.id).replace(/-/g, '').slice(0, 4)}`
    }
    await supabase.from('admin_users').update({ brand_subdomain: base }).eq('id', user.id)
  } catch { /* best-effort */ }
  return base
}
