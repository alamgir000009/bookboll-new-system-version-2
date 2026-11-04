<script setup>
/**
 * ForBusinessBlogPost.vue — a single article at /post/:slug.
 *
 * Reads the business blog, falling back to the marketplace blog while the
 * business blog has no posts of its own (lib/blog.js loadBusinessBlogPost), so a
 * slug reached from the index always resolves. Article JSON-LD + SEO overrides,
 * and a signup CTA under the article — readers here run venues, gyms, academies.
 *
 * Mirrors the marketplace article layout (web/src/customer/pages/BlogPost.vue)
 * in the business landing palette. The city cross-links that page carries are
 * marketplace-only and deliberately absent here.
 */
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute } from 'vue-router'
import { loadBusinessBlogPost, renderPostBody, fmtPostDate } from '../lib/blog.js'
import { readableTags } from '../lib/blogPresentation.js'
import { useSeo } from '../lib/seo.js'
import { authUser } from '../lib/auth.js'
import { adminLoginUrl, adminDashboardUrl } from '../lib/crossApp.js'
import BusinessHeader from '../components/BusinessHeader.vue'
import { ArrowLeft, ArrowRight, CalendarDays, User, Newspaper } from 'lucide-vue-next'

const route = useRoute()
const post = ref(null)
const loading = ref(true)

async function load() {
  loading.value = true
  post.value = await loadBusinessBlogPost(route.params.slug)
  loading.value = false
}
onMounted(load)
watch(() => route.params.slug, load)

const html = computed(() => post.value ? renderPostBody(post.value.body) : '')
const tags = computed(() => readableTags(post.value?.tags))

