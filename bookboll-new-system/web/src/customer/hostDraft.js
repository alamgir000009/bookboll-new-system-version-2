/**
 * hostDraft.js — keeps an in-progress "Host" draft so it survives
 * (a) navigating to a court's full listing page (to browse it AND pick the date/time
 * there, reusing the normal booking selection) and back, and (b) a HARD RELOAD —
 * the draft is mirrored to sessionStorage so an accidental refresh or a mobile
 * browser evicting the tab doesn't wipe a half-built event. sessionStorage (not
 * localStorage) on purpose: a draft dies with the browsing session instead of
 * resurrecting weeks later.
 *
 * GAME flow: HostGame "Location & Schedule" step → open a listing page (?host=game) →
 * pick a date + time slots like a normal booking → "Use this court & time" stashes the
 * court + date + slots here and returns to the wizard's Contact step.
 *
 * TOURNAMENT flow: HostGame "Schedule" step shows a bracket chart. Tapping a game card →
 * browse courts → open a listing page (?host=tournament) → pick the date + match block →
 * "Use this court & time" stashes it here. Because the wizard unmounts on navigation, the
 * WHOLE tournament draft (teams, match length, every already-scheduled game) is persisted
 * here too, plus which match (matchRound/matchIndex) is being scheduled, so it can be
 * rebuilt and the new pick slotted into the right game on return.
 *
 * 'event' (hangout) uses the same shape as 'game'.
 */
import { reactive } from 'vue'

const STORE_KEY = 'bb_host_draft'

const EMPTY = {
  active: false,         // is there a draft to restore?
  type: '',              // 'game' | 'event' | 'tournament'
  basics: null,          // a shallow copy of the wizard's basics form
  step: 0,               // wizard step at autosave time (0 = unknown → restore picks)
  // chosen court + date + time (set by the listing page's "Use this court & time")
  selectedCourtId: '',   // chosen listing id
  gameDate: '',          // chosen date (YYYY-MM-DD)
  gameSlots: null,       // chosen slots [{ start_time, end_time, price }] (one contiguous window)
  pickHoldExpiresAt: 0,  // epoch ms the just-picked slots are held until (0 = not held / degraded)
  // tournament-only: the full draft so the bracket can be rebuilt after navigation
  numTeams: 0,
  matchHours: 1,
  games: null,           // flat [{ round, index, location_id, listing_id, owned, date, slot, venueName, courtName }]
  matchRound: null,      // which match is being scheduled (round)
  matchIndex: null,      // which match is being scheduled (index in round)
  communityId: '',       // hosting FOR a community (?community=) — stamped on publish
}

function readStored() {
  try {
    const s = JSON.parse(sessionStorage.getItem(STORE_KEY) || 'null')
    return s && s.active ? s : null
  } catch { return null }
}

export const hostDraft = reactive({ ...EMPTY, ...(readStored() || {}) })

function persist() {
  try {
    if (hostDraft.active) sessionStorage.setItem(STORE_KEY, JSON.stringify(hostDraft))
    else sessionStorage.removeItem(STORE_KEY)
  } catch { /* storage full/blocked — module state still works for SPA nav */ }
}

export function saveHostDraft(patch) { Object.assign(hostDraft, patch, { active: true }); persist() }
export function clearHostDraft() { Object.assign(hostDraft, EMPTY); persist() }
