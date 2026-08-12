/**
 * marketing.js — customer marketing consent + campaigns.
 *
 * Consent lives on `customers.marketing_email/sms/whatsapp` (opt-in, default false). Only opted-in
 * customers are emailable from the admin Customers page. Every customer has a stable
 * `unsubscribe_token` so each marketing email carries a one-click unsubscribe link.
 *
 * Delivery is "record & preview": we store the campaign + render the exact compliant HTML
 * (sender disclaimer + unsubscribe button). Swap `recordCampaign` for a real ESP/edge function later.
 */
import { supabase } from './supabase.js'

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

/** Record a sent campaign (best-effort; tolerates a pre-migration DB). */
export async function recordCampaign({ ownerUserId = null, createdBy = null, senderLabel = 'Bookboll', channel = 'email', audience = '', subject = '', body = '', html = '', recipientCount = 0 } = {}) {
  const row = { owner_user_id: ownerUserId, created_by: createdBy, sender_label: senderLabel, channel, audience, subject, body, html, recipient_count: recipientCount }
  try {
    const { data, error } = await supabase.from('marketing_campaigns').insert(row).select().maybeSingle()
    return { data, error }
  } catch (e) { return { error: e } }
}

/** Public unsubscribe: flip the requested channel(s) off for the customer holding `token`. */
export async function applyUnsubscribe(token, channel = 'email') {
  if (!token) return { error: 'Missing token.' }
  const patch = channel === 'all'
    ? { marketing_email: false, marketing_sms: false, marketing_whatsapp: false }
    : { [MARKETING_COL[channel] || 'marketing_email']: false }
  patch.marketing_updated_at = new Date().toISOString()
  try {
    const { data, error } = await supabase.from('customers').update(patch).eq('unsubscribe_token', token).select('id, full_name, email').maybeSingle()
    if (error) return { error: error.message }
    if (!data) return { error: 'This unsubscribe link is invalid or has expired.' }
    return { ok: true, customer: data }
  } catch (e) { return { error: String(e) } }
}
