/**
 * heroBanners.js — customizable home hero slideshow.
 *
 * The customer home page shows a rotating set of banners. Each banner has a
 * heading/subheading, an image OR colour background, an audience (everyone /
 * signed-in / signed-out) and an optional call-to-action button (preset action or
 * custom link, with its own text + colour).
 *
 * Super admin edits the Bookboll marketplace banners (apex, platform_settings);
 * each provider edits their own (admin_users.hero_banners) from My Website.
 */

// Selectable button actions (customer-facing routes). `to` is the destination.
export const BANNER_ACTIONS = [
  { key: 'none',            label: 'No button',           to: null },
  { key: 'signin',          label: 'Sign in',             to: '/account' },
  { key: 'book_venue',      label: 'All venues (browse)', to: '/facilities' },
  { key: 'get_membership',  label: 'All memberships (browse)', to: '/programs' },
  { key: 'join_event',      label: 'Join an event',       to: '/games' },
  { key: 'host_event',      label: 'Host an event',       to: '/host-game' },
  // Deep-link to ONE specific listing — the editor picks the target (`b.target`).
  { key: 'venue',           label: 'A specific venue',      to: null, kind: 'venue' },
  { key: 'program',         label: 'A specific membership', to: null, kind: 'program' },
  { key: 'event',           label: 'A specific event',      to: null, kind: 'event' },
  { key: 'rewards',         label: 'View rewards',        to: '/rewards' },
  { key: 'blog',            label: 'Read the blog',       to: '/blog' },
  { key: 'all_venues',      label: 'All venues (SEO directory)', to: '/venues' },
  { key: 'all_memberships', label: 'All memberships (SEO directory)', to: '/memberships' },
  { key: 'custom',          label: 'Custom link',         to: null },
]

// Specific-listing actions and the customer detail path each deep-links to.
export const LISTING_ACTION_PATH = { venue: '/listings/', program: '/programs/', event: '/games/' }

export const DEFAULT_BUTTON_TEXT = {
  signin: 'Sign in', book_venue: 'Book a venue', join_event: 'Join an event',
  host_event: 'Host an event', get_membership: 'Get a membership', rewards: 'My rewards',
  blog: 'Read the blog', all_venues: 'Browse venues', all_memberships: 'Browse memberships',
  venue: 'Book now', program: 'View membership', event: 'View event',
  custom: 'Learn more',
}

export const AUDIENCE_LABEL = { all: 'Everyone', in: 'Signed-in', out: 'Signed-out' }

// Placeholder tokens usable in banner heading/description text (documented in the editor).
export const BANNER_TOKENS = [
  { code: '{city}',    desc: "The visitor's selected city" },
  { code: '{country}', desc: "The visitor's country" },
  { code: '{name}',    desc: "A signed-in customer's first name (else “there”)" },
]
/** Replace {city}/{country}/{name} tokens with live values (used on the storefront + preview). */
export function applyTokens(s, { city, country, name } = {}) {
  return (s || '')
    .replace(/\{city\}/gi, city || 'your city')
    .replace(/\{country\}/gi, country || 'your country')
    .replace(/\{name\}/gi, name || 'there')
}

// Curated, web-safe font choices for banner text (no external fonts to load).
export const BANNER_FONTS = [
  { key: '',                                         label: 'Default' },
  { key: "'Helvetica Neue', Arial, sans-serif",      label: 'Clean sans' },
  { key: "Georgia, 'Times New Roman', serif",        label: 'Elegant serif' },
  { key: "'Trebuchet MS', system-ui, sans-serif",    label: 'Rounded' },
  { key: "'Courier New', monospace",                 label: 'Monospace' },
  { key: "Impact, 'Arial Black', sans-serif",        label: 'Bold display' },
]

let _seq = 0
function newId() { return 'b' + Date.now().toString(36) + (_seq++).toString(36) }

export function blankBanner() {
  return {
    id: newId(), heading: '', subheading: '', image: '', icon: '', bg: '', text_color: '#ffffff', font: '',
    heading_color: '', heading_font: '',   // optional per-heading overrides (fall back to text_color/font)
    audience: 'all', countries: [], cities: [], action: 'book_venue', actions: ['book_venue'],
    layout: 'left', show_logo: false,
    link: '', target: '', button_text: '', button_color: '',
  }
}

/** The built-in default banner(s) the storefront shows when none are configured —
 *  surfaced in the editor as a ready-made starting point. `platform` = Bookboll
 *  marketplace vs a provider's white-label site; `brandName` personalises the provider one. */
export function defaultBanners({ platform = true, brandName = '' } = {}) {
  if (platform) {
    return normalizeBanners([{
      heading: 'Play more. Hangout. Near you.',
      subheading: 'Book courts, join games and find classes in {city}.',
      audience: 'all', action: 'signin', button_text: 'Sign in to earn rewards',
    }])
  }
  // An EXACT copy of the provider site's default hero (BusinessHome): brand
  // gradient (bg empty → brand colors), centered layout with the logo, the
  // business name as heading, and the two CTAs.
  return normalizeBanners([{
    heading: brandName || 'Welcome',
    subheading: brandName
      ? `Book your next session at ${brandName} — courts, classes and more.`
      : 'Book your next session with us — courts, classes and more.',
    audience: 'all', layout: 'center', show_logo: true,
    actions: ['book_venue', 'get_membership'], button_text: 'Book Now',
  }])
}

