<script setup>
/**
 * ManageRequests.vue — a dedicated host page for working through join requests, off the
 * event page so a flood of requests never buries the bracket / event details. Shared by
 * games, hangouts AND tournaments (all `hosted_games` rows). Filter + search make a big
 * pile manageable; accept/decline/remove mirror the inline actions they replaced.
 */
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute } from 'vue-router'
import { customer, isCustomer } from '../../lib/customerSession.js'
import { loadGame, loadGameRequests, respondToRequest, markAttendance, acceptAllRequests } from '../../customer/data.js'
import { totalGames } from '../../customer/bracket.js'
import { meetupState } from '../../customer/meetup.js'
import { pushToast } from '../../lib/toast.js'
import { useSeo } from '../../lib/seo.js'
import FormAnswers from '../../components/FormAnswers.vue'
import WhatsappIcon from '../../components/WhatsappIcon.vue'
import { ArrowLeft, Users, Check, X, Phone, Search, Trophy, Clock, UserCheck, UserX, Star } from 'lucide-vue-next'

const route = useRoute()
useSeo({ title: 'Manage requests', noindex: true, path: route.path })

const game = ref(null)
const requests = ref([])
const loading = ref(true)
const err = ref('')
const busyId = ref('')
const fStatus = ref('pending')   // default to the ones that need action
const q = ref('')

const isTournament = computed(() => game.value?.game_type === 'tournament')
// Accurate type label, matching GameCard / GameDetail (game_type 'event' = hangout).
const typeLabel = computed(() => game.value?.game_type === 'tournament' ? 'Tournament' : game.value?.game_type === 'event' ? 'Hangout' : 'Game')
const typeWord = computed(() => typeLabel.value.toLowerCase())
const isHost = computed(() => isCustomer.value && game.value && customer.value.id === game.value.host_customer_id)
const backPath = computed(() => game.value ? (isTournament.value ? `/tournaments/${game.value.id}` : `/games/${game.value.id}`) : '/my-games')
const unitLabel = computed(() => isTournament.value ? 'teams' : 'players')
const cap = computed(() => Number(isTournament.value ? (game.value?.num_teams || game.value?.max_players) : game.value?.max_players) || 0)

const pending = computed(() => requests.value.filter(r => r.status === 'pending'))
const accepted = computed(() => requests.value.filter(r => r.status === 'accepted'))
const rejected = computed(() => requests.value.filter(r => r.status === 'rejected'))
const waitlisted = computed(() => requests.value.filter(r => r.status === 'waitlisted'))
const capFull = computed(() => cap.value > 0 && accepted.value.length >= cap.value)

const TABS = computed(() => {
  const t = [
    { key: 'pending', label: 'Pending', n: pending.value.length },
    { key: 'accepted', label: 'Confirmed', n: accepted.value.length },
    { key: 'rejected', label: 'Declined', n: rejected.value.length },
  ]
  if (waitlisted.value.length) t.splice(2, 0, { key: 'waitlisted', label: 'Waitlist', n: waitlisted.value.length })
  t.push({ key: 'all', label: 'All', n: requests.value.length })
  return t
})

const shown = computed(() => {
  const term = q.value.trim().toLowerCase()
  return requests.value
    .filter(r => fStatus.value === 'all' || r.status === fStatus.value)
    .filter(r => !term || (r.joiner_name || '').toLowerCase().includes(term) || (r.joiner_message || '').toLowerCase().includes(term))
})

// Attendance marking unlocks once the event is live/ended.
const lifeState = computed(() => meetupState(game.value, accepted.value.length))
const attendanceMode = computed(() => ['active', 'archived'].includes(lifeState.value))

async function load() {
  loading.value = true; err.value = ''
  game.value = await loadGame(route.params.id)
  if (game.value) {
    requests.value = await loadGameRequests(game.value.id)
  }
  loading.value = false
}

async function setAttendance(req, showed) {
  busyId.value = req.id
  const { error } = await markAttendance(req.id, showed)
  busyId.value = ''
  if (error) { err.value = error; return }
  requests.value = await loadGameRequests(game.value.id)
}
onMounted(load)
watch(() => route.params.id, load)
watch(() => customer.value?.id, load)

async function decide(req, status) {
  busyId.value = req.id
  const { error } = await respondToRequest(req.id, status)
  busyId.value = ''
  if (error) { err.value = error; return }
  err.value = ''
  requests.value = await loadGameRequests(game.value.id)
}

