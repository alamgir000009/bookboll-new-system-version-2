/**
 * gen-sitemap.mjs — build-time sitemap generator.
 *
 * Reads Supabase creds from .env (the same anon key the storefront uses) and writes
 * public/sitemap.xml with the static marketing routes plus every public venue, court,
 * program and event/tournament detail URL. Runs as part of `npm run build` so the
 * sitemap refreshes on each deploy. Best-effort: any DB error still emits the static
 * routes so the build never fails on this.
 */
import { createClient } from '@supabase/supabase-js'
import { readFileSync, writeFileSync, existsSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'

const __dirname = dirname(fileURLToPath(import.meta.url))
const ROOT = resolve(__dirname, '..')
const SITE = (process.env.SITE_URL || 'https://bookboll.com').replace(/\/$/, '')

// Minimal .env reader (avoids adding dotenv).
function readEnv() {
  const out = {}
  const p = resolve(ROOT, '.env')
  if (!existsSync(p)) return out
  for (const line of readFileSync(p, 'utf8').split('\n')) {
    const m = line.match(/^\s*([A-Z0-9_]+)\s*=\s*(.*)\s*$/)
    if (m) out[m[1]] = m[2].replace(/^['"]|['"]$/g, '')
  }
  return out
}

const env = readEnv()
const URL = process.env.VITE_SUPABASE_URL || env.VITE_SUPABASE_URL
const KEY = process.env.VITE_SUPABASE_ANON_KEY || env.VITE_SUPABASE_ANON_KEY

const urls = new Map()   // loc -> lastmod (dedup)
const add = (path, lastmod) => { if (path) urls.set(`${SITE}${path}`, lastmod || null) }

// Matches src/lib/blog.js slugify (city + sport landing slugs).
const slug = (s) => String(s || '').toLowerCase().trim()
  .replace(/['"]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 80)

// Static marketing routes.
;['/', '/facilities', '/programs', '/games', '/host-game', '/blog', '/venues', '/memberships', '/terms'].forEach(p => add(p))

async function collect() {
  if (!URL || !KEY) { console.warn('[sitemap] No Supabase creds — writing static routes only.'); return }
  const sb = createClient(URL, KEY)
  // Supabase returns query errors in `.error` (no throw) — surface those too.
  const safe = async (label, fn) => {
    try { const r = await fn(); if (r.error) console.warn(`[sitemap] ${label}:`, r.error.message); return r.data || [] }
    catch (e) { console.warn(`[sitemap] ${label}:`, e.message); return [] }
  }

  const [locs, allListings, allPrograms, games, posts, cities, countries] = await Promise.all([
    safe('locations', () => sb.from('locations').select('id, city_id, created_at').eq('status', 'active')),
    safe('listings', () => sb.from('listings').select('id, location_id, category, sport_category, created_at, show_on_marketplace, approval_status, eligibility_status')),
    safe('class_programs', () => sb.from('class_programs').select('id, location_id, category, sport_category, created_at, show_on_marketplace, approval_status, eligibility_status').eq('status', 'active')),
    safe('hosted_games', () => sb.from('hosted_games').select('id, game_type, created_at, status, game_date, moderation_status')),
    safe('blog_posts', () => sb.from('blog_posts').select('slug, published_at').eq('status', 'published')),
    safe('cities', () => sb.from('cities').select('id, name, country_id').eq('status', 'active')),
    safe('countries', () => sb.from('countries').select('id, name').eq('status', 'active')),
  ])

  // Marketplace visibility — same rule as the storefront (data.js listingVisible /
  // programVisible on the apex): flagged for the marketplace AND approved AND not
  // paused. Missing values count as visible (pre-migration rows). The sitemap only
  // advertises what the marketplace actually shows.
  const visible = (r) => r.show_on_marketplace !== false
    && (r.approval_status || 'approved') === 'approved'
    && (r.eligibility_status || 'eligible') !== 'paused'
  const listings = allListings.filter(visible)
  const programs = allPrograms.filter(visible)

  // A venue page is only listed when the venue has ≥1 marketplace-visible listing —
  // empty/unapproved venues are hidden on the marketplace, so don't send crawlers there.
  const bookableLocs = new Set(listings.map(l => l.location_id))
  locs.forEach(r => { if (bookableLocs.has(r.id)) add(`/facilities/${r.id}`, r.created_at) })
  listings.forEach(r => add(`/listings/${r.id}`, r.created_at))
  programs.forEach(r => add(`/programs/${r.id}`, r.created_at))
  // Only advertise games that are still joinable: open, not admin-removed, and not
  // in the past — otherwise crawlers hit dead/soft-404 event pages.
  const todayISO = new Date().toISOString().slice(0, 10)
  const gameVisible = (r) => (r.status || 'open') === 'open'
    && (r.moderation_status || 'active') !== 'removed'
    && (!r.game_date || r.game_date >= todayISO)
  games.filter(gameVisible).forEach(r => add(r.game_type === 'tournament' ? `/tournaments/${r.id}` : `/games/${r.id}`, r.created_at))
  posts.forEach(r => add(`/blog/${r.slug}`, r.published_at))

  // City/country × (category|sport) landing pages, from active venues only.
  const cityById = Object.fromEntries(cities.map(c => [c.id, c]))
  const countryName = Object.fromEntries(countries.map(c => [c.id, c.name]))
  const cityOfLoc = Object.fromEntries(locs.map(l => [l.id, l.city_id]))
  const cityHas = new Set(), combo = new Set(), catCombo = new Set()        // city-level
  const ctyHas = new Set(), ctyCombo = new Set()                            // country-level
  listings.forEach(l => {
    const cid = cityOfLoc[l.location_id]; if (!cid) return
    cityHas.add(cid)
    if (l.sport_category) combo.add(`${cid}|${l.sport_category}`)
    catCombo.add(`${cid}|${l.category || 'sports'}`)
    const coid = cityById[cid]?.country_id
    if (coid) { ctyHas.add(coid); if (l.sport_category) ctyCombo.add(`${coid}|${l.sport_category}`) }
  })
  cityHas.forEach(cid => { const n = cityById[cid]?.name; if (n) add(`/venues/${slug(n)}`) })
  catCombo.forEach(key => { const [cid, cat] = key.split('|'); const n = cityById[cid]?.name; if (n && cat) add(`/venues/${slug(n)}/${slug(cat)}`) })
  combo.forEach(key => { const [cid, sport] = key.split('|'); const n = cityById[cid]?.name; if (n && sport) add(`/venues/${slug(n)}/${slug(sport)}`) })
  ctyHas.forEach(coid => { const n = countryName[coid]; if (n) add(`/venues/${slug(n)}`) })
  ctyCombo.forEach(key => { const [coid, sport] = key.split('|'); const n = countryName[coid]; if (n && sport) add(`/venues/${slug(n)}/${slug(sport)}`) })

  // Membership (gym/class/academy) landing pages — city + country, category + type.
  const mCityHas = new Set(), mCombo = new Set(), mCatCombo = new Set()
  const mCtyHas = new Set(), mCtyCombo = new Set(), mCtyCat = new Set()
  programs.forEach(p => {
    const cid = cityOfLoc[p.location_id]; if (!cid) return
    mCityHas.add(cid)
    mCatCombo.add(`${cid}|${p.category || 'academy'}`)
    if (p.sport_category) mCombo.add(`${cid}|${p.sport_category}`)
    const coid = cityById[cid]?.country_id
    if (coid) { mCtyHas.add(coid); mCtyCat.add(`${coid}|${p.category || 'academy'}`); if (p.sport_category) mCtyCombo.add(`${coid}|${p.sport_category}`) }
  })
  mCityHas.forEach(cid => { const n = cityById[cid]?.name; if (n) add(`/memberships/${slug(n)}`) })
  mCatCombo.forEach(key => { const [cid, cat] = key.split('|'); const n = cityById[cid]?.name; if (n && cat) add(`/memberships/${slug(n)}/${slug(cat)}`) })
  mCombo.forEach(key => { const [cid, t] = key.split('|'); const n = cityById[cid]?.name; if (n && t) add(`/memberships/${slug(n)}/${slug(t)}`) })
  mCtyHas.forEach(coid => { const n = countryName[coid]; if (n) add(`/memberships/${slug(n)}`) })
  mCtyCat.forEach(key => { const [coid, cat] = key.split('|'); const n = countryName[coid]; if (n && cat) add(`/memberships/${slug(n)}/${slug(cat)}`) })
  mCtyCombo.forEach(key => { const [coid, t] = key.split('|'); const n = countryName[coid]; if (n && t) add(`/memberships/${slug(n)}/${slug(t)}`) })
}

function xml() {
  const body = [...urls.entries()].map(([loc, lastmod]) => {
    const lm = lastmod ? `\n    <lastmod>${new Date(lastmod).toISOString().slice(0, 10)}</lastmod>` : ''
    return `  <url>\n    <loc>${loc}</loc>${lm}\n  </url>`
  }).join('\n')
  return `<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n${body}\n</urlset>\n`
}

await collect()
const out = resolve(ROOT, 'public', 'sitemap.xml')
writeFileSync(out, xml())
console.log(`[sitemap] Wrote ${urls.size} URLs to public/sitemap.xml`)
