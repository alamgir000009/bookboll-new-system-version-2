/**
 * whatsapp.js — dashboard side of the WhatsApp channel.
 *
 * PER-PROVIDER, mirroring emailSender: each provider connects THEIR OWN WhatsApp Business
 * number + token (stored on admin_users.whatsapp_config) and markets their own customers
 * from it. Bookboll (super admin / no owner) uses its OWN number — stored in
 * platform_settings key 'whatsapp_config' with the token as a function secret — for its
 * marketing and the future menu-driven booking funnel.
 *
 * The browser NEVER talks to Meta directly (server-to-server + token secrecy). Everything
 * goes through the `send-whatsapp` Edge Function, which resolves the right number+token per
 * call from the `ownerId` we pass (provider row → provider's config; no ownerId → platform).
 *
 * WhatsApp rules that shape the UI:
 *  - Marketing/bulk messages MUST use Meta-approved templates (no free-form blasts).
 *  - Free-form text only inside the 24-hour window after the customer last messaged.
 */
import { ref, computed } from 'vue'
import { supabase } from './supabase.js'
import { useCurrentUser } from './currentUser.js'
import { recordCampaign } from './marketing.js'   // compliance ledger for auto-sent queued campaigns

export const WHATSAPP_CONFIG_KEY = 'whatsapp_config'   // platform_settings key (Bookboll's number)

export function blankWhatsappConfig() {
  return {
    enabled: false,
    // ── Meta app (from developers.facebook.com → your app) ──
    app_id: '',             // App ID (Dashboard → App settings → Basic)
    app_secret: '',         // App secret (same page) — platform only; verifies webhook signatures
    business_id: '',        // Meta Business Manager ID (business.facebook.com → Business settings) — optional reference
    // ── WhatsApp product (App → WhatsApp → API Setup) ──
    phone_number_id: '',    // "Phone number ID" shown under the sender number
    waba_id: '',            // "WhatsApp Business Account ID" on the same screen
    display_number: '',     // the human-readable sender number (reference only)
    // ── Auth ──
    access_token: '',       // permanent System User token. Stored here for BOTH sides so the
                            // dashboard is self-serve at launch; the functions fall back to the
                            // WHATSAPP_ACCESS_TOKEN secret when this is empty.
    // ── Webhook (platform only — providers don't receive messages) ──
    verify_token: '',       // any string you invent; must match what you paste into Meta's webhook config
    verified_at: null,
  }
}

/** The webhook callback URL to paste into Meta (App → WhatsApp → Configuration → Webhook). */
export function webhookCallbackUrl() {
  const base = String(import.meta.env.VITE_SUPABASE_URL || '').replace(/\/+$/, '')
  return base ? `${base}/functions/v1/whatsapp-webhook` : ''
}

/** A fresh random verify token (any string works — this just makes a strong one). */
export function generateVerifyToken() {
  const a = new Uint8Array(18)
  crypto.getRandomValues(a)
  return 'bb_' + [...a].map(b => b.toString(16).padStart(2, '0')).join('')
}

// ── Load / save, keyed by owner ───────────────────────────────────────────────
// A provider (ownerId set) stores config on their admin_users row. Bookboll (no ownerId)
// stores it in platform_settings and keeps the token as the function's WHATSAPP_ACCESS_TOKEN
// secret (so platform config never carries a token in the browser).
export async function loadWhatsappConfig(ownerId) {
  if (ownerId) {
    const { data } = await supabase.from('admin_users').select('whatsapp_config').eq('id', ownerId).maybeSingle()
    return { ...blankWhatsappConfig(), ...(data?.whatsapp_config || {}) }
  }
  const { data } = await supabase.from('platform_settings').select('value').eq('key', WHATSAPP_CONFIG_KEY).maybeSingle()
  let saved = {}
  try { saved = data?.value ? JSON.parse(data.value) : {} } catch { saved = {} }
  // Platform config has no token field (it's the fn secret); mark enabled when a number is set.
  return { ...blankWhatsappConfig(), ...saved, enabled: !!saved.phone_number_id }
}

