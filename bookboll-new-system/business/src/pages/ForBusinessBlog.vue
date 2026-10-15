<script setup>
/**
 * ForBusinessBlog.vue — the Bookboll for Business blog index (/blog).
 *
 * A separate publication from the customer marketplace blog: these posts target
 * venue owners, gyms and academies — growth guides, product news, playbooks.
 * While the business blog has no posts of its own, lib/blog.js falls back to the
 * marketplace blog so this page is never empty (see loadBusinessBlogPosts).
 *
 * Layout follows the marketplace blog (web/src/customer/pages/Blog.vue): a search
 * toolbar with a live count, one featured "latest story", then the rest as a list.
 * Colours follow the business landing page (deep green / light surfaces) rather
 * than the marketplace's brand variables — this app has no customer shell.
 */
import { ref, computed, onMounted } from 'vue'
import { loadBusinessBlogPosts, fmtPostDate } from '../lib/blog.js'
import { browsePosts, readableTags } from '../lib/blogPresentation.js'
import { useSeo } from '../lib/seo.js'
import { authUser } from '../lib/auth.js'
import { adminLoginUrl, adminDashboardUrl } from '../lib/crossApp.js'
import BusinessHeader from '../components/BusinessHeader.vue'
import { Newspaper, ArrowRight, Search, X } from 'lucide-vue-next'

const posts = ref([])
const loading = ref(true)
const query = ref('')

// Searching flattens the feature/list split — every match is shown as one list.
const visiblePosts = computed(() => browsePosts(posts.value, query.value))
const featured = computed(() => !query.value.trim() ? visiblePosts.value[0] : null)
const articles = computed(() => featured.value ? visiblePosts.value.slice(1) : visiblePosts.value)

onMounted(async () => {
  try { posts.value = await loadBusinessBlogPosts() } finally { loading.value = false }
})

useSeo({
  exactTitle: 'Blog — Grow Your Sports Business | Bookboll for Business',
  description: 'Guides, playbooks and product news for sports venues, gyms and academies — filling quiet hours, pricing courts, growing memberships and running your business on Bookboll.',
  path: '/blog',
  jsonLd: {
    '@context': 'https://schema.org',
    '@type': 'Blog',
    name: 'Bookboll for Business Blog',
    url: 'https://business.bookboll.com/blog',
    description: 'Growth guides and product news for sports venues, gyms and academies.',
    publisher: { '@type': 'Organization', name: 'Bookboll', url: 'https://bookboll.com/' },
  },
})
</script>

