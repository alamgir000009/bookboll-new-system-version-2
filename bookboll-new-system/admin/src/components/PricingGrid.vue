<script setup>
import { ref, computed, watch } from 'vue'
import { Plus, Trash2 } from 'lucide-vue-next'
import { fmt12, toMinutes, toTimeStr, endMinutes, periodsOf, effectivePeriods, periodSlotStarts } from '../lib/slots'
import { markDirty } from '../lib/unsavedGuard.js'
import { useToast } from '../lib/toast.js'

/*
 * One store, two views.
 *
 * Dynamic pricing lives in `pricing_groups` and nowhere else. The grid below is a
 * VIEW of those groups — every square is resolved from the group windows the same
 * way the server resolves a customer's slot — and painting a square rewrites the
 * group. There is no second table to drift from, so what the admin sees here is
 * what the customer is charged.
 */

const DAY_NAME = { 0: 'Sun', 1: 'Mon', 2: 'Tue', 3: 'Wed', 4: 'Thu', 5: 'Fri', 6: 'Sat' }
const DAY_SHORT = { 0: 'SUN', 1: 'MON', 2: 'TUE', 3: 'WED', 4: 'THU', 5: 'FRI', 6: 'SAT' }
const DAY_ORDER = [1, 2, 3, 4, 5, 6, 0]

/* `pricing_groups.days` comes back in two shapes depending on which editor last
   wrote the listing: this grid posts numeric indices ("1,2,3", Sunday = 0), the
   API validates lowercase day NAMES ("monday"). Read both. */
const DAY_INDEX = {
  sun: 0, sunday: 0, mon: 1, monday: 1, tue: 2, tues: 2, tuesday: 2,
  wed: 3, weds: 3, wednesday: 3, thu: 4, thur: 4, thurs: 4, thursday: 4,
  fri: 5, friday: 5, sat: 6, saturday: 6,
}
const ALL_TIMES = Array.from({ length: 24 * 2 }, (_, i) => toTimeStr(i * 30))

const props = defineProps({
  groups: { type: Array, required: true },
  basePrice: { type: Number, default: 0 },
  slotDuration: { type: Number, default: 60 },
  pattern: { type: String, default: 'A' },
  currency: { type: String, default: '' },
  /** The listing's own weekly hours: [{ day_of_week, is_open, periods }] */
  hours: { type: Array, default: () => [] },
  /** The venue's weekly hours, same shape. The server sells a slot only inside
   *  BOTH schedules, so the grid honours both too. Empty = not loaded. */
  venueHours: { type: Array, default: () => [] },
  /** 'auto' | 'fixed' | 'custom' — with 'custom', the squares are customSlots, not the schedule cut up. */
  startTimeMode: { type: String, default: 'auto' },
  /** [{ start_time, end_time, days: [0..6] }] — each slot has its own length. */
  customSlots: { type: Array, default: () => [] },
})
const emit = defineEmits(['update:groups', 'update:pattern', 'below-base'])

const toast = useToast()
const activeGroupId = ref(null)
const directPriceInput = ref(null)
const priceInputError = ref(false)
const priceErrorMsg = ref('')
// Tell the parent when the error is specifically "below base price" so it can
// highlight the base-price field — the thing the user actually has to change.
watch([priceInputError, priceErrorMsg], () => {
  emit('below-base', priceInputError.value && /lower than base price/i.test(priceErrorMsg.value))
})

// ── Time arithmetic ──────────────────────────────────────────────────────────
const minutesOf = (t) => toMinutes(t)
const endMin = (t) => endMinutes(t)
const hm = (m) => (m >= 24 * 60 ? '00:00' : toTimeStr(m))

// How long one bookable slot is. Guarded: a listing with no duration set would
// otherwise make every loop below run forever.
const slotMins = computed(() => (Number(props.slotDuration) > 0 ? Number(props.slotDuration) : 60))
// Custom mode: the provider's own slots, so each square can be a different length.
const customMode = computed(() => props.startTimeMode === 'custom' && props.customSlots.length > 0)

// ── Which hours are open, and which schedule closes the rest ─────────────────
function listingDay(dayIndex) {
  return props.hours.find(h => Number(h.day_of_week) === dayIndex) || { day_of_week: dayIndex, is_open: false, periods: [] }
}
function venueDay(dayIndex) {
  return props.venueHours.find(h => Number(h.day_of_week) === dayIndex) || null
}
/** Open periods the server would actually sell: listing hours ∩ venue hours —
 *  or, for custom slots, the venue's hours alone (the listing's schedule does
 *  not apply; unknown venue hours mean the whole day). */
