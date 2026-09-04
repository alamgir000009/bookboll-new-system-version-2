<script setup>
import { ref, onMounted, watch, computed } from 'vue'
import { useRoute } from 'vue-router'
import { customer, isCustomer } from '../../lib/customerSession.js'
import { ensureSignedIn } from '../../lib/authGate.js'
import { pushToast } from '../../lib/toast.js'
import { loadTournament, requestToJoin, respondToRequest, generateMatchups, setMatchWinner, clearMatchWinner, tournamentsEnabled, probeTournaments, loadCountryIso } from '../../customer/apiGames.js'
import { getSavedPhone, saveCustomerPhone } from '../../customer/customerPhones.js'
import PhoneInput from '../components/PhoneInput.vue'
import JoinModal from '../components/JoinModal.vue'
import FormAnswers from '../../components/FormAnswers.vue'
import { useSeo, eventLd } from '../../lib/seo.js'
import { roundName, fmtTime, totalGames, nextMatchFor } from '../../customer/bracket.js'
import { ArrowLeft, ArrowRight, Trophy, Users, CalendarDays, MapPin, Clock, Check, X, Phone, ShieldCheck, Shuffle, Gift, RotateCcw, Lock } from 'lucide-vue-next'
import WhatsappIcon from '../../components/WhatsappIcon.vue'

const route = useRoute()
const data = ref(null)
const loading = ref(true)
const err = ref('')
const submitting = ref(false)
const working = ref(false)
const form = ref({ name: '', phone: '', whatsapp: '', message: '' })
const tIso = ref('')   // tournament's country ISO → phone dial-code default
const showJoin = ref(false)   // join form (+ waiver) shown as a modal
const joinInitial = computed(() => ({ name: form.value.name, whatsapp: form.value.whatsapp, phone: form.value.phone }))
// Mandatory: must have a Bookboll account before entering a team.
// Members-only community tournaments: publicly listed, but entering requires
// accepted community membership first (mirrors GameDetail's gate).
const memberGated = computed(() =>
  !!t.value?.community_id && t.value.community_public !== true && !isHost.value &&
  communityInfo.value?.membership?.status !== 'accepted')
const memberPending = computed(() => memberGated.value && communityInfo.value?.membership?.status === 'pending')
const joiningCommunity = ref(false)
async function joinCommunityFirst() {
  if (!isCustomer.value && !(await ensureSignedIn())) return
  if (!communityInfo.value) return
  joiningCommunity.value = true
  const { joinCommunity } = await import('../../customer/community.js')
  const res = await joinCommunity(communityInfo.value, customer.value.id)
  joiningCommunity.value = false
  if (res.error) { pushToast(res.error, 'error'); return }
  communityInfo.value = { ...communityInfo.value, membership: res.membership }
  if (res.membership?.status === 'accepted') pushToast(`You're in ${communityInfo.value.name} — enter your team now 🎉`)
  else pushToast('Request sent — the community admins will review it.')
}

async function openJoin() {
  if (!isCustomer.value && !(await ensureSignedIn())) return
  showJoin.value = true
}

const t = computed(() => data.value?.tournament || null)
// Community tournament? Load the club so the back link returns there.
const communityInfo = ref(null)
watch(() => [t.value?.community_id, customer.value?.id], async ([cid]) => {
  communityInfo.value = null
  if (!cid) return
  try { const { loadCommunity } = await import('../../customer/community.js'); communityInfo.value = await loadCommunity(cid, customer.value?.id || null) } catch { /* pre-migration */ }
}, { immediate: true })
const matches = computed(() => data.value?.matches || [])
const requests = computed(() => data.value?.requests || [])
const totalRounds = computed(() => data.value?.totalRounds || 0)

const isHost = computed(() => isCustomer.value && t.value && customer.value.id === t.value.host_customer_id)
const accepted = computed(() => requests.value.filter(r => r.status === 'accepted'))
const pending = computed(() => requests.value.filter(r => r.status === 'pending'))
const waitlisted = computed(() => requests.value.filter(r => r.status === 'waitlisted'))
const myReq = computed(() => isCustomer.value ? requests.value.find(r => r.joiner_customer_id === customer.value.id) || null : null)
// Instant registration + Stripe entry fees (host-chosen at creation, like games).
const instantJoin = computed(() => t.value?.approval_mode === 'instant')
const stripeEntries = computed(() => t.value?.charge?.ticketMode === 'stripe' && Number(t.value?.price_per_person) > 0)
const joinCardHint = computed(() => {
  if (instantJoin.value && stripeEntries.value) return "Enter your team — your bracket spot is confirmed instantly and the entry fee is charged online via the host's Stripe (simulated)."
  if (instantJoin.value) return 'Enter your team — your bracket spot is confirmed instantly. Entry fee is paid to the host on arrival.'
  if (stripeEntries.value) return 'Enter your team — the host confirms, then seeds the bracket. You pay the entry fee online only if accepted.'
  return 'Enter your team — the host confirms, then seeds the bracket. No payment now.'
})
const matchupsReady = computed(() => matches.value.some(m => m.round === 0 && (m.home_team || m.away_team)))

const byRound = computed(() => {
  const m = {}
  for (const mt of matches.value) (m[mt.round] ||= []).push(mt)
  return Object.keys(m).map(Number).sort((a, b) => a - b)
    .map(r => ({ round: r, name: roundName(r, totalRounds.value), matches: m[r].sort((a, b) => a.match_index - b.match_index) }))
})

