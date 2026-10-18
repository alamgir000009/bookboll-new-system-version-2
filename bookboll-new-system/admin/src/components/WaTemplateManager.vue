<script setup>
/**
 * WaTemplateManager — author, submit and track WhatsApp message templates from the
 * dashboard (no Meta Business Manager round-trips), plus the queued-campaign board.
 *
 * Mounted on the /whatsapp page for BOTH the super admin (platform number) and
 * providers (their own number) — it resolves the active business itself via
 * useProviderWhatsapp. Flow: build template → "Submit for review" → shows PENDING →
 * "Check for updates" (also runs automatically on open) → APPROVED (usable in the
 * Customers composer; any queued campaign auto-sends) or REJECTED (reason shown).
 */
import { ref, computed, onMounted } from 'vue'
import {
  useProviderWhatsapp, listWaTemplates, createWaTemplate, deleteWaTemplate,
  slugifyTemplateName, renderTemplateBody, processWaQueue, loadWaQueue,
  dismissWaQueueItem, cancelWaQueueItem,
} from '../lib/whatsapp.js'
import { pushToast } from '../lib/toast.js'
import { askConfirm } from '../lib/confirm.js'
import { FileText, Plus, RefreshCw, Trash2, Send, CheckCircle2, Clock3, XCircle, Loader2, Megaphone, X } from 'lucide-vue-next'

const { wa, load: loadWa, configured, ownerId } = useProviderWhatsapp()

const templates = ref([])
const tplState = ref('')          // '' | 'loading' | 'ready' | 'error'
const tplError = ref('')
const queue = ref([])
const checking = ref(false)

const LANGS = [
  { code: 'en_US', label: 'English (US)' }, { code: 'en_GB', label: 'English (UK)' },
  { code: 'ar', label: 'Arabic' }, { code: 'fr', label: 'French' }, { code: 'es', label: 'Spanish' },
  { code: 'de', label: 'German' }, { code: 'hi', label: 'Hindi' }, { code: 'ur', label: 'Urdu' },
]

// ── Builder ──
const showBuilder = ref(false)
const submitting = ref(false)
const form = ref({ name: '', language: 'en_US', category: 'MARKETING', headerText: '', bodyText: '', footerText: '' })
const cleanName = computed(() => slugifyTemplateName(form.value.name))
const varCount = computed(() => {
  let n = 0
  for (const m of String(form.value.bodyText).matchAll(/\{\{(\d+)\}\}/g)) n = Math.max(n, Number(m[1]))
  return n
})
const examples = ref([])
function syncExamples() { examples.value = Array.from({ length: varCount.value }, (_, i) => examples.value[i] || '') }
function insertVar() {
  form.value.bodyText += `{{${varCount.value + 1}}}`
  syncExamples()
}
const previewBody = computed(() => renderTemplateBody(form.value.bodyText, examples.value.map((e, i) => e || `Sample ${i + 1}`)))

async function refresh({ silent } = {}) {
  if (!configured.value) { tplState.value = ''; return }
  if (!silent) tplState.value = 'loading'
  const res = await listWaTemplates(ownerId.value)
  if (res.ok) {
    templates.value = res.templates || []
    tplState.value = 'ready'
    tplError.value = ''
  } else {
    tplState.value = 'error'
    tplError.value = res.error || 'Could not load templates.'
  }
  queue.value = await loadWaQueue()
}

/** Check statuses AND run the auto-send pump (queued campaigns fire on approval). */
async function checkNow() {
  checking.value = true
  const pump = await processWaQueue()
  for (const s of pump.sent || []) {
    const r = s.result || {}
    pushToast(r.error
      ? `Queued campaign "${s.template_name}" failed to send: ${r.error}`
      : `Template "${s.template_name}" was APPROVED — queued campaign auto-sent to ${r.sent} customer${r.sent === 1 ? '' : 's'}.`,
      r.error ? 'error' : 'success')
  }
  for (const r of pump.rejected || []) {
    pushToast(`Template "${r.template_name}" was REJECTED: ${r.reject_reason}. The queued campaign was NOT sent.`, 'error')
  }
  await refresh({ silent: true })
  checking.value = false
}

