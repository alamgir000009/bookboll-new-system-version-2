<script setup>
/**
 * Tracking.vue — "My Website → Tracking": connect analytics & marketing pixels.
 *
 * Dual-mode (mirrors Brand.vue):
 *   • PROVIDER      → saves tracking_* columns on the owner's admin_users row. Fires ONLY on
 *                     that provider's white-label storefront (<slug>.bookboll.com).
 *   • SUPER ADMIN   → saves tracking_* keys in platform_settings. Fires ONLY on the main
 *                     Bookboll marketplace (apex bookboll.com).
 *
 * The scoping is enforced by lib/tracking.js, which injects the ACTIVE brand's tags only
 * (provider's on their site, platform's on the apex) — so a provider only ever receives data
 * from their own site, never the marketplace or another business's site.
 *
 * All writes are best-effort: if the tracking columns aren't migrated yet, we tell the user
 * to run the SQL rather than failing silently.
 */
import { ref, reactive, computed, onMounted, watch } from 'vue'
import { supabase } from '../lib/supabase.js'
import { useCurrentUser } from '../lib/currentUser.js'
import { useToast } from '../lib/toast.js'
import { markDirty, clearDirty } from '../lib/unsavedGuard.js'
import { logAudit, AUDIT } from '../lib/audit.js'
import { providerSlug, brandPreviewUrl, marketplaceUrl, BRAND_APEX } from '../lib/brand.js'
import { isGa4, isGtm, isMetaPix } from '../lib/tracking.js'
import {
  LineChart, Check, Loader, ExternalLink, Info, ShieldCheck, Code2, AlertTriangle, Globe2, Store,
} from 'lucide-vue-next'

const { currentOwnerUserId, isSuperAdmin, role: actorRole } = useCurrentUser()
const toast = useToast()

const KEYS = ['tracking_meta_pixel', 'tracking_ga4', 'tracking_gtm', 'tracking_head']
const ownerId = computed(() => currentOwnerUserId.value)
// Editing the MAIN Bookboll marketplace (apex, platform_settings) is HQ-only (Super Admin +
// Platform Staff with no provider context) — same rule as Brand.vue's marketplace editor.
const isPlatformMode = computed(() => (isSuperAdmin.value || actorRole.value === 'platform_staff') && !ownerId.value)

const loading = ref(true)
const saving = ref(false)
const needsMigration = ref(false)
const ownerSlug = ref('')

const f = reactive({ meta_pixel: '', ga4: '', gtm: '', head: '' })

// ── Per-field validity (empty = fine; filled must be well-formed) ──────────────
const metaState = computed(() => !f.meta_pixel.trim() ? '' : (isMetaPix(f.meta_pixel) ? 'ok' : 'bad'))
const ga4State  = computed(() => !f.ga4.trim() ? '' : (isGa4(f.ga4) ? 'ok' : 'bad'))
const gtmState  = computed(() => !f.gtm.trim() ? '' : (isGtm(f.gtm) ? 'ok' : 'bad'))
const hasInvalid = computed(() => [metaState.value, ga4State.value, gtmState.value].includes('bad'))
const canSave = computed(() => !saving.value && !loading.value && !hasInvalid.value)

// What will actually fire, for the summary strip.
const active = computed(() => {
  const out = []
  if (metaState.value === 'ok') out.push('Meta Pixel')
  if (ga4State.value === 'ok') out.push('Google Analytics 4')
  if (gtmState.value === 'ok') out.push('Google Tag Manager')
  if (f.head.trim()) out.push('Custom code')
  return out
})

// Where these tags fire (a clickable test link).
const fireUrl = computed(() => isPlatformMode.value
  ? marketplaceUrl('/')
  : (ownerSlug.value ? brandPreviewUrl(ownerSlug.value) : ''))
const fireLabel = computed(() => isPlatformMode.value
  ? BRAND_APEX
  : (ownerSlug.value ? `${ownerSlug.value}.${BRAND_APEX}` : 'your storefront'))