/** Coerce stored value (jsonb array or JSON string) into a clean banner array. */
export function normalizeBanners(raw) {
  if (typeof raw === 'string') { try { raw = JSON.parse(raw) } catch { return [] } }
  if (!Array.isArray(raw)) return []
  return raw.map(b => ({
    id: b?.id || newId(),
    heading: b?.heading || '',
    subheading: b?.subheading || '',
    image: b?.image || '',
    icon: b?.icon || '',
    bg: b?.bg || '',
    text_color: b?.text_color || '#ffffff',
    font: b?.font || '',
    heading_color: b?.heading_color || '',   // '' → falls back to text_color
    heading_font: b?.heading_font || '',      // '' → falls back to font
    audience: ['all', 'in', 'out'].includes(b?.audience) ? b.audience : 'all',
    countries: Array.isArray(b?.countries) ? b.countries.filter(Boolean) : [],
    cities: Array.isArray(b?.cities) ? b.cities.filter(Boolean) : [],
    // MULTI-CTA: `actions` is the source of truth; legacy single `action` is
    // kept in sync (first entry) for old readers. Old rows with only `action`
    // migrate transparently.
    actions: Array.isArray(b?.actions) && b.actions.length
      ? b.actions.filter(k => k && k !== 'none' && BANNER_ACTIONS.some(a => a.key === k))
      : (b?.action && b.action !== 'none' ? [b.action] : []),
    action: (Array.isArray(b?.actions) && b.actions.length ? b.actions[0] : b?.action) || 'none',
    layout: b?.layout === 'center' ? 'center' : 'left',
    show_logo: !!b?.show_logo,
    link: b?.link || '',
    target: b?.target || '',   // id of the specific venue/membership/event for deep-link actions
    button_text: b?.button_text || '',
    button_color: b?.button_color || '',
  }))
}

/**
 * Banners to show for the current viewer, filtered by audience AND geo.
 * @param ctx { signedIn, countryId, cityId } (a bare boolean is accepted for signedIn too).
 * Empty countries/cities on a banner = show everywhere.
 */
export function visibleBanners(raw, ctx = {}) {
  const c = typeof ctx === 'boolean' ? { signedIn: ctx } : (ctx || {})
  const { signedIn = false, countryId = null, cityId = null } = c
  return normalizeBanners(raw).filter(b => {
    const audOk = b.audience === 'all' || (b.audience === 'in' && signedIn) || (b.audience === 'out' && !signedIn)
    if (!audOk) return false
    const countryOk = !b.countries.length || (countryId && b.countries.includes(countryId))
    const cityOk = !b.cities.length || (cityId && b.cities.includes(cityId))
    return countryOk && cityOk
  })
}

/** Destination path/URL for a banner's button (custom → its link; a specific-listing
 *  action → its detail page; else the preset route). */
export function bannerLink(b) {
  if (!b) return ''
  if (b.action === 'custom') return b.link || ''
  if (LISTING_ACTION_PATH[b.action]) return b.target ? `${LISTING_ACTION_PATH[b.action]}${b.target}` : ''
  return (BANNER_ACTIONS.find(a => a.key === b.action)?.to) || ''
}

/** Destination for ONE action key on a banner. */
export function bannerLinkFor(b, key) {
  if (!b || !key) return ''
  if (key === 'custom') return b.link || ''
  if (LISTING_ACTION_PATH[key]) return b.target ? `${LISTING_ACTION_PATH[key]}${b.target}` : ''
  return (BANNER_ACTIONS.find(a => a.key === key)?.to) || ''
}

/** All renderable CTA buttons for a banner: [{ key, to, text }]. The banner's
 *  custom button_text overrides the FIRST button's label; the rest use their
 *  action's default label. Actions without a resolvable link are skipped. */
export function bannerButtons(b) {
  const keys = Array.isArray(b?.actions) && b.actions.length
    ? b.actions : (b?.action && b.action !== 'none' ? [b.action] : [])
  const out = []
  for (const key of keys) {
    const to = bannerLinkFor(b, key)
    if (!to) continue
    const text = (out.length === 0 && (b.button_text || '').trim()) || DEFAULT_BUTTON_TEXT[key] || 'Open'
    out.push({ key, to, text })
  }
  return out
}

function hexA(hex, a) {
  const m = String(hex || '').replace('#', '')
  if (!/^[0-9a-f]{6}$/i.test(m)) return `rgba(15,23,42,${a})`
  const n = parseInt(m, 16)
  return `rgba(${(n >> 16) & 255}, ${(n >> 8) & 255}, ${n & 255}, ${a})`
}

/** Background style for a banner: image, flat colour, or — when BOTH are set —
 *  the colour as a translucent OVERLAY tinting the image (keeps text readable). */
export function bannerBgStyle(b) {
  if (b?.image && b?.bg) return { backgroundImage: `linear-gradient(${hexA(b.bg, 0.55)}, ${hexA(b.bg, 0.55)}), url(${b.image})` }
  if (b?.image) return { backgroundImage: `url(${b.image})` }
  if (b?.bg) return { background: b.bg }
  return {}
}

export function bannerButtonText(b) {
  return (b?.button_text || '').trim() || DEFAULT_BUTTON_TEXT[b?.action] || ''
}

/** Does this banner show a button? (action set, a destination resolves, and text exists) */
export function hasButton(b) {
  return !!b && b.action && b.action !== 'none' && !!bannerLink(b) && !!bannerButtonText(b)
}