/* ---- Mobile (≤760px) Google-style round-by-round bracket ---- */
const activeRound = ref(null)   // round index currently in focus on mobile
const activeIdx = computed(() => byRound.value.findIndex(c => c.round === activeRound.value))
const activeCol = computed(() => (activeIdx.value >= 0 ? byRound.value[activeIdx.value] : null))
const prevCol = computed(() => (activeIdx.value > 0 ? byRound.value[activeIdx.value - 1] : null))
// Default: first round with any undecided match; fall back to the last round.
// Re-validates after reloads (winner picks / re-generated matchups) without jumping around.
watch(byRound, (cols) => {
  if (!cols.length) { activeRound.value = null; return }
  if (activeRound.value !== null && cols.some(c => c.round === activeRound.value)) return
  const open = cols.find(c => c.matches.some(m => !m.winner))
  activeRound.value = (open || cols[cols.length - 1]).round
}, { immediate: true })
const slideDir = ref(1)   // 1 = forward (next round), -1 = back — drives the slide direction
function goRound(r) {
  const i = byRound.value.findIndex(c => c.round === r)
  slideDir.value = i >= activeIdx.value ? 1 : -1
  activeRound.value = r
}
function stepRound(dir) {
  const i = activeIdx.value + dir
  if (i >= 0 && i < byRound.value.length) { slideDir.value = dir; activeRound.value = byRound.value[i].round }
}
// Swipe via POINTER events (touch + pen + mouse-drag). No preventDefault / no pointer
// capture so taps and the buttons inside keep working; `touch-action: pan-y` on
// .td-mb-area lets the browser own vertical scroll while horizontal swipes reach us.
let swX = 0, swY = 0, swId = null
function onSwipeStart(e) { swId = e.pointerId; swX = e.clientX; swY = e.clientY }
function onSwipeEnd(e) {
  if (swId === null || e.pointerId !== swId) return
  swId = null
  const dx = e.clientX - swX, dy = e.clientY - swY
  if (Math.abs(dx) <= Math.abs(dy)) return   // vertical scroll gesture — ignore
  if (dx < -40) stepRound(1)
  else if (dx > 40) stepRound(-1)
}

