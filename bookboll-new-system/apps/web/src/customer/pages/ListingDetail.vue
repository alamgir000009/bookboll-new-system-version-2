<script setup>
import { ref, onMounted, onBeforeUnmount, watch, computed } from 'vue'
import { useRoute, useRouter, onBeforeRouteLeave } from 'vue-router'
import { selectedCountry, customer, isCustomer } from '../../lib/customerSession.js'
import { ensureSignedIn } from '../../lib/authGate.js'
import { setCheckoutSession, clearCheckoutSession, checkoutSession } from '../../customer/bookingSession.js'
import { useSeo, productLd } from '../../lib/seo.js'
import { facilityPath } from '../../lib/cityPages.js'
import { hostDraft } from '../../customer/hostDraft.js'
import { loadListing } from '../../customer/data.js'
import { quoteBooking, isPerPerson, extensionsEnabled, extensionSlotPrice, extensionMaxSlots, addonAmount, paymentPlan, paymentSplit, effectivePaymentOptions } from '../../customer/booking.js'
import { loadDayAvailability, buildDaySlots, dayScheduleInfo, createCustomerBookingsMulti, holdSlots, holdMinutes, releaseHolds, holdToken, loadMonthAvailability, cheapestAvailableForDay, PAY_MAP } from '../../customer/apiBooking.js'
import { validatePromo } from '../../lib/promocodes.js'
import { loadLoyaltyConfig } from '../../lib/loyalty.js'
import { loadCustomerLoyalty, clearCustomerLoyaltyCache } from '../../lib/customerSession.js'
import { isWhiteLabel } from '../../lib/brand.js'
import { getPrefillPhone, saveCustomerPhone } from '../../customer/customerPhones.js'
import { fmt12, dayPeriod, toMinutes, endMinutes } from '../../lib/slots.js'
import DateField from '../components/DateField.vue'
import PhoneInput from '../components/PhoneInput.vue'
import { ArrowLeft, ArrowRight, MapPin, Users, Clock, Building2, Check, Plus, Minus, Navigation, CalendarCheck, Calendar, ShieldCheck, User, Mail, Phone, Loader, Sparkles, X, Trophy, MousePointerClick, Tag, Info, Expand, Sun, Moon } from 'lucide-vue-next'
import Lightbox from '../components/Lightbox.vue'

const route = useRoute()
const router = useRouter()
const data = ref(null)
const loading = ref(true)

// Listing photo gallery (first = cover). activePhoto drives the hero; thumbnails switch it.
const activePhoto = ref(0)
const gallery = computed(() => data.value?.photos?.length ? data.value.photos : (data.value?.photo ? [data.value.photo] : []))
const heroPhoto = computed(() => gallery.value[activePhoto.value] || gallery.value[0] || '')
const lightbox = ref(false)   // full-screen photo viewer

// "Select mode": opened from the game host wizard. The host picks a date + time here
// (reusing the normal booking selection), then "Use this for my game" stashes the
// court + date + slots and returns to the wizard's Contact step.
const selectMode = computed(() => ['game', 'tournament', 'event'].includes(route.query.host))

// Two-phase MOBILE flow: the page opens as a calm details screen (photos, tags,
// description, venue, "from" price) with one Book now button; tapping it swaps to a
// clean slot picker with the descriptive chrome hidden. Desktop is unaffected — the
// ld-intro/ld-booking classes only do anything inside the phone media block.
// Phone-only two-phase flow: the details screen shows a docked "Book now" bar that
// flips the page into the picker. On desktop the picker is always visible alongside,
// so tapping a slot IS the action — no separate CTA button needed.
const bookingOpen = ref(false)
const selectKind = computed(() => route.query.host === 'tournament' ? 'match' : route.query.host === 'event' ? 'event' : 'game')
// Host select mode (game / hangout / tournament match) reuses the NORMAL multi-slot
// picker — the event/match length is whatever slots the host books (no fixed block).
async function useForGame() {
  const sel = (selectedByDate.value[date.value] || []).slice().sort((a, b) => String(a.start_time).localeCompare(String(b.start_time)))
  if (!sel.length) return
  // Reserve the picked slots for this device (the same 10-min hold a normal checkout uses)
  // so nobody grabs them while the host finishes setting up, and pass the deadline back so
  // the wizard can show a countdown. Best-effort: degrades to no-hold if holds aren't live.
  let holdExpiresAt = 0
  try {
    const held = await holdSlots(l.value, date.value, sel.map(s => ({ start_time: s.start_time, end_time: s.end_time })))
    if (held && held.ok === false) { err.value = 'That time was just taken — please pick another slot.'; loadDay(); return }
    if (held?.expiresAt) holdExpiresAt = new Date(held.expiresAt).getTime()
    else if (held?.ok) holdExpiresAt = Date.now() + (await holdMinutes()) * 60000   // degraded → still show a soft timer
  } catch { /* holds unavailable — proceed without a reservation */ }
  hostDraft.selectedCourtId = route.params.id
  hostDraft.gameDate = date.value
  hostDraft.gameSlots = sel.map(s => ({ start_time: s.start_time, end_time: s.end_time, price: Number(s.price) || 0 }))
  hostDraft.pickHoldExpiresAt = holdExpiresAt
  hostDraft.active = true
  router.push('/host-game')
}
function cancelSelect() { router.push('/host-game') }

function ymd(d) { const z = n => String(n).padStart(2, '0'); return `${d.getFullYear()}-${z(d.getMonth() + 1)}-${z(d.getDate())}` }
const date = ref(ymd(new Date()))
let suppressDateWatch = false   // set during load() so setting date.value doesn't double-fetch
const slots = ref([])
const loadingSlots = ref(false)
// Special-day / weekly-schedule info for the shown date — drives the
// "Closed — <name>" and "Special hours" notices above the slot grid.
const daySched = ref(null)
const selectedByDate = ref({})     // { 'YYYY-MM-DD': [{start_time,end_time,price}] }
const players = ref(1)
const addonQty = ref({})
const saving = ref(false)
const err = ref('')
const success = ref(null)
const guest = ref({ name: '', email: '', phone: '' })
const phone = ref('')   // checkout phone (per-country; prefilled + saved). Used for guest & signed-in.
const step = ref(1)   // 1 = choose date & time, 2 = checkout

