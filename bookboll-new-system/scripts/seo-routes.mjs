/**
 * seo-routes.mjs — the marketplace's public, indexable URLs, collected from LIVE
 * Supabase data (cities, venues, blog posts). Shared by generate-sitemap.mjs and
 * prerender.mjs so the sitemap and the prerendered pages can never disagree.
 *
 * Runs in CI (GitHub Actions) where Supabase is reachable; every fetch is
 * best-effort — a failed query just drops that group of URLs, never the deploy.
 */
import { readFileSync } from 'node:fs'

export const APEX = 'https://bookboll.com'

// Mirrors src/lib/blog.js slugify (city + venue slugs must match the app's).
export function slugify(s) {
  return String(s || '')
    .toLowerCase().trim()
    .replace(/['"]/g, '')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 80)
}

export function loadEnv() {
  const env = { ...process.env }
  try {
    for (const line of readFileSync('.env', 'utf8').split('\n')) {
      const m = line.match(/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/)
      if (m && env[m[1]] == null) env[m[1]] = m[2].trim()
    }
  } catch { /* .env optional when real env vars are set */ }
  return env
}

async function rows(env, table, query) {
  const res = await fetch(`${env.VITE_SUPABASE_URL}/rest/v1/${table}?${query}`, {
    headers: { apikey: env.VITE_SUPABASE_ANON_KEY, Authorization: `Bearer ${env.VITE_SUPABASE_ANON_KEY}` },
  })
  if (!res.ok) throw new Error(`${table}: HTTP ${res.status}`)
  return res.json()
}

// Marketplace-scope blog posts (owner_user_id null, not business-audience);
// pre-migration DBs lack the audience/owner columns — retry progressively wider.
async function blogPosts(env) {
  try { return await rows(env, 'blog_posts', 'select=slug,published_at&status=eq.published&owner_user_id=is.null&or=(audience.is.null,audience.neq.business)&order=published_at.desc&limit=500') }
  catch { /* audience column missing */ }
  try { return await rows(env, 'blog_posts', 'select=slug,published_at&status=eq.published&owner_user_id=is.null&order=published_at.desc&limit=500') }
  catch { return rows(env, 'blog_posts', 'select=slug,published_at&status=eq.published&order=published_at.desc&limit=500') }
}

// For-businesses landing blog (audience='business'). Pre-migration → none exist.
async function businessBlogPosts(env) {
  try { return await rows(env, 'blog_posts', 'select=slug,published_at&status=eq.published&owner_user_id=is.null&audience=eq.business&order=published_at.desc&limit=500') }
  catch { return [] }
}

const STATIC_ROUTES = [
  '/', '/facilities', '/programs', '/games', '/communities', '/blog',
  '/venues', '/memberships', '/terms',
  '/for-business', '/for-business/pricing', '/for-business/blog', '/for-business/terms',
]

/**
 * @returns {Promise<{routes: Array<{path, lastmod?, ar?}>, note: string}>}
 * `ar: true` marks the Arabic twin of a page (path already carries the /ar prefix).
 */
export async function collectRoutes() {
  const env = loadEnv()
  const routes = STATIC_ROUTES.map(p => ({ path: p }))
  const notes = []
  let geoOk = false

  let cityVenuePaths = []
  try {
    const [countries, cities, locations] = await Promise.all([
      rows(env, 'countries', 'select=id&status=eq.active'),
      rows(env, 'cities', 'select=id,name,country_id&status=eq.active'),
      rows(env, 'locations', 'select=id,name,city_id,created_at&status=eq.active&limit=1000'),
    ])
    const activeCountry = new Set(countries.map(c => c.id))
    const cityHasVenue = new Set(locations.map(l => l.city_id))
    const liveCities = cities.filter(c => activeCountry.has(c.country_id) && cityHasVenue.has(c.id))
    for (const c of liveCities) {
      const slug = slugify(c.name)
      cityVenuePaths.push(`/venues/${slug}`)
      routes.push({ path: `/venues/${slug}` })
      routes.push({ path: `/memberships/${slug}` })
    }
    // Venue detail pages — keyword slug after the id (the app 301s bare-id URLs here).
    for (const l of locations.slice(0, 500)) {
      routes.push({ path: `/facilities/${l.id}/${slugify(l.name)}`, lastmod: (l.created_at || '').slice(0, 10) || undefined })
    }
    notes.push(`${liveCities.length} cities, ${Math.min(locations.length, 500)} venues`)
    geoOk = true
  } catch (e) { notes.push(`geo skipped (${e.message})`) }

  try {
    const posts = await blogPosts(env)
    for (const p of posts) if (p.slug) routes.push({ path: `/blog/${p.slug}`, lastmod: (p.published_at || '').slice(0, 10) || undefined })
    notes.push(`${posts.length} blog posts`)
  } catch (e) { notes.push(`blog skipped (${e.message})`) }

  try {
    const bizPosts = await businessBlogPosts(env)
    for (const p of bizPosts) if (p.slug) routes.push({ path: `/for-business/blog/${p.slug}`, lastmod: (p.published_at || '').slice(0, 10) || undefined })
    notes.push(`${bizPosts.length} business blog posts`)
  } catch (e) { notes.push(`business blog skipped (${e.message})`) }

  // Arabic twins — the key discovery pages only (translation happens at render).
  const AR_PAGES = ['/', '/facilities', '/programs', '/games', '/for-business', '/for-business/pricing', ...cityVenuePaths.slice(0, 12)]
  for (const p of AR_PAGES) routes.push({ path: p === '/' ? '/ar' : `/ar${p}`, ar: true })

  return { routes, geoOk, note: notes.join(' · ') }
}
