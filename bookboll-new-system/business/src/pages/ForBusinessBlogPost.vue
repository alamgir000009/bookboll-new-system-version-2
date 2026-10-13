<script setup>
/**
 * ForBusinessBlogPost.vue — a single article at /for-business/blog/:slug.
 *
 * Business-audience posts only (audience='business'); the customer marketplace
 * blog lives separately at /blog. Article JSON-LD + SEO overrides, teal landing
 * theme, and a signup CTA under the article (readers here are venue owners).
 */
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute } from 'vue-router'
import { loadPostBySlug, renderPostBody, fmtPostDate } from '../lib/blog.js'
import { useSeo } from '../lib/seo.js'
import { authUser } from '../lib/auth.js'
import { adminLoginUrl, adminDashboardUrl } from '../lib/crossApp.js'
import LanguageSwitcher from '../components/LanguageSwitcher.vue'
import BrandMark from '../components/BrandMark.vue'
import { ArrowLeft, ArrowRight, CalendarDays, User } from 'lucide-vue-next'

const route = useRoute()
const post = ref(null)
const loading = ref(true)

async function load() {
  loading.value = true
  post.value = await loadPostBySlug(route.params.slug, 'business')
  loading.value = false
}
onMounted(load)
watch(() => route.params.slug, load)

const html = computed(() => post.value ? renderPostBody(post.value.body) : '')

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
    <!-- ── Top nav (fixed teal landing theme) ── -->
    <header class="fbb-nav">
      <div class="fbb-nav-in">
        <router-link to="/" class="fbb-brand">
          <span class="fbb-mark" data-no-translate><BrandMark /></span>
          <span class="fbb-brand-name" data-no-translate>Bookboll</span>
          <span class="fbb-brand-pill">for Business</span>
        </router-link>
        <nav class="fbb-nav-actions">
          <LanguageSwitcher class="fbb-lang" />
          <a v-if="authUser" :href="adminDashboardUrl()" class="fbb-nav-cta">Open dashboard</a>
          <template v-else>
            <a :href="adminLoginUrl()" class="fbb-nav-signin">Sign in</a>
            <router-link to="/signup" class="fbb-nav-cta">Get started free</router-link>
          </template>
        </nav>
      </div>
    </header>

    <div class="fbb-wrap">
      <router-link to="/blog" class="fbb-back-link"><ArrowLeft :size="16" /> All articles</router-link>
      <div v-if="loading" class="fbb-msg">Loading…</div>
      <div v-else-if="!post" class="fbb-msg">Article not found.</div>

      <article v-else class="fbb-article">
        <div v-if="post.tags?.length" class="fbb-tags"><span v-for="t in post.tags" :key="t" class="fbb-tag">{{ t }}</span></div>
        <h1 class="fbb-title">{{ post.title }}</h1>
        <div class="fbb-meta">
          <span><User :size="14" /> {{ post.author_name }}</span>
          <span v-if="post.published_at"><CalendarDays :size="14" /> {{ fmtPostDate(post.published_at) }}</span>
        </div>
        <div v-if="post.cover_image" class="fbb-cover" :style="{ backgroundImage: `url(${post.cover_image})` }"></div>
        <p v-if="post.excerpt" class="fbb-lede">{{ post.excerpt }}</p>
        <div class="blog-content" v-html="html"></div>

        <!-- Business-audience CTA — readers here run venues/gyms/academies -->
        <div class="fbb-post-cta">
          <div class="fbb-post-cta-txt">
            <strong>Run a venue, gym or academy?</strong>
            <span>Get a free booking website, marketplace listing and payments — free forever.</span>
          </div>
          <a v-if="authUser" :href="adminDashboardUrl()" class="fbb-cta-btn">Open my dashboard <ArrowRight :size="16" /></a>
          <router-link v-else to="/signup" class="fbb-cta-btn">Get started free <ArrowRight :size="16" /></router-link>
        </div>
      </article>
    </div>

    <footer class="fbb-foot">
      <span>© {{ new Date().getFullYear() }} Bookboll — booking software for sports facilities</span>
      <div class="fbb-foot-links">
        <router-link to="/">For Business</router-link>
        <router-link to="/pricing">Pricing</router-link>
        <router-link to="/blog">Blog</router-link>
        <router-link to="/terms">Provider terms</router-link>
        <a v-if="authUser" :href="adminDashboardUrl()">Dashboard</a>
        <a v-else :href="adminLoginUrl()">Sign in</a>
      </div>
    </footer>
  </div>