// ── Slot hold countdown ("reserved for 9:58…") ───────────────────────────────
const holdExpiresAt = ref(0)     // epoch ms; 0 = no active hold
const holdRemaining = ref(0)     // seconds left
const holdActive = ref(false)    // true once a real (non-degraded) hold is in place
const holdExpiredMsg = ref(false)
let holdTimer = null
const holdMMSS = computed(() => {
  const s = Math.max(0, holdRemaining.value)
  return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`
})
function stopHoldTimer() { if (holdTimer) { clearInterval(holdTimer); holdTimer = null } }
function startHoldTimer(expEpoch) {
  holdExpiresAt.value = expEpoch
  holdActive.value = true
  stopHoldTimer()
  const tick = () => {
    holdRemaining.value = Math.max(0, Math.round((holdExpiresAt.value - Date.now()) / 1000))
    if (holdRemaining.value <= 0) onHoldExpired()
  }
  tick()
  holdTimer = setInterval(tick, 1000)
}
async function onHoldExpired() {
  stopHoldTimer()
  holdActive.value = false
  holdExpiresAt.value = 0
  await releaseHolds(l.value)
  clearCheckoutSession()
  selectedByDate.value = {}
  step.value = 1
  holdExpiredMsg.value = true
  loadDay()
}
async function dropHolds() {
  stopHoldTimer()
  holdActive.value = false
  holdExpiresAt.value = 0
  await releaseHolds(l.value)
}
// Reserve every currently-selected slot (across all dates) the moment selection changes,
// so the customer's picks are locked in while they keep choosing — and the countdown runs
// live from the first pick. Returns { ok }; ok:false means a slot was just taken.
let syncing = false
async function syncHolds() {
  if (selectMode.value) return { ok: true }   // host-game picker doesn't reserve
  const dates = Object.keys(selectedByDate.value).filter(d => (selectedByDate.value[d] || []).length)
  if (!dates.length) { await dropHolds(); clearCheckoutSession(); return { ok: true } }
  syncing = true
  let earliest = null, bad = null
  for (const d of dates) {
    const held = await holdSlots(l.value, d, selectedByDate.value[d])
    if (!held.ok) { bad = { date: d, start_time: held.start_time }; break }
    if (held.expiresAt) { const t = new Date(held.expiresAt).getTime(); earliest = earliest ? Math.min(earliest, t) : t }
  }
  syncing = false
  if (bad) return { ok: false, ...bad }
  if (earliest) { startHoldTimer(earliest); writeCheckoutSession(earliest) }   // real holds → live countdown + resumable session (degraded → neither)
  return { ok: true }
}

// A short "when" label for the floating resume card (first day + time, "+N days" if multiple).
function sessionWhenLabel() {
  const pd = fullQuote.value.perDay
  if (!pd.length) return ''
  const d0 = pd[0], b0 = d0.blocks[0]
  let lbl = `${fmtDate(d0.date)}${b0 ? ', ' + fmtTime(b0.start) : ''}`
  const extra = fullQuote.value.dates.length - 1
  if (extra > 0) lbl += ` +${extra} day${extra > 1 ? 's' : ''}`
  return lbl
}
// Persist the in-progress checkout so the hold survives navigation/refresh (resume card).
function writeCheckoutSession(expEpoch) {
  setCheckoutSession({
    listingId: route.params.id,
    listingName: l.value.name || 'Venue',
    locationName: loc.value?.name || '',
    currency: cur.value,
    total: fullQuote.value.total,
    slotCount: fullQuote.value.totalSlots,
    whenLabel: sessionWhenLabel(),
    selectionByDate: JSON.parse(JSON.stringify(selectedByDate.value)),
    expiresAt: expEpoch,
    holdToken: holdToken(),
  })
}

// Live grid: when anyone else holds/releases/books a slot on this listing, refresh the
// day the customer is viewing so taken times grey out in real time. No-op if Realtime
// isn't enabled on the project (subscription just never fires).
let reloadDebounce = null
function setupRealtime(_listingId) {
  teardownRealtime()
}
function teardownRealtime() {
  clearTimeout(reloadDebounce)
}

const l = computed(() => data.value?.listing || {})
const loc = computed(() => data.value?.location || null)
const cur = computed(() => loc.value?.currency_override || selectedCountry.value?.currency_code || 'AED')
const slotMins = computed(() => l.value.slot_duration || 60)
const perPerson = computed(() => isPerPerson(l.value))
const maxPlayers = computed(() => Math.max(1, l.value.capacity || 1))
const canExtend = computed(() => extensionsEnabled(l.value))

const selected = computed(() => selectedByDate.value[date.value] || [])           // current day
const selectedSet = computed(() => new Set(selected.value.map(s => s.start_time)))
const dayQuote = computed(() => quoteBooking(l.value, selected.value, [], players.value))   // current day blocks

// discounted price per slot on the CURRENT day: extension slots within a selected
// run, plus the next free slot after EACH run (the per-block offer).
const slotPriceMap = computed(() => {
  const m = {}
  if (!canExtend.value) return m
  const maxExt = extensionMaxSlots(l.value)
  for (const b of dayQuote.value.blocks) {
    // index 0 = base (full); 1..maxExt = discounted; beyond that back to full price.
    b.slots.forEach((s, i) => {
      const disc = i >= 1 && i <= maxExt
      m[s.start_time] = disc ? { price: extensionSlotPrice(l.value, s.price), disc: true } : { price: Number(s.price), disc: false }
    })
    // The next free slot is only a discounted extension while the run is still under the cap.
    if (b.slots.length <= maxExt) {
      const next = slots.value.find(x => x.start_time === b.end && x.available && !selectedSet.value.has(x.start_time))
      if (next) { const ext = extensionSlotPrice(l.value, Number(next.price)); m[next.start_time] = { price: ext, disc: ext < Number(next.price) } }
    }
  }
  return m
})
function priceFor(s) { return slotPriceMap.value[s.start_time]?.price ?? s.price }
function isDisc(s) { return !!slotPriceMap.value[s.start_time]?.disc }

// Hide times that have already passed today — they're never bookable and just
// clutter the grid (booked/full FUTURE slots are still shown so the user can see
// what's taken and pick an adjacent time).
const visibleSlots = computed(() => slots.value.filter(s => !s.past))

// Price heat-map for the day: green = cheapest tier, amber = mid, red = priciest.
// Ranked over the day's DISTINCT prices so all three colours show even when prices
// cluster. Discounted (extension) slots are always green (best value).
const dayPrices = computed(() =>
  [...new Set(slots.value.filter(s => s.available).map(s => Number(s.price) || 0))].sort((a, b) => a - b)
)
function tierClass(s) {
  if (isDisc(s)) return 'tier-low'
  const ps = dayPrices.value
  if (ps.length < 2) return ''
  const frac = ps.indexOf(Number(s.price) || 0) / (ps.length - 1)   // 0 cheapest → 1 priciest
  return frac <= 0.34 ? 'tier-low' : frac <= 0.67 ? 'tier-mid' : 'tier-high'
}

// One offer per contiguous run on the current day (handles gaps → multiple offers).
const offers = computed(() => {
  if (!canExtend.value) return []
  const maxExt = extensionMaxSlots(l.value)
  const out = []
  for (const b of dayQuote.value.blocks) {
    if (b.slots.length > maxExt) continue   // this run already hit its discount cap
    const next = slots.value.find(x => x.start_time === b.end && x.available && !selectedSet.value.has(x.start_time))
    if (next) { const full = Number(next.price), ext = extensionSlotPrice(l.value, full); out.push({ slot: { start_time: next.start_time, end_time: next.end_time, price: next.price }, fullPrice: full, extPrice: ext, savings: Math.max(0, full - ext), savePct: full > 0 ? Math.round((1 - ext / full) * 100) : 0 }) }
  }
  return out
})

const selectedAddons = computed(() => (data.value?.addons || []).map(a => ({ ...a, qty: addonQty.value[a.id] || 0 })).filter(a => a.qty > 0))

// Aggregate across ALL selected days (the real checkout).
const fullQuote = computed(() => {
  const dates = Object.keys(selectedByDate.value).filter(d => (selectedByDate.value[d] || []).length).sort()
  let slotsTotal = 0, savings = 0, totalSlots = 0
  const perDay = dates.map(d => {
    const q = quoteBooking(l.value, selectedByDate.value[d], [], players.value)
    slotsTotal += q.slotsTotal; savings += q.savings; totalSlots += q.totalSlots
    return { date: d, blocks: q.blocks }
  })
  const totalHours = (totalSlots * (Number(l.value.slot_duration) || 60)) / 60
  const pricedAddons = selectedAddons.value.map(a => ({ ...a, amount: addonAmount(a, a.qty, totalHours) }))
  const addonsTotal = pricedAddons.reduce((n, a) => n + a.amount, 0)
  return { dates, perDay, slotsTotal, savings, totalSlots, addonsTotal, pricedAddons, total: slotsTotal + addonsTotal }
})

// A restored checkout session or a bot deep-link arrives with slots already selected —
// skip the details intro and land straight in the picker.
watch(() => fullQuote.value.totalSlots, (n) => { if (n > 0) bookingOpen.value = true }, { immediate: true })

// ── Promo code ────────────────────────────────────────────────────────────────
const promoInput = ref('')
const promo = ref(null)        // { code(row), discount } once applied
const promoError = ref('')
const promoBusy = ref(false)
const promoDiscount = computed(() => Math.min(Number(promo.value?.discount || 0), fullQuote.value.total))
const payableTotal = computed(() => Math.max(0, fullQuote.value.total - promoDiscount.value))
async function applyPromo() {
  promoError.value = ''
  promoBusy.value = true
  // Preview only — the server re-validates and applies the discount at booking time.
  const res = await validatePromo(promoInput.value, {
    amount: fullQuote.value.total,
    locationId: loc.value?.id || null,
    listingId: l.value?.id || route.params.id,
    paymentMethod: PAY_MAP[payMethod.value],
  })
  promoBusy.value = false
  if (!res.ok) { promo.value = null; promoError.value = res.reason; return }
  promo.value = { code: res.code, discount: res.discount }
}
function removePromo() { promo.value = null; promoInput.value = ''; promoError.value = '' }

// Payment: bookboll-owned → online; provider → deposit (+ cash if admin-enabled).
// Surface decides WHICH options apply: the provider's own white-label site uses their
// customizable `site_*` options (full online gated on a connected payout method); the
// Bookboll marketplace uses the standard options.
const surface = computed(() => (isWhiteLabel.value ? 'site' : 'marketplace'))
const providerHasPayout = computed(() => !!data.value?.providerHasPayout)
const payMethod = ref('deposit')
const plan = computed(() => paymentPlan(loc.value, effectivePaymentOptions(data.value?.paymentOptions, { surface: surface.value, providerHasPayout: providerHasPayout.value })))
const paySplit = computed(() => paymentSplit(payMethod.value, payableTotal.value, plan.value.depositPct, plan.value.depositAmount))
watch(plan, (p) => { if (!p.methods.includes(payMethod.value)) payMethod.value = p.methods[0] }, { immediate: true })
const PAY_LABEL = { deposit: 'Deposit + on arrival', cash: 'Pay on arrival', online: 'Pay online' }
const PAY_NOTE = { deposit: 'Deposit secures your booking; pay the balance at the venue.', cash: 'No prepayment — pay when you arrive.', online: 'Pay securely online.' }
const PAY_DESC = {
  deposit: 'Pay a small deposit now to lock in your booking — the deposit is non-refundable. The rest is paid in person at the venue when you arrive.',
  online: 'Pay the full amount now. There is nothing left to pay at the venue.',
  cash: 'Pay nothing now — pay the full amount in person when you arrive.',
}

// ── Loyalty points (Bookboll marketplace only) ───────────────────────────────
// Points are EARNED server-side: Laravel credits listings.loyalty_points on booking
// confirmation. Spending them at checkout is not offered here — the API has no
// redeem parameter, so a client-side discount would be shown but never charged.
// Config + balance are still loaded for display and for the future redeem flow.
const loyaltyCfg = ref(null)
const loyaltyBalance = ref(0)         // customer's points balance for this country
// What's actually charged online today.
const chargeNow = computed(() => paySplit.value.online)

// "4 slots · 4 hrs" style detail for the order summary.
const hoursLabel = computed(() => {
  const h = (fullQuote.value.totalSlots * (Number(l.value.slot_duration) || 60)) / 60
  if (!h) return ''
  return `${Number.isInteger(h) ? h : h.toFixed(1)} hr${h === 1 ? '' : 's'}`
})
// Confirm button states the amount actually charged today.
const confirmLabel = computed(() => {
  if (saving.value) return 'Booking…'
  if (paySplit.value.method === 'cash') return 'Confirm booking'
  return `Pay ${cur.value} ${Number(chargeNow.value).toLocaleString()} & confirm`
})

const dateLabel = computed(() => new Date(date.value + 'T00:00:00').toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric' }))
function fmtDate(d) { return new Date(d + 'T00:00:00').toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' }) }
function fmtTime(t) { return fmt12(t) }

// Calendar "From {price}" labels = cheapest AVAILABLE slot per day (so booked-out cheap
// slots don't mislead). Loaded per viewed month, on demand, and cached.
const monthPrices = ref({})          // { 'YYYY-MM-DD': cheapest available price | null }
const loadedMonths = new Set()       // 'y-m' already fetched
let lastMonthView = new Date()
function priceForDate(ds) { const v = monthPrices.value[ds]; return v === undefined ? null : v }
async function ensureMonthPrices(viewDate, force = false) {
  if (!data.value || selectMode.value) return
  lastMonthView = viewDate
  const y = viewDate.getFullYear(), m = viewDate.getMonth()
  const key = `${y}-${m}`
  if (loadedMonths.has(key) && !force) return
  loadedMonths.add(key)
  const z = n => String(n).padStart(2, '0')
  const days = new Date(y, m + 1, 0).getDate()
  const from = `${y}-${z(m + 1)}-01`, to = `${y}-${z(m + 1)}-${z(days)}`
  const month = await loadMonthAvailability(l.value, from, to)
  const next = { ...monthPrices.value }
  for (let d = 1; d <= days; d++) { const ds = `${y}-${z(m + 1)}-${z(d)}`; next[ds] = cheapestAvailableForDay(l.value, ds, month, holdToken()) }
  monthPrices.value = next
}
function onCalMonth(viewDate) { ensureMonthPrices(viewDate) }

async function load() {
  loading.value = true
  data.value = await loadListing(route.params.id)
  activePhoto.value = 0
  loading.value = false
  if (isCustomer.value) guest.value = { name: customer.value.full_name || '', email: customer.value.email || '', phone: '' }
  phone.value = getPrefillPhone(selectedCountry.value?.id)   // this country's number, else the account's
  if (!selectMode.value) setupRealtime(data.value?.listing?.id)   // live grid updates
  // All date.value mutations below are followed by a single loadDay() at the end — mute the
  // date watcher so we don't fire two or three overlapping availability fetches (and a
  // loading flash) on one page open.
  suppressDateWatch = true
  const sess = checkoutSession.value
  const active = sess && Number(sess.expiresAt) > Date.now()
  // Resume an in-progress checkout for THIS listing (held slots survive navigation/refresh).
  const resumed = !selectMode.value && active && sess.listingId === route.params.id
  if (resumed) {
    selectedByDate.value = JSON.parse(JSON.stringify(sess.selectionByDate || {}))
    const firstDate = Object.keys(selectedByDate.value).sort()[0]
    if (firstDate) date.value = firstDate              // show the day their slots are on
    startHoldTimer(Number(sess.expiresAt))             // resume the live countdown
    syncHolds()                                        // re-validate + refresh expiry on the server
  }
  // Deep link from the WhatsApp booking bot (…?d=YYYY-MM-DD&t=HH:MM): jump to that day and
  // pre-select the slot. Skipped for host-select mode, when resuming THIS listing, and — most
  // importantly — when a live checkout exists on a DIFFERENT listing (auto-holding here would
  // release that cart's slots and overwrite the session).
  const preT = (!selectMode.value && !resumed && !(active && sess.listingId !== route.params.id))
    ? prefillTarget() : null
  if (preT) date.value = preT.d
  await loadDay()                                      // the single availability fetch for the resolved day
  suppressDateWatch = false
  if (preT) {
    const slot = (slots.value || []).find(s => String(s.start_time).slice(0, 5) === preT.t && s.available)
    if (slot) await toggleSlot(slot)                   // selects + places the hold
  }
  ensureMonthPrices(new Date(date.value + 'T00:00:00'))   // prefetch the current month's "from" prices
}
// Parse + validate the bot deep-link query into a { d, t } target, or null.
function prefillTarget() {
  const qd = String(route.query.d || ''), qt = String(route.query.t || '')
  if (!/^\d{4}-\d{2}-\d{2}$/.test(qd) || !qt) return null
  const dt = new Date(qd + 'T00:00:00')
  if (isNaN(dt.getTime()) || ymd(dt) !== qd) return null      // reject impossible dates (e.g. 2026-99-99)
  // Allow ~1 day of slack: the bot reckons "today" in the venue timezone, so a link opened on a
  // device ahead of that tz near midnight must still land instead of being silently dropped.
  const y = new Date(); y.setDate(y.getDate() - 1)
  if (qd < ymd(y)) return null
  return { d: qd, t: qt.slice(0, 5) }
}
// When scheduling a TOURNAMENT match, the other matches in the same draft aren't real
// bookings yet — so buildDaySlots (DB-only) can't know they've claimed a slot on this
// court/date. Grey those windows out here so the host can't put two games on one court
// at the same time. Returns the slots unchanged for the game/hangout flow (single pick).
function excludeSiblingDraftSlots(built) {
  if (route.query.host !== 'tournament' || !Array.isArray(hostDraft.games)) return built
  const myId = route.params.id, d = date.value
  const taken = hostDraft.games.filter(g =>
    g && g.slot && g.listing_id === myId && g.date === d &&
    !(g.round === hostDraft.matchRound && g.index === hostDraft.matchIndex)
  )
  if (!taken.length) return built
  return built.map(s => {
    const ss = toMinutes(s.start_time), se = endMinutes(s.end_time)
    // How many sibling matches already use a slot overlapping this one. A court can host
    // up to its capacity per slot, so only block once the siblings fill the remaining space.
    const used = taken.filter(g => ss < endMinutes(g.slot.end_time) && toMinutes(g.slot.start_time) < se).length
    if (!used) return s
    const spacesLeft = Math.max(0, (s.spacesLeft || 0) - used)
    const available = spacesLeft > 0 && !s.past
    return { ...s, spacesLeft, available, booked: !available && !s.past, siblingTaken: !available }
  })
}
async function loadDay() {
  if (!data.value) return
  loadingSlots.value = true
  const avail = await loadDayAvailability(l.value, date.value)
  daySched.value = dayScheduleInfo(l.value, date.value, avail)
  slots.value = excludeSiblingDraftSlots(buildDaySlots(l.value, date.value, avail, holdToken()))
  loadingSlots.value = false
}
onMounted(load)
// Switching between two listings REUSES this component (param-only route change), so clear
// the previous listing's selection + local hold UI before load() restores any session for the new one.
watch(() => route.params.id, () => {
  selectedByDate.value = {}; step.value = 1
  stopHoldTimer(); holdActive.value = false; holdExpiresAt.value = 0; holdExpiredMsg.value = false
  load()
})
watch(date, (nd, od) => {
  // Host select mode (game/hangout/tournament match) books a SINGLE day — switching the
  // date drops any prior day's picks so a booking can't span days. Normal booking keeps
  // its multi-day cart.
  if (selectMode.value && od && nd !== od) selectedByDate.value = {}
  if (suppressDateWatch) return   // load() sets the date + loads the grid itself; don't double-fetch
  loadDay()
})

useSeo(() => {
  if (!data.value) return { path: route.path, noindex: !loading.value }
  const venue = loc.value?.name
  const price = Number(data.value.startPrice) || 0
  return {
    title: `${l.value.name}${venue ? ' · ' + venue : ''}`,
    description: `Book ${l.value.name}${venue ? ' at ' + venue : ''}${price ? ' from ' + cur.value + ' ' + price.toLocaleString() : ''} by the hour on Bookboll.`,
    image: heroPhoto.value,
    path: route.path,
    type: 'product',
    jsonLd: productLd({
      name: `${l.value.name}${venue ? ' — ' + venue : ''}`, description: l.value.description,
      image: heroPhoto.value, price: price || undefined, currency: cur.value,
      url: `https://bookboll.com${route.path}`,
    }),
  }
})

