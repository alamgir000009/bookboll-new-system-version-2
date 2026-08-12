/**
 * generate-sitemap.mjs — writes dist/sitemap.xml from LIVE data on every deploy,
 * so new venues, cities and blog posts appear in search without anyone touching
 * a file. English pages carry hreflang alternates to their Arabic /ar twins.
 *
 * Never fails the deploy: on any error the committed public/sitemap.xml (already
 * copied into dist/ by the build) simply stays as-is.
 */
import { writeFileSync } from 'node:fs'
import { collectRoutes, APEX } from './seo-routes.mjs'

const esc = (s) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')

try {
  const { routes, geoOk, note } = await collectRoutes()
  // A sitemap without the city/venue URLs would be a downgrade from the committed
  // fallback — only replace it when the live-data fetch actually worked.
  if (!geoOk) throw new Error(`live data unavailable (${note})`)
  const arSet = new Set(routes.filter(r => r.ar).map(r => r.path))
  const lines = ['<?xml version="1.0" encoding="UTF-8"?>',
    '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">']
  for (const r of routes) {
    lines.push('  <url>')
    lines.push(`    <loc>${esc(APEX + (r.path === '/' ? '/' : r.path))}</loc>`)
    if (r.lastmod) lines.push(`    <lastmod>${r.lastmod}</lastmod>`)
    // hreflang pairing between an English page and its /ar twin (both directions).
    const twin = r.ar ? (r.path === '/ar' ? '/' : r.path.slice(3)) : (r.path === '/' ? '/ar' : `/ar${r.path}`)
    if (r.ar || arSet.has(twin)) {
      const en = r.ar ? APEX + twin : APEX + (r.path === '/' ? '/' : r.path)
      const ar = r.ar ? APEX + r.path : APEX + twin
      lines.push(`    <xhtml:link rel="alternate" hreflang="en" href="${esc(en)}"/>`)
      lines.push(`    <xhtml:link rel="alternate" hreflang="ar" href="${esc(ar)}"/>`)
      lines.push(`    <xhtml:link rel="alternate" hreflang="x-default" href="${esc(en)}"/>`)
    }
    lines.push('  </url>')
  }
  lines.push('</urlset>')
  writeFileSync('dist/sitemap.xml', lines.join('\n') + '\n')
  console.log(`sitemap: ${routes.length} URLs written (${note})`)
} catch (e) {
  console.warn(`sitemap generation skipped — keeping the committed fallback (${e.message})`)
}