function openPeriods(dayIndex) {
  if (customMode.value) {
    const v = venueDay(dayIndex)
    return v ? periodsOf(v) : [{ open_time: '00:00', close_time: '00:00' }]
  }
  return effectivePeriods(listingDay(dayIndex), props.venueHours)
}
function inPeriods(periods, m) {
  return periods.some(p => m >= minutesOf(p.open_time) && m < endMin(p.close_time))
}
/**
 * Why a given hour is shut, so the admin is sent to the right editor.
 * "listing" — this listing's weekly schedule; "venue" — the venue's hours;
 * null — it is open.
 */
function closedReason(dayIndex, m) {
  if (customMode.value) return inPeriods(openPeriods(dayIndex), m) ? null : 'venue'
  if (!inPeriods(periodsOf(listingDay(dayIndex)), m)) return 'listing'
  const v = venueDay(dayIndex)
  if (v && !inPeriods(periodsOf(v), m)) return 'venue'
  return null
}

// ── The slots that really exist, per day (mirrors ListingService) ────────────
/** [start, end] minute pairs a day sells, by mode. A custom slot counts only
 *  where it begins inside an open period and ends inside it (or the period
 *  closes at midnight, which lets it spill like an automatic slot). */
function slotPairsForDay(dayIndex) {
  const periods = openPeriods(dayIndex)
  if (!customMode.value) return periodSlotStarts(periods, slotMins.value).map(m => [m, m + slotMins.value])
  const out = []
  for (const cs of props.customSlots) {
    if (!(cs.days || []).map(Number).includes(dayIndex)) continue
    const a = minutesOf(cs.start_time)
    let b = endMin(cs.end_time)
    if (b <= a) b += 24 * 60
    const fits = periods.some(p => {
      const open = minutesOf(p.open_time), close = endMin(p.close_time)
      return a >= open && a < close && (b <= close || minutesOf(p.close_time) === 0)
    })
    if (fits && !out.some(([x]) => x === a)) out.push([a, b])
  }
  return out.sort((x, y) => x[0] - y[0])
}
function slotsForDay(dayIndex) {
  return slotPairsForDay(dayIndex).map(([m]) => toTimeStr(m))
}
/** Per day, the slot starts that genuinely exist — the grid's source of truth. */
const daySlots = computed(() => {
  const map = {}
  for (const d of DAY_ORDER) map[d] = new Set(slotsForDay(d))
  return map
})
/** How long the slot at (day, start) lasts — uniform unless the slots are custom. */
const slotLengths = computed(() => {
  const map = {}
  for (const d of DAY_ORDER) for (const [a, b] of slotPairsForDay(d)) map[d + '|' + toTimeStr(a)] = b - a
  return map
})
function slotLen(day, slot) { return slotLengths.value[day + '|' + slot] ?? slotMins.value }
/** A square is paintable only where that day actually has a bookable slot. */
function slotInOpen(dayIndex, slot) {
  return daySlots.value[dayIndex]?.has(slot) ?? false
}

// ── The 24-hour timeline the grid is drawn on ────────────────────────────────
/*
 * Rows are a fixed 24-hour timeline, so closed hours are VISIBLE as closed rather
 * than silently absent, and a slot that starts at 07:00 on Friday still lines up
 * with one that starts at 15:00 on Monday. A row is the finest tick both an hour
 * and the slot length divide into (60 for 1h/2h slots, 30 for 90-minute slots,
 * 15 for 45-minute ones), and a slot is drawn as one cell spanning as many rows
 * as it lasts — a 2-hour slot at 07:00 occupies the 07:00 and 08:00 rows.
 */
const gcd = (a, b) => (b ? gcd(b, a % b) : a)
const rowStep = computed(() => {
  if (!customMode.value) return Math.max(15, gcd(60, slotMins.value))
  // Custom slots can start and end anywhere; the row is the finest tick every
  // start and length divides into, down to 5 minutes.
  let g = 60
  for (const cs of props.customSlots) {
    const a = minutesOf(cs.start_time)
    let b = endMin(cs.end_time)
    if (b <= a) b += 24 * 60
    g = gcd(gcd(g, a), b - a)
  }
  return Math.max(5, g)
})
const rowCount = computed(() => Math.floor((24 * 60) / rowStep.value))
const rowTimes = computed(() => Array.from({ length: rowCount.value }, (_, i) => i * rowStep.value))
/** Rows a slot spans; a slot that runs past midnight is clipped at the bottom. */
function spanFor(day, startMin) {
  const rows = Math.max(1, Math.round(slotLen(day, toTimeStr(startMin)) / rowStep.value))
  return Math.min(rows, rowCount.value - startMin / rowStep.value)
}
/**
 * What to draw in each day column: one entry per real slot (spanning rows) and
 * one closed marker per row no slot covers. Explicit grid placement, so a
 * spanning slot never pushes the rows under it out of line.
 */
