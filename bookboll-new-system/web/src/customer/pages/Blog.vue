<script setup>
import { ref, computed, onMounted } from 'vue'
import { loadPublishedPosts, fmtPostDate } from '../../lib/blog.js'
import { browsePosts, readableTags } from '../../lib/blogPresentation.js'
import { useSeo } from '../../lib/seo.js'
import { platformBrand, activeBrand, isWhiteLabel } from '../../lib/brand.js'
import { Newspaper, ArrowRight, Search, X } from 'lucide-vue-next'
const posts = ref([])
const loading = ref(true)
const query = ref('')
const visiblePosts = computed(() => browsePosts(posts.value, query.value))
const featured = computed(() => !query.value.trim() ? visiblePosts.value[0] : null)
const articles = computed(() => featured.value ? visiblePosts.value.slice(1) : visiblePosts.value)
const brandName = computed(() => (isWhiteLabel.value ? activeBrand.value?.name : platformBrand.value?.name) || 'Bookboll')
onMounted(async () => { try { posts.value = await loadPublishedPosts() } finally { loading.value = false } })
useSeo(() => ({ title: 'Blog — tips, guides & stories', description: `Tips, guides and stories from ${brandName.value}.`, path: '/blog', noindex: isWhiteLabel.value }))
</script>

<template>
  <div class="blog">
    <header class="blog-hero">
      <div><p class="blog-eyebrow">Ideas for your next booking</p><h1>The {{ brandName }} Blog</h1><p>Practical guides for players, hosts and venue owners.</p></div>
      <a v-if="posts.length" href="#blog-articles" class="blog-browse">Browse articles <ArrowRight :size="17" /></a>
    </header>
    <div v-if="loading" class="blog-empty" role="status"><Newspaper :size="30" /><p>Loading articles…</p></div>
    <div v-else-if="!posts.length" class="blog-empty"><Newspaper :size="34" /><h2>Stories are on the way</h2><p>Check back for tips, guides and ideas for your next booking.</p></div>
    <template v-else>
      <section id="blog-articles" class="blog-toolbar" aria-label="Find an article">
        <div class="blog-search"><Search :size="19" aria-hidden="true" /><input v-model="query" type="search" aria-label="Search articles" placeholder="Search articles, activities or topics" /><button v-if="query" type="button" aria-label="Clear article search" @click="query = ''"><X :size="18" /></button></div>
        <p role="status">{{ visiblePosts.length }} {{ visiblePosts.length === 1 ? 'article' : 'articles' }}<span> · Newest first</span></p>
      </section>
      <router-link v-if="featured" :to="`/post/${featured.slug}`" class="blog-feature">
        <div class="blog-feature-image"><img v-if="featured.cover_image" :src="featured.cover_image" alt="" /><Newspaper v-else :size="40" /></div>
        <div class="blog-feature-body">
          <span class="blog-eyebrow">Latest story</span>
          <div v-if="readableTags(featured.tags).length" class="blog-tags"><span v-for="tag in readableTags(featured.tags).slice(0, 2)" :key="tag">{{ tag }}</span></div>
          <h2>{{ featured.title }}</h2><p class="blog-excerpt">{{ featured.excerpt }}</p>
          <p class="blog-meta">{{ featured.author_name }}<span v-if="featured.published_at"> · {{ fmtPostDate(featured.published_at) }}</span></p>
          <span class="blog-read">Read the story <ArrowRight :size="17" /></span>
        </div>
      </router-link>
      <section v-if="articles.length" class="blog-stories" :aria-label="query.trim() ? 'Search results' : 'More stories'">
        <h2 class="blog-section-title">{{ query.trim() ? 'Search results' : 'More stories' }}</h2>
        <div class="blog-grid">
          <router-link v-for="post in articles" :key="post.id" :to="`/post/${post.slug}`" class="blog-card">
            <div class="blog-thumb"><img v-if="post.cover_image" :src="post.cover_image" alt="" loading="lazy" /><Newspaper v-else :size="25" /></div>
            <div class="blog-card-body"><p class="blog-meta"><span v-if="post.published_at">{{ fmtPostDate(post.published_at) }}</span><span v-else>{{ post.author_name }}</span></p><h3>{{ post.title }}</h3><p class="blog-excerpt">{{ post.excerpt }}</p><span class="blog-read">Read article <ArrowRight :size="15" /></span></div>
          </router-link>
        </div>
      </section>
      <div v-if="!visiblePosts.length" class="blog-empty"><Search :size="30" /><h2>No matching articles</h2><p>Try an activity such as football or padel.</p><button type="button" @click="query = ''">Show all articles</button></div>
    </template>
  </div>
</template>