</template>

<style scoped>
.fbb { --ink:#0f172a; --teal:#14b8a6; --teal-2:#2dd4bf; --teal-lite:#5eead4; background:#ffffff; color:var(--ink); min-height:100vh; }

/* nav (mirrors the landing) */
.fbb-nav { position:sticky; top:0; z-index:30; background:rgba(15,23,42,.82); backdrop-filter:blur(10px); border-bottom:1px solid rgba(148,163,184,.16); }
.fbb-nav-in { max-width:1120px; margin:0 auto; padding:10px 22px; display:flex; align-items:center; justify-content:space-between; gap:12px; }
.fbb-brand { display:inline-flex; align-items:center; gap:9px; text-decoration:none; }
.fbb-mark { width:30px; height:30px; border-radius:9px; background:#16a39b; color:#fff; display:flex; align-items:center; justify-content:center; }
.fbb-brand-name { color:#fff; font-weight:900; font-size:18px; letter-spacing:-.01em; }
.fbb-brand-pill { color:var(--teal-lite); font-weight:800; font-size:11.5px; background:rgba(20,184,166,.16); padding:3px 9px; border-radius:999px; }
.fbb-nav-actions { display:flex; align-items:center; gap:14px; }
.fbb-nav-signin { color:#cbd5e1; font-weight:700; font-size:14px; text-decoration:none; }
.fbb-nav-signin:hover { color:#fff; }
.fbb-nav-cta { display:inline-flex; align-items:center; gap:7px; background:var(--teal); color:#04201c; font-weight:800; font-size:14px; padding:9px 17px; border-radius:999px; text-decoration:none; white-space:nowrap; }
.fbb-nav-cta:hover { background:var(--teal-2); }
.fbb-lang :deep(.lang-trigger) { background:rgba(255,255,255,.08); border-color:rgba(148,163,184,.35); color:#e2e8f0; }

.fbb-wrap { max-width:760px; margin:0 auto; padding:28px 22px 48px; }
.fbb-back-link { display:inline-flex; align-items:center; gap:6px; color:#0f766e; text-decoration:none; font-weight:800; font-size:14px; margin-bottom:18px; }
.fbb-msg { text-align:center; padding:50px; color:#94a3b8; }

.fbb-tags { display:flex; flex-wrap:wrap; gap:6px; margin-bottom:12px; }
.fbb-tag { font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.04em; color:#0f766e; background:#ccfbf1; padding:3px 10px; border-radius:999px; }
.fbb-title { font-size:34px; font-weight:900; color:var(--ink); line-height:1.15; margin:0 0 12px; }
.fbb-meta { display:flex; flex-wrap:wrap; gap:16px; color:#64748b; font-size:13.5px; font-weight:600; margin-bottom:20px; }
.fbb-meta span { display:inline-flex; align-items:center; gap:6px; }
.fbb-cover { height:360px; border-radius:18px; background-size:cover; background-position:center; margin-bottom:24px; }
.fbb-lede { font-size:18px; line-height:1.6; color:#334155; font-weight:500; margin:0 0 20px; }

/* signup CTA under the article */
.fbb-post-cta { display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:wrap; margin-top:36px; padding:20px 22px; border-radius:16px; background:linear-gradient(135deg,#0f172a,#134e4a); color:#fff; }
.fbb-post-cta-txt { display:flex; flex-direction:column; gap:3px; min-width:0; }
.fbb-post-cta-txt strong { font-size:16px; font-weight:900; color:#fff; }
.fbb-post-cta-txt span { font-size:13px; color:#cbd5e1; }
.fbb-cta-btn { display:inline-flex; align-items:center; gap:7px; background:var(--teal); color:#04201c; font-weight:800; font-size:14px; padding:11px 19px; border-radius:999px; text-decoration:none; white-space:nowrap; }
.fbb-cta-btn:hover { background:var(--teal-2); }

.fbb-foot { max-width:1120px; margin:0 auto; padding:22px; display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; font-size:12.5px; color:#64748b; border-top:1px solid #eef2f6; }
.fbb-foot-links { display:flex; gap:16px; flex-wrap:wrap; }
.fbb-foot-links a { color:#475569; font-weight:700; text-decoration:none; }
.fbb-foot-links a:hover { color:var(--teal); }

@media (max-width:700px) {
  .fbb-wrap { padding:18px 14px 36px; }
  .fbb-back-link { min-height:44px; align-items:center; }
  .fbb-title { font-size:23px; line-height:1.2; margin-bottom:10px; }
  .fbb-meta { margin-bottom:14px; gap:12px; }
  .fbb-cover { height:auto; aspect-ratio:16 / 9; max-height:210px; border-radius:12px; margin-bottom:16px; }
  .fbb-lede { font-size:16px; line-height:1.55; margin-bottom:16px; }
  .fbb-post-cta { flex-direction:column; align-items:stretch; text-align:center; }
  .fbb-cta-btn { justify-content:center; }
  .fbb-nav-signin { display:none; }
}
</style>

<!-- Non-scoped so it styles the v-html article body. Namespaced under .fbb — the
     customer BlogPost.vue owns the bare .blog-content rules and its chunk may not
     be loaded when someone lands directly on this page. -->
<style>
.fbb .blog-content { font-size: 16.5px; line-height: 1.75; color: #1e293b; overflow-wrap: break-word; }
.fbb .blog-content pre { overflow-x: auto; max-width: 100%; }
.fbb .blog-content table { display: block; overflow-x: auto; max-width: 100%; }
.fbb .blog-content h2 { font-size: 24px; font-weight: 800; color: #0f172a; margin: 32px 0 12px; }
.fbb .blog-content h3 { font-size: 20px; font-weight: 800; color: #0f172a; margin: 26px 0 10px; }
.fbb .blog-content h4 { font-size: 17px; font-weight: 800; color: #0f172a; margin: 22px 0 8px; }
.fbb .blog-content p { margin: 0 0 16px; }
.fbb .blog-content ul, .fbb .blog-content ol { margin: 0 0 16px; padding-left: 24px; }
.fbb .blog-content li { margin: 4px 0; }
.fbb .blog-content a { color: #0f766e; text-decoration: underline; }
.fbb .blog-content strong { font-weight: 800; color: #0f172a; }
.fbb .blog-content code { background: #f1f5f9; padding: 1px 6px; border-radius: 5px; font-size: .9em; font-family: ui-monospace, monospace; overflow-wrap: break-word; }
.fbb .blog-content blockquote { margin: 0 0 16px; padding: 8px 18px; border-left: 4px solid #14b8a6; background: #f8fafc; color: #475569; border-radius: 0 8px 8px 0; }
.fbb .blog-content img { max-width: 100%; border-radius: 12px; margin: 8px 0; }
@media (max-width: 700px) {
  .fbb .blog-content { font-size: 15px; line-height: 1.7; }
  .fbb .blog-content h2 { font-size: 18px; margin: 24px 0 10px; }
  .fbb .blog-content h3 { font-size: 16.5px; margin: 20px 0 8px; }
  .fbb .blog-content h4 { font-size: 15px; margin: 18px 0 6px; }
}
</style>