const columns = computed(() => DAY_ORDER.map(d => {
  const starts = [...daySlots.value[d]].map(minutesOf).sort((a, b) => a - b)
  const covered = new Set()
  const cells = []
  for (const s of starts) {
    const span = spanFor(d, s)
    for (let r = 0; r < span; r++) covered.add(s + r * rowStep.value)
    cells.push({ kind: 'slot', day: d, slot: toTimeStr(s), row: s / rowStep.value, span })
  }
  for (const m of rowTimes.value) {
    if (!covered.has(m)) cells.push({ kind: 'closed', day: d, slot: toTimeStr(m), row: m / rowStep.value, span: 1, reason: closedReason(d, m) })
  }
  return cells
}))
const gridStyle = computed(() => ({
  gridTemplateColumns: '60px repeat(7, minmax(60px, 1fr))',
  gridTemplateRows: `auto repeat(${rowCount.value}, var(--pg-row))`,
}))
const cellPlacement = (c) => ({ gridColumn: DAY_ORDER.indexOf(c.day) + 2, gridRow: `${c.row + 2} / span ${c.span}` })
const isHourRow = (m) => m % 60 === 0

function explainClosed(c) {
  const when = `${DAY_NAME[c.day]} ${fmt12(c.slot)}`
  if (c.reason === 'venue') {
    toast.error(`${when} is outside the venue's opening hours. Open the venue at this time to price it.`)
  } else if (c.reason === 'listing') {
    toast.error(`${when} is closed in this listing's Weekly Schedule. Open it there to price it.`)
  } else {
    // Open on both schedules, but no slot of this length fits here — e.g. a
    // 1-hour window on a 2-hour listing. Nothing can be booked, so nothing can
    // be priced.
    toast.error(customMode.value
      ? `${when} is open, but none of this listing's custom slots starts here, so nothing can be booked or priced at this time.`
      : `${when} is open, but no ${slotMins.value}-minute slot starts here, so nothing can be booked or priced at this time.`)
  }
}

// ── Pricing group days ───────────────────────────────────────────────────────
function groupDays(g) {
  const raw = Array.isArray(g?.days) ? g.days : String(g?.days ?? '').split(',')
  return [...new Set(
    raw
      .map(d => String(d).trim().toLowerCase())
      .filter(Boolean)
      .map(d => (/^[0-6]$/.test(d) ? Number(d) : DAY_INDEX[d]))
      .filter(d => Number.isInteger(d)),
  )].sort((a, b) => a - b)
}
function groupHasDay(g, d) { return groupDays(g).includes(d) }
function toggleGroupDay(g, d) {
  const set = new Set(groupDays(g))
  if (set.has(d)) set.delete(d)
  else set.add(d)
  updateGroup(g.id, { days: [...set].sort((a, b) => a - b).join(',') })
}

// ── Colour: by price, lowest green … highest dark red ────────────────────────
/*
 * A square's colour says how expensive it is relative to this listing's other
 * prices: the cheapest dynamic price is green, the dearest is dark red, and the
 * rest spread across yellow and orange between them. Two groups at the same
 * price look the same, because to a customer they ARE the same.
 */
const PRICE_COLORS = ['#16a34a', '#ca8a04', '#ea580c', '#991b1b']
const priceLadder = computed(() =>
  [...new Set(props.groups.map(g => Number(g.price)).filter(n => Number.isFinite(n)))].sort((a, b) => a - b))
function colorForPrice(price) {
  const ladder = priceLadder.value
  const idx = ladder.indexOf(Number(price))
  if (idx < 0 || ladder.length < 2) return PRICE_COLORS[0]
  return PRICE_COLORS[Math.round((idx * (PRICE_COLORS.length - 1)) / (ladder.length - 1))]
}
const groupColor = (g) => colorForPrice(g?.price)

// ── Resolving a square, exactly as the server does ───────────────────────────
function groupEndMin(g) {
  const m = minutesOf(g.end_time || '00:00')
  return m === 0 ? 24 * 60 : m   // midnight as an END is end-of-day
}
/** Does this group's window touch the slot starting at (day, slot)? */
function groupCoversSlot(g, day, slot) {
  if (!groupDays(g).includes(day)) return false
  const s = minutesOf(slot)
  return s < groupEndMin(g) && s + slotLen(day, slot) > minutesOf(g.start_time || '00:00')
}
/*
 * Which group wins a square — resolved as matchingPricingGroupForSlot() does:
 * prefer the group the slot STARTS inside, then the lowest sort_order, then the
 * lowest id. The two must agree, or the admin is shown a price the customer will
 * not be charged.
 */