<template>
  <div class="fbb">
    <BusinessHeader />

    <main>
      <section class="fbb-hero">
        <div class="fbb-wrap">
          <div>
            <p class="fbb-kicker"><Newspaper :size="17" /> Ideas for your next booking</p>
            <h1>The <em>Business</em> Blog</h1>
            <p class="fbb-lead">Guides, playbooks and product news for venues, gyms and academies — pricing your courts, filling quiet hours and growing memberships.</p>
          </div>
          <a v-if="posts.length" href="#fbb-articles" class="fbb-browse">Browse articles <ArrowRight :size="17" /></a>
        </div>
      </section>

      <div class="fbb-wrap fbb-body">
        <div v-if="loading" class="fbb-state" role="status"><Newspaper :size="30" /><p>Loading articles…</p></div>
        <div v-else-if="!posts.length" class="fbb-state"><Newspaper :size="34" /><h2>Stories are on the way</h2><p>Check back for guides on pricing, memberships and filling quiet hours.</p></div>

        <template v-else>
          <section id="fbb-articles" class="fbb-toolbar" aria-label="Find an article">
            <div class="fbb-search">
              <Search :size="19" aria-hidden="true" />
              <input v-model="query" type="search" aria-label="Search articles" placeholder="Search articles, activities or topics" />
              <button v-if="query" type="button" aria-label="Clear article search" @click="query = ''"><X :size="18" /></button>
            </div>
            <p role="status">{{ visiblePosts.length }} {{ visiblePosts.length === 1 ? 'article' : 'articles' }}<span> · Newest first</span></p>
          </section>

          <router-link v-if="featured" :to="`/post/${featured.slug}`" class="fbb-feature">
            <div class="fbb-feature-image">
              <img v-if="featured.cover_image" :src="featured.cover_image" alt="" />
              <Newspaper v-else :size="40" />
            </div>
            <div class="fbb-feature-body">
              <span class="fbb-eyebrow">Latest story</span>
              <div v-if="readableTags(featured.tags).length" class="fbb-tags">
                <span v-for="tag in readableTags(featured.tags).slice(0, 2)" :key="tag">{{ tag }}</span>
              </div>
              <h2>{{ featured.title }}</h2>
              <p class="fbb-excerpt">{{ featured.excerpt }}</p>
              <p class="fbb-meta">{{ featured.author_name }}<span v-if="featured.published_at"> · {{ fmtPostDate(featured.published_at) }}</span></p>
              <span class="fbb-read">Read the story <ArrowRight :size="17" /></span>
            </div>
          </router-link>

          <section v-if="articles.length" class="fbb-stories" :aria-label="query.trim() ? 'Search results' : 'More stories'">
            <h2 class="fbb-section-title">{{ query.trim() ? 'Search results' : 'More stories' }}</h2>
            <div class="fbb-grid">
              <router-link v-for="post in articles" :key="post.id" :to="`/post/${post.slug}`" class="fbb-card">
                <div class="fbb-thumb">
                  <img v-if="post.cover_image" :src="post.cover_image" alt="" loading="lazy" />
                  <Newspaper v-else :size="25" />
                </div>
                <div class="fbb-card-body">
                  <p class="fbb-meta"><span v-if="post.published_at">{{ fmtPostDate(post.published_at) }}</span><span v-else>{{ post.author_name }}</span></p>
                  <h3>{{ post.title }}</h3>
                  <p class="fbb-excerpt">{{ post.excerpt }}</p>
                  <span class="fbb-read">Read article <ArrowRight :size="15" /></span>
                </div>
              </router-link>
            </div>
          </section>

          <div v-if="!visiblePosts.length" class="fbb-state">
            <Search :size="30" /><h2>No matching articles</h2>
            <p>Try a topic such as memberships, pricing or payouts.</p>
            <button type="button" @click="query = ''">Show all articles</button>
          </div>
        </template>
      </div>

      <section class="fbb-cta">
        <div class="fbb-wrap">
          <div>
            <h2>Put the playbook to work.</h2>
            <p>Your branded booking website, a place on the Bookboll marketplace and one dashboard to run it all.</p>
          </div>
          <a v-if="authUser" :href="adminDashboardUrl()" class="fbb-button">Open my dashboard <ArrowRight :size="17" /></a>
          <router-link v-else to="/signup" class="fbb-button">Create your free account <ArrowRight :size="17" /></router-link>
        </div>
      </section>
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
/* Palette mirrors the landing page (ForBusiness.vue) so the blog reads as the same site. */
.fbb { --ink:#12332f; --green:#075e54; --green-ink:#087f77; --muted:#526762; --border:#dfe8e5; --tint:#edf7f2; background:#fff; color:var(--ink); min-height:100vh; line-height:1.55; display:flex; flex-direction:column; }
.fbb * { box-sizing:border-box; }
.fbb a { text-decoration:none; }
.fbb h1, .fbb h2, .fbb h3, .fbb p { margin:0; color:inherit; }
.fbb :is(a,button,input):focus-visible { outline:3px solid #1a6afd; outline-offset:3px; }
.fbb main { flex:1; }
.fbb-wrap { width:min(1160px,calc(100% - 64px)); margin-inline:auto; }

/* Hero — the landing's deep-green band, so the two pages share a spine. */
.fbb-hero { background:#064e45; color:#fff; padding-block:52px 46px; }
.fbb-hero .fbb-wrap { display:flex; align-items:flex-end; justify-content:space-between; gap:40px; }
.fbb-kicker { display:flex; align-items:center; gap:9px; color:#c4f1dd; font-size:13px; font-weight:700; margin-bottom:16px; }
.fbb-hero h1 { font-size:clamp(34px,4.3vw,52px); line-height:1.06; letter-spacing:-1.6px; font-weight:750; }
.fbb-hero h1 em { font-style:normal; color:#b4e5cf; }
.fbb-lead { color:#d8eae4; font-size:17px; line-height:1.65; max-width:620px; margin-top:18px; }
.fbb-browse { display:inline-flex; align-items:center; gap:8px; flex-shrink:0; min-height:44px; color:#fff; font-size:14px; font-weight:700; text-decoration:underline; text-underline-offset:4px; }

.fbb-body { padding-block:40px 56px; }

/* Search toolbar */
.fbb-toolbar { display:flex; justify-content:space-between; align-items:center; gap:16px; border-block:1px solid var(--border); padding-block:16px; margin-bottom:28px; scroll-margin-top:20px; }
.fbb-search { display:flex; align-items:center; gap:10px; width:min(100%,480px); border:1px solid var(--border); border-radius:10px; background:#fff; padding-inline:14px 4px; color:var(--muted); }
.fbb-search:focus-within { outline:2px solid var(--green); outline-offset:2px; }
.fbb-search input { min-width:0; width:100%; height:46px; border:0; outline:0; background:transparent; color:var(--ink); font:inherit; font-size:14px; }
.fbb-search input::-webkit-search-cancel-button { display:none; }
.fbb-search button { display:grid; place-items:center; flex-shrink:0; width:44px; height:44px; border:0; background:transparent; color:var(--muted); cursor:pointer; }
.fbb-toolbar > p { font-size:13px; color:var(--muted); white-space:nowrap; }

/* Featured "latest story" */
.fbb-feature { display:grid; grid-template-columns:1fr 1fr; border:1px solid var(--border); border-radius:18px; overflow:hidden; background:#fff; color:var(--ink); }
.fbb-feature-image, .fbb-thumb { display:grid; place-items:center; overflow:hidden; background:var(--tint); color:var(--green-ink); }
.fbb-feature-image { position:relative; min-height:300px; }
.fbb-feature-image img { position:absolute; inset:0; width:100%; height:100%; object-fit:cover; }
.fbb-feature-body { padding:clamp(22px,3vw,40px); display:flex; flex-direction:column; align-items:flex-start; justify-content:center; }
.fbb-eyebrow { color:var(--green-ink); font-size:12px; font-weight:750; letter-spacing:.04em; margin-bottom:10px; }
.fbb-feature h2 { font-size:clamp(22px,2.5vw,30px); line-height:1.25; letter-spacing:-.4px; margin-bottom:12px; }

.fbb-excerpt { color:var(--muted); font-size:14px; line-height:1.6; margin-bottom:16px; display:-webkit-box; -webkit-box-orient:vertical; -webkit-line-clamp:3; overflow:hidden; }
.fbb-meta { font-size:12px; color:var(--muted); line-height:1.5; margin-bottom:14px; }
.fbb-tags { display:flex; flex-wrap:wrap; gap:6px; margin-bottom:10px; }
.fbb-tags span { background:#d9f0e7; color:var(--green); padding:4px 9px; border-radius:5px; font-size:12px; font-weight:650; }
.fbb-read { display:inline-flex; align-items:center; gap:8px; color:var(--green); font-size:14px; font-weight:700; }

/* More stories */
.fbb-stories { margin-top:36px; }
.fbb-section-title { font-size:20px; letter-spacing:-.3px; margin-bottom:16px; }
.fbb-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:0 28px; }
.fbb-card { display:flex; align-items:flex-start; gap:18px; padding:22px 0; border-top:1px solid var(--border); color:var(--ink); }
.fbb-thumb { width:128px; aspect-ratio:1; flex-shrink:0; border-radius:10px; }
.fbb-thumb img { width:100%; height:100%; object-fit:cover; }
.fbb-card-body { min-width:0; }
.fbb-card .fbb-meta { margin-bottom:6px; }
.fbb-card h3 { font-size:17px; line-height:1.4; margin-bottom:8px; }
.fbb-card .fbb-excerpt { -webkit-line-clamp:2; margin-bottom:10px; }
.fbb-card:hover h3, .fbb-feature:hover h2 { color:var(--green); text-decoration:underline; text-underline-offset:3px; }

/* Loading / empty / no-results */
.fbb-state { min-height:260px; display:flex; flex-direction:column; align-items:center; justify-content:center; text-align:center; gap:12px; color:var(--muted); padding:28px; }
.fbb-state h2 { color:var(--ink); font-size:21px; }
.fbb-state p { line-height:1.6; max-width:42ch; }
.fbb-state button { min-height:44px; border:1px solid var(--border); border-radius:8px; background:#fff; color:var(--green); font:inherit; font-weight:700; padding:11px 18px; cursor:pointer; }

/* Closing CTA — the landing's final band */
.fbb-cta { background:#075e54; color:#fff; }
.fbb-cta .fbb-wrap { display:flex; align-items:center; justify-content:space-between; gap:30px; padding-block:40px; }
.fbb-cta h2 { font-size:28px; letter-spacing:-.6px; line-height:1.2; }
.fbb-cta p { font-size:15px; color:#d5ebe5; margin-top:8px; max-width:520px; }
.fbb-button { display:inline-flex; align-items:center; justify-content:center; gap:10px; flex-shrink:0; min-height:48px; padding:14px 23px; border-radius:9px; background:#fff; color:var(--green); font-size:15px; font-weight:700; }
.fbb-button:hover { background:#dcf5ec; }

.fbb-foot { display:flex; justify-content:space-between; gap:24px; flex-wrap:wrap; padding-block:30px; font-size:13px; color:var(--muted); }
.fbb-foot nav { display:flex; gap:22px; flex-wrap:wrap; }
.fbb-foot a { color:var(--muted); }
.fbb-foot a:hover { color:var(--green); }

@media (max-width:900px) {
  .fbb-grid { grid-template-columns:1fr; }
  .fbb-thumb { width:140px; }
  .fbb-cta .fbb-wrap { flex-direction:column; align-items:flex-start; }
}
@media (max-width:700px) {
  .fbb-wrap { width:100%; padding-inline:20px; }
  .fbb-hero { padding-block:36px 32px; }
  .fbb-hero h1 { font-size:clamp(28px,8.4vw,38px); letter-spacing:-1.1px; }
  .fbb-lead { font-size:15px; }
  .fbb-browse { display:none; }
  .fbb-body { padding-block:28px 40px; }
  .fbb-toolbar { flex-direction:column; align-items:stretch; gap:12px; margin-bottom:20px; }
  /* 16px keeps iOS from zooming the page when the search field takes focus. */
  .fbb-search input { font-size:16px; }
  .fbb-feature { grid-template-columns:1fr; }
  .fbb-feature-image { min-height:0; aspect-ratio:16/9; }
  .fbb-feature-body { padding:22px; }
  .fbb-card { gap:14px; padding-block:18px; }
  .fbb-thumb { width:88px; }
  .fbb-card h3 { font-size:16px; }
  .fbb-card .fbb-excerpt { font-size:13px; }
  .fbb-stories { margin-top:26px; }
  .fbb-cta h2 { font-size:23px; }
  .fbb-button { width:100%; }
  .fbb-foot { flex-direction:column; gap:12px; }
  .fbb-foot nav { gap:16px; }
}
@media (max-width:360px) { .fbb-wrap { padding-inline:16px; } }
</style>
