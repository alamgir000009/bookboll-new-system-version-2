<script setup>
import { ref, computed, watch } from 'vue'
import { Plus, Trash2 } from 'lucide-vue-next'
import { fmt12 } from '../lib/slots'
import { markDirty } from '../lib/unsavedGuard.js'

const DAY_NAME = { 0: 'Sun', 1: 'Mon', 2: 'Tue', 3: 'Wed', 4: 'Thu', 5: 'Fri', 6: 'Sat' }

/* `pricing_groups.days` comes back in two shapes, depending on which editor last
   wrote the listing: this grid posts numeric indices ("1,2,3", Sunday = 0), while
   the API validates lowercase day NAMES ("monday,tuesday"). Parsing only numbers
   meant a name-based group showed EVERY day pill switched off — and the moment
   anyone tapped a day, the Set was seeded with NaN and the days it already had were
   written away. That is the likeliest way a working group became a dead one. */
const DAY_INDEX = {
  sun: 0, sunday: 0, mon: 1, monday: 1, tue: 2, tues: 2, tuesday: 2,
  wed: 3, weds: 3, wednesday: 3, thu: 4, thur: 4, thurs: 4, thursday: 4,
  fri: 5, friday: 5, sat: 6, saturday: 6,
}
const ALL_TIMES = Array.from({ length: 24 * 2 }, (_, i) => {
  const h = Math.floor(i / 2), m = (i % 2) * 30
  return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`
})

const props = defineProps({
  groups: { type: Array, required: true },
  cells: { type: Array, required: true },
  basePrice: { type: Number, default: 0 },
  slotDuration: { type: Number, default: 60 },
  pattern: { type: String, default: 'A' },
  currency: { type: String, default: 'AED' },
  hours: { type: Array, default: () => [] },
})
const emit = defineEmits(['update:groups', 'update:cells', 'update:pattern', 'below-base'])

const activeGroupId = ref(null)
const directPriceInput = ref(null)
const priceInputError = ref(false)
const priceErrorMsg = ref('')
// Tell the parent when the error is specifically "below base price" so it can
// highlight the base-price field — the thing the user actually has to change.
watch([priceInputError, priceErrorMsg], () => {
  emit('below-base', priceInputError.value && /lower than base price/i.test(priceErrorMsg.value))
})
const GRID_COLORS = ['#b91c1c', '#ea580c', '#eab308', '#0f766e', '#3b82f6', '#8b5cf6', '#ec4899']

// Keep the two views in sync: the Slot Grid stores per-cell direct prices, the Groups
// view stores cells assigned to a named price group. When the user switches views we
// convert the cells so the SAME prices show up in both (so painting the grid no longer
// leaves the Groups view empty, and vice-versa).
/* The two tabs are VIEWS. Switching between them used to rewrite your data.
   Groups -> Slot Grid flattened every group-painted square into a bare number,
   losing which group put it there. Slot Grid -> Groups mapped those numbers back
   to groups keyed by PRICE:

       const byPrice = new Map(newGroups.map(g => [Number(g.price), g.id]))

   A Map keyed on price means the LAST group with that price wins, so any two
   groups sharing a price collapsed into one. On a listing with Groups 1/3/4 all
   at 300 and Groups 2/5 both at 275, one round trip through the tabs reassigned
   every square to Group 4 and Group 5 and erased the other three from the grid
   entirely. The price looked unchanged, so nothing seemed wrong — until a group's
   Days were edited and the slots did not move, because the squares no longer
   pointed at it.

   Price is not an identity and never can be. There is no fix for this converter,
   only deletion: changing a view must not touch stored data. Both tabs now render
   the same resolved price, so nothing needs converting to stay in sync. */

const DAY_ORDER = [1, 2, 3, 4, 5, 6, 0]

const priceTiers = computed(() => {
  return [...new Set(props.groups.map(g => Number(g.price) || 0))].sort((a, b) => b - a)
})
function colorForTier(price, tiers) {
  if (!tiers.length) return '#f59e0b'
  const idx = tiers.indexOf(Number(price) || 0)
  if (idx === 0) return '#b91c1c'
  if (idx === 1) return '#ea580c'
  return '#eab308'
}
/*
 * A group's colour is its own. This used to be derived from where the group's
 * PRICE ranked against the others, so two groups looked different purely
 * because one cost more — and a group silently changed colour when someone
 * edited a different group's price. Use the colour stored on the row, and fall
 * back to a stable one based on the group's position so it never shifts.
 */
const STABLE_COLORS = ['#0f766e', '#3b82f6', '#f59e0b', '#10b981', '#ef4444', '#06b6d4', '#8b5cf6', '#ec4899']
function groupColor(g) {
  if (g?.color) return g.color
  const i = props.groups.findIndex(x => x.id === g?.id)
  return STABLE_COLORS[(i < 0 ? 0 : i) % STABLE_COLORS.length]
}
const DAY_SHORT = { 0: 'SUN', 1: 'MON', 2: 'TUE', 3: 'WED', 4: 'THU', 5: 'FRI', 6: 'SAT' }

function hoursFor(dayIndex) {
  return props.hours.find(h => h.day_of_week === dayIndex) || { is_open: true, periods: [{ open_time: '08:00', close_time: '23:00' }] }
}
function minutesOf(t) { const [h, m] = t.split(':').map(Number); return h * 60 + m }
// Midnight as a CLOSE time means end-of-day (a 12 PM → 12 AM schedule is 12
// open hours, not an empty window).
function endMin(t) { const m = minutesOf(t); return m === 0 ? 24 * 60 : m }
function dayPeriods(h) {
  if (h.periods && h.periods.length) return h.periods
  if (h.open_time) return [{ open_time: h.open_time, close_time: h.close_time }]
  return []
}

// How long one bookable slot is. Guarded, because a listing with no duration set
// would otherwise make the row generator loop forever.
const slotMins = computed(() => (Number(props.slotDuration) > 0 ? Number(props.slotDuration) : 60))

/*
 * The grid's rows must BE the bookable slots, not a guess at them.
 *
 * This used to take the earliest open time across the whole week and step from
 * there in slot-sized jumps, one row list shared by every day. On a listing
 * whose days open at different times that is simply a different grid from the
 * real one: listing 16 opens 00:00-01:00 and 07:00-00:00, so the shared list
 * started at midnight and produced 00:00, 02:00, 04:00 … while the actual
 * bookable slots on Friday and Saturday are 07:00, 09:00, 11:00 … Every square
 * the admin painted therefore landed on a time no customer could ever book, and
 * a price set "at midnight" was never charged by anything.
 *
 * Mirror ListingService::generateTimeSlots() exactly: walk each of the day's
 * open periods, step by the slot length, keep a slot whose START is inside the
 * period, and allow the last one to overrun ONLY when the period closes at
 * midnight (which is how a 23:00-01:00 slot legitimately exists).
 */
function slotsForDay(dayIndex) {
  const h = hoursFor(dayIndex)
  if (!h.is_open) return []

  const step = slotMins.value
  const out = []
  for (const p of dayPeriods(h)) {
    const open = minutesOf(p.open_time)
    const close = endMin(p.close_time)
    const closesAtMidnight = minutesOf(p.close_time) === 0
    for (let m = open; m < close; m += step) {
      if (! closesAtMidnight && m + step > close) break
      out.push(`${String(Math.floor(m / 60)).padStart(2, '0')}:${String(m % 60).padStart(2, '0')}`)
    }
  }

  return out
}

/** Per day, the slot starts that genuinely exist — the grid's source of truth. */
const daySlots = computed(() => {
  const map = {}
  for (const d of DAY_ORDER) map[d] = new Set(slotsForDay(d))

  return map
})

/** Every row the grid needs: the union across days, in time order. */
const slots = computed(() => {
  const all = new Set()
  for (const d of DAY_ORDER) for (const slot of slotsForDay(d)) all.add(slot)

  return [...all].sort((a, b) => minutesOf(a) - minutesOf(b))
})

/** A square exists only where that day actually has a bookable slot. */
function slotInOpen(dayIndex, slot) {
  return daySlots.value[dayIndex]?.has(slot) ?? false
}

function groupEndMin(g) {
  const m = minutesOf(g.end_time || '00:00')

  return m === 0 ? 24 * 60 : m   // midnight as an END is end-of-day
}

/** Does this group's window touch the square at (day, slot)? */
function groupCoversSlot(g, day, slot) {
  if (! groupDays(g).includes(day)) return false
  const s = minutesOf(slot)

  return s < groupEndMin(g) && s + slotMins.value > minutesOf(g.start_time || '00:00')
}

/*
 * Which group wins this square — resolved exactly as the backend resolves it in
 * matchingPricingGroupForSlot(): prefer the group the slot STARTS inside, then
 * the lowest sort_order, then the lowest id. The two must agree, or the admin
 * is shown a price the customer will not be charged.
 */
function windowGroupFor(day, slot) {
  const covering = props.groups.filter(g => groupCoversSlot(g, day, slot))
  if (! covering.length) return null

  const s = minutesOf(slot)
  // An unsaved group has a `tmp-g-…` id; the backend will give it the highest id
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

function cellStyle(day, slot) {
  if (!slotInOpen(day, slot)) return { background: 'var(--surface-hover)', color: 'var(--text-muted)', cursor: 'not-allowed' }
  const { group } = resolvedCell(day, slot)
  if (!group) return props.pattern === 'B' ? { background: 'var(--surface)', color: 'var(--text-muted)' } : { background: 'var(--surface)' }
  const col = groupColor(group)
  return { background: col + '22', borderLeft: `3px solid ${col}`, color: 'var(--text)' }
}

function cellLabel(day, slot) {
  if (!slotInOpen(day, slot)) return ''
  const { group } = resolvedCell(day, slot)
  if (group) return group.price
  // The Slot Grid spells the base price out; the Groups view leaves it blank so
  // the priced squares stand out.
  return props.pattern === 'B' ? props.basePrice : ''
}

function isFaded(day, slot) {
  if (props.pattern !== 'B') return false
  // Faded means "nothing special here" — base price, not merely unpainted.
  return slotInOpen(day, slot) && !resolvedCell(day, slot).group
}




/* Painting rewrites the GROUP, because the group is the only thing stored.
 *
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
    for (const slot of slots.value) {
      const m = minutesOf(slot)
      if (m >= gs && m < ge) out.add(d + '|' + slot)
    }
  }
  return out
}

/** Cut a square set back into the fewest rectangles that cover exactly it. */
function rectanglesOf(squares) {
  const step = slotMins.value
  const byDay = new Map()
  for (const key of squares) {
    const [d, slot] = key.split('|')
    if (!byDay.has(d)) byDay.set(d, [])
    byDay.get(d).push(minutesOf(slot))
  }

  const daysByRun = new Map()
  for (const [d, mins] of byDay) {
    mins.sort((a, b) => a - b)
    let from = null, prev = null
    const close = () => {
      const key = from + '-' + (prev + step)
      if (!daysByRun.has(key)) daysByRun.set(key, [])
      daysByRun.get(key).push(Number(d))
    }
    for (const m of mins) {
      if (from === null) { from = prev = m; continue }
      if (m === prev + step) { prev = m; continue }
      close(); from = prev = m
    }
    if (from !== null) close()
  }

  // End-of-day is 00:00 by this model's convention. A slot length that does not
  // divide the day (a 50-minute slot, say) can push the last run past midnight,
  // and `m % 1440` turned that into 01:00 — a window reading 23:00-01:00, which
  // wraps and therefore matches nothing at all. Clamp instead of wrapping.
  const hm = (m) => (m >= 24 * 60
    ? '00:00'
    : `${String(Math.floor(m / 60)).padStart(2, '0')}:${String(m % 60).padStart(2, '0')}`)
  return [...daysByRun.entries()]
    .map(([run, days]) => {
      const [a, b] = run.split('-').map(Number)
      return { _from: a, start_time: hm(a), end_time: hm(b), days: [...new Set(days)].sort((x, y) => x - y).join(',') }
    })
    .sort((x, y) => x._from - y._from)
}

// A split group keeps ONE name with a part number. Strip it before re-splitting,
// or every paint compounds the suffix and two different groups collide on "(2)".
const PART = / · \d+$/
const baseName = (n) => String(n || 'Group').replace(PART, '')

/**
 * Rebuild one group from its squares, splitting when they are not one block.
 *
 * The FIRST rectangle always keeps the original row's id, so a group the user
 * has selected stays selected and the server updates it in place instead of
 * churning a new row.
 */
function rebuild(g, squares) {
  const rects = rectanglesOf(squares)
  const base = baseName(g.name)
  return rects.map((r, i) => {
    const { _from, ...rect } = r
    return {
      ...g,
      ...rect,
      id: i === 0 ? g.id : `tmp-g-${g.id}-${rect.days}-${rect.start_time}`,
      name: base,
    }
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

/**
 * Apply a square change, touching ONLY the groups that edit concerns.
 *
 * Every group used to be round-tripped through squaresOf() -> rebuild() on any
 * paint, and squaresOf() only sees squares the grid actually draws. A window
 * that does not land on a slot boundary — 23:00-00:00 on a two-hour grid — maps
 * to NO squares, so rebuilding it produced nothing and the group was silently
 * deleted. Painting one unrelated square on Friday morning destroyed the Friday
 * night price, with no warning and nothing in the payload to show why.
 *
 * A group is rebuilt only if it is the brush's target or if it actually owns one
 * of the squares being taken. Everything else is passed through byte-identical,
 * so it cannot be reshaped or lost by an edit somewhere else in the week.
 */
function applyPaint(keys, targetId, mutate) {
  markDirty()
  const wasActive = props.groups.find(g => g.id === activeGroupId.value)
  const touches = (g) => keys.some(key => {
    const [d, slot] = key.split('|')
    return groupCoversSlot(g, Number(d), slot)
  })

  const out = []
  for (const g of props.groups) {
    if (g.id !== targetId && ! touches(g)) {
      out.push(g)
      continue
    }
    const sq = squaresOf(g)
    mutate(g, sq)
    out.push(...rebuild(g, sq))
  }
  emit('update:groups', numberParts(out))

  // Erasing a group's last square removes its row; fall back to whatever is left
  // of the same group rather than leaving the brush pointing at nothing.
  if (! out.some(g => g.id === activeGroupId.value)) {
    const kin = wasActive && out.find(g => baseName(g.name) === baseName(wasActive.name))
    activeGroupId.value = kin ? kin.id : (out[0]?.id ?? null)
  }
}

/*
 * Matching is by price, but ONLY against groups this grid created. The converter
 * that collapsed groups by price (and silently merged three different 300 groups
 * into one) matched against EVERY group, which is what destroyed the listing-52
 * pricing. A group the user named is never a candidate here.
 */
const isGridGroup = (g) => /^Grid\s/.test(baseName(g.name))

function paintWithPrice(keys, price) {
  markDirty()
  const erase = price === Number(props.basePrice)
  const touches = (g) => keys.some(key => {
    const [d, slot] = key.split('|')
    return groupCoversSlot(g, Number(d), slot)
  })

  // Every "Grid <price>" row is one logical brush — a scattered paint splits it
  // into several rectangles, and adding to only one of them would strand the
  // rest. Pool their squares so neighbouring ones merge instead of piling up.
  const mine = erase ? [] : props.groups.filter(g => isGridGroup(g) && Number(g.price) === price)

  const out = []
  for (const g of props.groups) {
    if (mine.includes(g)) {
      continue   // pooled below
    }
    // Untouched by this paint: pass through exactly as stored. Rebuilding it
    // would re-derive its window from the grid and lose anything the grid
    // cannot draw, such as a 23:00-00:00 window on a two-hour grid.
    if (! touches(g)) {
      out.push(g)
      continue
    }
    const sq = squaresOf(g)
    for (const key of keys) sq.delete(key)   // one price per square
    out.push(...rebuild(g, sq))
  }

  if (! erase) {
    const pooled = new Set()
    for (const g of mine) for (const key of squaresOf(g)) pooled.add(key)
    for (const key of keys) pooled.add(key)

    const template = mine[0] ?? {
      id: `tmp-g-grid-${price}-${Date.now()}`,
      name: `Grid ${price}`,
      color: GRID_COLORS[out.length % GRID_COLORS.length],
      sort_order: out.length,
    }
    out.push(...rebuild({ ...template, name: `Grid ${price}`, price }, pooled))
  }

  emit('update:groups', numberParts(out))
}

/** The typed price, or null with the error shown if it cannot be used. */
function typedPrice() {
  const v = directPriceInput.value
  if (v === null || v === '' || isNaN(Number(v))) {
    priceInputError.value = true
    priceErrorMsg.value = 'Please enter a price'

    return null
  }
  const price = Number(v)
  if (price < Number(props.basePrice)) {
    priceInputError.value = true
    priceErrorMsg.value = 'Price cannot be lower than base price. Change the base price first.'

    return null
  }
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

const painting = ref(false)
const paintMode = ref('paint')
function onMouseDown(day, slot) {
  const owner = resolvedCell(day, slot).group
  if (props.pattern === 'B') {
    // Repainting a square with the price it already shows clears it, which is
    // also what painting the base price does.
    const price = typedPrice()
    if (price === null) return
    paintMode.value = (owner && Number(owner.price) === price) ? 'erase' : 'paint'
  } else {
    if (!activeGroupId.value) return
    paintMode.value = (owner && owner.id === activeGroupId.value) ? 'erase' : 'paint'
  }
  painting.value = true
  paintCell(day, slot, paintMode.value)
}
function onMouseEnter(day, slot) { if (painting.value) paintCell(day, slot, paintMode.value) }
function onMouseUp() { painting.value = false }
function startPointerPaint(event, day, slot) {
  if (event.pointerType !== 'mouse') return
  event.preventDefault()
  onMouseDown(day, slot)
}
function continuePointerPaint(event, day, slot) {
  if (event.pointerType === 'mouse') onMouseEnter(day, slot)
}
function activateCell(event, day, slot) {
  // Mouse presses paint immediately and support dragging. Touch and keyboard
  // activate on click so swiping the grid never changes prices accidentally.
  if (event.detail === 0 || event.pointerType !== 'mouse') {
    onMouseDown(day, slot)
    onMouseUp()
  }
}

function addGroup() {
  markDirty()
  const colors = ['#0f766e', '#3b82f6', '#f59e0b', '#10b981', '#ef4444', '#06b6d4']
  const idx = props.groups.length % colors.length
  const next = [...props.groups, {
    id: `tmp-g-${Date.now()}`,
    name: `Group ${props.groups.length + 1}`,
    color: colors[idx],
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

function fillDay(day) {
  const mine = slots.value.filter(slot => slotInOpen(day, slot)).map(slot => day + '|' + slot)

  if (props.pattern === 'B') {
    const price = typedPrice()
    if (price === null) return
    paintWithPrice(mine, price)

    return
  }

  if (!activeGroupId.value) return
  applyPaint(mine, activeGroupId.value, (g, sq) => {
    for (const key of mine) g.id === activeGroupId.value ? sq.add(key) : sq.delete(key)
  })
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

    <!-- Slot Grid has no group CARDS, so without this row there is nothing to
         paint with and every click is a silent no-op. -->
    <!-- Slot Grid: type a price, paint it on. The number becomes a pricing
         group of its own, so there is still only one place a price lives. -->
    <div v-if="pattern==='B'" class="pg-toolbar">
      <div class="price-input">
        <label>Price ({{ currency }})</label>
        <input type="number" aria-label="Price to apply to slots" v-model.number="directPriceInput" placeholder="Enter price"
               :class="{'price-err': priceInputError}"
               @input="priceInputError = false"
               style="width: 140px;" />
        <span v-if="priceInputError" class="price-err-msg">{{ priceErrorMsg }}</span>
        <span v-else class="price-hint">Paint the base price ({{ basePrice }}) to erase</span>
      </div>
    </div>

    <p class="pg-scroll-hint">Swipe across for all days. Tap a time to apply the selected price.</p>
    <div class="grid-wrap" @pointerup="onMouseUp" @pointerleave="onMouseUp" @pointercancel="onMouseUp">
      <div class="grid-head">
        <div></div>
        <div v-for="d in DAY_ORDER" :key="d" class="grid-day">
          {{ DAY_SHORT[d] }}
          <button type="button" class="fill-btn" @click="fillDay(d)" :aria-label="`Apply price to all ${DAY_NAME[d]} times`" title="Fill whole day">Apply all</button>
        </div>
      </div>
      <div v-for="slot in slots" :key="slot" class="grid-row">
        <div class="grid-time">{{ fmt12(slot) }}</div>
        <button type="button" v-for="d in DAY_ORDER" :key="d" class="grid-cell"
             :disabled="!slotInOpen(d, slot)"
             :aria-label="`${DAY_NAME[d]} ${fmt12(slot)}, ${slotInOpen(d, slot) ? currency + ' ' + (cellLabel(d, slot) || basePrice) : 'closed'}`"
             :class="{faded: isFaded(d, slot)}"
             :style="cellStyle(d, slot)"
             @pointerdown="startPointerPaint($event, d, slot)"
             @pointerenter="continuePointerPaint($event, d, slot)"
             @click="activateCell($event, d, slot)">
          {{ cellLabel(d, slot) }}
        </button>
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
.price-hint { color: var(--text-muted); font-size: 12px; }
.tool { display: inline-flex; align-items: center; gap: 6px; padding: 6px 10px; font-size: 12px; border: 1px solid var(--border); border-radius: 6px; background: var(--surface, #ffffff); color: var(--text); }
.tool.active { background: var(--primary); border-color: var(--primary); color: #fff; }
.price-input { display: flex; align-items: center; gap: 8px; font-size: 13px; }
.price-input label { color: var(--text-muted); }
.price-err { border-color: #b91c1c !important; background: var(--danger-bg, #fef2f2) !important; }
.price-err-msg { color: var(--danger-ink, #b91c1c); font-size: 12px; }
.price-hint { color: var(--text-muted); font-size: 12px; }

.groups-list { display: flex; flex-direction: column; gap: 14px; }
.group-card { border: 1px solid var(--border); border-radius: 8px; padding: 18px; background: var(--surface, #ffffff); cursor: pointer; transition: border-color .12s, box-shadow .12s; }
.group-card.active { border-color: var(--primary); box-shadow: 0 0 0 2px rgba(15,118,110,.12); }
.pg-paint-hint { font-size: 12.5px; color: var(--text-muted); margin-top: 4px; }
.clear-painted { margin-right: auto; }
.clear-btn { color: var(--error); }
.gc-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; }
.gc-title { display: flex; align-items: center; gap: 10px; flex: 1; min-width: 0; }
.gc-dot { width: 12px; height: 12px; border-radius: 50%; }
.gc-name-input { width: auto; min-width: 0; flex: 1; max-width: 240px; padding: 6px 10px; font-size: 15px; font-weight: 600; border: 1px solid transparent; background: transparent; }
.gc-name-input:hover, .gc-name-input:focus { border-color: var(--border); background: var(--surface, #ffffff); }
.icon-del { padding: 6px; border-radius: 6px; color: var(--error); }
.icon-del:hover { background: var(--danger-bg, #fee2e2); }
.day-chips { display: flex; gap: 8px; flex-wrap: wrap; }
.day-chip { padding: 6px 14px; font-size: 13px; font-weight: 500; border: 1px solid var(--border); border-radius: 999px; background: var(--surface, #ffffff); color: var(--text-muted); transition: all 0.15s; }
.day-chip:hover:not(.selected) { border-color: var(--primary); color: var(--primary-ink); }
.empty-groups { text-align: center; padding: 40px 20px; color: var(--text-muted); font-size: 14px; border: 1px dashed var(--border); border-radius: 8px; }

.grid-wrap { border: 1px solid var(--border); border-radius: 6px; overflow: auto; user-select: none; max-height: 480px; }
.grid-head, .grid-row { display: grid; grid-template-columns: 60px repeat(7, minmax(60px, 1fr)); }
.grid-head { background: var(--bg); position: sticky; top: 0; z-index: 2; }
.grid-day { padding: 8px; font-size: 12px; font-weight: 600; text-align: center; color: var(--text-muted); border-left: 1px solid var(--border); display: flex; flex-direction: column; align-items: center; gap: 2px; }
.fill-btn { font-size: 10px; color: var(--primary-ink); background: none; padding: 1px 4px; border-radius: 3px; }
.fill-btn:hover { background: var(--primary-soft, #ccfbf1); }
.grid-row { border-top: 1px solid var(--border); }
.grid-time { padding: 4px 8px; font-size: 11px; font-family: 'SF Mono', Monaco, monospace; color: var(--text-muted); background: var(--bg); text-align: right; position: sticky; left: 0; z-index: 1; }
.grid-cell { border: 0; border-radius: 0; border-left: 1px solid var(--border); padding: 0; min-height: 30px; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 500; cursor: pointer; transition: opacity 0.1s; }
.grid-cell:hover { opacity: 0.75; }
.grid-cell.faded { color: var(--text-muted) !important; font-weight: 400; }
.grid-cell.faded:hover { color: var(--primary-ink) !important; background: var(--primary-soft, #f0fdfa) !important; }
.day-chip.selected { color: var(--primary-ink); background: var(--primary-soft); border-color: var(--primary); }
.pg-scroll-hint { color: var(--text-muted); font-size: 12px; line-height: 1.6; }
.grid-head, .grid-row { min-width: 620px; }
.grid-cell:focus-visible { outline: 3px solid var(--primary); outline-offset: -3px; }
@media (max-width: 760px) {
  .pg { padding: 12px; }
  .pg-top { justify-content: space-between; flex-wrap: wrap; }
  .group-card { padding: 12px; }
  .gc-dot { flex-shrink: 0; }
  .pg :is(.vt-btn,.day-chip,.icon-del,.fill-btn) { min-height: 44px; }
  .icon-del { min-width: 44px; flex-shrink: 0; }
  .price-input { flex-wrap: wrap; min-width: 0; }
  .price-hint, .price-err-msg { flex-basis: 100%; }
  .grid-cell { min-height: 44px; }
  .grid-time { display: flex; align-items: center; font-size: 10px; padding: 4px; }
}
</style>
