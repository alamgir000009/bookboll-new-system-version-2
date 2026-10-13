/**
 * notifications.js — a lightweight, DERIVED notification feed for the customer site.
 *
 * No new table: notifications are computed from `game_join_requests` + `hosted_games`.
 *   • HOST side   — someone has a PENDING request to join a game/tournament I host.
 *   • JOINER side — one of MY requests was accepted or rejected.
 *
 * "Unread" is tracked client-side with a localStorage snapshot of the status we last
 * acknowledged per request id (`bb_game_notif_seen`). A request is unread when its
 * current status differs from what we last saw — so a brand-new pending request, or a
 * request that just flipped to accepted/rejected, both surface as unread. markAllSeen()
 * snapshots the current statuses, clearing the badge.
 */
import { ref } from 'vue'
import { loadNotificationFeed } from './apiGames.js'
import { myCommunities, loadPendingMembers } from './community.js'

const SEEN_KEY = 'bb_game_notif_seen'

export const notifications = ref([])   // [{ id, kind, text, link, status, ts, unread }]
export const unreadCount = ref(0)

function readSeen() { try { return JSON.parse(localStorage.getItem(SEEN_KEY) || '{}') } catch { return {} } }
function writeSeen(map) { localStorage.setItem(SEEN_KEY, JSON.stringify(map)) }

function linkFor(game) {
  if (!game) return '/games'
  return game.game_type === 'tournament' ? `/tournaments/${game.id}` : `/games/${game.id}`
}
// Host notifications about people joining go straight to the requests page (where the
// host accepts/declines), not the event page — so they don't have to hunt for it.
function requestsLinkFor(game) { return game ? `${linkFor(game)}/requests` : '/my-games' }
function gameLabel(game) { return game?.title || game?.sport || (game?.game_type === 'tournament' ? 'your tournament' : 'your game') }

/**
 * Recompute the feed for the signed-in customer. Resilient: if the games tables
 * aren't there yet it simply yields an empty feed (no errors).
 */
