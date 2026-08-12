<script setup>
/** Blog.vue — public blog index: published posts as cards. */
import { ref, computed, onMounted } from 'vue'
import { loadPublishedPosts, fmtPostDate } from '../../lib/blog.js'
import { useSeo } from '../../lib/seo.js'
import { platformBrand, activeBrand, isWhiteLabel } from '../../lib/brand.js'
import { Newspaper, ArrowRight } from 'lucide-vue-next'

const posts = ref([])
const loading = ref(true)
// White-label → that provider's own posts; apex → the Bookboll marketplace posts.
const ownerId = computed(() => activeBrand.value?.ownerUserId || null)
const brandName = computed(() => (isWhiteLabel.value ? activeBrand.value?.name : platformBrand.value?.name) || 'Bookboll')

onMounted(async () => { posts.value = await loadPublishedPosts({ ownerId: ownerId.value }); loading.value = false })

useSeo(() => ({
  title: 'Blog — tips, guides & stories',
  description: `Tips, guides and stories from ${brandName.value}.`,
  path: '/blog',
  noindex: isWhiteLabel.value,
}))
</script>

<template>
  <div class="blog">
    <header class="blog-hero">
      <h1>The {{ brandName }} Blog</h1>
      <p>Tips, guides and stories for players, hosts and venue owners.</p>
    </header>

    <div v-if="loading" class="cmsg">Loading…</div>
    <div v-else-if="!posts.length" class="blog-empty"><Newspaper :size="34" /><p>No posts yet — check back soon.</p></div>

    <div v-else class="blog-grid">
      <router-link v-for="p in posts" :key="p.id" :to="`/blog/${p.slug}`" class="bcard">
        <div class="bcard-cover" :style="p.cover_image ? { backgroundImage: `url(${p.cover_image})` } : {}">
          <span v-if="!p.cover_image" class="bcard-cover-ph"><Newspaper :size="26" /></span>
        </div>
        <div class="bcard-body">
          <div v-if="p.tags?.length" class="bcard-tags"><span v-for="t in p.tags.slice(0,3)" :key="t" class="bcard-tag">{{ t }}</span></div>
          <h2 class="bcard-title">{{ p.title }}</h2>
          <p v-if="p.excerpt" class="bcard-excerpt">{{ p.excerpt }}</p>
          <div class="bcard-foot">
            <span class="bcard-meta">{{ p.author_name }}<span v-if="p.published_at"> · {{ fmtPostDate(p.published_at) }}</span></span>
            <span class="bcard-read">Read <ArrowRight :size="14" /></span>
          </div>
        </div>
      </router-link>
    </div>
  </div>
</template>

<style scoped>
.blog-hero { text-align: center; padding: 26px 0 22px; }
.blog-hero h1 { font-size: 30px; font-weight: 900; color: #0f172a; margin: 0; }
.blog-hero p { font-size: 15px; color: var(--text-muted, #64748b); margin: 8px 0 0; }
.cmsg { text-align: center; padding: 50px; color: var(--text-light, #94a3b8); }
.blog-empty { text-align: center; color: var(--text-light, #94a3b8); padding: 50px; display: flex; flex-direction: column; align-items: center; gap: 10px; }
.blog-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(290px, 1fr)); gap: 18px; }
.bcard { display: flex; flex-direction: column; background: var(--surface, #ffffff); border: 1px solid var(--border, #e2e8f0); border-radius: 16px; overflow: hidden; text-decoration: none; transition: transform .12s, box-shadow .12s; }
.bcard:hover { transform: translateY(-2px); box-shadow: 0 12px 30px rgba(15,23,42,.1); }
.bcard-cover { height: 168px; background: linear-gradient(135deg, var(--brand-accent), var(--brand)); background-size: cover; background-position: center; display: flex; align-items: center; justify-content: center; color: rgba(255,255,255,.9); }
.bcard-body { padding: 16px 18px 18px; display: flex; flex-direction: column; flex: 1; }
.bcard-tags { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 8px; }
.bcard-tag { font-size: 10.5px; font-weight: 800; text-transform: uppercase; letter-spacing: .04em; color: var(--brand); background: color-mix(in srgb, var(--brand) 12%, white); padding: 2px 8px; border-radius: 999px; }
.bcard-title { font-size: 18px; font-weight: 800; color: #0f172a; margin: 0 0 6px; line-height: 1.3; }
.bcard-excerpt { font-size: 14px; color: var(--text-muted, #64748b); line-height: 1.55; margin: 0 0 14px; flex: 1; }
.bcard-foot { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
.bcard-meta { font-size: 12.5px; color: var(--text-light, #94a3b8); font-weight: 600; }
.bcard-read { display: inline-flex; align-items: center; gap: 5px; font-size: 13px; font-weight: 800; color: var(--brand); }

@media (max-width: 760px) {
  /* Tighten the hero chrome so the first card surfaces sooner. */
  .blog-hero { padding: 16px 0 14px; }
  .blog-hero h1 { font-size: 21px; }
  /* Subtitle restates the h1/SEO description — hide it so the first card surfaces sooner. */
  .blog-hero p { display: none; }
  /* Denser single-column browse grid with a tighter gap. */
  .blog-grid { grid-template-columns: 1fr; gap: 12px; }
  /* Proper photo shape: 16/9 (~193px at 375px viewport) instead of a letterbox strip. */
  .bcard-cover { height: auto; aspect-ratio: 16 / 9; }
  /* Tighter card body + clamped excerpt keep card heights predictable. */
  .bcard-body { padding: 13px 14px 14px; }
  .bcard-title { font-size: 16px; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
  .bcard-excerpt { margin-bottom: 10px; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
  /* Readable chip labels; max 2 chips per card on mobile (desktop keeps 3). */
  .bcard-tag { font-size: 11px; }
  .bcard-tags .bcard-tag:nth-child(n+3) { display: none; }
  /* Reclaim horizontal room for empty/loading states. */
  .cmsg, .blog-empty { padding: 40px 16px; }
}
</style>