async function goCheckout() {
  err.value = ''
  holdExpiredMsg.value = false
  if (!fullQuote.value.dates.length) { err.value = 'Please pick at least one time slot.'; return }
  if (selectMode.value) { step.value = 2; return }   // host-game flow doesn't book here
  // Mandatory: must have a Bookboll account before checking out.
  if (!isCustomer.value && !(await ensureSignedIn())) return
  if (isCustomer.value) guest.value = { name: customer.value.full_name || '', email: customer.value.email || '', phone: '' }
  // Signing in happens right above, so this is the first moment the account's
  // number is known. Only fill a field the shopper has left empty.
  if (!phone.value.trim()) phone.value = getPrefillPhone(selectedCountry.value?.id)
  // Slots are already held from selection; refresh/re-validate before showing checkout.
  saving.value = true
  const res = await syncHolds()
  saving.value = false
  if (!res.ok) { err.value = 'One of your times was just taken — please pick another slot.'; loadDay(); return }
  step.value = 2
  loadLoyalty()
  if (typeof window !== 'undefined') window.scrollTo({ top: 0, behavior: 'smooth' })
}
// Loyalty points are a Bookboll-marketplace perk only — skip on provider white-label sites.
async function loadLoyalty() {
  loyaltyCfg.value = null; loyaltyBalance.value = 0
  if (isWhiteLabel.value || !isCustomer.value) return
  const countryId = selectedCountry.value?.id || null
  const [cfg, bal] = await Promise.all([
    loadLoyaltyConfig(countryId),
    loadCustomerLoyalty(),
  ])
  loyaltyCfg.value = cfg
  loyaltyBalance.value = Number(bal?.points || 0)
}
// Going back to selection keeps the slots held (countdown continues) so the customer
// doesn't lose them — they're only released on expiry, an empty selection, or leaving.
function backToSelection() { step.value = 1 }

function setDay(day, arr) { selectedByDate.value = { ...selectedByDate.value, [day]: arr } }
// Day/night glyph per slot — 7:00 vs 19:00 at a glance (AM/PM trips people up).
const periodIcon = (t) => (dayPeriod(t) === 'day' ? Sun : Moon)

async function toggleSlot(s) {
  if (!s.available) return
  err.value = ''; holdExpiredMsg.value = false
  const arr = selected.value
  const i = arr.findIndex(x => x.start_time === s.start_time)
  if (i >= 0) {
    setDay(date.value, arr.filter((_, idx) => idx !== i))   // deselect → reconcile releases it
    await syncHolds()
  } else {
    setDay(date.value, [...arr, { start_time: s.start_time, end_time: s.end_time, price: s.price }])
    const res = await syncHolds()   // reserve it immediately so no one else can grab it
    if (!res.ok) {                  // someone just took it between render and tap
      setDay(date.value, selected.value.filter(x => x.start_time !== s.start_time))
      err.value = 'That time was just taken — please choose another.'
      loadDay()
    }
  }
}
async function addExtension(o) {
  setDay(date.value, [...selected.value, { start_time: o.slot.start_time, end_time: o.slot.end_time, price: o.slot.price }])
  const res = await syncHolds()
  if (!res.ok) { setDay(date.value, selected.value.filter(x => x.start_time !== o.slot.start_time)); err.value = 'That time was just taken — please choose another.'; loadDay() }
}
function clearDay(day) { const c = { ...selectedByDate.value }; delete c[day]; selectedByDate.value = c; syncHolds() }
function removeBlock(day, block) {
  const starts = new Set((block.slots || []).map(s => s.start_time))
  const rest = (selectedByDate.value[day] || []).filter(s => !starts.has(s.start_time))
  if (rest.length) setDay(day, rest); else clearDay(day)
  syncHolds()
  if (!fullQuote.value.dates.length) step.value = 1   // nothing left to check out → back to selection
}
function removeAddon(a) { addonQty.value = { ...addonQty.value, [a.id]: 0 } }
function stepAddon(a, d) {
  const next = Math.max(0, Math.min(a.stock != null && a.stock > 0 ? a.stock : 99, (addonQty.value[a.id] || 0) + d))
  addonQty.value = { ...addonQty.value, [a.id]: next }
}
function openMaps() { if (data.value?.mapsUrl) window.open(data.value.mapsUrl, '_blank', 'noopener') }

async function confirm() {
  err.value = ''
  if (!fullQuote.value.dates.length) { err.value = 'Please pick at least one time slot.'; return }
  saving.value = true
  // A Bookboll account is mandatory: openCheckout() gates step 2 behind
  // ensureSignedIn(), so reaching here signed out means the session expired
  // mid-checkout. The booking API needs a Sanctum token, so stop and re-gate
  // rather than trying to create an identity here.
  if (!isCustomer.value) {
    saving.value = false
    if (!(await ensureSignedIn())) { err.value = 'Please sign in to complete your booking.'; return }
    saving.value = true
  }
  const base = customer.value
  if (!base) { err.value = 'Please sign in to complete your booking.'; saving.value = false; return }
  let cust = base
  if (phone.value.trim()) cust = { ...cust, phone: phone.value.trim() }   // book with the entered number
  const appliedPromo = promo.value   // capture before the form resets
  const discount = promoDiscount.value
  const snap = { perDay: fullQuote.value.perDay, gross: fullQuote.value.total, discount, total: payableTotal.value, method: payMethod.value, depositPct: plan.value.depositPct, depositAmount: plan.value.depositAmount }
  // The promo CODE (not the client-side discount) goes to the server — Laravel
  // re-validates it and applies the discount itself, so the charged amount always
  // matches what it computed, and usage_count is incremented there.
  const res = await createCustomerBookingsMulti({ listing: l.value, location: loc.value, selectionByDate: selectedByDate.value, addons: selectedAddons.value, players: players.value, customer: cust, payment: { method: payMethod.value, depositPct: plan.value.depositPct, depositAmount: plan.value.depositAmount, promocode: appliedPromo?.code?.code || null }, surface: surface.value, providerHasPayout: providerHasPayout.value })
  saving.value = false
  if (res.error) { err.value = res.error; loadDay(); return }   // e.g. "just taken" → refresh grid
  if (res.redirect) return
  stopHoldTimer(); holdActive.value = false; holdExpiresAt.value = 0   // booked → holds released server-side
  clearCheckoutSession()   // checkout complete → dismiss the resume card
  saveCustomerPhone(selectedCountry.value?.id, phone.value)   // remember for this country
  // Points are credited by Laravel on confirmation (listings.loyalty_points) — nothing
  // to award from here. Refresh the cached balance so Rewards reflects the booking.
  clearCustomerLoyaltyCache()
  success.value = { ...snap, currency: res.currency }
  promo.value = null; promoInput.value = ''
  selectedByDate.value = {}
}
function bookAnother() { success.value = null; addonQty.value = {}; players.value = 1; step.value = 1; loadDay() }

// Leaving the page no longer releases the hold — the resumable checkout session (bookingSession.js)
// keeps it alive across navigation/refresh and owns its release (resume card Close, expiry, or
// successful booking). We only tear down this page's local timer + realtime subscription here.
onBeforeUnmount(() => { stopHoldTimer(); teardownRealtime() })
onBeforeRouteLeave(() => { stopHoldTimer(); teardownRealtime() })
</script>