async function load() {
  loading.value = true
  needsMigration.value = false
  if (isPlatformMode.value) {
    const { data } = await supabase.from('platform_settings').select('key, value').in('key', KEYS)
    const m = {}
    for (const r of (data || [])) m[r.key] = r.value
    f.meta_pixel = m.tracking_meta_pixel || ''
    f.ga4 = m.tracking_ga4 || ''
    f.gtm = m.tracking_gtm || ''
    f.head = m.tracking_head || ''
  } else if (ownerId.value) {
    // Owner slug (columns that always exist) — for the "where it fires" link.
    const base = await supabase.from('admin_users').select('id, brand_subdomain, username, business_name, brand_name').eq('id', ownerId.value).maybeSingle()
    if (base.data) ownerSlug.value = providerSlug(base.data)
    // Tracking values (best-effort — columns may not be migrated yet).
    const trk = await supabase.from('admin_users').select('tracking_meta_pixel, tracking_ga4, tracking_gtm, tracking_head').eq('id', ownerId.value).maybeSingle()
    if (!trk.error && trk.data) {
      f.meta_pixel = trk.data.tracking_meta_pixel || ''
      f.ga4 = trk.data.tracking_ga4 || ''
      f.gtm = trk.data.tracking_gtm || ''
      f.head = trk.data.tracking_head || ''
    } else if (trk.error && /column|schema|tracking_/i.test(trk.error.message || '')) {
      needsMigration.value = true   // surface the missing migration immediately, not only on save
    }
  }
  loading.value = false
  clearDirty()
}
onMounted(load)
// Reload when the acting context changes (e.g. an owner is resolved, or the demo role switch
// flips between provider and platform mode) so the form always shows the right saved values.
watch([isPlatformMode, ownerId], load)

