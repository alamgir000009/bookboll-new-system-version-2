/**
 * matchmaking.js — PURE round generators for live meetups (zero deps, no I/O).
 * The organizer taps "Generate round" and gets court assignments for the
 * accepted players; tapping a winner per court feeds the live standings.
 *
 *   • round_robin — everyone mixes: repeat opponents (and repeat partners in
 *     doubles) are minimized greedily; sit-outs rotate fairly.
 *   • swiss       — winners face winners: standings (by wins) pair adjacent,
 *     avoiding rematches when possible.
 *   • king_court  — winners move UP a court, losers move DOWN (court 1 = top).
 *
 * Rounds document (jsonb on hosted_games.rounds):
 *   { teamSize, current, rounds: [
 *       { n, courts: [{ court, home: [ids], away: [ids], winner: ''|'home'|'away', rated: false }], sitOut: [ids] } ] }
 * Deterministic — same inputs, same round (testable, and a re-tap can't shuffle).
 */

export const PLAY_FORMATS = {
  round_robin: { label: 'Round robin', hint: 'Everyone mixes — new partners & opponents each round' },
  swiss: { label: 'Swiss', hint: 'Winners face winners — courts balance out by results' },
  king_court: { label: 'King of the court', hint: 'Win to move up a court, lose to move down' },
}

// ── Shared helpers ──────────────────────────────────────────────────────────────
const byId = (players) => Object.fromEntries(players.map(p => [p.id, p]))
const ratingOf = (p) => Number(p?.rating) || 1200

/** Per-player wins/draws/losses across recorded rounds. */
export function standings(roundsDoc, players) {
  const rows = Object.fromEntries(players.map(p => [p.id, { id: p.id, name: p.name, rating: ratingOf(p), wins: 0, draws: 0, losses: 0, played: 0 }]))
  for (const rd of (roundsDoc?.rounds || [])) {
    for (const c of (rd.courts || [])) {
      if (!c.winner) continue
      if (c.winner === 'draw') {
        for (const id of [...c.home, ...c.away]) if (rows[id]) { rows[id].draws++; rows[id].played++ }
        continue
      }
      const win = c.winner === 'home' ? c.home : c.away
      const lose = c.winner === 'home' ? c.away : c.home
      for (const id of win) if (rows[id]) { rows[id].wins++; rows[id].played++ }
      for (const id of lose) if (rows[id]) { rows[id].losses++; rows[id].played++ }
    }
  }
  return Object.values(rows).sort((a, b) => b.wins - a.wins || b.draws - a.draws || a.losses - b.losses || b.rating - a.rating || a.name.localeCompare(b.name))
}

// How often each pair has been OPPONENTS / PARTNERS so far.
function pairCounts(roundsDoc) {
  const opp = {}, mate = {}
  const bump = (m, a, b) => { const k = a < b ? a + '|' + b : b + '|' + a; m[k] = (m[k] || 0) + 1 }
  for (const rd of (roundsDoc?.rounds || [])) {
    for (const c of (rd.courts || [])) {
      for (const a of c.home) for (const b of c.away) bump(opp, a, b)
      for (let i = 0; i < c.home.length; i++) for (let j = i + 1; j < c.home.length; j++) bump(mate, c.home[i], c.home[j])
      for (let i = 0; i < c.away.length; i++) for (let j = i + 1; j < c.away.length; j++) bump(mate, c.away[i], c.away[j])
    }
  }
  const get = (m) => (a, b) => m[a < b ? a + '|' + b : b + '|' + a] || 0
  return { opp: get(opp), mate: get(mate) }
}

// Times each player has sat out so far (for fair rotation).
function sitCounts(roundsDoc) {
  const n = {}
  for (const rd of (roundsDoc?.rounds || [])) for (const id of (rd.sitOut || [])) n[id] = (n[id] || 0) + 1
  return (id) => n[id] || 0
}

/** Who plays this round: capacity = courts × teamSize × 2; extras rotate out fairly. */
function pickActive(players, courts, teamSize, roundsDoc) {
  const cap = courts * teamSize * 2
  if (players.length <= cap) return { active: [...players], sitOut: [] }
  const sat = sitCounts(roundsDoc)
  // Those who have sat out the LEAST sit out now (rotation); stable by join order.
  const sorted = [...players].sort((a, b) => sat(a.id) - sat(b.id))
  const sitters = sorted.slice(0, players.length - cap)
  const sitIds = new Set(sitters.map(p => p.id))
  return { active: players.filter(p => !sitIds.has(p.id)), sitOut: sitters.map(p => p.id) }
}

// Split one court's players (teamSize*2) into two rating-balanced teams,
// preferring partners who haven't teamed up yet (doubles).
function splitTeams(group, teamSize, mate) {
  if (teamSize === 1) return { home: [group[0].id], away: [group[1].id] }
  // Doubles: sort by rating, then pick the pairing of {best+worst} vs {middle}
  // unless it repeats partners more than the alternative.
  const s = [...group].sort((a, b) => ratingOf(b) - ratingOf(a))
  const optA = { home: [s[0], s[3]], away: [s[1], s[2]] }   // balanced by rating
  const optB = { home: [s[0], s[2]], away: [s[1], s[3]] }
  const repeats = o => mate(o.home[0].id, o.home[1].id) + mate(o.away[0].id, o.away[1].id)
  const pick = repeats(optB) < repeats(optA) ? optB : optA
  return { home: pick.home.map(p => p.id), away: pick.away.map(p => p.id) }
}

