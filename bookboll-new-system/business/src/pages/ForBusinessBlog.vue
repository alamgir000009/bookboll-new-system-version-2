<script setup>
/**
 * ForBusinessBlog.vue — the dedicated /for-business/blog index.
 *
 * A separate publication from the customer marketplace blog (/blog): these posts
 * target venue owners, gyms and academies — growth guides, product news, playbooks.
 * Reads Bookboll posts with audience='business' (see lib/blog.js + migration
 * 20260766000000). Fixed teal landing theme, bare 'auth' layout, indexable.
 */
import { ref, onMounted } from 'vue'
import { loadPublishedPosts, fmtPostDate } from '../lib/blog.js'
import { useSeo } from '../lib/seo.js'
import { authUser } from '../lib/auth.js'
import { adminLoginUrl, adminDashboardUrl } from '../lib/crossApp.js'
import BusinessHeader from '../components/BusinessHeader.vue'
import { Newspaper, ArrowRight, ArrowLeft } from 'lucide-vue-next'

const posts = ref([])
const loading = ref(true)
onMounted(async () => {
  posts.value = await loadPublishedPosts({ audience: 'business' })
  loading.value = false
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
    <!-- ── Top nav (fixed teal landing theme, mirrors /for-business/pricing) ── -->
    <BusinessHeader />

    <!-- ── Hero ── -->
    <section class="fbb-hero">
      <router-link to="/" class="fbb-back"><ArrowLeft :size="15" /> Bookboll for Business</router-link>
      <h1>The <span class="fbb-hl">Business</span> Blog</h1>
      <p class="fbb-hero-sub">Guides, playbooks and product news for venues, gyms and academies — pricing your courts, filling quiet hours and growing memberships.</p>
    </section>

    <!-- ── Posts ── -->
    <section class="fbb-sec">
      <div v-if="loading" class="fbb-msg">Loading…</div>
      <div v-else-if="!posts.length" class="fbb-empty"><Newspaper :size="34" /><p>No articles yet — check back soon.</p></div>

      <div v-else class="fbb-grid">
        <router-link v-for="p in posts" :key="p.id" :to="`/blog/${p.slug}`" class="fbb-card">
          <div class="fbb-card-cover" :style="p.cover_image ? { backgroundImage: `url(${p.cover_image})` } : {}">
            <span v-if="!p.cover_image" class="fbb-card-ph"><Newspaper :size="26" /></span>
          </div>
          <div class="fbb-card-body">
            <div v-if="p.tags?.length" class="fbb-tags"><span v-for="t in p.tags.slice(0, 3)" :key="t" class="fbb-tag">{{ t }}</span></div>
            <h2 class="fbb-card-title">{{ p.title }}</h2>
            <p v-if="p.excerpt" class="fbb-card-excerpt">{{ p.excerpt }}</p>
            <div class="fbb-card-foot">
              <span class="fbb-card-meta">{{ p.author_name }}<span v-if="p.published_at"> · {{ fmtPostDate(p.published_at) }}</span></span>
              <span class="fbb-card-read">Read <ArrowRight :size="14" /></span>
            </div>
          </div>
        </router-link>
      </div>
    </section>

    <!-- ── CTA ── -->
    <section class="fbb-cta">
      <h2>Put the playbook to work.</h2>
      <a v-if="authUser" :href="adminDashboardUrl()" class="fbb-cta-btn">Open my dashboard <ArrowRight :size="17" /></a>
      <router-link v-else to="/signup" class="fbb-cta-btn">Create your free account <ArrowRight :size="17" /></router-link>
    </section>

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

/* hero */
.fbb-hero { background:radial-gradient(1100px 460px at 80% -10%, rgba(20,184,166,.22), transparent 60%), linear-gradient(135deg,#0f172a,#134e4a); color:#fff; padding:46px 22px 54px; text-align:center; }
.fbb-back { display:inline-flex; align-items:center; gap:6px; color:var(--teal-lite); font-weight:800; font-size:13px; text-decoration:none; margin-bottom:16px; }
.fbb-hero h1 { color:#fff; font-size:38px; font-weight:900; letter-spacing:-.02em; line-height:1.14; margin:0; }
.fbb-hl { color:var(--teal-2); }
.fbb-hero-sub { max-width:640px; margin:14px auto 0; color:#cbd5e1; font-size:15.5px; line-height:1.6; }

.fbb-sec { max-width:1120px; margin:0 auto; padding:42px 22px; }
.fbb-msg { text-align:center; padding:50px; color:#94a3b8; }
.fbb-empty { text-align:center; color:#94a3b8; padding:50px; display:flex; flex-direction:column; align-items:center; gap:10px; }

/* post cards */
.fbb-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(290px, 1fr)); gap:18px; }
.fbb-card { display:flex; flex-direction:column; background:#fff; border:1px solid #e2e8f0; border-radius:16px; overflow:hidden; text-decoration:none; transition:transform .12s, box-shadow .12s; }
.fbb-card:hover { transform:translateY(-2px); box-shadow:0 12px 30px rgba(15,23,42,.1); }
.fbb-card-cover { height:168px; background:linear-gradient(135deg,#134e4a,var(--teal)); background-size:cover; background-position:center; display:flex; align-items:center; justify-content:center; color:rgba(255,255,255,.9); }
.fbb-card-body { padding:16px 18px 18px; display:flex; flex-direction:column; flex:1; }
.fbb-tags { display:flex; flex-wrap:wrap; gap:6px; margin-bottom:8px; }
.fbb-tag { font-size:10.5px; font-weight:800; text-transform:uppercase; letter-spacing:.04em; color:#0f766e; background:#ccfbf1; padding:2px 8px; border-radius:999px; }
.fbb-card-title { font-size:18px; font-weight:800; color:var(--ink); margin:0 0 6px; line-height:1.3; }
.fbb-card-excerpt { font-size:14px; color:#64748b; line-height:1.55; margin:0 0 14px; flex:1; }
.fbb-card-foot { display:flex; align-items:center; justify-content:space-between; gap:10px; }
.fbb-card-meta { font-size:12.5px; color:#94a3b8; font-weight:600; }
.fbb-card-read { display:inline-flex; align-items:center; gap:5px; font-size:13px; font-weight:800; color:#0f766e; }

/* CTA + footer (mirrors pricing) */
.fbb-cta { background:linear-gradient(135deg,#0f172a,#134e4a); color:#fff; text-align:center; padding:48px 22px; }
.fbb-cta h2 { color:#fff; font-size:26px; font-weight:900; letter-spacing:-.02em; margin:0 0 18px; }
.fbb-cta-btn { display:inline-flex; align-items:center; gap:8px; background:var(--teal); color:#04201c; font-weight:800; font-size:15.5px; padding:13px 24px; border-radius:999px; text-decoration:none; }
.fbb-cta-btn:hover { background:var(--teal-2); }
.fbb-foot { max-width:1120px; margin:0 auto; padding:22px; display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; font-size:12.5px; color:#64748b; }
.fbb-foot-links { display:flex; gap:16px; flex-wrap:wrap; }
.fbb-foot-links a { color:#475569; font-weight:700; text-decoration:none; }
.fbb-foot-links a:hover { color:var(--teal); }

@media (max-width:700px) {
  .fbb-hero h1 { font-size:28px; }
  .fbb-hero-sub { font-size:14px; }
  .fbb-sec { padding:26px 14px; }
  .fbb-grid { grid-template-columns:1fr; gap:12px; }
  .fbb-card-cover { height:auto; aspect-ratio:16 / 9; }
  .fbb-card-body { padding:13px 14px 14px; }
  .fbb-card-title { font-size:16px; }
}
</style>