export async function loadNotifications(customerId) {
  if (!customerId) { notifications.value = []; unreadCount.value = 0; return }
  const seen = readSeen()
  const items = []

  // ── HOST: pending requests on games/tournaments I host ──
  // Pull moderation fields too (with a pre-migration fallback) so we can also tell
  // the host when Bookboll has taken one of their events down.
  const feed = await loadNotificationFeed(customerId)
  const mine = { error: !feed, data: feed?.hostedGames || [] }
  const today = new Date(); const ymd = d => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
  const TODAY = ymd(today); const TMRW = ymd(new Date(today.getTime() + 864e5))
  if (!mine.error) {
    const games = mine.data || []
    const byId = Object.fromEntries(games.map(g => [g.id, g]))
    const ids = games.map(g => g.id)

    // Events a Bookboll admin removed → surface a clear notice (with the reason).
    for (const g of games) {
      if ((g.moderation_status || 'active') === 'removed') {
        items.push({
          id: 'm_' + g.id, kind: 'mod', reqId: 'mod_' + g.id, status: g.moderation_status,
          text: `Your event “${gameLabel(g)}” was removed by Bookboll${g.moderation_reason ? ': ' + g.moderation_reason : '.'}`,
          link: '/my-games', ts: g.moderated_at || new Date().toISOString(),
          unread: seen['mod_' + g.id] !== g.moderation_status,
        })
      }
      // HOST: your event starts today/tomorrow — final heads-up.
      if (g.status === 'open' && (g.game_date === TODAY || g.game_date === TMRW)) {
        items.push({
          id: 'hs_' + g.id, kind: 'soon', reqId: 'hsoon_' + g.id, status: g.game_date,
          text: `Your event “${gameLabel(g)}” starts ${g.game_date === TODAY ? 'today' : 'tomorrow'} — check who's coming`,
          link: linkFor(g), ts: `${g.game_date}T00:00:00`, unread: seen['hsoon_' + g.id] !== g.game_date,
        })
      }
    }
    if (ids.length) {
      // Pending requests (review events) AND accepted joins on INSTANT events —
      // an instant join never passes through 'pending', so the host would
      // otherwise never hear that someone got in.
      const rq = { data: (feed?.hostRequests || []).filter(r => ids.includes(r.game_id)) }
      for (const r of (rq.data || [])) {
        const g = byId[r.game_id]
        if (r.status === 'pending') {
          items.push({
            id: 'h_' + r.id, kind: 'host', reqId: r.id, status: 'pending',
            text: `${r.joiner_name || 'Someone'} wants to join “${gameLabel(g)}”`,
            link: requestsLinkFor(g), ts: r.created_at, unread: seen[r.id] !== 'pending',
          })
        } else if ((g?.approval_mode || 'review') === 'instant') {
          items.push({
            id: 'hj_' + r.id, kind: 'joined', reqId: 'hj_' + r.id, status: 'accepted',
            text: `${r.joiner_name || 'Someone'} joined “${gameLabel(g)}”`,
            link: requestsLinkFor(g), ts: r.created_at, unread: seen['hj_' + r.id] !== 'accepted',
          })
        }
      }
    }
  }

  // ── HOST: a tournament game's time has passed with no result recorded ──
  // One item per finished-but-undecided match; disappears once the winner is set.
  try {
    // Comes with the feed above — the on-login bracket prompt fetches it separately.
    for (const d of (feed?.dueMatches || [])) {
      items.push({
        id: 'r_' + d.match.id, kind: 'result', reqId: 'res_' + d.match.id, status: 'due',
        text: `“${d.match.home_team}” vs “${d.match.away_team}” in “${gameLabel(d.tournament)}” has finished — record the result to advance the bracket`,
        link: `/tournaments/${d.tournament.id}`,
        ts: `${d.match.match_date}T${(d.match.end_time || '23:59').slice(0, 5)}:00`,
        unread: seen['res_' + d.match.id] !== 'due',
      })
    }
  } catch { /* tournaments table not migrated yet */ }

  // ── COMMUNITIES: my membership got approved · people waiting on clubs I manage ──
  try {
    const mine = await myCommunities(customerId)
    if (mine.length) {
      const cById = Object.fromEntries(mine.map((c) => [c.id, c]))
      for (const c of mine) {
        const m = c.membership
        if (m?.status === 'accepted' && c.is_private && m.role === 'member') {
          items.push({
            id: 'cma_' + m.id, kind: 'joiner', reqId: 'cma_' + m.id, status: 'accepted',
            text: `You're in — welcome to “${c.name}”`,
            link: `/communities/${c.id}`, ts: m.joined_at, unread: seen['cma_' + m.id] !== 'accepted',
          })
        }
      }
      // One call covers every community I run — asking per community meant a
      // request each on every page load.
      for (const p of await loadPendingMembers()) {
        if (p.customer_id === customerId) continue
        items.push({
          id: 'cmp_' + p.id, kind: 'host', reqId: 'cmp_' + p.id, status: 'pending',
          text: `Someone wants to join “${p.community_name || cById[p.community_id]?.name || 'your community'}”`,
          link: `/communities/${p.community_id}`, ts: p.joined_at, unread: seen['cmp_' + p.id] !== 'pending',
        })
      }
    }
  } catch { /* communities API unavailable */ }

  // ── JOINER: my own requests — decisions, cancellations, reminders, results ──
  const mineReqs = { error: !feed, data: feed?.myRequests || [] }
  if (!mineReqs.error) {
    const reqs = mineReqs.data || []
    const gids = [...new Set(reqs.map(r => r.game_id))]
    let games = []
    if (gids.length) games = (feed?.myGames || []).filter(g => gids.includes(g.id))
    const byId = Object.fromEntries(games.map(g => [g.id, g]))
    const myReqIds = new Set(reqs.map(r => r.id))

    for (const r of reqs) {
      const g = byId[r.game_id]
      if (['accepted', 'rejected'].includes(r.status)) {
        items.push({
          id: 'j_' + r.id, kind: 'joiner', reqId: r.id, status: r.status,
          text: r.status === 'accepted' ? `You're in for “${gameLabel(g)}”` : `Your request for “${gameLabel(g)}” wasn't accepted`,
          link: linkFor(g), ts: r.created_at, unread: seen[r.id] !== r.status,
        })
      }
      // Event I joined (or asked to) was CANCELLED by the host.
      if (g?.status === 'cancelled' && r.status !== 'rejected') {
        items.push({
          id: 'cx_' + g.id + '_' + r.id, kind: 'cancelled', reqId: 'cx_' + g.id, status: 'cancelled',
          text: `“${gameLabel(g)}” was cancelled by the host`,
          link: '/games', ts: r.created_at, unread: seen['cx_' + g.id] !== 'cancelled',
        })
      }
      // I'm in and it starts today/tomorrow — reminder.
      if (r.status === 'accepted' && g?.status === 'open' && (g.game_date === TODAY || g.game_date === TMRW)) {
        items.push({
          id: 'js_' + r.id, kind: 'soon', reqId: 'soon_' + r.id, status: g.game_date,
          text: `“${gameLabel(g)}” starts ${g.game_date === TODAY ? 'today' : 'tomorrow'} — see the details & directions`,
          link: linkFor(g), ts: `${g.game_date}T00:00:00`, unread: seen['soon_' + r.id] !== g.game_date,
        })
      }
    }

    // TOURNAMENT results for MY team: won → advance/champion; lost → knocked out.
    const tourGids = games.filter(g => g.game_type === 'tournament').map(g => g.id)
    if (tourGids.length) {
      try {
        const ms = (feed?.matches || []).filter(m => tourGids.includes(m.tournament_id))
        for (const m of (ms || [])) {
          const iAmHome = myReqIds.has(m.home_request_id), iAmAway = myReqIds.has(m.away_request_id)
          if (!iAmHome && !iAmAway) continue
          const g = byId[m.tournament_id]
          const totalRounds = Math.max(1, Math.ceil(Math.log2(Math.max(2, g?.num_teams || 2))))
          const isFinal = m.round === totalRounds - 1
          const won = (m.winner === 'home' && iAmHome) || (m.winner === 'away' && iAmAway)
          items.push({
            id: 'mr_' + m.id, kind: 'result', reqId: 'mres_' + m.id, status: m.winner,
            text: won
              ? (isFinal ? `🏆 You WON “${gameLabel(g)}” — champions!` : `You won your match in “${gameLabel(g)}” — you advance!`)
              : `You were knocked out of “${gameLabel(g)}” — good game`,
            link: linkFor(g), ts: m.match_date ? `${m.match_date}T23:00:00` : new Date().toISOString(),
            unread: seen['mres_' + m.id] !== m.winner,
          })
        }
      } catch { /* tournaments table optional */ }
    }
  }

  items.sort((a, b) => new Date(b.ts || 0) - new Date(a.ts || 0))
  notifications.value = items
  unreadCount.value = items.filter(i => i.unread).length
}

// Acknowledge everything currently shown → badge clears, items stay readable.
export function markAllSeen() {
  const seen = readSeen()
  for (const n of notifications.value) seen[n.reqId] = n.status
  writeSeen(seen)
  notifications.value = notifications.value.map(n => ({ ...n, unread: false }))
  unreadCount.value = 0
}

export function clearNotifications() { notifications.value = []; unreadCount.value = 0 }