const mkCourt = (court, home, away) => ({ court, home, away, winner: '', rated: false })

// ── Generators (one round each) ─────────────────────────────────────────────────
export function buildRoundRobin(players, courts, teamSize, roundsDoc) {
  const { active, sitOut } = pickActive(players, courts, teamSize, roundsDoc)
  const { opp, mate } = pairCounts(roundsDoc)
  const per = teamSize * 2
  // Greedy grouping: seed each court with the least-played pairing available.
  const pool = [...active].sort((a, b) => ratingOf(b) - ratingOf(a))
  const groups = []
  while (pool.length >= per && groups.length < courts) {
    const first = pool.shift()
    const group = [first]
    while (group.length < per) {
      // pick the pool player with the fewest prior meetings with the group
      let best = 0, bestScore = Infinity
      for (let i = 0; i < pool.length; i++) {
        const score = group.reduce((n, g) => n + opp(g.id, pool[i].id) + mate(g.id, pool[i].id), 0)
        if (score < bestScore) { bestScore = score; best = i }
      }
      group.push(pool.splice(best, 1)[0])
    }
    groups.push(group)
  }
  for (const p of pool) sitOut.push(p.id)   // leftovers (players % per) sit out too
  return {
    courts: groups.map((g, i) => { const t = splitTeams(g, teamSize, mate); return mkCourt(i + 1, t.home, t.away) }),
    sitOut,
  }
}

export function buildSwiss(players, courts, teamSize, roundsDoc) {
  const { active, sitOut } = pickActive(players, courts, teamSize, roundsDoc)
  const { mate } = pairCounts(roundsDoc)
  const table = standings(roundsDoc, active)   // wins desc → adjacent pairing
  const ordered = table.map(r => active.find(p => p.id === r.id)).filter(Boolean)
  const per = teamSize * 2
  const groups = []
  while (ordered.length >= per && groups.length < courts) groups.push(ordered.splice(0, per))
  for (const p of ordered) sitOut.push(p.id)
  return {
    courts: groups.map((g, i) => { const t = splitTeams(g, teamSize, mate); return mkCourt(i + 1, t.home, t.away) }),
    sitOut,
  }
}

export function buildKingOfCourt(players, courts, teamSize, roundsDoc) {
  const { active, sitOut } = pickActive(players, courts, teamSize, roundsDoc)
  const { mate } = pairCounts(roundsDoc)
  const per = teamSize * 2
  const prev = (roundsDoc?.rounds || [])[roundsDoc?.rounds?.length - 1]
  const pos = {}   // id → target court (1 = top)
  if (prev) {
    for (const c of (prev.courts || [])) {
      const winners = c.winner === 'home' ? c.home : c.winner === 'away' ? c.away : []
      const losers = c.winner === 'home' ? c.away : c.winner === 'away' ? c.home : []
      for (const id of winners) pos[id] = Math.max(1, c.court - 1)          // move up
      for (const id of losers) pos[id] = Math.min(courts, c.court + 1)      // move down
      if (!winners.length) for (const id of [...c.home, ...c.away]) pos[id] = c.court   // undecided/draw → stay
    }
  }
  // Order: assigned court asc, then rating desc (round 1 = pure rating seeding;
  // returning sit-outs slot in at the bottom).
  const ordered = [...active].sort((a, b) =>
    (pos[a.id] || courts + 1) - (pos[b.id] || courts + 1) || ratingOf(b) - ratingOf(a))
  const groups = []
  while (ordered.length >= per && groups.length < courts) groups.push(ordered.splice(0, per))
  for (const p of ordered) sitOut.push(p.id)
  return {
    courts: groups.map((g, i) => { const t = splitTeams(g, teamSize, mate); return mkCourt(i + 1, t.home, t.away) }),
    sitOut,
  }
}

/** Dispatcher: append the next round to the document (returns a NEW doc). */
export function nextRound(roundsDoc, players, { format, courts, teamSize }) {
  const doc = roundsDoc && Array.isArray(roundsDoc.rounds)
    ? { teamSize, current: roundsDoc.current || 0, rounds: [...roundsDoc.rounds] }
    : { teamSize, current: 0, rounds: [] }
  const gen = format === 'swiss' ? buildSwiss : format === 'king_court' ? buildKingOfCourt : buildRoundRobin
  const r = gen(players, Math.max(1, courts || 1), Math.max(1, teamSize || 1), doc)
  if (!r.courts.length) return { doc, error: `Not enough players for a ${teamSize > 1 ? 'doubles' : 'singles'} round — need at least ${teamSize * 2}.` }
  doc.rounds.push({ n: doc.rounds.length + 1, courts: r.courts, sitOut: r.sitOut })
  doc.current = doc.rounds.length
  return { doc }
}
