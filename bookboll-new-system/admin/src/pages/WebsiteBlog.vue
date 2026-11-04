<script setup>
/**
 * WebsiteBlog.vue — "My Website → Blog": a provider's OWN blog posts, shown on their
 * white-label storefront (their /blog, scoped to them). Mirrors the super-admin
 * marketplace blog (/blog-posts), but every post is stamped with the provider's
 * owner_user_id and the storefront only reveals the Blog module once they publish one.
 */
import { ref, computed, onMounted, watch } from 'vue'
import { loadSiteSettings } from '../lib/siteSettings.js'
import { appBaseUrl } from '../lib/appBase.js'
import { useCurrentUser } from '../lib/currentUser.js'
import { usePremium } from '../lib/premium.js'
import { loadAllPosts, savePost, deletePost, slugify, fmtPostDate, renderMarkdown, sanitizeHtml } from '../lib/blog.js'
import { uploadImage, imageUploadConfigured } from '../lib/imageUpload.js'
import { markDirty, clearDirty, confirmDiscardIfDirty } from '../lib/unsavedGuard.js'
import RichEditor from '../components/RichEditor.vue'
import { Plus, Pencil, Trash2, Eye, X, Loader2, ImagePlus, ExternalLink, FileText, Check, Newspaper } from 'lucide-vue-next'

const { currentOwnerUserId, isSuperAdmin, role: actorRole } = useCurrentUser()
const { isPremium, requirePremium } = usePremium()   // the storefront blog is a Premium feature
const ownerId = computed(() => currentOwnerUserId.value)
// Providers manage their own; a super admin / platform staff needs to pick a provider first.
const needsProvider = computed(() => !ownerId.value)

const posts = ref([])
const loading = ref(true)
const loadErr = ref('')
const view = ref('list')        // 'list' | 'edit'
const saving = ref(false)
const formErr = ref('')
const imgBusy = ref(false)
const showPreview = ref(false)
const brand = ref({ name: '', subdomain: '' })   // for the default author + "view live" link

const blank = () => ({ id: null, title: '', slug: '', excerpt: '', body: '', cover_image: '', status: 'draft', author_name: brand.value.name || '', tags: [], meta_title: '', meta_description: '', published_at: null })
const form = ref(blank())
const tagsInput = ref('')
const slugTouched = ref(false)

async function loadBrand() {
  if (!ownerId.value) { brand.value = { name: '', subdomain: '' }; return }
  const { settings, username } = await loadSiteSettings()
  brand.value = {
    name: settings.brand_name || username || '',
    subdomain: String(settings.brand_subdomain || username || '').toLowerCase(),
  }
}

async function load() {
  loading.value = true; loadErr.value = ''
  if (!ownerId.value) { posts.value = []; loading.value = false; return }
  await loadBrand()
  const res = await loadAllPosts(ownerId.value)
  if (res.error) loadErr.value = res.error
  posts.value = res.data || []
  loading.value = false
}
onMounted(load)
watch(ownerId, load)

// Live URL on the provider's white-label storefront (scoped via ?b=<subdomain>).
function liveUrl(p) {
  const origin = appBaseUrl()
  const b = brand.value.subdomain ? `?b=${brand.value.subdomain}` : ''
  return `${origin}/blog/${p.slug}${b}`
}

watch([form, tagsInput], () => { if (view.value === 'edit') markDirty() }, { deep: true, flush: 'sync' })
async function closeEditor() { if (await confirmDiscardIfDirty()) view.value = 'list' }
function newPost() { clearDirty(); form.value = blank(); tagsInput.value = ''; slugTouched.value = false; formErr.value = ''; showPreview.value = false; view.value = 'edit' }
function editPost(p) { clearDirty();
  const body = p.body || ''
  const htmlBody = /<\/?[a-z][\s\S]*>/i.test(body) ? body : renderMarkdown(body)
  form.value = { ...p, body: htmlBody, tags: p.tags || [] }
  tagsInput.value = (p.tags || []).join(', '); slugTouched.value = true; formErr.value = ''; showPreview.value = false; view.value = 'edit'
}
function onTitle() { if (!slugTouched.value) form.value.slug = slugify(form.value.title) }

const preview = computed(() => sanitizeHtml(form.value.body))

async function onCover(e) {
  const file = e.target.files?.[0]; if (!file) return
  imgBusy.value = true; formErr.value = ''
  const res = await uploadImage(file, 'blog')
  imgBusy.value = false
  if (res.error) { formErr.value = res.error; return }
  form.value.cover_image = res.url
}

