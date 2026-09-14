/**
 * seo.js — per-page SEO/meta for the customer storefront.
 *
 * `useSeo(input)` sets <title>, meta description, canonical, Open Graph / Twitter cards,
 * robots, and optional JSON-LD structured data via @unhead/vue. `input` may be a plain
 * object or a getter function (use the getter form when fields depend on async-loaded data
 * so the tags update once it arrives).
 *
 *   useSeo(() => ({ title: venue.value?.name, description: '...', jsonLd: {...} }))
 *
 * Canonical always points at the apex marketplace URL, and white-label provider sites are
 * forced to noindex, so only the Bookboll marketplace competes in search.
 */
import { computed, unref } from 'vue'
import { useHead } from '@unhead/vue'
import { platformBrand, activeBrand, isWhiteLabel, BRAND_APEX } from './brand.js'

const DEFAULT_DESCRIPTION =
  'Book sports venues, classes and events near you. Reserve courts and pitches by the hour, ' +
  'join games and tournaments, and sign up for memberships — all on Bookboll.'

function siteName() {
  return (isWhiteLabel.value ? activeBrand.value?.name : platformBrand.value?.name) || 'Bookboll'
}

// Always resolve to SOMETHING so every shared link gets a preview image: an
// explicit page image, else the brand logo, else the static social card. Without
// the final fallback, pages with no image emitted no og:image and social scrapers
// kept the (previously broken) static tag from index.html.
const OG_FALLBACK = `https://${BRAND_APEX}/og-default.png`
function defaultImage(explicit) {
  return explicit || platformBrand.value?.logo || activeBrand.value?.logo || OG_FALLBACK
}

// Canonical = the apex marketplace URL for this path (no white-label host, no
// query string, no deploy base path).
function currentRelPath() {
  if (typeof location === 'undefined') return '/'
  const base = import.meta.env.BASE_URL || '/'
  const p = location.pathname
  return p.startsWith(base) ? '/' + p.slice(base.length) : p
}
function apexCanonical(path) {
  // Fallback path strips the /ar prefix — apexCanonical always yields the ENGLISH URL.
  const p = path || currentRelPath().replace(/^\/ar(\/|$)/, '/')
  return `https://${BRAND_APEX}${p.startsWith('/') ? p : '/' + p}`
}
// Is the current URL the Arabic (/ar/…) edition of the page?
function underAr() {
  return /^\/ar(\/|$)/.test(currentRelPath())
}

export function useSeo(input) {
  const resolve = () => (typeof input === 'function' ? input() : input) || {}

  useHead(computed(() => {
    const o = resolve()
    const title = unref(o.title)
    const exactTitle = unref(o.exactTitle)   // use verbatim (no "· site" suffix) — e.g. a provider's custom meta title
    const description = unref(o.description) || DEFAULT_DESCRIPTION
    const site = siteName()
    const fullTitle = exactTitle || (title ? `${title} · ${site}` : `${site} — Book sports venues, classes & events`)
    const image = defaultImage(unref(o.image))
    // The /ar edition canonicalises to its own /ar URL and cross-links its
    // English twin via hreflang (and vice versa), so both editions can rank.
    const isAr = underAr()
    const enCanonical = unref(o.canonical) || apexCanonical(unref(o.path))
    const enPath = enCanonical.replace(`https://${BRAND_APEX}`, '') || '/'
    const arCanonical = `https://${BRAND_APEX}${enPath === '/' ? '/ar' : '/ar' + enPath}`
    const canonical = isAr ? arCanonical : enCanonical
    const type = unref(o.type) || 'website'
    const noindex = unref(o.noindex) || isWhiteLabel.value
    const jsonLd = unref(o.jsonLd)

    const meta = [
      { name: 'description', content: description },
      { property: 'og:title', content: fullTitle },
      { property: 'og:description', content: description },
      { property: 'og:type', content: type },
      { property: 'og:site_name', content: site },
      { property: 'og:locale', content: isAr ? 'ar_QA' : 'en_US' },
      { property: 'og:url', content: canonical },
      { name: 'twitter:card', content: 'summary_large_image' },
      { name: 'twitter:title', content: fullTitle },
      { name: 'twitter:description', content: description },
    ]
    if (image) {
      meta.push({ property: 'og:image', content: image })
      meta.push({ name: 'twitter:image', content: image })
    }
    if (noindex) meta.push({ name: 'robots', content: 'noindex, nofollow' })

    const links = [{ rel: 'canonical', href: canonical }]
    if (!noindex) {
      links.push({ rel: 'alternate', hreflang: 'en', href: enCanonical })
      links.push({ rel: 'alternate', hreflang: 'ar', href: arCanonical })
      links.push({ rel: 'alternate', hreflang: 'x-default', href: enCanonical })
    }
    const head = {
      title: fullTitle,
      meta,
      link: links,
    }
    if (jsonLd) {
      head.script = [{ type: 'application/ld+json', innerHTML: JSON.stringify(jsonLd) }]
    }
    return head
  }))
}

