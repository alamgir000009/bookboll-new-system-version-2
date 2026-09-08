/**
 * marketing.js — customer marketing consent + campaigns.
 *
 * Consent lives on `customers.marketing_email/sms/whatsapp` (opt-in, default false). Only opted-in
 * customers are emailable from the admin Customers page. Every customer has a stable
 * `unsubscribe_token` so each marketing email carries a one-click unsubscribe link.
 *
 * Delivery is "record & preview": we store the campaign + render the exact compliant HTML
 * (sender disclaimer + unsubscribe button). Swap `recordCampaign` for a real ESP later.
 *
 * The ledger lives on our own backend (marketing_campaigns). The OWNER of a campaign is
 * decided server-side from the caller, so a provider cannot file a send under Bookboll's
 * name or another provider's.
 */
import { apiFetch } from './api.js'

export const MARKETING_CHANNELS = ['email', 'sms', 'whatsapp']
export const MARKETING_LABELS = { email: 'Email', sms: 'SMS', whatsapp: 'WhatsApp' }
export const MARKETING_COL = { email: 'marketing_email', sms: 'marketing_sms', whatsapp: 'marketing_whatsapp' }

function esc(s) {
  return String(s == null ? '' : s)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#39;')
}

/** Public one-click unsubscribe link for a customer + channel ('email'|'sms'|'whatsapp'|'all'). */
export function unsubscribeUrl(token, channel = 'email') {
  const origin = (typeof window !== 'undefined' && window.location?.origin) || 'https://bookboll.com'
  return `${origin}/unsubscribe?t=${encodeURIComponent(token || '')}&ch=${encodeURIComponent(channel)}`
}

/**
 * Render a compliant marketing email as HTML (inline styles for email clients).
 *  - senderLabel: "Bookboll" or the provider's business name
 *  - isProvider: true → the "sent via the Bookboll platform" disclaimer is shown
 *  - brandColor: accent (defaults to Bookboll teal)
 *  - unsubUrl: the recipient's unsubscribe link (token-based)
 */
export function marketingEmailHtml({ subject = '', body = '', senderLabel = 'Bookboll', isProvider = false, brandColor = '#0f766e', unsubUrl = '#' } = {}) {
  const safeColor = /^#[0-9a-fA-F]{3,8}$/.test(brandColor) ? brandColor : '#0f766e'
  const paras = String(body || '').split(/\n{2,}/).map(p => `<p style="margin:0 0 14px;font-size:15px;line-height:1.6;color:#334155;">${esc(p).replace(/\n/g, '<br>')}</p>`).join('')
  const disclaimer = isProvider
    ? `This email was sent by <strong>${esc(senderLabel)}</strong> via the <strong>Bookboll</strong> platform.`
    : `This email was sent by <strong>Bookboll</strong>.`
  return `<!doctype html><html><body style="margin:0;padding:0;background:#f1f5f9;">
  <div style="max-width:560px;margin:0 auto;padding:24px 16px;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;">
    <div style="background:#fff;border-radius:16px;overflow:hidden;border:1px solid #e2e8f0;">
      <div style="background:${safeColor};padding:18px 24px;">
        <span style="color:#fff;font-size:18px;font-weight:800;letter-spacing:.2px;">${esc(senderLabel)}</span>
      </div>
      <div style="padding:24px;">
        ${subject ? `<h1 style="margin:0 0 14px;font-size:21px;font-weight:800;color:#0f172a;">${esc(subject)}</h1>` : ''}
        ${paras || '<p style="color:#94a3b8;">(no message)</p>'}
      </div>
    </div>
    <div style="text-align:center;padding:18px 12px 4px;font-size:12px;line-height:1.6;color:#94a3b8;">
      <p style="margin:0 0 12px;">${disclaimer}</p>
      <a href="${esc(unsubUrl)}" style="display:inline-block;padding:9px 18px;border:1px solid #cbd5e1;border-radius:999px;color:#475569;text-decoration:none;font-weight:700;font-size:12.5px;">Unsubscribe from marketing emails</a>
      <p style="margin:14px 0 0;">You're receiving this because you opted in to marketing from ${esc(senderLabel)} on Bookboll.</p>
    </div>
  </div></body></html>`
}

/**
 * Record a sent campaign. `ownerUserId`/`createdBy` are accepted for call-site
 * compatibility but ignored — the server takes both from the authenticated caller.
 * Returns the old { data, error } shape so composers keep reading `camp.data?.id`.
 */
export async function recordCampaign({ senderLabel = 'Bookboll', channel = 'email', audience = '', subject = '', body = '', html = '', recipientCount = 0 } = {}) {
  try {
    const res = await apiFetch('/admin/marketing/campaigns', {
      method: 'POST',
      body: { sender_label: senderLabel, channel, audience, subject, body, html, recipient_count: recipientCount },
    })
    if (!res.success) return { error: { message: res.message || 'Could not record the campaign.' } }
    return { data: res.data?.campaign || null }
  } catch (e) { return { error: e } }
}

/** Remove a ledger row for a send that never happened. */
export async function deleteCampaign(id) {
  if (!id) return { ok: false }
  try {
    const res = await apiFetch(`/admin/marketing/campaigns/${encodeURIComponent(id)}`, { method: 'DELETE' })
    return { ok: !!res.success }
  } catch { return { ok: false } }
}

/** The caller's own campaign history, newest first. */
export async function loadCampaigns(limit = 50) {
  const res = await apiFetch('/admin/marketing/campaigns', { query: { limit } })
  return res.success ? (res.data?.campaigns || []) : []
}

/**
 * Public unsubscribe: flip the requested channel(s) off for the customer holding
 * `token`. No session — the recipient is reading an email, so the token IS the
 * credential and the endpoint is deliberately unauthenticated.
 */
export async function applyUnsubscribe(token, channel = 'email') {
  if (!token) return { error: 'Missing token.' }
  try {
    const res = await apiFetch('/marketing/unsubscribe', { method: 'POST', body: { token, channel } })
    if (!res.success) return { error: res.message || 'This unsubscribe link is invalid or has expired.' }
    return { ok: true, customer: res.data?.customer || null }
  } catch (e) { return { error: String(e) } }
}