// Bulk-accept every pending request (two-tap confirm to avoid an accidental flood).
const confirmAll = ref(false)
const acceptAllBusy = ref(false)
async function acceptAll() {
  if (!confirmAll.value) { confirmAll.value = true; return }
  confirmAll.value = false
  acceptAllBusy.value = true
  err.value = ''
  const res = await acceptAllRequests(game.value.id)
  acceptAllBusy.value = false
  if (res.error) { err.value = res.error; return }
  requests.value = await loadGameRequests(game.value.id)
  if (res.full) { err.value = `All ${cap.value} spots are already filled — remove someone or raise "max ${unitLabel.value}" first.`; return }
  if (res.leftover > 0) pushToast(`Accepted ${res.accepted} — ${res.leftover} left pending (over the ${cap.value}-${isTournament.value ? 'team' : 'player'} cap).`)
  else pushToast(`Accepted all ${res.accepted} ${unitLabel.value} ✓`)
}

function waLink(num) { return 'https://wa.me/' + String(num || '').replace(/[^\d]/g, '') }
const PILL = { pending: 'rp-pending', accepted: 'rp-accepted', rejected: 'rp-rejected', waitlisted: 'rp-pending' }
</script>

<template>
  <div class="mrq">
    <router-link :to="backPath" class="cback"><ArrowLeft :size="16" /> Back to {{ game ? typeWord : 'event' }}</router-link>

    <div v-if="loading" class="mrq-msg">Loading…</div>
    <div v-else-if="!game" class="mrq-msg">Event not found.</div>
    <div v-else-if="!isHost" class="mrq-msg">Only the host can manage requests for this {{ typeWord }}.</div>

    <template v-else>
      <header class="mrq-head">
        <div class="mrq-head-main">
          <div class="mrq-badge"><component :is="isTournament ? Trophy : Users" :size="14" /> {{ typeLabel }}</div>
          <h1>{{ game.title || game.sport }}</h1>
          <p class="mrq-sub">Accept the {{ unitLabel }} you want in{{ cap ? ` — ${accepted.length} of ${cap} confirmed` : '' }}. Decline the rest.</p>
        </div>
        <div v-if="pending.length && !capFull" class="mrq-head-cta">
          <template v-if="confirmAll">
            <button type="button" class="mrq-acceptall confirm" :disabled="acceptAllBusy" @click="acceptAll"><Check :size="16" /> Confirm — accept {{ pending.length }}</button>
            <button type="button" class="mrq-acceptall cancel" :disabled="acceptAllBusy" title="Cancel" @click="confirmAll = false"><X :size="16" /></button>
          </template>
          <button v-else type="button" class="mrq-acceptall" :disabled="acceptAllBusy" @click="acceptAll">
            <Check :size="16" /> {{ acceptAllBusy ? 'Accepting…' : `Accept all (${pending.length})` }}
          </button>
        </div>
      </header>

      <!-- Confirmed-capacity hint for tournaments/capped events -->
      <div v-if="capFull" class="mrq-full"><Check :size="15" /> You've confirmed all {{ cap }} {{ unitLabel }}. Any more you accept go over the limit.</div>

      <div class="mrq-tabs" role="tablist">
        <button v-for="tb in TABS" :key="tb.key" type="button" class="mrq-tab" :class="{ on: fStatus === tb.key }"
          :aria-pressed="fStatus === tb.key" @click="fStatus = tb.key">
          {{ tb.label }}<span v-if="tb.n" class="mrq-tab-n">{{ tb.n }}</span>
        </button>
      </div>

      <div class="mrq-search">
        <Search :size="16" />
        <input v-model="q" type="search" :placeholder="`Search ${unitLabel} by name…`" />
      </div>

      <div v-if="err" class="mrq-err">{{ err }}</div>

      <div v-if="!shown.length" class="mrq-empty">
        <Users :size="30" />
        <p>{{ q ? 'No matches for your search.' : (requests.length ? 'Nothing in this tab.' : 'No requests yet — share your ' + typeWord + ' so ' + unitLabel + ' can join!') }}</p>
      </div>

      <div v-else class="mrq-grid">
        <div v-for="r in shown" :key="r.id" class="reqcard" :class="'rs-' + r.status">
          <div class="reqcard-top">
            <router-link v-if="r.joiner_customer_id" :to="`/u/${r.joiner_customer_id}`" class="req-name req-name-link">{{ r.joiner_name }}</router-link>
            <div v-else class="req-name">{{ r.joiner_name }}</div>
            <span class="req-pill" :class="PILL[r.status]">{{ r.status }}</span>
          </div>
          <!-- Refund / no-show markers -->
          <div v-if="r.refund_state === 'refunded' || r.refund_state === 'forfeited'" class="req-aids">
            <span v-if="r.refund_state === 'refunded'" class="req-aid refund">Refunded</span>
            <span v-else-if="r.refund_state === 'forfeited'" class="req-aid forfeit">No-show — forfeited</span>
          </div>
          <p v-if="r.joiner_message" class="req-msg">"{{ r.joiner_message }}"</p>
          <FormAnswers v-if="game.signup_form && game.signup_form.length" :fields="game.signup_form" :answers="r.form_answers || {}" />
          <div class="req-contact">
            <a v-if="r.joiner_whatsapp" :href="waLink(r.joiner_whatsapp)" target="_blank" class="req-wa" title="WhatsApp"><WhatsappIcon :size="16" /></a>
            <a v-if="r.joiner_phone" :href="`tel:${r.joiner_phone}`" class="req-tel"><Phone :size="14" /> {{ r.joiner_phone }}</a>
          </div>
          <div v-if="r.status === 'pending' || r.status === 'waitlisted'" class="req-actions">
            <button class="req-accept" :disabled="busyId === r.id" @click="decide(r, 'accepted')"><Check :size="16" /> Accept</button>
            <button class="req-reject" :disabled="busyId === r.id" @click="decide(r, 'rejected')"><X :size="16" /> Decline</button>
          </div>
          <!-- Live/ended: attendance marking replaces Remove — powers reliability + forfeits -->
          <div v-else-if="r.status === 'accepted' && attendanceMode" class="req-actions">
            <button class="req-att show" :class="{ on: r.attended === true }" :disabled="busyId === r.id" @click="setAttendance(r, true)"><UserCheck :size="15" /> Showed up</button>
            <button class="req-att noshow" :class="{ on: r.attended === false }" :disabled="busyId === r.id" @click="setAttendance(r, false)"><UserX :size="15" /> No-show</button>
          </div>
          <div v-else-if="r.status === 'accepted'" class="req-actions">
            <button class="req-reject" :disabled="busyId === r.id" @click="decide(r, 'rejected')"><X :size="16" /> Remove</button>
          </div>
          <div v-else-if="r.status === 'rejected'" class="req-actions">
            <button class="req-accept ghost" :disabled="busyId === r.id || capFull" @click="decide(r, 'accepted')"><Check :size="16" /> Accept instead</button>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
