// send-whatsapp — the single server-side sender for Bookboll's WhatsApp channel.
//
// The dashboard invokes this (supabase.functions.invoke) for:
//   - status         → is the token set + which number is connected (settings page)
//   - list_templates → approved WABA templates (marketing composer picker)
//   - send_template  → one templated message (test sends; later notifications)
//   - send_text      → one free-form text (inbox replies / bot, 24-h window only)
//   - send_campaign  → paced bulk template send to opted-in customers (marketing)
//
// Secrets: WHATSAPP_ACCESS_TOKEN (Meta Graph token — server-side ONLY, never in the app).
// Config:  platform_settings key 'whatsapp_config' → { phone_number_id, waba_id, display_number }
//          (edited by the super admin on the /whatsapp page; not sensitive, so it lives in DB).
// Logs:    every dispatch → whatsapp_outbound (service role; the webhook later flips
//          status to delivered/read from Meta's status events).
//
// All responses are HTTP 200 with { ok: boolean, ... } — simpler for the client than
// mixed status codes through functions.invoke.
import { createClient } from 'https://esm.sh/@supabase/supabase-js@2'

const GRAPH = 'https://graph.facebook.com/v25.0'
const PLATFORM_TOKEN = Deno.env.get('WHATSAPP_ACCESS_TOKEN') ?? ''   // Bookboll's own number
const SERVICE_KEY = Deno.env.get('SUPABASE_SERVICE_ROLE_KEY') ?? ''

const supabase = createClient(Deno.env.get('SUPABASE_URL')!, SERVICE_KEY)

const CORS = {
  'Access-Control-Allow-Origin': '*',
  'Access-Control-Allow-Headers': 'authorization, x-client-info, apikey, content-type',
  'Access-Control-Allow-Methods': 'POST, OPTIONS',
}
const json = (obj: unknown) =>
  new Response(JSON.stringify(obj), { status: 200, headers: { ...CORS, 'Content-Type': 'application/json' } })

// WhatsApp wants digits only (country code, no +, no spaces).
const normPhone = (p: unknown) => String(p ?? '').replace(/\D/g, '')

// Substitute {{1}}, {{2}}… into a template body for the outbound log / previews.
function renderBody(bodyText: string, variables: string[] = []) {
  return String(bodyText || '').replace(/\{\{(\d+)\}\}/g, (_, n) => variables[Number(n) - 1] ?? `{{${n}}}`)
}

// Resolve which number+token to use for a call. A provider (ownerId) uses their OWN config
// stored on admin_users.whatsapp_config; Bookboll (no ownerId) uses the platform_settings
// config + the WHATSAPP_ACCESS_TOKEN secret.
async function resolveSender(ownerId: string | null) {
  if (ownerId) {
    const { data } = await supabase.from('admin_users').select('whatsapp_config').eq('id', ownerId).maybeSingle()
    const c = data?.whatsapp_config || {}
    return {
      isProvider: true,
      phoneNumberId: String(c.phone_number_id || ''),
      wabaId: String(c.waba_id || ''),
      token: String(c.access_token || ''),
      enabled: !!c.enabled,
    }
  }
  const { data } = await supabase.from('platform_settings').select('value').eq('key', 'whatsapp_config').maybeSingle()
  let c: any = {}
  try { c = data?.value ? JSON.parse(data.value) : {} } catch { c = {} }
  // Dashboard-entered token first (self-serve setup), WHATSAPP_ACCESS_TOKEN secret as fallback.
  const token = String(c.access_token || '') || PLATFORM_TOKEN
  return {
    isProvider: false,
    phoneNumberId: String(c.phone_number_id || ''),
    wabaId: String(c.waba_id || ''),
    token,
    tokenSource: String(c.access_token || '') ? 'dashboard' : (PLATFORM_TOKEN ? 'secret' : 'none'),
    enabled: !!c.phone_number_id,
  }
}

async function graph(token: string, path: string, init: RequestInit = {}) {
  const res = await fetch(`${GRAPH}/${path}`, {
    ...init,
    // One hung connection must fail that call, not eat the function's wall clock.
    signal: AbortSignal.timeout(15000),
    headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json', ...(init.headers || {}) },
  })
  const body = await res.json().catch(() => ({}))
  if (!res.ok) {
    const msg = body?.error?.message || `Graph API ${res.status}`
    throw new Error(msg)
  }
  return body
}

// One message via the Cloud API. Returns the wamid.
async function dispatch(token: string, phoneNumberId: string, to: string, payload: Record<string, unknown>) {
  const body = await graph(token, `${phoneNumberId}/messages`, {
    method: 'POST',
    body: JSON.stringify({ messaging_product: 'whatsapp', recipient_type: 'individual', to, ...payload }),
  })
  return body?.messages?.[0]?.id ?? null
}

function templatePayload(template: { name: string; language: string }, variables: string[] = []) {
  return {
    type: 'template',
    template: {
      name: template.name,
      language: { code: template.language || 'en_US' },
      ...(variables.length
        ? { components: [{ type: 'body', parameters: variables.map((v) => ({ type: 'text', text: String(v) })) }] }
        : {}),
    },
  }
}

