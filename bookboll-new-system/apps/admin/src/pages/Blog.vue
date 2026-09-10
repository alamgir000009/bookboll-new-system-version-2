<script setup>
/**
 * Blog.vue — super-admin blog manager for BOTH Bookboll blogs, switched by tab:
 *   • Marketplace  → Laravel posts type=main (customer /blog)
 *   • For Business → Laravel posts type=business (/for-business/blog)
 * Body is rich HTML (sanitized, see lib/blog.js). The provider WebsiteBlog uses
 * the same helpers against Laravel /admin/site-blog.
 */
import { ref, computed, onMounted } from 'vue'
import { loadAllPosts, savePost, deletePost, slugify, fmtPostDate, renderMarkdown, sanitizeHtml } from '../lib/blog.js'
import { uploadImage, imageUploadConfigured } from '../lib/imageUpload.js'
import { businessHomeUrl } from '../lib/crossApp.js'
import { webBaseUrl } from '../lib/appBase.js'
import RichEditor from '../components/RichEditor.vue'
import { Plus, Pencil, Trash2, Eye, EyeOff, X, Loader2, ImagePlus, ExternalLink, FileText, Check, Store, Briefcase } from 'lucide-vue-next'

const posts = ref([])
const loading = ref(true)
const loadErr = ref('')
const view = ref('list')        // 'list' | 'edit'
const saving = ref(false)
const formErr = ref('')
const imgBusy = ref(false)
const showPreview = ref(false)

// Which Bookboll blog is being managed. The two are fully separate publications —
// the customer marketplace blog and the for-businesses landing blog.
const audience = ref('marketplace')   // 'marketplace' | 'business'
const isBiz = computed(() => audience.value === 'business')
const blogBase = computed(() => isBiz.value ? businessHomeUrl('/blog') : `${webBaseUrl()}/blog`)

const blank = () => ({ id: null, title: '', slug: '', excerpt: '', body: '', cover_image: '', status: 'draft', author_name: 'Bookboll', tags: [], meta_title: '', meta_description: '', published_at: null })
const form = ref(blank())
const tagsInput = ref('')
const slugTouched = ref(false)

async function load() {
  loading.value = true; loadErr.value = ''
  const res = await loadAllPosts(null, audience.value)
  if (res.error) loadErr.value = res.error
  posts.value = res.data || []
  loading.value = false
}
onMounted(load)
function switchAudience(a) {
  if (audience.value === a) return
  audience.value = a
  view.value = 'list'
  load()
}

function newPost() { form.value = blank(); tagsInput.value = ''; slugTouched.value = false; formErr.value = ''; showPreview.value = false; view.value = 'edit' }
function editPost(p) {
  // Legacy posts may be stored as markdown — convert to HTML so the rich editor shows them formatted.
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
  if (!form.value.title.trim()) { formErr.value = 'A title is required.'; return }
  if (!form.value.slug.trim()) form.value.slug = slugify(form.value.title)
  form.value.tags = tagsInput.value.split(',').map(t => t.trim()).filter(Boolean)
  form.value.body = sanitizeHtml(form.value.body)   // store clean HTML
  if (publish === true) form.value.status = 'published'
  if (publish === false) form.value.status = 'draft'
  saving.value = true
  const res = await savePost(form.value, null, audience.value)   // stamps the active blog
  saving.value = false
  if (res.error) { formErr.value = res.error; return }
  await load(); view.value = 'list'
}

async function remove(p) {
  if (!confirm(`Delete "${p.title}"? This cannot be undone.`)) return
  const res = await deletePost(p.id)
  if (res.error) { alert(res.error); return }
  await load()
}
</script>