// ── JSON-LD builders (schema.org) ───────────────────────────────────────────
export function sportsActivityLocationLd({ name, description, image, address, city, country, lat, lng, priceRange, url }) {
  const ld = { '@context': 'https://schema.org', '@type': 'SportsActivityLocation', name }
  if (description) ld.description = description
  if (image) ld.image = image
  if (url) ld.url = url
  if (priceRange) ld.priceRange = priceRange
  if (address || city || country) {
    ld.address = { '@type': 'PostalAddress' }
    if (address) ld.address.streetAddress = address
    if (city) ld.address.addressLocality = city
    if (country) ld.address.addressCountry = country
  }
  if (lat != null && lng != null) ld.geo = { '@type': 'GeoCoordinates', latitude: lat, longitude: lng }
  return ld
}

export function eventLd({ name, description, image, startDate, endDate, locationName, address, city, price, currency, url }) {
  const ld = { '@context': 'https://schema.org', '@type': 'SportsEvent', name }
  if (description) ld.description = description
  if (image) ld.image = image
  if (startDate) ld.startDate = startDate
  if (endDate) ld.endDate = endDate
  if (url) ld.url = url
  if (locationName || address || city) {
    ld.location = { '@type': 'Place', name: locationName || city || '' }
    if (address || city) {
      ld.location.address = { '@type': 'PostalAddress' }
      if (address) ld.location.address.streetAddress = address
      if (city) ld.location.address.addressLocality = city
    }
  }
  if (price != null) ld.offers = { '@type': 'Offer', price: String(price), priceCurrency: currency || '' }
  return ld
}

export function productLd({ name, description, image, price, currency, url }) {
  const ld = { '@context': 'https://schema.org', '@type': 'Product', name }
  if (description) ld.description = description
  if (image) ld.image = image
  if (url) ld.url = url
  if (price != null) ld.offers = { '@type': 'Offer', price: String(price), priceCurrency: currency || '', availability: 'https://schema.org/InStock' }
  return ld
}

export function organizationLd() {
  return {
    '@context': 'https://schema.org',
    '@type': 'Organization',
    name: siteName(),
    url: `https://${BRAND_APEX}/`,
    ...(platformBrand.value?.logo ? { logo: platformBrand.value.logo } : {}),
  }
}

export function websiteLd() {
  return {
    '@context': 'https://schema.org',
    '@type': 'WebSite',
    name: siteName(),
    url: `https://${BRAND_APEX}/`,
    potentialAction: {
      '@type': 'SearchAction',
      target: `https://${BRAND_APEX}/facilities?q={search_term_string}`,
      'query-input': 'required name=search_term_string',
    },
  }
}

// items: [{ q, a }]
export function faqLd(items) {
  const list = (items || []).filter(i => i && i.q && i.a)
  if (!list.length) return null
  return {
    '@context': 'https://schema.org',
    '@type': 'FAQPage',
    mainEntity: list.map(i => ({
      '@type': 'Question', name: i.q,
      acceptedAnswer: { '@type': 'Answer', text: i.a },
    })),
  }
}

// items: [{ name, path }] — absolute apex URLs are built from path.
export function breadcrumbLd(items) {
  const list = (items || []).filter(Boolean)
  if (!list.length) return null
  return {
    '@context': 'https://schema.org',
    '@type': 'BreadcrumbList',
    itemListElement: list.map((it, i) => ({
      '@type': 'ListItem', position: i + 1, name: it.name,
      item: `https://${BRAND_APEX}${it.path.startsWith('/') ? it.path : '/' + it.path}`,
    })),
  }
}

// items: [{ name, path }] — an ItemList of links (e.g., the venues on a landing page).
export function itemListLd(items, listName) {
  const list = (items || []).filter(Boolean)
  if (!list.length) return null
  return {
    '@context': 'https://schema.org',
    '@type': 'ItemList',
    ...(listName ? { name: listName } : {}),
    numberOfItems: list.length,
    itemListElement: list.map((it, i) => ({
      '@type': 'ListItem', position: i + 1, name: it.name,
      url: `https://${BRAND_APEX}${it.path.startsWith('/') ? it.path : '/' + it.path}`,
    })),
  }
}