function windowGroupFor(day, slot) {
  const covering = props.groups.filter(g => groupCoversSlot(g, day, slot))
  if (!covering.length) return null
  const s = minutesOf(slot)
  // An unsaved group has a `tmp-g-…` id; the server will give it the highest id
  // of the save, so rank it last on that tiebreak rather than first.
  const idRank = (g) => (Number.isFinite(Number(g.id)) ? Number(g.id) : Number.MAX_SAFE_INTEGER)
  const rank = (g) => [
    (s >= minutesOf(g.start_time || '00:00') && s < groupEndMin(g)) ? 0 : 1,
    Number(g.sort_order) || 0,
    idRank(g),
  ]
  return [...covering].sort((a, b) => {
    const ra = rank(a), rb = rank(b)
    for (let i = 0; i < ra.length; i++) if (ra[i] !== rb[i]) return ra[i] - rb[i]
    return 0
  })[0]
}
/** The group pricing this square, and what it costs. */
function resolvedCell(day, slot) {
  const g = windowGroupFor(day, slot)
  return { group: g || null, price: g ? Number(g.price) : Number(props.basePrice) }
}
function cellStyle(c) {
  const { group } = resolvedCell(c.day, c.slot)
  if (!group) return {}
  const col = colorForPrice(group.price)
  return { background: col + '26', borderLeft: `3px solid ${col}`, color: col }
}
function cellLabel(c) {
  return resolvedCell(c.day, c.slot).price
}
function cellTitle(c) {
  const { group, price } = resolvedCell(c.day, c.slot)
  // The END of a slot that runs past midnight is a real clock time on the next
  // day (23:00 + 2h reads "1:00 AM"), unlike a group window's end, where 00:00
  // means end-of-day.
  const end = toTimeStr((minutesOf(c.slot) + slotLen(c.day, c.slot)) % (24 * 60))
  return `${DAY_NAME[c.day]} ${fmt12(c.slot)} – ${fmt12(end)} · ${props.currency} ${price}${group ? ` · ${group.name}` : ' · base price'}`
}

// ── Painting: rewrite the GROUP, because the group is the only thing stored ──
/*
 * A group is a rectangle: one hour range across a set of days. An arbitrary set
 * of painted squares is not, so a square cannot simply be "folded in" — widening
 * start_time to swallow it would silently re-price every hour in between, which
 * on a live listing is somebody's money. Instead the group's squares are
 * recomputed and cut back into rectangles: contiguous hour runs, with the days
 * that share an identical run collected together. A tidy block stays one group;
 * a scattered paint honestly becomes two or three rather than quietly covering
 * hours nobody picked.
 */
function squaresOf(g) {
  const out = new Set()
  const gs = minutesOf(g.start_time || '00:00')
  const ge = groupEndMin(g)
  for (const d of groupDays(g)) {
    for (const slot of daySlots.value[d] ?? []) {
      const m = minutesOf(slot)
      if (m >= gs && m < ge) out.add(d + '|' + slot)
    }
  }
  return out
}
/** Cut a square set back into the fewest rectangles that cover exactly it. */
function rectanglesOf(squares) {
  const byDay = new Map()
  for (const key of squares) {
    const [d, slot] = key.split('|')
    if (!byDay.has(d)) byDay.set(d, [])
    byDay.get(d).push(minutesOf(slot))
  }
  const daysByRun = new Map()
  for (const [d, mins] of byDay) {
    mins.sort((a, b) => a - b)
    let from = null, prevEnd = null
    const close = () => {
      const key = from + '-' + prevEnd
      if (!daysByRun.has(key)) daysByRun.set(key, [])
      daysByRun.get(key).push(Number(d))
    }
    for (const m of mins) {
      const end = m + slotLen(Number(d), toTimeStr(m))
      if (from === null) { from = m; prevEnd = end; continue }
      if (m === prevEnd) { prevEnd = end; continue }
      close(); from = m; prevEnd = end
    }
    if (from !== null) close()
  }
  // End-of-day is 00:00 by this model's convention; hm() clamps a run that ends
  // past midnight instead of wrapping it into a window that matches nothing.
  return [...daysByRun.entries()]
    .map(([run, days]) => {
      const [a, b] = run.split('-').map(Number)
      return { _from: a, start_time: hm(a), end_time: hm(b), days: [...new Set(days)].sort((x, y) => x - y).join(',') }
    })
    .sort((x, y) => x._from - y._from)
}
// A split group keeps ONE name with a part number. Strip it before re-splitting,
// or every paint compounds the suffix and two different groups collide on "· 2".
const PART = / · \d+$/
const baseName = (n) => String(n || 'Group').replace(PART, '')
/** Rebuild one group from its squares, splitting when they are not one block.
 *  The FIRST rectangle keeps the original row's id, so a selected group stays
 *  selected and the server updates it in place instead of churning a new row. */
