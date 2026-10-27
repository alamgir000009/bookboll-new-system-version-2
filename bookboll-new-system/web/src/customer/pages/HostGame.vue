<script setup>
import { ref, onMounted, onBeforeUnmount, computed, watch, nextTick } from 'vue'
import { appBaseUrl } from '../../lib/appBase.js'
import { useRouter, useRoute } from 'vue-router'
import { selectedCity, selectedCountry, customer, isCustomer } from '../../lib/customerSession.js'
import { isWhiteLabel } from '../../lib/brand.js'
import CheckoutRewards from '../components/CheckoutRewards.vue'
import { ensureSignedIn } from '../../lib/authGate.js'
import { useSeo } from '../../lib/seo.js'
useSeo({ title: 'Host a game, hangout or tournament', description: 'Set up your own pickup game, social hangout or tournament, pick a venue and invite players — host on Bookboll.', path: '/host-game' })
import { loadLocations, loadFacility, loadFacilities, loadListing } from '../../customer/data.js'
import { createGame, gamesEnabled, probeGames, tournamentsEnabled, probeTournaments, hangoutLocationEnabled, probeHangoutLocation, ownSpotMinFee, ownSpotMinFeeCurrency, seatFee, seatFeeAt, eventSeatFeePct, SEAT_FEE_PCT, courtCommissionPct, courtDeposit, createTournament, courtPaymentPlan, splitCourt, PAY_METHOD_LABEL } from '../../customer/apiGames.js'
import { PLAY_FORMATS } from '../../customer/matchmaking.js'
import { loadDayAvailability, buildDaySlots, holdSlots, holdMinutes } from '../../customer/apiBooking.js'
import { hostDraft, saveHostDraft, clearHostDraft } from '../../customer/hostDraft.js'
import { loadCommunity } from '../../customer/community.js'
import { getPrefillPhone, saveCustomerPhone } from '../../customer/customerPhones.js'
import { loadStripeConnect, loadStripeCountries, startStripeConnect, syncStripeConnect, saveStripeKeys, disconnectStripe, isStripeConnected } from '../../customer/hostPayouts.js'
import { TEAM_OPTIONS, roundsFor, matchesInRound, totalGames, roundName, fmtTime, consecutiveSlots, normTime, addMinutes } from '../../customer/bracket.js'
import PhoneInput from '../components/PhoneInput.vue'
import EventFeeSummary from '../components/EventFeeSummary.vue'
import { validateHostBasics } from '../../lib/hostBasics.js'
import { validateHostMedia } from '../../lib/hostMedia.js'
import LiabilityWaiver from '../components/LiabilityWaiver.vue'
import VenueBrowse from '../components/VenueBrowse.vue'
import CustomFormBuilder from '../../components/CustomFormBuilder.vue'
import { ArrowLeft, ArrowRight, Trophy, Gamepad2, Info, Sparkles, Check, MapPin, Clock, UsersRound, Loader, Calendar, Building2, X, Plus, Pencil, Gift, EyeOff, ImagePlus, Copy, Share2, ShieldCheck, CreditCard, Banknote, Zap, RefreshCw, Hourglass, CalendarDays } from 'lucide-vue-next'
import { subcategoriesFor } from '../../lib/venueCategories.js'
import { uploadImage } from '../../lib/imageUpload.js'
import { durationLabel, SLOT_DURATIONS, toMinutes, endMinutes } from '../../lib/slots.js'

// Event duration options for the "Event's duration" dropdown — 15-minute steps (15 min
// up to 8 hours), matching the admin listing form's duration selector. Stored in hours to
// match the rest of the wizard. This is the EVENT length only — independent of the venue
// slots the host books — used only for a hangout at the host's own spot (no venue booking).
const GAME_DURATIONS = SLOT_DURATIONS.map(d => ({ value: d.value / 60, label: d.label }))
function durLabel(h) { return durationLabel(Math.round(h * 60)) }

const router = useRouter()
const route = useRoute()