async function save(publish) {
  formErr.value = ''
  if (!ownerId.value) { formErr.value = 'Pick a provider first.'; return }
  if (!form.value.title.trim()) { formErr.value = 'A title is required.'; return }
  if (!form.value.slug.trim()) form.value.slug = slugify(form.value.title)
  form.value.tags = tagsInput.value.split(',').map(t => t.trim()).filter(Boolean)
  form.value.body = sanitizeHtml(form.value.body)
  if (publish === true) form.value.status = 'published'
  if (publish === false) form.value.status = 'draft'
  saving.value = true
  const res = await savePost(form.value, ownerId.value)
  saving.value = false
  // Show what the server actually said. This used to swallow any error mentioning
  // a "column" and replace it with "run the blog_posts owner migration" — a leftover
  // from the Supabase build (that table and PostgREST's schema-cache errors are both
  // gone). It hid the real cause of every save failure behind a migration that does
  // not exist here.
  if (res.error) { formErr.value = res.error; return }
  clearDirty(); await load(); view.value = 'list'
}

async function remove(p) {
  if (!confirm(`Delete "${p.title}"? This cannot be undone.`)) return
  const res = await deletePost(p.id, ownerId.value)
  if (res.error) { alert(res.error); return }
  await load()
}
</script>

<template>
  <div class="page">
    <!-- Non-provider staff with no provider context -->
    <template v-if="needsProvider">
      <div class="page-header">
        <div>
          <h1 class="page-title">Blog</h1>
          <div class="page-subtitle">Write posts that appear on your own website to attract customers</div>
        </div>
      </div>
      <div class="blogadm-pick"><Newspaper :size="34" /><h3>Pick a provider</h3><p>Your website blog belongs to a provider business. Switch to a provider with the role switcher (top bar) to manage theirs.</p></div>
    </template>

    <!-- LIST -->
    <template v-else-if="view === 'list'">
      <div class="page-header">
        <div>
          <h1 class="page-title">Blog</h1>
          <div class="page-subtitle">Share news, stories and useful tips. Your Blog page appears when you publish your first post.</div>
        </div>
        <button class="btn btn-primary" @click="requirePremium('blog') && newPost()"><Plus :size="16" /> New post<span v-if="!isPremium" class="prem-lock">Premium</span></button>
      </div>

      <div v-if="loadErr" class="blogadm-warn">{{ loadErr }}</div>
      <div v-if="loading" class="blogadm-empty">Loading…</div>
      <div v-else-if="!posts.length" class="blogadm-empty"><FileText :size="34" /><p>No posts yet. Write your first article — it’ll appear on your website’s blog.</p></div>

      <div v-else class="blogadm-list">
        <div v-for="p in posts" :key="p.id" class="blogadm-row">
          <div class="blogadm-thumb" :style="p.cover_image ? { backgroundImage: `url(${p.cover_image})` } : {}">
            <FileText v-if="!p.cover_image" :size="20" />
          </div>
          <div class="blogadm-main">
            <div class="blogadm-title">{{ p.title }}</div>
            <div class="blogadm-meta">
              <span class="blogadm-badge" :class="p.status">{{ p.status === 'published' ? 'Published' : 'Draft' }}</span>
              <span class="blogadm-slug">/blog/{{ p.slug }}</span>
              <span v-if="p.published_at" class="blogadm-date">· {{ fmtPostDate(p.published_at) }}</span>
            </div>
          </div>
          <div class="blogadm-actions">
            <a v-if="p.status === 'published'" :href="liveUrl(p)" target="_blank" rel="noopener" class="blogadm-ic" title="View live"><ExternalLink :size="16" /></a>
            <button class="blogadm-ic" title="Edit" @click="editPost(p)"><Pencil :size="16" /></button>
            <button class="blogadm-ic danger" title="Delete" @click="remove(p)"><Trash2 :size="16" /></button>
          </div>
        </div>
      </div>
    </template>

    <!-- EDITOR -->
    <template v-else>
      <div class="page-header">
        <div>
          <button class="blogadm-back" @click="closeEditor"><X :size="15" /> Close</button>
          <h1 class="page-title">{{ form.id ? 'Edit post' : 'New post' }}</h1>
        </div>
        <div class="blogadm-head-actions">
          <button class="btn btn-secondary" @click="showPreview = !showPreview"><Eye :size="15" /> {{ showPreview ? 'Hide' : 'Preview' }}</button>
          <button class="btn btn-secondary" :disabled="saving" @click="save(false)">Save draft</button>
          <button class="btn btn-primary" :disabled="saving" @click="save(true)">
            <Loader2 v-if="saving" :size="15" class="spin" /><Check v-else :size="15" /> Publish
          </button>
        </div>
      </div>

      <div v-if="formErr" class="blogadm-warn">{{ formErr }}</div>

      <div class="blogadm-grid">
        <div class="blogadm-form">
          <label class="blogadm-l">Title</label>
          <input aria-label="Post title" v-model="form.title" class="blogadm-in" placeholder="5 tips to get the most out of your membership" @input="onTitle" />

          <label class="blogadm-l">Page address</label>
          <div class="blogadm-slugrow"><span>/blog/</span><input aria-label="Page address" v-model="form.slug" class="blogadm-in" placeholder="membership-tips" @input="slugTouched = true" /></div>

          <label class="blogadm-l">Excerpt <span class="blogadm-opt">shown on cards & in search</span></label>
          <textarea aria-label="Post summary" v-model="form.excerpt" class="blogadm-in" rows="2" placeholder="A short summary…"></textarea>

          <label class="blogadm-l">Body</label>
          <RichEditor v-model="form.body" placeholder="Write your article — use the toolbar to format, color and link text…" />

          <div v-if="showPreview" class="blogadm-preview">
            <div class="blogadm-preview-tag">Preview</div>
            <div class="blog-content" v-html="preview"></div>
          </div>
        </div>

        <aside class="blogadm-side">
          <label class="blogadm-l">Cover image</label>
          <div class="blogadm-cover" :style="form.cover_image ? { backgroundImage: `url(${form.cover_image})` } : {}">
            <span v-if="!form.cover_image" class="blogadm-cover-ph"><ImagePlus :size="22" /></span>
          </div>
          <label class="btn btn-secondary blogadm-upload" v-if="imageUploadConfigured()">
            <Loader2 v-if="imgBusy" :size="15" class="spin" /><ImagePlus v-else :size="15" />
            {{ imgBusy ? 'Uploading…' : (form.cover_image ? 'Replace image' : 'Upload image') }}
            <input type="file" accept="image/*" hidden @change="onCover" />
          </label>
          <button v-if="form.cover_image" class="blogadm-rm" @click="form.cover_image = ''">Remove image</button>

          <label class="blogadm-l">Author</label>
          <input aria-label="Author" v-model="form.author_name" class="blogadm-in" :placeholder="brand.name || 'Your business'" />

          <label class="blogadm-l">Tags <span class="blogadm-opt">comma-separated</span></label>
          <input aria-label="Tags" v-model="tagsInput" class="blogadm-in" placeholder="tips, fitness, dubai" />

          <details class="blogadm-seo">
            <summary>Search engine appearance</summary>
            <label class="blogadm-l">Meta title</label>
            <input aria-label="Search title" v-model="form.meta_title" class="blogadm-in" :placeholder="form.title" />
            <label class="blogadm-l">Meta description</label>
            <textarea aria-label="Search description" v-model="form.meta_description" class="blogadm-in" rows="2" :placeholder="form.excerpt"></textarea>
          </details>
        </aside>
      </div>
    </template>
  </div>
