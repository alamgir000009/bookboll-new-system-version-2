// otp — issue + verify short-lived phone verification codes for customer sign-up.
//
// Actions (POST JSON, all replies HTTP 200 { ok, ... }):
//   send   { phone, channel }            → generate a 6-digit code, store it (5-min expiry),
//                                          deliver via WhatsApp (Bookboll's number) or SMS.
//   verify { phone, code }               → check the latest live code for that number.
//
// WhatsApp delivery reuses Bookboll's Cloud API number (platform_settings.whatsapp_config
// phone_number_id + WHATSAPP_ACCESS_TOKEN secret). SMS is SIMULATED (logged) until a provider
// (e.g. Twilio) is wired — set TWILIO_* to make it real. Codes live in otp_codes (service role;
// never exposed to the anon client). Demo-grade: plaintext code + a demoCode echo for testing.
import { createClient } from 'https://esm.sh/@supabase/supabase-js@2'

const GRAPH = 'https://graph.facebook.com/v25.0'
const WA_TOKEN = Deno.env.get('WHATSAPP_ACCESS_TOKEN') ?? ''
const DEMO_ECHO = (Deno.env.get('OTP_DEMO_ECHO') ?? '1') === '1'   // return the code in the response (TEST ONLY)
const CODE_TTL_MIN = 5
const MAX_PER_WINDOW = 5           // sends allowed per number per 10 min
const MAX_ATTEMPTS = 5             // wrong guesses before a code is dead

const supabase = createClient(Deno.env.get('SUPABASE_URL')!, Deno.env.get('SUPABASE_SERVICE_ROLE_KEY')!)

const CORS = {
  'Access-Control-Allow-Origin': '*',
  'Access-Control-Allow-Headers': 'authorization, x-client-info, apikey, content-type',
  'Access-Control-Allow-Methods': 'POST, OPTIONS',
}
const json = (o: unknown) => new Response(JSON.stringify(o), { status: 200, headers: { ...CORS, 'Content-Type': 'application/json' } })

const normPhone = (p: unknown) => String(p ?? '').replace(/\D/g, '').replace(/^00/, '')
const gen6 = () => String(Math.floor(100000 + Math.random() * 900000))

// Bookboll's own WhatsApp number (for OTP delivery).
async function platformWa(): Promise<{ phoneNumberId: string; token: string }> {
  const { data } = await supabase.from('platform_settings').select('value').eq('key', 'whatsapp_config').maybeSingle()
  let c: any = {}
  try { c = data?.value ? JSON.parse(data.value) : {} } catch { c = {} }
  return { phoneNumberId: String(c.phone_number_id || ''), token: WA_TOKEN }
}

async function sendWhatsApp(to: string, text: string): Promise<{ ok: boolean; error?: string }> {
  const { phoneNumberId, token } = await platformWa()
  if (!phoneNumberId || !token) return { ok: false, error: 'WhatsApp not configured' }
  try {
    const res = await fetch(`${GRAPH}/${phoneNumberId}/messages`, {
      method: 'POST',
      signal: AbortSignal.timeout(15000),
      headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
      body: JSON.stringify({ messaging_product: 'whatsapp', to, type: 'text', text: { body: text } }),
    })
    const body = await res.json().catch(() => ({}))
    if (!res.ok) return { ok: false, error: body?.error?.message || `Graph ${res.status}` }
    return { ok: true }
  } catch (e) { return { ok: false, error: (e as Error).message } }
}

