/**
 * Platform blog admin API — Laravel /admin/posts
 * (Marketplace type=main · For Business type=business)
 */
import { apiFetch } from './api.js'

export function audienceToType(audience) {
  return audience === 'business' ? 'business' : 'main'
}

export function mapPostFromApi(post) {
  if (!post) return null
  const tags = Array.isArray(post.tags)
    ? post.tags.map((t) => (typeof t === 'string' ? t : (t?.name || ''))).filter(Boolean)
    : []
  return {
    id: post.id,
    title: post.title || '',
    slug: post.slug || '',
    excerpt: post.excerpt || '',
    body: post.content || '',
    cover_image: post.featured_image || '',
    status: post.status === 'published' ? 'published' : 'draft',
    author_name: post.author?.name || post.author_name || 'Bookboll',
    tags,
    meta_title: post.seo_title || '',
    meta_description: post.seo_description || '',
    published_at: post.published_at || null,
    type: post.type || 'main',
    created_at: post.created_at || null,
    updated_at: post.updated_at || null,
  }
}

export function firstError(res, fallback = 'Request failed') {
  if (!res) return fallback
  if (res.errors && typeof res.errors === 'object') {
    const first = Object.values(res.errors).flat()[0]
    if (first) return String(first)
  }
  return res.message || fallback
}

export async function listAdminPosts({ type = 'main', records_per_page = 100 } = {}) {
  return apiFetch('/admin/posts', {
    query: { type, records_per_page },
  })
}

export async function createAdminPost(body) {
  return apiFetch('/admin/posts', { method: 'POST', body })
}

export async function updateAdminPost(id, body) {
  return apiFetch(`/admin/posts/${id}`, { method: 'PUT', body })
}

export async function deleteAdminPost(id) {
  return apiFetch(`/admin/posts/${id}`, { method: 'DELETE' })
}

/** Build Laravel create/update payload from Blog.vue form shape. */
export function toApiPayload(post, audience = 'marketplace') {
  const type = audienceToType(audience)
  const status = post.status === 'published' ? 'published' : 'draft'
  const payload = {
    title: (post.title || '').trim(),
    slug: (post.slug || '').trim() || undefined,
    excerpt: post.excerpt || '',
    content: post.body || '',
    status,
    type,
    tags: Array.isArray(post.tags) ? post.tags : [],
    seo_title: post.meta_title || '',
    seo_description: post.meta_description || '',
    featured: false,
    read_time: post.read_time || '5 min read',
  }
  const cover = (post.cover_image || '').trim()
  if (cover) payload.image_url = cover
  return payload
}

/* ── A provider's own site blog ────────────────────────────────────────────────
   Same wire shape as the Bookboll blog above, on a separate, owner-scoped route:
   the server takes the owner from the session, so the two blogs cannot mix.      */

export function listSiteBlogPosts() {
  return apiFetch('/admin/site-blog')
}

export function createSiteBlogPost(body) {
  return apiFetch('/admin/site-blog', { method: 'POST', body })
}

export function updateSiteBlogPost(id, body) {
  return apiFetch(`/admin/site-blog/${id}`, { method: 'PUT', body })
}

export function deleteSiteBlogPost(id) {
  return apiFetch(`/admin/site-blog/${id}`, { method: 'DELETE' })
}
