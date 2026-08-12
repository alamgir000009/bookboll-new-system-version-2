// WhatsApp Business Cloud API — inbound webhook + menu-driven BOOKING BOT (Bookboll marketplace).
//
// Meta talks to this endpoint two ways:
//   1. GET  — one-time verification handshake when you save the webhook in Meta.
//   2. POST — every inbound message + delivery/read status. We store them, sync receipts,
//             and (for Bookboll's own number) run the guided venue-booking funnel.
//
// The bot is a plain STATE MACHINE (no AI): it walks the customer through
// greet → city → sport → venue → date → slot → confirm, then hands off a deep link to the
// existing web checkout to finish payment. State lives in whatsapp_sessions; live slot
// availability comes from the bb_available_slots() SQL function (shared with the web app).
//
// Deploy:  supabase functions deploy whatsapp-webhook --no-verify-jwt
// Secrets: WHATSAPP_VERIFY_TOKEN (Meta handshake), WHATSAPP_ACCESS_TOKEN (Bookboll's number).
//          SUPABASE_URL / SUPABASE_SERVICE_ROLE_KEY are injected automatically.
import { createClient } from 'https://esm.sh/@supabase/supabase-js@2'

const GRAPH = 'https://graph.facebook.com/v25.0'
// Function-secret FALLBACKS — the dashboard-entered platform config (below) wins when set,
// so the owner can complete the whole Meta setup from the WhatsApp settings page.
const ENV_VERIFY_TOKEN = Deno.env.get('WHATSAPP_VERIFY_TOKEN') ?? ''
const ENV_TOKEN = Deno.env.get('WHATSAPP_ACCESS_TOKEN') ?? ''   // Bookboll's own number (the bot speaks as Bookboll)
const ENV_APP_SECRET = Deno.env.get('META_APP_SECRET') ?? ''    // Meta app secret → verifies X-Hub-Signature-256
const TZ = 'Asia/Qatar'                                     // market timezone for "today"/"tomorrow" (no DST)
const MAX_ROWS = 200                                        // hard cap on rows written / bot replies per POST

const supabase = createClient(
  Deno.env.get('SUPABASE_URL')!,
  Deno.env.get('SUPABASE_SERVICE_ROLE_KEY')!,   // service role: bypasses RLS to read catalog + write sessions
)

// Dashboard-first credentials: platform_settings.whatsapp_config (filled in on the
// WhatsApp settings page) → function secrets as fallback. Cached ~60s per isolate so
// a webhook burst doesn't hammer the settings table.
let _waCfgCache: { at: number; cfg: any } | null = null
async function waCfg(): Promise<any> {
  if (_waCfgCache && Date.now() - _waCfgCache.at < 60_000) return _waCfgCache.cfg
  let cfg: any = {}
  try {
    const { data } = await supabase.from('platform_settings').select('value').eq('key', 'whatsapp_config').maybeSingle()
    cfg = data?.value ? JSON.parse(data.value) : {}
  } catch { cfg = {} }
  _waCfgCache = { at: Date.now(), cfg }
  return cfg
}
const getVerifyToken = async () => String((await waCfg()).verify_token || '') || ENV_VERIFY_TOKEN
const getToken = async () => String((await waCfg()).access_token || '') || ENV_TOKEN
const getAppSecret = async () => String((await waCfg()).app_secret || '') || ENV_APP_SECRET

// ── small helpers ──────────────────────────────────────────────────────────────
const cap = (s: string, n: number) => (s.length > n ? s.slice(0, n - 1) + '…' : s)
const uniq = <T,>(a: T[]) => [...new Set(a)]