// SMS — SIMULATED until an SMS provider is configured. Wire Twilio here (TWILIO_ACCOUNT_SID /
// TWILIO_AUTH_TOKEN / TWILIO_FROM) to make it real.
async function sendSms(to: string, text: string): Promise<{ ok: boolean; simulated?: boolean; error?: string }> {
  const sid = Deno.env.get('TWILIO_ACCOUNT_SID'), tok = Deno.env.get('TWILIO_AUTH_TOKEN'), from = Deno.env.get('TWILIO_FROM')
  if (!sid || !tok || !from) { console.log(`[SMS simulated] → +${to}: ${text}`); return { ok: true, simulated: true } }
  try {
    const res = await fetch(`https://api.twilio.com/2010-04-01/Accounts/${sid}/Messages.json`, {
      method: 'POST',
      signal: AbortSignal.timeout(15000),
      headers: { Authorization: 'Basic ' + btoa(`${sid}:${tok}`), 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ To: `+${to}`, From: from, Body: text }),
    })
    if (!res.ok) { const b = await res.text(); return { ok: false, error: `Twilio ${res.status}: ${b.slice(0, 120)}` } }
    return { ok: true }
  } catch (e) { return { ok: false, error: (e as Error).message } }
}

Deno.serve(async (req) => {
  if (req.method === 'OPTIONS') return new Response('ok', { headers: CORS })
  if (req.method !== 'POST') return json({ ok: false, error: 'POST only' })

  let body: any = {}
  try { body = await req.json() } catch { return json({ ok: false, error: 'Invalid JSON' }) }
  const action = String(body?.action || '')
  const identifier = normPhone(body?.phone)
  if (identifier.length < 8) return json({ ok: false, error: 'Enter a valid phone number.' })

  if (action === 'send') {
    const channel = body?.channel === 'sms' ? 'sms' : 'whatsapp'

    // Rate limit per number.
    const since = new Date(Date.now() - 10 * 60 * 1000).toISOString()
    const { count } = await supabase.from('otp_codes').select('id', { count: 'exact', head: true })
      .eq('identifier', identifier).gt('created_at', since)
    if ((count ?? 0) >= MAX_PER_WINDOW) return json({ ok: false, error: 'Too many code requests — please wait a few minutes.' })

    const code = gen6()
    const expires_at = new Date(Date.now() + CODE_TTL_MIN * 60 * 1000).toISOString()
    const { error: insErr } = await supabase.from('otp_codes').insert({ identifier, channel, code, purpose: String(body?.purpose || 'customer_signup'), expires_at })
    if (insErr) return json({ ok: false, error: insErr.message })

    const msg = `Your Bookboll verification code is ${code}. It expires in ${CODE_TTL_MIN} minutes.`
    const sent = channel === 'sms' ? await sendSms(identifier, msg) : await sendWhatsApp(identifier, msg)
    // Delivery is best-effort (a brand-new WhatsApp number has no open 24-h session window, so
    // Meta may reject free-form text until an approved authentication template is used). We still
    // return ok so the demo flow can proceed via the echoed code.
    return json({ ok: true, channel, delivered: sent.ok, simulated: (sent as any).simulated || false, deliveryError: sent.ok ? undefined : sent.error, ...(DEMO_ECHO ? { demoCode: code } : {}) })
  }

  if (action === 'verify') {
    const code = String(body?.code || '').trim()
    if (!/^\d{4,8}$/.test(code)) return json({ ok: false, error: 'Enter the code we sent you.' })
    const { data: row } = await supabase.from('otp_codes').select('*')
      .eq('identifier', identifier).eq('consumed', false)
      .order('created_at', { ascending: false }).limit(1).maybeSingle()
    if (!row) return json({ ok: false, error: 'No active code — request a new one.' })
    if (new Date(row.expires_at).getTime() < Date.now()) return json({ ok: false, error: 'Code expired — request a new one.' })
    if ((row.attempts ?? 0) >= MAX_ATTEMPTS) return json({ ok: false, error: 'Too many attempts — request a new code.' })
    if (String(row.code) !== code) {
      await supabase.from('otp_codes').update({ attempts: (row.attempts ?? 0) + 1 }).eq('id', row.id)
      return json({ ok: false, error: 'Incorrect code — try again.' })
    }
    await supabase.from('otp_codes').update({ consumed: true }).eq('id', row.id)
    return json({ ok: true, verified: true })
  }

  return json({ ok: false, error: `Unknown action "${action}".` })
})