function rebuild(g, squares) {
  const rects = rectanglesOf(squares)
  const base = baseName(g.name)
  return rects.map((r, i) => {
    const { _from, ...rect } = r
    return { ...g, ...rect, id: i === 0 ? g.id : `tmp-g-${g.id}-${rect.days}-${rect.start_time}`, name: base }
  })
}
/** Number the pieces of a split group, but only where there is more than one. */
function numberParts(list) {
  const seen = new Map()
  for (const g of list) seen.set(baseName(g.name), (seen.get(baseName(g.name)) || 0) + 1)
  const n = new Map()
  return list.map(g => {
    const base = baseName(g.name)
    if (seen.get(base) < 2) return { ...g, name: base }
    n.set(base, (n.get(base) || 0) + 1)
    return { ...g, name: `${base} · ${n.get(base)}` }
  })
}
const touchesAny = (g, keys) => keys.some(key => {
  const [d, slot] = key.split('|')
  return groupCoversSlot(g, Number(d), slot)
})
/**
 * Apply a square change, touching ONLY the groups that edit concerns. A group
 * whose window the grid cannot draw (23:00-00:00 on a two-hour grid) maps to no
 * squares, and rebuilding it would delete it — so anything the paint does not
 * concern is passed through byte-identical.
 */
function applyPaint(keys, targetId, mutate) {
  markDirty()
  const wasActive = props.groups.find(g => g.id === activeGroupId.value)
  const out = []
  for (const g of props.groups) {
    if (g.id !== targetId && !touchesAny(g, keys)) { out.push(g); continue }
    const sq = squaresOf(g)
    mutate(g, sq)
    out.push(...rebuild(g, sq))
  }
  emit('update:groups', numberParts(out))
  // Erasing a group's last square removes its row; fall back to whatever is left
  // of the same group rather than leaving the brush pointing at nothing.
  if (!out.some(g => g.id === activeGroupId.value)) {
    const kin = wasActive && out.find(g => baseName(g.name) === baseName(wasActive.name))
    activeGroupId.value = kin ? kin.id : (out[0]?.id ?? null)
  }
}
/* Price painting matches ONLY against groups this grid created ("Grid <price>").
   The old converter matched EVERY group by price and collapsed three different
   300 groups into one; a group the user named is never a candidate here. */
const isGridGroup = (g) => /^Grid\s/.test(baseName(g.name))
function paintWithPrice(keys, price) {
  markDirty()
  const erase = price === Number(props.basePrice)
  // Every "Grid <price>" row is one logical brush — pool their squares so
  // neighbouring ones merge instead of piling up as one-hour groups.
  const mine = erase ? [] : props.groups.filter(g => isGridGroup(g) && Number(g.price) === price)
  const out = []
  for (const g of props.groups) {
    if (mine.includes(g)) continue
    if (!touchesAny(g, keys)) { out.push(g); continue }
    const sq = squaresOf(g)
    for (const key of keys) sq.delete(key)   // one price per square
    out.push(...rebuild(g, sq))
  }
  if (!erase) {
    const pooled = new Set()
    for (const g of mine) for (const key of squaresOf(g)) pooled.add(key)
    for (const key of keys) pooled.add(key)
    const template = mine[0] ?? { id: `tmp-g-grid-${price}-${Date.now()}`, name: `Grid ${price}`, sort_order: out.length }
    out.push(...rebuild({ ...template, name: `Grid ${price}`, price }, pooled))
  }
  emit('update:groups', numberParts(out))
}
const priceInputEl = ref(null)
/*
 * A click with no usable price has to say so where the user is LOOKING. The
 * price box sits above a 24-row grid; by the 9 PM row it has scrolled out of
 * view, so an inline error next to it was invisible and the click looked dead.
 * Toast it, bring the box back on screen, and put the cursor in it.
 */
function rejectPrice(message) {
  priceInputError.value = true
  priceErrorMsg.value = message
  toast.error(message)
  const el = priceInputEl.value
  if (el?.scrollIntoView) el.scrollIntoView({ block: 'center', behavior: 'smooth' })
  el?.focus?.()
  return null
}
/** The typed price, or null with the error shown if it cannot be used. */
function typedPrice() {
  const v = directPriceInput.value
  if (v === null || v === '' || isNaN(Number(v))) return rejectPrice('Enter a price first, then click or drag the slots to apply it.')
  const price = Number(v)
  if (price < Number(props.basePrice)) return rejectPrice(`Price cannot be lower than the base price (${props.basePrice}). Change the base price first.`)
  priceInputError.value = false
  priceErrorMsg.value = ''
  return price
}
function paintCell(day, slot, mode) {
  if (!slotInOpen(day, slot)) return
  const key = day + '|' + slot
  if (props.pattern === 'B') {
    const price = typedPrice()
    if (price === null) return
    paintWithPrice([key], mode === 'erase' ? Number(props.basePrice) : price)
    return
  }
  if (!activeGroupId.value) return
  applyPaint([key], activeGroupId.value, (g, sq) => {
    if (g.id !== activeGroupId.value) sq.delete(key)
    else if (mode === 'erase') sq.delete(key)
    else sq.add(key)
  })
}
function fillDay(day) {
  const mine = [...daySlots.value[day] ?? []].map(slot => day + '|' + slot)
  if (!mine.length) return
  if (props.pattern === 'B') {
    const price = typedPrice()
    if (price === null) return
    paintWithPrice(mine, price)
    return
  }
  if (!activeGroupId.value) { toast.error('Select a group above first, then apply it to the whole day.'); return }
  applyPaint(mine, activeGroupId.value, (g, sq) => {
    for (const key of mine) g.id === activeGroupId.value ? sq.add(key) : sq.delete(key)
  })
}