<template>
  <div>
    <button v-if="selectMode" type="button" class="cback cback-btn" @click="cancelSelect"><ArrowLeft :size="16" /> Back to venues</button>
    <router-link v-else :to="loc ? facilityPath(loc.id, loc.name) : '/facilities'" class="cback"><ArrowLeft :size="16" /> {{ loc ? loc.name : 'All venues' }}</router-link>
    <div v-if="loading" class="cmsg">Loading…</div>
    <div v-else-if="!data" class="cmsg">Venue not found.</div>

    <div v-else class="ld" :class="{ 'ld-checkout': step === 2 && !selectMode,
      'ld-intro': step === 1 && !selectMode && !bookingOpen,
      'ld-booking': step === 1 && (selectMode || bookingOpen) }">
      <div class="ld-hero" :class="{ 'has-photo': heroPhoto }" :style="heroPhoto ? { backgroundImage: `url(${heroPhoto})` } : {}"
        :role="heroPhoto ? 'button' : undefined" :aria-label="heroPhoto ? 'View photos full screen' : undefined"
        @click="heroPhoto && (lightbox = true)">
        <div v-if="!heroPhoto" class="ld-hero-fallback"><Building2 :size="52" /></div>
        <span v-if="heroPhoto" class="ld-hero-expand"><Expand :size="15" /></span>
      </div>
      <!-- Thumbnail strip (only when there are multiple photos) -->
      <div v-if="gallery.length > 1" class="ld-thumbs">
        <button v-for="(p, i) in gallery" :key="i" type="button" class="ld-thumb"
          :class="{ on: i === activePhoto }" :style="{ backgroundImage: `url(${p})` }"
          @click="activePhoto = i" :aria-label="`Photo ${i + 1}`"></button>
      </div>

      <!-- SUCCESS — full width, replaces the flow -->
      <div v-if="success" class="ld-done">
        <div class="ld-success">
          <div class="ld-success-ico"><CalendarCheck :size="34" /></div>
          <h3>You're booked!</h3>
          <template v-for="day in success.perDay" :key="day.date">
            <div class="ld-success-day">{{ fmtDate(day.date) }}</div>
            <div v-for="(b, i) in day.blocks" :key="i" class="ld-success-line"><Clock :size="14" /> {{ fmtTime(b.start) }} – {{ fmtTime(b.end) }}</div>
          </template>
          <div class="ld-success-total">
            <template v-if="success.method === 'deposit'">Deposit <strong>{{ success.currency }} {{ Number(paymentSplit('deposit', success.total, success.depositPct, success.depositAmount).online).toLocaleString() }}</strong> paid <span class="ld-plan-nr">non-refundable</span> · <strong>{{ success.currency }} {{ Number(paymentSplit('deposit', success.total, success.depositPct, success.depositAmount).arrival).toLocaleString() }}</strong> at the venue</template>
            <template v-else-if="success.method === 'online'">Paid online <strong>{{ success.currency }} {{ Number(success.total).toLocaleString() }}</strong></template>
            <template v-else>Pay <strong>{{ success.currency }} {{ Number(success.total).toLocaleString() }}</strong> at the venue</template>
          </div>
          <div class="ld-sim-note">A receipt has been emailed to you.</div>
          <div class="ld-done-actions">
            <router-link to="/my-bookings" class="ld-book-btn">My bookings</router-link>
            <button type="button" class="ld-map-btn" @click="bookAnother">Book more</button>
          </div>
        </div>
      </div>

      <template v-else>
        <!-- Host select-mode banner -->
        <div v-if="selectMode" class="ld-selbar">
          <div class="ld-selbar-msg"><Trophy :size="16" /> Hosting your {{ selectKind }} — pick a date &amp; time for this venue</div>
          <button type="button" class="ld-selbar-cancel" @click="cancelSelect"><ArrowLeft :size="15" /> Back to venues</button>
        </div>

        <!-- Step indicator (normal booking only) -->
        <div v-if="!selectMode" class="ld-steps">
          <button type="button" class="ld-step" :class="{ on: step === 1, done: step > 1 }" @click="backToSelection()">
            <span class="ld-step-n"><Check v-if="step > 1" :size="14" /><template v-else>1</template></span>
            <span class="ld-step-t">Choose date &amp; time</span>
          </button>
          <span class="ld-step-bar" :class="{ done: step > 1 }"></span>
          <div class="ld-step" :class="{ on: step === 2 }">
            <span class="ld-step-n">2</span>
            <span class="ld-step-t">Checkout</span>
          </div>
        </div>

        <!-- A hold just expired while the customer was idle -->
        <div v-if="!selectMode && step === 1 && holdExpiredMsg" class="ld-hold-expired">
          <Clock :size="15" /> Your held time slots were released. Please pick your times again.
        </div>

        <!-- Live hold countdown is now shown by the global floating <CheckoutResumeCard>
             (compact timer on this page; full resume card elsewhere). -->

        <!-- ===== STEP 1 — choose date & time (also the host's select-mode screen) ===== -->
        <div v-if="selectMode || step === 1" class="ld-grid"
          :class="{ 'ld-grid-hasbar': (!selectMode && fullQuote.dates.length) || (selectMode && selected.length) }">
          <div class="ld-main">
            <h1>{{ l.name }}</h1>
            <div class="ld-tags">
              <span v-if="l.sport_category" class="ld-tag">{{ l.sport_category }}</span>
              <span class="ld-fact"><Users :size="15" /> up to {{ l.capacity || 1 }} players</span>
              <span class="ld-fact"><Clock :size="15" /> {{ slotMins }} min slots</span>
            </div>
            <p v-if="l.description" class="ld-desc">{{ l.description }}</p>

            <button v-if="!selectMode && bookingOpen" type="button" class="ld-backdetails" @click="bookingOpen = false">
              <ArrowLeft :size="14" /> Venue details
            </button>
            <h2 class="ld-h2">Choose a date &amp; time</h2>
            <DateField v-model="date" :price-for="priceForDate" :currency="cur" @month="onCalMonth" />

            <div class="ld-time-label">
              {{ selectMode ? `Pick the time(s) to book for your ${selectKind}` : 'Tap one or more times' }} <span class="ld-h2-sub">· {{ dateLabel }}</span>
              <span v-if="canExtend && !selectMode" class="ld-ext-hint"><Sparkles :size="13" /> adjacent times are discounted</span>
            </div>
            <div v-if="selectMode" class="ld-select-hint"><Info :size="13" /> Pick one or more times on this day — your {{ selectKind }}'s length is set by what you book (same day only).</div>
            <div v-if="loadingSlots" class="ld-slots-msg"><Loader :size="16" class="ld-spin" /> Loading times…</div>
            <div v-else-if="daySched?.closed" class="ld-slots-msg ld-closed-msg">
              Closed on this date<template v-if="daySched.name && daySched.name !== 'Closed'"> — {{ daySched.name }}</template>. Pick another day above.
            </div>
            <div v-else-if="!visibleSlots.length" class="ld-slots-msg">No open times left for this date — pick another day above.</div>
            <!-- Normal booking AND host select (game/hangout/tournament match): free multi-slot picking -->
            <div v-else-if="daySched?.special" class="ld-special-note">
              <Info :size="13" /> Special hours on this date<template v-if="daySched.name && daySched.name !== 'Special schedule'"> — {{ daySched.name }}</template>
            </div>
            <div v-if="!loadingSlots && !daySched?.closed && visibleSlots.length" class="ld-slots">
              <button v-for="s in visibleSlots" :key="s.start_time" class="ld-slot"
                :class="[{ on: selectedSet.has(s.start_time), taken: !s.available }, s.available ? tierClass(s) : '']"
                :disabled="!s.available" @click="toggleSlot(s)">
                <span class="ld-slot-time"><component :is="periodIcon(s.start_time)" :size="12" class="ld-slot-sun" :class="`p-${dayPeriod(s.start_time)}`" />{{ fmtTime(s.start_time) }}</span>
                <span class="ld-slot-endt">– {{ fmtTime(s.end_time) }}</span>
                <template v-if="s.available">
                  <span class="ld-slot-price" :class="{ disc: !selectMode && isDisc(s) }">{{ cur }} {{ Number(selectMode ? s.price : priceFor(s)).toLocaleString() }}<s v-if="!selectMode && isDisc(s)" class="ld-slot-was">{{ Number(s.price).toLocaleString() }}</s></span>
                  <span v-if="(!selectMode && isDisc(s)) || s.capacity > 1" class="ld-slot-left">{{ !selectMode && isDisc(s) ? '↓ extension' : s.spacesLeft + ' left' }}</span>
                </template>
                <span v-else class="ld-slot-x">{{ s.siblingTaken ? 'In this event' : (s.past ? 'Passed' : (s.capacity > 1 ? 'Full' : 'Booked')) }}</span>
              </button>
            </div>

            <!-- Booking extras (offers / players / add-ons) — not shown when a host is just picking a court -->
            <template v-if="!selectMode">
            <!-- Extension offers (one per contiguous run) -->
            <button v-for="(o, i) in offers" :key="'o'+i" type="button" class="ld-offer" @click="addExtension(o)">
              <div class="ld-offer-ico"><Sparkles :size="20" /></div>
              <div class="ld-offer-txt">
                <div class="ld-offer-title">Make it longer<span v-if="o.savePct > 0"> &amp; save {{ o.savePct }}%</span></div>
                <div class="ld-offer-sub">Add {{ fmtTime(o.slot.start_time) }}–{{ fmtTime(o.slot.end_time) }} for <b>{{ cur }} {{ Number(o.extPrice).toLocaleString() }}</b><s v-if="o.savings > 0"> {{ cur }} {{ Number(o.fullPrice).toLocaleString() }}</s></div>
              </div>
              <span class="ld-offer-add"><Plus :size="16" /> Add</span>
            </button>

            <template v-if="perPerson && maxPlayers > 1">
              <h2 class="ld-h2">How many players?</h2>
              <div class="ld-stepper">
                <button @click="players = Math.max(1, players - 1)" :disabled="players <= 1"><Minus :size="16" /></button>
                <span>{{ players }} <span class="ld-step-note">· priced per player (max {{ maxPlayers }})</span></span>
                <button @click="players = Math.min(maxPlayers, players + 1)" :disabled="players >= maxPlayers"><Plus :size="16" /></button>
              </div>
            </template>

            <template v-for="g in (data.addons.length ? [{kind:'product',items:data.addons.filter(a=>a.kind==='product')},{kind:'service',items:data.addons.filter(a=>a.kind==='service')},{kind:'rental',items:data.addons.filter(a=>a.kind==='rental')}].filter(x=>x.items.length) : [])" :key="g.kind">
              <h2 class="ld-h2">{{ {product:'Products',service:'Services',rental:'Rentals'}[g.kind] }}</h2>
              <div class="ld-addons">
                <div v-for="a in g.items" :key="a.id" class="ld-addon">
                  <div class="ld-addon-info">
                    <div class="ld-addon-name">{{ a.name }}</div>
                    <div class="ld-addon-price">{{ cur }} {{ Number(a.price || 0).toLocaleString() }} <span class="ld-addon-unit">each</span><span v-if="a.description"> · {{ a.description }}</span></div>
                  </div>
                  <div class="ld-stepper sm">
                    <button @click="stepAddon(a, -1)" :disabled="!(addonQty[a.id] > 0)"><Minus :size="14" /></button>
                    <span>{{ addonQty[a.id] || 0 }}</span>
                    <button @click="stepAddon(a, 1)"><Plus :size="14" /></button>
                  </div>
                </div>
              </div>
            </template>
            </template>

            <!-- Mobile intro: one calm CTA — details above, slot picker on tap -->
            <div v-if="!selectMode && !bookingOpen" class="ld-booknow">
              <div class="ld-bn-price"><span>from</span> {{ cur }} {{ Number(data.startPrice || 0).toLocaleString() }} <em>/ {{ slotMins }} mins</em></div>
              <button type="button" class="ld-book-btn ld-bn-btn" @click="bookingOpen = true">Book now <ArrowRight :size="17" /></button>
            </div>
          </div>

          <!-- sidebar: host select (pick & use) OR normal running cart + continue -->
          <aside class="ld-side">
            <!-- Venue card (moved above the booking / game action) -->
            <div v-if="loc" class="ld-sum-h">Where</div>
            <button v-if="loc" type="button" class="ld-venue ld-side-venue" @click="openMaps" :title="data.mapsUrl ? 'Open in Google Maps' : ''">
              <div class="ld-venue-pin"><MapPin :size="18" /></div>
              <div class="ld-venue-info">
                <div class="ld-venue-name">{{ loc.name }}</div>
                <div v-if="loc.address" class="ld-venue-addr">{{ loc.address }}</div>
              </div>
              <Navigation v-if="data.mapsUrl" :size="16" class="ld-venue-go" />
            </button>

            <template v-if="selectMode">
              <template v-if="selected.length">
                <div class="ld-sum-h">Your {{ selectKind }} time</div>
                <div class="ld-side-sel">
                  <div class="ld-sel-day">
                    <div class="ld-sel-row"><Calendar :size="14" /> {{ fmtDate(date) }}</div>
                    <div v-for="(b, i) in dayQuote.blocks" :key="i" class="ld-sel-block">
                      <span><Clock :size="13" /> {{ fmtTime(b.start) }}–{{ fmtTime(b.end) }}<span v-if="b.slots.length > 1" class="ld-sel-ext"> ({{ b.slots.length }} slots)</span></span>
                      <span class="ld-sel-bprice">{{ cur }} {{ Number(b.total).toLocaleString() }}</span>
                    </div>
                  </div>
                </div>
                <div class="ld-side-action ld-side-action-sm"><button type="button" class="ld-book-btn" @click="useForGame">Use this venue &amp; time <ArrowRight :size="17" /></button></div>
              </template>
              <div v-else class="ld-side-hint"><MousePointerClick :size="15" /> Pick a slot above to continue</div>
            </template>

            <template v-else>
            <div v-if="!fullQuote.dates.length" class="ld-side-price">
              <span class="ld-sp-from">from</span> {{ cur }} {{ Number(data.startPrice || 0).toLocaleString() }}
              <span class="ld-sp-per">/ {{ slotMins }} mins</span>
            </div>

            <div v-if="fullQuote.dates.length" class="ld-side-sel">
              <div v-for="day in fullQuote.perDay" :key="day.date" class="ld-sel-day">
                <div class="ld-sel-row"><Calendar :size="14" /> {{ fmtDate(day.date) }}<button type="button" class="ld-sel-clear" @click="clearDay(day.date)"><X :size="13" /></button></div>
                <div v-for="(b, i) in day.blocks" :key="i" class="ld-sel-block">
                  <span><Clock :size="13" /> {{ fmtTime(b.start) }}–{{ fmtTime(b.end) }}<span v-if="b.slots.length > 1" class="ld-sel-ext"> ({{ b.slots.length }} slots)</span></span>
                  <span class="ld-sel-bprice">{{ cur }} {{ Number(b.total).toLocaleString() }}</span>
                </div>
              </div>
            </div>
            <div v-else class="ld-side-hint"><MousePointerClick :size="15" /> Tap one or more times to book (across multiple days too)</div>

            <div v-if="err" class="ld-err">{{ err }}</div>
            <div v-if="fullQuote.dates.length" class="ld-side-action">
            <div class="ld-breakdown">
              <div v-if="holdActive" class="ld-bar-timer" :class="{ urgent: holdRemaining <= 180 }"><Clock :size="13" /> {{ holdMMSS }} held</div>
              <div class="ld-bk-row"><span>{{ fullQuote.totalSlots }} slot{{ fullQuote.totalSlots > 1 ? 's' : '' }}<span v-if="fullQuote.dates.length > 1"> · {{ fullQuote.dates.length }} days</span></span><span>{{ cur }} {{ Number(fullQuote.slotsTotal).toLocaleString() }}</span></div>
              <div v-if="fullQuote.savings > 0" class="ld-bk-row ld-bk-save"><span>Extension savings</span><span>− {{ cur }} {{ Number(fullQuote.savings).toLocaleString() }}</span></div>
              <div v-if="fullQuote.addonsTotal > 0" class="ld-bk-row"><span>Add-ons</span><span>{{ cur }} {{ Number(fullQuote.addonsTotal).toLocaleString() }}</span></div>
              <div class="ld-bk-row ld-bk-total"><span>Total</span><strong>{{ cur }} {{ Number(fullQuote.total).toLocaleString() }}</strong></div>
            </div>

            <button type="button" class="ld-book-btn" @click="goCheckout">Continue to checkout <ArrowRight :size="17" /></button>
            </div>
            </template>
          </aside>
        </div>

        <!-- ===== STEP 2 — checkout ===== -->
        <div v-else class="ld-grid">
          <div class="ld-main">
            <button type="button" class="cback ld-back2" @click="backToSelection()"><ArrowLeft :size="16" /> Edit selection</button>

            <div class="ld-co-head">
              <h1>{{ l.name }}</h1>
              <div v-if="loc" class="ld-co-venue"><MapPin :size="14" /> {{ loc.name }}</div>
            </div>

            <h2 class="ld-h2">Order summary</h2>
            <div class="ld-cart">
              <div v-for="day in fullQuote.perDay" :key="day.date" class="ld-cart-day">
                <div class="ld-cart-dayhead"><Calendar :size="15" /> {{ fmtDate(day.date) }}</div>
                <div v-for="b in day.blocks" :key="b.start" class="ld-cart-row">
                  <span class="ld-cart-l"><Clock :size="14" /> {{ fmtTime(b.start) }}–{{ fmtTime(b.end) }}<span v-if="b.slots.length > 1" class="ld-sel-ext"> · {{ b.slots.length }} slots</span></span>
                  <span class="ld-cart-r">
                    <span class="ld-cart-p">{{ cur }} {{ Number(b.total).toLocaleString() }}</span>
                    <button type="button" class="ld-cart-x" @click="removeBlock(day.date, b)" title="Remove this time"><X :size="15" /></button>
                  </span>
                </div>
              </div>
              <div v-for="a in fullQuote.pricedAddons" :key="a.id" class="ld-cart-row ld-cart-addon">
                <span class="ld-cart-l"><Plus :size="13" /> {{ a.qty }} × {{ a.name }}</span>
                <span class="ld-cart-r">
                  <span class="ld-cart-p">{{ cur }} {{ Number(a.amount).toLocaleString() }}</span>
                  <button type="button" class="ld-cart-x" @click="removeAddon(a)" title="Remove add-on"><X :size="14" /></button>
                </span>
              </div>
            </div>

            <h2 class="ld-h2">Your details</h2>
            <div v-if="isCustomer" class="ld-who">
              <div class="ld-who-ava">{{ (customer.full_name || customer.email || '?').slice(0,1).toUpperCase() }}</div>
              <div class="ld-who-info">
                <div class="ld-who-name">{{ customer.full_name || 'Signed in' }}</div>
                <div class="ld-who-mail">{{ customer.email }}</div>
              </div>
            </div>
            <PhoneInput v-model="phone" :default-iso="selectedCountry?.iso_code" placeholder="Phone number" class="ld-phone" />

            <h2 class="ld-h2">Payment method</h2>
            <div class="ld-pay">
              <div v-if="plan.methods.length > 1" class="ld-pay-methods wrap">
                <button v-for="m in plan.methods" :key="m" type="button" class="ld-pay-opt" :class="{ on: payMethod === m }" @click="payMethod = m">{{ PAY_LABEL[m] }}</button>
              </div>
              <div class="ld-pay-desc"><ShieldCheck :size="16" /> <span>{{ PAY_DESC[payMethod] }}</span></div>
            </div>
          </div>

          <!-- sidebar: order total + payment plan + confirm -->
          <aside class="ld-side">
            <div class="ld-sum-h">Order total</div>
            <div class="ld-breakdown">
              <div class="ld-bk-row"><span>{{ fullQuote.totalSlots }} slot{{ fullQuote.totalSlots > 1 ? 's' : '' }}<span v-if="hoursLabel"> · {{ hoursLabel }}</span><span v-if="fullQuote.dates.length > 1"> · {{ fullQuote.dates.length }} days</span></span><span>{{ cur }} {{ Number(fullQuote.slotsTotal).toLocaleString() }}</span></div>
              <div v-if="fullQuote.savings > 0" class="ld-bk-row ld-bk-save"><span>Extension savings</span><span>− {{ cur }} {{ Number(fullQuote.savings).toLocaleString() }}</span></div>
              <div v-if="fullQuote.addonsTotal > 0" class="ld-bk-row"><span>Add-ons</span><span>{{ cur }} {{ Number(fullQuote.addonsTotal).toLocaleString() }}</span></div>
              <div v-if="promoDiscount > 0" class="ld-bk-row ld-bk-save"><span>Promo {{ promo.code.code }}</span><span>− {{ cur }} {{ Number(promoDiscount).toLocaleString() }}</span></div>
              <div class="ld-bk-row ld-bk-total"><span>Total</span><strong>{{ cur }} {{ Number(payableTotal).toLocaleString() }}</strong></div>
            </div>

            <!-- Promo code -->
            <div class="ld-promo">
              <div v-if="promoDiscount > 0" class="ld-promo-on">
                <span class="ld-promo-tag"><Tag :size="13" /> {{ promo.code.code }} applied</span>
                <button type="button" class="ld-promo-x" @click="removePromo">Remove</button>
              </div>
              <div v-else class="ld-promo-form">
                <input v-model="promoInput" type="text" class="ld-promo-input" placeholder="Promo code"
                  @keyup.enter="applyPromo" :disabled="promoBusy" />
                <button type="button" class="ld-promo-btn" :disabled="promoBusy || !promoInput.trim()" @click="applyPromo">{{ promoBusy ? '…' : 'Apply' }}</button>
              </div>
              <div v-if="promoError" class="ld-promo-err">{{ promoError }}</div>
            </div>

            <!-- Spending loyalty points at checkout is disabled until the API supports
                 it — showing a discount the server won't apply would undercharge. -->

            <div class="ld-sum-h">Payment plan</div>
            <div class="ld-plan">
              <template v-if="paySplit.method === 'deposit'">
                <div class="ld-plan-row now">
                  <div class="ld-plan-info">
                    <div class="ld-plan-lbl">Pay now <span class="ld-plan-tag">deposit · {{ plan.depositPct }}%</span> <span class="ld-plan-nr">non-refundable</span></div>
                    <div class="ld-plan-sub">Charged today to secure your booking · non-refundable</div>
                  </div>
                  <strong class="ld-plan-amt">{{ cur }} {{ Number(chargeNow).toLocaleString() }}</strong>
                </div>
                <div class="ld-plan-row later">
                  <div class="ld-plan-info">
                    <div class="ld-plan-lbl">Remaining on arrival</div>
                    <div class="ld-plan-sub">Paid in person{{ loc ? ' at ' + loc.name : ' at the venue' }}</div>
                  </div>
                  <span class="ld-plan-amt2">{{ cur }} {{ Number(paySplit.arrival).toLocaleString() }}</span>
                </div>
              </template>
              <template v-else-if="paySplit.method === 'online'">
                <div class="ld-plan-row now">
                  <div class="ld-plan-info">
                    <div class="ld-plan-lbl">Pay now <span class="ld-plan-tag">full amount</span></div>
                    <div class="ld-plan-sub">Charged today — nothing to pay at the venue</div>
                  </div>
                  <strong class="ld-plan-amt">{{ cur }} {{ Number(chargeNow).toLocaleString() }}</strong>
                </div>
              </template>
              <template v-else>
                <div class="ld-plan-row later">
                  <div class="ld-plan-info">
                    <div class="ld-plan-lbl">Pay at the venue</div>
                    <div class="ld-plan-sub">Nothing charged today — pay the full amount on arrival</div>
                  </div>
                  <strong class="ld-plan-amt">{{ cur }} {{ Number(paySplit.arrival).toLocaleString() }}</strong>
                </div>
              </template>
            </div>

            <div v-if="err" class="ld-err">{{ err }}</div>
            <button type="button" class="ld-book-btn" :disabled="saving" @click="confirm">{{ confirmLabel }}</button>
            <div class="ld-pay-note"><ShieldCheck :size="14" /> Secure payment by Stripe. Cards are never seen by Bookboll.</div>
            <button type="button" class="ld-map-btn" @click="step = 1"><ArrowLeft :size="16" /> Back to selection</button>
          </aside>
        </div>
      </template>
    </div>
    <Lightbox v-if="lightbox" :images="gallery" :start="activePhoto" :alt="l?.name || 'Listing'" @close="lightbox = false" />
  </div>
</template>

<style scoped>
.cback { display: inline-flex; align-items: center; gap: 6px; color: var(--brand); text-decoration: none; font-weight: 700; font-size: 14px; margin-bottom: 16px; max-width: 100%; }
.cback-btn { background: none; border: none; cursor: pointer; padding: 0; }
.cmsg { text-align: center; padding: 50px; color: var(--text-light, #94a3b8); }
.ld-hero { height: 260px; border-radius: 20px; background-size: cover; background-position: center; background-color: var(--brand-accent); position: relative; }
.ld-hero.has-photo { cursor: zoom-in; }
.ld-hero-fallback { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; color: rgba(255,255,255,.85); }
.ld-hero-expand { position: absolute; bottom: 10px; inset-inline-end: 10px; width: 32px; height: 32px; border-radius: 10px; background: rgba(8,12,20,.55); color: #fff; display: flex; align-items: center; justify-content: center; pointer-events: none; }
.ld-thumbs { display: flex; gap: 8px; margin-top: 10px; overflow-x: auto; padding-bottom: 2px; }
.ld-thumb { flex: 0 0 auto; width: 72px; height: 54px; border-radius: 10px; background-size: cover; background-position: center; border: 2px solid transparent; cursor: pointer; padding: 0; transition: border-color .15s, opacity .15s; opacity: .75; }
.ld-thumb:hover { opacity: 1; }
.ld-thumb.on { border-color: var(--brand-primary, var(--primary)); opacity: 1; }
.ld-grid { display: grid; grid-template-columns: 1fr 320px; gap: 24px; margin-top: 20px; }
.ld-main h1 { font-size: 28px; font-weight: 900; color: #0f172a; margin: 0; }

/* Select mode (host picking this court for a game) */
.ld-selbar { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-top: 16px; background: linear-gradient(135deg,#fffbeb,#fef3c7); border: 1.5px solid #fcd34d; border-radius: 14px; padding: 12px 16px; position: sticky; top: 12px; z-index: 5; }
.ld-selbar-msg { display: flex; align-items: center; gap: 8px; font-size: 14.5px; font-weight: 800; color: #92400e; }
.ld-selbar-actions { display: flex; align-items: center; gap: 8px; }
.ld-selbar-cancel { display: inline-flex; align-items: center; gap: 5px; background: var(--surface, #ffffff); border: 1.5px solid var(--border, #e2e8f0); color: #334155; font-weight: 700; font-size: 13.5px; padding: 9px 14px; border-radius: 999px; cursor: pointer; }
.ld-selbar-go { display: inline-flex; align-items: center; gap: 6px; background: #b45309; color: #fff; font-weight: 800; font-size: 13.5px; padding: 9px 16px; border-radius: 999px; border: none; cursor: pointer; }
.ld-select-main { margin-top: 18px; max-width: 640px; }
.ld-select-cta { margin-top: 18px; max-width: 360px; }
.ld-tags { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin: 12px 0; }
.ld-tag { font-size: 13px; font-weight: 700; color: var(--brand); background: color-mix(in srgb, var(--brand) 18%, white); padding: 4px 12px; border-radius: 999px; text-transform: capitalize; }
.ld-fact { display: flex; align-items: center; gap: 5px; font-size: 13.5px; color: var(--text-muted, #64748b); font-weight: 600; }
.ld-desc { font-size: 15px; color: #334155; line-height: 1.6; margin: 16px 0; }
.ld-h2 { font-size: 18px; font-weight: 800; color: #0f172a; margin: 24px 0 12px; }
.ld-h2-sub { font-size: 14px; font-weight: 600; color: var(--text-light, #94a3b8); }
.ld-time-label { font-size: 14px; font-weight: 800; color: #334155; margin: 18px 0 10px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.ld-ext-hint { display: inline-flex; align-items: center; gap: 4px; font-size: 12px; font-weight: 700; color: #b45309; background: #fef3c7; padding: 3px 9px; border-radius: 999px; }
.ld-select-hint { display: flex; align-items: center; gap: 6px; font-size: 12.5px; color: #475569; background: #f1f5f9; border-radius: 9px; padding: 8px 11px; margin: 0 0 10px; }
.ld-select-hint svg { color: var(--brand); flex-shrink: 0; }
.ld-slots-msg { display: flex; align-items: center; gap: 8px; color: var(--text-muted, #64748b); font-size: 14px; padding: 8px 0; }
.ld-closed-msg { color: #b91c1c; background: #fef2f2; border: 1px solid #fecaca; border-radius: 10px; padding: 10px 14px; font-weight: 600; }
.ld-special-note { display: flex; align-items: center; gap: 6px; color: #4338ca; background: #eef2ff; border: 1px solid #c7d2fe; border-radius: 8px; padding: 7px 12px; font-size: 12.5px; font-weight: 600; margin-bottom: 10px; }
.ld-spin { animation: ldspin 1s linear infinite; } @keyframes ldspin { to { transform: rotate(360deg); } }
.ld-slots { display: grid; grid-template-columns: repeat(auto-fill, minmax(100px, 1fr)); gap: 8px; }
.ld-slots.ld-blocks { grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); }
.ld-hblock .ld-slot-time { font-size: 12.5px; }
.ld-hblock .ld-slot-price { color: var(--brand); font-weight: 800; }
.ld-slot { display: flex; flex-direction: column; align-items: center; gap: 2px; padding: 10px 6px; border: 1.5px solid var(--border, #e2e8f0); border-radius: 10px; background: var(--surface, #ffffff); cursor: pointer; }
.ld-slot:hover:not(:disabled) { border-color: var(--brand-accent); }
.ld-slot.on { border-color: var(--brand); background: var(--brand); }
.ld-slot.on .ld-slot-time, .ld-slot.on .ld-slot-price, .ld-slot.on .ld-slot-left, .ld-slot.on .ld-slot-endt { color: #fff; }
.ld-slot.taken { background: #f8fafc; cursor: not-allowed; opacity: .7; }
.ld-slot-time { font-size: 14px; font-weight: 800; color: #0f172a; }
.ld-slot-endt { font-size: 10.5px; font-weight: 600; color: var(--text-light, #94a3b8); margin-top: -3px; }
.ld-slot-price { font-size: 11.5px; font-weight: 600; color: var(--brand); }
.ld-slot-price.disc { font-weight: 800; }
.ld-slot-was { font-size: 10px; font-weight: 600; color: var(--text-muted, #64748b); margin-left: 4px; text-decoration: line-through; text-decoration-color: #94a3b8; }
.ld-slot.on .ld-slot-was { color: rgba(255,255,255,.8); text-decoration-color: rgba(255,255,255,.8); }

/* price heat-map (price text only): green cheapest → amber mid → orange priciest */
.ld-slot.tier-low:not(.on) .ld-slot-price { color: #16a34a; }
.ld-slot.tier-mid:not(.on) .ld-slot-price { color: #ca8a04; }
.ld-slot.tier-high:not(.on) .ld-slot-price { color: #ea580c; }
.ld-slot-left { font-size: 10.5px; font-weight: 700; color: #b45309; }
.ld-slot.on .ld-slot-left { color: #fde68a; }
.ld-slot-x { font-size: 11px; font-weight: 700; color: #cbd5e1; }
.ld-offer { display: flex; align-items: center; gap: 12px; width: 100%; text-align: left; margin-top: 12px; padding: 12px 14px; border: none; border-radius: 14px; cursor: pointer; background: linear-gradient(135deg, #fffbeb, #fef3c7); box-shadow: inset 0 0 0 1.5px #fcd34d; transition: transform .1s; }
.ld-offer:hover { transform: translateY(-1px); background: linear-gradient(135deg, #fef3c7, #fde68a); }
.ld-offer-ico { width: 38px; height: 38px; border-radius: 10px; background: #f59e0b; color: #fff; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.ld-offer-txt { flex: 1; min-width: 0; }
.ld-offer-title { font-size: 14.5px; font-weight: 800; color: #92400e; }
.ld-offer-sub { font-size: 13px; color: #78350f; margin-top: 1px; }
.ld-offer-sub b { color: #0f172a; }
.ld-offer-sub s { color: #b45309; opacity: .75; margin-left: 4px; }
.ld-offer-add { display: inline-flex; align-items: center; gap: 5px; background: #b45309; color: #fff; font-weight: 800; font-size: 13.5px; padding: 8px 14px; border-radius: 999px; flex-shrink: 0; }
.ld-stepper { display: inline-flex; align-items: center; gap: 14px; border: 1.5px solid var(--border, #e2e8f0); border-radius: 10px; padding: 6px 12px; }
.ld-stepper button { width: 28px; height: 28px; border-radius: 8px; background: #f1f5f9; color: #0f172a; display: flex; align-items: center; justify-content: center; }
.ld-stepper button:disabled { opacity: .4; }
.ld-stepper span { font-size: 15px; font-weight: 700; color: #0f172a; }
.ld-step-note { font-size: 12px; font-weight: 500; color: var(--text-light, #94a3b8); }
.ld-stepper.sm { padding: 4px 8px; gap: 10px; }
.ld-stepper.sm button { width: 24px; height: 24px; }
.ld-addons { display: flex; flex-direction: column; gap: 8px; }
.ld-addon { display: flex; align-items: center; justify-content: space-between; gap: 12px; border: 1px solid var(--border, #e2e8f0); border-radius: 12px; padding: 10px 14px; }
.ld-addon-name { font-size: 14px; font-weight: 700; color: #0f172a; }
.ld-addon-price { font-size: 12.5px; color: var(--text-muted, #64748b); }
.ld-addon-unit { font-weight: 700; color: var(--brand); }
.ld-venue { display: flex; align-items: center; gap: 12px; width: 100%; text-align: left; border: 1px solid var(--border, #e2e8f0); border-radius: 14px; padding: 14px 16px; background: var(--surface, #ffffff); cursor: pointer; }
.ld-venue:hover { border-color: var(--brand-accent); background: color-mix(in srgb, var(--brand) 8%, white); }
.ld-side-venue { margin-bottom: 16px; }
.ld-venue-pin { width: 38px; height: 38px; border-radius: 10px; background: color-mix(in srgb, var(--brand) 18%, white); color: var(--brand); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.ld-venue-info { flex: 1; min-width: 0; }
.ld-venue-name { font-size: 15px; font-weight: 800; color: #0f172a; }
.ld-venue-addr { font-size: 13px; color: var(--text-muted, #64748b); }
.ld-venue-go { color: var(--brand); flex-shrink: 0; }
.ld-side { background: var(--surface, #ffffff); border: 1px solid var(--border, #e2e8f0); border-radius: 18px; padding: 22px; height: fit-content; position: sticky; top: 20px; }
.ld-side-price { font-size: 24px; font-weight: 900; color: #0f172a; }
.ld-sp-from { font-size: 13px; font-weight: 600; color: var(--text-light, #94a3b8); }
.ld-sp-per { font-size: 13px; font-weight: 600; color: var(--text-light, #94a3b8); }
.ld-side-sel { background: color-mix(in srgb, var(--brand) 8%, white); border: 1px solid #99f6e4; border-radius: 12px; padding: 12px 14px; margin: 16px 0; }
.ld-sel-day + .ld-sel-day { margin-top: 10px; padding-top: 10px; border-top: 1px solid color-mix(in srgb, var(--brand) 18%, white); }
.ld-sel-row { display: flex; align-items: center; gap: 7px; font-size: 14px; color: #0f172a; font-weight: 700; margin-bottom: 6px; }
.ld-sel-clear { margin-left: auto; color: var(--text-light, #94a3b8); display: flex; }
.ld-sel-clear:hover { color: #dc2626; }
.ld-sel-block { display: flex; align-items: center; justify-content: space-between; gap: 8px; font-size: 13px; color: #334155; margin-top: 5px; }
.ld-sel-block > span:first-child { display: flex; align-items: center; gap: 5px; }
.ld-sel-ext { color: #b45309; font-weight: 700; }
.ld-sel-bprice { font-weight: 700; }
.ld-side-hint { font-size: 13.5px; color: var(--text-muted, #64748b); background: #f8fafc; border-radius: 12px; padding: 14px; text-align: center; margin: 16px 0; display: flex; align-items: center; justify-content: center; gap: 7px; }
.ld-side-fields { display: flex; flex-direction: column; gap: 9px; margin-bottom: 10px; }
.ld-phone { margin-bottom: 14px; }
.ld-field { display: flex; align-items: center; gap: 9px; border: 1.5px solid var(--border, #e2e8f0); border-radius: 10px; padding: 0 12px; color: var(--text-light, #94a3b8); }
.ld-field input { flex: 1; border: none; outline: none; padding: 11px 0; font-size: 14.5px; color: #0f172a; background: transparent; }
.ld-breakdown { margin: 6px 0 14px; }
/* Hold countdown that rides inside the mobile fixed action bar — desktop uses the
   floating <CheckoutResumeCard>, so it's hidden here until the phone breakpoint. */
.ld-bar-timer { display: none; }
.ld-bk-row { display: flex; align-items: center; justify-content: space-between; font-size: 13.5px; color: #475569; padding: 4px 0; }
.ld-bk-save { color: #16a34a; font-weight: 700; }
.ld-bk-total { border-top: 1px solid #eef2f6; margin-top: 4px; padding-top: 8px; font-size: 15px; color: #0f172a; }
.ld-bk-total strong { font-size: 20px; font-weight: 900; }
.ld-err { font-size: 13px; color: #dc2626; margin-bottom: 10px; }
.ld-promo { margin: 0 0 16px; }
.ld-promo-form { display: flex; gap: 8px; }
.ld-promo-input { flex: 1; min-width: 0; border: 1.5px solid var(--border, #e2e8f0); border-radius: 10px; padding: 9px 12px; font-size: 13.5px; text-transform: uppercase; }
.ld-promo-input:focus { outline: none; border-color: var(--brand); }
.ld-promo-btn { border: 1.5px solid var(--brand); background: var(--surface, #ffffff); color: var(--brand); font-weight: 800; font-size: 13px; padding: 0 16px; border-radius: 10px; cursor: pointer; }
.ld-promo-btn:disabled { opacity: .5; cursor: default; }
.ld-promo-on { display: flex; align-items: center; justify-content: space-between; background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 10px; padding: 8px 12px; }
.ld-promo-tag { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 800; color: #065f46; }
.ld-promo-x { background: none; border: none; color: #047857; font-size: 12.5px; font-weight: 700; cursor: pointer; text-decoration: underline; }
.ld-promo-err { font-size: 12.5px; color: #dc2626; margin-top: 6px; }
.ld-hold { display: flex; align-items: center; gap: 9px; background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; font-size: 13.5px; font-weight: 600; padding: 10px 14px; border-radius: 12px; margin-bottom: 14px; }
.ld-hold strong { font-variant-numeric: tabular-nums; font-weight: 800; }
.ld-hold.urgent { background: #fef2f2; border-color: #fecaca; color: #b91c1c; }
.ld-hold-expired { display: flex; align-items: center; gap: 9px; background: #fffbeb; border: 1px solid #fde68a; color: #92400e; font-size: 13.5px; font-weight: 600; padding: 10px 14px; border-radius: 12px; margin-bottom: 14px; }
.ld-book-btn { display: flex; align-items: center; justify-content: center; gap: 8px; width: 100%; background: var(--brand); color: #fff; font-weight: 800; padding: 14px; border: none; border-radius: 12px; text-decoration: none; font-size: 15.5px; cursor: pointer; }
.ld-h2 { scroll-margin-top: 84px; }   /* clears any fixed header when scrolled to */
/* Two-phase mobile controls — inert on desktop (shown/styled only in the phone block). */
.ld-booknow { display: none; }
.ld-backdetails { display: none; }
.ld-book-btn:hover:not(:disabled) { background: #0e6b63; }
.ld-book-btn:disabled { opacity: .5; cursor: not-allowed; }
.ld-pay { margin: 4px 0 12px; }
.ld-pay-methods { display: flex; gap: 6px; margin-bottom: 10px; }
.ld-pay-opt { flex: 1; border: 1.5px solid var(--border, #e2e8f0); background: var(--surface, #ffffff); color: #475569; font-weight: 700; font-size: 12px; padding: 8px 6px; border-radius: 9px; cursor: pointer; line-height: 1.2; }
.ld-pay-opt.on { border-color: var(--brand); background: color-mix(in srgb, var(--brand) 8%, white); color: var(--brand); }
.ld-pay-split { background: #f8fafc; border: 1px solid #eef2f6; border-radius: 12px; padding: 10px 12px; }
.ld-pay-row { display: flex; align-items: center; justify-content: space-between; font-size: 13.5px; color: #0f172a; }
.ld-pay-row strong { font-size: 16px; font-weight: 900; }
.ld-pay-row.sub { font-size: 12.5px; color: var(--text-muted, #64748b); margin-top: 4px; }
.ld-pay-note { display: flex; align-items: center; justify-content: center; gap: 6px; font-size: 12.5px; color: var(--brand); margin-top: 10px; text-align: center; }
.ld-map-btn { display: flex; align-items: center; justify-content: center; gap: 8px; width: 100%; margin-top: 10px; background: var(--surface, #ffffff); border: 1.5px solid var(--border, #e2e8f0); color: #334155; font-weight: 700; padding: 12px; border-radius: 12px; cursor: pointer; font-size: 14px; }
.ld-map-btn:hover { border-color: var(--brand-accent); color: var(--brand); }
.ld-success { text-align: center; }
.ld-success-ico { width: 64px; height: 64px; margin: 0 auto 12px; border-radius: 50%; background: #dcfce7; color: #16a34a; display: flex; align-items: center; justify-content: center; }
.ld-success h3 { font-size: 20px; font-weight: 900; color: #0f172a; margin: 0 0 12px; }
.ld-success-day { font-size: 12.5px; font-weight: 800; color: var(--brand); margin-top: 8px; }
.ld-success-line { display: flex; align-items: center; justify-content: center; gap: 7px; font-size: 14px; color: #334155; font-weight: 600; margin-bottom: 4px; }
.ld-success-total { font-size: 14.5px; color: #0f172a; margin: 12px 0 6px; }
.ld-sim-note { font-size: 11.5px; color: var(--text-light, #94a3b8); margin: 0 0 14px; font-style: italic; }
.ld-success .ld-book-btn { margin-bottom: 10px; }

/* ===== 2-step flow ===== */
.ld-steps { display: flex; align-items: center; gap: 10px; margin: 20px 0 4px; }
.ld-step { display: inline-flex; align-items: center; gap: 9px; background: none; border: none; padding: 0; cursor: default; font-size: 14.5px; font-weight: 800; color: var(--text-light, #94a3b8); }
.ld-step.on { color: #0f172a; }
button.ld-step { cursor: pointer; }
button.ld-step.done:hover .ld-step-t { text-decoration: underline; }
.ld-step-n { width: 26px; height: 26px; flex-shrink: 0; border-radius: 50%; display: flex; align-items: center; justify-content: center; background: var(--border, #e2e8f0); color: var(--text-muted, #64748b); font-size: 13px; font-weight: 900; }
.ld-step.on .ld-step-n { background: var(--brand); color: #fff; }
.ld-step.done .ld-step-n { background: #16a34a; color: #fff; }
.ld-step-bar { flex: 1; max-width: 80px; height: 2px; border-radius: 2px; background: var(--border, #e2e8f0); }
.ld-step-bar.done { background: #16a34a; }
.ld-back2 { background: none; border: none; cursor: pointer; padding: 0; margin: 4px 0 4px; }
.ld-co-head { margin-bottom: 6px; }
.ld-co-head h1 { font-size: 24px; font-weight: 900; color: #0f172a; margin: 0; }
.ld-co-venue { display: flex; align-items: center; gap: 6px; font-size: 14px; font-weight: 600; color: var(--text-muted, #64748b); margin-top: 4px; }
.ld-cart { border: 1px solid var(--border, #e2e8f0); border-radius: 14px; padding: 6px 16px; }
.ld-cart-day { padding: 12px 0; }
.ld-cart-day + .ld-cart-day { border-top: 1px solid #eef2f6; }
.ld-cart-dayhead { display: flex; align-items: center; gap: 7px; font-size: 13.5px; font-weight: 800; color: var(--brand); margin-bottom: 8px; }
.ld-cart-row { display: flex; align-items: center; justify-content: space-between; gap: 10px; font-size: 14px; color: #334155; padding: 4px 0; }
.ld-cart-l { display: flex; align-items: center; gap: 7px; font-weight: 600; }
.ld-cart-p { font-weight: 800; color: #0f172a; white-space: nowrap; }
.ld-cart-r { display: inline-flex; align-items: center; gap: 8px; flex-shrink: 0; }
.ld-cart-x { display: inline-flex; align-items: center; justify-content: center; width: 26px; height: 26px; border-radius: 8px; background: #f1f5f9; color: var(--text-light, #94a3b8); border: none; cursor: pointer; flex-shrink: 0; }
.ld-cart-x:hover { background: #fee2e2; color: #dc2626; }
.ld-cart-addon { border-top: 1px dashed #eef2f6; padding-top: 8px; margin-top: 2px; color: #475569; }
.ld-who { display: flex; align-items: center; gap: 12px; border: 1px solid var(--border, #e2e8f0); border-radius: 14px; padding: 14px 16px; }
.ld-who-ava { width: 40px; height: 40px; flex-shrink: 0; border-radius: 50%; background: color-mix(in srgb, var(--brand) 18%, white); color: var(--brand); display: flex; align-items: center; justify-content: center; font-weight: 900; font-size: 16px; }
.ld-who-name { font-size: 15px; font-weight: 800; color: #0f172a; }
.ld-who-mail { font-size: 13px; color: var(--text-muted, #64748b); }
.ld-pay-methods.wrap { flex-wrap: wrap; }
.ld-pay-methods.wrap .ld-pay-opt { flex: 1 1 120px; }
.ld-sum-h { font-size: 11.5px; font-weight: 800; text-transform: uppercase; letter-spacing: .06em; color: var(--text-light, #94a3b8); margin: 0 0 6px; }
.ld-side .ld-breakdown { margin: 0 0 16px; }
.ld-pay-desc { display: flex; align-items: flex-start; gap: 9px; font-size: 13px; color: #334155; background: #f8fafc; border: 1px solid #eef2f6; border-radius: 12px; padding: 12px 14px; line-height: 1.5; }
.ld-pay-desc svg { color: var(--brand); flex-shrink: 0; margin-top: 1px; }
.ld-plan { display: flex; flex-direction: column; gap: 8px; margin-bottom: 14px; }
.ld-plan-row { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; padding: 12px; border-radius: 12px; border: 1px solid #eef2f6; }
.ld-plan-row.now { background: color-mix(in srgb, var(--brand) 8%, white); border-color: #99f6e4; }
.ld-plan-info { min-width: 0; }
.ld-plan-lbl { font-size: 13.5px; font-weight: 800; color: #0f172a; display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.ld-plan-tag { font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: .03em; color: var(--brand); background: color-mix(in srgb, var(--brand) 18%, white); padding: 2px 7px; border-radius: 999px; }
.ld-plan-sub { font-size: 12px; color: var(--text-muted, #64748b); margin-top: 3px; line-height: 1.4; }
.ld-plan-nr { display: inline-block; font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: .3px; color: #b45309; background: #fef3c7; border-radius: 5px; padding: 1px 6px; vertical-align: middle; }
.ld-plan-amt { font-size: 18px; font-weight: 900; color: #0f172a; white-space: nowrap; }
.ld-plan-amt2 { font-size: 14.5px; font-weight: 800; color: #475569; white-space: nowrap; }
.ld-done { max-width: 460px; margin: 24px auto; background: var(--surface, #ffffff); border: 1px solid var(--border, #e2e8f0); border-radius: 18px; padding: 32px 28px; }
.ld-done-actions { margin-top: 6px; }

@media (max-width: 760px) {
  .ld-grid { grid-template-columns: 1fr; }
  .ld-side { position: static; }
  /* Checkout step on mobile: strip the hero image, step tabs, "Edit selection" and the
     repeated venue header so the Order summary sits at the very top with minimal scrolling.
     (The top breadcrumb still links back to the venue.) */
  .ld-checkout .ld-hero,
  .ld-checkout .ld-thumbs,
  .ld-checkout .ld-steps,
  .ld-checkout .ld-back2,
  .ld-checkout .ld-co-head { display: none; }
  .ld-checkout .ld-grid { gap: 12px; margin-top: 4px; }

  /* ── Fix 2: compact hero on step 1 (desktop 260px untouched) ── */
  /* Proper 16:9 photo proportions (not a thin strip), still compact */
  .ld-hero { height: auto; aspect-ratio: 16 / 9; max-height: 200px; border-radius: 14px; }
  .ld-thumb { width: 60px; height: 45px; }

  /* ── Fix 3: smaller headings + tighter section spacing above the CTA ── */
  .ld-main h1 { font-size: 22px; }
  .ld-h2 { font-size: 16px; margin: 16px 0 8px; }
  .ld-desc {
    font-size: 14px; margin: 10px 0; line-height: 1.5;
    /* clamp long venue copy so it never buries the calendar */
    display: -webkit-box; -webkit-line-clamp: 4; -webkit-box-orient: vertical; overflow: hidden;
  }
  .ld-tags { gap: 8px; margin: 8px 0; }
  .ld-time-label { margin: 12px 0 8px; }

  /* ── Fix 4: comfortable, ≥44px slot targets in a breathable grid ── */
  .ld-slots { grid-template-columns: repeat(auto-fill, minmax(90px, 1fr)); gap: 8px; }
  .ld-slot { min-height: 56px; padding: 12px 6px; }

  /* ── Fix 5: 16px promo input stops iOS auto-zoom ── */
  .ld-promo-input { font-size: 16px; }

  /* ── Fix 6: pay-method chips stack full-width, ≥44px each ── */
  .ld-pay-methods.wrap { gap: 8px; }
  .ld-pay-methods.wrap .ld-pay-opt { flex: 1 1 100%; padding: 12px; font-size: 14px; min-height: 44px; }

  /* ── Fix 7: de-emphasise the redundant trailing "Back to selection" (top
     "Edit selection" already covers going back) so "Pay & confirm" is the one action ── */
  .ld-checkout .ld-side .ld-map-btn { display: none; }

  /* ── Fix 8: trim padded cards + side chrome to reclaim vertical space ── */
  .ld-side { padding: 16px; }
  .ld-offer { padding: 10px 12px; }
  .ld-venue { padding: 12px 14px; }
  .ld-side-sel { margin: 12px 0; }
  .ld-side .ld-breakdown { margin: 0 0 12px; }

  /* ── Fix 9: host select-mode banner wraps tightly + non-sticky (top
     breadcrumb already offers a way back) to free room for slot picking ── */
  .ld-selbar { position: static; padding: 10px 12px; gap: 8px; }
  .ld-selbar-msg { font-size: 13.5px; }

  /* ── Fix 1: FIXED slim Step-1 action bar (total + CTA). Unlike a sticky bar
     (which stays below the fold until you scroll down to it), this docks flush
     to the true viewport bottom the instant a slot is tapped — so "Continue" is
     reachable with zero scroll. Full-bleed dock (nav bar is hidden on flow
     pages, so the bar owns the bottom edge). The full breakdown stays in flow
     above; here we show only the running Total. ── */
  .ld-side-action {
    position: fixed; left: 0; right: 0; bottom: 0; z-index: 85;   /* nav bar is hidden on flow pages — own the bottom edge */
    display: flex; align-items: center; gap: 10px;
    background: var(--surface, #ffffff); border: none; border-top: 1px solid #e6ebf1; border-radius: 0;
    padding: 10px 14px calc(10px + env(safe-area-inset-bottom)) 16px; margin: 0;
    box-shadow: 0 -8px 24px -12px rgba(15, 23, 42, .25);
  }
  /* collapse the breakdown to just the Total, compact, on the left */
  .ld-side-action .ld-breakdown { flex: 1 1 auto; min-width: 0; margin: 0; padding: 0; }
  .ld-side-action .ld-bk-row:not(.ld-bk-total) { display: none; }
  /* the held-slot countdown rides above the Total inside the bar (replaces the
     redundant floating compact card, which we hide on phones) */
  .ld-side-action .ld-bar-timer {
    display: inline-flex; align-items: center; gap: 4px;
    font-size: 11px; font-weight: 800; color: var(--brand); margin: 0 0 1px;
    font-variant-numeric: tabular-nums;
  }
  .ld-side-action .ld-bar-timer.urgent { color: #dc2626; }
  .ld-side-action .ld-bk-total {
    display: flex; flex-direction: column; align-items: flex-start; gap: 0;
    border: none; margin: 0; padding: 0; line-height: 1.15;
  }
  .ld-side-action .ld-bk-total > span { font-size: 11px; font-weight: 600; color: var(--text-muted, #64748b); }
  .ld-side-action .ld-bk-total > strong { font-size: 18px; }
  .ld-side-action .ld-book-btn { margin: 0; flex: 0 0 auto; width: auto; padding: 12px 18px; white-space: nowrap; }
  /* host select-mode: no breakdown → the single button fills the bar */
  .ld-side-action-sm .ld-book-btn { flex: 1 1 auto; width: 100%; }
  /* keep the last in-flow content clear of the floating bar (+ the nav below it) */
  .ld-grid { padding-bottom: 12px; }
  .ld-grid-hasbar { padding-bottom: 96px; }

  /* ── Two-phase flow: DETAILS first, then a clean slot picker ──────────────
     Intro = photos, name, tags, description, venue + "from" price with ONE
     Book now button. Tapping it adds .ld-booking: the descriptive chrome
     drops away and only the date/slots/add-ons UI remains. */
  .ld-intro .ld-main .ld-h2,
  .ld-intro .ld-main .ld-h2 ~ * { display: none; }          /* hide booking UI from the first "Choose a date & time" on */
  .ld-intro .ld-main .ld-booknow { display: flex; }          /* …except the fixed Book now bar */
  .ld-intro .ld-side .ld-side-hint { display: none; }        /* "tap one or more times" makes no sense yet */
  .ld-intro .ld-steps { display: none; }                     /* flow tabs belong to the picker, not the details screen */
  .ld-intro .ld-side .ld-side-price { display: none; }       /* the fixed Book-now bar already shows the from-price */
  .ld-intro .ld-grid { padding-bottom: 96px; }               /* clearance for the Book now bar */
  .ld-intro .ld-desc { -webkit-line-clamp: unset; display: block; }  /* intro IS the details screen — show the full description */

  .ld-booknow {
    position: fixed; left: 0; right: 0; bottom: 0; z-index: 85;   /* docked flush at the true bottom — no nav bar on flow pages */
    display: flex; align-items: center; gap: 10px;
    background: var(--surface, #ffffff); border: none; border-top: 1px solid #e6ebf1; border-radius: 0;
    padding: 10px 14px calc(10px + env(safe-area-inset-bottom)) 16px;
    box-shadow: 0 -8px 24px -12px rgba(15, 23, 42, .25);
  }
  .ld-bn-price { flex: 1 1 auto; min-width: 0; display: flex; flex-direction: column; line-height: 1.15; font-size: 18px; font-weight: 900; color: #0f172a; }
  .ld-bn-price span { font-size: 11px; font-weight: 600; color: var(--text-muted, #64748b); }
  .ld-bn-price em { font-style: normal; font-size: 11px; font-weight: 600; color: var(--text-muted, #64748b); }
  .ld-bn-btn { flex: 0 0 auto; width: auto; padding: 12px 20px; white-space: nowrap; }

  /* Booking mode: strip the descriptive chrome so picking a time is the whole screen */
  .ld-booking .ld-hero, .ld-booking .ld-thumbs,
  .ld-booking .ld-tags, .ld-booking .ld-desc { display: none; }
  .ld-booking .ld-main h1 { font-size: 18px; margin-bottom: 4px; }
  .ld-booking .ld-side .ld-sum-h, .ld-booking .ld-side .ld-side-venue { display: none; }
  .ld-backdetails {
    display: inline-flex; align-items: center; gap: 5px;
    background: none; border: none; padding: 2px 0; margin: 2px 0 0; cursor: pointer;
    font-size: 13px; font-weight: 700; color: var(--brand);
  }

  /* ── 360px pass: nothing under 11px — end-times, strike-through prices and
     the "non-refundable" deposit tags are load-bearing labels ── */
  .ld-slot-endt, .ld-slot-left,
  .ld-slot-was, .ld-plan-tag, .ld-plan-nr { font-size: 11px; }

  /* ── 360px pass: players / add-on qty steppers reach ~44px effective touch
     targets (button + surrounding stepper padding) ── */
  .ld-stepper button { width: 40px; height: 40px; }
  .ld-stepper.sm button { width: 36px; height: 36px; }

  /* ── 360px pass: keep "Pay AED … & confirm" on one line for common totals ── */
  .ld-checkout .ld-side .ld-book-btn { font-size: 14.5px; padding-left: 10px; padding-right: 10px; }

  /* ── 360px pass: success card padding was eating ~80px of a 360px screen ── */
  .ld-done { padding: 22px 16px; margin: 16px auto; }
}

/* ── Narrow phones (360px, 344px foldables): compact the fixed bottom bars so
   the nowrap CTA and the Total never collide or push past the card edge.
   Subset of the 760px block — desktop untouched. ── */
@media (max-width: 400px) {
  .ld-side-action, .ld-booknow { padding: 8px 10px calc(8px + env(safe-area-inset-bottom)) 12px; }
  .ld-side-action .ld-book-btn, .ld-bn-btn { padding: 12px 12px; font-size: 14px; }
  .ld-side-action .ld-bk-total > strong { font-size: 16px; white-space: nowrap; }
  .ld-bn-price { font-size: 16px; }
}

/* Day/night glyph on each slot (AM/PM disambiguation) */
.ld-slot-time { display: inline-flex; align-items: center; gap: 4px; }
.ld-slot-sun { flex-shrink: 0; }
.ld-slot-sun.p-day { color: #f59e0b; }
.ld-slot-sun.p-night { color: #6366f1; }
.ld-slot.taken .ld-slot-sun { color: #cbd5e1; }
.ld-slot.on .ld-slot-sun { color: #fff; }
</style>