async function logOutbound(rows: Record<string, unknown>[], ownerId: string | null) {
  if (!rows.length) return
  const stamped = rows.map((r) => ({ ...r, owner_user_id: ownerId }))   // scope to the sender
  const { error } = await supabase.from('whatsapp_outbound').insert(stamped)
  if (error) console.error('whatsapp_outbound insert failed:', error.message)
}

Deno.serve(async (req) => {
  if (req.method === 'OPTIONS') return new Response('ok', { headers: CORS })
  if (req.method !== 'POST') return json({ ok: false, error: 'POST only' })

  // Access control is left to Supabase's gateway ("Verify JWT" setting). We intentionally
  // do NOT re-check the caller's key here: this project's key format (legacy anon JWT vs
  // new publishable key) makes a string comparison unreliable and would reject the very
  // calls we want (the dashboard's supabase.functions.invoke). Demo-grade — matches the
  // rest of this app's anon-key model; production would add a real auth check.
  let body: any = {}
  try { body = await req.json() } catch { return json({ ok: false, error: 'Invalid JSON body' }) }
  const action = String(body?.action || '')

  try {
    // Which business is sending? Provider row → their own number+token; null → Bookboll.
    const ownerId = body?.ownerId ? String(body.ownerId) : null
    const sender = await resolveSender(ownerId)
    const { phoneNumberId, token, wabaId } = sender

    // ── status: is this business connected? (settings page) ─────────────────────
    if (action === 'status') {
      const out: any = { ok: true, deployed: true, isProvider: sender.isProvider, hasToken: !!token, tokenSource: (sender as any).tokenSource || (sender.isProvider ? 'dashboard' : 'secret'), configured: !!phoneNumberId }
      if (token && phoneNumberId) {
        try {
          const info = await graph(token, `${phoneNumberId}?fields=verified_name,display_phone_number,quality_rating`)
          out.phone = { name: info?.verified_name, number: info?.display_phone_number, quality: info?.quality_rating }
        } catch (e) { out.phoneError = (e as Error).message }
      }
      return json(out)
    }

    if (!token) return json({ ok: false, error: sender.isProvider ? 'Add your WhatsApp access token on the WhatsApp page.' : 'The WHATSAPP_ACCESS_TOKEN secret is not set on the function.' })

    // ── list_templates: ALL templates + status/rejection reason (manager + composer) ──
    if (action === 'list_templates') {
      if (!wabaId) return json({ ok: false, reason: 'no_waba', error: 'Add your WhatsApp Business Account ID on the WhatsApp page to list templates.' })
      const res = await graph(token, `${wabaId}/message_templates?fields=name,status,category,language,components,rejected_reason&limit=100`)
      const templates = (res?.data ?? []).map((t: any) => {
        const bodyComp = (t.components ?? []).find((c: any) => c?.type === 'BODY')
        const headComp = (t.components ?? []).find((c: any) => c?.type === 'HEADER')
        const footComp = (t.components ?? []).find((c: any) => c?.type === 'FOOTER')
        const bodyText = bodyComp?.text ?? ''
        let varCount = 0
        for (const m of bodyText.matchAll(/\{\{(\d+)\}\}/g)) varCount = Math.max(varCount, Number(m[1]))
        return {
          name: t.name, status: t.status, category: t.category, language: t.language,
          bodyText, varCount,
          headerText: headComp?.format === 'TEXT' ? (headComp?.text ?? '') : '',
          footerText: footComp?.text ?? '',
          rejectedReason: t.rejected_reason && t.rejected_reason !== 'NONE' ? t.rejected_reason : '',
        }
      })
      return json({ ok: true, templates })
    }

    // ── create_template: submit a new template to Meta for review ───────────────
    // The whole point: author + submit from the dashboard instead of Meta Business
    // Manager. Meta reviews it (usually minutes-to-hours); status comes back via
    // list_templates (PENDING → APPROVED / REJECTED + rejected_reason).
    if (action === 'create_template') {
      if (!wabaId) return json({ ok: false, error: 'Add the WhatsApp Business Account ID on the WhatsApp page first.' })
      const name = String(body.name || '').toLowerCase().replace(/[^a-z0-9_]+/g, '_').replace(/^_+|_+$/g, '').slice(0, 512)
      const bodyText = String(body.bodyText || '').trim().slice(0, 1024)
      if (!name || !bodyText) return json({ ok: false, error: 'Template name and body text are required.' })
      const language = String(body.language || 'en_US')
      const category = ['MARKETING', 'UTILITY'].includes(String(body.category)) ? String(body.category) : 'MARKETING'
      const headerText = String(body.headerText || '').trim().slice(0, 60)
      const footerText = String(body.footerText || '').trim().slice(0, 60)

      const components: any[] = []
      if (headerText) components.push({ type: 'HEADER', format: 'TEXT', text: headerText })
      const bodyComp: any = { type: 'BODY', text: bodyText }
      // Meta requires example values for every {{n}} variable in the body.
      let varCount = 0
      for (const m of bodyText.matchAll(/\{\{(\d+)\}\}/g)) varCount = Math.max(varCount, Number(m[1]))
      if (varCount > 0) {
        const examples: string[] = Array.isArray(body.examples) ? body.examples.map(String) : []
        bodyComp.example = { body_text: [Array.from({ length: varCount }, (_, i) => examples[i] || `Sample ${i + 1}`)] }
      }
      components.push(bodyComp)
      if (footerText) components.push({ type: 'FOOTER', text: footerText })

      const res = await graph(token, `${wabaId}/message_templates`, {
        method: 'POST',
        body: JSON.stringify({ name, language, category, components }),
      })
      return json({ ok: true, id: res?.id ?? null, status: res?.status ?? 'PENDING', name })
    }

    // ── delete_template: remove a template (draft cleanup / rejected retry) ─────
    if (action === 'delete_template') {
      if (!wabaId) return json({ ok: false, error: 'WABA ID is required.' })
      const name = String(body.name || '').trim()
      if (!name) return json({ ok: false, error: 'Template name is required.' })
      await graph(token, `${wabaId}/message_templates?name=${encodeURIComponent(name)}`, { method: 'DELETE' })
      return json({ ok: true })
    }

    if (!phoneNumberId) return json({ ok: false, error: 'WhatsApp is not configured yet — set the phone number ID on the WhatsApp page.' })

    // ── send_template: single templated message ─────────────────────────────────
    if (action === 'send_template') {
      const to = normPhone(body.to)
      if (!to) return json({ ok: false, error: 'Recipient number is required.' })
      const template = body.template || { name: 'hello_world', language: 'en_US' }
      const variables: string[] = Array.isArray(body.variables) ? body.variables.map(String) : []
      const wamid = await dispatch(token, phoneNumberId, to, templatePayload(template, variables))
      await logOutbound([{
        to_number: to, customer_id: body.customerId ?? null, campaign_id: body.campaignId ?? null,
        kind: 'template', template_name: template.name,
        body: renderBody(body.bodyText || '', variables) || `[template: ${template.name}]`,
        wa_message_id: wamid, status: 'sent',
      }], ownerId)
      return json({ ok: true, id: wamid })
    }

    // ── send_text: single free-form text (24-h service window only) ─────────────
    if (action === 'send_text') {
      const to = normPhone(body.to)
      const text = String(body.text || '').slice(0, 4096)
      if (!to || !text) return json({ ok: false, error: 'Recipient and text are required.' })
      const wamid = await dispatch(token, phoneNumberId, to, { type: 'text', text: { body: text, preview_url: true } })
      await logOutbound([{
        to_number: to, customer_id: body.customerId ?? null, campaign_id: null,
        kind: 'text', body: text, wa_message_id: wamid, status: 'sent',
      }], ownerId)
      return json({ ok: true, id: wamid })
    }

    // ── send_campaign: bulk template send (marketing) ────────────────────────────
    if (action === 'send_campaign') {
      const template = body.template
      if (!template?.name) return json({ ok: false, error: 'Template is required for campaigns.' })
      const bodyText = String(body.bodyText || '')
      const recipients: any[] = Array.isArray(body.recipients) ? body.recipients.slice(0, 200) : []
      if (!recipients.length) return json({ ok: false, error: 'No recipients.' })
      const dropped = (Array.isArray(body.recipients) ? body.recipients.length : 0) - recipients.length

      const results: any[] = []
      const rows: any[] = []
      for (const r of recipients) {
        const to = normPhone(r.to)
        const variables: string[] = Array.isArray(r.variables) ? r.variables.map(String) : []
        if (!to) { results.push({ to: r.to, ok: false, error: 'Invalid number' }); continue }
        try {
          const wamid = await dispatch(token, phoneNumberId, to, templatePayload(template, variables))
          results.push({ to, ok: true, id: wamid })
          rows.push({
            to_number: to, customer_id: r.customerId ?? null, campaign_id: body.campaignId ?? null,
            kind: 'template', template_name: template.name,
            body: renderBody(bodyText, variables) || `[template: ${template.name}]`,
            wa_message_id: wamid, status: 'sent',
          })
        } catch (e) {
          const msg = (e as Error).message
          results.push({ to, ok: false, error: msg })
          rows.push({
            to_number: to, customer_id: r.customerId ?? null, campaign_id: body.campaignId ?? null,
            kind: 'template', template_name: template.name,
            body: renderBody(bodyText, variables) || `[template: ${template.name}]`,
            wa_message_id: null, status: 'failed', error: msg,
          })
        }
        // Flush the log incrementally so a mid-run death (wall clock, crash) loses at
        // most ~20 rows of already-sent history instead of the whole campaign.
        if (rows.length >= 20) await logOutbound(rows.splice(0), ownerId)
      }
      await logOutbound(rows, ownerId)
      const sent = results.filter((r) => r.ok).length
      return json({ ok: true, sent, failed: results.length - sent, dropped, results })
    }

    return json({ ok: false, error: `Unknown action "${action}".` })
  } catch (e) {
    return json({ ok: false, error: (e as Error).message })
  }
})