// ── Drag to paint ────────────────────────────────────────────────────────────
const painting = ref(false)
const paintMode = ref('paint')
function beginPaint(day, slot) {
  const owner = resolvedCell(day, slot).group
  if (props.pattern === 'B') {
    // Repainting a square with the price it already shows clears it, which is
    // also what painting the base price does.
    const price = typedPrice()
    if (price === null) return
    paintMode.value = (owner && Number(owner.price) === price) ? 'erase' : 'paint'
  } else {
    if (!activeGroupId.value) { toast.error('Select a group above first, then click or drag across the grid.'); return }
    paintMode.value = (owner && owner.id === activeGroupId.value) ? 'erase' : 'paint'
  }
  painting.value = true
  paintCell(day, slot, paintMode.value)
}
function endPaint() { painting.value = false }
function onPointerDown(event, c) {
  if (event.pointerType !== 'mouse') return
  event.preventDefault()
  beginPaint(c.day, c.slot)
}
function onPointerEnter(event, c) {
  if (painting.value && event.pointerType === 'mouse') paintCell(c.day, c.slot, paintMode.value)
}
function onClick(event, c) {
  // Mouse presses paint immediately (and drag). Touch and keyboard activate on
  // click so swiping the grid never changes prices accidentally.
  if (event.detail === 0 || event.pointerType !== 'mouse') { beginPaint(c.day, c.slot); endPaint() }
}

// ── Group cards ──────────────────────────────────────────────────────────────
function addGroup() {
  markDirty()
  const next = [...props.groups, {
    id: `tmp-g-${Date.now()}`,
    name: `Group ${props.groups.length + 1}`,
    price: props.basePrice,
    sort_order: props.groups.length,
    start_time: '18:00',
    end_time: '22:00',
    days: '1,2,3,4,5',
  }]
  emit('update:groups', next)
  activeGroupId.value = next[next.length - 1].id
}
function updateGroup(id, patch) {
  markDirty()
  emit('update:groups', props.groups.map(g => g.id === id ? { ...g, ...patch } : g))
}
function removeGroup(id) {
  markDirty()
  emit('update:groups', props.groups.filter(g => g.id !== id))
  if (activeGroupId.value === id) activeGroupId.value = null
}
</script>