<style scoped>
.blog { padding-block:24px 48px; color:var(--text); }
.blog-hero { display:flex; align-items:flex-end; justify-content:space-between; gap:24px; padding-block:4px 28px; }
.blog-eyebrow { color:var(--brand-ink,var(--brand)); font-size:12px; font-weight:750; letter-spacing:.04em; margin:0 0 10px; }
.blog-hero h1 { font-size:clamp(26px,3vw,38px); line-height:1.15; letter-spacing:-.025em; margin:0; }
.blog-hero p:not(.blog-eyebrow) { color:var(--text-muted); margin:12px 0 0; line-height:1.5; }
.blog-browse,.blog-read { display:inline-flex; gap:8px; align-items:center; color:var(--brand-ink,var(--brand)); font-size:14px; font-weight:700; text-decoration:none; }
.blog-browse { min-height:44px; flex-shrink:0; }
.blog-toolbar { display:flex; justify-content:space-between; align-items:center; gap:16px; border-block:1px solid var(--border); padding-block:16px; margin-bottom:24px; scroll-margin-top:150px; }
.blog-search { display:flex; align-items:center; gap:10px; border:1px solid var(--border); border-radius:10px; background:var(--surface); padding-inline:14px 4px; width:min(100%,480px); color:var(--text-muted); }
.blog-search:focus-within { outline:2px solid var(--brand); outline-offset:2px; }
.blog-search input { min-width:0; width:100%; height:46px; border:0; outline:0; background:transparent; color:var(--text); font:inherit; font-size:14px; }
.blog-search input::-webkit-search-cancel-button { display:none; }
.blog-search button { display:grid; place-items:center; flex-shrink:0; height:44px; width:44px; border:0; background:transparent; color:var(--text-muted); cursor:pointer; }
.blog-toolbar>p { font-size:13px; color:var(--text-muted); margin:0; white-space:nowrap; }
.blog-feature { display:grid; grid-template-columns:1fr 1fr; border:1px solid var(--border); border-radius:18px; overflow:hidden; background:var(--surface); color:var(--text); text-decoration:none; }
.blog-feature-image,.blog-thumb { background:var(--brand-tint,var(--canvas)); color:var(--brand-ink,var(--brand)); display:grid; place-items:center; overflow:hidden; }
.blog-feature-image { position:relative; min-height:300px; }
.blog-feature-image img { position:absolute; inset:0; width:100%; height:100%; object-fit:cover; }
.blog-feature-body { padding:clamp(22px,3vw,40px); display:flex; flex-direction:column; align-items:flex-start; justify-content:center; }
.blog-feature h2 { font-size:clamp(22px,2.5vw,30px); line-height:1.25; margin:0 0 12px; letter-spacing:-.015em; }
.blog-excerpt { color:var(--text-muted); line-height:1.6; font-size:14px; margin:0 0 16px; display:-webkit-box; -webkit-box-orient:vertical; -webkit-line-clamp:3; overflow:hidden; }
.blog-meta { font-size:12px; color:var(--text-muted); line-height:1.5; margin:0 0 14px; }
.blog-tags { display:flex; flex-wrap:wrap; gap:6px; margin-bottom:10px; }
.blog-tags span { background:var(--brand-tint); color:var(--brand-ink,var(--brand)); padding:4px 8px; border-radius:5px; font-size:12px; }
.blog-stories { margin-top:32px; }.blog-section-title { font-size:20px; margin:0 0 16px; }
.blog-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:0 28px; }
.blog-card { display:flex; align-items:flex-start; gap:18px; padding:22px 0; border-top:1px solid var(--border); color:var(--text); text-decoration:none; }
.blog-thumb { width:128px; aspect-ratio:1; flex-shrink:0; border-radius:10px; }.blog-thumb img { width:100%; height:100%; object-fit:cover; }.blog-card-body { min-width:0; }
.blog-card .blog-meta { margin-bottom:6px; }.blog-card h3 { font-size:17px; line-height:1.4; margin:0 0 8px; }.blog-card .blog-excerpt { -webkit-line-clamp:2; margin-bottom:10px; }
.blog-card:hover h3,.blog-feature:hover h2 { color:var(--brand-ink,var(--brand)); text-decoration:underline; text-underline-offset:3px; }
.blog a:focus-visible,.blog button:focus-visible { outline:2px solid var(--brand); outline-offset:4px; }
.blog-empty { min-height:260px; display:flex; flex-direction:column; align-items:center; justify-content:center; text-align:center; gap:12px; color:var(--text-muted); padding:28px; }
.blog-empty h2 { color:var(--text); font-size:21px; margin:0; }.blog-empty p { margin:0; line-height:1.6; }.blog-empty button { border:1px solid var(--border); color:var(--brand-ink,var(--brand)); background:var(--surface); padding:12px 18px; border-radius:8px; cursor:pointer; }
@media(max-width:900px) { .blog-grid { grid-template-columns:1fr; }.blog-thumb { width:140px; } }
@media(max-width:600px) {
  .blog { padding-block:20px 32px; }.blog-hero { padding-bottom:20px; }.blog-browse { display:none; }.blog-hero p:not(.blog-eyebrow) { font-size:14px; }
  .blog-toolbar { align-items:stretch; flex-direction:column; gap:12px; margin-bottom:20px; }.blog-feature { grid-template-columns:1fr; }.blog-feature-image { min-height:0; aspect-ratio:16/9; }.blog-feature-body { padding:22px; }
  .blog-card { gap:14px; padding-block:18px; }.blog-thumb { width:88px; }.blog-card h3 { font-size:16px; }.blog-card .blog-excerpt { font-size:13px; }.blog-stories { margin-top:26px; }
}
</style>
