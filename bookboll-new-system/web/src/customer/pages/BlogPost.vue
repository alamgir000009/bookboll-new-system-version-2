<script setup>
/** BlogPost.vue — public single article (markdown body), with Article SEO. */
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute } from 'vue-router'
import { loadPostBySlug, renderPostBody, fmtPostDate } from '../../lib/blog.js'
import { useSeo } from '../../lib/seo.js'
import { platformBrand, activeBrand, isWhiteLabel } from '../../lib/brand.js'
import { ArrowLeft, Newspaper, CalendarDays, User, MapPin } from 'lucide-vue-next'
import { loadDirectory, slugifyCity } from '../../lib/cityPages.js'

const route = useRoute()
const post = ref(null)
const loading = ref(true)
// Internal links to the city landing pages — every post passes link equity to
// the pages that actually rank ("padel courts in Doha" etc.). Marketplace only.
const linkCities = ref([])
onMounted(async () => {
  if (isWhiteLabel.value) return
  try {
    const dir = await loadDirectory()
    linkCities.value = dir.flatMap(g => g.cities).sort((a, b) => b.count - a.count).slice(0, 8)
      .map(c => ({ name: c.name, slug: slugifyCity(c.name) }))
  } catch { /* section simply hidden */ }
})
const brandName = computed(() => (isWhiteLabel.value ? activeBrand.value?.name : platformBrand.value?.name) || 'Bookboll')

async function load() { loading.value = true; post.value = await loadPostBySlug(route.params.slug); loading.value = false }
onMounted(load)
watch(() => route.params.slug, load)

const html = computed(() => post.value ? renderPostBody(post.value.body) : '')

useSeo(() => {
  if (!post.value) return { path: route.path, noindex: !loading.value }   // 404 → noindex
  const p = post.value
  return {
    title: p.meta_title || p.title,
    description: p.meta_description || p.excerpt || `${p.title} — on the ${brandName} blog.`,
    image: p.cover_image || undefined,
    path: route.path,
    type: 'article',
    noindex: isWhiteLabel.value,
    jsonLd: {
      '@context': 'https://schema.org',
      '@type': 'Article',
      headline: p.title,
      ...(p.excerpt ? { description: p.excerpt } : {}),
      ...(p.cover_image ? { image: p.cover_image } : {}),
      ...(p.published_at ? { datePublished: p.published_at } : {}),
      ...(p.updated_at ? { dateModified: p.updated_at } : {}),
      author: { '@type': 'Organization', name: p.author_name || brandName.value },
      publisher: { '@type': 'Organization', name: brandName.value },
      mainEntityOfPage: `https://bookboll.com${route.path}`,
    },
  }
})
</script>

<template>
  <div class="bp">
    <router-link to="/blog" class="cback"><ArrowLeft :size="16" /> All posts</router-link>
    <div v-if="loading" class="cmsg">Loading…</div>
    <div v-else-if="!post" class="cmsg">Post not found.</div>

    <article v-else class="bp-article">
      <div v-if="post.tags?.length" class="bp-tags"><span v-for="t in post.tags" :key="t" class="bp-tag">{{ t }}</span></div>
      <h1 class="bp-title">{{ post.title }}</h1>
      <div class="bp-meta">
        <span><User :size="14" /> {{ post.author_name }}</span>
        <span v-if="post.published_at"><CalendarDays :size="14" /> {{ fmtPostDate(post.published_at) }}</span>
      </div>
      <div v-if="post.cover_image" class="bp-cover" :style="{ backgroundImage: `url(${post.cover_image})` }"></div>
      <p v-if="post.excerpt" class="bp-lede">{{ post.excerpt }}</p>
      <div class="blog-content" v-html="html"></div>

      <!-- Cross-links into the city landing pages (marketplace only) -->
      <div v-if="!isWhiteLabel && linkCities.length" class="bp-cities">
        <div class="bp-cities-h"><MapPin :size="14" /> Book sports venues by city</div>
        <div class="bp-cities-row">
          <router-link v-for="c in linkCities" :key="c.slug" :to="`/venues/${c.slug}`" class="bp-city">{{ c.name }}</router-link>
        </div>
      </div>
    </article>
  </div>
</template>