<template>
  <div class="pg">
    <div class="pg-top">
      <div class="view-toggle">
        <button type="button" class="vt-btn" :class="{active: pattern==='A'}" @click="emit('update:pattern','A')">Groups</button>
        <button type="button" class="vt-btn" :class="{active: pattern==='B'}" @click="emit('update:pattern','B')">Slot Grid</button>
      </div>
      <button v-if="pattern==='A'" type="button" class="btn btn-secondary btn-sm" @click="addGroup"><Plus :size="14" />Add Group</button>
    </div>

    <div v-if="pattern==='A'" class="groups-list">
      <div v-for="g in groups" :key="g.id" class="group-card" :class="{active: activeGroupId===g.id}" @click="activeGroupId = g.id">
        <div class="gc-header">
          <div class="gc-title">
            <span class="gc-dot" :style="{background: groupColor(g)}"></span>
            <input class="gc-name-input" aria-label="Pricing group name" :value="g.name" @input="e => updateGroup(g.id, {name: e.target.value})" />
          </div>
          <button type="button" class="icon-del" :aria-label="`Remove ${g.name}`" @click.stop="removeGroup(g.id)"><Trash2 :size="16" /></button>
        </div>
        <div class="form-group">
          <label class="form-label">Price per Hour ({{ currency }})</label>
          <input type="number" aria-label="Group price per hour" min="0" :value="g.price" @input="e => updateGroup(g.id, {price: Number(e.target.value)})" />
        </div>
        <div class="grid grid-2">
          <div class="form-group">
            <label class="form-label">Start Time</label>
            <select aria-label="Group start time" :value="g.start_time" @change="e => updateGroup(g.id, {start_time: e.target.value})">
              <option v-for="t in ALL_TIMES" :key="t" :value="t">{{ fmt12(t) }}</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">End Time</label>
            <select aria-label="Group end time" :value="g.end_time" @change="e => updateGroup(g.id, {end_time: e.target.value})">
              <option v-for="t in ALL_TIMES" :key="t" :value="t">{{ fmt12(t) }}</option>
            </select>
          </div>
        </div>
        <div class="form-group" style="margin-bottom: 0;">
          <label class="form-label">Days</label>
          <div class="day-chips">
            <button v-for="d in DAY_ORDER" :key="d" type="button" class="day-chip"
                    :class="{selected: groupHasDay(g, d)}"
                    :aria-pressed="groupHasDay(g, d)"
                    @click="toggleGroupDay(g, d)">{{ DAY_NAME[d] }}</button>
          </div>
        </div>
      </div>
      <div v-if="!groups.length" class="empty-groups">No pricing groups yet. Click Add Group to define time-range pricing.</div>
      <div v-else class="pg-paint-hint">
        The grid below is these groups. Painting a square adds that day and hour to the group —
        {{ activeGroupId ? 'click or drag across the grid to paint with the selected group.' : 'select a group above to start.' }}
      </div>
    </div>

    <!-- Slot Grid: type a price, click or drag it onto squares. The number becomes
         a pricing group of its own, so there is still only one place a price lives. -->
    <div v-if="pattern==='B'" class="pg-toolbar">
      <div class="price-input">
        <label>Price ({{ currency }})</label>
        <input ref="priceInputEl" type="number" aria-label="Price to apply to slots" v-model.number="directPriceInput" placeholder="Enter price"
               :class="{'price-err': priceInputError}"
               @input="priceInputError = false"
               style="width: 140px;" />
        <span v-if="priceInputError" class="price-err-msg">{{ priceErrorMsg }}</span>
        <span v-else class="price-hint">Paint the base price ({{ basePrice }}) to erase</span>
      </div>
    </div>

    <p class="pg-scroll-hint">Swipe across for all days. Tap a time to apply the selected price.</p>

    <div class="grid-wrap" @pointerup="endPaint" @pointerleave="endPaint" @pointercancel="endPaint">
      <div class="pg-grid" :style="gridStyle">
        <div class="grid-corner"></div>
        <div v-for="d in DAY_ORDER" :key="'h' + d" class="grid-day" :style="{ gridColumn: DAY_ORDER.indexOf(d) + 2, gridRow: 1 }">
          {{ DAY_SHORT[d] }}
          <button type="button" class="fill-btn" @click="fillDay(d)" :aria-label="`Apply price to all ${DAY_NAME[d]} times`" title="Apply to the whole day">Apply all</button>
        </div>

        <div v-for="(m, i) in rowTimes" :key="'t' + m" class="grid-time" :class="{ hour: isHourRow(m) }" :style="{ gridColumn: 1, gridRow: i + 2 }">
          <span v-if="isHourRow(m) || rowStep < 60">{{ fmt12(toTimeStr(m)) }}</span>
        </div>

        <template v-for="col in columns" :key="'c' + col[0]?.day">
          <template v-for="c in col" :key="c.day + '|' + c.slot + c.kind">
            <button v-if="c.kind === 'slot'" type="button" class="grid-cell"
                    :class="{ faded: !resolvedCell(c.day, c.slot).group }"
                    :style="{ ...cellPlacement(c), ...cellStyle(c) }"
                    :title="cellTitle(c)"
                    :aria-label="cellTitle(c)"
                    @pointerdown="onPointerDown($event, c)"
                    @pointerenter="onPointerEnter($event, c)"
                    @click="onClick($event, c)">
              {{ cellLabel(c) }}
            </button>
            <button v-else type="button" class="grid-cell closed"
                    :style="cellPlacement(c)"
                    :title="`${DAY_NAME[c.day]} ${fmt12(c.slot)} — closed`"
                    :aria-label="`${DAY_NAME[c.day]} ${fmt12(c.slot)}, closed`"
                    @click="explainClosed(c)"></button>
          </template>
        </template>
      </div>
    </div>
  </div>
</template>

