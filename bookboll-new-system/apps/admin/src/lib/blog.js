/**
 * blog.js — data + helpers for the marketplace blog.
 *
 * Platform admin CRUD (/blog-posts) uses Laravel /admin/posts.
 * Provider white-label blog (WebsiteBlog) still uses Supabase blog_posts until
 * owner scoping exists on Laravel. Public storefront helpers remain Supabase.
 */
import { supabase } from './supabase.js'
import {
  audienceToType,
  listAdminPosts,
  createAdminPost,
  updateAdminPost,
  deleteAdminPost,
  mapPostFromApi,
  toApiPayload,
  firstError as blogApiError,
} from './blogApi.js'

// ── Slug ─────────────────────────────────────────────────────────────────────
export function slugify(s) {
  return String(s || '')
    .toLowerCase().trim()
    .replace(/['"]/g, '')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 80)
}

// Scope a query to the right blog: a provider's posts (ownerId set) or the Bookboll
// MARKETPLACE posts (ownerId null/undefined → owner_user_id IS NULL).
function scopeOwner(q, ownerId) {
  return ownerId ? q.eq('owner_user_id', ownerId) : q.is('owner_user_id', null)
}
// Bookboll-owned posts split into two blogs (migration 20260766000000):
//   'marketplace' → the customer marketplace blog at /blog (NULL counts as marketplace —
//                   pre-audience rows all belong there)
//   'business'    → the for-businesses landing blog at /for-business/blog
// Provider posts (ownerId set) ignore audience entirely.
function scopeAudience(q, ownerId, audience) {
  if (ownerId) return q
  return audience === 'business'
    ? q.eq('audience', 'business')
    : q.or('audience.is.null,audience.neq.business')
}
// Pre-migration the owner_user_id column doesn't exist. For MARKETPLACE reads we then fall
// back to the unscoped query (all posts = marketplace posts, since no provider posts exist
// yet); provider reads just return empty. Keeps the marketplace blog working before the SQL.
function ownerColMissing(error) {
  return !!error && /owner_user_id/i.test(error.message || '') && /column|schema|exist/i.test(error.message || '')
}
// Same guard for the audience column: marketplace reads fall back to audience-unscoped
// (all Bookboll posts were marketplace posts before the migration); business reads return
// empty until the SQL is run.
function audienceColMissing(error) {
  return !!error && /audience/i.test(error.message || '') && /column|schema|exist/i.test(error.message || '')
}

// ── Reads (public) ───────────────────────────────────────────────────────────
// `ownerId` = a provider's id for their white-label blog, else null = a Bookboll blog,
// picked by `audience` ('marketplace' default, or 'business' for /for-business/blog).
export async function loadPublishedPosts({ limit = 50, ownerId = null, audience = 'marketplace' } = {}) {
  const base = () => supabase.from('blog_posts')
    .select('id, title, slug, excerpt, cover_image, author_name, tags, published_at')
    .eq('status', 'published')
  const order = (q) => q.order('published_at', { ascending: false, nullsFirst: false }).limit(limit)
  try {
    let { data, error } = await order(scopeAudience(scopeOwner(base(), ownerId), ownerId, audience))
    if (error && !ownerId && audienceColMissing(error)) {
      if (audience === 'business') return []                       // no business blog before the SQL
      ;({ data, error } = await order(scopeOwner(base(), ownerId)))
    }
    if (error && !ownerId && ownerColMissing(error)) ({ data, error } = await order(base()))
    if (error) return []
    return data || []
  } catch { return [] }
}

export async function loadPostBySlug(slug, ownerId = null, audience = 'marketplace') {
  const base = () => supabase.from('blog_posts').select('*').eq('slug', slug).eq('status', 'published')
  try {
    let { data, error } = await scopeAudience(scopeOwner(base(), ownerId), ownerId, audience).maybeSingle()
    if (error && !ownerId && audienceColMissing(error)) {
      if (audience === 'business') return null
      ;({ data, error } = await scopeOwner(base(), ownerId).maybeSingle())
    }
    if (error && !ownerId && ownerColMissing(error)) ({ data, error } = await base().maybeSingle())
    return data || null
  } catch { return null }
}

// Count published posts for a scope — drives the storefront Blog-module visibility.
// Only called for providers (ownerId set); errors (pre-migration) ⇒ 0 ⇒ module hidden.
export async function countPublishedPosts(ownerId = null) {
  try {
    const { count } = await scopeOwner(
      supabase.from('blog_posts').select('id', { count: 'exact', head: true }).eq('status', 'published'), ownerId
    )
    return count || 0
  } catch { return 0 }
}

// ── CRUD (admin) ─────────────────────────────────────────────────────────────
// Platform (ownerId null) → Laravel posts filtered by type (main|business).
// Provider (ownerId set) → Supabase blog_posts (WebsiteBlog white-label).
export async function loadAllPosts(ownerId = null, audience = 'marketplace') {
  if (!ownerId) {
    const type = audienceToType(audience)
    const res = await listAdminPosts({ type, records_per_page: 100 })
    if (!res.success) return { error: blogApiError(res, 'Could not load posts.') }
    const page = res.data?.posts
    const rows = Array.isArray(page?.data) ? page.data : (Array.isArray(page) ? page : [])
    return { data: rows.map(mapPostFromApi) }
  }

  const base = () => supabase.from('blog_posts').select('*').order('created_at', { ascending: false })
  try {
    let { data, error } = await scopeAudience(scopeOwner(base(), ownerId), ownerId, audience)
    if (error && !ownerId && audienceColMissing(error)) {
      if (audience === 'business') return { error: 'The business blog needs the audience column — run migration 20260766000000 in Supabase.' }
      ;({ data, error } = await scopeOwner(base(), ownerId))
    }
    if (error && !ownerId && ownerColMissing(error)) ({ data, error } = await base())
    if (error) return { error: error.message }
    return { data: data || [] }
  } catch (e) { return { error: e.message } }
}

export async function savePost(post, ownerId = null, audience = 'marketplace') {
  if (!ownerId) {
    const payload = toApiPayload(post, audience)
    const res = post.id
      ? await updateAdminPost(post.id, payload)
      : await createAdminPost(payload)
    if (!res.success) return { error: blogApiError(res, 'Could not save post.') }
    return { data: mapPostFromApi(res.data?.post) }
  }

  const now = new Date().toISOString()
  const row = {
    title: (post.title || '').trim(),
    slug: (post.slug || '').trim() || slugify(post.title),
    excerpt: post.excerpt || '',
    body: post.body || '',
    cover_image: post.cover_image || '',
    status: post.status === 'published' ? 'published' : 'draft',
    author_name: (post.author_name || '').trim() || 'Bookboll',
    tags: Array.isArray(post.tags) ? post.tags : [],
    meta_title: post.meta_title || '',
    meta_description: post.meta_description || '',
    updated_at: now,
  }
  // Only stamp an owner for provider posts; marketplace posts leave it NULL (and stay
  // writable even before the owner_user_id column is migrated).
  if (ownerId) row.owner_user_id = ownerId
  // Bookboll posts carry their blog (marketplace vs for-business landing). Provider
  // posts don't — their scope is the owner.
  else row.audience = audience === 'business' ? 'business' : 'marketplace'
  // Stamp published_at the first time it goes live.
  if (row.status === 'published' && !post.published_at) row.published_at = now
  if (row.status !== 'published') row.published_at = post.published_at || null
  else if (post.published_at) row.published_at = post.published_at

  const write = (r) => post.id
    ? supabase.from('blog_posts').update(r).eq('id', post.id).select().maybeSingle()
    : supabase.from('blog_posts').insert(r).select().maybeSingle()
  try {
    let { data, error } = await write(row)
    // Pre-migration fallback: marketplace posts still save without the audience column;
    // a business post can't exist yet, so surface the missing migration instead.
    if (error && audienceColMissing(error)) {
      if (row.audience === 'business') return { error: 'The business blog needs the audience column — run migration 20260766000000 in Supabase.' }
      const { audience: _a, ...noAud } = row
      ;({ data, error } = await write(noAud))
    }
    if (error) return { error: error.message }
    return { data }
  } catch (e) { return { error: e.message } }
}

export async function deletePost(id, ownerId = null) {
  if (ownerId == null) {
    // Platform blog — Laravel. WebsiteBlog passes ownerId so stays on Supabase.
    const res = await deleteAdminPost(id)
    if (!res.success) return { error: blogApiError(res, 'Could not delete post.') }
    return { ok: true }
  }
  try {
    const { error } = await supabase.from('blog_posts').delete().eq('id', id)
    if (error) return { error: error.message }
    return { ok: true }
  } catch (e) { return { error: e.message } }
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