</template>

<style scoped>
.page-subtitle code, .blogadm-warn code { background: var(--bg, #f1f5f9); padding: 1px 6px; border-radius: 5px; font-size: 12px; }
.blogadm-head-actions { display: flex; gap: 8px; flex-wrap: wrap; }
.blogadm-back { background: none; border: none; color: var(--text-muted, #64748b); font-weight: 700; font-size: 13px; cursor: pointer; display: inline-flex; align-items: center; gap: 5px; padding: 0 0 6px; }
.btn:disabled { opacity: .6; cursor: not-allowed; }
.spin { animation: bspin 1s linear infinite; } @keyframes bspin { to { transform: rotate(360deg); } }
.blogadm-warn { background: var(--warning-bg, #fffbeb); border: 1px solid var(--warning-border, #fde68a); color: var(--warning-ink, #92400e); font-size: 13.5px; padding: 11px 14px; border-radius: 10px; margin-bottom: 14px; }
.blogadm-empty { text-align: center; color: var(--text-light, #94a3b8); padding: 50px; display: flex; flex-direction: column; align-items: center; gap: 10px; }
.blogadm-pick { display: flex; flex-direction: column; align-items: center; gap: 8px; text-align: center; padding: 60px 20px; color: var(--text-light, #94a3b8); }
.blogadm-pick h3 { margin: 6px 0 0; color: var(--text, #0f172a); }
.blogadm-pick p { max-width: 380px; font-size: 13.5px; }

.blogadm-list { display: flex; flex-direction: column; gap: 8px; }
.blogadm-row { display: flex; align-items: center; gap: 14px; background: var(--surface, #ffffff); border: 1px solid var(--border, #e2e8f0); border-radius: 14px; padding: 12px 14px; }
.blogadm-thumb { width: 56px; height: 44px; flex-shrink: 0; border-radius: 9px; background: var(--bg, #f1f5f9); background-size: cover; background-position: center; color: var(--text-light, #94a3b8); display: flex; align-items: center; justify-content: center; }
.blogadm-main { flex: 1; min-width: 0; }
.blogadm-title { font-size: 15px; font-weight: 800; color: var(--text, #0f172a); }
.blogadm-meta { display: flex; align-items: center; gap: 8px; font-size: 12.5px; color: var(--text-light, #94a3b8); margin-top: 3px; flex-wrap: wrap; }
.blogadm-badge { font-size: 10.5px; font-weight: 800; text-transform: uppercase; letter-spacing: .04em; padding: 2px 8px; border-radius: 999px; }
.blogadm-badge.published { background: var(--positive-bg, #dcfce7); color: var(--positive-ink, #15803d); }
.blogadm-badge.draft { background: var(--bg, #f1f5f9); color: var(--text-muted, #64748b); }
.blogadm-slug { font-family: ui-monospace, monospace; }
.blogadm-actions { display: flex; gap: 6px; }
.blogadm-ic { width: 34px; height: 34px; border-radius: 9px; border: 1px solid var(--border, #e2e8f0); background: var(--surface, #ffffff); color: var(--text-secondary, #475569); display: flex; align-items: center; justify-content: center; cursor: pointer; text-decoration: none; }
.blogadm-ic:hover { border-color: var(--primary); color: var(--primary-ink); }
.blogadm-ic.danger:hover { border-color: var(--danger-border, #fecaca); color: var(--danger-ink, #dc2626); background: var(--danger-bg, #fef2f2); }

.blogadm-grid { display: grid; grid-template-columns: 1fr 300px; gap: 20px; }
.blogadm-l { display: block; font-size: 12.5px; font-weight: 800; color: var(--text-secondary, #334155); margin: 14px 0 5px; }
.blogadm-l:first-child { margin-top: 0; }
.blogadm-opt { font-weight: 600; color: var(--text-light, #94a3b8); font-size: 11px; }
.blogadm-in { width: 100%; box-sizing: border-box; border: 1.5px solid var(--border, #e2e8f0); border-radius: 10px; padding: 10px 12px; font-size: 14px; color: var(--text, #0f172a); font-family: inherit; }
.blogadm-in:focus { outline: none; border-color: var(--primary); }
.blogadm-slugrow { display: flex; align-items: center; gap: 6px; }
.blogadm-slugrow span { font-size: 13px; color: var(--text-light, #94a3b8); font-family: ui-monospace, monospace; }
.blogadm-preview { margin-top: 16px; border: 1px solid var(--border, #e2e8f0); border-radius: 12px; padding: 18px; background: var(--surface, #ffffff); position: relative; }
.blogadm-preview-tag { position: absolute; top: -9px; left: 14px; background: #0f172a; color: #fff; font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: .05em; padding: 2px 8px; border-radius: 999px; }
.blogadm-side { background: var(--surface, #ffffff); border: 1px solid var(--border, #e2e8f0); border-radius: 14px; padding: 16px; height: fit-content; }
.blogadm-cover { width: 100%; height: 130px; border-radius: 10px; background: var(--bg, #f1f5f9); background-size: cover; background-position: center; display: flex; align-items: center; justify-content: center; color: var(--text-light, #94a3b8); }
.blogadm-upload { width: 100%; justify-content: center; margin-top: 10px; cursor: pointer; }
.blogadm-rm { width: 100%; margin-top: 6px; background: none; border: none; color: var(--text-light, #94a3b8); font-size: 12.5px; font-weight: 700; cursor: pointer; }
.blogadm-rm:hover { color: var(--danger-ink, #dc2626); }
.blogadm-seo { margin-top: 14px; border-top: 1px solid #eef2f6; padding-top: 12px; }
.blogadm-seo summary { font-size: 12.5px; font-weight: 800; color: var(--text-secondary, #334155); cursor: pointer; }
@media (max-width: 820px) { .blogadm-grid { grid-template-columns: 1fr; } }

/* Phone (≤600px): 360px-wide fixes — no desktop impact */
@media (max-width: 600px) {
  /* iOS auto-zoom guard: inputs + textareas share .blogadm-in */
  .blogadm-in { font-size: 16px; }

  /* List rows: keep long slugs/titles from pushing past the viewport */
  .blogadm-title { overflow-wrap: anywhere; }
  .blogadm-slug { overflow-wrap: anywhere; }
  .blogadm-row { gap: 10px; padding: 10px 12px; }
  .blogadm-thumb { width: 46px; }

  /* Touch targets: bigger icon buttons + full-width tappable editor actions */
  .blogadm-ic { width: 40px; height: 40px; }
  .blogadm-head-actions { width: 100%; }
  .blogadm-head-actions .btn { min-height: 44px; flex: 1 1 45%; justify-content: center; }

  /* Empty states: less wasted padding at 360px */
  .blogadm-empty { padding: 36px 16px; }
  .blogadm-pick { padding: 40px 16px; }

  /* Status pill: bump essential Published/Draft label up from 10.5px */
  .blogadm-badge { font-size: 11px; padding: 2px 7px; }
}
</style>