<style scoped>
.pg { border: 1px solid var(--border); border-radius: 8px; padding: 14px; background: var(--surface, #ffffff); }

.pg-top { display: flex; justify-content: flex-end; align-items: center; gap: 10px; margin-bottom: 14px; }
.view-toggle { display: inline-flex; background: var(--bg, #f1f5f9); border-radius: 8px; padding: 3px; }
.vt-btn { padding: 6px 14px; font-size: 13px; font-weight: 500; border-radius: 6px; color: var(--text-muted); }
.vt-btn.active { background: var(--surface, #ffffff); color: var(--text); box-shadow: var(--shadow-sm); }

.pg-toolbar { display: flex; gap: 8px; align-items: center; margin-bottom: 12px; }
.price-input { display: flex; align-items: center; gap: 8px; font-size: 13px; flex-wrap: wrap; }
.price-input label { color: var(--text-muted); }
.price-err { border-color: #b91c1c !important; background: var(--danger-bg, #fef2f2) !important; }
.price-err-msg { color: var(--danger-ink, #b91c1c); font-size: 12px; }
.price-hint { color: var(--text-muted); font-size: 12px; }

.groups-list { display: flex; flex-direction: column; gap: 14px; margin-bottom: 12px; }
.group-card { border: 1px solid var(--border); border-radius: 8px; padding: 18px; background: var(--surface, #ffffff); cursor: pointer; transition: border-color .12s, box-shadow .12s; }
.group-card.active { border-color: var(--primary); box-shadow: 0 0 0 2px rgba(15,118,110,.12); }
.pg-paint-hint { font-size: 12.5px; color: var(--text-muted); margin-top: 4px; }
.gc-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; }
.gc-title { display: flex; align-items: center; gap: 10px; flex: 1; min-width: 0; }
.gc-dot { width: 12px; height: 12px; border-radius: 50%; flex-shrink: 0; }
.gc-name-input { width: auto; min-width: 0; flex: 1; max-width: 240px; padding: 6px 10px; font-size: 15px; font-weight: 600; border: 1px solid transparent; background: transparent; }
.gc-name-input:hover, .gc-name-input:focus { border-color: var(--border); background: var(--surface, #ffffff); }
.icon-del { padding: 6px; border-radius: 6px; color: var(--error); }
.icon-del:hover { background: var(--danger-bg, #fee2e2); }
.day-chips { display: flex; gap: 8px; flex-wrap: wrap; }
.day-chip { padding: 6px 14px; font-size: 13px; font-weight: 500; border: 1px solid var(--border); border-radius: 999px; background: var(--surface, #ffffff); color: var(--text-muted); transition: all 0.15s; }
.day-chip:hover:not(.selected) { border-color: var(--primary); color: var(--primary-ink); }
.empty-groups { text-align: center; padding: 40px 20px; color: var(--text-muted); font-size: 14px; border: 1px dashed var(--border); border-radius: 8px; }

.pg-scroll-hint { color: var(--text-muted); font-size: 12px; line-height: 1.6; margin: 0 0 8px; }

.grid-wrap { border: 1px solid var(--border); border-radius: 6px; overflow: auto; user-select: none; max-height: 480px; }
/* Not `.grid` — the global form-layout `.grid { gap:16px }` would gutter every cell. */
.pg-grid { --pg-row: 30px; display: grid; min-width: 620px; }
.grid-corner { position: sticky; top: 0; left: 0; z-index: 3; background: var(--bg); grid-column: 1; grid-row: 1; }
.grid-day { padding: 8px; font-size: 12px; font-weight: 600; text-align: center; color: var(--text-muted); border-left: 1px solid var(--border); background: var(--bg); position: sticky; top: 0; z-index: 2; display: flex; flex-direction: column; align-items: center; gap: 2px; }
.fill-btn { font-size: 10px; color: var(--primary-ink); background: none; padding: 1px 4px; border-radius: 3px; }
.fill-btn:hover { background: var(--primary-soft, #ccfbf1); }

.grid-time { padding: 4px 8px; font-size: 11px; font-family: 'SF Mono', Monaco, monospace; color: var(--text-muted); background: var(--bg); text-align: right; position: sticky; left: 0; z-index: 1; border-top: 1px solid var(--border); line-height: 1.3; }

.grid-cell { border: 0; border-radius: 0; border-left: 1px solid var(--border); border-top: 1px solid var(--border); padding: 0; min-height: var(--pg-row); display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 500; cursor: pointer; transition: opacity 0.1s; background: var(--surface, #ffffff); color: var(--text); }
.grid-cell:hover { opacity: 0.75; }
/* Base price: present, bookable, but nothing special — read it as a quiet number. */
.grid-cell.faded { color: var(--text-muted) !important; font-weight: 400; }
.grid-cell.faded:hover { color: var(--primary-ink) !important; background: var(--primary-soft, #f0fdfa) !important; }
/* Closed: flat grey, clearly not a slot. Still clickable, so the click can say
   WHICH schedule to open. */
.grid-cell.closed { cursor: not-allowed; background: var(--surface-hover, var(--bg)); }
.grid-cell.closed:hover { opacity: 1; }
.grid-cell:focus-visible { outline: 3px solid var(--primary); outline-offset: -3px; z-index: 1; }
.day-chip.selected { color: var(--primary-ink); background: var(--primary-soft); border-color: var(--primary); }

@media (max-width: 760px) {
  .pg { padding: 12px; }
  .pg-top { justify-content: space-between; flex-wrap: wrap; }
  .group-card { padding: 12px; }
  .pg :is(.vt-btn,.day-chip,.icon-del,.fill-btn) { min-height: 44px; }
  .icon-del { min-width: 44px; flex-shrink: 0; }
  .price-hint, .price-err-msg { flex-basis: 100%; }
  .pg-grid { --pg-row: 44px; }
  .grid-time { display: flex; align-items: center; justify-content: flex-end; font-size: 10px; padding: 4px; }
}
</style>