function waLink(num) { return 'https://wa.me/' + String(num || '').replace(/[^\d]/g, '') }
function dlabel(d) { return d ? new Date(d + 'T00:00:00').toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' }) : '' }

useSeo(() => {
  const tv = t.value
  if (!tv) return { path: route.path, noindex: !loading.value }
  const name = tv.title || `${tv.sport || ''} tournament`.trim()
  const where = tv.custom_location_name || tv.locationName || ''
  const image = (Array.isArray(tv.photos) && tv.photos[0]) || tv.photo_url || ''
  return {
    title: `${name}${tv.game_date ? ' · ' + dlabel(tv.game_date) : ''}`,
    description: `${tv.description || `Enter "${name}"`}${where ? ' at ' + where : ''}${tv.game_date ? ' on ' + dlabel(tv.game_date) : ''}. ${Number(tv.prize_pot) ? tv.currency + ' ' + Number(tv.prize_pot).toLocaleString() + ' prize pot. ' : ''}Register your team on Bookboll.`,
    image,
    path: route.path,
    type: 'article',
    jsonLd: eventLd({
      name, description: tv.description, image,
      startDate: tv.game_date ? `${tv.game_date}T${(tv.start_time || '00:00')}` : undefined,
      locationName: where, city: tv.city_name,
      price: Number(tv.price_per_person) || undefined, currency: tv.currency,
      url: `https://bookboll.com${route.path}`,
    }),
  }
})

async function load() {
  loading.value = true; err.value = ''
  await probeTournaments()
  data.value = await loadTournament(route.params.id)
  tIso.value = await loadCountryIso(t.value?.country_id)
  if (isCustomer.value && data.value) {
    form.value.name = customer.value.full_name || ''
    const saved = getSavedPhone(t.value?.country_id)   // remembered for THIS tournament's country
    form.value.whatsapp = saved
    form.value.phone = saved
  }
  loading.value = false
}
onMounted(load)
watch(() => route.params.id, load)
watch(() => customer.value?.id, load)

// The JoinModal collects the form + waiver and emits the data here.
async function submitJoin(data) {
  err.value = ''
  submitting.value = true
  const res = await requestToJoin(t.value.id, {
    customerId: isCustomer.value ? customer.value.id : null,
    name: data.name, phone: data.phone, whatsapp: data.whatsapp || data.phone, message: data.message,
    form_answers: data.form_answers || {},
  })
  submitting.value = false
  if (res.error) { err.value = res.error; return }   // modal shows err, stays open
  showJoin.value = false
  saveCustomerPhone(t.value.country_id, data.whatsapp || data.phone)
  await load()
}
async function decide(req, status) {
  const { error } = await respondToRequest(req.id, status)
  if (error) { err.value = error; return }
  await load()
}
async function genMatchups() {
  working.value = true
  await generateMatchups(t.value.id, t.value.num_teams || t.value.max_players)
  working.value = false
  await load()
}
// The match that THIS match's winner feeds into (null at the Final), from the loaded set.
function nextMatchOf(m) {
  const nxt = nextMatchFor(m.round, m.match_index, totalRounds.value)
  if (!nxt) return null
  return matches.value.find(x => x.round === nxt.round && x.match_index === nxt.index) || null
}
// A decided match is "locked" once its winner has already played the next round — changing
// it then would corrupt the later result, so we make the host undo that first.
function nextLocked(m) { const n = nextMatchOf(m); return !!(n && n.winner) }
const bracketMsg = ref('')   // inline hint for winner-change guards

async function pickWinner(match, side) {
  if (!isHost.value) return
  const team = side === 'home' ? match.home_team : match.away_team
  if (!team) return
  if (match.winner === side) return                       // already the winner — nothing to do
  if (match.winner && nextLocked(match)) {                // trying to switch, but the next round is decided
    const n = nextMatchOf(match)
    bracketMsg.value = `Undo the ${roundName(n.round, totalRounds.value)} result first, then change this one.`
    return
  }
  bracketMsg.value = ''
  working.value = true
  await setMatchWinner(t.value.id, match, side, totalRounds.value)
  working.value = false
  await load()
}
// Undo a recorded result (wrong team tapped) → back to TBD so the host can re-pick.
async function undoWinner(match) {
  if (!isHost.value || !match.winner) return
  if (nextLocked(match)) {
    const n = nextMatchOf(match)
    bracketMsg.value = `That team already played their next match — undo the ${roundName(n.round, totalRounds.value)} result first.`
    return
  }
  working.value = true
  const res = await clearMatchWinner(t.value.id, match, totalRounds.value)
  working.value = false
  if (res?.error) { bracketMsg.value = res.error; return }
  bracketMsg.value = ''
  await load()
}

const statusMeta = {
  pending: { label: 'Pending — the host will confirm your team', cls: 'st-pending' },
  accepted: { label: "Your team's in! The host will reach out", cls: 'st-accepted' },
  rejected: { label: 'Not accepted this time', cls: 'st-rejected' },
}
</script>

<template>
  <div>
    <router-link :to="communityInfo ? `/communities/${communityInfo.id}` : '/games'" class="cback"><ArrowLeft :size="16" /> {{ communityInfo ? communityInfo.name : 'All events' }}</router-link>

    <div v-if="tournamentsEnabled === false" class="cmsg soon">
      <Trophy :size="34" />
      <h2>Tournaments are almost ready</h2>
      <p>This feature needs a quick one-time setup by an admin. Check back soon!</p>
    </div>
    <div v-else-if="loading" class="cmsg">Loading…</div>
    <div v-else-if="!t" class="cmsg">Tournament not found.</div>

    <template v-else>
      <div class="td-head">
        <div class="td-badge"><Trophy :size="15" /> Tournament</div>
        <h1>{{ t.title || t.sport }}</h1>
        <div class="td-sport">{{ t.sport }}<span v-if="t.skill_level && t.skill_level !== 'all'"> · {{ t.skill_level }} level</span></div>
      </div>

      <div class="td-facts">
        <div class="fact"><Users :size="18" /><div><b>{{ t.num_teams || t.max_players }} teams</b><span>{{ accepted.length }} confirmed</span></div></div>
        <div class="fact"><Trophy :size="18" /><div><b>{{ totalRounds }} rounds</b><span>{{ totalGames(t.num_teams || t.max_players) }} games</span></div></div>
        <div class="fact"><CalendarDays :size="18" /><div><b>{{ dlabel(t.game_date) }}</b><span>starts</span></div></div>
        <div class="fact"><ShieldCheck :size="18" /><div><b>{{ t.currency }} {{ Number(t.price_per_person||0).toLocaleString() }}</b><span>entry / team · pay host</span></div></div>
      </div>

      <div v-if="Number(t.prize_pot) > 0" class="td-prize">
        <Gift :size="22" />
        <div><b>{{ t.currency }} {{ Number(t.prize_pot).toLocaleString() }} prize pot</b>
        <span>{{ t.prize_note || 'Paid by the host directly to the winning team.' }}</span></div>
      </div>

      <p v-if="t.description" class="td-desc">{{ t.description }}</p>

      <!-- JOINER: status, or a CTA that opens the join modal -->
      <div v-if="!isHost" class="td-join">
        <div v-if="myReq" class="td-myreq" :class="statusMeta[myReq.status]?.cls">
          <div class="td-myreq-t"><Check v-if="myReq.status === 'accepted'" :size="16" /><Clock v-else-if="myReq.status === 'pending'" :size="16" /><X v-else :size="16" /> {{ statusMeta[myReq.status]?.label }}</div>
          <a v-if="myReq.status === 'accepted' && t.host_whatsapp" :href="waLink(t.host_whatsapp)" target="_blank" class="td-wa"><WhatsappIcon :size="16" /> Message the host</a>
        </div>
        <div v-else-if="memberGated" class="td-mgate">
          <div class="td-mgate-t"><ShieldCheck :size="15" /> {{ communityInfo?.name || 'Community' }} members only</div>
          <p>This tournament is for community members. Join {{ communityInfo?.name || 'the community' }} first — then you can enter your team.</p>
          <p v-if="memberPending" class="td-mgate-pending"><Clock :size="13" /> Your request to join is pending admin approval.</p>
          <button v-else class="td-joincard-btn" :disabled="joiningCommunity || !communityInfo" @click="joinCommunityFirst">
            <Users :size="16" /> {{ joiningCommunity ? 'Joining…' : (communityInfo?.is_private ? 'Request to join the community' : `Join ${communityInfo?.name || 'the community'}`) }}
          </button>
          <router-link v-if="communityInfo" :to="`/communities/${communityInfo.id}`" class="td-mgate-link">See the community first →</router-link>
        </div>
        <div v-else class="td-joincard">
          <div class="td-joincard-l">
            <div class="td-joincard-t">Want in?</div>
            <div class="td-joincard-d">{{ joinCardHint }}</div>
          </div>
          <button class="td-joincard-btn" @click="openJoin"><Users :size="17" /> {{ instantJoin ? 'Enter now' : 'Enter your team' }}</button>
        </div>
      </div>

      <!-- HOST: manage team requests -->
      <div v-if="isHost" class="td-host">
        <div class="td-host-bar">
          <div>
            <h2 class="td-h2" style="margin:0">Teams <span class="td-count">{{ accepted.length }}/{{ t.num_teams || t.max_players }}</span></h2>
            <p class="td-hint" style="margin:4px 0 0">Confirm teams, then generate the matchups to fill round 1.</p>
            <p class="td-hint" style="margin:2px 0 0; font-size:12px">Removed a team after generating matchups? Re-generate, or just advance their opponent in the bracket.</p>
          </div>
          <button class="td-gen" :disabled="working || accepted.length < 2" @click="genMatchups"><Shuffle :size="16" /> {{ matchupsReady ? 'Re-generate matchups' : 'Generate matchups' }}</button>
        </div>
        <!-- Requests live on their own page so a flood of them never buries the bracket. -->
        <router-link :to="`/tournaments/${t.id}/requests`" class="td-reqlink">
          <span class="td-reqlink-ic"><Users :size="20" /></span>
          <span class="td-reqlink-tx">
            <b>Manage team requests</b>
            <span>{{ pending.length }} pending · {{ accepted.length }} confirmed<template v-if="waitlisted.length"> · {{ waitlisted.length }} waitlisted</template></span>
          </span>
          <span class="td-reqlink-go"><span v-if="pending.length" class="td-reqlink-badge">{{ pending.length }}</span> Open <ArrowRight :size="16" /></span>
        </router-link>
      </div>

      <!-- BRACKET -->
      <h2 class="td-h2">Bracket</h2>
      <p v-if="isHost" class="td-hint" style="margin-top:-4px">Tap the winner of each match — they advance automatically. Tapped wrong? Tap the other team, or Undo, to change it (while their next match is still open).</p>
      <div v-if="isHost && bracketMsg" class="td-bracketmsg"><Lock :size="14" /> {{ bracketMsg }}</div>
      <div class="td-bracket">
        <div v-for="col in byRound" :key="col.round" class="td-col">
          <div class="td-col-h">{{ col.name }}</div>
          <div class="td-col-body">
            <div class="td-mwrap" v-for="m in col.matches" :key="m.id">
              <div class="td-match" :class="{ done: m.winner }">
                <button class="td-team" :class="{ win: m.winner === 'home', lose: m.winner === 'away', tbd: !m.home_team, tap: isHost && m.home_team && !(m.winner && nextLocked(m)) }"
                  :disabled="!isHost || !m.home_team || (!!m.winner && nextLocked(m))" @click="pickWinner(m, 'home')">
                  <span class="td-team-n">{{ m.home_team || 'TBD' }}</span>
                  <Trophy v-if="m.winner === 'home'" :size="14" class="td-crown" />
                </button>
                <div class="td-vs">vs</div>
                <button class="td-team" :class="{ win: m.winner === 'away', lose: m.winner === 'home', tbd: !m.away_team, tap: isHost && m.away_team && !(m.winner && nextLocked(m)) }"
                  :disabled="!isHost || !m.away_team || (!!m.winner && nextLocked(m))" @click="pickWinner(m, 'away')">
                  <span class="td-team-n">{{ m.away_team || 'TBD' }}</span>
                  <Trophy v-if="m.winner === 'away'" :size="14" class="td-crown" />
                </button>
                <div class="td-mmeta"><Clock :size="12" /> {{ fmtTime(m.start_time) }}<span v-if="m.listingName"> · {{ m.listingName }}</span><span v-if="m.match_date"> · {{ dlabel(m.match_date) }}</span></div>
                <div v-if="isHost && m.winner" class="td-undo">
                  <button v-if="!nextLocked(m)" type="button" class="td-undo-btn" @click="undoWinner(m)"><RotateCcw :size="12" /> Undo</button>
                  <span v-else class="td-undo-lock"><Lock :size="11" /> Undo next round to change</span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Mobile-only (≤760px): swipeable round-by-round bracket, Google knockout style -->
      <div class="td-mb" v-if="byRound.length">
        <div class="td-mb-tabs" role="tablist" aria-label="Rounds">
          <button v-for="col in byRound" :key="'tab' + col.round" type="button" class="td-mb-tab"
            :class="{ on: col.round === activeRound }" :aria-pressed="col.round === activeRound"
            @click="goRound(col.round)">{{ col.name }}</button>
        </div>
        <div class="td-mb-area" @pointerdown="onSwipeStart" @pointerup="onSwipeEnd">
          <!-- Collapsed previous round: context + wires showing which two feed each active match -->
          <button v-if="prevCol" type="button" class="td-mb-mini" aria-label="Previous round — tap to go back" @click="stepRound(-1)">
            <span class="td-mb-mini-h">{{ prevCol.name }}</span>
            <span class="td-mb-mini-body">
              <span v-for="m in prevCol.matches" :key="'mini' + m.id" class="td-mb-mini-m">
                <span class="td-mb-mini-t" :class="{ w: m.winner === 'home' }">{{ m.home_team || 'TBD' }}</span>
                <span class="td-mb-mini-t" :class="{ w: m.winner === 'away' }">{{ m.away_team || 'TBD' }}</span>
              </span>
            </span>
          </button>
          <!-- Active round: the same full match cards + handlers as desktop -->
          <Transition :name="slideDir > 0 ? 'tdm' : 'tdm-back'" mode="out-in">
            <div v-if="activeCol" :key="activeCol.round" class="td-mb-col" :class="{ 'has-prev': !!prevCol }">
              <div class="td-col-h">{{ activeCol.name }}</div>
              <div class="td-mb-col-body">
                <div class="td-mb-mwrap" v-for="m in activeCol.matches" :key="m.id">
                  <div class="td-match" :class="{ done: m.winner }">
                    <button class="td-team" :class="{ win: m.winner === 'home', lose: m.winner === 'away', tbd: !m.home_team, tap: isHost && m.home_team && !(m.winner && nextLocked(m)) }"
                      :disabled="!isHost || !m.home_team || (!!m.winner && nextLocked(m))" @click="pickWinner(m, 'home')">
                      <span class="td-team-n">{{ m.home_team || 'TBD' }}</span>
                      <Trophy v-if="m.winner === 'home'" :size="14" class="td-crown" />
                    </button>
                    <div class="td-vs">vs</div>
                    <button class="td-team" :class="{ win: m.winner === 'away', lose: m.winner === 'home', tbd: !m.away_team, tap: isHost && m.away_team && !(m.winner && nextLocked(m)) }"
                      :disabled="!isHost || !m.away_team || (!!m.winner && nextLocked(m))" @click="pickWinner(m, 'away')">
                      <span class="td-team-n">{{ m.away_team || 'TBD' }}</span>
                      <Trophy v-if="m.winner === 'away'" :size="14" class="td-crown" />
                    </button>
                    <div class="td-mmeta"><Clock :size="12" /> {{ fmtTime(m.start_time) }}<span v-if="m.listingName"> · {{ m.listingName }}</span><span v-if="m.match_date"> · {{ dlabel(m.match_date) }}</span></div>
                    <div v-if="isHost && m.winner" class="td-undo">
                      <button v-if="!nextLocked(m)" type="button" class="td-undo-btn" @click="undoWinner(m)"><RotateCcw :size="12" /> Undo</button>
                      <span v-else class="td-undo-lock"><Lock :size="11" /> Undo next round to change</span>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </Transition>
        </div>
      </div>

      <div v-if="!matchupsReady" class="td-bracket-note"><Trophy :size="15" /> The bracket fills once the host generates the matchups from confirmed teams.</div>

      <!-- Mobile-only docked CTA (flow route: shell hides the bottom nav + reserves clearance) -->
      <div v-if="!isHost && !myReq" class="td-dock">
        <button class="td-dock-btn" @click="openJoin"><Users :size="17" /> {{ instantJoin ? 'Enter now' : 'Enter your team' }}</button>
      </div>
    </template>

    <JoinModal :open="showJoin" kind="tournament" :instant="instantJoin" :default-iso="tIso" :busy="submitting" :error="err" :initial="joinInitial" :custom-form="t?.signup_form" @submit="submitJoin" @close="showJoin = false" />
  </div>
</template>

<style scoped>
.cback { display: inline-flex; align-items: center; gap: 6px; color: var(--brand); text-decoration: none; font-weight: 700; font-size: 14px; margin-bottom: 16px; }
.cmsg { text-align: center; padding: 50px; color: var(--text-light, #94a3b8); }
.cmsg.soon { display: flex; flex-direction: column; align-items: center; gap: 8px; color: #be123c; }
.cmsg.soon h2 { color: #0f172a; margin: 8px 0 0; }
.td-head { border-radius: 20px; padding: 24px; color: #fff; background: linear-gradient(135deg,#f43f5e,#e11d48); }
.td-badge { display: inline-flex; align-items: center; gap: 6px; background: rgba(255,255,255,.2); font-size: 13px; font-weight: 800; padding: 5px 12px; border-radius: 999px; }
.td-head h1 { font-size: 28px; font-weight: 900; margin: 12px 0 4px; color: #fff; }
.td-sport { font-size: 15px; opacity: .92; text-transform: capitalize; }

.td-facts { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 12px; margin-top: 18px; }
.fact { display: flex; align-items: center; gap: 12px; background: var(--surface, #ffffff); border: 1px solid var(--border, #e2e8f0); border-radius: 14px; padding: 14px 16px; color: #e11d48; }
.fact div { display: flex; flex-direction: column; color: #0f172a; }
.fact b { font-size: 15px; }
.fact span { font-size: 12.5px; color: var(--text-light, #94a3b8); }
.td-desc { font-size: 15px; color: #334155; line-height: 1.6; margin: 18px 0; }
.td-prize { display: flex; align-items: center; gap: 12px; background: linear-gradient(135deg,#fffbeb,#fef3c7); border: 1.5px solid #fcd34d; border-radius: 14px; padding: 14px 16px; color: #b45309; margin-top: 16px; }
.td-prize b { display: block; font-size: 17px; font-weight: 900; color: #92400e; }
.td-prize span { font-size: 13px; color: #a16207; }

.td-h2 { font-size: 20px; font-weight: 800; color: #0f172a; margin: 28px 0 10px; }
.td-hint { font-size: 13.5px; color: var(--text-muted, #64748b); margin: 0 0 14px; }

.td-join { margin-top: 22px; }
.td-joincard { display: flex; align-items: center; justify-content: space-between; gap: 16px; background: var(--surface, #ffffff); border: 1.5px solid var(--border, #e2e8f0); border-radius: 16px; padding: 18px 20px; }
.td-joincard-t { font-size: 16px; font-weight: 800; color: #0f172a; }
.td-joincard-d { font-size: 13px; color: var(--text-muted, #64748b); margin-top: 3px; line-height: 1.45; }
.td-joincard-btn { flex-shrink: 0; display: inline-flex; align-items: center; gap: 7px; background: #e11d48; color: #fff; font-weight: 800; font-size: 15px; padding: 13px 20px; border: none; border-radius: 12px; cursor: pointer; }
.td-joincard-btn:hover { background: #be123c; }
@media (max-width: 560px) { .td-joincard { flex-direction: column; align-items: stretch; } .td-joincard-btn { justify-content: center; } }
.td-dock { display: none; } /* mobile-only docked CTA */
.td-form { max-width: 420px; }
.td-in { width: 100%; box-sizing: border-box; border: 1.5px solid var(--border, #e2e8f0); border-radius: 10px; padding: 12px 14px; font-size: 15px; margin-bottom: 10px; outline: none; }
.td-phone { margin-bottom: 10px; }
.td-in:focus { border-color: var(--brand-accent); }
.td-ta { resize: vertical; font-family: inherit; }
.td-err { font-size: 13px; color: #dc2626; margin-bottom: 10px; }
.td-btn { width: 100%; max-width: 420px; background: var(--brand); color: #fff; font-weight: 800; font-size: 15.5px; padding: 14px; border: none; border-radius: 12px; cursor: pointer; }
.td-btn:disabled { opacity: .6; }
.td-myreq { border-radius: 12px; padding: 16px; max-width: 460px; }
.td-myreq-t { font-size: 14.5px; font-weight: 700; display: flex; align-items: center; gap: 7px; }
.st-pending { background: #fef3c7; color: #92400e; }
.st-accepted { background: #dcfce7; color: #166534; }
.st-rejected { background: #f1f5f9; color: var(--text-muted, #64748b); }
.td-wa { display: inline-flex; align-items: center; gap: 7px; background: #25d366; color: #fff; font-weight: 800; padding: 10px 16px; border-radius: 999px; text-decoration: none; margin-top: 12px; font-size: 14px; }

.td-host-bar { display: flex; align-items: flex-end; justify-content: space-between; gap: 14px; flex-wrap: wrap; }
.td-count { font-size: 13px; background: #0f172a; color: #fff; border-radius: 999px; padding: 2px 10px; }
.td-gen { display: inline-flex; align-items: center; gap: 7px; background: #e11d48; color: #fff; font-weight: 800; font-size: 14px; padding: 11px 16px; border: none; border-radius: 999px; cursor: pointer; }
.td-gen:disabled { opacity: .5; cursor: not-allowed; }
.td-noreq { color: var(--text-light, #94a3b8); padding: 22px; text-align: center; background: #f8fafc; border-radius: 12px; margin-top: 12px; }
/* Manage-requests link (replaces the inline request list on the event page) */
.td-reqlink { display: flex; align-items: center; gap: 12px; margin-top: 12px; background: var(--surface, #ffffff); border: 1.5px solid var(--border, #e2e8f0); border-radius: 14px; padding: 14px 16px; text-decoration: none; }
.td-reqlink:hover { border-color: #fda4af; }
.td-reqlink-ic { flex-shrink: 0; width: 40px; height: 40px; border-radius: 11px; background: #fff1f2; color: #e11d48; display: flex; align-items: center; justify-content: center; }
.td-reqlink-tx { flex: 1; min-width: 0; display: flex; flex-direction: column; }
.td-reqlink-tx b { font-size: 15px; color: #0f172a; }
.td-reqlink-tx span { font-size: 12.5px; color: var(--text-muted, #64748b); margin-top: 2px; }
.td-reqlink-go { flex-shrink: 0; display: inline-flex; align-items: center; gap: 6px; font-weight: 800; font-size: 13.5px; color: var(--brand); }
.td-reqlink-badge { display: inline-flex; align-items: center; justify-content: center; min-width: 20px; height: 20px; padding: 0 6px; border-radius: 999px; background: #e11d48; color: #fff; font-size: 11.5px; font-weight: 800; }
/* Winner change/undo */
.td-bracketmsg { display: flex; align-items: center; gap: 7px; font-size: 13px; font-weight: 600; color: #b45309; background: #fffbeb; border: 1px solid #fde68a; border-radius: 10px; padding: 9px 12px; margin-bottom: 10px; }
.td-undo { margin-top: 6px; padding: 0 2px; }
.td-undo-btn { display: inline-flex; align-items: center; gap: 4px; background: none; border: none; color: var(--text-muted, #64748b); font: inherit; font-weight: 800; font-size: 11.5px; padding: 2px 0; cursor: pointer; }
.td-undo-btn:hover { color: #e11d48; }
.td-undo-lock { display: inline-flex; align-items: center; gap: 4px; font-size: 10.5px; font-weight: 700; color: var(--text-light, #94a3b8); }
.td-reqs { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 12px; margin-top: 12px; }
.reqcard { background: var(--surface, #ffffff); border: 1px solid var(--border, #e2e8f0); border-radius: 14px; padding: 16px; }
.reqcard.rs-accepted { border-color: #86efac; background: #f0fdf4; }
.reqcard.rs-rejected { opacity: .6; }
.reqcard-top { display: flex; align-items: center; justify-content: space-between; }
.req-name { font-size: 16px; font-weight: 800; color: #0f172a; }
.req-name-link { text-decoration: none; }
.req-name-link:hover { color: var(--brand); text-decoration: underline; }
.req-pill { font-size: 11.5px; font-weight: 800; text-transform: uppercase; padding: 3px 9px; border-radius: 999px; }
.rp-pending { background: #fef3c7; color: #92400e; }
.rp-accepted { background: #dcfce7; color: #166534; }
.rp-rejected { background: #fee2e2; color: #991b1b; }
.req-msg { font-size: 14px; color: #475569; font-style: italic; margin: 8px 0; }
.req-contact { display: flex; gap: 10px; margin: 10px 0; flex-wrap: wrap; }
.req-wa { display: inline-flex; align-items: center; gap: 6px; background: #25d366; color: #fff; font-weight: 700; font-size: 13px; padding: 7px 13px; border-radius: 999px; text-decoration: none; }
.req-tel { display: inline-flex; align-items: center; gap: 6px; background: #f1f5f9; color: #334155; font-weight: 700; font-size: 13px; padding: 7px 13px; border-radius: 999px; text-decoration: none; }
.req-actions { display: flex; gap: 10px; margin-top: 6px; }
.req-accept, .req-reject { flex: 1; display: inline-flex; align-items: center; justify-content: center; gap: 6px; font-weight: 800; font-size: 14px; padding: 10px; border-radius: 10px; border: none; cursor: pointer; }
.req-accept { background: #16a34a; color: #fff; }
.req-reject { background: #fee2e2; color: #b91c1c; }

/* bracket */
/* Proper knockout bracket: each round centers between its two feeder matches (flex:1
   wrappers of equal height) with elbow connector wires drawn in the 46px gutter. */
.td-bracket { display: flex; gap: 46px; align-items: stretch; overflow-x: auto; padding: 6px 2px 14px; }
.td-col { flex: 0 0 210px; display: flex; flex-direction: column; }
.td-col-h { flex: 0 0 auto; font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: .05em; color: #be123c; text-align: center; margin-bottom: 10px; }
.td-col-body { flex: 1 1 0; display: flex; flex-direction: column; }
.td-mwrap { flex: 1 1 0; display: flex; align-items: center; position: relative; min-height: 122px; }
.td-mwrap .td-match { width: 100%; }
/* Outgoing elbow: horizontal stub from the match centre + vertical line to the pair midpoint. */
.td-col:not(:last-child) .td-mwrap::after { content: ''; position: absolute; left: 100%; width: 23px; height: 50%; border-right: 2px solid #cbd5e1; box-sizing: border-box; }
.td-col:not(:last-child) .td-mwrap:nth-child(odd)::after { top: 50%; border-top: 2px solid #cbd5e1; }
.td-col:not(:last-child) .td-mwrap:nth-child(even)::after { bottom: 50%; border-bottom: 2px solid #cbd5e1; }
/* Incoming stub into the next round's match centre (meets the vertical line at the gutter middle). */
.td-col:not(:first-child) .td-mwrap::before { content: ''; position: absolute; right: 100%; width: 23px; height: 2px; top: 50%; background: #cbd5e1; }
.td-match { background: var(--surface, #ffffff); border: 1px solid var(--border, #e2e8f0); border-radius: 12px; padding: 8px; }
.td-match.done { border-color: #99f6e4; }
.td-team { display: flex; align-items: center; justify-content: space-between; gap: 6px; width: 100%; text-align: left; background: #f8fafc; border: 1.5px solid transparent; border-radius: 8px; padding: 9px 11px; font-size: 14px; font-weight: 700; color: #0f172a; cursor: default; }
.td-team.tap { cursor: pointer; }
.td-team.tap:hover { border-color: var(--brand-accent); background: color-mix(in srgb, var(--brand) 8%, white); }
.td-team.win { background: #dcfce7; color: #166534; }
.td-team.lose { opacity: .5; text-decoration: line-through; }
.td-team.tbd { color: #cbd5e1; font-style: italic; font-weight: 600; }
.td-team-n { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.td-crown { color: #ca8a04; flex-shrink: 0; }
.td-vs { text-align: center; font-size: 11px; font-weight: 800; color: #cbd5e1; margin: 3px 0; }
.td-mmeta { display: flex; align-items: center; gap: 4px; font-size: 11.5px; color: var(--text-light, #94a3b8); margin-top: 7px; padding: 0 2px; flex-wrap: wrap; }
.td-bracket-note { display: flex; align-items: center; gap: 8px; font-size: 13.5px; color: #be123c; background: #fff1f2; border: 1px solid #fecdd3; border-radius: 12px; padding: 12px 14px; margin-top: 4px; }

/* Mobile round-by-round bracket: hidden on desktop (≥761px keeps the columns untouched) */
.td-mb { display: none; }

/* ---- Mobile polish pass 2 (calm, glanceable, tighter rhythm) ---- */
@media (max-width: 760px) {
  .cmsg { padding: 36px 16px; }

  /* Hero: page h1 down to 21px, tighter chrome (weight 900 kept — it's the page h1) */
  .td-head { padding: 18px 16px; border-radius: 16px; }
  .td-head h1 { font-size: 21px; margin: 10px 0 2px; }
  .td-sport { font-size: 14px; }

  /* Facts: compact 2x2 grid instead of four stacked full-width cards */
  .td-facts { grid-template-columns: 1fr 1fr; gap: 8px; margin-top: 12px; }
  .fact { padding: 10px 12px; gap: 8px; border-radius: 12px; }
  .fact b { font-size: 14px; overflow-wrap: anywhere; }
  .fact span { font-size: 12px; }
  .fact div { min-width: 0; }

  /* Prize: decision info stays, but stops reading as a second heading */
  .td-prize { padding: 12px 14px; }
  .td-prize b { font-size: 16px; font-weight: 800; }

  /* Description: 4-line clamp so the join CTA stays near the fold */
  .td-desc {
    font-size: 14.5px; line-height: 1.5; margin: 14px 0;
    display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 4; overflow: hidden;
  }

  /* Section headings under the h1; tighter vertical rhythm */
  .td-h2 { font-size: 17px; margin: 22px 0 8px; }

  /* Join card: pass-1 dense-card standard */
  .td-joincard { padding: 14px 16px; border-radius: 14px; }
  .td-joincard-t { font-size: 15px; }

  /* Host bar: full-width action reads deliberate, not wrapped-by-accident */
  .td-host-bar { align-items: stretch; flex-direction: column; gap: 10px; }
  .td-gen { justify-content: center; }

  /* Bracket: swap the scrolling columns for the round-by-round view */
  .td-bracket { display: none; }
  .td-mb { display: block; }

  /* Round-tab chips (horizontal scroll if they overflow) */
  .td-mb-tabs { display: flex; gap: 8px; overflow-x: auto; padding: 2px 2px 10px; -webkit-overflow-scrolling: touch; scrollbar-width: none; }
  .td-mb-tabs::-webkit-scrollbar { display: none; }
  .td-mb-tab {
    flex: 0 0 auto; white-space: nowrap; border: 1.5px solid var(--border, #e2e8f0); background: var(--surface, #ffffff); color: var(--text-muted, #64748b);
    font: inherit; font-weight: 800; font-size: 12.5px; padding: 8px 14px; border-radius: 999px; cursor: pointer;
  }
  .td-mb-tab.on { background: #e11d48; border-color: #e11d48; color: #fff; }

  /* Bracket area: collapsed previous round (left) + full active round.
     touch-action: pan-y = browser keeps vertical scroll, but horizontal swipes
     are delivered to our pointer handlers (without it, mobile browsers may
     claim the horizontal gesture and the swipe never fires). */
  /* 30px gutter holds the connector wires between the collapsed prev round and the active round. */
  .td-mb-area { display: flex; align-items: stretch; gap: 30px; touch-action: pan-y; }
  .td-mb-mini {
    flex: 0 0 86px; min-width: 0; display: flex; flex-direction: column;
    background: #f8fafc; border: 1px solid var(--border, #e2e8f0); border-radius: 12px; padding: 8px 0 8px 6px;
    font: inherit; text-align: left; cursor: pointer; opacity: .7;
  }
  .td-mb-mini-h { display: block; font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: .04em; color: var(--text-light, #94a3b8); text-align: center; margin-bottom: 6px; }
  .td-mb-mini-body { flex: 1; display: flex; flex-direction: column; }
  .td-mb-mini-m { flex: 1 1 0; position: relative; display: flex; flex-direction: column; justify-content: center; gap: 3px; padding-right: 6px; min-width: 0; }
  .td-mb-mini-t { display: block; background: var(--surface, #ffffff); border: 1px solid var(--border, #e2e8f0); border-radius: 7px; padding: 4px 6px; font-size: 10.5px; font-weight: 600; color: var(--text-muted, #64748b); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .td-mb-mini-t.w { color: #166534; font-weight: 800; }
  .td-mb-col { flex: 1; min-width: 0; display: flex; flex-direction: column; }
  .td-mb-col-body { flex: 1; display: flex; flex-direction: column; }
  .td-mb-mwrap { flex: 1 1 0; display: flex; align-items: center; position: relative; min-height: 108px; }
  .td-mb-mwrap .td-match { width: 100%; }
  /* Wires: prev-round mini items elbow right; active matches stub left; they meet mid-gutter. */
  .td-mb-mini-m::after { content: ''; position: absolute; left: 100%; width: 15px; height: 50%; border-right: 2px solid #cbd5e1; box-sizing: border-box; }
  .td-mb-mini-m:nth-child(odd)::after { top: 50%; border-top: 2px solid #cbd5e1; }
  .td-mb-mini-m:nth-child(even)::after { bottom: 50%; border-bottom: 2px solid #cbd5e1; }
  .td-mb-col.has-prev .td-mb-mwrap::before { content: ''; position: absolute; right: 100%; width: 15px; height: 2px; top: 50%; background: #cbd5e1; }

  /* Subtle slide/fade when the active round changes; direction follows the swipe */
  .tdm-enter-active, .tdm-leave-active,
  .tdm-back-enter-active, .tdm-back-leave-active { transition: transform .18s ease, opacity .18s ease; }
  .tdm-enter-from { opacity: 0; transform: translateX(16px); }
  .tdm-leave-to { opacity: 0; transform: translateX(-16px); }
  .tdm-back-enter-from { opacity: 0; transform: translateX(-16px); }
  .tdm-back-leave-to { opacity: 0; transform: translateX(16px); }

  /* Touch targets: winner-pick + primary/host action buttons >= 44px tall */
  .td-team { min-height: 44px; }
  .td-joincard-btn, .td-gen, .req-accept, .req-reject { min-height: 44px; }

  /* Request cards: long unbroken names must not push the status pill past the edge */
  .reqcard-top { flex-wrap: wrap; gap: 6px; }
  .req-name { min-width: 0; overflow-wrap: anywhere; }

  /* Docked primary CTA: flush to the viewport bottom (flow route — the shell hides
     the bottom nav and already reserves .cust-main bottom padding for clearance) */
  .td-dock {
    display: block; position: fixed; left: 0; right: 0; bottom: 0; z-index: 90;
    background: var(--surface, #ffffff); border-top: 1px solid var(--border, #e2e8f0); box-shadow: 0 -8px 24px rgba(15,23,42,.10);
    padding: 10px 14px calc(10px + env(safe-area-inset-bottom, 0px));
    border-radius: 0;
  }
  .td-dock-btn {
    width: 100%; display: flex; align-items: center; justify-content: center; gap: 7px;
    background: #e11d48; color: #fff; font-weight: 800; font-size: 15.5px;
    min-height: 48px; padding: 13px; border: none; border-radius: 12px; cursor: pointer;
  }
}
.td-mgate { border: 1.5px solid #99f6e4; background: #f0fdfa; border-radius: 14px; padding: 14px; display: flex; flex-direction: column; gap: 8px; }
.td-mgate-t { display: flex; align-items: center; gap: 7px; font-weight: 800; font-size: 14px; color: #0f766e; }
.td-mgate p { margin: 0; font-size: 12.5px; color: #475569; line-height: 1.5; }
.td-mgate-pending { display: flex; align-items: center; gap: 6px; font-weight: 700; color: #b45309 !important; }
.td-mgate-link { font-size: 12.5px; font-weight: 700; color: var(--brand, #0d9488); text-decoration: none; }
</style>