async function submitTemplate() {
  syncExamples()
  if (!cleanName.value || !form.value.bodyText.trim()) { pushToast('Give the template a name and body text.', 'error'); return }
  submitting.value = true
  const res = await createWaTemplate(ownerId.value, {
    name: cleanName.value, language: form.value.language, category: form.value.category,
    headerText: form.value.headerText, bodyText: form.value.bodyText, footerText: form.value.footerText,
    examples: examples.value,
  })
  submitting.value = false
  if (!res.ok) { pushToast(res.error || 'Meta rejected the submission.', 'error'); return }
  pushToast(`Template "${cleanName.value}" submitted — Meta usually reviews within minutes to a few hours.`, 'success')
  showBuilder.value = false
  form.value = { name: '', language: 'en_US', category: 'MARKETING', headerText: '', bodyText: '', footerText: '' }
  examples.value = []
  refresh({ silent: true })
}

async function removeTemplate(t) {
  const ok = await askConfirm({
    title: `Delete template "${t.name}"?`,
    message: 'This removes it from your WhatsApp Business Account. Campaigns queued on it will never send.',
    confirmText: 'Delete', cancelText: 'Keep',
  })
  if (!ok) return
  const res = await deleteWaTemplate(ownerId.value, t.name)
  if (!res.ok) { pushToast(res.error || 'Could not delete.', 'error'); return }
  pushToast(`Template "${t.name}" deleted.`, 'success')
  refresh({ silent: true })
}

async function dismiss(row) { await dismissWaQueueItem(row.id); queue.value = queue.value.filter(q => q.id !== row.id) }
async function cancelQueued(row) {
  const ok = await askConfirm({ title: 'Cancel this queued campaign?', message: 'It will not send when the template is approved.', confirmText: 'Cancel campaign', cancelText: 'Keep queued' })
  if (!ok) return
  await cancelWaQueueItem(row.id)
  refresh({ silent: true })
}

