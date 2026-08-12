/**
 * eventNotify.js — WhatsApp notifications for the EVENTS module, sent from
 * BOOKBOLL's own number at the moment something happens (a request arrives,
 * a decision is made, a bracket advances, an event is cancelled), each with
 * an actionable link back to the exact page where the recipient acts.
 *
 * FIRE-AND-FORGET by design: a WhatsApp hiccup must never break the user's
 * action, so every send is awaited nowhere, swallows errors, and skips
 * silently when the number is missing or the channel isn't configured.
 *
 * Delivery strategy (Meta rules): a free-form text (send_text) only delivers
 * inside the 24-hour window after the recipient last messaged Bookboll. So we
 * try send_text first and, if it fails, fall back to the utility template
 * `bookboll_event_update` (create it once in WhatsApp → Message templates:
 * category UTILITY, body "{{1}}" and a second line "{{2}}" — message + link).
 * Until that template is approved, out-of-window sends simply no-op — the
 * in-app bell still covers every one of these moments.
 */
import { supabase } from '../lib/supabase.js'
import { appBaseUrl } from '../lib/appBase.js'
import { fmt12 } from '../lib/slots.js'

const EVENT_TEMPLATE = { name: 'bookboll_event_update', language: 'en_US' }

// Self-contained on purpose: lib/whatsapp.js drags admin-dashboard session libs into
// the bundle; the customer site only needs the function invoke + a number normalizer.
function normalizeWaNumber(phone) {
  let d = String(phone || '').replace(/\D/g, '')
  if (d.startsWith('00')) d = d.slice(2)
  return d.length >= 8 ? d : ''
}
async function invokeWhatsapp(payload) {
  try {
    const { data, error } = await supabase.functions.invoke('send-whatsapp', { body: payload })
    return error ? { ok: false, error: String(error.message || error) } : (data ?? { ok: false })
  } catch { return { ok: false } }
}

const siteBase = () => (appBaseUrl() || 'https://bookboll.com')
export const eventLink = (game) =>
  `${siteBase()}${game?.game_type === 'tournament' ? '/tournaments/' : '/games/'}${game?.id}`
// Host-facing "someone joined" links land on the requests page, where the host acts.
export const requestsLink = (game) => `${eventLink(game)}/requests`

const label = (g) => g?.title || g?.sport || 'your event'
const whenLine = (g) => {
  if (!g?.game_date) return ''
  const d = new Date(g.game_date + 'T00:00:00').toLocaleDateString('en-US', { weekday: 'short', day: 'numeric', month: 'short' })
  return g.start_time ? `${d} · ${fmt12(g.start_time)}` : d
}

/** Core sender: text first (24h window), template fallback (out-of-window). */
async function deliver(to, message, link) {
  const num = normalizeWaNumber(to)
  if (!num) return
  const text = link ? `${message}\n${link}` : message
  try {
    const res = await invokeWhatsapp({ action: 'send_text', ownerId: null, to: num, text })
    if (res?.ok) return
    await invokeWhatsapp({ action: 'send_template', ownerId: null, to: num, template: EVENT_TEMPLATE, variables: [message, link || siteBase()], bodyText: text })
  } catch { /* never let notifications break the action */ }
}
const fire = (to, message, link) => { deliver(to, message, link) }   // deliberately not awaited

// ── The moments ────────────────────────────────────────────────────────────────

/** Someone requested / instantly joined → tell the HOST, link to review. */
export function notifyHostNewJoiner(game, joinerName, { instant = false } = {}) {
  if (!game?.host_whatsapp) return
  const who = joinerName || 'Someone'
  const msg = instant
    ? `🎉 ${who} just joined “${label(game)}”${whenLine(game) ? ` (${whenLine(game)})` : ''}. See who's coming:`
    : `👋 ${who} wants to join “${label(game)}”. Review and approve or decline here:`
  fire(game.host_whatsapp, msg, requestsLink(game))
}

/** Host decided → tell the JOINER the outcome. */
export function notifyJoinerDecision(game, request, status) {
  if (!request?.joiner_whatsapp) return
  if (status === 'accepted') {
    fire(request.joiner_whatsapp,
      `✅ You're in! “${label(game)}”${whenLine(game) ? ` — ${whenLine(game)}` : ''}. Details, directions and the host's contact:`,
      eventLink(game))
  } else if (status === 'rejected') {
    fire(request.joiner_whatsapp,
      `Your spot request for “${label(game)}” wasn't accepted this time — plenty more events near you:`,
      `${siteBase()}/games`)
  }
}

/** Bracket advanced → tell both teams how their match went. */
export function notifyMatchResult(game, match, winnerSide, reqs = {}) {
  const winner = winnerSide === 'home' ? match.home_team : match.away_team
  const loser = winnerSide === 'home' ? match.away_team : match.home_team
  const winReq = winnerSide === 'home' ? reqs.home : reqs.away
  const loseReq = winnerSide === 'home' ? reqs.away : reqs.home
  if (winReq?.joiner_whatsapp) fire(winReq.joiner_whatsapp,
    `🏆 “${winner}” won and advances in “${label(game)}”! Check your next match:`, eventLink(game))
  if (loseReq?.joiner_whatsapp) fire(loseReq.joiner_whatsapp,
    `Good game — “${loser}” was knocked out of “${label(game)}”. Full bracket:`, eventLink(game))
}

/** Event cancelled → tell everyone who joined or asked to (capped). */
export function notifyEventCancelled(game, requests = []) {
  const seen = new Set()
  for (const r of requests.slice(0, 30)) {
    if (!['accepted', 'pending'].includes(r.status)) continue
    const num = normalizeWaNumber(r.joiner_whatsapp)
    if (!num || seen.has(num)) continue
    seen.add(num)
    fire(num, `❌ “${label(game)}”${whenLine(game) ? ` on ${whenLine(game)}` : ''} was cancelled by the host. Find another event:`, `${siteBase()}/games`)
  }
}