<style scoped>
.cback { display: inline-flex; align-items: center; gap: 6px; color: var(--brand-ink, var(--brand)); text-decoration: none; font-weight: 700; font-size: 14px; margin-bottom: 16px; }
/* City cross-links under the article — quiet ghost pills (home-page register) */
.bp-cities { margin-top: 34px; padding-top: 18px; border-top: 1px solid var(--border, #e8edf3); }
.bp-cities-h { display: flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 800; color: var(--text-secondary, #334155); margin-bottom: 10px; }
.bp-cities-h svg { color: var(--brand-ink, var(--brand)); }
.bp-cities-row { display: flex; flex-wrap: wrap; gap: 7px; }
.bp-city { font-size: 12.5px; font-weight: 600; color: var(--text-muted, #64748b); border: 1px solid var(--border, #e8edf3); border-radius: 999px; padding: 6px 13px; text-decoration: none; transition: border-color .12s, color .12s; }
.bp-city:hover { border-color: var(--brand-accent); color: var(--brand-ink, var(--brand)); }
.cmsg { text-align: center; padding: 50px; color: var(--text-light, #94a3b8); }
.bp-article { max-width: 720px; margin: 0 auto; }
.bp-tags { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 12px; }
.bp-tag { font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: .04em; color: var(--brand-ink, var(--brand)); background: color-mix(in srgb, var(--brand) 12%, var(--surface, white)); padding: 3px 10px; border-radius: 999px; }
.bp-title { font-size: 34px; font-weight: 900; color: var(--text, #0f172a); line-height: 1.15; margin: 0 0 12px; }
.bp-meta { display: flex; flex-wrap: wrap; gap: 16px; color: var(--text-muted, #64748b); font-size: 13.5px; font-weight: 600; margin-bottom: 20px; }
.bp-meta span { display: inline-flex; align-items: center; gap: 6px; }
.bp-cover { height: 360px; border-radius: 18px; background-size: cover; background-position: center; margin-bottom: 24px; }
.bp-lede { font-size: 18px; line-height: 1.6; color: var(--text-secondary, #334155); font-weight: 500; margin: 0 0 20px; }

/* Mobile: cut vertical scroll above the article body (the primary "action" on a read page). */
@media (max-width: 760px) {
  .cback { padding: 8px 4px; min-height: 44px; }
  .bp-tags { margin-bottom: 10px; }
  .bp-tag:nth-child(n+4) { display: none; } /* cap chip noise above the h1; tags stay in DOM for SEO */
  .bp-title { font-size: 22px; line-height: 1.2; margin-bottom: 10px; }
  .bp-meta { margin-bottom: 14px; gap: 12px; }
  .bp-cover { height: auto; aspect-ratio: 16 / 9; max-height: 210px; border-radius: 12px; margin-bottom: 16px; }
  .bp-lede { font-size: 16px; line-height: 1.55; margin-bottom: 16px; display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 3; overflow: hidden; }
}
</style>

<!-- Non-scoped so it styles the v-html markdown output -->
<style>
.blog-content { font-size: 16.5px; line-height: 1.75; color: var(--text, #1e293b); overflow-wrap: break-word; }
.blog-content pre { overflow-x: auto; max-width: 100%; }
.blog-content table { display: block; overflow-x: auto; max-width: 100%; }
.blog-content h2 { font-size: 24px; font-weight: 800; color: var(--text, #0f172a); margin: 32px 0 12px; }
.blog-content h3 { font-size: 20px; font-weight: 800; color: var(--text, #0f172a); margin: 26px 0 10px; }
.blog-content h4 { font-size: 17px; font-weight: 800; color: var(--text, #0f172a); margin: 22px 0 8px; }
.blog-content p { margin: 0 0 16px; }
.blog-content ul, .blog-content ol { margin: 0 0 16px; padding-left: 24px; }
.blog-content li { margin: 4px 0; }
.blog-content a { color: var(--brand, #0f766e); text-decoration: underline; }
.blog-content strong { font-weight: 800; color: var(--text, #0f172a); }
.blog-content code { background: var(--bg, #f1f5f9); padding: 1px 6px; border-radius: 5px; font-size: .9em; font-family: ui-monospace, monospace; overflow-wrap: break-word; }
.blog-content blockquote { margin: 0 0 16px; padding: 8px 18px; border-left: 4px solid var(--brand-accent, #14b8a6); background: var(--canvas, #f8fafc); color: var(--text-secondary, #475569); border-radius: 0 8px 8px 0; }
.blog-content img { max-width: 100%; border-radius: 12px; margin: 8px 0; }

/* Mobile: tighten body heading rhythm so content reads dense but breathable on a phone. */
@media (max-width: 760px) {
  .blog-content { font-size: 15px; line-height: 1.7; }
  .blog-content h2 { font-size: 18px; margin: 24px 0 10px; }
  .blog-content h3 { font-size: 16.5px; margin: 20px 0 8px; }
  .blog-content h4 { font-size: 15px; margin: 18px 0 6px; }
}
</style>