const STATUS_META = {
  APPROVED: { icon: CheckCircle2, cls: 'ok', label: 'Approved' },
  PENDING: { icon: Clock3, cls: 'wait', label: 'In review' },
  REJECTED: { icon: XCircle, cls: 'bad', label: 'Rejected' },
}
const statusMeta = (s) => STATUS_META[s] || { icon: Clock3, cls: 'wait', label: s }
const fmtT = (ts) => ts ? new Date(ts).toLocaleString('en-US', { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit', hour12: true }) : ''

onMounted(async () => { await loadWa(); await refresh(); if (configured.value) checkNow() })
</script>

<template>
  <div v-if="configured" class="card wtm">
    <div class="wtm-head">
      <div class="wtm-title"><FileText :size="17" /> Message templates
        <span class="wtm-sub">Create marketing templates here and submit them to Meta — no Business Manager needed.</span>
      </div>
      <div class="wtm-actions">
        <button type="button" class="btn btn-sm" :disabled="checking" @click="checkNow">
          <Loader2 v-if="checking" :size="14" class="wtm-spin" /><RefreshCw v-else :size="14" /> Check for updates
        </button>
        <button type="button" class="btn btn-primary btn-sm" @click="showBuilder = !showBuilder"><Plus :size="14" /> New template</button>
      </div>
    </div>

    <!-- Builder -->
    <div v-if="showBuilder" class="wtm-builder">
      <div class="wtm-grid">
        <div>
          <label class="form-label">Template name</label>
          <input v-model="form.name" placeholder="e.g. Weekend padel offer" />
          <div v-if="cleanName" class="wtm-hint">Submitted as <code data-no-translate>{{ cleanName }}</code></div>
        </div>
        <div>
          <label class="form-label">Language</label>
          <select v-model="form.language"><option v-for="l in LANGS" :key="l.code" :value="l.code">{{ l.label }}</option></select>
        </div>
        <div>
          <label class="form-label">Category</label>
          <select v-model="form.category">
            <option value="MARKETING">Marketing (offers & news)</option>
            <option value="UTILITY">Utility (account/booking updates)</option>
          </select>
        </div>
      </div>
      <div class="wtm-field">
        <label class="form-label">Header <span class="wtm-opt">optional</span></label>
        <input v-model="form.headerText" maxlength="60" placeholder="Short headline (no variables)" />
      </div>
      <div class="wtm-field">
        <label class="form-label">Body</label>
        <textarea v-model="form.bodyText" rows="4" maxlength="1024" @input="syncExamples"
          placeholder="Hi {{1}}! This weekend only: 20% off all padel bookings…"></textarea>
        <div class="wtm-body-tools">
          <button type="button" class="btn btn-sm" @click="insertVar"><Plus :size="13" /> Add variable <span data-no-translate v-text="'{{' + (varCount + 1) + '}}'"></span></button>
          <span class="wtm-hint">Variables <span data-no-translate v-text="'{{1}}…{{n}}'"></span> are filled per customer when you send.</span>
        </div>
      </div>
      <div v-if="varCount" class="wtm-field">
        <label class="form-label">Example values <span class="wtm-opt">Meta requires a sample for each variable</span></label>
        <div class="wtm-examples">
          <input v-for="(e, i) in examples" :key="i" v-model="examples[i]" :placeholder="`Example for {{${i + 1}}}`" />
        </div>
      </div>
      <div class="wtm-field">
        <label class="form-label">Footer <span class="wtm-opt">optional</span></label>
        <input v-model="form.footerText" maxlength="60" placeholder="e.g. Reply STOP to unsubscribe" />
      </div>
      <div v-if="form.bodyText" class="wtm-preview">
        <div class="wtm-preview-h">Preview</div>
        <div class="wtm-bubble">
          <div v-if="form.headerText" class="wtm-bubble-head">{{ form.headerText }}</div>
          <div class="wtm-bubble-body">{{ previewBody }}</div>
          <div v-if="form.footerText" class="wtm-bubble-foot">{{ form.footerText }}</div>
        </div>
      </div>
      <div class="wtm-submit">
        <button type="button" class="btn btn-primary" :disabled="submitting || !cleanName || !form.bodyText.trim()" @click="submitTemplate">
          <Loader2 v-if="submitting" :size="15" class="wtm-spin" /><Send v-else :size="15" /> Submit for Meta review
        </button>
        <span class="wtm-hint">Review usually takes minutes to a few hours. Approved templates appear in the Customers marketing composer automatically.</span>
      </div>
    </div>

    <!-- Template list -->
    <div v-if="tplState === 'loading'" class="wtm-empty"><Loader2 :size="15" class="wtm-spin" /> Loading templates…</div>
    <div v-else-if="tplState === 'error'" class="wtm-empty">{{ tplError }}</div>
    <div v-else-if="tplState === 'ready' && !templates.length" class="wtm-empty">No templates yet — create your first one above.</div>
    <div v-else-if="templates.length" class="wtm-list">
      <div v-for="t in templates" :key="t.name + t.language" class="wtm-row">
        <div class="wtm-row-main">
          <span class="wtm-name" data-no-translate>{{ t.name }}</span>
          <span class="wtm-chip" :class="statusMeta(t.status).cls"><component :is="statusMeta(t.status).icon" :size="12" /> {{ statusMeta(t.status).label }}</span>
          <span class="wtm-meta">{{ t.category }} · {{ t.language }}<template v-if="t.varCount"> · {{ t.varCount }} var{{ t.varCount === 1 ? '' : 's' }}</template></span>
        </div>
        <div v-if="t.bodyText" class="wtm-row-body">{{ t.bodyText }}</div>
        <div v-if="t.status === 'REJECTED'" class="wtm-reject">Rejected by Meta{{ t.rejectedReason ? ': ' + t.rejectedReason : '' }} — fix the content and submit again under a new name.</div>
        <button type="button" class="wtm-del" :aria-label="`Delete template ${t.name}`" @click="removeTemplate(t)"><Trash2 :size="14" /></button>
      </div>
    </div>

    <!-- Queued campaigns -->
    <template v-if="queue.length">
      <div class="wtm-qh"><Megaphone :size="15" /> Queued & recent campaigns</div>
      <div class="wtm-list">
        <div v-for="q in queue" :key="q.id" class="wtm-row" :class="'q-' + q.status">
          <div class="wtm-row-main">
            <span class="wtm-name" data-no-translate>{{ q.template_name }}</span>
            <span v-if="q.status === 'awaiting_approval'" class="wtm-chip wait"><Clock3 :size="12" /> Sends on approval</span>
            <span v-else-if="q.status === 'sent'" class="wtm-chip ok"><CheckCircle2 :size="12" /> Auto-sent {{ fmtT(q.sent_at) }}</span>
            <span v-else-if="q.status === 'rejected'" class="wtm-chip bad"><XCircle :size="12" /> Template rejected</span>
            <span v-else class="wtm-chip">Cancelled</span>
            <span class="wtm-meta">{{ (q.payload?.recipients || []).length }} recipient{{ (q.payload?.recipients || []).length === 1 ? '' : 's' }} · queued {{ fmtT(q.created_at) }}</span>
          </div>
          <div v-if="q.status === 'sent' && q.result" class="wtm-row-body">{{ q.result.sent }} sent{{ q.result.failed ? `, ${q.result.failed} failed` : '' }}{{ q.result.error ? ' — ' + q.result.error : '' }}</div>
          <div v-if="q.status === 'rejected'" class="wtm-reject">{{ q.reject_reason }} — this campaign was NOT sent.</div>
          <button v-if="q.status === 'awaiting_approval'" type="button" class="wtm-del" aria-label="Cancel queued campaign" @click="cancelQueued(q)"><X :size="14" /></button>
          <button v-else type="button" class="wtm-del" aria-label="Dismiss" @click="dismiss(q)"><X :size="14" /></button>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
.wtm { padding: 18px; margin-top: 16px; }
.wtm-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-bottom: 12px; }
.wtm-title { display: flex; align-items: center; gap: 8px; font-size: 15px; font-weight: 800; color: var(--text, #0f172a); flex-wrap: wrap; }
.wtm-sub { font-size: 12.5px; font-weight: 500; color: var(--text-muted, #64748b); flex-basis: 100%; }
.wtm-actions { display: flex; gap: 8px; flex-shrink: 0; }
.wtm-spin { animation: wtmspin 1s linear infinite; } @keyframes wtmspin { to { transform: rotate(360deg); } }

.wtm-builder { border: 1px solid var(--border); border-radius: 12px; padding: 14px; margin-bottom: 14px; background: var(--surface-hover, #f8fafc); }
.wtm-grid { display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 10px; }
.wtm-field { margin-top: 10px; }
.wtm-opt { font-size: 11px; font-weight: 600; color: var(--text-light, #94a3b8); }
.wtm-builder input, .wtm-builder select, .wtm-builder textarea { width: 100%; border: 1px solid var(--border); border-radius: 9px; padding: 9px 11px; font-size: 13.5px; background: var(--surface, #ffffff); }
.wtm-hint { font-size: 12px; color: var(--text-muted, #64748b); margin-top: 5px; }
.wtm-hint code { background: #eef2f6; padding: 1px 6px; border-radius: 6px; }
.wtm-body-tools { display: flex; align-items: center; gap: 10px; margin-top: 7px; flex-wrap: wrap; }
.wtm-examples { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 8px; }
.wtm-preview { margin-top: 12px; }
.wtm-preview-h { font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: .05em; color: var(--text-light, #94a3b8); margin-bottom: 6px; }
.wtm-bubble { background: #dcf8c6; border-radius: 12px 12px 12px 3px; padding: 10px 12px; max-width: 420px; font-size: 13.5px; color: var(--text, #0f172a); }
.wtm-bubble-head { font-weight: 800; margin-bottom: 3px; }
.wtm-bubble-body { white-space: pre-wrap; line-height: 1.45; }
.wtm-bubble-foot { font-size: 11.5px; color: var(--text-muted, #64748b); margin-top: 5px; }
.wtm-submit { display: flex; align-items: center; gap: 12px; margin-top: 12px; flex-wrap: wrap; }

.wtm-empty { display: flex; align-items: center; gap: 8px; color: var(--text-light, #94a3b8); font-size: 13.5px; padding: 12px 0; }
.wtm-list { display: flex; flex-direction: column; gap: 8px; }
.wtm-row { position: relative; border: 1px solid var(--border); border-radius: 10px; padding: 10px 40px 10px 12px; }
.wtm-row-main { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.wtm-name { font-weight: 800; font-size: 13.5px; color: var(--text, #0f172a); }
.wtm-chip { display: inline-flex; align-items: center; gap: 4px; font-size: 11px; font-weight: 800; padding: 3px 9px; border-radius: 999px; background: var(--bg, #f1f5f9); color: var(--text-secondary, #475569); }
.wtm-chip.ok { background: var(--positive-bg, #f0fdf4); color: var(--positive-ink, #15803d); }
.wtm-chip.wait { background: var(--warning-bg, #fef3c7); color: var(--warning-ink, #92400e); }
.wtm-chip.bad { background: var(--danger-bg, #fef2f2); color: var(--danger-ink, #b91c1c); }
.wtm-meta { font-size: 12px; color: var(--text-light, #94a3b8); }
.wtm-row-body { font-size: 12.5px; color: var(--text-secondary, #475569); margin-top: 5px; line-height: 1.45; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.wtm-reject { font-size: 12.5px; font-weight: 600; color: var(--danger-ink, #b91c1c); background: var(--danger-bg, #fef2f2); border-radius: 8px; padding: 7px 10px; margin-top: 7px; }
.wtm-del { position: absolute; top: 9px; inset-inline-end: 9px; width: 28px; height: 28px; border-radius: 8px; border: none; background: var(--bg, #f1f5f9); color: var(--text-muted, #64748b); display: flex; align-items: center; justify-content: center; cursor: pointer; }
.wtm-del:hover { background: var(--danger-bg, #fee2e2); color: var(--danger-ink, #dc2626); }
.wtm-qh { display: flex; align-items: center; gap: 7px; font-size: 13.5px; font-weight: 800; color: var(--text, #0f172a); margin: 16px 0 8px; }

@media (max-width: 700px) {
  .wtm-grid { grid-template-columns: 1fr; }
  .wtm-builder input, .wtm-builder select, .wtm-builder textarea { font-size: 16px; }
  /* 360px: long template slugs must break instead of pushing past the row edge */
  .wtm-name, .wtm-hint code { word-break: break-word; overflow-wrap: anywhere; }
  .wtm-row-main > * { min-width: 0; }
  /* 360px: header buttons go full-width + comfortable tap targets */
  .wtm-actions { width: 100%; }
  .wtm-actions .btn { flex: 1; min-height: 44px; }
  .wtm-submit .btn, .wtm-body-tools .btn { min-height: 44px; }
  /* 360px: bigger delete/dismiss tap target */
  .wtm-del { width: 40px; height: 40px; top: 6px; inset-inline-end: 6px; }
  .wtm-row { padding-inline-end: 50px; }
  /* 360px: reclaim horizontal space */
  .wtm { padding: 14px; }
  .wtm-builder { padding: 12px; }
}
</style>