<template>
  <div class="page">
    <!-- LIST -->
    <template v-if="view === 'list'">
      <div class="page-header">
        <div>
          <div class="page-title">Blog</div>
          <div class="page-subtitle" v-if="isBiz">Articles shown on the for-businesses site (<code>business.bookboll.com/blog</code>).</div>
          <div class="page-subtitle" v-else>Articles shown on the public marketplace at <code>/blog</code>.</div>
        </div>
        <button class="btn btn-primary" @click="newPost"><Plus :size="16" /> New post</button>
      </div>

      <!-- Which Bookboll publication: the customer marketplace vs the for-businesses landing -->
      <div class="blogadm-tabs" role="tablist">
        <button class="blogadm-tab" :class="{ on: !isBiz }" role="tab" :aria-selected="!isBiz" @click="switchAudience('marketplace')"><Store :size="15" /> Marketplace blog</button>
        <button class="blogadm-tab" :class="{ on: isBiz }" role="tab" :aria-selected="isBiz" @click="switchAudience('business')"><Briefcase :size="15" /> For Business blog</button>
      </div>

      <div v-if="loadErr" class="blogadm-warn">{{ loadErr }}</div>
      <div v-if="loading" class="blogadm-empty">Loading…</div>
      <div v-else-if="!posts.length" class="blogadm-empty"><FileText :size="34" /><p>No {{ isBiz ? 'For Business' : 'marketplace' }} posts yet. Write your first article.</p></div>

      <div v-else class="blogadm-list">
        <div v-for="p in posts" :key="p.id" class="blogadm-row">
          <div class="blogadm-thumb" :style="p.cover_image ? { backgroundImage: `url(${p.cover_image})` } : {}">
            <FileText v-if="!p.cover_image" :size="20" />
          </div>
          <div class="blogadm-main">
            <div class="blogadm-title">{{ p.title }}</div>
            <div class="blogadm-meta">
              <span class="blogadm-badge" :class="p.status">{{ p.status === 'published' ? 'Published' : 'Draft' }}</span>
              <span class="blogadm-slug">{{ blogBase }}/{{ p.slug }}</span>
              <span v-if="p.published_at" class="blogadm-date">· {{ fmtPostDate(p.published_at) }}</span>
            </div>
          </div>
          <div class="blogadm-actions">
            <a v-if="p.status === 'published'" :href="`${blogBase}/${p.slug}`" target="_blank" class="blogadm-ic" title="View live"><ExternalLink :size="16" /></a>
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
          <button class="blogadm-back" @click="view = 'list'"><X :size="15" /> Close</button>
          <div class="page-title">{{ form.id ? 'Edit post' : 'New post' }} <span class="blogadm-aud">{{ isBiz ? 'For Business blog' : 'Marketplace blog' }}</span></div>
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
          <input v-model="form.title" class="blogadm-in" placeholder="How to host a 5-a-side tournament" @input="onTitle" />

          <label class="blogadm-l">Slug</label>
          <div class="blogadm-slugrow"><span>{{ blogBase }}/</span><input v-model="form.slug" class="blogadm-in" placeholder="how-to-host" @input="slugTouched = true" /></div>

          <label class="blogadm-l">Excerpt <span class="blogadm-opt">shown on cards & in search</span></label>
          <textarea v-model="form.excerpt" class="blogadm-in" rows="2" placeholder="A short summary…"></textarea>

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
          <input v-model="form.author_name" class="blogadm-in" placeholder="Bookboll" />

          <label class="blogadm-l">Tags <span class="blogadm-opt">comma-separated</span></label>
          <input v-model="tagsInput" class="blogadm-in" placeholder="padel, tips, dubai" />

          <details class="blogadm-seo">
            <summary>SEO overrides</summary>
            <label class="blogadm-l">Meta title</label>
            <input v-model="form.meta_title" class="blogadm-in" :placeholder="form.title" />
            <label class="blogadm-l">Meta description</label>
            <textarea v-model="form.meta_description" class="blogadm-in" rows="2" :placeholder="form.excerpt"></textarea>
          </details>
        </aside>
      </div>
    </template>
  </div>
</template>