// Hosting FOR a community (arrived via /host-game?community=<id> from a community's
// Meetups tab). Survives the court-pick round trip via hostDraft.communityId; the
// published event is stamped with community_id so it shows on the community page.
const communityId = ref('')
const communityName = ref('')
const listPublic = ref(false)   // community event → also list in public Events (opt-in)
function ymd(d) { const z = n => String(n).padStart(2, '0'); return `${d.getFullYear()}-${z(d.getMonth() + 1)}-${z(d.getDate())}` }
const today = ymd(new Date())
function fmtDate(d) { return d ? new Date(d + 'T00:00:00').toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' }) : '' }
const currency = computed(() => selectedCountry.value?.currency_code || '')

const type = ref('')        // '' (chooser) | 'game' | 'tournament'
const step = ref(1)
const signupForm = ref([])  // optional custom sign-up questions for this event
const saving = ref(false)
const err = ref('')
const venues = ref([])

// shared basics
const f = ref({
  title: '', sport: '', skill_level: 'all', description: '', images: [],
  allow_waitlist: true,   // when full, queue joiners for freed spots
  price_per_person: '', max_players: '',   // blank — the host types their own capacity
  ticket_mode: 'arrival',   // 'arrival' (cash to the host) | 'stripe' (online tickets, fee split per ticket)
  approval_mode: 'review',  // 'review' (host approves each request) | 'instant' (anyone joins until full)
  prize_on: false, prize_places: [{ amount: '' }],   // optional prize pot, split by place (1st, 2nd…)
  lock_minutes_before: 0,    // roster lock: 0 = at start time, else minutes before start
  play_format: '', courts_count: 1, team_size: 2,          // optional live rounds (game day engine)
  // hangout-only: own-spot (no venue), hidden exact location
  spot_mode: '',               // '' = not picked yet (hangouts choose on their own screen) · 'venue' = book a court · 'own' = host's own place
  loc_name: '', loc_address: '', own_date: '', own_start: '',
  hide_location: false,
  host_name: '', host_phone: '', host_whatsapp: '',
  request_deadline: '',   // optional (recommended) cut-off for join requests/applications
})
const titleInput = ref(null), sportInput = ref(null), capacityInput = ref(null), priceInput = ref(null)
const basicsErrors = computed(() => validateHostBasics(f.value, type.value))
const hostWaiver = ref(false)   // must agree to the liability waiver before publishing

// ── Event photos (at least one required for games and hangouts): drop/pick → compress → our own storage. First = cover. ──
const imgUploading = ref(false)
const imgError = ref('')
const dropActive = ref(false)
const gFileInput = ref(null)
const descriptionInput = ref(null), photoButton = ref(null), mediaAttempted = ref(false)
const mediaErrors = computed(() => validateHostMedia(f.value, { photosRequired: isGameLike.value }))
function pickGameFiles() { gFileInput.value?.click() }
async function addGameImages(files) {
  if (imgUploading.value) return
  const list = Array.from(files || []).filter(file => file.type?.startsWith('image/'))
  if (!list.length) return
  imgError.value = ''; imgUploading.value = true
  try {
    for (const file of list) {
      const res = await uploadImage(file, 'games')
      if (res.error || !res.url) { imgError.value = res.error || 'The photo could not be uploaded. Please try again.'; break }
      f.value.images.push(res.url)
    }
  } catch {
    imgError.value = 'The photo could not be uploaded. Please try again.'
  } finally {
    imgUploading.value = false
    if (gFileInput.value) gFileInput.value.value = ''
  }
}
function onGameFilesChange(e) { addGameImages(e.target.files) }
function onGameDrop(e) { dropActive.value = false; addGameImages(e.dataTransfer?.files) }
function removeGameImage(i) { f.value.images.splice(i, 1) }

// ── Prize pot: toggle + a row per place (1st, 2nd, 3rd…), total auto-summed ──
function ordinal(n) { const s = ['th', 'st', 'nd', 'rd'], v = n % 100; return n + (s[(v - 20) % 10] || s[v] || s[0]) }
function placeBadge(i) { return i + 1 }
const prizeTotal = computed(() => f.value.prize_on ? f.value.prize_places.reduce((n, p) => n + (Number(p.amount) || 0), 0) : 0)
// Human breakdown for 2+ funded places (a single prize needs no breakdown — the total says it all).
const prizeBreakdown = computed(() => {
  if (!f.value.prize_on) return ''
  const filled = f.value.prize_places.map((p, i) => ({ i, amt: Number(p.amount) || 0 })).filter(p => p.amt > 0)
  if (filled.length < 2) return ''
  return filled.map(p => `${ordinal(p.i + 1)} ${currency.value} ${p.amt.toLocaleString()}`).join(' · ')
})
function togglePrize() { f.value.prize_on = !f.value.prize_on; if (f.value.prize_on && !f.value.prize_places.length) f.value.prize_places = [{ amount: '' }] }
function addPlace() { f.value.prize_places.push({ amount: '' }) }
function removePlace(i) { if (f.value.prize_places.length > 1) f.value.prize_places.splice(i, 1) }


// ── GAME state ──
const cityCourts = ref([])        // every bookable court in the city (browse-able cards)
const gCourt = ref('')            // selected listing id
const gCourtObj = ref(null)       // the full listing row (needed for slot generation)
const gVenue = ref('')            // selected court's location id
const gVenueName = ref('')
const gOwned = ref(false)         // is the venue Bookboll-owned? (→ full online)
const gCourtLoading = ref(false)
const gDate = ref('')
const gSlots = ref([])            // visible (non-past) slots for the day
const gLoading = ref(false)
const gSel = ref([])              // selected block's slots
const gHoldExpiresAt = ref(0)     // epoch ms the game's picked slots are reserved until (0 = none)
// The chosen court's commission rate — the SAME rate its normal venue bookings
// use (listing → country → 15%), so the host's deposit matches a regular booking.
const gCommissionPct = ref(null)
// How this court may be paid for (the provider's own settings) and the host's pick.
const payPlan = ref({ methods: [], depositAmount: 0, depositPercentage: 0 })
const payMethod = ref('')
const payLabel = PAY_METHOD_LABEL
// Events per-seat commission — the chosen court's COUNTRY rate (admin-set on the
// Countries page), else the platform default. Resolved async like gCommissionPct.
const seatPct = ref(SEAT_FEE_PCT)

// ── TOURNAMENT state ──
const numTeams = ref(0)
// Event/match length is DERIVED from the venue slots the host books — there's no length
// field for games or tournaments. (Only a hangout at the host's OWN spot, which has no
// booking, still uses a manual duration; see gameHours.)
function hoursLabel(h) { return (Number.isInteger(h) ? h : h.toFixed(1)) + ' hr' }
// Minutes from a "HH:MM[:SS]" time — used to infer a match/event length from booked slots.
function toMin(t) { const p = normTime(t).split(':'); return (+p[0]) * 60 + (+p[1]) }
const rounds = ref([])            // [{ games: [{ location_id, courts, listing_id, date, daySlots, loading, slot, err }] }]

// Community meetups may carry their own platform commission rate.
const seatOpts = () => ({ community: !!communityId.value })
onMounted(async () => {
  // Returning from a court's listing page? Restore the draft FIRST (synchronously) so
  // we land on the right step immediately — the court detail loads in the background.
  // A fresh "Host a meetup for this community" click carries ?community= in the URL
  // (the court-pick round trip returns to a bare /host-game, using the draft instead).
  // On that fresh intent, always start at the type chooser — never let a stale saved
  // draft skip straight into a game.
  const fromCommunity = !!route.query.community
  // Auto-restore ONLY the court-pick round trip (a court/slot was just chosen on
  // a listing page — selectedCourtId is set). A plain visit with a leftover
  // draft shows the CHOOSER first, with a continue-or-start-new banner.
  const dType = (hostDraft.active && !fromCommunity && hostDraft.selectedCourtId) ? hostDraft.type : ''
  const restoring = dType === 'game' || dType === 'event' || dType === 'tournament'
  // capture what we need BEFORE clearing the shared draft
  const pendingCourtId = hostDraft.selectedCourtId
  const pendingDate = hostDraft.gameDate
  const pendingSlots = hostDraft.gameSlots
  const pendingHold = hostDraft.pickHoldExpiresAt || 0
  // Community context: fresh from the URL, or carried through the draft round trip.
  communityId.value = String(route.query.community || hostDraft.communityId || '')
  if (communityId.value) loadCommunity(communityId.value).then(cm => { communityName.value = cm?.name || '' })
  // Fresh community intent → drop any unrelated stale draft so the chooser is clean.
  if (fromCommunity && hostDraft.active) clearHostDraft()
  // Leftover draft, no pending court pick → offer it on the chooser (don't clear).
  if (hostDraft.active && !dType) draftAvailable.value = true
  eventSeatFeePct(null, seatOpts()).then(p => { seatPct.value = p })
  const tDraft = dType === 'tournament'
    ? { numTeams: hostDraft.numTeams, games: hostDraft.games, matchRound: hostDraft.matchRound, matchIndex: hostDraft.matchIndex, basics: hostDraft.basics }
    : null

  if (dType === 'game' || dType === 'event') {
    resumedDraft.value = true
    type.value = dType
    if (hostDraft.basics) Object.assign(f.value, hostDraft.basics)
    // Court + time picked → Contact (step 3). Otherwise land where the host left
    // off — the autosaved step (hard-reload recovery) or Location & Schedule.
    step.value = pendingCourtId ? 3 : (hostDraft.step >= 1 && hostDraft.step <= 4 ? hostDraft.step : 2)
    if (pendingCourtId) gCourtLoading.value = true
    clearHostDraft()
  } else if (dType === 'tournament') {
    resumedDraft.value = true
    type.value = 'tournament'
    if (tDraft.basics) Object.assign(f.value, tDraft.basics)
    if (tDraft.numTeams) {
      pickTeams(tDraft.numTeams)            // rebuild the (empty) bracket structure
      ;(tDraft.games || []).forEach(sg => { // re-apply every already-scheduled game
        const gm = rounds.value[sg.round]?.games[sg.index]
        if (gm) Object.assign(gm, { location_id: sg.location_id, listing_id: sg.listing_id, owned: sg.owned, date: sg.date, slot: sg.slot, venueName: sg.venueName, courtName: sg.courtName, commissionPct: sg.commissionPct, capacity: sg.capacity, holdExpiresAt: sg.holdExpiresAt })
      })
      // Land the mobile bracket back on the round the host was just scheduling (else first open).
      activeBracketRound.value = tDraft.matchRound != null ? tDraft.matchRound : defaultBracketRound()
      step.value = 3                        // land back on the bracket chart
    } else {
      // Autosaved before teams were picked (hard-reload recovery) → resume the step.
      step.value = hostDraft.step >= 1 && hostDraft.step <= 5 ? hostDraft.step : 1
    }
    clearHostDraft()
  }

  if (!restoring && isCustomer.value) {
    f.value.host_name = customer.value.full_name || ''
    const savedPhone = getPrefillPhone(selectedCountry.value?.id)   // this country's number, else the account's
    f.value.host_phone = savedPhone
    f.value.host_whatsapp = savedPhone
  }

  loadMyManagedCommunities()

  await Promise.all([probeGames(), probeTournaments(), probeHangoutLocation()])
  const [locs, courts] = await Promise.all([loadLocations(selectedCity.value?.id), loadFacilities(selectedCity.value?.id)])
  venues.value = locs            // venues (locations) — name lookups
  cityCourts.value = courts      // court cards — browsing (games + tournament matches)

  if ((dType === 'game' || dType === 'event') && pendingCourtId) {
    const d = await loadListing(pendingCourtId)
    const card = cityCourts.value.find(c => c.id === pendingCourtId) || { id: pendingCourtId, locationId: d?.location?.id, pinText: d?.location?.name }
    applyCourt(card, d)              // resets gDate/gSel…
    gDate.value = pendingDate || ''  // …then restore the date + slots picked on the listing page
    gSel.value = pendingSlots || []
    gHoldExpiresAt.value = pendingHold
    gCourtLoading.value = false
  } else if (dType === 'tournament' && pendingCourtId && pendingSlots?.length && tDraft && tDraft.matchRound != null) {
    // Slot the court+date+time the host just picked into the match they were scheduling.
    const gm = rounds.value[tDraft.matchRound]?.games[tDraft.matchIndex]
    if (gm) {
      const d = await loadListing(pendingCourtId)
      const card = cityCourts.value.find(c => c.id === pendingCourtId) || { id: pendingCourtId, locationId: d?.location?.id, pinText: d?.location?.name, name: d?.listing?.name }
      const sorted = pendingSlots.slice().sort((a, b) => String(a.start_time).localeCompare(String(b.start_time)))
      Object.assign(gm, {
        location_id: card.locationId, listing_id: card.id,
        owned: d?.location ? !d.location.owner_user_id : false,
        date: pendingDate || '',
        slot: { start_time: sorted[0].start_time, end_time: sorted[sorted.length - 1].end_time, price: sorted.reduce((n, s) => n + (Number(s.price) || 0), 0) },
        venueName: card.pinText || d?.location?.name || '', courtName: card.name || d?.listing?.name || '',
        commissionPct: await courtCommissionPct(card.id),   // deposit at this court's own booking rate
        capacity: Math.max(1, Number(d?.listing?.max_bookings_per_slot) || 1),   // games this court can host per slot
        holdExpiresAt: pendingHold,   // this match's court is reserved until here
      })
    }
  }
})

// ── "Post as" — personal, or a community the host owns/admins ────────────────
// Shown on the type chooser. Picking a community stamps community_id on publish
// (same mechanism as arriving via a community's Host button / ?community=).
const myManagedCommunities = ref([])
async function loadMyManagedCommunities() {
  if (!isCustomer.value) return
  try {
    const { myCommunities, canManage } = await import('../../customer/community.js')
    const rows = await myCommunities(customer.value.id)
    myManagedCommunities.value = rows.filter(c => canManage(c.membership))
  } catch { myManagedCommunities.value = [] }
}
function postAs(id, name) {
  communityId.value = id
  communityName.value = name
}

// ── step model ──
// An "event" is a simpler, generic game (no sport/skill/prize), so it shares the game flow.
const isGameLike = computed(() => type.value === 'game' || type.value === 'event')

// Live rounds (round robin / Swiss / king of the court, 1–2 players per side across
// courts) only make sense for small court/racket sports — never for team sports like
// football. Gate the whole "Run rounds" section to those.
const ROUNDS_SPORTS = ['padel', 'paddle', 'tennis', 'pickle', 'badminton', 'squash', 'racquet', 'ping']

// ── Sport: ONE searchable dropdown of the sports taxonomy (the same 'Sports'
// types the admin listing form uses). Typing filters the list — and anything
// typed that isn't in it is simply kept as the sport, so nothing is blocked.
const SPORT_OPTIONS = subcategoriesFor('sports').filter(t => t !== 'Other')
// Hangouts can be ANY activity — bowling night, board games, casual football —
// so their list is the full hangout taxonomy plus the sports.
const ACTIVITY_OPTIONS = [
  ...subcategoriesFor('hangout').filter(t => t !== 'Other'),
  ...SPORT_OPTIONS,
]
const sportChoices = computed(() => type.value === 'event' ? ACTIVITY_OPTIONS : SPORT_OPTIONS)
const sportOpen = ref(false)
const filteredSports = computed(() => {
  const q = (f.value.sport || '').trim().toLowerCase()
  if (!q) return sportChoices.value
  const hits = sportChoices.value.filter(t => t.toLowerCase().includes(q))
  return hits.length ? hits : sportChoices.value
})
function pickSport(t) { f.value.sport = t; sportOpen.value = false }
function onSportBlur() { setTimeout(() => { sportOpen.value = false }, 150) }
const supportsRounds = computed(() => {
  const s = (f.value.sport || '').trim().toLowerCase()
  return !!s && ROUNDS_SPORTS.some(k => s.includes(k))
})
// If the sport changes to one that can't run rounds, drop any format the host had picked.
watch(supportsRounds, (ok) => { if (!ok) { f.value.play_format = ''; f.value.courts_count = 1; f.value.team_size = 2 } })
const steps = computed(() => type.value === 'tournament'
  ? ['Details', 'Teams', 'Schedule', 'Contact & rules', 'Review']
  : ['Details & tickets', 'Place & time', 'Contact & rules', 'Review'])
const lastStep = computed(() => steps.value.length)
const stepHeading = ref(null)
watch(step, async () => { await nextTick(); stepHeading.value?.focus({ preventScroll: true }); stepHeading.value?.scrollIntoView({block:'start',behavior:'auto'}) })

// ── Hangout: WHERE it's hosted is a screen of its own ─────────────────────────
// A hangout runs either at a Bookboll venue (browse → open a court → book a slot) or
// at the host's own spot (no booking at all — just a name, date and time). Those are
// two different flows, so step 2 opens on a choice screen and only then shows the
// matching one; the old tab strip sat above the venue cards and read as a filter.
// Empty spot_mode = nothing chosen yet. Games/tournaments never see this screen.
const spotChoiceOn = computed(() => type.value === 'event' && hangoutLocationEnabled.value !== false)
const needsSpotChoice = computed(() => spotChoiceOn.value && step.value === 2 && !f.value.spot_mode)
function chooseSpot(mode) { f.value.spot_mode = mode; err.value = '' }
// Own-spot hangouts switched off platform-side while one was being set up → there's no
// choice left to make and no own-spot form to show; fall back to the venue flow.
watch(hangoutLocationEnabled, (on) => { if (on === false && f.value.spot_mode === 'own') f.value.spot_mode = 'venue' })

// Steps that browse venue/court cards (or the tournament bracket) get a wider canvas;
// plain form steps — and the hosting-mode choice — stay a comfortable centered column.
const isWideStep = computed(() =>
  (type.value === 'tournament' && step.value === 3) ||
  (isGameLike.value && step.value === 2 && !needsSpotChoice.value && !(type.value === 'event' && f.value.spot_mode === 'own'))
)

// A clear title + helper line for every step, so each screen reads as a designed page.
const stepHead = computed(() => {
  const T = type.value, S = step.value, city = selectedCity.value?.name || 'your city'
  if (S === lastStep.value) return { title: 'Review your event', sub: 'Check your event, what guests pay and what you pay before publishing.' }
  if (S === lastStep.value - 1) return { title: 'Contact & joining rules', sub: 'Tell guests how to reach you and decide when requests close.' }
  if (T === 'tournament') {
    if (S === 1) return { title: 'Tournament basics', sub: 'Name it, set the entry fee and an optional prize pot.' }
    if (S === 2) return { title: 'Format', sub: 'Choose how many teams will play.' }
    if (S === 3) return schedulingMatch.value
      ? { title: 'Pick a venue for this game', sub: `${roundName(schedulingMatch.value.r, rounds.value.length)} · Game ${schedulingMatch.value.i + 1} — open a venue to choose the date & time.` }
      : { title: 'Schedule the games', sub: 'Tap each game to set its venue, date & time — or auto‑schedule from Game 1.' }
  }
  if (S === 1) return T === 'event'
    ? { title: 'Hangout basics', sub: 'Name it, set who can join and how long it runs.' }
    : { title: 'Game basics', sub: 'Name it, set the price, and add a few photos.' }
  if (S === 2) {
    if (needsSpotChoice.value) return { title: 'Where will you host it?', sub: 'Book a Bookboll venue, or run it at a spot of your own. You can switch back on this step.' }
    if (T === 'event' && f.value.spot_mode === 'own') return { title: 'Where & when', sub: 'Use your own spot — set the place, date and time.' }
    return { title: 'Pick a venue & time', sub: `Browse venues in ${city}, open one to choose your date & time, then come back to finish.` }
  }
  return { title: '', sub: '' }
})

function chooseType(t) { type.value = t; step.value = 1; err.value = ''; mediaAttempted.value = false }

// ── GAME: slot loading ──
async function loadGameSlots() {
  gSel.value = []
  gSlots.value = []
  if (!gCourtObj.value || !gDate.value) return
  gLoading.value = true
  const avail = await loadDayAvailability(gCourtObj.value, gDate.value)
  gSlots.value = buildDaySlots(gCourtObj.value, gDate.value, avail).filter(s => !s.past)
  gLoading.value = false
}
// Apply a chosen court (from its loaded listing detail) to the game state.
function applyCourt(card, d) {
  gCourt.value = card.id; gVenue.value = card.locationId; gVenueName.value = card.pinText
  gDate.value = ''; gSel.value = []; gSlots.value = []
  gCourtObj.value = d?.listing || null
  gOwned.value = d?.location ? !d.location.owner_user_id : false
  gCommissionPct.value = null   // → default rate until the court's own rate resolves
  courtCommissionPct(card.id).then(p => { if (gCourt.value === card.id) gCommissionPct.value = p })
  payPlan.value = { methods: [], depositAmount: 0, depositPercentage: 0 }
  payMethod.value = ''
  courtPaymentPlan(card.id).then(pl => {
    if (gCourt.value !== card.id) return
    payPlan.value = pl
    payMethod.value = pl.methods[0] || ''
  })
  eventSeatFeePct(card.id, seatOpts()).then(p => { if (gCourt.value === card.id) seatPct.value = p })
  if (!f.value.sport && gCourtObj.value?.sport_category) f.value.sport = gCourtObj.value.sport_category
}
// Open the court's FULL listing page (with a "Select this court" banner). The wizard
// draft is stashed so it survives the navigation and is restored on return.
function openCourtPage(card) {
  saveHostDraft({ type: type.value, basics: { ...f.value }, communityId: communityId.value })
  router.push(`/listings/${card.id}?host=${type.value}`)
}
// Own-spot hangout duration only (no venue booking there). For a venue game/hangout the
// length is DERIVED from the booked slots, so this is unused in that case.
const gameHours = ref(1)
const ownSpot = computed(() => type.value === 'event' && f.value.spot_mode === 'own')
// The event window players see:
//   • venue (game/hangout) → the span of the booked slots (first start → last end).
//   • own spot (hangout)   → the host's start time + the chosen duration (no booking).
const gameRange = computed(() => {
  if (ownSpot.value) {
    if (!f.value.own_start) return null
    const start = normTime(f.value.own_start)
    return { start, end: addMinutes(start, gameHours.value * 60), mins: gameHours.value * 60 }
  }
  if (!gSel.value.length) return null
  const start = normTime(gSel.value[0].start_time)
  const end = normTime(gSel.value[gSel.value.length - 1].end_time)
  return { start, end, mins: toMin(end) - toMin(start) }
})

// ── Stripe Connect (host payouts, SIMULATED) ─────────────────────────────────
// With Stripe tickets, joiners pay online and Bookboll's fee is split from each
// ticket automatically — so the host skips the upfront per-seat service fee.
const stripeAcct = ref(null)
const stripeBusy = ref(false)
const stripeErr = ref('')
const stripeConnected = computed(() => isStripeConnected(stripeAcct.value))
// 'connect' = Bookboll opens a Stripe account for them; 'keys' = they bring their own.
const payoutTab = ref('connect')
const keyForm = ref({ secret: '', publishable: '' })

async function loadStripe() { stripeAcct.value = isCustomer.value ? await loadStripeConnect() : null }
watch(() => customer.value?.id, loadStripe, { immediate: true })

// Stripe opens the account IN A COUNTRY and fixes it there forever — it decides
// which ID and bank details the host is asked for. Which countries are possible
// depends on Bookboll's own Stripe account, so the server supplies the list.
const stripeCountries = ref([])
const stripeCountry = ref('')
const stripeCountriesLoaded = ref(false)
// Where the host appears to be, when Stripe won't open an account there. Their
// own Stripe keys are then the only route, so we say so instead of leaving them
// to work it out from a dropdown of countries they have nothing to do with.
const stripeHomeUnsupported = ref('')

async function loadStripeCountryList() {
  if (!isCustomer.value || stripeCountriesLoaded.value) return
  const r = await loadStripeCountries()
  stripeCountriesLoaded.value = true
  if (r.error) { stripeErr.value = r.error; return }
  stripeCountries.value = r.countries
  // Prefill with our best guess, but the host always gets the final say — plenty
  // of people bank somewhere other than where they live.
  stripeCountry.value = r.locked || r.default || ''
  stripeHomeUnsupported.value = (r.detected && r.detectedSupported === false) ? (r.detectedName || r.detected) : ''
  // Nothing in the list can work for them, so open on the tab that can.
  if (stripeHomeUnsupported.value && !stripeAcct.value && !stripeCountry.value) payoutTab.value = 'keys'
}
// Fetched as soon as we have a customer: the answer decides which payout tab to
// open, so waiting for them to click "Connect" would be too late.
watch(() => customer.value?.id, () => { loadStripeCountryList() }, { immediate: true })

async function connectStripe() {
  if (!isCustomer.value && !(await ensureSignedIn())) return
  await loadStripeCountryList()
  if (!stripeCountry.value) {
    stripeErr.value = 'Choose the country your bank account is in first.'
    return
  }
  stripeBusy.value = true
  stripeErr.value = ''
  const base = window.location.origin + route.path + '?community=' + (route.query.community || '')
  const r = await startStripeConnect({
    returnUrl: base + '&stripe=done',
    refreshUrl: base + '&stripe=retry',
    country: stripeCountry.value,
  })
  stripeBusy.value = false
  if (r.error) { stripeErr.value = r.error; return }
  // Onboarding happens on Stripe, so keep their progress before leaving.
  saveHostDraft({ type: type.value, basics: { ...f.value }, communityId: communityId.value })
  window.location.href = r.url
}

async function submitStripeKeys() {
  if (!isCustomer.value && !(await ensureSignedIn())) return
  stripeBusy.value = true
  stripeErr.value = ''
  const r = await saveStripeKeys({ secretKey: keyForm.value.secret, publishableKey: keyForm.value.publishable })
  stripeBusy.value = false
  if (r.error) { stripeErr.value = r.error; return }
  stripeAcct.value = r.account
  keyForm.value = { secret: '', publishable: '' }
  f.value.ticket_mode = 'stripe'
}

async function refreshStripe() {
  stripeBusy.value = true
  stripeErr.value = ''
  const r = await syncStripeConnect()
  stripeBusy.value = false
  if (r.account) stripeAcct.value = r.account
  else if (r.error) stripeErr.value = r.error
}

async function unlinkStripe() {
  stripeBusy.value = true
  await disconnectStripe()
  stripeBusy.value = false
  stripeAcct.value = null
  stripeErr.value = ''
}
// Effective mode: Stripe tickets apply to ALL event types, but only once connected.
const effectiveTicketMode = computed(() =>
  f.value.ticket_mode === 'stripe' && stripeConnected.value ? 'stripe' : 'arrival')

// Instant-join hint adapts to the event type and how joiners pay.
const instantJoinHint = computed(() => {
  const paid = Number(f.value.price_per_person) > 0
  const tourn = type.value === 'tournament'
  if (paid && f.value.ticket_mode === 'stripe') return tourn
    ? 'Any team that pays gets in automatically — their entry fee is charged via Stripe the moment they register.'
    : 'Anyone who pays gets in automatically — their ticket is charged via Stripe the moment they join.'
  if (paid) return tourn
    ? 'Teams grab a bracket spot instantly and pay you on arrival — no approval step.'
    : 'Anyone can grab a spot instantly and pays you on arrival — no approval step.'
  return tourn ? 'Teams register instantly until the bracket is full — no approval step.' : "Anyone can join instantly until it's full — no approval step."
})

// What the host pays now vs on arrival: court deposit (Bookboll commission) or full
// online for Bookboll venues, PLUS the non-refundable seats service fee (WAIVED
// upfront when Stripe tickets are on — the fee is split from each ticket instead).
const hostCharge = computed(() => {
  const cur = currency.value
  if (isGameLike.value) {
    // Hangout at the host's own spot: no court, so everything due is Bookboll's.
    // Selling seats → the usual seats percentage. FREE → there is no seats value to
    // take a percentage of, so it pays the flat publish fee instead (in USD, the one
    // currency the platform sets it in). Mirrors priceOwnSpot() on the server.
    if (ownSpot.value) {
      const seatsValue = (Number(f.value.price_per_person) || 0) * (Number(f.value.max_players) || 0)
      if (seatsValue <= 0) {
        const min = Number(ownSpotMinFee.value) || 0
        return { cur: ownSpotMinFeeCurrency.value, courtTotal: 0, courtNow: 0, courtArrival: 0, serviceFee: min, dueNow: min, dueArrival: 0, bookbollOwned: false, ownSpotMin: true }
      }
      const fee = effectiveTicketMode.value === 'stripe' ? 0 : seatFeeAt(seatsValue, seatPct.value)
      return { cur, courtTotal: 0, courtNow: 0, courtArrival: 0, serviceFee: fee, dueNow: fee, dueArrival: 0, bookbollOwned: false }
    }
    const courtTotal = gSel.value.reduce((n, s) => n + (Number(s.price) || 0), 0)
    // Pay the court the way the provider allows — the same options and deposit a
    // regular booking of this court would offer.
    const courtNow = splitCourt(courtTotal, payMethod.value, payPlan.value).now
    // Seats = the cap — games and hangouts both set one.
    const seats = Number(f.value.max_players) || 0
    const serviceFee = effectiveTicketMode.value === 'stripe' ? 0 : seatFeeAt((Number(f.value.price_per_person) || 0) * seats, seatPct.value)
    return { cur, courtTotal, courtNow, courtArrival: courtTotal - courtNow, serviceFee, dueNow: courtNow + serviceFee, dueArrival: courtTotal - courtNow, bookbollOwned: payMethod.value === 'online', payMethod: payMethod.value }
  }
  let courtTotal = 0, courtNow = 0
  rounds.value.forEach(rd => rd.games.forEach(gm => {
    const p = Number(gm.slot?.price) || 0
    courtTotal += p
    courtNow += gm.owned ? p : courtDeposit(p, gm.commissionPct)
  }))
  const serviceFee = effectiveTicketMode.value === 'stripe' ? 0 : seatFeeAt((Number(f.value.price_per_person) || 0) * (Number(numTeams.value) || 0), seatPct.value)
  return { cur, courtTotal, courtNow, courtArrival: courtTotal - courtNow, serviceFee, dueNow: courtNow + serviceFee, dueArrival: courtTotal - courtNow }
})

// ── Bookboll's cut, shown AS the host types the price ────────────────────────
// The service fee used to appear only on the last screen ("What you'll pay now"), so a
// host setting a price found out what it costs them at the very end. These reuse the
// same percentage and rounding as hostCharge, so the preview and the bill can't disagree.
const feeUnit = computed(() => type.value === 'tournament' ? 'team' : type.value === 'event' ? 'person' : 'player')
const feeUnits = computed(() => feeUnit.value === 'person' ? 'people' : feeUnit.value + 's')
// How many heads the fee is charged on: the roster cap (games/hangouts) or the bracket
// size (tournaments — not chosen until step 2, so 0 there means "not known yet").
const feeCount = computed(() => type.value === 'tournament' ? (Number(numTeams.value) || 0) : (Number(f.value.max_players) || 0))
const feePerHead = computed(() => seatFeeAt(Number(f.value.price_per_person) || 0, seatPct.value))
const feeTotal = computed(() => seatFeeAt((Number(f.value.price_per_person) || 0) * feeCount.value, seatPct.value))
const feeNet = computed(() => Math.max(0, (Number(f.value.price_per_person) || 0) - feePerHead.value))

// The full court payment may include provider revenue. Only an explicit
// server quote of commission + Bookboll fees can fund a rewards discount.
const rewardSelection = ref(null)
const rewardsControl = ref(null)
const loyaltyCountryId = computed(() => ownSpot.value || type.value === 'tournament' ? selectedCountry.value?.id : gCourtObj.value?.country_id || selectedCountry.value?.id || null)
const rewardsCheckout = computed(() => ({
  game_type:type.value,listing_id:ownSpot.value ? null : gCourt.value,
  country_id:loyaltyCountryId.value,city_id:selectedCity.value?.id,
  game_date:ownSpot.value ? f.value.own_date : gDate.value,
  start_time:gameRange.value.start,end_time:gameRange.value.end,
  price_per_person:Number(f.value.price_per_person)||0,max_players:Number(f.value.max_players)||0,
  num_teams:Number(numTeams.value)||0,ticket_mode:effectiveTicketMode.value,
  payment_method:payMethod.value,community_id:communityId.value || null,
  matches:type.value === 'tournament' ? rounds.value.flatMap((rd,r)=>rd.games.map((gm,g)=>({round:r,index:g,listing_id:gm.listing_id,date:gm.date,start_time:gm.slot?.start_time,end_time:gm.slot?.end_time}))) : [],
}))
const loyaltyAmount = computed(() => rewardSelection.value?.amount || 0)
const dueNowNet = computed(() => Math.max(0, +(hostCharge.value.dueNow - loyaltyAmount.value).toFixed(2)))

// The event's START as a datetime-local string ("YYYY-MM-DDTHH:MM"), used to cap the
// "request to join" deadline — you can't apply once the event has started. Games/hangouts
// = their (single) date+start; tournaments = the EARLIEST scheduled match.
const eventStartLocal = computed(() => {
  if (isGameLike.value) {
    if (ownSpot.value) return (f.value.own_date && f.value.own_start) ? `${f.value.own_date}T${normTime(f.value.own_start)}` : ''
    return (gDate.value && gameRange.value) ? `${gDate.value}T${normTime(gameRange.value.start)}` : ''
  }
  let best = ''
  rounds.value.forEach(rd => rd.games.forEach(gm => {
    if (gm.date && gm.slot?.start_time) {
      const dt = `${gm.date}T${normTime(gm.slot.start_time)}`
      if (!best || dt < best) best = dt
    }
  }))
  return best
})

function venueName(id) { return venues.value.find(v => v.id === id)?.name || 'the venue' }
// The balance is paid to EACH provider at THAT venue on THAT date — not all at once.
// Group the on-arrival amounts by venue + date so the host sees each separate payment.
const arrivalBreakdown = computed(() => {
  if (isGameLike.value) {
    if (gOwned.value) return []
    const arrival = hostCharge.value.courtArrival
    return arrival > 0 ? [{ name: venueName(gVenue.value), date: gDate.value, amount: arrival, games: 1 }] : []
  }
  const map = {}
  rounds.value.forEach(rd => rd.games.forEach(gm => {
    if (!gm.slot || gm.owned) return
    const price = Number(gm.slot.price) || 0
    const arrival = price - courtDeposit(price, gm.commissionPct)
    if (arrival <= 0) return
    const key = `${gm.location_id}|${gm.date}`
    if (!map[key]) map[key] = { name: venueName(gm.location_id), date: gm.date, amount: 0, games: 0 }
    map[key].amount += arrival; map[key].games += 1
  }))
  return Object.values(map).sort((a, b) => (a.date || '').localeCompare(b.date || '') || a.name.localeCompare(b.name))
})

// ── TOURNAMENT: bracket preview + scheduling ──
const bracketPreview = computed(() => {
  if (!numTeams.value) return null
  const tr = roundsFor(numTeams.value)
  return { rounds: tr, games: totalGames(numTeams.value), list: Array.from({ length: tr }, (_, r) => ({ name: roundName(r, tr), games: matchesInRound(numTeams.value, r) })) }
})
function newGame() {
  return { location_id: '', courts: [], listing_id: '', owned: false, date: '', daySlots: [], loading: false, slot: null, err: '', venueName: '', courtName: '' }
}
function pickTeams(n) {
  numTeams.value = n
  rounds.value = Array.from({ length: roundsFor(n) }, (_, r) => ({ games: Array.from({ length: matchesInRound(n, r) }, newGame) }))
  activeBracketRound.value = 0
}

// ── Bracket-chart scheduling ──────────────────────────────────────────────────
// The Schedule step shows the bracket as cards. Tapping a card opens a court browse for
// THAT match; tapping a court opens its real listing page (?host=tournament) to pick the
// date + match-length block — the SAME flow as hosting a game. Because navigating away
// unmounts this wizard, the whole draft is stashed in hostDraft and rebuilt on return.
const schedulingMatch = ref(null)   // { r, i } while picking a court for a match, else null
const scheduledCount = computed(() => rounds.value.reduce((n, rd) => n + rd.games.filter(g => g.slot).length, 0))
function scheduleMatch(r, i) { schedulingMatch.value = { r, i }; err.value = '' }
function backToChart() { schedulingMatch.value = null }
function serializeGames() {
  return rounds.value.flatMap((rd, r) => rd.games.map((gm, i) => ({
    round: r, index: i, location_id: gm.location_id, listing_id: gm.listing_id, owned: gm.owned,
    date: gm.date, slot: gm.slot ? { ...gm.slot } : null, venueName: gm.venueName, courtName: gm.courtName,
    commissionPct: gm.commissionPct, capacity: gm.capacity, holdExpiresAt: gm.holdExpiresAt,
  })))
}
// Open a court's listing page to schedule the match in schedulingMatch.
function openMatchCourt(card) {
  const m = schedulingMatch.value
  if (!m) return
  saveHostDraft({
    type: 'tournament', basics: { ...f.value },
    numTeams: numTeams.value, communityId: communityId.value,
    games: serializeGames(), matchRound: m.r, matchIndex: m.i,
    selectedCourtId: '', gameDate: '', gameSlots: null,   // reset the pick (so a cancel doesn't re-apply)
  })
  router.push(`/listings/${card.id}?host=tournament`)
}
// Per-game venue change → load that venue's courts.
async function onGameVenueT(r, g) {
  const gm = rounds.value[r].games[g]
  gm.courts = []; gm.listing_id = ''; gm.daySlots = []; gm.slot = null; gm.err = ''; gm.owned = false
  if (!gm.location_id) return
  const data = await loadFacility(gm.location_id)
  gm.courts = data?.listings || []
  gm.owned = !data?.location?.owner_user_id   // Bookboll venue → full online court payment
  gm.listing_id = gm.courts[0]?.id || ''
  if (gm.date) await loadTGameSlots(r, g)
}
function gameCourtObj(gm) { return (gm.courts || []).find(c => c.id === gm.listing_id) || null }
async function loadTGameSlots(r, g) {
  const gm = rounds.value[r].games[g]
  gm.slot = null; gm.err = ''; gm.daySlots = []
  const obj = gameCourtObj(gm)
  if (!obj || !gm.date) return
  gm.loading = true
  // Deposit at this court's own booking commission rate (resolves in parallel).
  courtCommissionPct(gm.listing_id).then(p => { gm.commissionPct = p })
  eventSeatFeePct(gm.listing_id, seatOpts()).then(p => { seatPct.value = p })
  const avail = await loadDayAvailability(obj, gm.date)
  gm.daySlots = buildDaySlots(obj, gm.date, avail).filter(s => s.available)   // each game is one bookable slot
  gm.loading = false
}
function pickGameBlock(r, g, b) {
  const gm = rounds.value[r].games[g]
  gm.slot = { start_time: b.start_time, end_time: b.end_time, price: Number(b.price) || 0 }
  gm.err = ''
}
const allGamesScheduled = computed(() => rounds.value.length > 0 && rounds.value.every(rd => rd.games.every(g => g.slot)))

// ── Mobile bracket: one round in focus at a time (Google-knockout style tabs) ──
// The desktop bracket scrolls its round columns side-by-side; on a phone that's a
// cramped horizontal scroll, so ≤760px shows round tabs + the active round's cards.
const activeBracketRound = ref(0)
const bracketSlideDir = ref(1)   // 1 = forward (later round), -1 = back — slide direction
const activeBracketCol = computed(() => rounds.value[activeBracketRound.value] || null)
// First round that still has an unscheduled game (else the last) — a sensible landing tab.
function defaultBracketRound() {
  const open = rounds.value.findIndex(rd => rd.games.some(g => !g.slot))
  return open >= 0 ? open : Math.max(0, rounds.value.length - 1)
}
function goBracketRound(r) {
  bracketSlideDir.value = r >= activeBracketRound.value ? 1 : -1
  activeBracketRound.value = r
}
// Swipe left/right to change round (pointer events → touch + mouse-drag).
let bswX = 0, bswY = 0, bswId = null
function onBracketSwipeStart(e) { bswId = e.pointerId; bswX = e.clientX; bswY = e.clientY }
function onBracketSwipeEnd(e) {
  if (bswId === null || e.pointerId !== bswId) return
  bswId = null
  const dx = e.clientX - bswX, dy = e.clientY - bswY
  if (Math.abs(dx) <= Math.abs(dy)) return
  const to = activeBracketRound.value + (dx < 0 ? 1 : -1)
  if (to >= 0 && to < rounds.value.length) goBracketRound(to)
}

// Auto-schedule: once Game 1 is set, fill every remaining game on the same court —
// the HOST picks the pacing first (preferences modal), then we plan around the
// venue's real availability and report the resulting plan back. Host can still edit.
const autoBusy = ref(false)
const autoErr = ref('')
const autoModal = ref(false)      // preferences sheet (opens on Auto-schedule)
const autoMode = ref('chain')     // 'chain' = back-to-back · 'rounds' = each round its own day
const autoGap = ref(0)            // rest days between rounds ('rounds' mode)
const autoDayStart = ref('same')  // later days start: 'same' = Game 1's time · 'earliest' = first free slot
const autoPlan = ref(null)        // { rounds:[{name,label}], notes:[] } — shown to the host after scheduling
const firstGameReady = computed(() => {
  const g0 = rounds.value[0]?.games[0]
  return !!(g0 && g0.location_id && g0.listing_id && g0.date && g0.slot)
})
function openAutoSchedule() { autoErr.value = ''; autoModal.value = true }

// Consecutive-slot blocks available on one day's open slots, starting from index `from`.
function blocksOnDay(open, from, count, max) {
  const out = []
  let i = from
  while (i + count <= open.length && out.length < max) {
    let ok = true
    for (let j = 1; j < count; j++) if (normTime(open[i + j].start_time) !== normTime(open[i + j - 1].end_time)) { ok = false; break }
    if (ok) { out.push(open.slice(i, i + count)); i += count }   // back-to-back
    else i++
  }
  return out
}

async function runAutoSchedule() {
  const g0 = rounds.value[0]?.games[0]
  if (!firstGameReady.value) return
  autoModal.value = false
  autoErr.value = ''; autoPlan.value = null; autoBusy.value = true
  let courtObj = (g0.courts || []).find(c => c.id === g0.listing_id)
  if (!courtObj) { const d = await loadListing(g0.listing_id); courtObj = d?.listing || null }   // after a nav rebuild, courts[] is empty
  if (!courtObj) { autoErr.value = "Couldn't load Game 1's venue — re-pick it and try again."; autoBusy.value = false; return }
  // Match length is inferred from Game 1's booked slots (number of consecutive slots).
  const slotDur = Number(courtObj.slot_duration) || 60
  const count = Math.max(1, Math.round((toMin(g0.slot.end_time) - toMin(g0.slot.start_time)) / slotDur))
  const need = totalGames(numTeams.value)
  const dayMap = {}        // date → that day's available slots (for the per-game grid)
  const notes = []         // smart decisions worth telling the host about
  const HORIZON = 45       // total days we're willing to scan
  async function openSlotsFor(ds) {
    if (!(ds in dayMap)) {
      const avail = await loadDayAvailability(courtObj, ds)
      dayMap[ds] = buildDaySlots(courtObj, ds, avail).filter(s => s.available)
    }
    return dayMap[ds]
  }
  // Index of the first slot at/after Game 1's start hour (end of list if none left).
  function fromG0Hour(open) {
    let i = 0
    while (i < open.length && normTime(open[i].start_time) < normTime(g0.slot.start_time)) i++
    return i
  }

  const perRound = rounds.value.map(rd => rd.games.length)
  const roundBlocks = []   // per round: [{ date, slots }]

  if (autoMode.value === 'chain') {
    // ── One chain, back-to-back: a single strictly-forward scan (bracket order
    // is inherent — block k+1 never starts before block k), rolling to the next
    // day whenever a day runs out. Sliced into rounds afterwards.
    const blocks = []
    const d = new Date(g0.date + 'T00:00:00')
    for (let day = 0; day < HORIZON && blocks.length < need; day++) {
      const ds = ymd(d)
      const open = await openSlotsFor(ds)
      const from = day === 0 ? fromG0Hour(open) : 0
      for (const slots of blocksOnDay(open, from, count, need - blocks.length)) blocks.push({ date: ds, slots })
      d.setDate(d.getDate() + 1)
    }
    if (blocks.length < need) {
      autoErr.value = `Couldn't fit ${need} matches from there — only room for ${blocks.length}. Try an earlier start, a shorter Game 1, or another venue.`
      autoBusy.value = false; return
    }
    let k = 0
    for (const n of perRound) roundBlocks.push(blocks.slice(k, k += n))
  } else {
    // ── One round per day: each round starts on its own date (Round 1 on Game 1's
    // date; every later round the day after the previous round ended + rest days),
    // still rolling forward if a round doesn't fit in one day.
    const d = new Date(g0.date + 'T00:00:00')
    let dayOffset = 0
    for (let r = 0; r < perRound.length; r++) {
      const needR = perRound[r]
      const got = []
      let firstDayOfRound = true
      while (got.length < needR && dayOffset < HORIZON) {
        const ds = ymd(d)
        const open = await openSlotsFor(ds)
        let from = 0
        if (r === 0 && firstDayOfRound) from = fromG0Hour(open)
        else if (autoDayStart.value === 'same') {
          from = fromG0Hour(open)
          if (from >= open.length && open.length) {
            from = 0   // nothing left at Game 1's hour → fall back to the day's first free slot
            notes.push(`${fmtDate(ds)}: nothing free from ${fmtTime(g0.slot.start_time)} — used the venue's earliest free slot instead.`)
          }
        }
        for (const slots of blocksOnDay(open, from, count, needR - got.length)) got.push({ date: ds, slots })
        if (got.length < needR) { d.setDate(d.getDate() + 1); dayOffset++; firstDayOfRound = false }
      }
      if (got.length < needR) {
        const placed = roundBlocks.reduce((n, b) => n + b.length, 0) + got.length
        autoErr.value = `Couldn't fit all ${need} matches — only room for ${placed}. Try an earlier start, a shorter Game 1, fewer rest days, or another venue.`
        autoBusy.value = false; return
      }
      roundBlocks.push(got)
      if (r < perRound.length - 1) {
        // Next round starts the day AFTER this round's last game, plus rest days.
        const target = new Date(got[got.length - 1].date + 'T00:00:00')
        target.setDate(target.getDate() + 1 + Math.max(0, autoGap.value))
        while (ymd(d) < ymd(target)) { d.setDate(d.getDate() + 1); dayOffset++ }
      }
    }
  }

  // Apply the plan to the bracket.
  for (let r = 0; r < rounds.value.length; r++) {
    for (let g = 0; g < rounds.value[r].games.length; g++) {
      const b = roundBlocks[r][g], gm = rounds.value[r].games[g]
      gm.location_id = g0.location_id; gm.courts = g0.courts; gm.owned = g0.owned
      gm.venueName = g0.venueName; gm.courtName = g0.courtName; gm.commissionPct = g0.commissionPct
      gm.capacity = Math.max(1, Number(courtObj.max_bookings_per_slot) || 1)
      gm.listing_id = g0.listing_id; gm.date = b.date; gm.daySlots = dayMap[b.date] || []; gm.err = ''
      gm.slot = { start_time: b.slots[0].start_time, end_time: b.slots[b.slots.length - 1].end_time, price: b.slots.reduce((n, x) => n + (Number(x.price) || 0), 0) }
    }
  }

  // Tell the host what was decided — per round: date(s), games, time span.
  const planRounds = roundBlocks.map((blocks, r) => {
    const dates = [...new Set(blocks.map(b => b.date))]
    const first = blocks[0], last = blocks[blocks.length - 1]
    let label
    if (dates.length === 1) {
      label = `${fmtDate(dates[0])} · ${blocks.length} game${blocks.length > 1 ? 's' : ''} · ${fmtTime(first.slots[0].start_time)}–${fmtTime(last.slots[last.slots.length - 1].end_time)}`
    } else {
      label = `${fmtDate(dates[0])} → ${fmtDate(dates[dates.length - 1])} · ${blocks.length} games`
      notes.push(`${roundName(r, roundBlocks.length)} didn't fit in one day at this venue — it continues on ${fmtDate(dates[dates.length - 1])}.`)
    }
    return { name: roundName(r, roundBlocks.length), label }
  })
  const allDates = [...new Set(roundBlocks.flat().map(b => b.date))]
  if (autoMode.value === 'chain' && allDates.length > 1) {
    notes.push(`The venue's days fill up, so the chain spans ${allDates.length} days (${fmtDate(allDates[0])} → ${fmtDate(allDates[allDates.length - 1])}).`)
  }
  autoPlan.value = { rounds: planRounds, notes: [...new Set(notes)] }
  autoBusy.value = false
}

// A court hosts only ONE game per time — two matches on the SAME court + date whose
// times overlap is a real double-booking (the exact bug the host hit). Draft matches
// aren't bookings yet, so nothing downstream catches this until publish; surface it
// here and block publishing (like orderError). Overlap is midnight-aware.
const slotClashes = computed(() => {
  const sched = []
  rounds.value.forEach((rd, r) => rd.games.forEach((gm, i) => {
    if (gm.slot && gm.date && gm.listing_id) sched.push({ r, i, id: gm.listing_id, date: gm.date, s: gm.slot.start_time, e: gm.slot.end_time, cap: Math.max(1, Number(gm.capacity) || 1) })
  }))
  // A court can host up to `capacity` games per slot (some listings allow several). Count
  // how many games overlap each one on the SAME court+date; it's only a clash once that
  // count EXCEEDS the court's capacity — a single-capacity court flags any overlap.
  for (let a = 0; a < sched.length; a++) {
    const x = sched[a]
    const others = sched.filter((y, b) => b !== a && x.id === y.id && x.date === y.date && toMinutes(x.s) < endMinutes(y.e) && toMinutes(y.s) < endMinutes(x.e))
    if (others.length + 1 > x.cap) {
      if (x.cap > 1) return `${roundName(x.r, rounds.value.length)} Game ${x.i + 1} has more games booked on that court at the same time than it can hold (max ${x.cap}). Move one to a different time, date or court.`
      const y = others[0]
      return `${roundName(x.r, rounds.value.length)} Game ${x.i + 1} and ${roundName(y.r, rounds.value.length)} Game ${y.i + 1} are booked on the same court at the same time — this court can only host one game per slot. Give one of them a different time, date or court.`
    }
  }
  return ''
})

// Rounds must be time-ordered: every game in a round finishes before the next round's
// earliest game starts (you can't play the final before the semis).
function dtv(date, time) { return new Date(`${date}T${normTime(time)}`).getTime() }
const orderError = computed(() => {
  for (let r = 0; r < rounds.value.length - 1; r++) {
    const cur = rounds.value[r].games.filter(g => g.slot && g.date)
    const nxt = rounds.value[r + 1].games.filter(g => g.slot && g.date)
    if (!cur.length || !nxt.length) continue
    const maxEnd = Math.max(...cur.map(g => dtv(g.date, g.slot.end_time)))
    const minStart = Math.min(...nxt.map(g => dtv(g.date, g.slot.start_time)))
    if (maxEnd > minStart) return `${roundName(r, rounds.value.length)} must finish before ${roundName(r + 1, rounds.value.length)} starts — adjust those dates/times.`
  }
  return ''
})

// ── navigation + validation ──
function validateStep(checkStep = step.value) {
  err.value = ''
  if (checkStep === 1) {
    mediaAttempted.value = true
    const firstInvalid = Object.keys(basicsErrors.value)[0]
    if (firstInvalid) {
      err.value = basicsErrors.value[firstInvalid]
      nextTick(() => {
        const field = ({ title: titleInput, sport: sportInput, max_players: capacityInput, price_per_person: priceInput })[firstInvalid]?.value
        field?.scrollIntoView({ block: 'center', behavior: 'auto' })
        field?.focus({ preventScroll: true })
      })
      return false
    }
    if (mediaErrors.value.description || mediaErrors.value.images) {
      err.value = mediaErrors.value.description || mediaErrors.value.images
      nextTick(() => (mediaErrors.value.description ? descriptionInput.value : photoButton.value)?.focus())
      return false
    }
  }
  if (isGameLike.value) {
    if (checkStep === 2) {
      if (needsSpotChoice.value) return (err.value = "Choose where you'll host it — a Bookboll venue or your own spot."), false
      if (ownSpot.value) {
        if (!f.value.loc_name.trim()) return (err.value = 'Give your spot a name (shown publicly).'), false
        if (!f.value.own_date) return (err.value = 'Pick a date for your hangout.'), false
        if (!f.value.own_start) return (err.value = 'Pick a start time.'), false
      } else if (!gCourt.value || !gDate.value || !gSel.value.length) {
        return (err.value = `Open a venue and pick a date & time for your ${type.value === 'event' ? 'hangout' : 'game'}.`), false
      }
    }
  } else {
    if (checkStep === 2) {
      if (!numTeams.value) return (err.value = 'Choose how many teams will play.'), false
    }
    if (checkStep === 3) {
      if (!allGamesScheduled.value) return (err.value = 'Tap every game in the bracket to set its court, date & time.'), false
      if (slotClashes.value) return (err.value = slotClashes.value), false
      if (orderError.value) return (err.value = orderError.value), false
    }
  }
  return true
}
function next() {
  if (imgUploading.value) { err.value = 'Wait for your photos to finish uploading.'; return }
  if (step.value === lastStep.value - 1) {
    if (!f.value.host_name.trim() || !(f.value.host_whatsapp.trim() || f.value.host_phone.trim())) { err.value = 'Add your name and a WhatsApp or phone number.'; return }
    if (f.value.request_deadline && eventStartLocal.value && new Date(f.value.request_deadline) >= new Date(eventStartLocal.value)) { err.value = 'Set the join deadline before your event starts.'; return }
  }
  if (validateStep()) step.value++
}
// Backing all the way out to the type chooser is an explicit "start over" —
// drop the autosaved draft so it doesn't resurrect on the next visit.
function back() {
  // Hangout step 2: the venue browser and the own-spot form each sit BEHIND the
  // "where will you host it?" screen — back out to that choice first, not to Basics.
  if (spotChoiceOn.value && step.value === 2 && f.value.spot_mode) { f.value.spot_mode = ''; err.value = ''; return }
  if (step.value > 1) step.value--; else { type.value = ''; clearHostDraft() }
}
// A saved draft resumes the wizard PAST the type chooser — say so, with a way out,
// so skipping the chooser never reads as the chooser being gone.
const resumedDraft = ref(false)
function startOver() { resumedDraft.value = false; step.value = 1; type.value = ''; clearHostDraft() }

// ── Leftover draft offered ON the chooser (plain visits never skip it) ────────
const draftAvailable = ref(false)
const draftLabel = computed(() =>
  ({ game: 'game', event: 'hangout', tournament: 'tournament' })[hostDraft.type] || 'event')
const draftTitle = computed(() => hostDraft.basics?.title || '')
function discardDraft() { clearHostDraft(); draftAvailable.value = false }
function continueDraft() {
  const dType = hostDraft.type
  draftAvailable.value = false
  communityId.value = communityId.value || String(hostDraft.communityId || '')
  if (dType === 'game' || dType === 'event') {
    type.value = dType
    if (hostDraft.basics) Object.assign(f.value, hostDraft.basics)
    step.value = hostDraft.step >= 1 && hostDraft.step <= 4 ? hostDraft.step : 2
  } else if (dType === 'tournament') {
    type.value = 'tournament'
    if (hostDraft.basics) Object.assign(f.value, hostDraft.basics)
    if (hostDraft.numTeams) {
      pickTeams(hostDraft.numTeams)
      ;(hostDraft.games || []).forEach(sg => {
        const gm = rounds.value[sg.round]?.games[sg.index]
        if (gm) Object.assign(gm, { location_id: sg.location_id, listing_id: sg.listing_id, owned: sg.owned, date: sg.date, slot: sg.slot, venueName: sg.venueName, courtName: sg.courtName, commissionPct: sg.commissionPct, capacity: sg.capacity, holdExpiresAt: sg.holdExpiresAt })
      })
      activeBracketRound.value = hostDraft.matchRound != null ? hostDraft.matchRound : defaultBracketRound()
      step.value = 3
    } else {
      step.value = hostDraft.step >= 1 && hostDraft.step <= 5 ? hostDraft.step : 1
    }
  }
  clearHostDraft()
}

// ── publish ──
async function publishGame() {
  if (rewardSelection.value && !rewardsControl.value?.validate()) { err.value = 'Review your refreshed points discount before publishing.'; return }
  saving.value = true
  const own = ownSpot.value
  const seats = Number(f.value.max_players) || 10
  const res = await createGame({
    host_customer_id: isCustomer.value ? customer.value.id : null,
    host_name: f.value.host_name, host_phone: f.value.host_phone, host_whatsapp: f.value.host_whatsapp || f.value.host_phone,
    location_id: own ? null : gVenue.value, listing_id: own ? null : gCourt.value,
    city_id: selectedCity.value?.id || null, country_id: selectedCountry.value?.id || null,
    title: f.value.title, sport: f.value.sport, game_type: type.value,
    game_date: own ? f.value.own_date : gDate.value, start_time: gameRange.value.start, end_time: gameRange.value.end,
    price_per_person: Number(f.value.price_per_person) || 0, currency: currency.value,
    max_players: seats, skill_level: f.value.skill_level,
    allow_waitlist: f.value.allow_waitlist !== false,
    prize_pot: prizeTotal.value, prize_note: prizeBreakdown.value,
    custom_location_name: own ? f.value.loc_name.trim() : '',
    custom_location_address: own ? f.value.loc_address.trim() : '',
    hide_location: own ? !!f.value.hide_location : false,
    description: f.value.description, images: f.value.images, status: 'open',
    signup_form: signupForm.value,
    request_deadline: f.value.request_deadline ? new Date(f.value.request_deadline).toISOString() : null,
    approval_mode: f.value.approval_mode,
    // The server prices the bill and reserves the court — it only needs to know
    // how the host wants to collect seat money and how many points to spend.
    rewards: rewardSelection.value,
    ticket_mode: effectiveTicketMode.value,
    payment_method: payMethod.value || null,
    // Saved with the game itself, so there's no follow-up write to go wrong.
    community_id: communityId.value || null,
    community_public: !!listPublic.value,
    lock_minutes_before: Number(f.value.lock_minutes_before) || null,
    play_format: type.value === 'game' ? (f.value.play_format || null) : null,
    courts_count: type.value === 'game' && f.value.play_format ? (Number(f.value.courts_count) || 1) : null,
  })
  saving.value = false
  if (res.error) { err.value = res.error; return }
  saveCustomerPhone(selectedCountry.value?.id, f.value.host_whatsapp || f.value.host_phone)
  // Money is owed: the event exists but is not live, and the share link only
  // appears once Stripe confirms. Keep the draft so nothing is lost if the host
  // backs out of checkout — the return handler clears it.
  if (res.requiresPayment && res.checkoutUrl) { window.location.href = res.checkoutUrl; return }
  clearHostDraft()   // published — drop the autosaved draft
  published.value = { id: res.game.id, type: type.value }   // show the share modal
}
async function publishTournament() {
  if (rewardSelection.value && !rewardsControl.value?.validate()) { err.value = 'Review your refreshed points discount before publishing.'; return }
  saving.value = true
  const res = await createTournament({
    host_customer_id: isCustomer.value ? customer.value.id : null,
    host_name: f.value.host_name, host_phone: f.value.host_phone, host_whatsapp: f.value.host_whatsapp || f.value.host_phone,
    city_id: selectedCity.value?.id || null, country_id: selectedCountry.value?.id || null, currency: currency.value,
    title: f.value.title, sport: f.value.sport, skill_level: f.value.skill_level, description: f.value.description,
    price_per_person: Number(f.value.price_per_person) || 0, num_teams: numTeams.value,
    prize_pot: prizeTotal.value, prize_note: prizeBreakdown.value,
    rewards: rewardSelection.value,
    ticket_mode: effectiveTicketMode.value,
    payment_method: payMethod.value || null,
    approval_mode: f.value.approval_mode,
    community_id: communityId.value || null,
    community_public: !!listPublic.value,
    lock_minutes_before: Number(f.value.lock_minutes_before) || null,
    signup_form: signupForm.value,
    request_deadline: f.value.request_deadline ? new Date(f.value.request_deadline).toISOString() : null,
    matches: rounds.value.flatMap((rd, r) => rd.games.map((gm, g) => ({
      round: r, index: g, listing_id: gm.listing_id, location_id: gm.location_id,
      date: gm.date, start_time: gm.slot.start_time, end_time: gm.slot.end_time,
      price: Number(gm.slot.price) || 0, bookbollOwned: !!gm.owned,
      commissionPct: gm.commissionPct ?? null,   // deposit at each court's own booking rate
    }))),
  })
  saving.value = false
  if (res.error) { err.value = res.error; return }
  saveCustomerPhone(selectedCountry.value?.id, f.value.host_whatsapp || f.value.host_phone)
  if (res.requiresPayment && res.checkoutUrl) { window.location.href = res.checkoutUrl; return }
  clearHostDraft()   // published — drop the autosaved draft
  published.value = { id: res.tournament.id, type: 'tournament' }   // show the share modal
}
// "Publish" first validates + checks contact, then pops the "important bits" waiver modal.
const showWaiver = ref(false)
async function publish() {
  if (imgUploading.value) { err.value = 'Wait for your photos to finish uploading.'; return }
  for (let checkStep = 1; checkStep < lastStep.value; checkStep++) {
    if (!validateStep(checkStep)) { step.value = checkStep; return }
  }
  // Mandatory: must have a Bookboll account before publishing (the host is linked to it).
  if (!isCustomer.value && !(await ensureSignedIn())) return
  if (!f.value.host_name.trim() || !(f.value.host_whatsapp.trim() || f.value.host_phone.trim())) { err.value = 'Add your name and a WhatsApp/phone so players can reach you.'; return }
  // The request-to-join deadline must be before the event starts (can't apply once it has begun).
  if (f.value.request_deadline && eventStartLocal.value && new Date(f.value.request_deadline).getTime() >= new Date(eventStartLocal.value).getTime()) {
    err.value = `Set the request-to-join deadline before the ${type.value === 'tournament' ? 'tournament' : type.value === 'event' ? 'hangout' : 'game'} starts.`; return
  }
  err.value = ''
  showWaiver.value = true   // confirm the important bits, then publish from the modal
}
// Confirm from the waiver modal (requires the "I understand" tick).
function confirmPublish() {
  if (!hostWaiver.value) return
  showWaiver.value = false
  type.value === 'tournament' ? publishTournament() : publishGame()
}
// Opening the auth gate inline keeps the in-progress host wizard intact (instead of
// navigating to /account). When it resolves, isCustomer flips and the wizard appears.
async function signInToHost() { await ensureSignedIn() }

// ── Post-publish: share modal (copy link / share) before viewing the event ──
const published = ref(null)   // { id, type } once created

// ── Reserved-slot countdown ────────────────────────────────────────────────────
// The courts the host picks are held for the platform window (like a normal checkout),
// so nobody grabs them mid-setup. Surface the remaining time so the host publishes
// before it lapses; "Refresh" re-holds every picked slot for a fresh window.
const nowTick = ref(Date.now())
let holdTicker = null
onMounted(() => { holdTicker = setInterval(() => { nowTick.value = Date.now() }, 1000) })
onMounted(async () => {
  if (route.query.stripe !== 'done' || !isCustomer.value) return
  await refreshStripe()
  router.replace({ path: route.path, query: { ...route.query, stripe: undefined } })
})

// Back from paying for the courts. `published` carries the event Stripe just paid
// for, so the share modal opens exactly as it does for a free event; `payment=
// cancelled` means the event and its court holds were released and the host has
// to start again — their draft is still here.
onMounted(() => {
  if (route.query.published) {
    clearHostDraft()
    published.value = { id: String(route.query.published), type: String(route.query.type || 'game') }
    router.replace({ path: route.path, query: {} })
    return
  }
  if (route.query.payment === 'cancelled') {
    err.value = 'Payment was not completed, so the event was not published and the courts were released. You can pick your slots again below.'
    router.replace({ path: route.path, query: {} })
  }
})
onBeforeUnmount(() => { if (holdTicker) clearInterval(holdTicker) })
// The soonest any currently-picked court is released (0 = nothing held / holds not live).
const holdDeadline = computed(() => {
  if (published.value) return 0
  if (isGameLike.value) return gHoldExpiresAt.value || 0
  if (type.value === 'tournament') {
    const ends = rounds.value.flatMap(rd => rd.games).filter(g => g.slot && g.holdExpiresAt).map(g => g.holdExpiresAt)
    return ends.length ? Math.min(...ends) : 0
  }
  return 0
})
const holdRemainingMs = computed(() => holdDeadline.value ? holdDeadline.value - nowTick.value : 0)
const holdActive = computed(() => holdRemainingMs.value > 0)
const holdExpired = computed(() => holdDeadline.value > 0 && holdRemainingMs.value <= 0)
const holdMMSS = computed(() => {
  const s = Math.max(0, Math.round(holdRemainingMs.value / 1000))
  return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`
})
const holdLow = computed(() => holdActive.value && holdRemainingMs.value <= 120000)   // ≤2 min → nudge
const holdRefreshing = ref(false)
// Re-hold every currently-picked slot for a fresh window (holdSlots only needs the listing id).
async function refreshHolds() {
  if (holdRefreshing.value) return
  holdRefreshing.value = true
  try {
    const mins = await holdMinutes()
    const stamp = held => held?.expiresAt ? new Date(held.expiresAt).getTime() : (held?.ok ? Date.now() + mins * 60000 : 0)
    if (isGameLike.value) {
      if (gCourt.value && gDate.value && gSel.value.length)
        gHoldExpiresAt.value = stamp(await holdSlots({ id: gCourt.value }, gDate.value, gSel.value.map(s => ({ start_time: s.start_time, end_time: s.end_time }))))
    } else {
      for (const rd of rounds.value) for (const gm of rd.games) {
        if (!gm.slot || !gm.listing_id || !gm.date) continue
        gm.holdExpiresAt = stamp(await holdSlots({ id: gm.listing_id }, gm.date, [{ start_time: gm.slot.start_time, end_time: gm.slot.end_time }]))
      }
    }
  } catch { /* best-effort */ }
  holdRefreshing.value = false
}

// ── Autosave: an accidental refresh (or a mobile browser evicting the tab) used to
// wipe a half-built event. Mirror the basics + step into the sessionStorage-backed
// draft as the host types; the court-pick round trip's own richer save (which also
// carries slots / the bracket) is never clobbered.
let draftTimer = null
watch([f, type, step, numTeams], () => {
  if (!type.value || published.value) return
  clearTimeout(draftTimer)
  draftTimer = setTimeout(() => {
    if (published.value || hostDraft.selectedCourtId) return   // publish landed / court pick in flight
    const patch = { type: type.value, basics: { ...f.value }, step: step.value, communityId: communityId.value }
    if (type.value === 'tournament' && numTeams.value) {
      patch.numTeams = numTeams.value
      patch.games = serializeGames()
    }
    saveHostDraft(patch)
  }, 400)
}, { deep: true })
const linkCopied = ref(false)
const TYPE_LABEL = { game: 'game', event: 'hangout', tournament: 'tournament' }
const publishedLabel = computed(() => TYPE_LABEL[published.value?.type] || 'event')
const publishedPath = computed(() => !published.value ? '' : (published.value.type === 'tournament' ? `/tournaments/${published.value.id}` : `/games/${published.value.id}`))
const publishedUrl = computed(() => published.value ? `${appBaseUrl()}${publishedPath.value}` : '')
async function copyPublishedLink() {
  try { await navigator.clipboard.writeText(publishedUrl.value); linkCopied.value = true; setTimeout(() => { linkCopied.value = false }, 2000) } catch (e) { /* clipboard blocked */ }
}
async function sharePublished() {
  const text = `Join my ${publishedLabel.value} on Bookboll`
  if (navigator.share) { try { await navigator.share({ title: f.value.title || 'Bookboll event', text, url: publishedUrl.value }) } catch (e) { /* cancelled */ } }
  else window.open(`https://wa.me/?text=${encodeURIComponent(text + ' — ' + publishedUrl.value)}`, '_blank')
}
function goToPublished() { const p = publishedPath.value; published.value = null; router.push(p) }
</script>

<template>
  <div class="hg" :class="{ 'hg-wide': isWideStep, 'hg-choosing': !type, 'hg-reviewing': step===lastStep }">
    <router-link :to="communityId ? `/communities/${communityId}` : '/games'" class="cback"><ArrowLeft :size="16" /> Back to {{ communityId ? 'community' : 'events' }}</router-link>

    <!-- games not enabled yet -->
    <div v-if="gamesEnabled === false" class="hg-signin">
      <CalendarDays :size="34" /><h2>Events are coming soon!</h2>
      <p>Hosting isn't switched on just yet. Check back shortly.</p>
      <router-link to="/" class="hg-signin-btn">Back to home</router-link>
    </div>

    <!-- not signed in -->
    <div v-else-if="!isCustomer" class="hg-signin">
      <Sparkles :size="34" /><h2>Sign in to host</h2>
      <p>You'll get join requests in one place and players will know who to contact.</p>
      <button type="button" class="hg-signin-btn" @click="signInToHost">Sign in / Create account</button>
    </div>

    <!-- TYPE CHOOSER -->
    <template v-else-if="!type">
      <div v-if="communityId && communityName" class="hg-community-chip"><UsersRound :size="13" /> Posting to <b>{{ communityName }}</b></div>
      <h1>What are you hosting{{ communityId && communityName ? ' for ' + communityName : '' }}?</h1>
      <p class="hg-sub">Choose the kind of gathering you have in mind. We’ll guide you through the details, location and costs.</p>

      <!-- Unfinished draft: continue it, or start something new below -->
      <div v-if="draftAvailable" class="hg-draft">
        <div class="hg-draft-txt">
          <b>You have an unfinished {{ draftLabel }}<template v-if="draftTitle"> — “{{ draftTitle }}”</template></b>
          <small>Continue where you left off, or start a new one below.</small>
        </div>
        <div class="hg-draft-btns">
          <button type="button" class="hg-draft-go" @click="continueDraft">Continue draft</button>
          <button type="button" class="hg-draft-x" @click="discardDraft">Discard</button>
        </div>
      </div>

      <!-- Post as: yourself, or a community you own/admin -->
      <div v-if="myManagedCommunities.length" class="hg-postas">
        <span class="hg-postas-l">Post as</span>
        <div class="hg-postas-chips">
          <button type="button" class="hg-pa" :class="{ on: !communityId }" @click="postAs('', '')">
            {{ customer?.full_name || 'Myself' }}
          </button>
          <button v-for="cm in myManagedCommunities" :key="cm.id" type="button" class="hg-pa"
                  :class="{ on: communityId === cm.id }" @click="postAs(cm.id, cm.name)">
            {{ cm.name }}
          </button>
        </div>
        <small v-if="communityId" class="hg-postas-hint">Posting to {{ communityName || 'the community' }} — it'll appear on the community page, and you'll pick who can join in the next steps.</small>
      </div>

      <div class="hg-choose">
        <button class="hg-choice" @click="chooseType('game')">
          <div class="hg-choice-ico game"><Gamepad2 :size="30" /></div>
          <div class="hg-choice-t">Host a game</div>
          <div class="hg-choice-d">For football, pickleball and other sports. Choose the level, book a listing and bring players together.</div><span class="hg-choice-go">Set up a game <ArrowRight :size="17" /></span>
        </button>
        <button class="hg-choice" @click="chooseType('event')">
          <div class="hg-choice-ico event"><Calendar :size="30" /></div>
          <div class="hg-choice-t">Plan a hangout</div>
          <div class="hg-choice-d">For picnics, board games or a casual meetup. Choose your place and decide who can join.</div><span class="hg-choice-go">Set up a hangout <ArrowRight :size="17" /></span>
        </button>
        <!-- <button class="hg-choice" :disabled="tournamentsEnabled === false" @click="chooseType('tournament')">
          <div class="hg-choice-ico tourn"><Trophy :size="30" /></div>
          <div class="hg-choice-t">A Tournament</div>
          <div class="hg-choice-d">A knockout bracket. Choose how many teams — we build the rounds & games and book a slot for each.</div>
          <div v-if="tournamentsEnabled === false" class="hg-choice-soon">Needs a quick admin setup</div>
        </button> -->
      </div>

      <div class="hg-how">
        <div class="hg-how-h">How it works</div>
        <div class="hg-how-steps">
          <div class="hg-how-step"><span class="hg-how-n">1</span><div><b>Set it up</b><span>Pick the type, choose a venue or your own spot, then set the time, price and photos.</span></div></div>
          <div class="hg-how-step"><span class="hg-how-n">2</span><div><b>People request to join</b><span>Your event is listed on Bookboll and shareable by link — players send a request.</span></div></div>
          <div class="hg-how-step"><span class="hg-how-n">3</span><div><b>Accept &amp; meet up</b><span>Manage requests, share updates and welcome your guests.</span></div></div>
        </div>
        <div class="hg-rules"><ShieldCheck :size="16" /><span><b>Keep it respectful.</b> Bookboll events are public group activities — no private, unsafe or indecent gatherings. Hosts who break this can be removed.</span></div>
      </div>
    </template>

    <!-- WIZARD -->
    <template v-else>
      <!-- progress -->
      <nav class="hg-steps" aria-label="Event creation progress">
        <template v-for="(s, i) in steps" :key="s">
          <button type="button" class="hg-step" :aria-label="`Step ${i + 1}: ${s}`" :class="{ on: step === i + 1, done: step > i + 1 }" :disabled="i+1>step || saving" :aria-current="step===i+1 ? 'step' : undefined" @click="step=i+1;err=''">
            <span class="hg-step-n"><Check v-if="step > i + 1" :size="13" /><template v-else>{{ i + 1 }}</template></span>
            <span class="hg-step-t">{{ s }}</span>
          </button>
          <span v-if="i < steps.length - 1" class="hg-step-bar" :class="{ done: step > i + 1 }"></span>
        </template>
      </nav>

      <div class="hg-stephead">
        <div v-if="communityId && communityName" class="hg-community-chip"><UsersRound :size="13" /> Posting to <b>{{ communityName }}</b></div>
        <div class="hg-badge" :class="type">
          <component :is="type === 'tournament' ? Trophy : type === 'event' ? Calendar : Gamepad2" :size="15" /> {{ type === 'tournament' ? 'Tournament' : type === 'event' ? 'Hangout' : 'Game' }}
        </div>
        <span class="hg-stepcount">Step {{ step }} of {{ lastStep }}</span><h2 ref="stepHeading" tabindex="-1" class="hg-steptitle">{{ stepHead.title }}</h2>
        <p v-if="stepHead.sub" class="hg-stepsub">{{ stepHead.sub }}</p>
      </div>

      <!-- Resumed-draft notice: the chooser was skipped to restore progress -->
      <div v-if="resumedDraft" class="hg-resume">
        <Info :size="14" />
        <span>Picked up where you left off.</span>
        <button type="button" class="hg-resume-btn" @click="startOver">Start over</button>
        <button type="button" class="hg-resume-x" aria-label="Dismiss" @click="resumedDraft = false"><X :size="14" /></button>
      </div>

      <!-- Reserved-slot countdown: the picked courts are held for the platform window; nudge
           the host to publish before it lapses, with a Refresh to re-hold for a fresh window. -->
      <div v-if="holdActive || holdExpired" class="hg-hold" :class="{ warn: holdLow || holdExpired }">
        <Clock :size="15" />
        <span v-if="holdActive" class="hg-hold-t">Your court{{ type === 'tournament' ? 's are' : ' is' }} reserved — <b>{{ holdMMSS }}</b> left. Publish to lock {{ type === 'tournament' ? 'them' : 'it' }} in.</span>
        <span v-else class="hg-hold-t">Reservation timed out — publish now to re-check &amp; secure your court{{ type === 'tournament' ? 's' : '' }}.</span>
        <button type="button" class="hg-hold-btn" :disabled="holdRefreshing" @click="refreshHolds">
          <Loader v-if="holdRefreshing" :size="13" class="hg-spin" /><RefreshCw v-else :size="13" /> {{ holdRefreshing ? 'Refreshing…' : 'Refresh' }}
        </button>
      </div>

      <!-- STEP 1 — basics (both) -->
      <div v-if="step === 1" class="hg-card">
        <label class="hg-l">{{ type === 'tournament' ? 'Tournament name *' : type === 'event' ? 'Hangout title *' : 'Title *' }}</label>
        <input ref="titleInput" required :aria-invalid="mediaAttempted && !!basicsErrors.title" :aria-describedby="mediaAttempted && basicsErrors.title ? 'host-title-error' : undefined" aria-label="Event title" v-model="f.title" class="hg-in" :placeholder="type === 'tournament' ? 'e.g. Ramadan Cup' : type === 'event' ? 'e.g. Sunday picnic & football' : 'e.g. Friday night 5-a-side'" />
        <p v-if="mediaAttempted && basicsErrors.title" id="host-title-error" class="hg-field-error">{{ basicsErrors.title }}</p>
        <div class="hg-row">
          <div class="hg-sport-wrap">
            <label class="hg-l">{{ type === 'event' ? 'Activity (optional)' : 'Sport *' }}</label>
            <input ref="sportInput" :required="type !== 'event'" :aria-invalid="mediaAttempted && !!basicsErrors.sport" :aria-describedby="mediaAttempted && basicsErrors.sport ? 'host-sport-error' : undefined" aria-label="Sport or activity" v-model="f.sport" class="hg-in"
                   :placeholder="type === 'event' ? 'Football, bowling, board games…' : 'Search or type a sport…'"
                   @focus="sportOpen = true" @input="sportOpen = true" @blur="onSportBlur" />
            <p v-if="mediaAttempted && basicsErrors.sport" id="host-sport-error" class="hg-field-error">{{ basicsErrors.sport }}</p>
            <div v-if="sportOpen" class="hg-sport-dd">
              <button v-for="t in filteredSports" :key="t" type="button" class="hg-sport-opt"
                      :class="{ on: f.sport === t }" @mousedown.prevent="pickSport(t)">{{ t }}</button>
            </div>
          </div>
          <div v-if="type !== 'event'"><label class="hg-l">Skill level</label>
            <select v-model="f.skill_level" class="hg-in">
              <option value="all">All levels</option><option value="beginner">Beginner</option>
              <option value="intermediate">Intermediate</option><option value="advanced">Advanced</option>
            </select>
          </div>
        </div>
        <div class="hg-row">
          <div v-if="isGameLike"><label class="hg-l">{{ type === 'event' ? 'Max people' : 'Max players' }}</label><input ref="capacityInput" :aria-invalid="mediaAttempted && !!basicsErrors.max_players" :aria-describedby="mediaAttempted && basicsErrors.max_players ? 'host-capacity-error' : undefined" aria-label="Maximum attendees" v-model.number="f.max_players" type="number" min="2" class="hg-in" :placeholder="type === 'event' ? 'e.g. 20' : 'e.g. 10'" /><p v-if="mediaAttempted && basicsErrors.max_players" id="host-capacity-error" class="hg-field-error">{{ basicsErrors.max_players }}</p></div>
          <div v-if="isGameLike" class="hg-wl">
            <div class="hg-prize-tl">
              <div class="hg-prize-tt"><Hourglass :size="15" /> Waitlist when full?</div>
              <div class="hg-prize-td">People can queue — they get in automatically when a spot frees.</div>
            </div>
            <button type="button" class="hg-switch" aria-label="Enable waitlist when full" :class="{ on: f.allow_waitlist }" @click="f.allow_waitlist = !f.allow_waitlist" :aria-pressed="f.allow_waitlist"><span class="hg-switch-k"></span></button>
          </div>
          <div><label class="hg-l">{{ type === 'tournament' ? 'Entry fee per team' : 'Price per person' }} ({{ currency }})</label><input ref="priceInput" :aria-invalid="mediaAttempted && !!basicsErrors.price_per_person" :aria-describedby="mediaAttempted && basicsErrors.price_per_person ? 'host-price-error' : undefined" aria-label="Price per person" v-model="f.price_per_person" type="number" min="0" class="hg-in" placeholder="0" /><p v-if="mediaAttempted && basicsErrors.price_per_person" id="host-price-error" class="hg-field-error">{{ basicsErrors.price_per_person }}</p></div>
        </div>
        <div v-if="!(Number(f.price_per_person) > 0)" class="hg-note" :class="{ 'hg-feenote': ownSpot }">
          <Info :size="14" />
          <!-- Free AND at the host's own spot: no court and no seats means nothing for
               Bookboll to take a cut of, so this one pays a flat fee to publish. -->
          <span v-if="ownSpot">
            A free event at your own spot pays a one-off
            <b>{{ ownSpotMinFeeCurrency }} {{ Number(ownSpotMinFee).toFixed(2) }}</b> fee to publish —
            it keeps the Events feed from filling up with throwaway posts. Charge a price per person
            instead and you pay the usual {{ seatPct }}% of seats, with nothing flat on top.
          </span>
          <span v-else>
            Free for guests. You’ll review venue costs and any hosting fee before publishing.
          </span>
        </div>

        <EventFeeSummary v-else :currency="currency" :rate="seatPct" :price="Number(f.price_per_person)" :per-head="feePerHead" :total="feeTotal" :net="feeNet" :count="feeCount" :unit="feeUnit" :units="feeUnits" :online="effectiveTicketMode === 'stripe'" :own-spot="ownSpot" />

        <!-- Paid events (all types): how joiners pay — on arrival (default) or online via the host's Stripe -->
        <div v-if="Number(f.price_per_person) > 0" class="hg-tickets">
          <label class="hg-l">How do {{ type === 'tournament' ? 'teams' : type === 'event' ? 'people' : 'players' }} pay you?</label>
          <div class="hg-tk-grid">
            <button type="button" class="hg-tk" :class="{ on: f.ticket_mode === 'arrival' }" @click="f.ticket_mode = 'arrival'">
              <span class="hg-tk-t"><Banknote :size="16" /> On arrival</span>
              <span class="hg-tk-d">You collect in person — cash or as you agree. You pay Bookboll's {{ seatPct }}% {{ type === 'tournament' ? 'team-entries' : 'seats' }} service fee upfront when publishing.</span>
            </button>
            <button type="button" class="hg-tk" :class="{ on: f.ticket_mode === 'stripe' }" @click="f.ticket_mode = 'stripe'">
              <span class="hg-tk-t"><CreditCard :size="16" /> Online tickets · Stripe <span class="hg-tk-chip">No upfront fee</span></span>
              <span class="hg-tk-d">{{ type === 'tournament' ? 'Teams' : type === 'event' ? 'People' : 'Players' }} pay online when you accept them. Bookboll's {{ seatPct }}% is split from each {{ type === 'tournament' ? 'entry' : 'ticket' }} automatically — nothing upfront; payouts follow your Stripe schedule.</span>
            </button>
          </div>
          <div v-if="f.ticket_mode === 'stripe'" class="hg-tk-connect" :class="{ ok: stripeConnected }">
            <!-- Already taking payments -->
            <template v-if="stripeConnected">
              <div class="hg-sc-ok">
                <Check :size="14" />
                <span>
                  Payouts go to <b>{{ stripeAcct.account_name || stripeAcct.account_id }}</b>
                  <span class="hg-tk-acct">{{ stripeAcct.account_id }}</span>
                  <span v-if="!stripeAcct.livemode" class="hg-sc-chip">test mode</span>
                  <span v-if="stripeAcct.mode === 'keys'" class="hg-sc-chip">your own Stripe</span>
                </span>
              </div>
              <div v-if="!stripeAcct.payouts_enabled" class="hg-sc-warn">
                <Info :size="13" /> Stripe can take payments but hasn't enabled payouts yet — finish your details in Stripe to get paid out.
              </div>
              <div class="hg-sc-links">
                <button type="button" class="hg-sc-link" :disabled="stripeBusy" @click="refreshStripe">Refresh status</button>
                <button type="button" class="hg-sc-link" :disabled="stripeBusy" @click="unlinkStripe">Disconnect</button>
              </div>
            </template>

            <!-- Onboarding started but Stripe won't let them charge yet -->
            <template v-else-if="stripeAcct && stripeAcct.mode === 'connect'">
              <span>
                Your Stripe account isn't finished yet, so this event falls back to pay on arrival.
                <template v-if="stripeAcct.country">
                  It was opened in <b>{{ stripeAcct.country }}</b> — Stripe fixes that, so disconnect and
                  start again if you need a different country.
                </template>
              </span>
              <div class="hg-sc-links">
                <button type="button" class="hg-tk-btn" :disabled="stripeBusy" @click="connectStripe">
                  <Loader v-if="stripeBusy" :size="14" class="hg-spin" /> Finish Stripe setup
                </button>
                <button type="button" class="hg-sc-link" :disabled="stripeBusy" @click="refreshStripe">I've finished — re-check</button>
              </div>
            </template>

            <!-- Nothing set up yet: two ways to get paid -->
            <template v-else>
              <span>Get paid for tickets — pick how the money reaches you. Until then this event falls back to pay on arrival.</span>
              <div v-if="stripeHomeUnsupported" class="hg-sc-warn">
                <Info :size="13" />
                <span>
                  Stripe won't let us open an account in <b>{{ stripeHomeUnsupported }}</b>. If you already
                  have a Stripe account anywhere — your own, or a company abroad — use your keys below and
                  you're set. Opening a new account only works if you can verify an ID and bank account in
                  one of the countries listed.
                </span>
              </div>
              <div class="hg-sc-tabs">
                <button type="button" :class="{ on: payoutTab === 'connect' }" @click="payoutTab = 'connect'">Open a new Stripe account</button>
                <button type="button" :class="{ on: payoutTab === 'keys' }" @click="payoutTab = 'keys'">I already have Stripe</button>
              </div>

              <div v-if="payoutTab === 'connect'" class="hg-sc-pane">
                <p class="hg-sc-p">
                  We'll open a <b>new</b> Stripe account in your name — Stripe handles the form and your
                  details never touch Bookboll. Bookboll's {{ seatPct }}% comes off each ticket
                  automatically, so there's nothing to pay upfront.
                </p>
                <label class="hg-sc-lbl">Which country will you be paid in?</label>
                <select v-model="stripeCountry" class="hg-in" :disabled="stripeBusy || !stripeCountries.length">
                  <option value="">Choose a country…</option>
                  <option v-for="c in stripeCountries" :key="c.code" :value="c.code">{{ c.name }}</option>
                </select>
                <p class="hg-sc-note">
                  Pick the country where <b>your ID and bank account</b> are — Stripe will ask for both,
                  and <b>can't change this later</b>. Somewhere you can't verify won't get past Stripe's form.
                  <span v-if="stripeCountriesLoaded && !stripeCountries.length">
                    We couldn't load the list just now; try again in a moment.
                  </span>
                </p>
                <button type="button" class="hg-tk-btn" :disabled="stripeBusy || !stripeCountry" @click="connectStripe">
                  <Loader v-if="stripeBusy" :size="14" class="hg-spin" /> {{ stripeBusy ? 'Opening Stripe…' : 'Connect with Stripe' }}
                </button>
              </div>

              <div v-else class="hg-sc-pane">
                <p class="hg-sc-p">
                  Paste the keys from a Stripe account you already have — <b>any country</b>, including one
                  we can't open new accounts in. Nothing to verify and nothing to set up: joiners are
                  charged straight on your own account. We check the keys with Stripe before saving, and
                  the secret key is encrypted. Bookboll's {{ seatPct }}% is <b>not</b> taken from these
                  tickets — it'll be billed to you separately.
                </p>
                <label class="hg-sc-lbl">Secret key</label>
                <input v-model="keyForm.secret" type="password" class="hg-in" placeholder="sk_live_… or sk_test_…" autocomplete="off" />
                <label class="hg-sc-lbl">Publishable key <span class="hg-sc-opt">(optional)</span></label>
                <input v-model="keyForm.publishable" type="text" class="hg-in" placeholder="pk_live_… or pk_test_…" autocomplete="off" />
                <button type="button" class="hg-tk-btn" :disabled="stripeBusy || !keyForm.secret" @click="submitStripeKeys">
                  <Loader v-if="stripeBusy" :size="14" class="hg-spin" /> {{ stripeBusy ? 'Checking with Stripe…' : 'Verify & save keys' }}
                </button>
              </div>
            </template>

            <div v-if="stripeErr" class="hg-sc-err">{{ stripeErr }}</div>
          </div>
        </div>

        <!-- Who gets in — host approves each request (default) or instant join -->
        <div class="hg-tickets">
          <label class="hg-l">Who gets in?</label>
          <div class="hg-tk-grid">
            <button type="button" class="hg-tk" :class="{ on: f.approval_mode === 'review' }" @click="f.approval_mode = 'review'">
              <span class="hg-tk-t"><ShieldCheck :size="16" /> You approve requests</span>
              <span class="hg-tk-d">{{ type === 'tournament' ? 'Teams register and you accept who you want — full control over the bracket.' : 'People request to join and you accept who you want — full control over who shows up.' }}</span>
            </button>
            <button type="button" class="hg-tk" :class="{ on: f.approval_mode === 'instant' }" @click="f.approval_mode = 'instant'">
              <span class="hg-tk-t"><Zap :size="16" /> Instant join</span>
              <span class="hg-tk-d">{{ instantJoinHint }}</span>
            </button>
          </div>
        </div>

        <!-- Community event: who can see it (members-only by default, opt-in to public) -->
        <div v-if="communityId" class="hg-tickets">
          <label class="hg-l">Who can see this {{ type === 'tournament' ? 'tournament' : type === 'event' ? 'hangout' : 'game' }}?</label>
          <div class="hg-cv">
            <button type="button" class="hg-cv-opt" :class="{ on: !listPublic }" @click="listPublic = false">
              <UsersRound :size="17" /><b>Members only</b><span>Listed in Events for everyone to see — but only {{ communityName || 'community' }} members can join.</span>
            </button>
            <button type="button" class="hg-cv-opt" :class="{ on: listPublic }" @click="listPublic = true">
              <Sparkles :size="17" /><b>Open to all</b><span>Anyone on Bookboll can join — no membership needed.</span>
            </button>
          </div>
        </div>

        <template v-if="type !== 'event'">
        <div class="hg-prize-toggle">
          <div class="hg-prize-tl">
            <div class="hg-prize-tt"><Gift :size="16" /> Offer a prize pot?</div>
            <div class="hg-prize-td">Reward the winners — it helps attract {{ type === 'tournament' ? 'teams' : 'players' }}.</div>
          </div>
          <button type="button" class="hg-switch" :class="{ on: f.prize_on }" @click="togglePrize" :aria-pressed="f.prize_on"><span class="hg-switch-k"></span></button>
        </div>
        <div v-if="f.prize_on" class="hg-prize-box">
          <div v-for="(p, i) in f.prize_places" :key="i" class="hg-prize-row">
            <span class="hg-prize-badge">{{ placeBadge(i) }}</span>
            <span class="hg-prize-place">{{ ordinal(i + 1) }} place</span>
            <div class="hg-prize-amt"><span class="hg-prize-cur">{{ currency }}</span><input v-model="p.amount" type="number" min="0" placeholder="0" /></div>
            <button v-if="f.prize_places.length > 1" type="button" class="hg-prize-x" @click="removePlace(i)" title="Remove"><X :size="15" /></button>
            <span v-else class="hg-prize-x ghost"></span>
          </div>
          <button type="button" class="hg-prize-add" @click="addPlace"><Plus :size="15" /> Add another place</button>
          <div class="hg-prize-total"><span>Total prize pot</span><b>{{ currency }} {{ prizeTotal.toLocaleString() }}</b></div>
          <div class="hg-note"><Info :size="14" /> You pay the winner(s) yourself — Bookboll never holds, guarantees or pays prizes.</div>
        </div>
        </template>

        <!-- Description (+ Photos for games/hangouts) side-by-side to use the width -->
        <section class="hg-media" aria-labelledby="host-media-heading">
          <h2 id="host-media-heading">{{ isGameLike ? 'Photos & description' : 'Event description' }}</h2>
          <p class="hg-media-help">{{ isGameLike ? 'Add a description and at least one photo so guests know what to expect.' : 'Tell teams what to expect at your tournament.' }}</p>
          <div :class="{ 'hg-twocol': isGameLike }">
          <div>
            <label for="host-description" class="hg-l">About your event <span class="hg-required">Required</span></label>
            <textarea id="host-description" ref="descriptionInput" required :aria-invalid="mediaAttempted && !!mediaErrors.description" :aria-describedby="mediaAttempted && mediaErrors.description ? 'host-description-error' : undefined" v-model="f.description" class="hg-in hg-ta" :rows="isGameLike ? 6 : 3" placeholder="Tell players what to expect — the format, who should come, what to bring, the vibe. This becomes the 'About this event' section on your event page."></textarea>
            <p v-if="mediaAttempted && mediaErrors.description" id="host-description-error" class="hg-field-error">{{ mediaErrors.description }}</p>
          </div>
          <div v-if="isGameLike">
            <div id="host-photos-label" class="hg-l">Photos <span class="hg-required">Required</span></div>
            <div class="hg-drop" role="group" aria-labelledby="host-photos-label" :class="{ on: dropActive }" @dragover.prevent="dropActive = true" @dragleave.prevent="dropActive = false" @drop.prevent="onGameDrop">
              <div v-if="f.images.length" class="hg-imgs">
                <div v-for="(img, i) in f.images" :key="img" class="hg-img" :style="{ backgroundImage: `url(${img})` }">
                  <span v-if="i === 0" class="hg-img-cover">Cover</span>
                  <button type="button" class="hg-img-x" @click="removeGameImage(i)" title="Remove photo"><X :size="13" /></button>
                </div>
              </div>
              <button id="host-photos" ref="photoButton" type="button" class="hg-drop-btn" :aria-describedby="mediaAttempted && mediaErrors.images ? 'host-photos-hint host-photos-error' : 'host-photos-hint'" :disabled="imgUploading" @click="pickGameFiles">
                <Loader v-if="imgUploading" :size="15" class="hg-spin" /><ImagePlus v-else :size="15" />
                {{ imgUploading ? 'Uploading…' : (f.images.length ? 'Add more photos' : 'Upload photos') }}
              </button>
              <input ref="gFileInput" type="file" accept="image/*" multiple class="hg-file" @change="onGameFilesChange" />
              <div id="host-photos-hint" class="hg-drop-hint">Upload at least one photo. The first photo is your cover. You can also drag &amp; drop images here.</div>
            </div>
            <div v-if="imgError" class="hg-img-err" role="alert">{{ imgError }}</div>
            <p v-if="mediaAttempted && mediaErrors.images" id="host-photos-error" class="hg-field-error">{{ mediaErrors.images }}</p>
          </div>
          </div>
        </section>
      </div>

      <!-- STEP 2 (game/hangout) — book a venue OR (hangout) use your own spot -->
      <template v-if="isGameLike && step === 2">
        <!-- hangout: WHERE it's hosted, on a screen of its own -->
        <div v-if="needsSpotChoice" class="hg-choose hg-spotchoose">
          <button type="button" class="hg-choice" @click="chooseSpot('venue')">
            <div class="hg-choice-ico venue"><Building2 :size="30" /></div>
            <div class="hg-choice-t">Book a Bookboll venue</div>
            <div class="hg-choice-d">Browse venues in {{ selectedCity?.name || 'your city' }}, open one and pick your date &amp; time. We hold the slot while you finish.</div>
            <div class="hg-choice-tags">
              <span><MapPin :size="12" /> A real venue, shown publicly</span>
              <span><Clock :size="12" /> Your slot is reserved</span>
            </div>
          </button>
          <button type="button" class="hg-choice" @click="chooseSpot('own')">
            <div class="hg-choice-ico own"><MapPin :size="30" /></div>
            <div class="hg-choice-t">Use my own spot</div>
            <div class="hg-choice-d">Your place, a park, the beach — anywhere. Name the spot and set the date, start time and how long it runs yourself.</div>
            <div class="hg-choice-tags">
              <span><Check :size="12" /> No court to book — your place, your time</span>
              <span><EyeOff :size="12" /> The exact address can stay private</span>
              <span class="hg-choice-fee"><CreditCard :size="12" /> Free events pay a {{ ownSpotMinFeeCurrency }} {{ ownSpotMinFee }} fee to publish</span>
            </div>
          </button>
        </div>

        <template v-else>
          <!-- which path you're on, with a way back to the choice -->
          <div v-if="spotChoiceOn" class="hg-modebar">
            <span class="hg-modebar-t">
              <component :is="ownSpot ? MapPin : Building2" :size="15" />
              {{ ownSpot ? 'At your own spot' : 'Booking a Bookboll venue' }}
            </span>
            <button type="button" class="hg-modebar-x" @click="chooseSpot('')">Change</button>
          </div>

          <!-- hangout · own spot (no venue booking) -->
          <div v-if="ownSpot" class="hg-card">
            <label for="host-spot-name" class="hg-l">Spot name * <span class="hg-opt">shown publicly</span></label>
            <input id="host-spot-name" v-model="f.loc_name" class="hg-in" placeholder="e.g. JBR Beach · Marina / My rooftop" />
            <label for="host-spot-address" class="hg-l">Exact address / details <span class="hg-opt">optional</span></label>
            <input id="host-spot-address" v-model="f.loc_address" class="hg-in" placeholder="Building, street, map pin, parking…" />
            <div class="hg-row">
              <div><label for="host-date" class="hg-l">Date *</label><input id="host-date" v-model="f.own_date" type="date" :min="today" class="hg-in" /></div>
              <div><label for="host-time" class="hg-l">Start time *</label><input id="host-time" v-model="f.own_start" type="time" class="hg-in" /></div>
            </div>
            <label class="hg-l">How long does it run?</label>
            <select aria-label="Event duration" v-model.number="gameHours" class="hg-in">
              <option v-for="d in GAME_DURATIONS" :key="d.value" :value="d.value">{{ d.label }}</option>
            </select>
            <div v-if="f.own_start" class="hg-note"><Clock :size="14" /> Runs ~{{ fmtTime(normTime(f.own_start)) }}–{{ fmtTime(addMinutes(normTime(f.own_start), gameHours * 60)) }} ({{ durLabel(gameHours) }}).</div>

            <div class="hg-prize-toggle" style="margin-top:14px">
              <div class="hg-prize-tl">
                <div class="hg-prize-tt"><EyeOff :size="16" /> Hide the exact spot?</div>
                <div class="hg-prize-td">Only people you accept see the address; everyone else sees just the name.</div>
              </div>
              <button type="button" class="hg-switch" aria-label="Hide the exact spot" :class="{ on: f.hide_location }" @click="f.hide_location = !f.hide_location" :aria-pressed="f.hide_location"><span class="hg-switch-k"></span></button>
            </div>
            <div class="hg-note"><Info :size="14" /> Your <b>spot name</b> is always shown publicly{{ f.hide_location ? '; the exact address stays private until you accept someone.' : '.' }}</div>
            <!-- No court and no seats means nothing for Bookboll to take a cut of, so a
                 free own-spot event pays a flat fee instead. Said here too: the price was
                 set a step ago, and finding this out at checkout is a nasty surprise. -->
            <div v-if="!(Number(f.price_per_person) > 0)" class="hg-note hg-feenote">
              <CreditCard :size="14" />
              <span>
                This event is free, so there's no court and no seat money for Bookboll to take a share of —
                publishing it costs a one-off <b>{{ ownSpotMinFeeCurrency }} {{ Number(ownSpotMinFee).toFixed(2) }}</b>,
                paid at the last step. Go back and set a price per person and you pay the usual
                {{ seatPct }}% of seats instead.
              </span>
            </div>
          </div>

          <!-- venue browse (games always · hangout "Book a Bookboll venue") -->
          <VenueBrowse v-else :courts="cityCourts" :venues="venues" :selected-id="gCourt" :currency="currency" :city-name="selectedCity?.name" @select="openCourtPage" />
        </template>
      </template>

      <!-- STEP 2 (tournament) — how many teams -->
      <template v-if="type === 'tournament' && step === 2">
        <div class="hg-card">
          <label class="hg-l">How many teams? *</label>
          <div class="hg-teams">
            <button v-for="n in TEAM_OPTIONS" :key="n" type="button" class="hg-team" :class="{ on: numTeams === n }" @click="pickTeams(n)">{{ n }}</button>
          </div>

          <div v-if="bracketPreview" class="hg-preview">
            <div class="hg-preview-h"><Trophy :size="16" /> {{ bracketPreview.rounds }} rounds · {{ bracketPreview.games }} games</div>
            <div class="hg-preview-rounds">
              <div v-for="(r, i) in bracketPreview.list" :key="i" class="hg-preview-r"><span>{{ r.name }}</span><b>{{ r.games }} game{{ r.games > 1 ? 's' : '' }}</b></div>
            </div>
            <div class="hg-note"><Info :size="14" /> Next you'll schedule each game — any venue, date and time. A round just has to finish before the next one starts.</div>
          </div>
        </div>
      </template>

      <!-- STEP 3 (tournament) — bracket chart; tap a game to set its court/date/time -->
      <template v-if="type === 'tournament' && step === 3">

        <!-- (A) picking a court for ONE match → browse court cards (same as the game flow) -->
        <template v-if="schedulingMatch">
          <button type="button" class="hg-chart-back" @click="backToChart"><ArrowLeft :size="15" /> Back to bracket</button>
          <VenueBrowse :courts="cityCourts" :venues="venues" :currency="currency" :city-name="selectedCity?.name" @select="openMatchCourt" />
        </template>

        <!-- (B) the bracket chart of game cards -->
        <template v-else>
          <div class="hg-auto">
            <div class="hg-auto-l">
              <div class="hg-auto-t"><Sparkles :size="16" /> Auto‑schedule {{ totalGames(numTeams) }} games</div>
              <div class="hg-auto-d">Set Game 1, then choose how the rest is planned: one back‑to‑back chain, or each round on its own day. You can still edit any game after.</div>
              <div v-if="autoErr" class="hg-roundwarn" style="margin-top:8px"><Info :size="14" /> {{ autoErr }}</div>
            </div>
            <button type="button" class="hg-auto-btn" :disabled="!firstGameReady || autoBusy" @click="openAutoSchedule">
              <Sparkles :size="16" /> {{ autoBusy ? 'Scheduling…' : 'Auto‑schedule rest' }}
            </button>
          </div>

          <!-- The plan we decided on — so the host knows exactly what was scheduled and why -->
          <div v-if="autoPlan" class="hg-ap">
            <div class="hg-ap-h"><Check :size="15" /> Schedule set — here's the plan</div>
            <div v-for="(pr, i) in autoPlan.rounds" :key="i" class="hg-ap-row">
              <span>{{ pr.name }}</span><b>{{ pr.label }}</b>
            </div>
            <div v-for="(n, i) in autoPlan.notes" :key="'n' + i" class="hg-ap-note"><Info :size="13" /> {{ n }}</div>
          </div>

          <div class="hg-chart-bar">
            <span class="hg-chart-prog" :class="{ done: scheduledCount === totalGames(numTeams) }">
              <Check :size="14" /> {{ scheduledCount }} / {{ totalGames(numTeams) }} games scheduled
            </span>
            <span v-if="!firstGameReady" class="hg-chart-hint"><Info :size="13" /> Tap Game 1 to start</span>
          </div>

          <div class="hg-bracket">
            <div v-for="(rd, r) in rounds" :key="r" class="hg-bcol">
              <div class="hg-bcol-h">
                <span class="hg-round-name">{{ roundName(r, rounds.length) }}</span>
                <Check v-if="rd.games.length && rd.games.every(g => g.slot)" :size="15" class="hg-round-ok" />
              </div>
              <div class="hg-bcol-games">
                <button v-for="(gm, g) in rd.games" :key="g" type="button" class="hg-mcard" :class="{ done: !!gm.slot }" @click="scheduleMatch(r, g)">
                  <div class="hg-mcard-top">
                    <span class="hg-mcard-n">Game {{ g + 1 }}</span>
                    <span class="hg-mcard-badge" :class="{ todo: !gm.slot }"><Check v-if="gm.slot" :size="12" /><Plus v-else :size="12" /></span>
                  </div>
                  <template v-if="gm.slot">
                    <div class="hg-mcard-line venue"><MapPin :size="12" /> {{ gm.courtName || gm.venueName || 'Venue' }}</div>
                    <div class="hg-mcard-line"><Calendar :size="12" /> {{ fmtDate(gm.date) }}</div>
                    <div class="hg-mcard-line"><Clock :size="12" /> {{ fmtTime(gm.slot.start_time) }}–{{ fmtTime(gm.slot.end_time) }}</div>
                    <div class="hg-mcard-edit"><Pencil :size="11" /> Change</div>
                  </template>
                  <div v-else class="hg-mcard-empty"><Plus :size="13" /> Set venue &amp; time</div>
                </button>
              </div>
            </div>
          </div>

          <!-- Mobile (≤760px): round-by-round tabs, Google-knockout style — tap a round
               to bring its games forward. Same cards + scheduleMatch handler as desktop. -->
          <div class="hg-mb">
            <div class="hg-mb-tabs" role="tablist" aria-label="Rounds">
              <button v-for="(rd, r) in rounds" :key="'tb' + r" type="button" class="hg-mb-tab"
                :class="{ on: r === activeBracketRound, done: rd.games.length && rd.games.every(g => g.slot) }"
                :aria-pressed="r === activeBracketRound" @click="goBracketRound(r)">
                <span class="hg-mb-tab-t">{{ roundName(r, rounds.length) }}</span>
                <span class="hg-mb-tab-c"><Check v-if="rd.games.length && rd.games.every(g => g.slot)" :size="11" /><template v-else>{{ rd.games.filter(g => g.slot).length }}/{{ rd.games.length }}</template></span>
              </button>
            </div>
            <div class="hg-mb-area" @pointerdown="onBracketSwipeStart" @pointerup="onBracketSwipeEnd">
              <Transition :name="bracketSlideDir > 0 ? 'hgm' : 'hgm-back'" mode="out-in">
                <div v-if="activeBracketCol" :key="activeBracketRound" class="hg-mb-col">
                  <button v-for="(gm, g) in activeBracketCol.games" :key="g" type="button" class="hg-mcard" :class="{ done: !!gm.slot }" @click="scheduleMatch(activeBracketRound, g)">
                    <div class="hg-mcard-top">
                      <span class="hg-mcard-n">Game {{ g + 1 }}</span>
                      <span class="hg-mcard-badge" :class="{ todo: !gm.slot }"><Check v-if="gm.slot" :size="12" /><Plus v-else :size="12" /></span>
                    </div>
                    <template v-if="gm.slot">
                      <div class="hg-mcard-line venue"><MapPin :size="12" /> {{ gm.courtName || gm.venueName || 'Venue' }}</div>
                      <div class="hg-mcard-line"><Calendar :size="12" /> {{ fmtDate(gm.date) }}</div>
                      <div class="hg-mcard-line"><Clock :size="12" /> {{ fmtTime(gm.slot.start_time) }}–{{ fmtTime(gm.slot.end_time) }}</div>
                      <div class="hg-mcard-edit"><Pencil :size="11" /> Change</div>
                    </template>
                    <div v-else class="hg-mcard-empty"><Plus :size="13" /> Set venue &amp; time</div>
                  </button>
                </div>
              </Transition>
            </div>
          </div>
          <div v-if="slotClashes" class="hg-roundwarn hg-clashwarn"><Info :size="14" /> {{ slotClashes }}</div>
          <div v-if="orderError" class="hg-roundwarn"><Info :size="14" /> {{ orderError }}</div>

          <!-- Auto-schedule preferences — minimal: two icon cards + chips -->
          <div v-if="autoModal" class="hg-am-backdrop" @click.self="autoModal = false">
            <div class="hg-am" role="dialog" aria-modal="true">
              <div class="hg-am-head">
                <span><Sparkles :size="16" /> Auto‑schedule</span>
                <button type="button" class="hg-am-x" @click="autoModal = false"><X :size="17" /></button>
              </div>

              <div class="hg-am-opts">
                <button type="button" class="hg-am-opt" :class="{ on: autoMode === 'chain' }" @click="autoMode = 'chain'">
                  <span class="hg-am-opt-ic"><Zap :size="22" /></span>
                  <span class="hg-am-opt-t">Back‑to‑back</span>
                  <span class="hg-am-opt-d">One chain · fastest</span>
                  <span class="hg-am-viz">
                    <i class="a"></i><i class="a"></i><i class="a"></i><i class="a"></i>
                  </span>
                </button>
                <button type="button" class="hg-am-opt" :class="{ on: autoMode === 'rounds' }" @click="autoMode = 'rounds'">
                  <span class="hg-am-opt-ic"><Calendar :size="22" /></span>
                  <span class="hg-am-opt-t">Round per day</span>
                  <span class="hg-am-opt-d">Rest between rounds</span>
                  <span class="hg-am-viz">
                    <i class="a"></i><i class="a"></i><i class="gap"></i><i class="b"></i><i class="gap"></i><i class="c"></i>
                  </span>
                </button>
              </div>

              <template v-if="autoMode === 'rounds'">
                <div class="hg-am-row">
                  <span class="hg-am-rl">Rest days</span>
                  <div class="hg-am-chips">
                    <button v-for="o in [{ v: 0, l: '0' }, { v: 1, l: '1' }, { v: 2, l: '2' }, { v: 6, l: '1 wk' }]" :key="o.v"
                      type="button" class="hg-am-chip" :class="{ on: autoGap === o.v }" @click="autoGap = o.v">{{ o.l }}</button>
                  </div>
                </div>
                <div class="hg-am-row">
                  <span class="hg-am-rl">Day start</span>
                  <div class="hg-am-chips">
                    <button type="button" class="hg-am-chip" :class="{ on: autoDayStart === 'same' }" @click="autoDayStart = 'same'"><Clock :size="12" /> Game 1's time</button>
                    <button type="button" class="hg-am-chip" :class="{ on: autoDayStart === 'earliest' }" @click="autoDayStart = 'earliest'"><Zap :size="12" /> Earliest</button>
                  </div>
                </div>
              </template>

              <button type="button" class="hg-am-go" :disabled="autoBusy" @click="runAutoSchedule">
                <Sparkles :size="15" /> {{ autoBusy ? 'Scheduling…' : `Plan ${totalGames(numTeams)} games` }}
              </button>
              <div class="hg-am-foot">You can edit any game after.</div>
            </div>
          </div>
        </template>
      </template>

      <!-- LAST STEP — contact + review -->
      <div v-if="step >= lastStep - 1" class="hg-card">
        <div class="hg-final" :class="{review:step===lastStep}">
        <div v-if="step===lastStep-1" class="hg-final-main">
        <h3 class="hg-h3">How players reach you</h3>
        <div class="hg-column">
          <div><label class="hg-l">Your name *</label><input aria-label="Host name" v-model="f.host_name" class="hg-in" /></div>
          <div><label class="hg-l">WhatsApp *</label><PhoneInput v-model="f.host_whatsapp" :default-iso="selectedCountry?.iso_code" placeholder="WhatsApp number" /></div>
        </div>
        <label class="hg-l">Phone (optional)</label>
        <PhoneInput v-model="f.host_phone" :default-iso="selectedCountry?.iso_code" placeholder="Phone number" />

        <label class="hg-l">Request to join deadline <span class="hg-rec">Recommended</span></label>
        <input v-model="f.request_deadline" type="datetime-local" :max="eventStartLocal || undefined" class="hg-in" />
        <div class="hg-hint">Must be before the {{ type === 'tournament' ? 'tournament' : type === 'event' ? 'hangout' : 'game' }} starts — no new {{ type === 'tournament' ? 'team registrations' : 'requests to join' }} are accepted after it. Leave empty to keep it open until it fills up.</div>

        <!-- Roster lock: when self-serve drops stop (Reclub-style anti-bailing) -->
        <label class="hg-l">Lock the roster <span class="hg-opt">no self-serve drop-outs after this</span></label>
        <div class="hg-lock-chips">
          <button v-for="o in [{ v: 0, l: 'At start' }, { v: 60, l: '1h before' }, { v: 120, l: '2h before' }, { v: 720, l: '12h before' }, { v: 1440, l: '24h before' }]" :key="o.v"
            type="button" class="hg-lock-chip" :class="{ on: Number(f.lock_minutes_before) === o.v }" @click="f.lock_minutes_before = o.v">{{ o.l }}</button>
        </div>
        <div class="hg-hint">Before the lock, anyone can drop out freely{{ effectiveTicketMode === 'stripe' && Number(f.price_per_person) > 0 ? ' (tickets auto-refund)' : '' }} and the waitlist fills the seat. After it, the roster is final{{ effectiveTicketMode === 'stripe' && Number(f.price_per_person) > 0 ? ' and tickets are non-refundable' : '' }}.</div>

        <!-- Optional live rounds: the game-day matchmaking engine — only for small
             court/racket sports (1–2 per side), never team sports like football. -->
        <details v-if="type === 'game' && supportsRounds" class="hg-disclosure"><summary>Live rounds <span>Optional</span></summary>
          <label class="hg-l">Run live rounds on game day? <span class="hg-opt">optional — auto-mixed matchups on your courts</span></label>
          <div class="hg-format-grid">
            <button type="button" class="hg-format" :class="{ on: !f.play_format }" @click="f.play_format = ''">
              <X :size="18" /><b>No rounds</b><span>Just play</span>
            </button>
            <button v-for="(fm, key) in PLAY_FORMATS" :key="key" type="button" class="hg-format" :class="{ on: f.play_format === key }" @click="f.play_format = key">
              <component :is="key === 'swiss' ? Trophy : key === 'king_court' ? Zap : RefreshCw" :size="18" />
              <b>{{ fm.label }}</b><span>{{ fm.hint }}</span>
            </button>
          </div>
          <div v-if="f.play_format" class="hg-format-opts">
            <div class="hg-am-row">
              <span class="hg-am-rl">Courts</span>
              <div class="hg-am-chips">
                <button v-for="n in [1, 2, 3, 4, 5, 6]" :key="n" type="button" class="hg-am-chip" :class="{ on: Number(f.courts_count) === n }" @click="f.courts_count = n">{{ n }}</button>
              </div>
            </div>
            <div class="hg-am-row">
              <span class="hg-am-rl">Per side</span>
              <div class="hg-am-chips">
                <button type="button" class="hg-am-chip" :class="{ on: Number(f.team_size) === 1 }" @click="f.team_size = 1">1v1</button>
                <button type="button" class="hg-am-chip" :class="{ on: Number(f.team_size) === 2 }" @click="f.team_size = 2">2v2</button>
              </div>
            </div>
            <div class="hg-hint">On game day you'll get a "Run rounds" panel: generate a round, tap the winners per court, and a live leaderboard updates automatically.</div>
          </div>
        </details>
        </div><!-- /hg-final-main -->

        <aside v-if="step===lastStep" class="hg-final-side">
        <div class="hg-review">
          <div class="hg-review-h">Your event <button type="button" class="hg-review-edit" @click="step=1"><Pencil :size="14" /> Edit details</button></div>
          <h3 class="hg-review-title">{{ f.title }}</h3><p v-if="f.sport" class="hg-review-sport">{{ f.sport }}</p>
          <template v-if="isGameLike && gameRange">
            <div v-if="!ownSpot" class="hg-review-row"><span>Venue</span><b>{{ gVenueName }}</b><button type="button" class="hg-review-edit" @click="step=2">Change</button></div><div v-if="ownSpot" class="hg-review-row"><span>Where</span><b>{{ f.loc_name }}<span v-if="f.hide_location"> · exact spot hidden</span></b></div>
            <div class="hg-review-row"><span>When</span><b>{{ fmtDate(ownSpot ? f.own_date : gDate) }} · {{ fmtTime(gameRange.start) }}–{{ fmtTime(gameRange.end) }}<span v-if="ownSpot" class="hg-review-dur"> ({{ durLabel(gameHours) }})</span></b></div>
            <div class="hg-review-row"><span>{{ type === 'event' ? 'People' : 'Players' }}</span><b>up to {{ f.max_players }}</b></div>
            <div class="hg-review-row"><span>Joining</span><b>{{ f.approval_mode === 'instant' ? 'Instant — no approval' : 'You approve requests' }}</b></div>
            <div v-if="Number(f.price_per_person) > 0" class="hg-review-row"><span>Tickets</span><b>{{ effectiveTicketMode === 'stripe' ? 'Online via Stripe' : 'Pay on arrival' }}</b></div>
          </template>
          <template v-else-if="type === 'tournament'">
            <div class="hg-review-row"><span>Format</span><b>{{ numTeams }} teams · {{ totalGames(numTeams) }} games</b></div>
            <div class="hg-review-row"><span>Venue slots reserved</span><b>{{ totalGames(numTeams) }} slots</b></div>
            <div class="hg-review-row"><span>Joining</span><b>{{ f.approval_mode === 'instant' ? 'Instant — no approval' : 'You approve registrations' }}</b></div>
            <div v-if="Number(f.price_per_person) > 0" class="hg-review-row"><span>Entry fees</span><b>{{ effectiveTicketMode === 'stripe' ? 'Online via Stripe' : 'Pay on arrival' }}</b></div>
          </template>
          <div class="hg-review-contact"><b>{{ f.host_name }}</b><span>{{ f.host_whatsapp || f.host_phone }}</span><button type="button" class="hg-review-edit" @click="step=lastStep-1">Edit contact &amp; rules</button></div><div v-if="prizeTotal > 0" class="hg-review-row hg-review-prize"><span><Gift :size="14" /> Prize pot</span><b>{{ currency }} {{ prizeTotal.toLocaleString() }}<span v-if="prizeBreakdown"> · {{ prizeBreakdown }}</span></b></div>
        </div>

        <div class="hg-pay">
          <!-- Only what this venue actually accepts — the same choice a customer
               booking the court would get. Hidden when there's nothing to choose. -->
          <div v-if="!ownSpot && payPlan.methods.length > 1" class="hg-paypick">
            <div class="hg-pay-h">How do you want to pay the venue?</div>
            <label v-for="m in payPlan.methods" :key="m" class="hg-paypick-opt" :class="{ on: payMethod === m }">
              <input type="radio" :value="m" v-model="payMethod" />
              <span class="hg-paypick-txt">
                <span class="hg-paypick-lbl">{{ payLabel[m] }}</span>
                <span class="hg-paypick-sub">
                  <template v-if="m === 'online'">{{ hostCharge.cur }} {{ hostCharge.courtTotal.toLocaleString() }} now</template>
                  <template v-else-if="m === 'cash'">Nothing now — settle it at the venue</template>
                  <template v-else>{{ hostCharge.cur }} {{ splitCourt(hostCharge.courtTotal, 'deposit', payPlan).now.toLocaleString() }} now, the rest at the venue</template>
                </span>
              </span>
            </label>
          </div>

          <div class="hg-pay-h">What you'll pay now</div>
          <div v-if="hostCharge.courtNow > 0" class="hg-pay-row"><span><template v-if="hostCharge.courtArrival > 0">Venue deposit (secures the venue{{ type === 'tournament' ? 's' : '' }}) <span class="hg-pay-nr">non-refundable</span></template><template v-else>Venue — full online</template></span><b>{{ hostCharge.cur }} {{ hostCharge.courtNow.toLocaleString() }}</b></div>
          <div v-else-if="!ownSpot && hostCharge.courtTotal > 0" class="hg-pay-row"><span>Venue — paid on arrival</span><b class="hg-pay-free">{{ hostCharge.cur }} 0</b></div>
          <div v-if="hostCharge.ownSpotMin" class="hg-pay-row"><span>Publish fee · free event at your own spot <span class="hg-pay-nr">non-refundable</span></span><b>{{ hostCharge.cur }} {{ hostCharge.serviceFee.toFixed(2) }}</b></div>
          <div v-else-if="effectiveTicketMode === 'stripe'" class="hg-pay-row"><span>Service fee · split from each Stripe ticket automatically</span><b class="hg-pay-free">{{ hostCharge.cur }} 0</b></div>
          <div v-else class="hg-pay-row"><span>Service fee · {{ seatPct }}% of {{ type === 'tournament' ? 'team entries' : 'seats' }} <span class="hg-pay-nr">non-refundable</span></span><b>{{ hostCharge.cur }} {{ hostCharge.serviceFee.toLocaleString() }}</b></div>
          <div class="hg-pay-row hg-pay-total"><span>Total due now</span><b>{{ hostCharge.cur }} {{ hostCharge.dueNow.toLocaleString() }}</b></div>

          <CheckoutRewards v-if="!isWhiteLabel" ref="rewardsControl" v-model="rewardSelection" :kind="type === 'tournament' ? 'host_tournament' : 'host_game'" :country-id="loyaltyCountryId" :currency="hostCharge.cur" :checkout="rewardsCheckout" :total="hostCharge.courtTotal + hostCharge.serviceFee" :due-now="hostCharge.dueNow" :active="step === lastStep && !hostCharge.ownSpotMin" />
          <div v-if="loyaltyAmount > 0" class="hg-pay-row hg-pay-charged"><span>Charged today with points off</span><b>{{ hostCharge.cur }} {{ dueNowNet.toLocaleString() }}</b></div>

          <template v-if="arrivalBreakdown.length">
            <div class="hg-pay-sub">Then pay the venue when you arrive<span v-if="arrivalBreakdown.length > 1"> — {{ arrivalBreakdown.length }} separate payments</span></div>
            <div v-for="(a, i) in arrivalBreakdown" :key="i" class="hg-pay-row hg-pay-arr">
              <span><MapPin :size="13" /> {{ a.name }} · {{ fmtDate(a.date) }}<span v-if="a.games > 1" class="hg-pay-g"> · {{ a.games }} games</span></span>
              <span>{{ hostCharge.cur }} {{ a.amount.toLocaleString() }}</span>
            </div>
            <div v-if="arrivalBreakdown.length > 1" class="hg-pay-arrtotal">Balance on arrival, total: {{ hostCharge.cur }} {{ hostCharge.dueArrival.toLocaleString() }}</div>
          </template>
          <div v-else class="hg-pay-row hg-pay-arr"><span>To pay on arrival</span><span>Nothing — all paid online</span></div>

          <!-- Picked Stripe tickets but never connected: warn BEFORE money is taken,
               otherwise the silent arrival fallback charges an upfront fee they thought
               they'd skipped. -->
          <div v-if="f.ticket_mode === 'stripe' && !stripeConnected && Number(f.price_per_person) > 0" class="hg-pay-warn hg-pay-warn-stripe"><Info :size="14" /> You chose <b>online tickets</b> but haven't connected Stripe, so this event will publish as <b>pay on arrival</b> — including the upfront service fee above. Go back to step 1 and connect Stripe to skip that fee.</div>
          <div v-if="hostCharge.ownSpotMin" class="hg-pay-warn"><Info :size="14" /> Your event is free and at your own spot, so there's no court and no seat money for Bookboll to take a share of — this one-off <b>{{ hostCharge.cur }} {{ hostCharge.serviceFee.toFixed(2) }}</b> is what it costs to publish it. It's <b>non-refundable</b>, including if you cancel the event. Set a price per person instead and you pay the usual {{ seatPct }}% of seats, with no flat fee.</div>
          <div v-else-if="effectiveTicketMode === 'stripe'" class="hg-pay-warn"><Info :size="14" /> {{ type === 'event' ? 'People' : 'Players' }} pay <b>online via your Stripe</b> when you accept them — Bookboll's {{ seatPct }}% is deducted from each ticket and payouts follow your Stripe schedule. Any venue deposit stays <b>non-refundable</b>.</div>
          <div v-else class="hg-pay-warn"><Info :size="14" /> The service fee is <b>non-refundable</b>. If not enough {{ type === 'tournament' ? 'teams' : type === 'event' ? 'people' : 'players' }} join and you cancel, it isn't returned — you host at your own risk. {{ type === 'tournament' ? 'Teams' : type === 'event' ? 'People' : 'Players' }} pay you {{ type === 'tournament' ? 'their entry fee' : type === 'event' ? 'directly' : 'their seat price' }} on arrival.</div>
        </div>
        </aside>
        </div><!-- /hg-final -->

        <details v-if="step===lastStep-1" class="hg-signup hg-disclosure"><summary>Sign-up questions <span>Optional</span></summary>

          <p class="hg-signup-sub">Ask people anything when they request to join (e.g. position, kit size, dietary needs).</p>
          <CustomFormBuilder v-model:fields="signupForm" />
        </details>
      </div>

      <div v-if="err" class="hg-err" role="alert">{{ err }}</div>

      <div v-if="!(type === 'tournament' && step === 3 && schedulingMatch)" class="hg-actions">
        <button class="hg-back" @click="back"><ArrowLeft :size="16" /> Back</button>
        <button v-if="step < lastStep && !needsSpotChoice" class="hg-next" @click="next">{{ step===lastStep-1 ? 'Review event' : 'Continue' }} <ArrowRight :size="16" /></button>
        <button v-else-if="step >= lastStep" class="hg-submit" :disabled="saving" @click="publish">{{ saving ? 'Publishing…' : (dueNowNet > 0 ? `Pay ${hostCharge.cur} ${dueNowNet.toLocaleString()} & publish` : (type === 'tournament' ? 'Publish tournament' : type === 'event' ? 'Publish hangout' : 'Publish game')) }}</button>
      </div>
    </template>

    <!-- "Important bits" waiver — pops up on Publish; must agree to continue -->
    <div v-if="showWaiver" class="hg-wv-back" @click.self="showWaiver = false">
      <div class="hg-wv">
        <button type="button" class="hg-wv-x" @click="showWaiver = false" aria-label="Close"><X :size="18" /></button>
        <LiabilityWaiver v-model="hostWaiver" kind="host" />
        <div class="hg-wv-actions">
          <button type="button" class="hg-wv-cancel" @click="showWaiver = false">Back</button>
          <button type="button" class="hg-wv-go" :disabled="!hostWaiver || saving" @click="confirmPublish">
            {{ saving ? 'Publishing…' : (dueNowNet > 0 ? `Pay ${hostCharge.cur} ${dueNowNet.toLocaleString()} & publish` : (type === 'tournament' ? 'Publish tournament' : type === 'event' ? 'Publish hangout' : 'Publish game')) }}
          </button>
        </div>
      </div>
    </div>

    <!-- Published! Invite-and-share modal -->
    <div v-if="published" class="hg-done-back">
      <div class="hg-done">
        <div class="hg-done-ico"><Check :size="26" /></div>
        <h2 class="hg-done-t">Your {{ publishedLabel }} is live!</h2>
        <p class="hg-done-d">Share the link to invite people directly — and it's also listed on Bookboll for players{{ selectedCity?.name ? ' in ' + selectedCity.name : '' }} to discover and request to join.</p>
        <div class="hg-done-link">
          <span class="hg-done-url">{{ publishedUrl }}</span>
          <button type="button" class="hg-done-copy" @click="copyPublishedLink"><component :is="linkCopied ? Check : Copy" :size="15" /> {{ linkCopied ? 'Copied!' : 'Copy' }}</button>
        </div>
        <div class="hg-done-actions">
          <button type="button" class="hg-done-share" @click="sharePublished"><Share2 :size="16" /> Share</button>
          <button type="button" class="hg-done-view" @click="goToPublished">View my {{ publishedLabel }}</button>
        </div>
      </div>
    </div>

  </div>
</template>

<style scoped>
.hg { max-width: 880px; margin: 0 auto; }
.cback { display: inline-flex; align-items: center; gap: 6px; color: var(--brand-ink, var(--brand)); text-decoration: none; font-weight: 700; font-size: 14px; margin-bottom: 16px; }
.hg h1 { font-size: 27px; font-weight: 900; color: var(--text, #0f172a); margin: 0; }
.hg-sub { font-size: 14.5px; color: var(--text-muted, #64748b); margin: 8px 0 18px; line-height: 1.5; }

/* type chooser */
.hg-choose { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 14px; margin-top: 8px; }
.hg-choice { position: relative; text-align: left; background: var(--surface, #ffffff); border: 2px solid var(--border, #e2e8f0); border-radius: 18px; padding: 22px; cursor: pointer; transition: .12s; }
.hg-choice:hover:not(:disabled) { border-color: var(--brand-accent); transform: translateY(-2px); }
.hg-choice:disabled { opacity: .55; cursor: not-allowed; }
.hg-choice-ico { width: 56px; height: 56px; border-radius: 14px; display: flex; align-items: center; justify-content: center; color: #fff; margin-bottom: 12px; }
.hg-choice-ico.game { background: var(--brand-tint); color: var(--brand-ink); }
.hg-choice-ico.tourn { background: var(--danger-bg); color: var(--danger-ink); }
.hg-choice-ico.event { background: linear-gradient(135deg,#f59e0b,#d97706); }
.hg-choice-t { font-size: 18px; font-weight: 900; color: var(--text, #0f172a); }
.hg-choice-d { font-size: 13.5px; color: var(--text-muted, #64748b); line-height: 1.5; margin-top: 5px; }
.hg-choice-soon { display: inline-block; margin-top: 10px; font-size: 11.5px; font-weight: 800; color: var(--danger-ink, #be123c); background: var(--danger-bg, #fff1f2); padding: 3px 9px; border-radius: 999px; }

/* chooser: how it works + rules */
.hg-how { margin-top: 30px; }
.hg-how-h { font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: .05em; color: var(--text-light, #94a3b8); margin-bottom: 14px; text-align: center; }
.hg-how-steps { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; }
.hg-how-step { display: flex; gap: 11px; align-items: flex-start; }
.hg-how-n { flex-shrink: 0; width: 26px; height: 26px; border-radius: 50%; background: var(--brand); color: var(--brand-contrast, #fff); font-size: 13px; font-weight: 800; display: flex; align-items: center; justify-content: center; }
.hg-how-step b { display: block; font-size: 14px; font-weight: 800; color: var(--text, #0f172a); }
.hg-how-step > div > span { font-size: 12.5px; color: var(--text-muted, #64748b); line-height: 1.45; }
.hg-rules { display: flex; align-items: flex-start; gap: 9px; margin-top: 18px; background: var(--canvas, #f8fafc); border: 1px solid var(--border, #e2e8f0); border-radius: 12px; padding: 12px 14px; font-size: 12.5px; color: var(--text-secondary, #475569); line-height: 1.5; }
.hg-rules svg { color: var(--brand-ink, var(--brand)); flex-shrink: 0; margin-top: 1px; }
.hg-rules b { color: var(--text, #0f172a); }
@media (max-width: 640px) { .hg-how-steps { grid-template-columns: 1fr; } }

/* stepper */
.hg-steps { display: flex; align-items: center; gap: 8px; margin-bottom: 14px; }
.hg-step { display: inline-flex; align-items: center; gap: 7px; color: var(--text-light, #94a3b8); font-weight: 800; font-size: 13px; }
.hg-step.on { color: var(--text, #0f172a); }
.hg-step-n { width: 24px; height: 24px; flex-shrink: 0; border-radius: 50%; background: var(--border, #e2e8f0); color: var(--text-muted, #64748b); display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 900; }
.hg-step.on .hg-step-n { background: var(--brand); color: #fff; }
.hg-step.done .hg-step-n { background: #16a34a; color: #fff; }
.hg-step-t { white-space: nowrap; }
.hg-step-bar { flex: 1; height: 2px; background: var(--border, #e2e8f0); border-radius: 2px; min-width: 10px; }
.hg-step-bar.done { background: #16a34a; }
@media (max-width: 560px) { .hg-step-t { display: none; } .hg-choose { grid-template-columns: 1fr; } }

/* per-step header */
.hg-stephead { margin-bottom: 18px; }
.hg-steptitle { font-size: 23px; font-weight: 900; color: var(--text, #0f172a); margin: 10px 0 4px; letter-spacing: -.01em; }
.hg-stepsub { font-size: 14.5px; color: var(--text-muted, #64748b); line-height: 1.5; margin: 0; max-width: 620px; }
.hg-format-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 8px; }
.hg-format { display: flex; flex-direction: column; align-items: center; gap: 4px; border: 2px solid var(--border, #e2e8f0); background: none; border-radius: 12px; padding: 12px 8px; cursor: pointer; font: inherit; color: var(--text-secondary, #475569); text-align: center; }
.hg-format b { font-size: 13px; color: var(--text, #0f172a); }
.hg-format span { font-size: 10.5px; color: var(--text-muted, #64748b); line-height: 1.3; }
.hg-format.on { border-color: #0d9488; background: var(--success-bg, #f0fdfa); }
.hg-format.on svg { color: #0d9488; }
.hg-format-opts { background: var(--canvas, #f8fafc); border: 1px solid var(--border, #e2e8f0); border-radius: 12px; padding: 12px 14px; margin-top: 8px; display: flex; flex-direction: column; gap: 10px; }
.hg-lock-chips { display: flex; gap: 8px; flex-wrap: wrap; }
.hg-lock-chip { border: 1.5px solid var(--border, #e2e8f0); background: none; color: var(--text-secondary, #475569); font-weight: 700; font-size: 12.5px; padding: 8px 14px; border-radius: 999px; cursor: pointer; }
.hg-lock-chip.on { background: #0f172a; border-color: #0f172a; color: #fff; }
.hg-community-chip { display: inline-flex; align-items: center; gap: 6px; font-size: 12.5px; font-weight: 700; color: #0d9488; background: var(--success-bg, #f0fdfa); border: 1px solid var(--success-border, #99f6e4); padding: 5px 12px; border-radius: 999px; margin-bottom: 10px; }
.hg-community-chip b { font-weight: 900; }
.hg-cv { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.hg-cv-opt { display: flex; flex-direction: column; align-items: flex-start; gap: 3px; text-align: left; background: none; border: 1.5px solid var(--border, #e2e8f0); border-radius: 14px; padding: 13px 14px; cursor: pointer; font: inherit; color: var(--text-secondary, #475569); }
.hg-cv-opt svg { color: var(--text-muted, #64748b); }
.hg-cv-opt b { font-size: 14.5px; font-weight: 800; color: var(--text, #0f172a); margin-top: 2px; }
.hg-cv-opt span { font-size: 12px; color: var(--text-muted, #64748b); line-height: 1.4; }
.hg-cv-opt.on { border-color: #0d9488; background: var(--success-bg, #f0fdfa); }
.hg-cv-opt.on svg, .hg-cv-opt.on b { color: #0d9488; }
@media (max-width: 560px) { .hg-cv { grid-template-columns: 1fr; } }
.hg-hold { display: flex; align-items: center; gap: 9px; flex-wrap: wrap; font-size: 13px; color: var(--info-ink, #0369a1); background: var(--info-bg, #f0f9ff); border: 1px solid var(--info-border, #bae6fd); border-radius: 12px; padding: 9px 12px; margin-bottom: 14px; }
.hg-hold-t { flex: 1; min-width: 180px; line-height: 1.35; }
.hg-hold-t b { font-variant-numeric: tabular-nums; font-weight: 900; }
.hg-hold.warn { color: var(--warning-ink, #b45309); background: var(--warning-bg, #fffbeb); border-color: var(--warning-border, #fde68a); }
.hg-hold-btn { flex-shrink: 0; display: inline-flex; align-items: center; gap: 5px; background: var(--surface, #ffffff); border: 1px solid currentColor; color: inherit; font: inherit; font-weight: 800; font-size: 12px; padding: 6px 11px; border-radius: 999px; cursor: pointer; }
.hg-hold-btn:disabled { opacity: .6; cursor: default; }

.hg-badge { display: inline-flex; align-items: center; gap: 6px; font-size: 12.5px; font-weight: 800; padding: 5px 12px; border-radius: 999px; margin-bottom: 0; }
.hg-badge.game { background: var(--info-bg, #dbeafe); color: var(--info-ink, #1d4ed8); }
.hg-badge.tournament { background: var(--danger-bg, #ffe4e6); color: var(--danger-ink, #be123c); }
.hg-badge.event { background: var(--warning-bg, #fef3c7); color: var(--warning-ink, #b45309); }
/* Hangout "where will you host it?" — the venue flow and the own-spot flow each get
   a screen of their own, chosen on cards in the same language as the type chooser. */
.hg-spotchoose { grid-template-columns: repeat(auto-fit, minmax(270px, 1fr)); margin-top: 2px; }
.hg-choice-ico.venue { background: var(--success-bg); color: var(--success-ink); }
.hg-choice-ico.own { background: var(--violet-bg); color: var(--violet-ink); }
.hg-choice-tags { display: flex; flex-direction: column; gap: 6px; margin-top: 13px; }
.hg-choice-tags span { display: inline-flex; align-items: center; gap: 6px; font-size: 12.5px; font-weight: 700; color: var(--text-muted, #64748b); }
.hg-choice-fee { color: var(--warning-ink, #b45309); }
/* Chosen path, with the way back to the choice */
.hg-modebar { display: flex; align-items: center; justify-content: space-between; gap: 10px; background: var(--bg, #f8fafc); border: 1px solid var(--border, #e2e8f0); border-radius: 12px; padding: 9px 10px 9px 14px; margin-bottom: 14px; }
.hg-modebar-t { display: inline-flex; align-items: center; gap: 7px; font-size: 13.5px; font-weight: 800; color: var(--text, #0f172a); }
.hg-modebar-x { border: 1px solid var(--border, #e2e8f0); background: var(--surface, #ffffff); color: var(--text, #0f172a); font-weight: 800; font-size: 12.5px; padding: 6px 13px; border-radius: 9px; cursor: pointer; }
.hg-modebar-x:hover { border-color: var(--brand-accent); }

.hg-card { background: var(--surface, #ffffff); border: 1px solid var(--border, #e2e8f0); border-radius: 18px; padding: 16px 18px; margin-bottom: 14px; }
.hg-h3 { font-size: 17px; font-weight: 800; color: var(--text, #0f172a); margin: 0 0 14px; }
.hg-signup { margin-top: 18px; padding-top: 16px; border-top: 1px solid var(--border, #eef2f6); }
.hg-signup .hg-h3 { margin-bottom: 4px; }
.hg-opt { font-size: 11.5px; font-weight: 700; color: var(--text-muted, #64748b); background: var(--bg, #f1f5f9); border-radius: 999px; padding: 2px 9px; vertical-align: middle; margin-left: 6px; }
.hg-signup-sub { font-size: 13px; color: var(--text-muted, #64748b); margin: 0 0 12px; }
.hg-l { display: block; font-size: 13.5px; font-weight: 700; color: var(--text-secondary, #334155); margin: 10px 0 5px; }
.hg-card > .hg-l:first-child, .hg-final-main > .hg-l:first-child, .hg-twocol > div > .hg-l:first-child { margin-top: 0; }
/* Two-column field layouts — use the horizontal space, cut vertical height. */
.hg-twocol { display: grid; grid-template-columns: 1fr 1fr; gap: 12px 18px; align-items: start; }
.hg-twocol .hg-ta { min-height: 132px; }
.hg-final { display: grid; grid-template-columns: 1.15fr 1fr; gap: 18px 24px; align-items: start; }
.hg-final-main, .hg-final-side { min-width: 0; }
.hg-final-side > .hg-review, .hg-final-side > .hg-pay { margin-top: 0; }
.hg-final-side > .hg-pay { margin-top: 12px; }
@media (max-width: 760px) { .hg-twocol, .hg-final { grid-template-columns: 1fr; } }
.hg-opt { font-size: 11px; font-weight: 700; color: var(--text-light, #94a3b8); background: var(--bg, #f1f5f9); padding: 1px 7px; border-radius: 999px; margin-left: 4px; }
.hg-in { width: 100%; box-sizing: border-box; border: 1.5px solid var(--border, #e2e8f0); border-radius: 10px; padding: 12px 14px; font-size: 15px; outline: none; background: var(--surface, #ffffff); }
.hg-in:focus { border-color: var(--brand-accent); }
.hg-ta { resize: vertical; font-family: inherit; }
.hg-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.hg-note { position: relative; padding-left: 20px; font-size: 12.5px; color: var(--text-light, #94a3b8); margin-top: 8px; line-height: 1.4; }
.hg-note > svg { position: absolute; left: 0; top: 2px; }
/* Fee preview: it quotes real money, so it sits a shade darker than a plain hint */
.hg-feenote { color: var(--text-muted, #64748b); font-size: 13px; line-height: 1.5; }
.hg-feenote b { color: var(--text, #0f172a); font-weight: 800; }

/* How-players-pay ticket-mode cards (paid games/hangouts) */
.hg-tickets { margin-top: 14px; }
.hg-tk-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 6px; }
.hg-tk { display: flex; flex-direction: column; align-items: flex-start; gap: 6px; text-align: left; padding: 13px 14px; border: 1.5px solid var(--border, #e2e8f0); border-radius: 14px; background: var(--surface, #ffffff); cursor: pointer; transition: border-color .15s, box-shadow .15s; }
.hg-tk:hover { border-color: var(--border-strong, #cbd5e1); }
.hg-tk.on { border-color: var(--brand, #16a34a); box-shadow: 0 0 0 3px color-mix(in srgb, var(--brand, #16a34a) 12%, transparent); }
.hg-tk-t { display: flex; align-items: center; gap: 7px; flex-wrap: wrap; font-size: 13.5px; font-weight: 800; color: var(--text, #0f172a); }
.hg-tk-d { font-size: 12px; color: var(--text-muted, #64748b); line-height: 1.45; }
.hg-tk-chip { font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: .03em; color: var(--success-ink, #166534); background: var(--success-bg, #dcfce7); padding: 2px 7px; border-radius: 999px; }
.hg-tk-connect { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-top: 10px; padding: 10px 12px; border: 1px dashed var(--border-strong, #cbd5e1); border-radius: 10px; font-size: 12.5px; color: var(--text-secondary, #475569); line-height: 1.45; }
.hg-tk-connect.ok { border-style: solid; border-color: var(--success-border, #bbf7d0); background: var(--success-bg, #f0fdf4); color: var(--success-ink, #166534); font-weight: 700; }
.hg-tk-connect > span:first-child { flex: 1; min-width: 180px; }
.hg-sc-ok { display: flex; gap: 7px; align-items: flex-start; }
.hg-sc-chip { margin-left: 6px; font-size: 11px; padding: 1px 6px; border-radius: 999px; background: rgba(127,127,127,.16); }
.hg-sc-warn { display: flex; gap: 6px; align-items: center; margin-top: 8px; font-size: 12.5px; opacity: .8; }
.hg-sc-links { display: flex; gap: 12px; align-items: center; margin-top: 10px; flex-wrap: wrap; }
.hg-sc-link { background: none; border: 0; padding: 0; font-size: 12.5px; font-weight: 600; text-decoration: underline; cursor: pointer; color: inherit; opacity: .8; }
.hg-sc-tabs { display: flex; gap: 6px; margin: 10px 0; flex-wrap: wrap; }
.hg-sc-tabs button { flex: 1; min-width: 150px; font-size: 12.5px; font-weight: 600; padding: 7px 10px; border-radius: 8px; cursor: pointer; border: 1px solid var(--line, #e5e7eb); background: transparent; color: inherit; }
.hg-sc-tabs button.on { border-color: var(--brand, #0f766e); background: color-mix(in srgb, var(--brand, #0f766e) 8%, transparent); }
.hg-sc-pane { display: flex; flex-direction: column; gap: 6px; }
.hg-sc-p { margin: 0 0 4px; font-size: 12.5px; line-height: 1.55; opacity: .8; }
.hg-sc-lbl { font-size: 12px; font-weight: 600; margin-top: 4px; }
.hg-sc-opt { font-weight: 400; opacity: .6; }
.hg-sc-note { margin: 4px 0 2px; font-size: 11.5px; line-height: 1.5; opacity: .68; }
.hg-sc-err { margin-top: 10px; font-size: 12.5px; color: var(--danger-ink, #dc2626); }
.hg-tk-acct { font-family: ui-monospace, monospace; font-size: 11.5px; font-weight: 600; color: var(--success-ink, #15803d); background: var(--success-bg, #dcfce7); padding: 2px 8px; border-radius: 999px; }
.hg-tk-btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border: none; border-radius: 999px; background: #635bff; color: #fff; font-size: 12.5px; font-weight: 800; cursor: pointer; white-space: nowrap; }
.hg-tk-btn:disabled { opacity: .6; cursor: default; }
.hg-pay-free { color: var(--success-ink, #16a34a); }
@media (max-width: 640px) { .hg-tk-grid { grid-template-columns: 1fr; } }
.hg-hint { font-size: 12px; color: var(--text-light, #94a3b8); margin-top: 5px; line-height: 1.45; }
.hg-rec { font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: .04em; color: var(--success-ink, #0f766e); background: var(--success-bg, #ccfbf1); border-radius: 999px; padding: 2px 7px; margin-left: 6px; }
.hg-feehint { display: flex; align-items: flex-start; gap: 7px; font-size: 12.5px; color: var(--brand-ink, var(--brand)); background: color-mix(in srgb, var(--brand) 8%, var(--surface, white)); border: 1px solid var(--success-border, #99f6e4); border-radius: 10px; padding: 10px 12px; margin-top: 10px; line-height: 1.45; }
.hg-feehint b { color: var(--text, #0f172a); }
.hg-auto { display: flex; align-items: center; gap: 14px; background: linear-gradient(135deg,var(--danger-bg, #fff1f2),var(--danger-bg, #ffe4e6)); border: 1.5px solid #fecdd3; border-radius: 14px; padding: 16px; margin-bottom: 14px; }
.hg-auto-l { flex: 1; min-width: 0; }
.hg-auto-t { display: flex; align-items: center; gap: 7px; font-size: 15px; font-weight: 900; color: var(--danger-ink, #be123c); }
.hg-auto-d { font-size: 12.5px; color: var(--danger-ink, #9f1239); margin-top: 4px; line-height: 1.45; }
.hg-auto-btn { display: inline-flex; align-items: center; gap: 7px; flex-shrink: 0; background: #e11d48; color: #fff; font-weight: 800; font-size: 14px; padding: 12px 18px; border: none; border-radius: 12px; cursor: pointer; }
.hg-auto-btn:disabled { opacity: .5; cursor: not-allowed; }

/* Auto-schedule result plan (informs the host what was decided) */
.hg-ap { background: var(--success-bg, #f0fdf4); border: 1.5px solid var(--success-border, #bbf7d0); border-radius: 14px; padding: 14px 16px; margin-bottom: 14px; }
.hg-ap-h { display: flex; align-items: center; gap: 7px; font-size: 13.5px; font-weight: 900; color: var(--success-ink, #15803d); margin-bottom: 8px; }
.hg-ap-row { display: flex; align-items: baseline; justify-content: space-between; gap: 10px; font-size: 13px; color: var(--success-ink, #166534); padding: 3px 0; }
.hg-ap-row span { font-weight: 700; flex-shrink: 0; }
.hg-ap-row b { font-weight: 600; text-align: right; }
.hg-ap-note { display: flex; align-items: flex-start; gap: 6px; font-size: 12px; color: var(--warning-ink, #92400e); background: var(--warning-bg, #fffbeb); border: 1px solid var(--warning-border, #fde68a); border-radius: 9px; padding: 8px 10px; margin-top: 8px; line-height: 1.45; }

/* Auto-schedule preferences modal */
.hg-am-backdrop { position: fixed; inset: 0; z-index: 1200; background: rgba(15,23,42,.55); display: flex; align-items: center; justify-content: center; padding: 14px; }
.hg-am { width: 100%; max-width: 440px; max-height: 92vh; overflow-y: auto; background: var(--surface, #ffffff); border-radius: 18px; padding: 18px; box-shadow: 0 24px 64px rgba(15,23,42,.35); }
.hg-am-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 4px; }
.hg-am-head > span { display: inline-flex; align-items: center; gap: 8px; font-size: 15.5px; font-weight: 900; color: var(--text, #0f172a); }
.hg-am-x { background: none; border: none; color: var(--text-muted, #64748b); cursor: pointer; padding: 4px; border-radius: 8px; display: inline-flex; }
.hg-am-x:hover { background: var(--bg, #f1f5f9); color: var(--text, #0f172a); }
.hg-am-opts { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin: 14px 0 12px; }
.hg-am-opt { display: flex; flex-direction: column; align-items: center; text-align: center; gap: 3px; border: 1.5px solid var(--border, #e2e8f0); border-radius: 14px; background: var(--surface, #ffffff); padding: 16px 10px 12px; cursor: pointer; transition: all .12s; }
.hg-am-opt:hover { border-color: #e11d48; }
.hg-am-opt.on { border-color: #e11d48; background: var(--danger-bg, #fff1f2); box-shadow: 0 0 0 3px rgba(225,29,72,.12); }
.hg-am-opt-ic { width: 44px; height: 44px; border-radius: 12px; background: var(--bg, #f1f5f9); color: var(--text-muted, #64748b); display: flex; align-items: center; justify-content: center; margin-bottom: 4px; }
.hg-am-opt.on .hg-am-opt-ic { background: #e11d48; color: #fff; }
.hg-am-opt-t { font-size: 13.5px; font-weight: 800; color: var(--text, #0f172a); }
.hg-am-opt.on .hg-am-opt-t { color: var(--danger-ink, #be123c); }
.hg-am-opt-d { font-size: 11.5px; color: var(--text-light, #94a3b8); }
.hg-am-opt.on .hg-am-opt-d { color: var(--danger-ink, #9f1239); }
/* tiny pacing illustration: blocks = games, gaps = days apart */
.hg-am-viz { display: flex; align-items: center; gap: 3px; margin-top: 8px; }
.hg-am-viz i { display: block; width: 12px; height: 8px; border-radius: 2.5px; background: #cbd5e1; }
.hg-am-viz i.gap { width: 5px; height: 2px; border-radius: 1px; }
.hg-am-opt.on .hg-am-viz i.a { background: #e11d48; }
.hg-am-opt.on .hg-am-viz i.b { background: #f43f5e; }
.hg-am-opt.on .hg-am-viz i.c { background: #fb7185; }
.hg-am-opt.on .hg-am-viz i.gap { background: #fecdd3; }
.hg-am-row { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
.hg-am-rl { font-size: 12.5px; font-weight: 800; color: var(--text-secondary, #475569); width: 74px; flex-shrink: 0; }
.hg-am-chips { display: flex; gap: 6px; flex-wrap: wrap; }
.hg-am-chip { display: inline-flex; align-items: center; gap: 5px; padding: 8px 12px; border: 1.5px solid var(--border, #e2e8f0); border-radius: 999px; background: var(--surface, #ffffff); font-size: 12.5px; font-weight: 700; color: var(--text-secondary, #334155); cursor: pointer; }
.hg-am-chip.on { border-color: #e11d48; background: var(--danger-bg, #fff1f2); color: var(--danger-ink, #be123c); }
.hg-am-go { width: 100%; display: inline-flex; align-items: center; justify-content: center; gap: 7px; background: #e11d48; color: #fff; font-weight: 800; font-size: 14.5px; padding: 13px; border: none; border-radius: 12px; cursor: pointer; }
.hg-am-go:disabled { opacity: .5; cursor: not-allowed; }
.hg-am-foot { font-size: 12px; color: var(--text-light, #94a3b8); text-align: center; margin-top: 8px; }

/* slots */
.hg-slots { display: grid; grid-template-columns: repeat(auto-fill, minmax(92px, 1fr)); gap: 8px; margin-top: 4px; }
.hg-slots:has(.hg-block) { grid-template-columns: repeat(auto-fill, minmax(132px, 1fr)); }
.hg-block .hg-slot-t { font-size: 12.5px; }
.hg-slot { display: flex; flex-direction: column; align-items: center; gap: 2px; padding: 10px 6px; border: 1.5px solid var(--border, #e2e8f0); border-radius: 10px; background: var(--surface, #ffffff); cursor: pointer; }
.hg-slot:hover:not(:disabled) { border-color: var(--brand-accent); }
.hg-slot.on { border-color: var(--brand); background: var(--brand); }
.hg-slot.on .hg-slot-t { color: #fff; }
.hg-slot.taken { background: var(--canvas, #f8fafc); cursor: not-allowed; opacity: .65; }
.hg-slot-t { font-size: 13.5px; font-weight: 800; color: var(--text, #0f172a); }
.hg-slot-p { font-size: 11px; font-weight: 700; color: var(--brand-ink, var(--brand)); }
.hg-slot.on .hg-slot-p { color: #fff; }
.hg-slot-x { font-size: 10px; font-weight: 700; color: #cbd5e1; }
.hg-slots-msg { display: flex; align-items: center; gap: 8px; color: var(--text-muted, #64748b); font-size: 14px; padding: 8px 0; }
.hg-spin { animation: hgspin 1s linear infinite; } @keyframes hgspin { to { transform: rotate(360deg); } }

/* event photo drop-zone */
.hg-drop { border: 1.5px dashed var(--border-strong, #cbd5e1); border-radius: 12px; padding: 14px; background: var(--canvas, #f8fafc); transition: border-color .12s, background .12s; }
.hg-drop.on { border-color: var(--brand-accent); background: color-mix(in srgb, var(--brand) 8%, var(--surface, white)); }
.hg-imgs { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 10px; }
.hg-img { position: relative; width: 86px; height: 86px; border-radius: 10px; background-size: cover; background-position: center; border: 1px solid var(--border, #e2e8f0); }
.hg-img-cover { position: absolute; left: 4px; bottom: 4px; font-size: 9.5px; font-weight: 800; text-transform: uppercase; letter-spacing: .03em; color: #fff; background: rgba(15,23,42,.78); padding: 2px 6px; border-radius: 5px; }
.hg-img-x { position: absolute; top: 3px; right: 3px; width: 20px; height: 20px; border-radius: 50%; border: none; background: rgba(15,23,42,.78); color: #fff; display: flex; align-items: center; justify-content: center; cursor: pointer; }
.hg-img-x:hover { background: #ef4444; }
.hg-drop-btn { display: inline-flex; align-items: center; gap: 7px; background: var(--surface, #ffffff); color: var(--brand-ink, var(--brand)); font-weight: 700; font-size: 13.5px; padding: 9px 14px; border: 1.5px solid var(--success-border, #99f6e4); border-radius: 10px; cursor: pointer; }
.hg-drop-btn:hover:not(:disabled) { background: color-mix(in srgb, var(--brand) 8%, var(--surface, white)); }
.hg-drop-btn:disabled { opacity: .6; cursor: default; }
.hg-file { display: none; }
.hg-drop-hint { font-size: 11.5px; color: var(--text-light, #94a3b8); margin-top: 8px; }
.hg-img-err { font-size: 12.5px; color: var(--danger-ink, #dc2626); margin-top: 6px; }
.hg-rangebox { display: flex; align-items: center; gap: 8px; margin-top: 12px; background: color-mix(in srgb, var(--brand) 8%, var(--surface, white)); border: 1px solid var(--success-border, #99f6e4); border-radius: 12px; padding: 12px 14px; font-size: 13.5px; color: var(--brand-ink, var(--brand)); }
.hg-rangebox b { color: var(--text, #0f172a); }

/* Court browse cards (game step 2) */
.hg-courts { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 14px; }
.hg-court { text-align: left; background: var(--surface, #ffffff); border: 2px solid var(--border, #e2e8f0); border-radius: 16px; overflow: hidden; cursor: pointer; padding: 0; transition: .12s; }
.hg-court:hover { border-color: var(--brand-accent); transform: translateY(-2px); box-shadow: 0 10px 24px rgba(15,23,42,.10); }
.hg-court.on { border-color: var(--brand); box-shadow: 0 0 0 3px color-mix(in srgb, var(--brand) 18%, white); }
.hg-court-img { position: relative; height: 120px; background: var(--brand-accent) center/cover no-repeat; display: flex; align-items: center; justify-content: center; color: rgba(255,255,255,.85); }
.hg-court-check { position: absolute; top: 8px; right: 8px; width: 26px; height: 26px; border-radius: 50%; background: var(--brand); color: #fff; display: flex; align-items: center; justify-content: center; }
.hg-court-body { padding: 11px 13px 13px; }
.hg-court-name { font-size: 15px; font-weight: 800; color: var(--text, #0f172a); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.hg-court-venue { display: flex; align-items: center; gap: 4px; font-size: 12.5px; color: var(--text-muted, #64748b); margin-top: 3px; }
.hg-court-price { font-size: 13px; font-weight: 800; color: var(--text, #0f172a); margin-top: 7px; }
.hg-court-price span { font-size: 11.5px; font-weight: 600; color: var(--text-light, #94a3b8); }
.hg-court-sel { display: flex; align-items: center; gap: 7px; font-size: 14.5px; font-weight: 800; color: var(--brand-ink, var(--brand)); margin-bottom: 12px; }
.hg-court-sel span { color: var(--text-muted, #64748b); font-weight: 600; font-size: 13px; }
.hg-court-open { display: inline-flex; align-items: center; gap: 4px; font-size: 12.5px; font-weight: 800; color: var(--brand-ink, var(--brand)); margin-top: 9px; }
.hg-court.on .hg-court-open { color: var(--brand-ink, var(--brand)); }

/* Court detail modal */
.hg-modal { position: fixed; inset: 0; z-index: 200; background: rgba(15,23,42,.55); display: flex; align-items: center; justify-content: center; padding: 18px; }
.hg-modal-card { position: relative; width: 460px; max-width: 100%; max-height: 90vh; overflow-y: auto; background: var(--surface, #ffffff); border-radius: 18px; }
.hg-modal-x { position: absolute; top: 12px; right: 12px; z-index: 2; width: 34px; height: 34px; border-radius: 50%; border: none; background: rgba(255,255,255,.9); color: var(--text, #0f172a); display: flex; align-items: center; justify-content: center; cursor: pointer; }
.hg-modal-hero { height: 180px; background: var(--brand-accent) center/cover no-repeat; border-radius: 18px 18px 0 0; display: flex; align-items: center; justify-content: center; color: rgba(255,255,255,.85); }
.hg-modal-body { padding: 18px 20px 6px; }
.hg-modal-name { font-size: 21px; font-weight: 900; color: var(--text, #0f172a); margin: 0; }
.hg-modal-tags { display: flex; flex-wrap: wrap; align-items: center; gap: 9px; margin: 10px 0; }
.hg-modal-tag { font-size: 12.5px; font-weight: 700; color: var(--brand-ink, var(--brand)); background: color-mix(in srgb, var(--brand) 18%, var(--surface, white)); padding: 3px 11px; border-radius: 999px; text-transform: capitalize; }
.hg-modal-fact { display: flex; align-items: center; gap: 5px; font-size: 13px; color: var(--text-muted, #64748b); font-weight: 600; }
.hg-modal-desc { font-size: 14px; color: var(--text-secondary, #334155); line-height: 1.55; margin: 12px 0; }
.hg-modal-where { display: flex; align-items: flex-start; gap: 9px; color: var(--brand-ink, var(--brand)); margin: 12px 0; }
.hg-modal-where b { display: block; font-size: 14.5px; color: var(--text, #0f172a); }
.hg-modal-where span { font-size: 13px; color: var(--text-muted, #64748b); }
.hg-modal-price { font-size: 17px; font-weight: 900; color: var(--text, #0f172a); margin: 12px 0 2px; }
.hg-modal-price span { font-size: 12.5px; font-weight: 600; color: var(--text-light, #94a3b8); }
.hg-modal-foot { display: flex; align-items: center; gap: 10px; padding: 14px 20px 18px; position: sticky; bottom: 0; background: var(--surface, #ffffff); }
.hg-modal-foot .hg-next { margin-left: auto; }

/* tournament teams + preview */
.hg-teams { display: flex; gap: 10px; flex-wrap: wrap; }
.hg-team { width: 64px; height: 56px; border: 2px solid var(--border, #e2e8f0); background: var(--surface, #ffffff); border-radius: 14px; font-size: 20px; font-weight: 900; color: var(--text-secondary, #334155); cursor: pointer; }
.hg-team.on { border-color: #e11d48; background: var(--danger-bg, #fff1f2); color: var(--danger-ink, #be123c); }
.hg-len { width: auto; min-width: 58px; padding: 0 14px; height: 52px; font-size: 18px; display: inline-flex; align-items: baseline; justify-content: center; gap: 3px; }
.hg-len-u { font-size: 11px; font-weight: 700; color: var(--text-light, #94a3b8); }
.hg-len.on .hg-len-u { color: #e11d48; }
.hg-preview { margin-top: 16px; background: var(--danger-bg, #fff1f2); border: 1px solid #fecdd3; border-radius: 14px; padding: 14px 16px; }
.hg-preview-h { display: flex; align-items: center; gap: 8px; font-size: 15px; font-weight: 900; color: var(--danger-ink, #be123c); }
.hg-preview-rounds { margin: 10px 0 4px; }
.hg-preview-r { display: flex; align-items: center; justify-content: space-between; font-size: 13.5px; color: var(--danger-ink, #9f1239); padding: 4px 0; border-top: 1px dashed #fecdd3; }
.hg-preview-r:first-child { border-top: none; }
.hg-preview-r b { color: var(--text, #0f172a); }

/* tournament rounds */
.hg-round-h { display: flex; align-items: center; gap: 10px; margin-bottom: 6px; }
.hg-round-name { font-size: 16px; font-weight: 900; color: var(--text, #0f172a); }
.hg-round-games { font-size: 12px; font-weight: 800; color: var(--danger-ink, #be123c); background: var(--danger-bg, #ffe4e6); padding: 3px 9px; border-radius: 999px; }
.hg-round-ok { color: var(--success-ink, #16a34a); margin-left: auto; }
.hg-game { border-top: 1px dashed var(--border, #e2e8f0); padding-top: 14px; margin-top: 14px; }
.hg-game:first-of-type { border-top: none; padding-top: 6px; margin-top: 6px; }
.hg-game-h { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 2px; }
.hg-game-n { font-size: 14px; font-weight: 800; color: var(--text-secondary, #334155); }
.hg-game-when { display: inline-flex; align-items: center; gap: 4px; font-size: 12.5px; font-weight: 700; color: var(--success-ink, #16a34a); background: var(--success-bg, #f0fdf4); padding: 3px 9px; border-radius: 999px; }
.hg-roundwarn { display: flex; align-items: flex-start; gap: 6px; font-size: 12.5px; color: var(--danger-ink, #be123c); background: var(--danger-bg, #fff1f2); border: 1px solid #fecdd3; border-radius: 10px; padding: 10px; margin-top: 8px; }
.hg-matches { margin-top: 12px; border: 1px solid var(--border, #eef2f6); border-radius: 12px; overflow: hidden; }
.hg-match { display: flex; align-items: center; justify-content: space-between; font-size: 13.5px; color: var(--text-secondary, #334155); padding: 9px 14px; }
.hg-match + .hg-match { border-top: 1px solid var(--border, #f1f5f9); }
.hg-match b { color: var(--text, #0f172a); }

/* tournament bracket chart (setup) */
.hg-wide { max-width: 1000px; }
.hg-chart-back { display: inline-flex; align-items: center; gap: 6px; background: none; border: none; color: var(--brand-ink, var(--brand)); font-weight: 800; font-size: 14px; cursor: pointer; padding: 0; margin-bottom: 12px; }
.hg-chart-bar { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 12px; }
.hg-chart-prog { display: inline-flex; align-items: center; gap: 7px; font-size: 13px; font-weight: 800; color: var(--danger-ink, #be123c); background: var(--danger-bg, #ffe4e6); border: 1px solid #fecdd3; padding: 7px 13px; border-radius: 999px; }
.hg-chart-prog.done { color: var(--success-ink, #15803d); background: var(--success-bg, #f0fdf4); border-color: var(--success-border, #bbf7d0); }
.hg-chart-hint { display: inline-flex; align-items: center; gap: 5px; font-size: 12.5px; font-weight: 700; color: var(--text-muted, #64748b); }
.hg-bracket { display: flex; gap: 18px; overflow-x: auto; padding: 4px 2px 14px; align-items: stretch; }
.hg-bcol { flex: 0 0 200px; min-width: 200px; display: flex; flex-direction: column; }
.hg-bcol-h { display: flex; align-items: center; gap: 7px; margin-bottom: 12px; padding-bottom: 8px; border-bottom: 2px solid #fecdd3; }
.hg-bcol-h .hg-round-name { font-size: 14.5px; }
.hg-bcol-h .hg-round-ok { color: var(--success-ink, #16a34a); margin-left: auto; }
.hg-bcol-games { flex: 1; display: flex; flex-direction: column; justify-content: space-around; gap: 12px; }
.hg-mcard { position: relative; text-align: left; background: var(--surface, #ffffff); border: 2px solid var(--border, #e2e8f0); border-radius: 14px; padding: 12px 13px; cursor: pointer; transition: .12s; }
.hg-mcard:hover { border-color: var(--brand-accent); transform: translateY(-1px); box-shadow: 0 8px 20px rgba(15,23,42,.09); }
.hg-mcard.done { border-color: var(--success-border, #86efac); background: var(--success-bg, #f0fdf4); }
.hg-mcard.done:hover { border-color: #22c55e; }
.hg-mcard-top { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.hg-mcard-n { font-size: 14px; font-weight: 900; color: var(--text, #0f172a); }
.hg-mcard-badge { width: 22px; height: 22px; flex-shrink: 0; border-radius: 50%; background: #16a34a; color: #fff; display: flex; align-items: center; justify-content: center; }
.hg-mcard-badge.todo { background: var(--danger-bg, #ffe4e6); color: #e11d48; }
.hg-mcard-line { display: flex; align-items: center; gap: 6px; font-size: 12px; color: var(--text-secondary, #475569); margin-top: 6px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.hg-mcard-line.venue { color: var(--text, #0f172a); font-weight: 700; }
.hg-mcard-line :deep(svg) { flex-shrink: 0; color: var(--text-light, #94a3b8); }
.hg-mcard-edit { display: inline-flex; align-items: center; gap: 4px; font-size: 11px; font-weight: 800; color: var(--brand-ink, var(--brand)); margin-top: 9px; }
.hg-mcard-empty { display: flex; align-items: center; gap: 6px; font-size: 12.5px; font-weight: 700; color: #e11d48; margin-top: 10px; }
/* Slot-clash warning reads as a hard blocker (heavier than the round-order hint). */
.hg-clashwarn { border-color: var(--danger-border, #fda4af); background: var(--danger-bg, #fff1f2); font-weight: 700; }
/* Mobile round-by-round bracket: hidden on desktop (columns stay untouched ≥761px). */
.hg-mb { display: none; }
@media (max-width: 1020px) { .hg-wide { max-width: 100%; } }

/* review */
.hg-review { margin-top: 16px; border: 1px solid var(--border, #e2e8f0); border-radius: 12px; padding: 14px 16px; background: var(--canvas, #f8fafc); }
.hg-review-h { font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: .05em; color: var(--text-light, #94a3b8); margin-bottom: 6px; }
.hg-review-row { display: flex; align-items: center; justify-content: space-between; font-size: 14px; color: var(--text-secondary, #475569); padding: 4px 0; }
.hg-review-row b { color: var(--text, #0f172a); }
.hg-review-dur { color: var(--text-light, #94a3b8); font-weight: 600; font-size: 12px; }
.hg-review-prize span:first-child { display: inline-flex; align-items: center; gap: 5px; color: var(--warning-ink, #b45309); font-weight: 700; }
.hg-review-prize b { color: var(--warning-ink, #b45309); }

/* prize pot toggle + per-place rows */
.hg-prize-toggle { display: flex; align-items: center; justify-content: space-between; gap: 14px; margin-top: 14px; padding: 13px 16px; border: 1.5px solid var(--border, #e2e8f0); border-radius: 14px; background: var(--surface, #ffffff); }
.hg-prize-tt { display: flex; align-items: center; gap: 7px; font-size: 15px; font-weight: 800; color: var(--text, #0f172a); }
.hg-prize-td { font-size: 12.5px; color: var(--text-light, #94a3b8); margin-top: 3px; }
.hg-switch { flex-shrink: 0; width: 48px; height: 28px; border-radius: 999px; border: none; background: #cbd5e1; cursor: pointer; padding: 0; position: relative; transition: background .15s; }
.hg-switch.on { background: #16a34a; }
.hg-switch-k { position: absolute; top: 3px; left: 3px; width: 22px; height: 22px; border-radius: 50%; background: var(--surface, #ffffff); transition: transform .15s; box-shadow: 0 1px 3px rgba(0,0,0,.2); }
.hg-switch.on .hg-switch-k { transform: translateX(20px); }
.hg-prize-box { margin-top: 10px; border: 1.5px solid var(--warning-border, #fde68a); background: var(--warning-bg, #fffbeb); border-radius: 14px; padding: 14px 16px; }
.hg-prize-row { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
.hg-prize-badge { flex-shrink: 0; width: 26px; height: 26px; border-radius: 50%; display: flex; align-items: center; justify-content: center; background: var(--warning-bg, #fde68a); font-size: 13px; font-weight: 900; color: var(--warning-ink, #92400e); }
.hg-prize-place { flex-shrink: 0; width: 70px; font-size: 13.5px; font-weight: 700; color: var(--warning-ink, #92400e); }
.hg-prize-amt { flex: 1; display: flex; align-items: center; gap: 6px; border: 1.5px solid var(--warning-border, #fcd34d); border-radius: 10px; padding: 0 12px; background: var(--surface, #ffffff); }
.hg-prize-amt:focus-within { border-color: #f59e0b; }
.hg-prize-cur { font-size: 13px; font-weight: 700; color: var(--text-light, #94a3b8); }
.hg-prize-amt input { flex: 1; min-width: 0; border: none; outline: none; padding: 11px 0; font-size: 15px; background: none; color: var(--text, #0f172a); }
.hg-prize-x { flex-shrink: 0; width: 30px; height: 30px; border-radius: 8px; border: none; background: var(--warning-bg, #fef3c7); color: var(--warning-ink, #b45309); display: flex; align-items: center; justify-content: center; cursor: pointer; }
.hg-prize-x:hover { background: var(--warning-bg, #fde68a); }
.hg-prize-x.ghost { background: none; cursor: default; }
.hg-prize-add { display: inline-flex; align-items: center; gap: 6px; background: none; border: 1.5px dashed var(--warning-border, #fcd34d); color: var(--warning-ink, #b45309); font-weight: 800; font-size: 13.5px; padding: 9px 14px; border-radius: 10px; cursor: pointer; }
.hg-prize-add:hover { background: var(--warning-bg, #fef3c7); }
.hg-prize-total { display: flex; align-items: center; justify-content: space-between; margin-top: 12px; padding-top: 12px; border-top: 1px dashed var(--warning-border, #fcd34d); font-size: 14px; font-weight: 700; color: var(--warning-ink, #92400e); }
.hg-prize-total b { font-size: 19px; font-weight: 900; color: var(--warning-ink, #b45309); }
.hg-pay { margin-top: 14px; border: 1.5px solid var(--success-border, #99f6e4); border-radius: 12px; padding: 14px 16px; background: color-mix(in srgb, var(--brand) 8%, var(--surface, white)); }
.hg-pay-h { font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: .05em; color: var(--brand-ink, var(--brand)); margin-bottom: 8px; }
.hg-pay-row { display: flex; align-items: center; justify-content: space-between; gap: 10px; font-size: 14px; color: var(--text-secondary, #334155); padding: 5px 0; }
/* The amount never breaks across lines — "USD" above "5.00" read as two numbers. */
.hg-pay-row b { color: var(--text, #0f172a); font-weight: 800; white-space: nowrap; }
.hg-paypick { margin-bottom: 14px; }
.hg-paypick-opt { display: flex; gap: 10px; align-items: flex-start; padding: 10px 12px; border: 1px solid var(--line, #e5e7eb); border-radius: 10px; margin-top: 8px; cursor: pointer; }
.hg-paypick-opt.on { border-color: var(--brand, #0f766e); background: color-mix(in srgb, var(--brand, #0f766e) 6%, transparent); }
.hg-paypick-txt { display: flex; flex-direction: column; gap: 2px; }
.hg-paypick-lbl { font-weight: 600; font-size: 13px; }
.hg-paypick-sub { font-size: 12px; opacity: .7; }
.hg-pay-nr { font-size: 10.5px; font-weight: 800; text-transform: uppercase; letter-spacing: .03em; color: var(--danger-ink, #b91c1c); background: var(--danger-bg, #fee2e2); padding: 2px 7px; border-radius: 999px; margin-left: 6px; }
.hg-pay-total { border-top: 1px solid var(--success-border, #99f6e4); margin-top: 4px; padding-top: 9px; font-size: 15px; }
.hg-pay-total b { font-size: 19px; font-weight: 900; }
.hg-pay-arr { color: var(--text-muted, #64748b); font-size: 13px; }
.hg-pay-arr > span:first-child { display: inline-flex; align-items: center; gap: 5px; }
.hg-pay-sub { font-size: 11.5px; font-weight: 800; text-transform: uppercase; letter-spacing: .04em; color: var(--text-light, #94a3b8); margin: 10px 0 2px; padding-top: 9px; border-top: 1px dashed var(--success-border, #99f6e4); }
.hg-pay-g { color: var(--text-light, #94a3b8); font-weight: 600; }
.hg-pay-arrtotal { font-size: 12px; color: var(--text-light, #94a3b8); text-align: right; margin-top: 3px; }
.hg-pay-warn { position: relative; font-size: 12.5px; color: var(--warning-ink, #92400e); background: var(--warning-bg, #fffbeb); border: 1px solid var(--warning-border, #fde68a); border-radius: 10px; padding: 10px 12px 10px 31px; margin-top: 10px; line-height: 1.45; }
.hg-pay-warn > svg { position: absolute; left: 11px; top: 11px; }
.hg-pay-warn b { color: var(--text, #0f172a); }
.hg-pay-warn-stripe { color: var(--violet-ink, #4338ca); background: var(--violet-bg, #eef2ff); border-color: var(--violet-border, #c7d2fe); }
.hg-pay-warn-stripe b { color: #312e81; }
/* Host loyalty redemption toggle (Bookboll marketplace) */
.hg-loy { display: flex; align-items: flex-start; gap: 10px; background: var(--warning-bg, #fffbeb); border: 1px solid var(--warning-border, #fde68a); border-radius: 12px; padding: 10px 12px; margin-top: 10px; cursor: pointer; }
.hg-loy-cb { margin-top: 2px; width: 17px; height: 17px; accent-color: var(--brand); flex-shrink: 0; }
.hg-loy-txt { display: flex; flex-direction: column; gap: 2px; }
.hg-loy-lbl { display: inline-flex; align-items: center; gap: 6px; font-size: 13.5px; font-weight: 800; color: var(--warning-ink, #92400e); }
.hg-loy-sub { font-size: 12px; color: var(--warning-ink, #b45309); font-weight: 600; }
.hg-pay-charged { border-top: 1px dashed var(--success-border, #99f6e4); margin-top: 4px; padding-top: 8px; font-weight: 800; }
.hg-pay-charged b { color: var(--success-ink, #16a34a); }

.hg-err { font-size: 14px; color: var(--danger-ink, #dc2626); background: var(--danger-bg, #fef2f2); border-radius: 10px; padding: 12px; margin-bottom: 12px; }
.hg-actions { display: flex; align-items: center; gap: 10px; margin-top: 18px; position: sticky; bottom: 0; padding: 14px 0 10px; background: linear-gradient(to top, var(--canvas, #f8fafc) 72%, rgba(248,250,252,0)); z-index: 5; }
.hg-back { display: inline-flex; align-items: center; gap: 6px; background: var(--surface, #ffffff); border: 1.5px solid var(--border, #e2e8f0); color: var(--text-secondary, #334155); font-weight: 700; padding: 13px 18px; border-radius: 12px; cursor: pointer; }
.hg-next { margin-left: auto; display: inline-flex; align-items: center; gap: 7px; background: var(--brand); color: #fff; font-weight: 800; font-size: 15px; padding: 14px 24px; border: none; border-radius: 12px; cursor: pointer; }
.hg-submit { margin-left: auto; background: #059669; color: #fff; font-weight: 800; font-size: 15.5px; padding: 14px 24px; border: none; border-radius: 12px; cursor: pointer; }
.hg-submit:hover:not(:disabled) { background: #047857; }
.hg-submit:disabled { opacity: .6; }
.hg-signin { text-align: center; padding: 50px 20px; color: var(--brand-ink, var(--brand)); }
.hg-signin h2 { font-size: 22px; color: var(--text, #0f172a); margin: 14px 0 6px; }
.hg-signin p { font-size: 14.5px; color: var(--text-muted, #64748b); margin: 0 0 20px; }
.hg-signin-btn { display: inline-block; background: var(--brand); color: #fff; font-weight: 800; font-size: 15px; padding: 13px 24px; border-radius: 999px; text-decoration: none; border: none; cursor: pointer; }

/* Published! share modal */
/* "Important bits" waiver modal */
.hg-wv-back { position: fixed; inset: 0; z-index: 200; background: rgba(15,23,42,.5); display: flex; align-items: center; justify-content: center; padding: 20px; }
.hg-wv { position: relative; background: var(--surface, #ffffff); border-radius: 18px; padding: 22px; max-width: 480px; width: 100%; box-shadow: 0 24px 60px rgba(0,0,0,.3); max-height: 90vh; overflow-y: auto; }
.hg-wv :deep(.wv) { margin: 0; }
.hg-wv-x { position: absolute; top: 12px; right: 12px; width: 30px; height: 30px; border-radius: 8px; border: none; background: var(--bg, #f1f5f9); color: var(--text-muted, #64748b); display: flex; align-items: center; justify-content: center; cursor: pointer; }
.hg-wv-x:hover { background: var(--border, #e2e8f0); color: var(--text, #0f172a); }
.hg-wv-actions { display: flex; gap: 10px; margin-top: 16px; }
.hg-wv-cancel { flex-shrink: 0; background: var(--bg, #f1f5f9); color: var(--text-secondary, #334155); border: none; border-radius: 12px; padding: 13px 18px; font-size: 14.5px; font-weight: 800; cursor: pointer; }
.hg-wv-cancel:hover { background: var(--border, #e2e8f0); }
.hg-wv-go { flex: 1; background: var(--brand); color: #fff; border: none; border-radius: 12px; padding: 13px 18px; font-size: 14.5px; font-weight: 800; cursor: pointer; }
.hg-wv-go:disabled { opacity: .5; cursor: not-allowed; }

.hg-done-back { position: fixed; inset: 0; z-index: 200; background: rgba(15,23,42,.5); display: flex; align-items: center; justify-content: center; padding: 20px; }
.hg-done { background: var(--surface, #ffffff); border-radius: 18px; padding: 26px 24px; max-width: 420px; width: 100%; text-align: center; box-shadow: 0 24px 60px rgba(0,0,0,.3); }
.hg-done-ico { width: 56px; height: 56px; border-radius: 50%; background: linear-gradient(135deg,#10b981,#059669); color: #fff; display: flex; align-items: center; justify-content: center; margin: 0 auto 14px; }
.hg-done-t { font-size: 21px; font-weight: 900; color: var(--text, #0f172a); margin: 0 0 8px; }
.hg-done-t::first-letter { text-transform: uppercase; }
.hg-done-d { font-size: 14px; color: var(--text-secondary, #475569); line-height: 1.55; margin: 0 0 18px; }
.hg-done-link { display: flex; align-items: center; gap: 8px; background: var(--canvas, #f8fafc); border: 1px solid var(--border, #e2e8f0); border-radius: 12px; padding: 8px 8px 8px 12px; margin-bottom: 14px; }
.hg-done-url { flex: 1; min-width: 0; font-size: 12.5px; color: var(--text-muted, #64748b); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; text-align: left; }
.hg-done-copy { display: inline-flex; align-items: center; gap: 5px; background: var(--surface, #ffffff); border: 1.5px solid var(--border, #e2e8f0); border-radius: 9px; padding: 7px 11px; font-size: 13px; font-weight: 700; color: var(--brand-ink, var(--brand)); cursor: pointer; flex-shrink: 0; }
.hg-done-copy:hover { background: color-mix(in srgb, var(--brand) 8%, var(--surface, white)); border-color: var(--success-border, #99f6e4); }
.hg-done-actions { display: flex; gap: 10px; }
.hg-done-share { flex: 1; display: inline-flex; align-items: center; justify-content: center; gap: 7px; background: var(--surface, #ffffff); border: 1.5px solid var(--border-strong, #cbd5e1); border-radius: 12px; padding: 12px; font-size: 14.5px; font-weight: 800; color: var(--text-secondary, #334155); cursor: pointer; }
.hg-done-share:hover { border-color: var(--brand-accent); color: var(--brand-ink, var(--brand)); }
.hg-done-view { flex: 1; background: linear-gradient(135deg,#10b981,#059669); color: #fff; border: none; border-radius: 12px; padding: 12px; font-size: 14.5px; font-weight: 800; cursor: pointer; }
.hg-done-view:hover { opacity: .93; }

/* create-event venue filter bar + onboard */
.hg-vbar { display: flex; align-items: center; gap: 8px; margin-bottom: 14px; flex-wrap: wrap; }
.hg-vsearch { display: flex; align-items: center; gap: 8px; background: var(--surface, #ffffff); border: 1.5px solid var(--border, #e2e8f0); border-radius: 10px; padding: 8px 13px; color: var(--text-light, #94a3b8); flex: 1; min-width: 170px; max-width: 320px; }
.hg-vsearch input { flex: 1; min-width: 0; border: none; outline: none; font-size: 14px; color: var(--text, #0f172a); background: transparent; }
.hg-vchips { display: flex; flex-wrap: wrap; gap: 6px; }
.hg-vchips button { border: 1.5px solid var(--border, #e2e8f0); background: var(--surface, #ffffff); color: var(--text-secondary, #475569); font-weight: 700; font-size: 13px; padding: 6px 12px; border-radius: 999px; cursor: pointer; }
.hg-vchips button:hover { border-color: var(--brand-accent); }
.hg-vchips button.on { background: var(--brand); border-color: var(--brand); color: #fff; }
.hg-onboard { display: inline-flex; align-items: center; gap: 7px; margin-top: 16px; background: var(--canvas, #f8fafc); border: 1.5px dashed var(--border-strong, #cbd5e1); border-radius: 12px; padding: 12px 16px; font-size: 14px; color: var(--text-secondary, #475569); cursor: pointer; }
.hg-onboard b { color: var(--brand-ink, var(--brand)); }
.hg-onboard:hover { border-color: var(--brand-accent); background: color-mix(in srgb, var(--brand) 8%, var(--surface, white)); }
.hg-onboard-form { text-align: left; }
.hg-onboard-foot { display: flex; gap: 10px; margin-top: 16px; }
.hg-onboard-cancel { flex: 1; background: var(--bg, #f1f5f9); color: var(--text-secondary, #334155); font-weight: 800; font-size: 14px; padding: 11px; border: none; border-radius: 12px; cursor: pointer; }
.hg-onboard-send { flex: 1; background: linear-gradient(135deg,#10b981,#059669); color: #fff; font-weight: 800; font-size: 14px; padding: 11px; border: none; border-radius: 12px; cursor: pointer; }
.hg-onboard-send:disabled { opacity: .6; cursor: default; }

/* ────────────────────────────────────────────────────────────────────────
   MOBILE (≤760px) — dock the wizard CTA flush to the viewport bottom
   (/host-game is a flow route: the floating bottom nav is hidden and the
   shell reserves calc(92px + safe-area) clearance on .cust-main), 16px
   inputs (no iOS zoom), bigger tap targets, and de-emphasised secondary
   buttons. Presentation-only; no markup/logic changes. Desktop is
   untouched (all rules scoped here). */
@media (max-width: 760px) {
  /* [1] Action bar: fixed full-bleed dock at the true viewport bottom.
     No page-local scroll clearance needed — the shell's flow-route
     padding on .cust-main already clears the bar. */
  .hg-actions {
    position: fixed;
    left: 0; right: 0; bottom: 0;
    z-index: 90;
    margin: 0;
    gap: 8px;
    padding: 10px 14px calc(10px + env(safe-area-inset-bottom, 0px));
    background: var(--surface, #ffffff);
    border-top: 1px solid var(--border, #e2e8f0);
    border-radius: 0;
    box-shadow: 0 -8px 24px rgba(15,23,42,.10);
  }
  /* [P3-1] 360px: the primary button shrinks/wraps instead of pushing the row
     past the viewport edge (the Pay label is data-driven and unbounded). */
  .hg-next, .hg-submit {
    flex: 1 1 auto; min-width: 0; justify-content: center;
    padding: 14px 14px; font-size: 15px; white-space: normal;
  }

  /* [2] Text-entry controls at 16px so iOS doesn't zoom the viewport on focus.
     Covers native fields, the prize amount, and the PhoneInput inner input. */
  .hg-in, .hg-ta, .hg-prize-amt input { font-size: 16px; }
  .hg :deep(.pin-num) { font-size: 16px; }

  /* [4] Tighten the per-step header — smaller title, less chrome above the form. */
  .cback { margin-bottom: 10px; }
  .hg-stephead { margin-bottom: 12px; }
  .hg-steptitle { font-size: 20px; margin: 6px 0 3px; }
  .hg-stepsub {
    font-size: 12.5px;
    display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 2; overflow: hidden;
  }

  /* [6] Paired fields stack full-width (matches .hg-twocol/.hg-final collapse). */
  .hg-row { grid-template-columns: 1fr; }

  /* [3] Trim vertical rhythm on the last step so the CTA is closer. */
  .hg-card { padding: 14px 14px; }
  .hg-l { margin: 8px 0 4px; }
  .hg-note, .hg-hint { line-height: 1.4; }
  .hg-review { padding: 12px; margin-top: 12px; }
  /* [P3-3] Review rows: long values (titles, When ranges, prize breakdowns)
     wrap onto their own line instead of crushing against the label. */
  .hg-review-row { padding: 3px 0; flex-wrap: wrap; gap: 2px 10px; }
  .hg-review-row b { text-align: right; overflow-wrap: anywhere; margin-left: auto; }
  .hg-pay { padding: 12px; margin-top: 12px; }
  .hg-pay-row { padding: 3px 0; }
  .hg-pay-warn { padding: 9px 11px 9px 31px; margin-top: 8px; }
  /* Optional sign-up questions read as clearly secondary (less top emphasis). */
  .hg-signup { margin-top: 12px; padding-top: 12px; }

  /* [7] Type chooser: denser cards so all three + "How it works" fit sooner. */
  .hg h1 { font-size: 22px; }
  .hg-sub { margin: 6px 0 14px; }
  .hg-choice { padding: 16px; }
  .hg-choice-ico { width: 44px; height: 44px; border-radius: 12px; margin-bottom: 10px; }
  .hg-choice-d { font-size: 13px; line-height: 1.45; }
  .hg-how { margin-top: 22px; }

  /* [5] Tournament bracket: swap the side-scrolling round columns for a round-by-round
     tabbed view (Google-knockout style) — tap a round to bring its games forward. */
  .hg-bracket { display: none; }
  .hg-mb { display: block; }
  .hg-mb-tabs { display: flex; gap: 8px; overflow-x: auto; padding: 2px 2px 12px; -webkit-overflow-scrolling: touch; scrollbar-width: none; }
  .hg-mb-tabs::-webkit-scrollbar { display: none; }
  .hg-mb-tab {
    flex: 0 0 auto; display: inline-flex; align-items: center; gap: 7px; white-space: nowrap;
    border: 1.5px solid var(--border, #e2e8f0); background: var(--surface, #ffffff); color: var(--text-muted, #64748b);
    font: inherit; font-weight: 800; font-size: 12.5px; padding: 8px 13px; border-radius: 999px; cursor: pointer;
  }
  .hg-mb-tab.on { background: #e11d48; border-color: #e11d48; color: #fff; }
  .hg-mb-tab-c { display: inline-flex; align-items: center; justify-content: center; min-width: 18px; height: 18px; padding: 0 5px; border-radius: 999px; font-size: 11px; font-weight: 800; background: var(--danger-bg, #fee2e2); color: var(--danger-ink, #be123c); }
  .hg-mb-tab.on .hg-mb-tab-c { background: rgba(255,255,255,.25); color: #fff; }
  .hg-mb-tab.done:not(.on) { border-color: var(--success-border, #86efac); color: var(--success-ink, #15803d); }
  .hg-mb-tab.done:not(.on) .hg-mb-tab-c { background: var(--success-bg, #dcfce7); color: var(--success-ink, #15803d); }
  .hg-mb-area { touch-action: pan-y; }
  .hg-mb-col { display: flex; flex-direction: column; gap: 12px; }
  /* Subtle slide/fade as the active round changes; direction follows the tap/swipe. */
  .hgm-enter-active, .hgm-leave-active,
  .hgm-back-enter-active, .hgm-back-leave-active { transition: transform .18s ease, opacity .18s ease; }
  .hgm-enter-from { opacity: 0; transform: translateX(16px); }
  .hgm-leave-to { opacity: 0; transform: translateX(-16px); }
  .hgm-back-enter-from { opacity: 0; transform: translateX(-16px); }
  .hgm-back-leave-to { opacity: 0; transform: translateX(16px); }

  /* Tournament bracket cards: bigger tap targets; empty state = ≥44px pill. */
  .hg-mcard { padding: 14px; min-height: 64px; }
  .hg-mcard-empty {
    font-size: 13px; padding: 8px 10px; margin-top: 10px;
    justify-content: center; border-radius: 10px;
    background: var(--danger-bg, #fff1f2); border: 1px dashed #fecdd3; min-height: 40px;
  }

  /* [9] Prize rows: let the place label shrink and the amount take the width;
     allow wrap so nothing overflows sideways at 375px. */
  .hg-prize-row { flex-wrap: wrap; }
  .hg-prize-place { width: auto; }

  /* [8] De-emphasise secondary buttons so the primary clearly dominates. */
  .hg-back {
    background: none; border-color: transparent; color: var(--text-muted, #64748b);
    font-weight: 700; padding: 12px 10px;
    flex-shrink: 0;
  }
  .hg-wv-cancel { background: none; color: var(--text-muted, #64748b); padding: 12px 14px; }
  .hg-wv-cancel:hover { background: var(--bg, #f1f5f9); }

  /* [10] Waiver + published modals become bottom sheets (app convention). */
  .hg-wv-back, .hg-done-back { align-items: flex-end; padding: 0; }
  .hg-wv, .hg-done {
    max-width: 100%; width: 100%;
    border-radius: 18px 18px 0 0; max-height: 90vh; overflow-y: auto;
  }

  /* [11] Published-share buttons stay comfortable ≥44px tap targets. */
  .hg-done-share, .hg-done-view { min-height: 44px; padding: 13px; }
  .hg-done-copy { flex-shrink: 0; min-height: 40px; }

  /* ── Pass 2: first-glance clarity ─────────────────────────────────────── */

  /* [P2-1] Auto-schedule banner: stack vertically instead of squeezing the
     long title + description beside a fixed-width button; clamp the blurb. */
  .hg-auto { flex-direction: column; align-items: stretch; gap: 10px; }
  .hg-auto-btn { width: 100%; justify-content: center; }
  .hg-auto-d {
    display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 2; overflow: hidden;
  }

  /* [P2-2] Reserve weight 900 for the page h1 + step title only; everything
     else drops to 800 so one heading clearly leads each screen. Money totals
     stay the biggest numbers in their cards, just not shouting. */
  .hg-choice-t, .hg-auto-t, .hg-preview-h, .hg-round-name,
  .hg-mcard-n, .hg-team, .hg-done-t { font-weight: 800; }
  .hg-prize-total b, .hg-pay-total b { font-size: 17px; font-weight: 800; }

  /* [P2-3] Nothing below the 11px floor; calm the twice-repeated red
     "non-refundable" chip (the amber .hg-pay-warn keeps the full rule). */
  .hg-rec, .hg-pay-nr, .hg-img-cover, .hg-slot-x { font-size: 11px; }
  .hg-pay-nr { text-transform: none; letter-spacing: 0; }

  /* [P2-4] Flatten card-inside-card: tinted fills alone carry the grouping.
     (Amber .hg-prize-box keeps its border — it sits on white.) */
  .hg-prize-toggle { border: none; border-radius: 0; padding: 10px 0; border-top: 1px solid var(--border, #eef2f6); }
  .hg-review { border-color: transparent; }
  .hg-pay { border-width: 1px; }

  /* [P2-5] Type chooser: clamp descriptions + "How it works" sentences to two
     lines. .hg-rules (conduct/safety copy) intentionally untouched. */
  .hg-choice-d, .hg-how-step > div > span {
    display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 2; overflow: hidden;
  }

  /* [P2-8] Photo thumbnails: 4/3 like covers elsewhere, not cropped squares. */
  .hg-img { width: 104px; height: auto; aspect-ratio: 4/3; }

  /* [P2-9] Drop-zone hint talks about drag & drop on touch and repeats the
     button label + Cover badge — hide it on phones. */
  .hg-drop-hint { display: none; }
}
.hg-resume { display: flex; align-items: center; gap: 8px; background: color-mix(in srgb, var(--brand, #0d9488), transparent 92%); border: 1px solid color-mix(in srgb, var(--brand, #0d9488), transparent 75%); color: var(--text, #0f172a); font-size: 13px; font-weight: 600; border-radius: 12px; padding: 9px 12px; margin-bottom: 12px; }
.hg-resume-btn { margin-inline-start: auto; border: none; background: none; color: var(--brand, #0d9488); font-weight: 800; font-size: 13px; cursor: pointer; text-decoration: underline; }
.hg-resume-x { border: none; background: none; color: var(--text-muted, #64748b); cursor: pointer; display: inline-flex; padding: 2px; }
.hg-sport-wrap { position: relative; }
.hg-sport-dd { position: absolute; top: 100%; left: 0; right: 0; z-index: 30; margin-top: 4px; background: var(--surface, #ffffff); border: 1px solid var(--border, #e2e8f0); border-radius: 12px; box-shadow: 0 14px 36px rgba(15,23,42,.16); max-height: 232px; overflow-y: auto; padding: 5px; }
.hg-sport-opt { display: block; width: 100%; text-align: start; border: none; background: none; font: inherit; font-size: 14px; font-weight: 600; color: var(--text, #0f172a); padding: 9px 11px; border-radius: 8px; cursor: pointer; }
.hg-sport-opt:hover { background: color-mix(in srgb, var(--brand, #0d9488), transparent 92%); }
.hg-sport-opt.on { color: var(--brand, #0d9488); background: color-mix(in srgb, var(--brand, #0d9488), transparent 90%); }
.hg-draft { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; background: color-mix(in srgb, var(--brand, #0d9488), transparent 92%); border: 1.5px solid color-mix(in srgb, var(--brand, #0d9488), transparent 70%); border-radius: 14px; padding: 13px 15px; margin: 14px 0 18px; }
.hg-draft-txt { flex: 1; min-width: 200px; display: flex; flex-direction: column; gap: 2px; }
.hg-draft-txt b { font-size: 14px; color: var(--text, #0f172a); }
.hg-draft-txt small { font-size: 12px; color: var(--text-muted, #64748b); }
.hg-draft-btns { display: flex; gap: 8px; }
.hg-draft-go { border: none; background: var(--brand, #0d9488); color: #fff; font-weight: 800; font-size: 13px; padding: 9px 15px; border-radius: 10px; cursor: pointer; }
.hg-draft-x { border: 1.5px solid var(--border, #e2e8f0); background: var(--surface, #ffffff); color: var(--text-muted, #64748b); font-weight: 700; font-size: 13px; padding: 9px 13px; border-radius: 10px; cursor: pointer; }
.hg-postas { border: 1.5px solid var(--border, #e2e8f0); background: var(--surface, #ffffff); border-radius: 14px; padding: 13px 15px; margin: 14px 0 18px; display: flex; flex-direction: column; gap: 9px; }
.hg-postas-l { font-size: 11.5px; font-weight: 800; text-transform: uppercase; letter-spacing: .05em; color: var(--text-light, #94a3b8); }
.hg-postas-chips { display: flex; gap: 8px; flex-wrap: wrap; }
.hg-pa { border: 1.5px solid var(--border, #e2e8f0); background: var(--surface, #ffffff); color: var(--text-secondary, #475569); font-weight: 700; font-size: 13px; padding: 8px 14px; border-radius: 999px; cursor: pointer; }
.hg-pa.on { border-color: var(--brand, #0d9488); background: color-mix(in srgb, var(--brand, #0d9488), transparent 90%); color: var(--brand, #0d9488); }
.hg-postas-hint { font-size: 12px; color: var(--text-muted, #64748b); }
.hg-wl { grid-column: 1 / -1; display: flex; align-items: center; justify-content: space-between; gap: 12px; border: 1px dashed var(--border, #e2e8f0); border-radius: 12px; padding: 11px 13px; }

/* A guided journey, with optional details out of the main decision path. */
.hg { max-width: 860px; padding-bottom: 24px; }.hg.hg-choosing,.hg.hg-wide,.hg.hg-reviewing { max-width: 1160px; }.hg > h1 { font-size: clamp(28px,3.5vw,40px); line-height: 1.2; letter-spacing: -.03em; max-width: 24ch; margin-top: 24px; }.hg-sub { max-width: 64ch; line-height: 1.7; font-size: 15px; margin-bottom: 30px; }
.hg-choose { grid-template-columns: repeat(2,minmax(0,1fr)); gap: 22px; margin-top: 24px; }.hg-choice { display: flex; flex-direction: column; align-items: stretch; justify-content: flex-start; padding: 28px; border-radius: 16px; background: var(--surface); border: 1px solid var(--border); text-align: start; }.hg-choice:hover { border-color: var(--brand-ink); transform: none; }.hg-choice-ico { width: 54px; height: 54px; background: var(--brand-tint,var(--bg)); color: var(--brand-ink); border-radius: 14px; margin-bottom: 22px; }.hg-choice-ico.event { background: var(--warning-bg); color: var(--warning-ink); }.hg-choice-t { font-size: 24px; margin-bottom: 10px; }.hg-choice-d { font-size: 14px; line-height: 1.7; display: block; -webkit-line-clamp: unset; color: var(--text-secondary); }.hg-choice-go { display: flex; align-items: center; justify-content: space-between; margin-top: 28px; padding-top: 18px; border-top: 1px solid var(--border); color: var(--brand-ink); font-size: 14px; font-weight: 700; }
.hg-how { margin-top: 34px; padding-top: 24px; border-top: 1px solid var(--border); }.hg-how-h { text-align: start; font-size: 18px; text-transform: none; letter-spacing: 0; color: var(--text); }.hg-how-steps { gap: 26px; }.hg-how-step b { font-size: 14px; }.hg-how-step > div > span { font-size: 13px; line-height: 1.7; display: block; -webkit-line-clamp: unset; }.hg-rules { margin-top: 26px; font-size: 12px; }
.hg-steps { margin: 18px 0 28px; gap: 10px; padding: 12px 0 20px; border-bottom: 1px solid var(--border); }.hg-step { border: 0; background: none; font: inherit; text-align: start; padding: 4px 0; color: var(--text-muted); cursor: pointer; }.hg-step:disabled { cursor: default; opacity: 1; }.hg-step.on { color: var(--brand-ink); }.hg-step-t { font-size: 13px; }.hg-stepcount { display: block; color: var(--text-muted); font-size: 12px; margin: 12px 0 8px; }.hg-steptitle { scroll-margin-top: 160px; font-size: 29px; letter-spacing: -.025em; }.hg-card { padding: 28px; border: 1px solid var(--border); border-radius: 14px; box-shadow: none; }.hg-l { font-size: 14px; font-weight: 650; margin-top: 22px; }.hg-l:first-child { margin-top: 0; }.hg-in { padding: 12px 14px; min-height: 46px; font-size: 16px; border-radius: 9px; background: var(--bg); color: var(--text); }.hg-row { gap: 20px; }.hg-note { line-height: 1.7; font-size: 13px; }
.hg-disclosure { border-block-start: 1px solid var(--border); margin-top: 24px; padding-top: 18px; }.hg-disclosure summary { cursor: pointer; font-size: 15px; font-weight: 650; color: var(--text); padding: 6px 0; }.hg-disclosure summary > span { font-weight: 400; color: var(--text-muted); font-size: 12px; margin-left: 8px; }.hg-disclosure[open] summary { margin-bottom: 18px; }.hg-final { display: block; }.hg-final.review .hg-final-side { display: grid; grid-template-columns: minmax(0,1fr) minmax(0,1fr); align-items: start; gap: 24px; }.hg-review-edit { display: inline-flex; align-items: center; gap: 6px; border: 0; background: none; color: var(--brand-ink); font-size: 12px; cursor: pointer; padding: 6px; margin-inline-start: auto; }.hg-review-h { display: flex; justify-content: space-between; align-items: center; gap: 10px; }.hg-review-contact { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; font-size: 13px; padding: 14px 0; border-bottom: 1px solid var(--border); }.hg-review-contact span { color: var(--text-muted); }.hg-review-title { font-size: 22px; margin: 16px 0 8px; overflow-wrap: anywhere; }.hg-review-sport { font-size: 13px; color: var(--brand-ink); margin-bottom: 16px; }.hg-final.review .hg-final-side > .hg-pay { margin-top: 0; }
.hg-actions { position: sticky; bottom: 12px; z-index: 20; border: 1px solid var(--border); background: var(--surface); border-radius: 12px; padding: 14px; margin-top: 20px; box-shadow: 0 6px 22px #0000000d; }.hg-back,.hg-next,.hg-submit { min-height: 46px; border-radius: 9px; }.hg-next,.hg-submit { background: var(--brand); }.hg :is(button,a,input,textarea,select,summary):focus-visible { outline: 2px solid var(--brand-ink); outline-offset: 3px; }
@media(max-width:760px) { .hg > h1 { font-size: 29px; }.hg-choose { grid-template-columns: 1fr; gap: 14px; }.hg-choice { display: block; padding: 22px; }.hg-choice-ico { width: 44px; height: 44px; margin-bottom: 16px; }.hg-choice-t { font-size: 22px; }.hg-choice-d { display: block; font-size: 14px; -webkit-line-clamp: unset; }.hg-choice-go { margin-top: 18px; padding-top: 14px; }.hg-how-steps { grid-template-columns: 1fr; gap: 18px; }.hg-how-step > div > span { display: block; -webkit-line-clamp: unset; }.hg-card { padding: 20px 16px; }.hg-steptitle { font-size: 25px; scroll-margin-top: 90px; }.hg-step-t { display: none; }.hg-step.on .hg-step-t { display: inline; font-size: 12px; }.hg-steps { gap: 8px; }.hg-step-bar { min-width: 8px; }.hg-final.review .hg-final-side { grid-template-columns: 1fr; }.hg-actions { bottom: 12px; padding: 10px; }.hg-actions button { font-size: 14px; }.hg-row { gap: 14px; }.hg-reviewing .hg-card { border: 0; padding: 0; background: none; }.hg-sub { display: block; } }
@media(prefers-reduced-motion:reduce) { .hg * { transition: none !important; animation: none !important; } }
</style>

<style scoped>
.hg-media { border-top:1px solid var(--border); margin-top:24px; padding-top:24px; }
.hg-media h2 { margin:0; font-size:18px; font-weight:700; color:var(--text); }
.hg-media-help { margin:8px 0 20px; color:var(--text-muted); line-height:1.6; font-size:14px; }
.hg-required { font-size:11px; font-weight:500; color:var(--text-muted); margin-inline-start:5px; }
.hg-field-error { margin:8px 0 0; color:var(--danger-ink, #b91c1c); font-size:13px; line-height:1.5; }
.hg-in[aria-invalid="true"] { border-color:var(--danger-ink, #b91c1c); background:color-mix(in srgb, var(--danger-ink, #b91c1c) 5%, var(--bg)); }.hg-in[aria-invalid="true"]:focus { outline-color:var(--danger-ink, #b91c1c); box-shadow:0 0 0 3px color-mix(in srgb, var(--danger-ink, #b91c1c) 18%, transparent); }
.hg-drop-btn { min-height:44px; }
@media(max-width:600px) { .hg-media .hg-twocol { grid-template-columns:minmax(0,1fr); } }
</style>
