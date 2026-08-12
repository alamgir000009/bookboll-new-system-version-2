<script setup>
/**
 * BracketUpdatePrompt — auto-popup for tournament HOSTS: when a scheduled game's
 * time has passed with no winner recorded, this modal opens on the next visit so
 * the bracket can be updated in two taps per game (no need to find the page).
 * Mounted globally in CustomerLayout; loads once per sign-in and re-checks after
 * each recorded result (a semi may become decidable right after its quarter).
 * "Later" snoozes for the browser session (per due-set, so NEW finished games
 * re-open it).
 */
import { ref, watch } from 'vue'
import { customer, isCustomer } from '../../lib/customerSession.js'
import { loadDueTournamentMatches, setMatchWinner } from '../../customer/data.js'
import { roundName } from '../../customer/bracket.js'
import { fmt12 } from '../../lib/slots.js'
import { Trophy, X, Check, Loader } from 'lucide-vue-next'

const SNOOZE_KEY = 'bb_bracket_prompt_snooze'
const due = ref([])          // [{ match, tournament, totalRounds }]
const open = ref(false)
const busyId = ref(null)
const err = ref('')
const doneCount = ref(0)

const setKey = list => list.map(d => d.match.id).sort().join(',')

async function check(auto = true) {
  if (!isCustomer.value) { due.value = []; open.value = false; return }
  const list = await loadDueTournamentMatches(customer.value.id)
  due.value = list
  if (!list.length) return
  if (auto && sessionStorage.getItem(SNOOZE_KEY) === setKey(list)) return   // snoozed this exact set
  open.value = true
}
watch(() => customer.value?.id, () => { doneCount.value = 0; check(true) }, { immediate: true })

async function pick(d, side) {
  if (busyId.value) return
  err.value = ''
  busyId.value = d.match.id
  const res = await setMatchWinner(d.tournament.id, d.match, side, d.totalRounds)
  busyId.value = null
  if (res.error) { err.value = res.error; return }
  doneCount.value++
  // Re-check: the winner may complete the pairing of ANOTHER already-passed match.
  const list = await loadDueTournamentMatches(customer.value.id)
  due.value = list
  if (!list.length) setTimeout(() => { open.value = false; doneCount.value = 0 }, 1200)
}

function later() {
  sessionStorage.setItem(SNOOZE_KEY, setKey(due.value))
  open.value = false
}

function dateLabel(d) {
  try { return new Date(d + 'T00:00:00').toLocaleDateString('en-US', { weekday: 'short', day: 'numeric', month: 'short' }) } catch { return d }
}
</script>

<template>
  <div v-if="open" class="bu-back" @click.self="later">
    <div class="bu" role="dialog" aria-modal="true" aria-label="Update your tournament bracket">
      <button type="button" class="bu-x" @click="later" aria-label="Close"><X :size="17" /></button>
      <div class="bu-head"><Trophy :size="18" /> {{ due.length ? 'How did it go?' : 'Bracket up to date!' }}</div>
      <p v-if="due.length" class="bu-sub">{{ due.length }} finished game{{ due.length === 1 ? '' : 's' }} need{{ due.length === 1 ? 's' : '' }} a result — tap the winner to advance your bracket.</p>
      <p v-else class="bu-sub bu-done"><Check :size="15" /> All results recorded — the bracket has advanced. Nice one!</p>

      <div v-for="d in due" :key="d.match.id" class="bu-card">
        <div class="bu-meta">
          <b>{{ d.tournament.title || d.tournament.sport }}</b>
          <span>{{ roundName(d.match.round, d.totalRounds) }} · {{ dateLabel(d.match.match_date) }}<template v-if="d.match.start_time"> · {{ fmt12(d.match.start_time) }}</template></span>
        </div>
        <div class="bu-teams">
          <button type="button" class="bu-team" :disabled="busyId === d.match.id" @click="pick(d, 'home')">
            <Loader v-if="busyId === d.match.id" :size="14" class="bu-spin" /><Trophy v-else :size="14" /> {{ d.match.home_team }}
          </button>
          <span class="bu-vs">vs</span>
          <button type="button" class="bu-team" :disabled="busyId === d.match.id" @click="pick(d, 'away')">
            <Loader v-if="busyId === d.match.id" :size="14" class="bu-spin" /><Trophy v-else :size="14" /> {{ d.match.away_team }}
          </button>
        </div>
      </div>

      <div v-if="err" class="bu-err">{{ err }}</div>
      <button v-if="due.length" type="button" class="bu-later" @click="later">I'll do it later</button>
    </div>
  </div>
</template>

<style scoped>
.bu-back { position: fixed; inset: 0; z-index: 320; background: rgba(15,23,42,.55); display: flex; align-items: center; justify-content: center; padding: 18px; overflow-y: auto; }
.bu { position: relative; width: 440px; max-width: 100%; max-height: 88vh; overflow-y: auto; background: var(--surface, #ffffff); border-radius: 18px; padding: 20px 20px 16px; box-shadow: 0 24px 60px rgba(15,23,42,.3); }
.bu-x { position: absolute; top: 12px; right: 12px; width: 32px; height: 32px; border-radius: 10px; border: none; background: #f1f5f9; color: var(--text-muted, #64748b); display: flex; align-items: center; justify-content: center; cursor: pointer; }
.bu-head { display: flex; align-items: center; gap: 8px; font-size: 17px; font-weight: 900; color: #0f172a; }
.bu-head svg { color: #e11d48; }
.bu-sub { font-size: 13px; color: var(--text-muted, #64748b); line-height: 1.45; margin: 6px 0 12px; }
.bu-done { display: flex; align-items: center; gap: 6px; color: #166534; font-weight: 700; }
.bu-card { border: 1.5px solid var(--border, #e2e8f0); border-radius: 14px; padding: 12px 13px; margin-bottom: 10px; }
.bu-meta { display: flex; flex-direction: column; gap: 2px; margin-bottom: 9px; }
.bu-meta b { font-size: 13.5px; color: #0f172a; }
.bu-meta span { font-size: 12px; color: var(--text-light, #94a3b8); font-weight: 600; }
.bu-teams { display: flex; align-items: center; gap: 8px; }
.bu-team { flex: 1; min-width: 0; display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 11px 8px; min-height: 44px; border: 1.5px solid var(--border, #e2e8f0); border-radius: 11px; background: var(--surface, #ffffff); font-size: 13px; font-weight: 800; color: #0f172a; cursor: pointer; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; transition: border-color .12s, background .12s; }
.bu-team:hover { border-color: #e11d48; background: #fff1f2; }
.bu-team:disabled { opacity: .6; cursor: default; }
.bu-team svg { flex-shrink: 0; color: #e11d48; }
.bu-vs { flex-shrink: 0; font-size: 11px; font-weight: 800; color: var(--text-light, #94a3b8); text-transform: uppercase; }
.bu-err { font-size: 12.5px; color: #b91c1c; background: #fee2e2; border-radius: 9px; padding: 8px 11px; margin-bottom: 10px; }
.bu-later { width: 100%; padding: 10px; border: none; background: none; color: var(--text-light, #94a3b8); font-size: 13px; font-weight: 700; cursor: pointer; }
.bu-later:hover { color: #475569; }
.bu-spin { animation: buspin 1s linear infinite; }
@keyframes buspin { to { transform: rotate(360deg); } }

/* Phones: bottom sheet */
@media (max-width: 600px) {
  .bu-back { align-items: flex-end; padding: 0; }
  .bu { width: 100%; border-radius: 18px 18px 0 0; padding-bottom: calc(16px + env(safe-area-inset-bottom, 0px)); }
}
</style>