// Imported posts often repeat their excerpt verbatim as the article's opening
// paragraph. Rendering both prints the same sentences twice, so show the lede
// only when it actually differs from how the body starts.
const plainText = (s) => String(s || '').replace(/<[^>]*>/g, ' ').replace(/&[a-z]+;|&#\d+;/gi, ' ').replace(/\s+/g, ' ').trim().toLowerCase()
const lede = computed(() => {
  const excerpt = (post.value?.excerpt || '').trim()
  if (!excerpt) return ''
  const normalized = plainText(excerpt)
  return plainText(post.value?.body).startsWith(normalized) ? '' : excerpt
})

useSeo(() => {
  if (!post.value) return { path: route.path, noindex: !loading.value }   // 404 → noindex
  const p = post.value
  return {
    exactTitle: `${p.meta_title || p.title} | Bookboll for Business`,
    description: p.meta_description || p.excerpt || `${p.title} — on the Bookboll for Business blog.`,
    image: p.cover_image || undefined,
    path: route.path,
    type: 'article',
    jsonLd: {
      '@context': 'https://schema.org',
      '@type': 'Article',
      headline: p.title,
      ...(p.excerpt ? { description: p.excerpt } : {}),
      ...(p.cover_image ? { image: p.cover_image } : {}),
      ...(p.published_at ? { datePublished: p.published_at } : {}),
      ...(p.updated_at ? { dateModified: p.updated_at } : {}),
      author: { '@type': 'Organization', name: p.author_name || 'Bookboll' },
      publisher: { '@type': 'Organization', name: 'Bookboll', url: 'https://bookboll.com/' },
      mainEntityOfPage: `https://bookboll.com${route.path}`,
    },
  }
})
</script>

<template>
  <div class="fbb">
    <BusinessHeader />

    <main class="fbb-wrap">
      <router-link to="/blog" class="fbb-back"><ArrowLeft :size="16" /> All articles</router-link>

      <div v-if="loading" class="fbb-state" role="status"><Newspaper :size="30" /><p>Loading article…</p></div>
      <div v-else-if="!post" class="fbb-state">
        <Newspaper :size="34" /><h2>Article not found</h2>
        <p>It may have been unpublished or the link may be out of date.</p>
        <router-link to="/blog" class="fbb-state-link">Back to all articles</router-link>
      </div>

      <article v-else class="fbb-article">
        <div v-if="tags.length" class="fbb-tags"><span v-for="t in tags" :key="t">{{ t }}</span></div>
        <h1>{{ post.title }}</h1>
        <div class="fbb-meta">
          <span><User :size="14" /> {{ post.author_name }}</span>
          <span v-if="post.published_at"><CalendarDays :size="14" /> {{ fmtPostDate(post.published_at) }}</span>
        </div>
        <div v-if="post.cover_image" class="fbb-cover"><img :src="post.cover_image" alt="" /></div>
        <p v-if="lede" class="fbb-lede">{{ lede }}</p>
        <div class="blog-content" v-html="html"></div>

        <aside class="fbb-post-cta">
          <div>
            <strong>Run a venue, gym or academy?</strong>
            <span>Get a free booking website, a place on the Bookboll marketplace and payments — no monthly fee on Free.</span>
          </div>
          <a v-if="authUser" :href="adminDashboardUrl()" class="fbb-button">Open my dashboard <ArrowRight :size="16" /></a>
          <router-link v-else to="/signup" class="fbb-button">Get started free <ArrowRight :size="16" /></router-link>
        </aside>
      </article>
    </main>

    <footer class="fbb-foot fbb-wrap">
      <span>© {{ new Date().getFullYear() }} Bookboll — booking software for sports facilities</span>
      <nav aria-label="Footer">
        <router-link to="/">For Business</router-link>
        <router-link to="/pricing">Pricing</router-link>
        <router-link to="/blog">Blog</router-link>
        <router-link to="/terms">Provider terms</router-link>
        <a v-if="authUser" :href="adminDashboardUrl()">Dashboard</a>
        <a v-else :href="adminLoginUrl()">Sign in</a>
      </nav>
    </footer>
  </div>
</template>

<style scoped>
/* Palette mirrors the landing page and the blog index. */
.fbb { --ink:#12332f; --green:#075e54; --green-ink:#087f77; --muted:#526762; --border:#dfe8e5; --tint:#edf7f2; background:#fff; color:var(--ink); min-height:100vh; line-height:1.55; display:flex; flex-direction:column; }
.fbb * { box-sizing:border-box; }
.fbb a { text-decoration:none; }
.fbb h1, .fbb h2, .fbb p { margin:0; color:inherit; }
.fbb :is(a,button):focus-visible { outline:3px solid #1a6afd; outline-offset:3px; }
.fbb-wrap { width:min(1160px,calc(100% - 64px)); margin-inline:auto; }
.fbb main { flex:1; padding-block:28px 52px; }

.fbb-back { display:inline-flex; align-items:center; gap:7px; min-height:44px; color:var(--green); font-size:14px; font-weight:700; }
.fbb-back:hover { text-decoration:underline; text-underline-offset:3px; }

.fbb-state { min-height:280px; display:flex; flex-direction:column; align-items:center; justify-content:center; text-align:center; gap:12px; color:var(--muted); padding:28px; }
.fbb-state h2 { color:var(--ink); font-size:21px; }
.fbb-state p { line-height:1.6; max-width:42ch; }
.fbb-state-link { min-height:44px; display:inline-flex; align-items:center; border:1px solid var(--border); border-radius:8px; padding:11px 18px; color:var(--green); font-weight:700; }

/* Article — a single measured column, the way the marketplace article reads. */
.fbb-article { max-width:720px; margin-inline:auto; padding-top:10px; }
.fbb-tags { display:flex; flex-wrap:wrap; gap:6px; margin-bottom:12px; }
.fbb-tags span { font-size:11px; font-weight:750; text-transform:uppercase; letter-spacing:.04em; color:var(--green); background:#d9f0e7; padding:4px 11px; border-radius:999px; }
.fbb-article h1 { font-size:clamp(28px,3.6vw,38px); font-weight:750; line-height:1.14; letter-spacing:-1.1px; margin-bottom:14px; }
.fbb-meta { display:flex; flex-wrap:wrap; gap:16px; color:var(--muted); font-size:13.5px; font-weight:600; margin-bottom:22px; }
.fbb-meta span { display:inline-flex; align-items:center; gap:6px; }
.fbb-meta svg { color:var(--green-ink); }
.fbb-cover { aspect-ratio:16/9; border-radius:16px; overflow:hidden; background:var(--tint); margin-bottom:26px; }
.fbb-cover img { width:100%; height:100%; object-fit:cover; display:block; }
.fbb-lede { font-size:18px; line-height:1.65; color:#3d5650; font-weight:500; padding-left:16px; border-left:3px solid #b9ded0; margin-bottom:24px; }

.fbb-post-cta { display:flex; align-items:center; justify-content:space-between; gap:18px; flex-wrap:wrap; margin-top:40px; padding:24px 26px; border-radius:16px; background:#075e54; color:#fff; }
.fbb-post-cta > div { display:flex; flex-direction:column; gap:5px; min-width:0; }
.fbb-post-cta strong { font-size:17px; font-weight:750; }
.fbb-post-cta span { font-size:13.5px; line-height:1.6; color:#d5ebe5; }
.fbb-button { display:inline-flex; align-items:center; justify-content:center; gap:8px; flex-shrink:0; min-height:48px; padding:13px 21px; border-radius:9px; background:#fff; color:var(--green); font-size:14.5px; font-weight:700; white-space:nowrap; }
.fbb-button:hover { background:#dcf5ec; }

.fbb-foot { display:flex; justify-content:space-between; gap:24px; flex-wrap:wrap; padding-block:28px; border-top:1px solid var(--border); font-size:13px; color:var(--muted); }
.fbb-foot nav { display:flex; gap:22px; flex-wrap:wrap; }
.fbb-foot a { color:var(--muted); }
.fbb-foot a:hover { color:var(--green); }

@media (max-width:700px) {
  .fbb-wrap { width:100%; padding-inline:20px; }
  .fbb main { padding-block:16px 36px; }
  .fbb-tags { margin-bottom:10px; }
  /* Cap chip noise above the h1 on a phone; the rest stay in the DOM for SEO. */
  .fbb-tags span:nth-child(n+4) { display:none; }
  .fbb-article h1 { font-size:clamp(23px,6.6vw,30px); letter-spacing:-.7px; }
  .fbb-meta { gap:12px; margin-bottom:16px; }
  .fbb-cover { border-radius:12px; margin-bottom:18px; }
  .fbb-lede { font-size:16px; line-height:1.6; padding-left:13px; margin-bottom:18px; }
  .fbb-post-cta { flex-direction:column; align-items:stretch; padding:20px; }
  .fbb-button { width:100%; }
  .fbb-foot { flex-direction:column; gap:12px; }
  .fbb-foot nav { gap:16px; }
}
@media (max-width:360px) { .fbb-wrap { padding-inline:16px; } }
</style>

<!-- Non-scoped so it styles the v-html article body. Namespaced under .fbb so it
     cannot leak into any other page's markdown output. -->
<style>
.fbb .blog-content { font-size:16.5px; line-height:1.78; color:#2b4a43; overflow-wrap:break-word; }
.fbb .blog-content > *:first-child { margin-top:0; }
.fbb .blog-content pre { overflow-x:auto; max-width:100%; }
.fbb .blog-content table { display:block; overflow-x:auto; max-width:100%; }
.fbb .blog-content h2 { font-size:24px; font-weight:750; color:#12332f; letter-spacing:-.4px; margin:34px 0 12px; }
.fbb .blog-content h3 { font-size:20px; font-weight:750; color:#12332f; letter-spacing:-.2px; margin:28px 0 10px; }
.fbb .blog-content h4 { font-size:17px; font-weight:750; color:#12332f; margin:22px 0 8px; }
.fbb .blog-content p { margin:0 0 18px; }
.fbb .blog-content ul, .fbb .blog-content ol { margin:0 0 18px; padding-left:24px; }
.fbb .blog-content li { margin:6px 0; }
.fbb .blog-content li::marker { color:#087f77; }
.fbb .blog-content a { color:#075e54; text-decoration:underline; text-underline-offset:2px; }
.fbb .blog-content strong { font-weight:750; color:#12332f; }
.fbb .blog-content code { background:#edf7f2; color:#0b5d52; padding:2px 6px; border-radius:5px; font-size:.9em; font-family:ui-monospace, monospace; overflow-wrap:break-word; }
.fbb .blog-content blockquote { margin:0 0 18px; padding:12px 20px; border-left:4px solid #0fa59b; background:#f4f7f7; color:#3d5650; border-radius:0 10px 10px 0; }
.fbb .blog-content img { max-width:100%; border-radius:12px; margin:10px 0; }
.fbb .blog-content hr { border:0; border-top:1px solid #dfe8e5; margin:28px 0; }
@media (max-width:700px) {
  .fbb .blog-content { font-size:15.5px; line-height:1.72; }
  .fbb .blog-content h2 { font-size:19px; margin:26px 0 10px; }
  .fbb .blog-content h3 { font-size:17px; margin:22px 0 8px; }
  .fbb .blog-content h4 { font-size:15.5px; margin:18px 0 6px; }
}
</style>