// Website we nudge customers to for photos + the full booking experience. Resolved per request
// from the platform config (falls back to bookboll.com); siteLabel() is the bare host for copy.
let SITE_BASE = 'https://bookboll.com'
const siteLabel = () => SITE_BASE.replace(/^https?:\/\//, '')

// A tappable "Start over" row appended to list menus (when there's room under Meta's 10-row cap).
const RESTART_ROW = { id: 'nav:restart', title: '🔄 Start over' }
const withRestart = <T extends { id: string; title: string }>(rows: T[]) =>
  rows.length < 10 ? [...rows, RESTART_ROW] : rows

// Verify Meta's X-Hub-Signature-256 = "sha256=" + HMAC-SHA256(rawBody, APP_SECRET). This is
// the ONLY thing that proves a POST really came from Meta (the endpoint is public, deployed
// --no-verify-jwt). Enforced whenever META_APP_SECRET is set; if it's unset we skip (so the
// function still runs before the secret is added) — set the secret in production.
const sigEnc = new TextEncoder()
async function validSignature(raw: string, header: string | null): Promise<boolean> {
  const APP_SECRET = await getAppSecret()
  if (!APP_SECRET) return true
  if (!header || !header.startsWith('sha256=')) return false
  const key = await crypto.subtle.importKey('raw', sigEnc.encode(APP_SECRET), { name: 'HMAC', hash: 'SHA-256' }, false, ['sign'])
  const mac = await crypto.subtle.sign('HMAC', key, sigEnc.encode(raw))
  const expected = 'sha256=' + [...new Uint8Array(mac)].map((b) => b.toString(16).padStart(2, '0')).join('')
  if (expected.length !== header.length) return false
  let diff = 0
  for (let i = 0; i < expected.length; i++) diff |= expected.charCodeAt(i) ^ header.charCodeAt(i)   // constant-time
  return diff === 0
}

// Mirror src/customer/data.js listingVisible() for the Bookboll marketplace: a listing is
// bookable via the bot only if it's placed on the marketplace AND past approval/eligibility.
// Missing columns count as visible (pre-migration rows). Keeps the bot from surfacing
// own-website-only, pending, rejected or paused listings.
const MKT_COLS = 'id, name, base_price, location_id, sport_category, show_on_marketplace, approval_status, eligibility_status'
const marketplaceVisible = (l: any) =>
  l?.show_on_marketplace !== false &&
  (l?.approval_status || 'approved') === 'approved' &&
  (l?.eligibility_status || 'eligible') !== 'paused'

// Best-effort plain-text extraction for the inbound log.
function messageBody(m: any): string | null {
  switch (m?.type) {
    case 'text':        return m.text?.body ?? null
    case 'button':      return m.button?.text ?? null
    case 'interactive': return m.interactive?.button_reply?.title ?? m.interactive?.list_reply?.title ?? null
    case 'image':       return m.image?.caption ?? '[image]'
    case 'video':       return m.video?.caption ?? '[video]'
    case 'audio':       return '[audio]'
    case 'document':    return m.document?.filename ?? '[document]'
    case 'location':    return '[location]'
    case 'reaction':    return m.reaction?.emoji ?? '[reaction]'
    default:            return m?.type ? `[${m.type}]` : null
  }
}

// What did the customer tap/type? Interactive replies carry our own routing ids.
function inboundChoice(m: any): string {
  if (m?.type === 'interactive') return m.interactive?.button_reply?.id || m.interactive?.list_reply?.id || ''
  if (m?.type === 'button')      return m.button?.payload || ''
  return ''
}
const inboundText = (m: any): string => (m?.type === 'text' ? String(m.text?.body || '').trim() : '')

// ── date helpers (market-local) ──────────────────────────────────────────────────
const ymdFmt = new Intl.DateTimeFormat('en-CA', { timeZone: TZ, year: 'numeric', month: '2-digit', day: '2-digit' })
const humanFmt = new Intl.DateTimeFormat('en-GB', { timeZone: TZ, weekday: 'short', day: 'numeric', month: 'short' })
const todayStr = () => ymdFmt.format(new Date())                     // "YYYY-MM-DD" in market tz
function addDays(ymd: string, n: number): string {
  const [y, m, d] = ymd.split('-').map(Number)
  const dt = new Date(Date.UTC(y, m - 1, d + n, 12))                 // noon UTC avoids offset rollover
  return ymdFmt.format(dt)
}
function humanDate(ymd: string): string {
  const [y, m, d] = ymd.split('-').map(Number)
  return humanFmt.format(new Date(Date.UTC(y, m - 1, d, 12)))        // e.g. "Sun 6 Jul"
}
// 24h "HH:MM" → 12h "9:00 AM" for DISPLAY only. Slot IDs + the deep-link t= stay 24h.
function fmt12(t: string): string {
  const [h, m] = String(t).split(':').map(Number)
  if (isNaN(h)) return String(t)
  return `${h % 12 || 12}:${String(m || 0).padStart(2, '0')} ${h >= 12 ? 'PM' : 'AM'}`
}

// ── Graph send + outbound log ──────────────────────────────────────────────────
let PLATFORM_PHONE_ID = ''   // resolved once per request

async function graphSend(to: string, payload: Record<string, unknown>): Promise<string | null> {
  const res = await fetch(`${GRAPH}/${PLATFORM_PHONE_ID}/messages`, {
    method: 'POST',
    signal: AbortSignal.timeout(15000),
    headers: { Authorization: `Bearer ${await getToken()}`, 'Content-Type': 'application/json' },
    body: JSON.stringify({ messaging_product: 'whatsapp', recipient_type: 'individual', to, ...payload }),
  })
  const body = await res.json().catch(() => ({}))
  if (!res.ok) { console.error('bot send failed:', body?.error?.message || res.status); return null }
  return body?.messages?.[0]?.id ?? null
}

// Send + log to whatsapp_outbound (owner null = Bookboll) so the bot's messages show up
// alongside marketing and pick up delivered/read receipts. Best-effort.
async function botSend(to: string, payload: Record<string, unknown>, logBody: string, kind = 'interactive') {
  const wamid = await graphSend(to, payload)
  const { error } = await supabase.from('whatsapp_outbound').insert({
    to_number: to, customer_id: null, campaign_id: null, owner_user_id: null,
    kind, body: logBody.slice(0, 4096), wa_message_id: wamid, status: wamid ? 'sent' : 'failed',
  })
  if (error) console.error('bot outbound log failed:', error.message)
  return wamid
}

const sendText = (to: string, text: string) =>
  botSend(to, { type: 'text', text: { body: text.slice(0, 4096), preview_url: true } }, text, 'text')

// buttons: up to 3 × {id,title}. title ≤ 20 chars.
function sendButtons(to: string, bodyText: string, buttons: { id: string; title: string }[]) {
  return botSend(to, {
    type: 'interactive',
    interactive: {
      type: 'button',
      body: { text: cap(bodyText, 1024) },
      action: { buttons: buttons.slice(0, 3).map((b) => ({ type: 'reply', reply: { id: b.id.slice(0, 256), title: cap(b.title, 20) } })) },
    },
  }, bodyText)
}

// list: up to 10 × {id,title,description}. title ≤ 24, description ≤ 72.
function sendList(to: string, bodyText: string, buttonLabel: string, rows: { id: string; title: string; description?: string }[]) {
  return botSend(to, {
    type: 'interactive',
    interactive: {
      type: 'list',
      body: { text: cap(bodyText, 1024) },
      action: {
        button: cap(buttonLabel, 20),
        sections: [{ rows: rows.slice(0, 10).map((r) => ({ id: r.id.slice(0, 200), title: cap(r.title, 24), ...(r.description ? { description: cap(r.description, 72) } : {}) })) }],
      },
    },
  }, bodyText)
}

// ── catalog queries (service role) ─────────────────────────────────────────────
async function activeLocationIds(cityId?: string): Promise<{ ids: string[]; nameById: Record<string, string> }> {
  let q = supabase.from('locations').select('id, name, city_id').eq('status', 'active')
  if (cityId) q = q.eq('city_id', cityId)
  const { data } = await q
  const ids = (data ?? []).map((l: any) => l.id)
  const nameById: Record<string, string> = {}
  for (const l of data ?? []) nameById[l.id] = l.name || 'Venue'
  return { ids, nameById }
}

async function listCities(): Promise<{ id: string; name: string }[]> {
  const { data: ls } = await supabase.from('listings').select(MKT_COLS).eq('status', 'active')
  const locIds = uniq((ls ?? []).filter(marketplaceVisible).map((r: any) => r.location_id).filter(Boolean))
  if (!locIds.length) return []
  const { data: locs } = await supabase.from('locations').select('city_id').in('id', locIds).eq('status', 'active')
  const cityIds = uniq((locs ?? []).map((r: any) => r.city_id).filter(Boolean))
  if (!cityIds.length) return []
  const { data: cities } = await supabase.from('cities').select('id, name').in('id', cityIds).eq('status', 'active').order('name')
  return (cities ?? []).map((c: any) => ({ id: c.id, name: c.name || 'City' }))
}

async function cityCurrency(cityId: string): Promise<string> {
  const { data: city } = await supabase.from('cities').select('country_id').eq('id', cityId).maybeSingle()
  if (!city?.country_id) return ''
  const { data: co } = await supabase.from('countries').select('currency_code').eq('id', city.country_id).maybeSingle()
  return co?.currency_code || ''
}

async function listSports(cityId: string): Promise<string[]> {
  const { ids } = await activeLocationIds(cityId)
  if (!ids.length) return []
  const { data } = await supabase.from('listings').select(MKT_COLS).in('location_id', ids).eq('status', 'active')
  return uniq((data ?? []).filter(marketplaceVisible).map((r: any) => r.sport_category).filter(Boolean)) as string[]
}

async function listVenues(cityId: string, sport: string): Promise<{ id: string; name: string; loc: string; price: number }[]> {
  const { ids, nameById } = await activeLocationIds(cityId)
  if (!ids.length) return []
  const { data } = await supabase.from('listings')
    .select(MKT_COLS)
    .in('location_id', ids).eq('status', 'active').eq('sport_category', sport).order('name')
  return (data ?? []).filter(marketplaceVisible)
    .map((l: any) => ({ id: l.id, name: l.name || 'Venue', loc: nameById[l.location_id] || '', price: Number(l.base_price || 0) }))
}

async function availableSlots(listingId: string, date: string): Promise<{ start: string; end: string; price: number }[]> {
  const { data, error } = await supabase.rpc('bb_available_slots', { p_listing_id: listingId, p_date: date })
  if (error) { console.error('bb_available_slots failed:', error.message); return [] }
  return (data ?? []).filter((s: any) => s.available).map((s: any) => ({ start: s.start_time, end: s.end_time, price: Number(s.price || 0) }))
}

// ── session store ──────────────────────────────────────────────────────────────
type Session = { step: string; context: Record<string, any> }
async function loadSession(waId: string): Promise<Session> {
  const { data } = await supabase.from('whatsapp_sessions').select('step, context').eq('wa_id', waId).maybeSingle()
  return { step: data?.step || 'start', context: data?.context || {} }
}
async function saveSession(waId: string, step: string, context: Record<string, any>) {
  const { error } = await supabase.from('whatsapp_sessions')
    .upsert({ wa_id: waId, step, context, updated_at: new Date().toISOString() }, { onConflict: 'wa_id' })
  if (error) console.error('session save failed:', error.message)
}

// ── funnel steps (each SENDS the next prompt and returns the next {step,context}) ─
// WhatsApp allows max 3 reply buttons → Book / Events / Memberships; "help" stays a
// text command (mentioned in the greeting) instead of burning a button slot.
async function promptMenu(to: string): Promise<Session> {
  await sendButtons(to, `Welcome to Bookboll ⚽🏀🎾\nBook a sports venue, join games & events near you, or grab a gym, class or academy membership — all in a few taps.\nBrowse everything (photos, offers, free trials) at ${siteLabel()}. Type *menu* anytime to start over.`, [
    { id: 'menu:book', title: '🏟️ Book a venue' },
    { id: 'menu:events', title: '🎉 Events near me' },
    { id: 'menu:programs', title: '💪 Memberships' },
  ])
  return { step: 'menu', context: {} }
}

// Interval labels for membership prices.
const INTERVAL_LONG: Record<string, string> = { monthly: 'month', weekly: 'week', quarterly: 'quarter', yearly: 'year' }
const INTERVAL_SHORT: Record<string, string> = { monthly: 'mo', weekly: 'wk', quarterly: 'qtr', yearly: 'yr' }

// A program is marketplace-visible under the SAME rule as listings (marketplaceVisible
// checks the identical three columns). Resolve a location's display name once.
async function locationLabel(locId?: string | null): Promise<string> {
  if (!locId) return ''
  const { data } = await supabase.from('locations').select('name').eq('id', locId).maybeSingle()
  return data?.name || ''
}
function eventKindIcon(t: string): string {
  return t === 'tournament' ? '🏆' : t === 'event' ? '🎊' : '⚽'
}

// ── EVENTS funnel: menu → pick city → tap an event → details + join link ──────────
// Cities that currently have at least one open, future/flexible, non-removed event.
async function eventCities(): Promise<{ id: string; name: string }[]> {
  const today = todayStr()
  const { data } = await supabase.from('hosted_games')
    .select('city_id, status, game_date, moderation_status')
    .eq('status', 'open')
    .or(`game_date.gte.${today},game_date.is.null`)
  const alive = (data ?? []).filter((g: any) => (g.moderation_status || 'active') !== 'removed')
  const cityIds = uniq(alive.map((g: any) => g.city_id).filter(Boolean))
  if (!cityIds.length) return []
  const { data: cities } = await supabase.from('cities').select('id, name').in('id', cityIds).order('name')
  return (cities ?? []).map((c: any) => ({ id: c.id, name: c.name || 'City' }))
}

async function eventsInCity(cityId: string): Promise<any[]> {
  const today = todayStr()
  const { data } = await supabase.from('hosted_games')
    .select('id, title, sport, game_type, game_date, start_time, price_per_person, currency, max_players, custom_location_name, location_id, status, moderation_status')
    .eq('status', 'open').eq('city_id', cityId)
    .or(`game_date.gte.${today},game_date.is.null`)
    .order('game_date', { ascending: true }).limit(10)
  return (data ?? []).filter((g: any) => (g.moderation_status || 'active') !== 'removed')
}

async function promptEventCity(to: string): Promise<Session> {
  const cities = await eventCities()
  if (!cities.length) { await sendText(to, `No upcoming events yet — new games, hangouts and tournaments show up at ${SITE_BASE}/games.\nType *menu* to go back.`); return { step: 'menu', context: {} } }
  await sendList(to, `🎉 Find events near you — which city?${overflowNote(cities.length)}`, 'Choose city', withRestart(cities.map((c) => ({ id: `evcity:${c.id}`, title: c.name }))))
  return { step: 'ev_city', context: {} }
}

async function promptEventList(to: string, cityId: string, cityName: string): Promise<Session> {
  const events = await eventsInCity(cityId)
  if (!events.length) { await sendText(to, `No upcoming events in ${cityName} right now. See other cities at ${SITE_BASE}/games.\nType *menu* to go back.`); return promptEventCity(to) }
  const rows = events.map((g: any) => {
    const when = g.game_date ? `${humanDate(g.game_date)}${g.start_time ? ' ' + fmt12(String(g.start_time).slice(0, 5)) : ''}` : 'Flexible'
    const price = Number(g.price_per_person) > 0 ? `${g.currency} ${Number(g.price_per_person)}` : 'Free'
    return { id: `ev:${g.id}`, title: `${eventKindIcon(g.game_type)} ${g.title || g.sport}`, description: `${when} · ${price}` }
  })
  await sendList(to, `Upcoming events in ${cityName}:\n📷 Full details & photos at ${siteLabel()}`, 'Choose event', withRestart(rows))
  return { step: 'ev_list', context: { evCityId: cityId, evCityName: cityName } }
}

async function sendEventDetail(to: string, gameId: string): Promise<void> {
  const { data: g } = await supabase.from('hosted_games').select('*').eq('id', gameId).maybeSingle()
  if (!g || g.status !== 'open' || (g.moderation_status || 'active') === 'removed') {
    await sendText(to, `That event is no longer available. Type *menu* to browse more.`); return
  }
  const path = g.game_type === 'tournament' ? '/tournaments/' : '/games/'
  const when = g.game_date ? `${humanDate(g.game_date)}${g.start_time ? ' at ' + fmt12(String(g.start_time).slice(0, 5)) : ''}` : 'Flexible date'
  const where = g.custom_location_name || (await locationLabel(g.location_id)) || ''
  const price = Number(g.price_per_person) > 0 ? `${g.currency} ${Number(g.price_per_person)} per person` : 'Free to join'
  const cap = Number(g.max_players) > 0 ? `\n👥 Up to ${g.max_players}` : ''
  await sendText(to, `${eventKindIcon(g.game_type)} *${g.title || g.sport}*\n📅 ${when}${where ? `\n📍 ${where}` : ''}\n💵 ${price}${cap}\n\nTap to view & join 👇\n${SITE_BASE}${path}${g.id}\n\nType *menu* to go back.`)
}

// ── MEMBERSHIPS funnel: menu → pick city → tap a program → details + join link ────
// Cities that currently have at least one marketplace-visible program (via its location).
async function programCities(): Promise<{ id: string; name: string }[]> {
  const { data: progs } = await supabase.from('class_programs')
    .select('location_id, status, approval_status, eligibility_status, show_on_marketplace')
    .eq('status', 'active')
  const locIds = uniq((progs ?? []).filter(marketplaceVisible).map((p: any) => p.location_id).filter(Boolean))
  if (!locIds.length) return []
  const { data: locs } = await supabase.from('locations').select('city_id').in('id', locIds).eq('status', 'active')
  const cityIds = uniq((locs ?? []).map((l: any) => l.city_id).filter(Boolean))
  if (!cityIds.length) return []
  const { data: cities } = await supabase.from('cities').select('id, name').in('id', cityIds).order('name')
  return (cities ?? []).map((c: any) => ({ id: c.id, name: c.name || 'City' }))
}

async function programsInCity(cityId: string): Promise<any[]> {
  const { data: locs } = await supabase.from('locations').select('id').eq('city_id', cityId).eq('status', 'active')
  const locIds = (locs ?? []).map((l: any) => l.id)
  if (!locIds.length) return []
  const { data } = await supabase.from('class_programs')
    .select('id, name, category, sport_category, monthly_price, currency, billing_interval, free_trial_enabled, free_trial_days, free_trial_sessions, status, approval_status, eligibility_status, show_on_marketplace')
    .eq('status', 'active').in('location_id', locIds).order('name').limit(24)
  return (data ?? []).filter(marketplaceVisible).slice(0, 10)
}

function programCatIcon(cat: string): string {
  return cat === 'gym' ? '🏋️' : cat === 'academy' ? '🎓' : '🧘'
}

async function promptProgramCity(to: string): Promise<Session> {
  const cities = await programCities()
  if (!cities.length) { await sendText(to, `Browse gym, class and academy memberships at ${SITE_BASE}/memberships.\nType *menu* to go back.`); return { step: 'menu', context: {} } }
  await sendList(to, `💪 Find memberships near you — which city?${overflowNote(cities.length)}`, 'Choose city', withRestart(cities.map((c) => ({ id: `prcity:${c.id}`, title: c.name }))))
  return { step: 'pr_city', context: {} }
}

async function promptProgramList(to: string, cityId: string, cityName: string): Promise<Session> {
  const progs = await programsInCity(cityId)
  if (!progs.length) { await sendText(to, `No memberships in ${cityName} right now. See others at ${SITE_BASE}/memberships.\nType *menu* to go back.`); return promptProgramCity(to) }
  const rows = progs.map((p: any) => {
    const per = INTERVAL_SHORT[p.billing_interval] || 'mo'
    const trial = p.free_trial_enabled && (Number(p.free_trial_days) > 0 || Number(p.free_trial_sessions) > 0) ? ' · free trial' : ''
    return { id: `pr:${p.id}`, title: `${programCatIcon(p.category)} ${p.name}`, description: `${p.currency || ''} ${Number(p.monthly_price) || 0}/${per}${trial}` }
  })
  await sendList(to, `Memberships in ${cityName}:\n📷 Full details & offers at ${siteLabel()}`, 'Choose one', withRestart(rows))
  return { step: 'pr_list', context: { prCityId: cityId, prCityName: cityName } }
}

async function sendProgramDetail(to: string, programId: string): Promise<void> {
  const { data: p } = await supabase.from('class_programs').select('*').eq('id', programId).maybeSingle()
  if (!p || p.status !== 'active' || !marketplaceVisible(p)) {
    await sendText(to, `That membership is no longer available. Type *menu* to browse more.`); return
  }
  const per = INTERVAL_LONG[p.billing_interval] || 'month'
  const trial = p.free_trial_enabled && Number(p.free_trial_days) > 0 ? `\n🎁 ${p.free_trial_days}-day free trial`
    : p.free_trial_enabled && Number(p.free_trial_sessions) > 0 ? `\n🎁 1 free trial session` : ''
  const where = (await locationLabel(p.location_id)) || ''
  const catLabel = p.category === 'gym' ? 'Gym' : p.category === 'academy' ? 'Academy' : 'Class'
  await sendText(to, `💪 *${p.name}*\n🏷️ ${catLabel}${p.sport_category ? ` · ${p.sport_category}` : ''}\n💵 ${p.currency || ''} ${Number(p.monthly_price) || 0}/${per}${trial}${where ? `\n📍 ${where}` : ''}\n\nTap to view & join 👇\n${SITE_BASE}/programs/${p.id}\n\nType *menu* to go back.`)
}

// WhatsApp interactive lists hold at most 10 rows. When there are more, say so (there's no
// in-chat pagination) and point to the website rather than silently hiding the rest.
const overflowNote = (n: number) => (n > 10 ? `\n\n(Showing 10 of ${n} — see all on ${siteLabel()})` : '')

async function promptCity(to: string, ctx: Record<string, any>): Promise<Session> {
  const cities = await listCities()
  if (!cities.length) { await sendText(to, 'No venues are available to book yet. Please check back soon.'); return { step: 'menu', context: {} } }
  await sendList(to, `Which city are you looking in?${overflowNote(cities.length)}`, 'Choose city', withRestart(cities.map((c) => ({ id: `city:${c.id}`, title: c.name }))))
  return { step: 'city', context: ctx }
}

async function promptSport(to: string, ctx: Record<string, any>): Promise<Session> {
  const sports = await listSports(ctx.cityId)
  if (!sports.length) { await sendText(to, `No venues in ${ctx.cityName} yet — try another city.`); return promptCity(to, {}) }
  await sendList(to, `What would you like to play in ${ctx.cityName}?${overflowNote(sports.length)}`, 'Choose sport', withRestart(sports.map((s) => ({ id: `sport:${s}`, title: s }))))
  return { step: 'sport', context: ctx }
}

async function promptVenue(to: string, ctx: Record<string, any>): Promise<Session> {
  const venues = await listVenues(ctx.cityId, ctx.sport)
  if (!venues.length) { await sendText(to, `No ${ctx.sport} venues in ${ctx.cityName} right now.`); return promptSport(to, ctx) }
  const cur = ctx.currency ? `${ctx.currency} ` : ''
  // Rows can't show photos, so tell the customer where to see them + full details.
  await sendList(to, `${ctx.sport} venues in ${ctx.cityName}:\n📷 See photos & full details at ${siteLabel()}${overflowNote(venues.length)}`, 'Choose venue',
    withRestart(venues.slice(0, 10).map((v) => ({ id: `venue:${v.id}`, title: v.name, description: `${v.loc ? v.loc + ' · ' : ''}from ${cur}${v.price}` }))))
  // Stash names so 'venue' step can label the selection without another query (only the shown 10).
  return { step: 'venue', context: { ...ctx, venueNames: Object.fromEntries(venues.slice(0, 10).map((v) => [v.id, { name: v.name, loc: v.loc }])) } }
}

async function promptDate(to: string, ctx: Record<string, any>): Promise<Session> {
  // Point to this venue's page (photos, reviews, amenities, memberships) while they pick a date.
  await sendButtons(to, `📅 When would you like to play at ${ctx.listingName}?\n\n📷 Photos & details: ${SITE_BASE}/listings/${ctx.listingId}`, [
    { id: 'date:today', title: 'Today' },
    { id: 'date:tomorrow', title: 'Tomorrow' },
    { id: 'date:more', title: 'More dates' },
  ])
  return { step: 'date', context: ctx }
}

async function promptMoreDates(to: string, ctx: Record<string, any>): Promise<Session> {
  const t = todayStr()
  const rows = Array.from({ length: 7 }, (_, i) => addDays(t, i + 2)).map((d) => ({ id: `date:${d}`, title: humanDate(d) }))
  await sendList(to, 'Pick a date:', 'Choose date', withRestart(rows))
  return { step: 'date', context: ctx }
}

async function promptSlots(to: string, ctx: Record<string, any>, date: string): Promise<Session> {
  const slots = await availableSlots(ctx.listingId, date)
  if (!slots.length) { await sendText(to, `No free slots at ${ctx.listingName} on ${humanDate(date)}. Try another date 👇`); return promptDate(to, ctx) }
  const cur = ctx.currency ? `${ctx.currency} ` : ''
  const shown = slots.slice(0, 10)
  const more = slots.length > 10 ? `\n(+${slots.length - 10} more — see the booking link)` : ''
  await sendList(to, `Free slots on ${humanDate(date)}:${more}`, 'Choose time',
    withRestart(shown.map((s) => ({ id: `slot:${s.start}-${s.end}-${s.price}`, title: `${fmt12(s.start)}–${fmt12(s.end)}`, description: `${cur}${s.price}` }))))
  return { step: 'slot', context: { ...ctx, date } }
}

// Re-derive the chosen slot from the CURRENT availability rather than trusting the tapped id.
// This (a) kills injection — the id's end/price are attacker-controllable in a forged webhook,
// so we take only the start and read end/price back from bb_available_slots — and (b) rejects a
// slot that was booked/expired since the list was shown (incl. a stale tap of an old list).
async function chooseSlot(to: string, ctx: Record<string, any>, payload: string): Promise<Session> {
  const want = String(payload).split('-')[0].slice(0, 5)
  const slots = await availableSlots(ctx.listingId, ctx.date)
  const match = slots.find((s) => String(s.start).slice(0, 5) === want)
  if (!match) { await sendText(to, 'That time was just taken or is being held by someone else — if it was only being held, it may free up within ~10 minutes. Here are the times open right now 👇'); return promptSlots(to, ctx, ctx.date) }
  return promptConfirm(to, { ...ctx, slotStart: match.start, slotEnd: match.end, slotPrice: match.price })
}

async function promptConfirm(to: string, ctx: Record<string, any>): Promise<Session> {
  const cur = ctx.currency ? `${ctx.currency} ` : ''
  await sendButtons(to,
    `Please confirm:\n\n🏟️ ${ctx.listingName}${ctx.locationName ? `\n📍 ${ctx.locationName}` : ''}\n📅 ${humanDate(ctx.date)}\n🕐 ${fmt12(ctx.slotStart)}–${fmt12(ctx.slotEnd)}\n💵 ${cur}${ctx.slotPrice}\n\nTap Confirm for your secure checkout link.`,
    [{ id: 'confirm:yes', title: '✅ Confirm' }, { id: 'confirm:no', title: '✖️ Cancel' }])
  return { step: 'confirm', context: ctx }
}

// ── the state machine ──────────────────────────────────────────────────────────
async function runBot(to: string, m: any) {
  const session = await loadSession(to)
  const ctx = session.context || {}
  const choice = inboundChoice(m)
  const text = inboundText(m).toLowerCase()
  const colon = choice.indexOf(':')
  const action = colon >= 0 ? choice.slice(0, colon) : choice
  const payload = colon >= 0 ? choice.slice(colon + 1) : ''

  // Restart from ANY step — tapped "🔄 Start over" (nav:restart) or a typed command.
  if ((action === 'nav' && payload === 'restart') ||
      (!choice && ['hi', 'hello', 'hey', 'menu', 'start', 'restart', 'cancel'].includes(text))) {
    const s = await promptMenu(to); return saveSession(to, s.step, s.context)
  }
  if (!choice && text === 'help') {
    await sendText(to, 'I can help you book a sports venue, find games & events to join, and browse gym/class memberships. Tap an option below, or type "menu" anytime to restart.')
    const s = await promptMenu(to); return saveSession(to, s.step, s.context)
  }

  let next: Session

  switch (session.step) {
    case 'menu':
      if (action === 'menu' && payload === 'book') next = await promptCity(to, {})
      else if (action === 'menu' && payload === 'events') next = await promptEventCity(to)
      else if (action === 'menu' && payload === 'programs') next = await promptProgramCity(to)
      else if (action === 'menu' && payload === 'help') { await sendText(to, 'Tap "Book a venue", "Events near me" or "Memberships" to start. Type "menu" anytime to restart.'); next = await promptMenu(to) }
      else next = await promptMenu(to)
      break

    // Events funnel: city → event list → detail + join link
    case 'ev_city':
      if (action === 'evcity' && payload) {
        const { data: city } = await supabase.from('cities').select('name').eq('id', payload).maybeSingle()
        next = await promptEventList(to, payload, city?.name || 'your city')
      } else next = await promptEventCity(to)
      break

    case 'ev_list':
      if (action === 'ev' && payload) { await sendEventDetail(to, payload); next = { step: 'ev_list', context: ctx } }
      else if (action === 'evcity' && payload) {
        const { data: city } = await supabase.from('cities').select('name').eq('id', payload).maybeSingle()
        next = await promptEventList(to, payload, city?.name || 'your city')
      } else if (ctx.evCityId) next = await promptEventList(to, ctx.evCityId, ctx.evCityName || 'your city')
      else next = await promptEventCity(to)
      break

    // Memberships funnel: city → program list → detail + join link
    case 'pr_city':
      if (action === 'prcity' && payload) {
        const { data: city } = await supabase.from('cities').select('name').eq('id', payload).maybeSingle()
        next = await promptProgramList(to, payload, city?.name || 'your city')
      } else next = await promptProgramCity(to)
      break

    case 'pr_list':
      if (action === 'pr' && payload) { await sendProgramDetail(to, payload); next = { step: 'pr_list', context: ctx } }
      else if (action === 'prcity' && payload) {
        const { data: city } = await supabase.from('cities').select('name').eq('id', payload).maybeSingle()
        next = await promptProgramList(to, payload, city?.name || 'your city')
      } else if (ctx.prCityId) next = await promptProgramList(to, ctx.prCityId, ctx.prCityName || 'your city')
      else next = await promptProgramCity(to)
      break

    case 'city':
      if (action === 'city' && payload) {
        const { data: city } = await supabase.from('cities').select('name').eq('id', payload).maybeSingle()
        const currency = await cityCurrency(payload)
        next = await promptSport(to, { ...ctx, cityId: payload, cityName: city?.name || 'your city', currency })
      } else next = await promptCity(to, ctx)
      break

    case 'sport':
      if (action === 'sport' && payload) next = await promptVenue(to, { ...ctx, sport: payload })
      else next = await promptSport(to, ctx)
      break

    case 'venue':
      if (action === 'venue' && payload) {
        const v = (ctx.venueNames || {})[payload] || {}
        next = await promptDate(to, { ...ctx, listingId: payload, listingName: v.name || 'the venue', locationName: v.loc || '', venueNames: undefined })
      } else next = await promptVenue(to, ctx)
      break

    case 'date':
      if (action === 'date' && payload === 'more') next = await promptMoreDates(to, ctx)
      else if (action === 'date' && payload === 'today') next = await promptSlots(to, ctx, todayStr())
      else if (action === 'date' && payload === 'tomorrow') next = await promptSlots(to, ctx, addDays(todayStr(), 1))
      else if (action === 'date' && /^\d{4}-\d{2}-\d{2}$/.test(payload)) next = await promptSlots(to, ctx, payload)
      else next = await promptDate(to, ctx)
      break

    case 'slot':
      if (action === 'slot' && payload && ctx.listingId && ctx.date) next = await chooseSlot(to, ctx, payload)
      else next = await promptSlots(to, ctx, ctx.date)
      break

    case 'confirm':
      if (action === 'confirm' && payload === 'yes') {
        // We don't reserve during the chat, so re-check availability at the LAST moment — the
        // slot may have been booked or held by someone else since we showed it. Only hand off
        // the checkout link if it's genuinely still free.
        const stillFree = (await availableSlots(ctx.listingId, ctx.date))
          .some((s) => String(s.start).slice(0, 5) === String(ctx.slotStart).slice(0, 5))
        if (!stillFree) {
          await sendText(to, `😕 Sorry — ${fmt12(ctx.slotStart)}–${fmt12(ctx.slotEnd)} at ${ctx.listingName} on ${humanDate(ctx.date)} was just taken or is being booked by someone else.\n\nIf it was only being held, it may free up within ~10 minutes. Here are the times open right now 👇`)
          next = await promptSlots(to, ctx, ctx.date)
        } else {
          const link = `${SITE_BASE}/listings/${ctx.listingId}?d=${ctx.date}&t=${encodeURIComponent(ctx.slotStart)}`
          await sendText(to, `You're almost done! 🎉\n\nTap to review & pay — this reserves your slot at checkout:\n${link}\n\nType "menu" to book another.`)
          next = { step: 'done', context: {} }
        }
      } else if (action === 'confirm' && payload === 'no') {
        await sendText(to, 'No problem — cancelled.')
        next = await promptMenu(to)
      } else if (action === 'slot' && payload && ctx.listingId && ctx.date) {
        // User re-tapped a different time from the still-visible slot list — honour it (don't
        // silently confirm the original slot).
        next = await chooseSlot(to, ctx, payload)
      } else next = await promptConfirm(to, ctx)
      break

    case 'done':
    case 'start':
    default:
      next = await promptMenu(to)
      break
  }

  await saveSession(to, next.step, next.context)
}

// ── HTTP handler ────────────────────────────────────────────────────────────────
Deno.serve(async (req) => {
  const url = new URL(req.url)

  // 1. Verification handshake
  if (req.method === 'GET') {
    const mode = url.searchParams.get('hub.mode')
    const token = url.searchParams.get('hub.verify_token')
    const challenge = url.searchParams.get('hub.challenge')
    if (mode === 'subscribe' && token && token === (await getVerifyToken())) {
      return new Response(challenge ?? '', { status: 200, headers: { 'Content-Type': 'text/plain' } })
    }
    return new Response('Forbidden', { status: 403 })
  }

  // 2. Event delivery
  if (req.method === 'POST') {
    const raw = await req.text()
    // Reject anything not signed by Meta (when the app secret is configured). Without this the
    // public endpoint would let a forged POST drive DB writes + outbound sends off our number.
    if (!(await validSignature(raw, req.headers.get('x-hub-signature-256')))) {
      return new Response('Forbidden', { status: 403 })
    }
    let payload: any = null
    try { payload = JSON.parse(raw) } catch { /* ignore malformed body */ }

    const rows: any[] = []
    const inboundMsgs: { from: string; m: any; phoneId: string | null }[] = []
    for (const entry of payload?.entry ?? []) {
      for (const change of entry?.changes ?? []) {
        const v = change?.value ?? {}
        const phoneNumberId = v?.metadata?.phone_number_id ?? null
        const nameByWaId: Record<string, string> = {}
        for (const c of v?.contacts ?? []) if (c?.wa_id) nameByWaId[c.wa_id] = c?.profile?.name ?? ''

        for (const m of v?.messages ?? []) {
          rows.push({
            kind: 'message', wa_message_id: m?.id ?? null, from_number: m?.from ?? null,
            from_name: m?.from ? (nameByWaId[m.from] ?? null) : null, phone_number_id: phoneNumberId,
            message_type: m?.type ?? null, body: messageBody(m), raw: change,
          })
          if (m?.from) inboundMsgs.push({ from: m.from, m, phoneId: phoneNumberId })
        }
        for (const s of v?.statuses ?? []) {
          rows.push({
            kind: 'status', wa_message_id: s?.id ?? null, from_number: s?.recipient_id ?? null,
            phone_number_id: phoneNumberId, status: s?.status ?? null, raw: change,
          })
        }
      }
    }

    if (rows.length) {
      // Bound the write so an oversized payload can't blow up the table in one call.
      const { error } = await supabase.from('whatsapp_inbound').insert(rows.slice(0, MAX_ROWS))
      if (error) console.error('whatsapp_inbound insert failed:', error.message)
    }

    // Sync delivery/read receipts onto our outbound log — monotonic (sent<delivered<read).
    const RANK: Record<string, number> = { sent: 1, delivered: 2, read: 3 }
    for (const r of rows) {
      if (r.kind !== 'status' || !r.wa_message_id || !r.status) continue
      let q = supabase.from('whatsapp_outbound').update({ status: r.status }).eq('wa_message_id', r.wa_message_id)
      const newRank = RANK[r.status as string]
      if (newRank) {
        const atOrAbove = Object.keys(RANK).filter((s) => RANK[s] >= newRank)
        q = q.not('status', 'in', `(${atOrAbove.join(',')})`)
      }
      const { error } = await q
      if (error) console.error('outbound status sync failed:', error.message)
    }

    // 3. Run the booking bot for inbound messages to BOOKBOLL's own number only.
    if (inboundMsgs.length && (await getToken())) {
      try {
        const { data } = await supabase.from('platform_settings').select('value').eq('key', 'whatsapp_config').maybeSingle()
        let cfg: any = {}
        try { cfg = data?.value ? JSON.parse(data.value) : {} } catch { cfg = {} }
        PLATFORM_PHONE_ID = String(cfg.phone_number_id || '')
        SITE_BASE = String(cfg.booking_base_url || 'https://bookboll.com').replace(/\/$/, '')   // website nudges + checkout link
        if (PLATFORM_PHONE_ID) {
          for (const im of inboundMsgs.slice(0, MAX_ROWS)) {
            // Only Bookboll's number drives the bot. Require an EXACT match (a forged payload
            // that omits metadata.phone_number_id yields null → not Bookboll → skipped).
            if (im.phoneId !== PLATFORM_PHONE_ID) continue
            await runBot(im.from, im.m)
          }
        }
      } catch (e) { console.error('bot run failed:', (e as Error).message) }
    }

    return new Response('EVENT_RECEIVED', { status: 200 })
  }

  return new Response('Method Not Allowed', { status: 405 })
})