<style scoped>
.page-subtitle code, .blogadm-warn code { background: #f1f5f9; padding: 1px 6px; border-radius: 5px; font-size: 12px; }
/* Marketplace / For Business publication tabs */
.blogadm-tabs { display: flex; gap: 6px; margin-bottom: 16px; background: #f1f5f9; border-radius: 12px; padding: 4px; width: fit-content; }
.blogadm-tab { display: inline-flex; align-items: center; gap: 7px; border: none; background: none; color: var(--text-muted, #64748b); font: inherit; font-weight: 800; font-size: 13.5px; padding: 8px 16px; border-radius: 9px; cursor: pointer; }
.blogadm-tab.on { background: var(--surface, #ffffff); color: #0f172a; box-shadow: 0 1px 4px rgba(15,23,42,.12); }
.blogadm-aud { font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: .04em; color: var(--primary); background: color-mix(in srgb, var(--primary) 10%, white); padding: 3px 10px; border-radius: 999px; vertical-align: middle; margin-left: 8px; }
.blogadm-head-actions { display: flex; gap: 8px; flex-wrap: wrap; }
.blogadm-back { background: none; border: none; color: var(--text-muted, #64748b); font-weight: 700; font-size: 13px; cursor: pointer; display: inline-flex; align-items: center; gap: 5px; padding: 0 0 6px; }
.btn:disabled { opacity: .6; cursor: not-allowed; }
.spin { animation: bspin 1s linear infinite; } @keyframes bspin { to { transform: rotate(360deg); } }
.blogadm-warn { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; font-size: 13.5px; padding: 11px 14px; border-radius: 10px; margin-bottom: 14px; }
.blogadm-empty { text-align: center; color: var(--text-light, #94a3b8); padding: 50px; display: flex; flex-direction: column; align-items: center; gap: 10px; }

.blogadm-list { display: flex; flex-direction: column; gap: 8px; }
.blogadm-row { display: flex; align-items: center; gap: 14px; background: var(--surface, #ffffff); border: 1px solid var(--border, #e2e8f0); border-radius: 14px; padding: 12px 14px; }
.blogadm-thumb { width: 56px; height: 44px; flex-shrink: 0; border-radius: 9px; background: #f1f5f9; background-size: cover; background-position: center; color: var(--text-light, #94a3b8); display: flex; align-items: center; justify-content: center; }
.blogadm-main { flex: 1; min-width: 0; }
.blogadm-title { font-size: 15px; font-weight: 800; color: #0f172a; }
.blogadm-meta { display: flex; align-items: center; gap: 8px; font-size: 12.5px; color: var(--text-light, #94a3b8); margin-top: 3px; flex-wrap: wrap; }
.blogadm-badge { font-size: 10.5px; font-weight: 800; text-transform: uppercase; letter-spacing: .04em; padding: 2px 8px; border-radius: 999px; }
.blogadm-badge.published { background: #dcfce7; color: #15803d; }
.blogadm-badge.draft { background: #f1f5f9; color: var(--text-muted, #64748b); }
.blogadm-slug { font-family: ui-monospace, monospace; }
.blogadm-actions { display: flex; gap: 6px; }
.blogadm-ic { width: 34px; height: 34px; border-radius: 9px; border: 1px solid var(--border, #e2e8f0); background: var(--surface, #ffffff); color: #475569; display: flex; align-items: center; justify-content: center; cursor: pointer; text-decoration: none; }
.blogadm-ic:hover { border-color: var(--primary); color: var(--primary); }
.blogadm-ic.danger:hover { border-color: #fecaca; color: #dc2626; background: #fef2f2; }

.blogadm-grid { display: grid; grid-template-columns: 1fr 300px; gap: 20px; }
.blogadm-l { display: block; font-size: 12.5px; font-weight: 800; color: #334155; margin: 14px 0 5px; }
.blogadm-l:first-child { margin-top: 0; }
.blogadm-opt { font-weight: 600; color: var(--text-light, #94a3b8); font-size: 11px; }
.blogadm-in { width: 100%; box-sizing: border-box; border: 1.5px solid var(--border, #e2e8f0); border-radius: 10px; padding: 10px 12px; font-size: 14px; color: #0f172a; font-family: inherit; }
.blogadm-in:focus { outline: none; border-color: var(--primary); }
.blogadm-body { font-family: ui-monospace, monospace; font-size: 13px; line-height: 1.6; resize: vertical; }
.blogadm-slugrow { display: flex; align-items: center; gap: 6px; }
.blogadm-slugrow span { font-size: 13px; color: var(--text-light, #94a3b8); font-family: ui-monospace, monospace; }
.blogadm-preview { margin-top: 16px; border: 1px solid var(--border, #e2e8f0); border-radius: 12px; padding: 18px; background: var(--surface, #ffffff); position: relative; }
.blogadm-preview-tag { position: absolute; top: -9px; left: 14px; background: #0f172a; color: #fff; font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: .05em; padding: 2px 8px; border-radius: 999px; }
.blogadm-side { background: var(--surface, #ffffff); border: 1px solid var(--border, #e2e8f0); border-radius: 14px; padding: 16px; height: fit-content; }
.blogadm-cover { width: 100%; height: 130px; border-radius: 10px; background: #f1f5f9; background-size: cover; background-position: center; display: flex; align-items: center; justify-content: center; color: var(--text-light, #94a3b8); }
.blogadm-upload { width: 100%; justify-content: center; margin-top: 10px; cursor: pointer; }
.blogadm-rm { width: 100%; margin-top: 6px; background: none; border: none; color: var(--text-light, #94a3b8); font-size: 12.5px; font-weight: 700; cursor: pointer; }
.blogadm-rm:hover { color: #dc2626; }
.blogadm-seo { margin-top: 14px; border-top: 1px solid #eef2f6; padding-top: 12px; }
.blogadm-seo summary { font-size: 12.5px; font-weight: 800; color: #334155; cursor: pointer; }
@media (max-width: 820px) { .blogadm-grid { grid-template-columns: 1fr; } }

/* Phone (360px-class) tuning — mobile-only, no desktop impact */
@media (max-width: 600px) {
  /* iOS anti-zoom: inputs/textareas at 16px */
  .blogadm-in { font-size: 16px; }

  /* Editor header: Preview / Save draft / Publish become a full-width row of equal tappable buttons */
  .blogadm-head-actions { width: 100%; }
  .blogadm-head-actions .btn { flex: 1; min-height: 44px; justify-content: center; }

  /* Long titles / unbroken slugs must never force sideways scroll */
  .blogadm-title, .blogadm-slug { overflow-wrap: anywhere; }

  /* List rows: bigger touch targets, slimmer thumb, allow actions to wrap under on very narrow screens */
  .blogadm-ic { width: 40px; height: 40px; }
  .blogadm-thumb { width: 44px; }
  .blogadm-row { flex-wrap: wrap; }
  .blogadm-actions { margin-left: auto; }

  /* Cover upload button reaches the 44px touch minimum */
  .blogadm-upload { min-height: 44px; }

  /* Status pill carries essential Published/Draft meaning — bump for legibility */
  .blogadm-badge { font-size: 11px; }

  /* Publication tabs: full-width, equal halves, 44px touch targets */
  .blogadm-tabs { width: 100%; box-sizing: border-box; }
  .blogadm-tab { flex: 1; justify-content: center; min-height: 44px; }
}
</style>
