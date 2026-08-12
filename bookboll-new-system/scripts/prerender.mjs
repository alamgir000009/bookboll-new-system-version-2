/**
 * prerender.mjs — snapshots the public marketplace pages into static HTML after
 * the Vite build, so Google/Bing/AI crawlers get full content + meta + JSON-LD
 * without executing JavaScript. The SPA still boots on top of each snapshot
 * (Vue mounts on #app), so nothing changes for real visitors.
 *
 * Runs in CI after `vite build`:
 *   node scripts/prerender.mjs --base=/bookboll-admin-dashboard/
 * Serves dist/ locally, drives headless Chromium (Playwright) over the public
 * routes from seo-routes.mjs, and writes dist/<route>/index.html per page.
 * Arabic /ar pages wait for the in-page translation before the snapshot, so the
 * saved HTML is real Arabic. Every page is best-effort — a failed route is
 * logged and skipped, the deploy never breaks.
 */
import http from 'node:http'
import { readFileSync, writeFileSync, mkdirSync, existsSync } from 'node:fs'
import { join, extname, normalize } from 'node:path'
import { collectRoutes } from './seo-routes.mjs'

const DIST = 'dist'
const BASE = (process.argv.find(a => a.startsWith('--base=')) || '--base=/').slice(7)
const CONCURRENCY = 4
const MIME = { '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.svg': 'image/svg+xml', '.ico': 'image/x-icon', '.txt': 'text/plain', '.xml': 'application/xml', '.webp': 'image/webp', '.jpg': 'image/jpeg' }

// ── Static server over dist/ with SPA fallback (mirrors GitHub Pages + 404.html) ──
const fallback = readFileSync(join(DIST, 'index.html'))
const server = http.createServer((req, res) => {
  let p = decodeURIComponent(new URL(req.url, 'http://x').pathname)
  if (p.startsWith(BASE)) p = '/' + p.slice(BASE.length)
  const file = normalize(join(DIST, p)).replace(/\\/g, '/')
  if (!file.startsWith(DIST)) { res.writeHead(403); return res.end() }
  try {
    const body = readFileSync(file)
    res.writeHead(200, { 'content-type': MIME[extname(file)] || 'application/octet-stream' })
    res.end(body)
  } catch {
    res.writeHead(200, { 'content-type': 'text/html' })
    res.end(fallback)
  }
})
await new Promise(r => server.listen(0, r))
const PORT = server.address().port

// Playwright: playwright-core is a devDependency; PLAYWRIGHT_MODULE overrides for local runs.
let pw
if (process.env.PLAYWRIGHT_MODULE) pw = await import(process.env.PLAYWRIGHT_MODULE)
else { try { pw = await import('playwright-core') } catch { pw = await import('playwright') } }
if (!pw.chromium && pw.default?.chromium) pw = pw.default   // CJS default-export shape
const browser = await pw.chromium.launch({ executablePath: process.env.PRERENDER_CHROMIUM || undefined })

let routes
try { ({ routes } = await collectRoutes()) }
catch (e) { console.warn(`route collection failed (${e.message}) — prerendering static pages only`); routes = ['/', '/facilities', '/programs', '/games', '/for-business', '/for-business/pricing'].map(p => ({ path: p })) }
if (routes.length > 600) routes = routes.slice(0, 600)

let done = 0, failed = 0
async function renderOne(context, r) {
  const page = await context.newPage()
  try {
    // Only same-origin traffic (the SPA + Supabase + translation API) matters; block heavy images.
    await page.route('**/*.{png,jpg,jpeg,webp,avif}', rt => rt.abort().catch(() => {}))
    const url = `http://127.0.0.1:${PORT}${BASE}${r.path === '/' ? '' : r.path.slice(1)}`
    await page.goto(url, { waitUntil: 'networkidle', timeout: 45000 })
    // Wait for the SPA to actually mount (brand + auth guards resolve first).
    await page.waitForFunction(() => { const a = document.getElementById('app'); return !!a && a.children.length > 0 }, { timeout: 25000 }).catch(() => {})
    if (r.ar) {
      // Give the in-page auto-translation time to produce Arabic text.
      await page.waitForFunction(() => document.documentElement.getAttribute('dir') === 'rtl', { timeout: 15000 }).catch(() => {})
      await page.waitForTimeout(9000)
    } else {
      await page.waitForTimeout(1500)   // late paints (async SEO heads, fonts) settle
    }
    const html = await page.content()
    const outDir = r.path === '/' ? DIST : join(DIST, r.path.slice(1))
    if (!existsSync(outDir)) mkdirSync(outDir, { recursive: true })
    writeFileSync(join(outDir, 'index.html'), html)
    done++
  } catch (e) {
    failed++
    console.warn(`  ✗ ${r.path} — ${e.message.split('\n')[0]}`)
  } finally {
    await page.close().catch(() => {})
  }
}

const context = await browser.newContext({ viewport: { width: 1280, height: 900 } })
const queue = [...routes]
await Promise.all(Array.from({ length: CONCURRENCY }, async () => {
  while (queue.length) await renderOne(context, queue.shift())
}))

await browser.close()
server.close()
console.log(`prerender: ${done} pages written, ${failed} skipped (of ${routes.length})`)