export async function saveWhatsappConfig(ownerId, cfg) {
  if (ownerId) {
    const { error } = await supabase.from('admin_users').update({ whatsapp_config: cfg }).eq('id', ownerId)
    return { error }
  }
  // Platform: the FULL config (token included) is persisted so the owner can finish the
  // Meta setup from the dashboard without touching function secrets. The edge functions
  // use these values first and fall back to the WHATSAPP_ACCESS_TOKEN /
  // WHATSAPP_VERIFY_TOKEN / META_APP_SECRET function secrets when a field is empty.
  // PROD HARDENING: before real launch either tighten RLS on platform_settings or clear
  // the token here and rely on the function secret again.
  const { error } = await supabase.from('platform_settings').upsert(
    { key: WHATSAPP_CONFIG_KEY, value: JSON.stringify(cfg), updated_at: new Date().toISOString() },
    { onConflict: 'key' },
  )
  return { error }
}

/**
 * Connected =
 *  - provider (ownerId): enabled + a phone number id + an access token (in the config), or
 *  - platform (no ownerId): a phone number id is set (the token is the function secret,
 *    confirmed live via the `status` action rather than a stored value).
 */
export function isWhatsappConfigured(cfg, ownerId = null) {
  if (!cfg) return false
  const hasNumber = !!String(cfg.phone_number_id || '').trim()
  if (ownerId) return !!(cfg.enabled && hasNumber && String(cfg.access_token || '').trim())
  return hasNumber
}

/**
 * Call the send-whatsapp Edge Function. Always passes `ownerId` so the function uses the
 * right number+token. Returns { ok, ... }, or { ok:false, notDeployed:true } when the
 * function isn't deployed yet so pages can show a "deploy it first" state.
 */
export async function invokeWhatsapp(payload) {
  try {
    const { data, error } = await supabase.functions.invoke('send-whatsapp', { body: payload })
    if (error) {
      const msg = String(error.message || error)
      const notDeployed = /Failed to send a request|not found|Failed to fetch/i.test(msg)
      return { ok: false, notDeployed, error: notDeployed ? 'The send-whatsapp function is not deployed yet.' : msg }
    }
    return data ?? { ok: false, error: 'Empty response from send-whatsapp.' }
  } catch {
    return { ok: false, notDeployed: true, error: 'The send-whatsapp function is not reachable.' }
  }
}

/** Digits-only WhatsApp number (country code, no +). Returns '' when clearly unusable. */
export function normalizeWaNumber(phone) {
  let d = String(phone || '').replace(/\D/g, '')
  if (d.startsWith('00')) d = d.slice(2)   // international dialing prefix → bare country code
  return d.length >= 8 ? d : ''
}

/** Replace {{1}}…{{n}} in a template body with values (missing → keep the placeholder). */
export function renderTemplateBody(bodyText, variables = []) {
  return String(bodyText || '').replace(/\{\{(\d+)\}\}/g, (_, n) => {
    const v = variables[Number(n) - 1]
    return v == null || v === '' ? `{{${n}}}` : String(v)
  })
}

/** Personalize one variable value: the literal token {name} → the customer's first name. */
export function personalizeVariable(value, customer) {
  const first = String(customer?.full_name || '').trim().split(/\s+/)[0] || 'there'
  return String(value ?? '').replaceAll('{name}', first)
}

// ── Templates: author + submit for Meta review from the dashboard ─────────────
// One platform instead of two: build the template here, we submit it to Meta via
// the edge function, and its review status (PENDING → APPROVED / REJECTED+reason)
// shows up in the same UI via list_templates.

/** Meta template names: lowercase snake_case only. */
export function slugifyTemplateName(s) {
  return String(s || '').toLowerCase().replace(/[^a-z0-9_]+/g, '_').replace(/^_+|_+$/g, '').slice(0, 60)
}

export function createWaTemplate(ownerId, { name, language, category, headerText, bodyText, footerText, examples }) {
  return invokeWhatsapp({ action: 'create_template', ownerId, name, language, category, headerText, bodyText, footerText, examples })
}

export function deleteWaTemplate(ownerId, name) {
  return invokeWhatsapp({ action: 'delete_template', ownerId, name })
}

export function listWaTemplates(ownerId) {
  return invokeWhatsapp({ action: 'list_templates', ownerId })
}

// ── Queued campaigns: compose now, auto-send when Meta approves ────────────────
// A campaign composed against a PENDING template is snapshotted into
// whatsapp_queued_campaigns. processWaQueue() — run whenever the WhatsApp page or
// the marketing composer opens — checks template statuses and either fires the
// stored campaign (APPROVED) or records the rejection reason (REJECTED).