async function save() {
  if (!canSave.value) return
  saving.value = true
  needsMigration.value = false
  try {
    if (isPlatformMode.value) {
      const now = new Date().toISOString()
      const rows = [
        { key: 'tracking_meta_pixel', value: f.meta_pixel.trim() },
        { key: 'tracking_ga4', value: f.ga4.trim() },
        { key: 'tracking_gtm', value: f.gtm.trim() },
        { key: 'tracking_head', value: f.head },
      ].map(r => ({ ...r, updated_at: now }))
      const { error } = await supabase.from('platform_settings').upsert(rows, { onConflict: 'key' })
      if (error) throw error
      logAudit({ action: AUDIT.UPDATED, entity_type: 'platform_tracking', entity_id: 'marketplace', details: `Updated marketplace tracking — ${active.value.join(', ') || 'cleared'}` })
      toast.success('Marketplace tracking saved.')
    } else {
      const patch = {
        tracking_meta_pixel: f.meta_pixel.trim(),
        tracking_ga4: f.ga4.trim(),
        tracking_gtm: f.gtm.trim(),
        tracking_head: f.head,
      }
      // .select() so we can confirm a row actually matched — an .update().eq() with no match
      // returns no error, which would otherwise show a false "saved" and silently lose the config.
      const { data, error } = await supabase.from('admin_users').update(patch).eq('id', ownerId.value).select('id')
      if (error) throw error
      if (!data || !data.length) throw new Error('Your account could not be found — tracking was not saved.')
      logAudit({ action: AUDIT.UPDATED, entity_type: 'tracking', entity_id: ownerId.value, details: `Updated storefront tracking — ${active.value.join(', ') || 'cleared'}` })
      toast.success('Tracking saved. It will fire on your storefront.')
    }
    clearDirty()
  } catch (e) {
    const msg = e?.message || String(e)
    if (/column|schema|tracking_/i.test(msg)) {
      needsMigration.value = true
      toast.error('Run the tracking migration first (20260716000000_tracking_analytics.sql).')
    } else {
      toast.error(msg || 'Could not save.')
    }
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="page" @input="markDirty">
    <div class="page-header">
      <div>
        <div class="page-title">Tracking</div>
        <div class="page-subtitle">Connect Meta, Google Tag Manager &amp; Google Analytics to your website</div>
      </div>
    </div>

    <!-- Scope callout — makes the data-isolation guarantee explicit -->
    <div class="trk-scope" :class="isPlatformMode ? 'plat' : 'prov'">
      <ShieldCheck :size="20" />
      <div v-if="isPlatformMode">
        <b>Marketplace tracking.</b> These tags fire on the main Bookboll marketplace
        (<code>{{ BRAND_APEX }}</code>) only — never on any provider's own website.
      </div>
      <div v-else>
        <b>Your storefront only.</b> These tags fire on <b>your</b> website
        (<code>{{ fireLabel }}</code>) only. They never run on the Bookboll marketplace or any
        other business's site — so you only ever receive <b>your</b> traffic &amp; conversions.
      </div>
    </div>

    <div class="card trk-card">
      <!-- Meta Pixel -->
      <div class="trk-field">
        <div class="trk-field-head">
          <label class="trk-label">Meta Pixel ID</label>
          <span v-if="metaState==='ok'" class="trk-chip ok"><Check :size="12" /> Valid</span>
          <span v-else-if="metaState==='bad'" class="trk-chip bad"><AlertTriangle :size="12" /> Numbers only</span>
        </div>
        <input v-model="f.meta_pixel" class="trk-input" :class="{ bad: metaState==='bad' }" placeholder="e.g. 1234567890123456" inputmode="numeric" autocomplete="off" spellcheck="false" />
        <p class="trk-hint">Meta Events Manager → Data sources → your pixel → <b>Settings</b>. A 15–16 digit number.</p>
      </div>

      <!-- GA4 -->
      <div class="trk-field">
        <div class="trk-field-head">
          <label class="trk-label">Google Analytics 4 — Measurement ID</label>
          <span v-if="ga4State==='ok'" class="trk-chip ok"><Check :size="12" /> Valid</span>
          <span v-else-if="ga4State==='bad'" class="trk-chip bad"><AlertTriangle :size="12" /> Format G-XXXXXXX</span>
        </div>
        <input v-model="f.ga4" class="trk-input" :class="{ bad: ga4State==='bad' }" placeholder="G-XXXXXXXXXX" autocomplete="off" spellcheck="false" />
        <p class="trk-hint">Google Analytics → Admin → Data streams → your web stream → <b>Measurement ID</b>.</p>
      </div>

      <!-- GTM -->
      <div class="trk-field">
        <div class="trk-field-head">
          <label class="trk-label">Google Tag Manager — Container ID</label>
          <span v-if="gtmState==='ok'" class="trk-chip ok"><Check :size="12" /> Valid</span>
          <span v-else-if="gtmState==='bad'" class="trk-chip bad"><AlertTriangle :size="12" /> Format GTM-XXXXXX</span>
        </div>
        <input v-model="f.gtm" class="trk-input" :class="{ bad: gtmState==='bad' }" placeholder="GTM-XXXXXXX" autocomplete="off" spellcheck="false" />
        <p class="trk-hint">Tag Manager → your container → the <b>GTM-XXXXXXX</b> ID at the top.</p>
      </div>

      <!-- Advanced: raw head code -->
      <details class="trk-adv">
        <summary><Code2 :size="15" /> Advanced — custom &lt;head&gt; code</summary>
        <div class="trk-adv-body">
          <p class="trk-hint">Paste any extra tags/scripts (TikTok pixel, LinkedIn Insight, verification meta, etc.). Added to the <code>&lt;head&gt;</code> of {{ isPlatformMode ? 'the marketplace' : 'your storefront' }} only.</p>
          <textarea v-model="f.head" class="trk-textarea" rows="6" spellcheck="false" placeholder="&lt;!-- e.g. --&gt;&#10;&lt;script&gt; /* your snippet */ &lt;/script&gt;"></textarea>
          <div class="trk-warn"><AlertTriangle :size="14" /> Only paste code from sources you trust — it runs on your live site.</div>
        </div>
      </details>

      <!-- Live summary of what fires -->
      <div class="trk-summary">
        <div class="trk-summary-h"><LineChart :size="15" /> What's live</div>
        <div v-if="active.length" class="trk-tags">
          <span v-for="a in active" :key="a" class="trk-tag"><Check :size="12" /> {{ a }}</span>
        </div>
        <div v-else class="trk-none">Nothing connected yet — add an ID above to start measuring traffic.</div>
        <p class="trk-summary-note"><Info :size="13" /> Page views are tracked automatically on every screen, including in-app navigation.</p>
      </div>

      <div v-if="needsMigration" class="trk-migrate">
        <AlertTriangle :size="15" /> The tracking columns aren't in your database yet. Run
        <code>supabase/migrations/20260716000000_tracking_analytics.sql</code>, then save again.
      </div>

      <div class="trk-actions">
        <a v-if="fireUrl" :href="fireUrl" target="_blank" rel="noopener" class="trk-open">
          <component :is="isPlatformMode ? Store : Globe2" :size="15" />
          {{ isPlatformMode ? 'Open marketplace' : 'Open my storefront' }} <ExternalLink :size="13" />
        </a>
        <button class="btn btn-primary trk-save" :disabled="!canSave" @click="save">
          <Loader v-if="saving" :size="16" class="trk-spin" /><Check v-else :size="16" />
          {{ saving ? 'Saving…' : 'Save tracking' }}
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.trk-scope { display: flex; align-items: flex-start; gap: 11px; border-radius: 14px; padding: 14px 16px; font-size: 13.5px; line-height: 1.55; margin-bottom: 16px; }
.trk-scope svg { flex-shrink: 0; margin-top: 1px; }
.trk-scope.prov { background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; }
.trk-scope.prov svg { color: #059669; }
.trk-scope.plat { background: #eff6ff; border: 1px solid #bfdbfe; color: #1e40af; }
.trk-scope.plat svg { color: #2563eb; }
.trk-scope code { background: rgba(15,23,42,.07); padding: 1px 6px; border-radius: 5px; font-size: 12.5px; font-weight: 700; }

.trk-card { display: flex; flex-direction: column; gap: 22px; max-width: 680px; padding: 22px 24px; }
@media (max-width: 560px) { .trk-card { padding: 16px; } }
.trk-field { display: flex; flex-direction: column; gap: 6px; }
.trk-field-head { display: flex; align-items: center; gap: 10px; }
.trk-label { font-size: 13.5px; font-weight: 700; color: var(--text, #0f172a); }
.trk-chip { display: inline-flex; align-items: center; gap: 4px; font-size: 11px; font-weight: 800; padding: 2px 8px; border-radius: 999px; }
.trk-chip.ok { color: #15803d; background: #f0fdf4; border: 1px solid #bbf7d0; }
.trk-chip.bad { color: #b45309; background: #fffbeb; border: 1px solid #fde68a; }
.trk-input { width: 100%; box-sizing: border-box; border: 1.5px solid var(--border, #e2e8f0); border-radius: 10px; padding: 11px 13px; font-size: 15px; font-family: inherit; outline: none; background: var(--card-bg, #fff); color: var(--text, #0f172a); }
.trk-input:focus { border-color: var(--primary, #0f766e); }
.trk-input.bad { border-color: #f59e0b; }
.trk-hint { font-size: 12px; color: var(--text-muted, #94a3b8); line-height: 1.5; margin: 0; }
.trk-hint code { background: rgba(15,23,42,.06); padding: 0 4px; border-radius: 4px; }

.trk-adv { border: 1px solid var(--border, #e2e8f0); border-radius: 12px; padding: 4px 14px; }
.trk-adv summary { cursor: pointer; font-size: 13.5px; font-weight: 700; color: var(--text, #334155); display: flex; align-items: center; gap: 8px; padding: 10px 0; list-style: none; }
.trk-adv summary::-webkit-details-marker { display: none; }
.trk-adv-body { padding: 4px 0 12px; display: flex; flex-direction: column; gap: 8px; }
.trk-textarea { width: 100%; box-sizing: border-box; border: 1.5px solid var(--border, #e2e8f0); border-radius: 10px; padding: 11px 13px; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12.5px; line-height: 1.5; resize: vertical; outline: none; background: var(--card-bg, #fff); color: var(--text, #0f172a); }
.trk-textarea:focus { border-color: var(--primary, #0f766e); }
.trk-warn { display: flex; align-items: center; gap: 6px; font-size: 11.5px; font-weight: 600; color: #b45309; }

.trk-summary { background: var(--bg, #f8fafc); border: 1px solid var(--border, #e2e8f0); border-radius: 12px; padding: 14px 16px; }
.trk-summary-h { display: flex; align-items: center; gap: 7px; font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: .05em; color: var(--text-muted, #64748b); margin-bottom: 10px; }
.trk-tags { display: flex; flex-wrap: wrap; gap: 8px; }
.trk-tag { display: inline-flex; align-items: center; gap: 5px; font-size: 12.5px; font-weight: 700; color: #0f766e; background: #f0fdfa; border: 1px solid #99f6e4; border-radius: 999px; padding: 4px 11px; }
.trk-none { font-size: 13px; color: var(--text-muted, #94a3b8); }
.trk-summary-note { display: flex; align-items: center; gap: 6px; font-size: 11.5px; color: var(--text-muted, #94a3b8); margin: 10px 0 0; }

.trk-migrate { display: flex; align-items: flex-start; gap: 8px; font-size: 12.5px; line-height: 1.5; color: #92400e; background: #fffbeb; border: 1px solid #fde68a; border-radius: 10px; padding: 11px 13px; }
.trk-migrate code { background: rgba(146,64,14,.1); padding: 1px 5px; border-radius: 4px; font-size: 11.5px; }

.trk-actions { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
.trk-open { display: inline-flex; align-items: center; gap: 6px; font-size: 13.5px; font-weight: 700; color: var(--primary, #0f766e); text-decoration: none; }
.trk-open:hover { text-decoration: underline; }
.trk-save { display: inline-flex; align-items: center; gap: 8px; }
.trk-spin { animation: trkspin 1s linear infinite; }
@keyframes trkspin { to { transform: rotate(360deg); } }

@media (max-width: 560px) {
  .trk-actions { flex-direction: column-reverse; align-items: stretch; }
  .trk-save { justify-content: center; min-height: 44px; }
  .trk-open { justify-content: center; min-height: 44px; align-items: center; }
  /* 360px: long unbroken strings (migration path, {slug}.bookboll.com) must wrap, not overflow */
  .trk-migrate code, .trk-scope code, .trk-hint code { word-break: break-all; overflow-wrap: anywhere; white-space: normal; }
  .trk-scope, .trk-migrate { min-width: 0; }
  .trk-scope > div { min-width: 0; }
  /* iOS auto-zoom: keep form fields at 16px on phones */
  .trk-input, .trk-textarea { font-size: 16px; }
  /* Let the validity chip wrap under long labels on very narrow screens */
  .trk-field-head { flex-wrap: wrap; row-gap: 4px; }
}
</style>
