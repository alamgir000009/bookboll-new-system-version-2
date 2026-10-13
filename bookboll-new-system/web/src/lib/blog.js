/**
 * blog.js — data + helpers for the marketplace blog (Laravel-backed).
 *
 * Posts come from the public endpoints /blog-articles and /blog-articles/{slug}.
 * A white-label site passes ?brand_slug= and gets that provider's own blog; without
 * it you get the Bookboll platform blogs, split by `type` ('main' = the marketplace
 * blog at /blog, 'business' = the for-businesses blog).
 *
 * Bodies are HTML from the admin editor and are rendered through the XSS-safe
 * sanitizer below; older markdown posts still render via renderMarkdown.
 */
import { apiFetch } from './api.js'

// ── Slug ─────────────────────────────────────────────────────────────────────
export function slugify(s) {
  return String(s || '')
    .toLowerCase().trim()
    .replace(/['"]/g, '')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 80)
}

const POST_TYPE = { marketplace: 'main', business: 'business' }

/** The brand whose blog to read — set on a white-label site, empty on the apex.
 *  Imported lazily: brand.js imports countPublishedPosts from here. */
async function brandSlug() {
  try {
    const { activeBrand } = await import('./brand.js')
    return activeBrand.value?.slug || ''
  } catch { return '' }
}

/** Laravel post row → the shape the blog pages read. */
function mapPost(p) {
  if (!p) return null
  const tags = Array.isArray(p.tags)
    ? p.tags.map(t => (typeof t === 'string' ? t : t?.name)).filter(Boolean)
    : []
  return {
    id: p.id,
    title: p.title || '',
    slug: p.slug || '',
    excerpt: p.excerpt || '',
    body: p.content || '',
    cover_image: p.featured_image || '',
    author_name: p.author?.name || 'Bookboll',
    tags,
    meta_title: p.seo_title || '',
    meta_description: p.seo_description || '',
    published_at: p.published_at || null,
    updated_at: p.updated_at || null,
  }
}

// ── Reads (public) ───────────────────────────────────────────────────────────
export async function loadPublishedPosts({ limit = 50, audience = 'marketplace' } = {}) {
  const slug = await brandSlug()
  const res = await apiFetch('/blog-articles', {
    query: { type: POST_TYPE[audience] || 'main', records_per_page: limit, ...(slug ? { brand_slug: slug } : {}) },
  })
  if (!res.success) return []
  return (res.data?.blogArticles?.data || []).map(mapPost).filter(Boolean)
}

export async function loadPostBySlug(slug, audience = 'marketplace') {
  if (!slug) return null
  const brand = await brandSlug()
  const res = await apiFetch(`/blog-articles/${encodeURIComponent(slug)}`, {
    query: { type: POST_TYPE[audience] || 'main', ...(brand ? { brand_slug: brand } : {}) },
  })
  return res.success ? mapPost(res.data?.post) : null
}

/** Published-post count for the active blog — drives Blog-module visibility. */
export async function countPublishedPosts() {
  return (await loadPublishedPosts({ limit: 100 })).length
}

// ── Markdown (safe subset) ───────────────────────────────────────────────────
function esc(s) {
  return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
}
function safeUrl(u) {
  const v = String(u || '').trim()
  return /^(https?:\/\/|\/|#|mailto:)/i.test(v) ? v : ''
}
function inline(s) {
  // images ![alt](url)
  s = s.replace(/!\[([^\]]*)\]\(([^)\s]+)\)/g, (_, alt, url) => {
    const u = safeUrl(url); return u ? `<img src="${u}" alt="${alt}" loading="lazy" />` : alt
  })
  // links [text](url)
  s = s.replace(/\[([^\]]+)\]\(([^)\s]+)\)/g, (_, txt, url) => {
    const u = safeUrl(url); if (!u) return txt
    const ext = /^https?:\/\//i.test(u)
    return `<a href="${u}"${ext ? ' target="_blank" rel="noopener nofollow"' : ''}>${txt}</a>`
  })
  s = s.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
  s = s.replace(/(^|[^*])\*([^*]+)\*/g, '$1<em>$2</em>')
  s = s.replace(/`([^`]+)`/g, '<code>$1</code>')
  return s
}
export function renderMarkdown(md) {
  const lines = esc(md || '').replace(/\r\n/g, '\n').split('\n')
  const out = []
  let para = [], list = null   // list = 'ul' | 'ol' | null
  const flushPara = () => { if (para.length) { out.push(`<p>${inline(para.join(' '))}</p>`); para = [] } }
  const flushList = () => { if (list) { out.push(`</${list}>`); list = null } }
  for (const raw of lines) {
    const line = raw.trimEnd()
    const h = line.match(/^(#{1,3})\s+(.*)$/)
    const ul = line.match(/^[-*]\s+(.*)$/)
    const ol = line.match(/^\d+\.\s+(.*)$/)
    const quote = line.match(/^&gt;\s+(.*)$/)
    if (!line.trim()) { flushPara(); flushList(); continue }
    if (h) { flushPara(); flushList(); const lvl = h[1].length + 1; out.push(`<h${lvl}>${inline(h[2])}</h${lvl}>`); continue }
    if (ul) { flushPara(); if (list !== 'ul') { flushList(); out.push('<ul>'); list = 'ul' } out.push(`<li>${inline(ul[1])}</li>`); continue }
    if (ol) { flushPara(); if (list !== 'ol') { flushList(); out.push('<ol>'); list = 'ol' } out.push(`<li>${inline(ol[1])}</li>`); continue }
    if (quote) { flushPara(); flushList(); out.push(`<blockquote>${inline(quote[1])}</blockquote>`); continue }
    flushList(); para.push(line.trim())
  }
  flushPara(); flushList()
  return out.join('\n')
}

// ── Safe HTML (rich-text editor output) ──────────────────────────────────────
// The admin editor produces HTML. We never trust it: a strict allowlist sanitizer
// (DOMParser-based) strips scripts, event handlers and unknown tags/attrs, and only
// keeps safe inline styles (color / background-color / text-align). Runs client-side.
const ALLOWED_TAGS = new Set(['p','br','h1','h2','h3','h4','strong','b','em','i','u','s','strike','ul','ol','li','blockquote','a','img','span','div','code','pre'])
const DROP_TAGS = new Set(['script','style','iframe','object','embed','link','meta','noscript','template','svg','math','form','input','button'])
const ALLOWED_ATTR = { a: ['href'], img: ['src','alt'], span: ['style'], p: ['style'], div: ['style'], li: ['style'], h1: ['style'], h2: ['style'], h3: ['style'], h4: ['style'], blockquote: ['style'], strong: ['style'], em: ['style'], u: ['style'], s: ['style'] }

function safeStyle(value) {
  const out = []
  for (const decl of String(value || '').split(';')) {
    const i = decl.indexOf(':'); if (i < 0) continue
    const prop = decl.slice(0, i).trim().toLowerCase()
    const val = decl.slice(i + 1).trim()
    if (!['color', 'background-color', 'text-align'].includes(prop)) continue
    if (/url\(|expression|javascript:|@import/i.test(val)) continue
    if (prop === 'text-align' && !/^(left|right|center|justify)$/i.test(val)) continue
    if ((prop === 'color' || prop === 'background-color') &&
        !/^(#[0-9a-f]{3,8}|rgba?\([\d.,\s%]+\)|transparent|[a-z]+)$/i.test(val)) continue
    out.push(`${prop}: ${val}`)
  }
  return out.join('; ')
}

export function sanitizeHtml(dirty) {
  if (typeof window === 'undefined' || !dirty) return ''
  const doc = new DOMParser().parseFromString(`<div>${dirty}</div>`, 'text/html')
  const root = doc.body.firstChild
  const clean = (node) => {
    for (const child of [...node.childNodes]) {
      if (child.nodeType === 3) continue                 // text — keep
      if (child.nodeType !== 1) { child.remove(); continue }  // comments etc — drop
      const tag = child.tagName.toLowerCase()
      if (DROP_TAGS.has(tag)) { child.remove(); continue }    // drop entirely (incl. contents)
      clean(child)                                        // sanitize descendants first
      if (!ALLOWED_TAGS.has(tag)) {                       // unknown tag → unwrap (keep cleaned children)
        while (child.firstChild) node.insertBefore(child.firstChild, child)
        child.remove(); continue
      }
      const allowed = ALLOWED_ATTR[tag] || []
      for (const attr of [...child.attributes]) {
        const name = attr.name.toLowerCase()
        if (name.startsWith('on') || !allowed.includes(name)) { child.removeAttribute(attr.name); continue }
        if ((name === 'href' || name === 'src') && !/^(https?:\/\/|\/|#|mailto:)/i.test(attr.value.trim())) child.removeAttribute(attr.name)
        if (name === 'style') { const s = safeStyle(attr.value); s ? child.setAttribute('style', s) : child.removeAttribute('style') }
      }
      if (tag === 'a' && /^https?:/i.test(child.getAttribute('href') || '')) {
        child.setAttribute('target', '_blank'); child.setAttribute('rel', 'noopener nofollow')
      }
    }
  }
  clean(root)
  return root.innerHTML
}

// Render a stored post body: rich-text HTML is sanitized; legacy markdown posts still
// render via the markdown renderer (detected by the absence of block-level HTML tags).
export function renderPostBody(body) {
  const s = String(body || '')
  return /<\/?(p|h[1-4]|ul|ol|li|blockquote|div|span|strong|em|b|i|u|a|img|br)\b/i.test(s)
    ? sanitizeHtml(s) : renderMarkdown(s)
}

// "Mar 5, 2026" style date.
export function fmtPostDate(d) {
  if (!d) return ''
  try { return new Date(d).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' }) } catch { return '' }
}