export async function queueWaCampaign({ ownerId, template, payload }) {
  const { data, error } = await supabase.from('whatsapp_queued_campaigns').insert({
    owner_user_id: ownerId || null,
    template_name: template.name,
    template_language: template.language || 'en_US',
    payload,
  }).select().maybeSingle()
  if (error) return { error: /whatsapp_queued_campaigns/.test(error.message) ? 'Queued campaigns need migration 20260735000000 — run it first.' : error.message }
  return { row: data }
}

export async function loadWaQueue(ownerId) {
  let q = supabase.from('whatsapp_queued_campaigns').select('*').is('dismissed_at', null)
    .order('created_at', { ascending: false }).limit(30)
  q = ownerId ? q.eq('owner_user_id', ownerId) : q.is('owner_user_id', null)
  const { data } = await q
  return data || []
}

export async function dismissWaQueueItem(id) {
  await supabase.from('whatsapp_queued_campaigns').update({ dismissed_at: new Date().toISOString() }).eq('id', id)
}

export async function cancelWaQueueItem(id) {
  await supabase.from('whatsapp_queued_campaigns')
    .update({ status: 'cancelled', updated_at: new Date().toISOString() }).eq('id', id).eq('status', 'awaiting_approval')
}

/**
 * The pump. Returns { checked, sent: [row…], rejected: [row…] } so callers can toast.
 * Safe to call often: it only acts on rows still awaiting approval, and flips the row's
 * status BEFORE dispatching so a double-run can't double-send a campaign.
 */
export async function processWaQueue(ownerId) {
  const queue = (await loadWaQueue(ownerId)).filter(r => r.status === 'awaiting_approval')
  if (!queue.length) return { checked: 0, sent: [], rejected: [] }
  const res = await listWaTemplates(ownerId)
  if (!res.ok) return { checked: 0, sent: [], rejected: [], error: res.error }
  const byName = Object.fromEntries((res.templates || []).map(t => [t.name, t]))

  const sent = [], rejected = []
  for (const row of queue) {
    const tpl = byName[row.template_name]
    if (!tpl) continue                                    // still syncing on Meta's side
    if (tpl.status === 'REJECTED') {
      await supabase.from('whatsapp_queued_campaigns').update({
        status: 'rejected', reject_reason: tpl.rejectedReason || 'Rejected by Meta review',
        updated_at: new Date().toISOString(),
      }).eq('id', row.id)
      rejected.push({ ...row, reject_reason: tpl.rejectedReason || 'Rejected by Meta review' })
      continue
    }
    if (tpl.status !== 'APPROVED') continue               // still PENDING

    // Claim the row first (status flip) so concurrent tabs can't both send it.
    const { data: claimed } = await supabase.from('whatsapp_queued_campaigns')
      .update({ status: 'sent', sent_at: new Date().toISOString(), updated_at: new Date().toISOString() })
      .eq('id', row.id).eq('status', 'awaiting_approval').select().maybeSingle()
    if (!claimed) continue

    const p = row.payload || {}
    // Write the compliance ledger row NOW (send time), same as a live campaign send.
    let campaignId = p.campaignId || null
    if (!campaignId && p.campaignMeta) {
      try { const c = await recordCampaign(p.campaignMeta); campaignId = c?.data?.id || null } catch { /* ledger optional pre-migration */ }
    }
    const out = await invokeWhatsapp({
      action: 'send_campaign', ownerId,
      template: { name: row.template_name, language: row.template_language },
      bodyText: p.bodyText || '', campaignId,
      recipients: p.recipients || [],
    })
    const result = out.ok
      ? { sent: out.sent, failed: out.failed, dropped: out.dropped || 0 }
      : { sent: 0, failed: (p.recipients || []).length, error: out.error }
    await supabase.from('whatsapp_queued_campaigns').update({ result }).eq('id', row.id)
    sent.push({ ...row, result })
  }
  return { checked: queue.length, sent, rejected }
}

/**
 * Reactive helper for the CURRENT business's WhatsApp sender — used by the marketing composer
 * (gate + send) and the WhatsApp settings page. Provider → their own config; Bookboll → the
 * platform config. `ownerId` is null for Bookboll (super admin).
 */
export function useProviderWhatsapp() {
  const { currentOwnerUserId } = useCurrentUser()
  const wa = ref(blankWhatsappConfig())
  const loaded = ref(false)
  async function load() {
    wa.value = await loadWhatsappConfig(currentOwnerUserId.value)
    loaded.value = true
  }
  const ownerId = computed(() => currentOwnerUserId.value || null)
  const configured = computed(() => isWhatsappConfigured(wa.value, ownerId.value))
  return { wa, loaded, load, configured, ownerId }
}
