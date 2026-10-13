/**
 * bracket.js — pure single-elimination bracket math + small time helpers, shared by
 * the tournament creation wizard (HostGame) and the bracket view (TournamentDetail).
 *
 * Model: N teams (a power of two) play a knockout. Round 0 = first round with N/2
 * matches; each later round halves the match count down to a single Final. Every
 * match is one bookable SLOT on a court; the host books one court per round and the
 * round's matches are laid out as consecutive slots.
 */

import { fmt12 } from '../lib/slots'

// Clean power-of-two team counts → no byes, tidy brackets, easy for users to grasp.
export const TEAM_OPTIONS = [4, 8, 16, 32]

export function isValidTeamCount(n) { return TEAM_OPTIONS.includes(Number(n)) }

// Number of knockout rounds for N teams (8 → 3: QF, SF, Final).
export function roundsFor(teams) { return Math.max(1, Math.round(Math.log2(Number(teams) || 2))) }

// Matches in a given (0-based) round: round 0 has teams/2, then halves each round.
export function matchesInRound(teams, round) { return Math.max(1, (Number(teams) || 2) / 2 ** (round + 1)) }

// Total matches across the whole bracket (= teams − 1 for a power-of-two knockout).
export function totalGames(teams) { return Math.max(0, (Number(teams) || 0) - 1) }

// Friendly name for a round given how many rounds there are in total.
export function roundName(round, totalRounds) {
  const fromEnd = totalRounds - round   // 1 = Final, 2 = Semis, 3 = Quarters…
  if (fromEnd === 1) return 'Final'
  if (fromEnd === 2) return 'Semi-finals'
  if (fromEnd === 3) return 'Quarter-finals'
  return `Round of ${2 ** fromEnd}`
}

// The structure as rounds → matches. Each match knows where its winner advances to
// (next round, next match index, and whether it lands in the home or away slot).
export function buildBracket(teams) {
  const totalRounds = roundsFor(teams)
  const rounds = []
  for (let r = 0; r < totalRounds; r++) {
    const count = matchesInRound(teams, r)
    const matches = []
    for (let i = 0; i < count; i++) {
      const isFinal = r === totalRounds - 1
      matches.push({
        round: r,
        index: i,
        nextRound: isFinal ? null : r + 1,
        nextIndex: isFinal ? null : Math.floor(i / 2),
        nextSlot: isFinal ? null : (i % 2 === 0 ? 'home' : 'away'),
      })
    }
    rounds.push(matches)
  }
  return { teams: Number(teams), totalRounds, rounds }
}

// Where the winner of (round,index) goes. null at the Final.
export function nextMatchFor(round, index, totalRounds) {
  if (round >= totalRounds - 1) return null
  return { round: round + 1, index: Math.floor(index / 2), slot: index % 2 === 0 ? 'home' : 'away' }
}

// Standard sequential seeding for round 0: [t0 vs t1], [t2 vs t3], …
// Pass an array of team objects; returns [{ home, away }] per round-0 match.
export function seedPairs(teamList, teams) {
  const out = []
  for (let i = 0; i < matchesInRound(teams, 0); i++) {
    out.push({ home: teamList[i * 2] || null, away: teamList[i * 2 + 1] || null })
  }
  return out
}

// ── Time helpers (slot times are "HH:MM" or "HH:MM:SS") ────────────────────────
export function normTime(t) { return String(t || '').slice(0, 5) }
export function toMins(t) { const [h, m] = normTime(t).split(':').map(Number); return (h || 0) * 60 + (m || 0) }
export function fromMins(mins) {
  const m = ((mins % 1440) + 1440) % 1440
  return `${String(Math.floor(m / 60)).padStart(2, '0')}:${String(m % 60).padStart(2, '0')}`
}
export function addMinutes(t, mins) { return fromMins(toMins(t) + mins) }
export function fmtTime(t) {
  const [h] = normTime(t).split(':').map(Number)
  if (Number.isNaN(h)) return ''
  return fmt12(normTime(t))
}

/**
 * Lay out `count` consecutive slots starting at a chosen slot. Given the day's
 * available slots (from buildDaySlots) and the index of the starting slot, return
 * the `count` slots in order IF they are all consecutive and bookable; otherwise
 * return null (the host must pick a start with enough room after it).
 */
export function consecutiveSlots(daySlots, startIndex, count) {
  const picked = []
  let idx = startIndex
  let prevEnd = null
  while (picked.length < count && idx < daySlots.length) {
    const s = daySlots[idx]
    if (!s.available) return null
    if (prevEnd !== null && normTime(s.start_time) !== normTime(prevEnd)) return null  // a gap → not consecutive
    picked.push(s)
    prevEnd = s.end_time
    idx++
  }
  return picked.length === count ? picked : null
}