.mrq { max-width: 920px; margin: 0 auto; }
.cback { display: inline-flex; align-items: center; gap: 6px; color: var(--brand); text-decoration: none; font-weight: 700; font-size: 14px; margin-bottom: 16px; }
.mrq-msg { text-align: center; padding: 50px; color: var(--text-light, #94a3b8); }
.mrq-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 14px; margin-bottom: 16px; }
.mrq-head-main { min-width: 0; }
.mrq-badge { display: inline-flex; align-items: center; gap: 6px; background: #ffe4e6; color: #be123c; font-size: 12.5px; font-weight: 800; padding: 4px 11px; border-radius: 999px; }
.mrq-head h1 { font-size: 24px; font-weight: 900; color: #0f172a; margin: 10px 0 3px; }
.mrq-sub { font-size: 14px; color: var(--text-muted, #64748b); margin: 0; }
.mrq-head-cta { display: flex; gap: 8px; flex-shrink: 0; }
.mrq-acceptall { display: inline-flex; align-items: center; justify-content: center; gap: 7px; white-space: nowrap; background: #16a34a; color: #fff; font: inherit; font-weight: 800; font-size: 14px; padding: 11px 18px; border: none; border-radius: 999px; cursor: pointer; min-height: 44px; }
.mrq-acceptall.confirm { background: #15803d; }
.mrq-acceptall.cancel { background: #fee2e2; color: #b91c1c; padding: 11px 14px; }
.mrq-acceptall:disabled { opacity: .6; cursor: not-allowed; }
.mrq-full { display: flex; align-items: center; gap: 8px; font-size: 13.5px; font-weight: 700; color: #15803d; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 12px; padding: 10px 13px; margin-bottom: 14px; }

.mrq-tabs { display: flex; gap: 8px; overflow-x: auto; padding-bottom: 10px; scrollbar-width: none; }
.mrq-tabs::-webkit-scrollbar { display: none; }
.mrq-tab { flex: 0 0 auto; display: inline-flex; align-items: center; gap: 7px; white-space: nowrap; border: 1.5px solid var(--border, #e2e8f0); background: var(--surface, #ffffff); color: var(--text-muted, #64748b); font: inherit; font-weight: 800; font-size: 13px; padding: 8px 14px; border-radius: 999px; cursor: pointer; }
.mrq-tab.on { background: #e11d48; border-color: #e11d48; color: #fff; }
.mrq-tab-n { display: inline-flex; align-items: center; justify-content: center; min-width: 18px; height: 18px; padding: 0 5px; border-radius: 999px; font-size: 11px; background: #fee2e2; color: #be123c; }
.mrq-tab.on .mrq-tab-n { background: rgba(255,255,255,.25); color: #fff; }

.mrq-search { display: flex; align-items: center; gap: 8px; border: 1.5px solid var(--border, #e2e8f0); background: var(--surface, #ffffff); border-radius: 12px; padding: 0 12px; margin: 4px 0 16px; color: var(--text-light, #94a3b8); }
.mrq-search input { flex: 1; border: none; outline: none; background: none; font: inherit; font-size: 15px; padding: 11px 0; color: #0f172a; }
.mrq-err { font-size: 13px; color: #dc2626; margin-bottom: 12px; }
.mrq-empty { text-align: center; padding: 40px 20px; color: var(--text-light, #94a3b8); }
.mrq-empty p { margin: 10px 0 0; font-size: 14px; }

.mrq-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(258px, 1fr)); gap: 12px; }
.reqcard { background: var(--surface, #ffffff); border: 1px solid var(--border, #e2e8f0); border-radius: 14px; padding: 16px; }
.reqcard.rs-accepted { border-color: #86efac; background: #f0fdf4; }
.reqcard.rs-rejected { opacity: .72; }
.reqcard-top { display: flex; align-items: center; justify-content: space-between; gap: 6px; flex-wrap: wrap; }
.req-name { font-size: 16px; font-weight: 800; color: #0f172a; min-width: 0; overflow-wrap: anywhere; }
.req-name-link { text-decoration: none; }
.req-name-link:hover { color: var(--brand); text-decoration: underline; }
.req-pill { font-size: 11px; font-weight: 800; text-transform: uppercase; padding: 3px 9px; border-radius: 999px; }
.rp-pending { background: #fef3c7; color: #92400e; }
.rp-accepted { background: #dcfce7; color: #166534; }
.rp-rejected { background: #fee2e2; color: #991b1b; }
.req-msg { font-size: 14px; color: #475569; font-style: italic; margin: 8px 0; }
.req-contact { display: flex; gap: 10px; margin: 10px 0; flex-wrap: wrap; }
.req-wa { display: inline-flex; align-items: center; gap: 6px; background: #25d366; color: #fff; font-weight: 700; font-size: 13px; padding: 7px 12px; border-radius: 999px; text-decoration: none; }
.req-tel { display: inline-flex; align-items: center; gap: 6px; background: #f1f5f9; color: #334155; font-weight: 700; font-size: 13px; padding: 7px 12px; border-radius: 999px; text-decoration: none; }
.req-actions { display: flex; gap: 10px; margin-top: 6px; }
.req-accept, .req-reject { flex: 1; display: inline-flex; align-items: center; justify-content: center; gap: 6px; font-weight: 800; font-size: 14px; padding: 10px; border-radius: 10px; border: none; cursor: pointer; min-height: 42px; }
.req-accept { background: #16a34a; color: #fff; }
.req-accept.ghost { background: #fff; color: #16a34a; border: 1.5px solid #86efac; }
.req-reject { background: #fee2e2; color: #b91c1c; }
.req-accept:disabled, .req-reject:disabled { opacity: .55; cursor: not-allowed; }
/* Refund / no-show marker chips */
.req-aids { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 7px; }
.req-aid { display: inline-flex; align-items: center; gap: 4px; font-size: 11px; font-weight: 800; padding: 3px 9px; border-radius: 999px; }
.req-aid.refund { background: #dbeafe; color: #1d4ed8; }
.req-aid.forfeit { background: #fee2e2; color: #b91c1c; }
/* Attendance toggles */
.req-att { flex: 1; display: inline-flex; align-items: center; justify-content: center; gap: 6px; font-weight: 800; font-size: 13.5px; padding: 10px; border-radius: 10px; border: 1.5px solid var(--border, #e2e8f0); background: none; color: var(--text-muted, #64748b); cursor: pointer; min-height: 42px; }
.req-att.show.on { background: #16a34a; border-color: #16a34a; color: #fff; }
.req-att.noshow.on { background: #dc2626; border-color: #dc2626; color: #fff; }
.req-att:disabled { opacity: .55; }

@media (max-width: 560px) {
  .mrq-head { flex-direction: column; gap: 12px; }
  .mrq-head h1 { font-size: 20px; }
  .mrq-head-cta { width: 100%; }
  .mrq-acceptall { flex: 1; }
  .mrq-grid { grid-template-columns: 1fr; }
}
</style>
