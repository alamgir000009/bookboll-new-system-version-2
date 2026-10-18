<script setup>
import { ref, onMounted, computed, watch, nextTick } from 'vue'
import AdminAppDownloadButton from '../components/AdminAppDownloadButton.vue'
import { ChevronLeft, ChevronRight, Plus, CalendarDays, MapPin, X, Users, Mail, Phone, Clock, GraduationCap, GripVertical, Lock } from 'lucide-vue-next'
import { useCurrentUser, activeUserId } from '../lib/currentUser.js'
import { CAP } from '../lib/permissions.js'
import BookingDetailModal from '../components/BookingDetailModal.vue'
import NewBookingDrawer from '../components/NewBookingDrawer.vue'
import MultiSelect from '../components/MultiSelect.vue'
import { CALENDAR_GRANULARITIES, toMinutes, endMinutes, toTimeStr, fmt12, fmtRange12 } from '../lib/slots.js'
import { resolveSpecialDay } from '../lib/specialDays.js'
import { fetchCalendarDashboard } from '../lib/calendar.js'
import { CalendarX, CalendarClock } from 'lucide-vue-next'
import { createTouchReorder } from '../lib/touchReorder.js'
import { generateProgramSessions, windDownMap } from '../lib/programSessions.js'
import { pushToast } from '../lib/toast.js'
import { localCalendarDate as ymd } from '../lib/calendarDate.js'

const { isProviderScoped, currentOwnerUserId, can, scopeLocations, scopePrograms } = useCurrentUser()

const bookings  = ref([])
const listings  = ref([])
const locations = ref([])
const locationHours = ref([])   // [{location_id, day_of_week, is_open, open_time, close_time}] — for closed-slot shading
const specialDays = ref([])     // special_days rows for the shown venues (closures / custom hours)
const programs  = ref([])   // full recurrence — the source of truth for reserved slots
const programExceptions = ref(new Set())   // `${program_id}|${date}` one-off cancellations
const subscriptions = ref([])   // (program_id, status, next_billing_at) — for wind-down
const viewingBookingId = ref(null)
const selectedDate = ref(ymd(new Date()))
const view = ref('day')
const loading = ref(false)

async function load() {
  loading.value = true
  const range = visibleRangeForFetch()
  const res = await fetchCalendarDashboard({
    from: range.from,
    to: range.to,
  })
  loading.value = false

  if (!res.success) {
    pushToast(res.message || 'Could not load calendar.', 'error')
    bookings.value = []
    return
  }

  const d = res.data || {}
  locations.value = d.locations || []
  listings.value = d.listings || []
  bookings.value = d.bookings || []
  locationHours.value = d.location_hours || []
  specialDays.value = d.special_days || []
  programs.value = d.programs || []
  subscriptions.value = d.subscriptions || []
  programExceptions.value = new Set(
    (d.program_exceptions || [])
      .filter(r => r.program_id && r.booking_date)
      .map(r => `${r.program_id}|${r.booking_date}`)
  )

  // Provider scope: narrow to assigned venues, then cascade.
  if (isProviderScoped.value && currentOwnerUserId.value) {
    locations.value = scopeLocations(locations.value)
    const ownedLocIds = new Set(locations.value.map(l => l.id))
    bookings.value = bookings.value.filter(b => ownedLocIds.has(resolvedLocationId(b)))
    listings.value = listings.value.filter(l => ownedLocIds.has(l.location_id))
    programs.value = scopePrograms(programs.value.filter(p =>
      p.owner_user_id === currentOwnerUserId.value && ownedLocIds.has(p.location_id)))
  }
}

/** Visible date window for the current view (day / week / month). */
function visibleRangeForFetch() {
  if (view.value === 'week') {
    const d = new Date(selectedDate.value + 'T00:00:00')
    const monday = new Date(d)
    monday.setDate(d.getDate() - ((d.getDay() + 6) % 7))
    const sunday = new Date(monday)
    sunday.setDate(monday.getDate() + 6)
    return { from: ymd(monday), to: ymd(sunday) }
  }
  if (view.value === 'month') {
    const d = new Date(selectedDate.value + 'T00:00:00')
    const year = d.getFullYear(), month = d.getMonth()
    const firstDay = new Date(year, month, 1)
    const lastDay = new Date(year, month + 1, 0)
    const startPad = (firstDay.getDay() + 6) % 7
    const start = new Date(year, month, 1 - startPad)
    const endPad = (7 - ((lastDay.getDay() + 6) % 7) - 1 + 7) % 7
    const end = new Date(year, month, lastDay.getDate() + endPad)
    return { from: ymd(start), to: ymd(end) }
  }
  return { from: selectedDate.value, to: selectedDate.value }
}

// ── Quick add booking (from the "Quick Booking" button or a calendar slot) ───
const canBook = computed(() => can(CAP.MANAGE_BOOKINGS))
const showNewBooking = ref(false)
const presetVenueId   = ref('')
const presetListingId = ref('')
const presetDate      = ref('')
const presetStartTime = ref('')

function openQuickBooking() {
  presetVenueId.value = ''
  presetListingId.value = ''
  presetDate.value = selectedDate.value
  presetStartTime.value = ''
  showNewBooking.value = true
}
// Click an empty area of a day column → prefill venue, listing, date and the clicked time
function openSlotBooking(loc, evt) {
  if (!canBook.value) return
  const body = evt.currentTarget
  const rect = body.getBoundingClientRect()
  const offsetY = evt.clientY - rect.top
  let mins = dayBounds.value.start + Math.floor(offsetY / pxPerMin.value)
  mins = Math.round(mins / 15) * 15      // snap to 15-min boundary
  if (!isOpenAt(loc.id, mins)) {
    pushToast('This venue is closed at that time. Use “Quick Booking” to book outside opening hours.', 'error')
    return
  }
  presetVenueId.value = loc.id
  // Preselect the location's first listing as a sensible default (the user can
  // switch it in the form if the venue has several courts/listings).
  presetListingId.value = listingsOf(loc.id)[0]?.id || ''
  presetDate.value = selectedDate.value
  presetStartTime.value = toTimeStr(Math.max(dayBounds.value.start, Math.min(mins, dayBounds.value.end - 15)))
  showNewBooking.value = true
}
function onBookingSaved() {
  showNewBooking.value = false
  load()
}

// ── Program session drill-down: show subscribers/attendees ───────────────────
const sessionModal = ref(null)   // { bk, program, subscribers, loading }
async function openProgramSession(bk) {
  // Class programs / subscriptions are not on Laravel yet — show an empty sheet.
  sessionModal.value = {
    bk,
    program: programs.value.find(p => p.id === bk.program_id) || { name: bk.notes || 'Program' },
    subscribers: (subscriptions.value || []).filter(s =>
      s.program_id === bk.program_id && (s.status === 'active' || s.trial_status === 'in_trial')),
    loading: false,
  }
}
function closeSession() { sessionModal.value = null }

const canManagePrograms = computed(() => can(CAP.MANAGE_LISTINGS))
// Cancel a single occurrence — deferred until class programs live on Laravel.
async function cancelProgramSession() {
  pushToast('Program session cancel is not available until programs are migrated to Laravel.', 'error')
}

// Route a calendar click to the right modal: program block → session; else booking detail
function onEventClick(bk) {
  if (bk.origin === 'class_program' && bk.program_id) openProgramSession(bk)
  else viewingBookingId.value = bk.id
}
onMounted(load)
// Reload when the active user/business changes (demo role-switcher) so the
// calendar never shows another business's venues or bookings.
watch(currentOwnerUserId, load)
// Refetch when the visible date window changes (day / week / month navigation).
watch([selectedDate, view], () => { load() })

// Day-view time granularity (60 / 30 / 15 min). The grid always covers the full
// 24-hour day (00:00–24:00) so every slot is reachable; closed hours are shaded.
const dayGranularity = ref(60)
const DEFAULT_START_MIN = 0
const DEFAULT_END_MIN   = 24 * 60

const dayBounds = computed(() => {
  let start = DEFAULT_START_MIN
  let end   = DEFAULT_END_MIN
  for (const b of events.value) {
    if (b.booking_date !== selectedDate.value || !b.start_time) continue
    start = Math.min(start, Math.floor(toMinutes(b.start_time) / 60) * 60)
    end   = Math.max(end,   Math.ceil(endMinutes(b.end_time)  / 60) * 60)
  }
  return { start, end }
})

const daySlots = computed(() => {
  const step = dayGranularity.value
  const { start, end } = dayBounds.value
  const out = []
  for (let m = start; m < end; m += step) {
    out.push(`${String(Math.floor(m / 60)).padStart(2, '0')}:${String(m % 60).padStart(2, '0')}`)
  }
  return out
})
// kept for the week/month helpers that still reference hourly buckets
const hours = daySlots

function shift(days) {
  const d = new Date(selectedDate.value)
  d.setDate(d.getDate() + days)
  selectedDate.value = ymd(d)
}
function shiftWeek(weeks) {
  const d = new Date(selectedDate.value)
  d.setDate(d.getDate() + weeks * 7)
  selectedDate.value = ymd(d)
}
function shiftMonth(n) {
  const d = new Date(selectedDate.value + 'T00:00:00')
  d.setMonth(d.getMonth() + n, 1)
  selectedDate.value = ymd(d)
}

const formattedDate = computed(() =>
  new Date(selectedDate.value + 'T00:00:00').toLocaleDateString('en-US', {
    weekday: 'long', month: 'long', day: 'numeric', year: 'numeric',
  })
)
const weekDates = computed(() => {
  const d = new Date(selectedDate.value + 'T00:00:00')
  const monday = new Date(d)
  monday.setDate(d.getDate() - ((d.getDay() + 6) % 7))
  return Array.from({ length: 7 }, (_, i) => {
    const dd = new Date(monday)
    dd.setDate(monday.getDate() + i)
    return ymd(dd)
  })
})
const weekLabel = computed(() => {
  const [start, end] = [weekDates.value[0], weekDates.value[6]]
  const fmt = (d) => new Date(d + 'T00:00:00').toLocaleDateString('en-US', { month: 'short', day: 'numeric' })
  return `${fmt(start)} – ${fmt(end)}, ${new Date(end + 'T00:00:00').getFullYear()}`
})
const monthLabel = computed(() =>
  new Date(selectedDate.value + 'T00:00:00').toLocaleDateString('en-US', { month: 'long', year: 'numeric' })
)
const monthDates = computed(() => {
  const d = new Date(selectedDate.value + 'T00:00:00')
  const year = d.getFullYear(), month = d.getMonth()
  const firstDay = new Date(year, month, 1)
  const lastDay  = new Date(year, month + 1, 0)
  const startPad = (firstDay.getDay() + 6) % 7
  const days = []
  for (let i = -startPad; i < lastDay.getDate(); i++) {
    const dd = new Date(year, month, 1 + i)
    days.push({ date: ymd(dd), inMonth: i >= 0 })
  }
  while (days.length % 7 !== 0) {
    const last = new Date(days[days.length - 1].date + 'T00:00:00')
    last.setDate(last.getDate() + 1)
    days.push({ date: ymd(last), inMonth: false })
  }
  return days
})

function listingsOf(locationId) {
  return listings.value.filter(l => l.location_id === locationId)
}
function listingById(id) {
  return listings.value.find(l => l.id === id)
}
function resolvedLocationId(booking) {
  return booking.location_id || listingById(booking.listing_id)?.location_id
}

const programColorById = computed(() => {
  const m = {}
  for (const p of programs.value) if (p.color) m[p.id] = p.color
  return m
})

// ── Rule-based program sessions: derive for the visible range, merge with real
// bookings. No materialised rows, so reservations never lapse and always reflect
// the current schedule. ──
const visibleRange = computed(() => {
  if (view.value === 'week')  return { from: weekDates.value[0], to: weekDates.value[6] }
  if (view.value === 'month') return { from: monthDates.value[0].date, to: monthDates.value[monthDates.value.length - 1].date }
  return { from: selectedDate.value, to: selectedDate.value }
})
const windDownUntil = computed(() => windDownMap(subscriptions.value))
const derivedSessions = computed(() =>
  generateProgramSessions(programs.value, visibleRange.value.from, visibleRange.value.to, {
    listings: listings.value, exceptions: programExceptions.value, windDownUntil: windDownUntil.value,
  })
)
const events = computed(() => [...bookings.value, ...derivedSessions.value])
function bookingColor(booking) {
  if (booking.origin === 'class_program') return programColorById.value[booking.program_id] || '#d97706'
  const lst = listingById(booking.listing_id)
  if (lst?.booking_color) return lst.booking_color
  return { confirmed: '#3b82f6', completed: '#10b981', cancelled: '#94a3b8', pending: '#f59e0b' }[booking.booking_status] || '#64748b'
}

// Pick black or white text for legibility on a given background colour (WCAG-ish luminance).
function contrastText(hex) {
  if (!hex) return '#ffffff'
  let c = hex.replace('#', '')
  if (c.length === 3) c = c.split('').map(x => x + x).join('')
  const r = parseInt(c.slice(0, 2), 16)
  const g = parseInt(c.slice(2, 4), 16)
  const b = parseInt(c.slice(4, 6), 16)
  if ([r, g, b].some(Number.isNaN)) return '#ffffff'
  const lum = (0.299 * r + 0.587 * g + 0.114 * b) / 255
  return lum > 0.6 ? '#0f172a' : '#ffffff'
}
function bookingLabel(booking) {
  if (booking.origin === 'class_program') return booking.notes || 'Program Block'
  return listingById(booking.listing_id)?.name || '—'
}

// ── Day-view geometry ─────────────────────────────────────────────────────────
// Row height per granularity (px). Everything else is derived from this so a
// booking's block height is always proportional to its real duration.
const ROW_HEIGHTS = { 60: 64, 30: 46, 15: 34 }
const rowHeight      = computed(() => ROW_HEIGHTS[dayGranularity.value] || 64)
const pxPerMin       = computed(() => rowHeight.value / dayGranularity.value)
const dayPixelHeight = computed(() => (dayBounds.value.end - dayBounds.value.start) * pxPerMin.value)

// Lay out one location column: position each booking by start/end time and
// split overlapping bookings (e.g. two courts at the same hour) into side-by-side lanes.
// Beyond this many side-by-side lanes the blocks turn into unreadable slivers
// (measured: 10 same-slot bookings = 27px-wide blocks on a phone) — collapse the
// whole cluster into ONE counted block that opens a booking list instead.
const isNarrowCal = ref(typeof window !== 'undefined' && window.matchMedia('(max-width: 700px)').matches)
if (typeof window !== 'undefined') {
  window.matchMedia('(max-width: 700px)').addEventListener('change', e => { isNarrowCal.value = e.matches })
}
const maxLanes = computed(() => (isNarrowCal.value ? 2 : 3))

function columnLayout(locationId) {
  const ppm = pxPerMin.value
  const dayStart = dayBounds.value.start
  const items = events.value
    .filter(b => resolvedLocationId(b) === locationId && b.booking_date === selectedDate.value && b.start_time)
    .map(b => {
      const startM = toMinutes(b.start_time)
      const endM   = Math.max(endMinutes(b.end_time), startM + 15) // clamp tiny/zero durations
      return { bk: b, startM, endM, lane: 0 }
    })
    .sort((a, b) => a.startM - b.startM || a.endM - b.endM)

  const out = []
  let i = 0
  while (i < items.length) {
    // grow a cluster of transitively-overlapping bookings
    const cluster = [items[i]]
    let clusterEnd = items[i].endM
    let j = i + 1
    while (j < items.length && items[j].startM < clusterEnd) {
      cluster.push(items[j])
      clusterEnd = Math.max(clusterEnd, items[j].endM)
      j++
    }
    // assign each booking to the first free lane
    const laneEnds = []
    for (const it of cluster) {
      let placed = false
      for (let L = 0; L < laneEnds.length; L++) {
        if (laneEnds[L] <= it.startM) { it.lane = L; laneEnds[L] = it.endM; placed = true; break }
      }
      if (!placed) { it.lane = laneEnds.length; laneEnds.push(it.endM) }
    }
    const lanes = laneEnds.length
    if (lanes > maxLanes.value) {
      // Too crowded to read side-by-side → one aggregated block for the cluster.
      const startM = cluster[0].startM
      out.push({
        group: true,
        items: cluster.map(c => c.bk),
        startM, endM: clusterEnd,
        top: (startM - dayStart) * ppm,
        height: Math.max(28, (clusterEnd - startM) * ppm),
        leftPct: 0, widthPct: 100,
      })
    } else {
      for (const it of cluster) {
        out.push({
          bk: it.bk,
          top: (it.startM - dayStart) * ppm,
          height: Math.max(20, (it.endM - it.startM) * ppm),
          leftPct: (it.lane / lanes) * 100,
          widthPct: 100 / lanes,
        })
      }
    }
    i = j
  }
  return out
}

// The "N bookings" group block opens this chooser — pick one to see its detail.
const groupSheet = ref(null)   // { items: [bk...], label }
function openGroup(ev) {
  groupSheet.value = {
    items: ev.items,
    label: `${ev.items.length} bookings · ${fmtRange12(toTimeStr(ev.startM), toTimeStr(ev.endM))}`,
  }
}
function pickFromGroup(bk) { groupSheet.value = null; onEventClick(bk) }

// ── Operating hours → closed-slot shading (day view) ──────────────────────────
// location_hours holds one row per weekday (0=Sun..6=Sat) with open/close times.
// For the selected date's weekday, derive each venue's OPEN intervals; everything
// else in the day is "closed" and gets shaded + blocked for quick-click booking.
const selectedDow = computed(() => new Date(selectedDate.value + 'T00:00:00').getDay())

// Venue-wide special day for the selected date (listing-specific rows don't
// re-shape a whole venue column — they surface via the header chip instead).
const locationSpecials = computed(() => {
  const out = {}
  for (const loc of locations.value) {
    const sd = resolveSpecialDay(specialDays.value, {
      date: selectedDate.value, locationId: loc.id, listingId: null,
    })
    if (sd) out[loc.id] = sd
  }
  return out
})
// Listing-scoped special days on the selected date, per venue — for the chip.
function listingSpecialsAt(locId) {
  return specialDays.value.filter(r =>
    r.date === selectedDate.value && r.location_id === locId && r.listing_id)
}
// All special days on a date across the shown venues — month-view marker.
function specialsOn(date) {
  return specialDays.value.filter(r => r.date === date)
}

const openIntervalsByLoc = computed(() => {
  const map = {}
  for (const r of locationHours.value) {
    const lid = r.location_id
    if (!map[lid]) map[lid] = { configured: true, open: [] }   // this venue has hours set
    if (Number(r.day_of_week) !== selectedDow.value) continue
    if (r.is_open && r.open_time && r.close_time) {
      const s = toMinutes(r.open_time), e = endMinutes(r.close_time)
      if (e > s) map[lid].open.push({ startM: s, endM: e })
    }
  }
  // Special days override the weekly grid for their date: an off-day shades the
  // whole column; a custom window replaces the venue's open interval(s).
  for (const loc of locations.value) {
    const sd = locationSpecials.value[loc.id]
    if (!sd) continue
    map[loc.id] = sd.closed
      ? { configured: true, open: [] }
      : { configured: true, open: [{ startM: sd.openMins, endM: sd.closeMins }] }
  }
  return map
})
// Closed bands = the day minus the open intervals, per venue. A venue with NO hours
// configured at all is treated as always-open (nothing shaded).
function closedBands(locationId) {
  const info = openIntervalsByLoc.value[locationId]
  if (!info || !info.configured) return []
  const { start: dayStart, end: dayEnd } = dayBounds.value
  const ppm = pxPerMin.value
  const open = [...info.open].sort((a, b) => a.startM - b.startM)
  const bands = []
  let cursor = dayStart
  for (const iv of open) {
    const s = Math.max(iv.startM, dayStart), e = Math.min(iv.endM, dayEnd)
    if (s > cursor) bands.push({ startM: cursor, endM: s })
    cursor = Math.max(cursor, e)
  }
  if (cursor < dayEnd) bands.push({ startM: cursor, endM: dayEnd })
  return bands.map(b => ({ top: (b.startM - dayStart) * ppm, height: (b.endM - b.startM) * ppm }))
}
function isOpenAt(locationId, mins) {
  const info = openIntervalsByLoc.value[locationId]
  if (!info || !info.configured) return true
  return info.open.some(iv => mins >= iv.startM && mins < iv.endM)
}

// ── Auto-scroll the day grid to the first open hour ──────────────────────────
// The grid spans the full 24h so every slot is reachable, but that means it
// opens at 00:00 — a wall of shaded CLOSED night hours you must scroll past
// (painful on a phone). On load / date / view / granularity change, jump the
// scroll to the earliest configured opening time across the shown venues
// (fallback 7:00am when no hours are set) — the working day is what you land on.
const dayScrollEl = ref(null)
const firstOpenMinute = computed(() => {
  let earliest = null
  for (const loc of locations.value) {
    const info = openIntervalsByLoc.value[loc.id]
    if (!info || !info.configured || !info.open.length) continue
    const m = Math.min(...info.open.map(iv => iv.startM))
    earliest = earliest === null ? m : Math.min(earliest, m)
  }
  return earliest === null ? 7 * 60 : earliest
})
function scrollDayToOpen() {
  const el = dayScrollEl.value
  if (!el || view.value !== 'day') return
  el.scrollTop = Math.max(0, (firstOpenMinute.value - dayBounds.value.start) * pxPerMin.value - 8)
}
watch([view, selectedDate, dayGranularity, locations, firstOpenMinute], () => nextTick(scrollDayToOpen))
onMounted(() => nextTick(scrollDayToOpen))

// ── Slot hover indicator (day view) — highlight only the slot under the cursor ──
const hoverCol = ref(null)      // location id currently hovered
const hoverTop = ref(0)         // px offset of the hovered slot band
const hoverClosed = ref(false)  // hovered slot falls outside operating hours
function onColHover(loc, evt) {
  if (!canBook.value) { hoverCol.value = null; return }
  const rect = evt.currentTarget.getBoundingClientRect()
  const step = dayGranularity.value
  let mins = dayBounds.value.start + Math.floor((evt.clientY - rect.top) / pxPerMin.value)
  mins = Math.floor(mins / step) * step                 // snap to the slot row
  hoverCol.value = loc.id
  hoverTop.value = (mins - dayBounds.value.start) * pxPerMin.value
  hoverClosed.value = !isOpenAt(loc.id, mins)
}
function clearColHover() { hoverCol.value = null }

// ── Per-user column order (drag-and-drop, persisted in localStorage) ──────────
// Each admin/provider user keeps their own left-to-right ordering of venue columns.
const colOrderKey = computed(() => `bb_cal_col_order_${activeUserId.value || 'anon'}`)
// Venue filter — narrows the day/week columns (a super admin sees EVERY business's
// venues otherwise). Persisted per user beside the column order.
const venueFilterKey = computed(() => `bb_cal_venue_filter_${activeUserId.value || 'anon'}`)
const venueFilter = ref([])
function loadVenueFilter() {
  try { venueFilter.value = JSON.parse(localStorage.getItem(venueFilterKey.value) || '[]') || [] }
  catch { venueFilter.value = [] }
}
watch(activeUserId, loadVenueFilter, { immediate: true })
watch(venueFilter, v => { try { localStorage.setItem(venueFilterKey.value, JSON.stringify(v)) } catch {} }, { deep: true })
const venueOptions = computed(() => locations.value.map(l => ({ value: l.id, label: l.name })))
const savedColOrder = ref([])
function loadColOrder() {
  try { savedColOrder.value = JSON.parse(localStorage.getItem(colOrderKey.value) || '[]') || [] }
  catch { savedColOrder.value = [] }
}
watch(activeUserId, loadColOrder, { immediate: true })
const orderedLocations = computed(() => {
  const base = venueFilter.value.length
    ? locations.value.filter(l => venueFilter.value.includes(l.id))
    : locations.value
  if (!savedColOrder.value.length) return base
  const byId = new Map(base.map(l => [l.id, l]))
  const out = []
  for (const id of savedColOrder.value) { const l = byId.get(id); if (l) { out.push(l); byId.delete(id) } }
  for (const l of base) if (byId.has(l.id)) out.push(l)   // new venues fall to the end
  return out
})
const dragColId = ref(null)
const dragOverColId = ref(null)
function onColDragStart(loc, evt) {
  dragColId.value = loc.id
  if (evt.dataTransfer) { evt.dataTransfer.effectAllowed = 'move'; try { evt.dataTransfer.setData('text/plain', loc.id) } catch {} }
}
function onColDragOver(loc, evt) {
  if (dragColId.value == null) return
  evt.preventDefault()
  if (evt.dataTransfer) evt.dataTransfer.dropEffect = 'move'
  dragOverColId.value = loc.id
}
function onColDrop(loc) {
  const from = dragColId.value, to = loc.id
  dragColId.value = null; dragOverColId.value = null
  if (!from || from === to) return
  const ids = orderedLocations.value.map(l => l.id)
  const fromIdx = ids.indexOf(from), toIdx = ids.indexOf(to)
  if (fromIdx < 0 || toIdx < 0) return
  ids.splice(toIdx, 0, ids.splice(fromIdx, 1)[0])
  savedColOrder.value = ids
  try { localStorage.setItem(colOrderKey.value, JSON.stringify(ids)) } catch {}
}
function onColDragEnd() { dragColId.value = null; dragOverColId.value = null }
// Touch path for column reordering — HTML5 drag events never fire on touch
// screens, so the grip routes gestures through the same handlers.
const colTR = createTouchReorder({
  attr: 'data-tr-col',
  start: (e, id) => onColDragStart({ id }, e),
  over: (e, id) => onColDragOver({ id }, e),
  leave: () => { dragOverColId.value = null },
  drop: (e, id) => onColDrop({ id }),
  end: onColDragEnd,
})

function bookingsOnDay(locationId, date) {
  return events.value.filter(b =>
    resolvedLocationId(b) === locationId && b.booking_date === date
  )
}
function bookingsOnDate(date) {
  return events.value.filter(b => b.booking_date === date)
}
function isToday(date) {
  return date === ymd(new Date())
}

const STATUS_COLORS = { confirmed: '#3b82f6', completed: '#10b981', cancelled: '#94a3b8', pending: '#f59e0b' }
function statusLabel(s) {
  return { confirmed: 'Confirmed', completed: 'Done', cancelled: 'Cancelled', pending: 'Pending' }[s] || s
}

// Returns true when a booking has an outstanding / un-collected payment
function hasPendingPayment(bk) {
  if (bk.booking_status === 'cancelled') return false
  if (bk.payment_method === 'payment_skipped') return false
  // payment_status values: 'paid' = done; 'deposit_paid' = partial; null/'pending' = nothing collected
  const ps = bk.payment_status
  return !ps || ps === 'pending' || ps === 'deposit_paid'
}
</script>

<template>
  <div class="page">
    <div class="page-header">
      <div>
        <h1 class="page-title">Calendar</h1>
        <div class="page-subtitle">Booking schedule by location &amp; listing</div>
      </div>
      <div class="cal-head-actions">
        <AdminAppDownloadButton />
        <button v-if="canBook" class="btn btn-primary" @click="openQuickBooking"><Plus :size="16" />Quick Booking</button>
      </div>
    </div>

    <div class="card cal-card">
      <!-- Toolbar -->
      <div class="cal-toolbar">
        <div class="date-nav">
          <template v-if="view === 'day'">
            <button class="btn-icon" aria-label="Previous day" @click="shift(-1)"><ChevronLeft :size="18" /></button>
            <label class="date-label date-picker" title="Jump to a date">
              <CalendarDays :size="15" />{{ formattedDate }}
              <input type="date" v-model="selectedDate" class="date-picker-input" />
            </label>
            <button class="btn-icon" aria-label="Next day" @click="shift(1)"><ChevronRight :size="18" /></button>
          </template>
          <template v-else-if="view === 'week'">
            <button class="btn-icon" aria-label="Previous week" @click="shiftWeek(-1)"><ChevronLeft :size="18" /></button>
            <label class="date-label date-picker" title="Jump to a date">
              <CalendarDays :size="15" />{{ weekLabel }}
              <input type="date" v-model="selectedDate" class="date-picker-input" />
            </label>
            <button class="btn-icon" aria-label="Next week" @click="shiftWeek(1)"><ChevronRight :size="18" /></button>
          </template>
          <template v-else>
            <button class="btn-icon" aria-label="Previous month" @click="shiftMonth(-1)"><ChevronLeft :size="18" /></button>
            <label class="date-label date-picker" title="Jump to a date">
              <CalendarDays :size="15" />{{ monthLabel }}
              <input type="date" v-model="selectedDate" class="date-picker-input" />
            </label>
            <button class="btn-icon" aria-label="Next month" @click="shiftMonth(1)"><ChevronRight :size="18" /></button>
          </template>
        </div>
        <div class="cal-controls" style="display:flex;gap:10px;align-items:center;">
          <!-- Slot granularity — day view only -->
          <div v-if="view === 'day'" class="gran-toggle" title="Time slot size">
            <button
              v-for="g in CALENDAR_GRANULARITIES" :key="g.value"
              :class="{ active: dayGranularity === g.value }"
              @click="dayGranularity = g.value"
            >{{ g.label }}</button>
          </div>
          <MultiSelect v-if="venueOptions.length > 1" v-model="venueFilter" placeholder="All venues" :options="venueOptions" class="cal-venue-filter" />
          <div class="view-toggle">
            <button :class="{ active: view === 'day' }"   @click="view='day'">Day</button>
            <button :class="{ active: view === 'week' }"  @click="view='week'">Week</button>
            <button :class="{ active: view === 'month' }" @click="view='month'">Month</button>
          </div>
          <button class="btn btn-secondary" @click="selectedDate = ymd(new Date())">Today</button>
        </div>
      </div>

      <!-- ── DAY VIEW (positioned event blocks) ────────────────────────── -->
      <div v-if="view === 'day'" ref="dayScrollEl" class="cal-scroll">
        <div v-if="locations.length" class="day-grid" :class="`gran-${dayGranularity}`">
          <!-- Time gutter -->
          <div class="day-gutter">
            <div class="day-corner"></div>
            <div class="day-gutter-body" :style="{ height: dayPixelHeight + 'px' }">
              <div
                v-for="(h, idx) in hours" :key="h"
                class="day-gutter-slot"
                :class="{ 'is-hour': h.endsWith(':00') }"
                :style="{ top: (idx * rowHeight) + 'px', height: rowHeight + 'px' }"
              >{{ fmt12(h) }}</div>
            </div>
          </div>

          <!-- Location columns (drag a header to reorder — saved per user) -->
          <div class="day-cols">
            <div v-for="loc in orderedLocations" :key="loc.id" class="day-col" :data-tr-col="loc.id" :class="{ 'col-dragover': dragOverColId === loc.id, 'col-dragging': dragColId === loc.id }">
              <div
                class="day-col-head" draggable="true" title="Drag to reorder columns"
                @dragstart="onColDragStart(loc, $event)"
                @dragover="onColDragOver(loc, $event)"
                @drop="onColDrop(loc)"
                @dragend="onColDragEnd"
              >
                <span class="col-grip-tap"
                  @touchstart.stop="colTR.onTouchstart($event, loc.id)" @touchmove="colTR.onTouchmove($event)"
                  @touchend="colTR.onTouchend($event)" @touchcancel="colTR.onTouchend($event)"><GripVertical :size="13" class="col-grip" /></span>
                <MapPin :size="12" class="th-pin" />{{ loc.name }}
              </div>
              <!-- Special-day banner: venue closed / custom hours / listing-level overrides.
                   Zero-height sticky wrapper + absolute chip = the banner overlays the top
                   of the column WITHOUT shifting the body, so gridlines/bookings stay
                   aligned with the time gutter and the chip-less sibling columns. -->
              <div v-if="locationSpecials[loc.id] || listingSpecialsAt(loc.id).length" class="day-sd-wrap">
                <div class="day-sd-chip" :class="{ closed: locationSpecials[loc.id]?.closed }">
                  <component :is="locationSpecials[loc.id]?.closed ? CalendarX : CalendarClock" :size="11" />
                  <template v-if="locationSpecials[loc.id]?.closed">Closed — {{ locationSpecials[loc.id].name }}</template>
                  <template v-else-if="locationSpecials[loc.id]">
                    {{ locationSpecials[loc.id].name }} · {{ fmtRange12(toTimeStr(locationSpecials[loc.id].openMins), toTimeStr(locationSpecials[loc.id].closeMins)) }}
                  </template>
                  <template v-else>Special hours · {{ listingSpecialsAt(loc.id).length }} listing{{ listingSpecialsAt(loc.id).length > 1 ? 's' : '' }}</template>
                </div>
              </div>
              <div class="day-col-body" :class="{ 'is-bookable': canBook }" :style="{ height: dayPixelHeight + 'px' }" @click="openSlotBooking(loc, $event)" @mousemove="onColHover(loc, $event)" @mouseleave="clearColHover">
                <!-- closed-hours shading -->
                <div
                  v-for="(cb, ci) in closedBands(loc.id)" :key="'cb-' + ci"
                  class="day-closed-band" :style="{ top: cb.top + 'px', height: cb.height + 'px' }"
                ><span v-if="cb.height >= 24" class="day-closed-tag"><Lock :size="10" />Closed</span></div>
                <!-- hovered-slot highlight -->
                <div
                  v-if="hoverCol === loc.id"
                  class="day-hover-band" :class="{ closed: hoverClosed }"
                  :style="{ top: hoverTop + 'px', height: rowHeight + 'px' }"
                ></div>
                <!-- gridlines -->
                <div
                  v-for="(h, idx) in hours" :key="'gl-' + h"
                  class="day-gridline" :class="{ 'is-hour': h.endsWith(':00') }"
                  :style="{ top: (idx * rowHeight) + 'px' }"
                ></div>
                <!-- event blocks (crowded clusters collapse into one counted block) -->
                <template v-for="ev in columnLayout(loc.id)" :key="ev.group ? 'grp-' + ev.startM + '-' + ev.items.length : ev.bk.id">
                  <button
                    v-if="ev.group"
                    type="button"
                    class="day-event-group"
                    :style="{ top: ev.top + 'px', height: ev.height + 'px' }"
                    :title="`${ev.items.length} bookings — tap to list them`"
                    @click.stop="openGroup(ev)"
                  >
                    <span class="deg-count"><Users :size="12" /> {{ ev.items.length }} bookings</span>
                    <span class="deg-time">{{ fmtRange12(toTimeStr(ev.startM), toTimeStr(ev.endM)) }} · tap to view</span>
                  </button>
                  <div
                    v-else
                    class="day-event"
                    :style="{
                      top: ev.top + 'px', height: ev.height + 'px',
                      left: `calc(${ev.leftPct}% + 2px)`, width: `calc(${ev.widthPct}% - 4px)`,
                      '--c': bookingColor(ev.bk),
                      '--tc': contrastText(bookingColor(ev.bk)),
                    }"
                    :title="`${bookingLabel(ev.bk)} · ${fmtRange12(ev.bk.start_time, ev.bk.end_time)} · ${statusLabel(ev.bk.booking_status)}${hasPendingPayment(ev.bk) ? ' · ⚠ Payment pending' : ''}`"
                    @click.stop="onEventClick(ev.bk)"
                  >
                    <span class="day-event-inner">
                      <span class="day-event-name"><GraduationCap v-if="ev.bk.origin === 'class_program'" :size="11" class="ev-prog-icon" />{{ bookingLabel(ev.bk) }}</span>
                      <span class="day-event-time">{{ fmtRange12(ev.bk.start_time, ev.bk.end_time) }}</span>
                    </span>
                    <span v-if="hasPendingPayment(ev.bk)" class="pay-dot" title="Payment pending"></span>
                  </div>
                </template>
              </div>
            </div>
          </div>
        </div>
        <div v-else class="cal-empty-note">No active locations found.</div>
      </div>

      <!-- ── WEEK VIEW ─────────────────────────────────────────────────── -->
      <div v-else-if="view === 'week'" class="week-scroll">
        <table class="week-table">
          <thead>
            <tr>
              <th class="week-th-loc">Location</th>
              <th
                v-for="d in weekDates" :key="d"
                class="week-th-day" :class="{ today: isToday(d) }"
              >
                <div class="week-day-name">{{ new Date(d + 'T00:00:00').toLocaleDateString('en-US', { weekday: 'short' }) }}</div>
                <div class="week-day-num" :class="{ today: isToday(d) }">{{ new Date(d + 'T00:00:00').getDate() }}</div>
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="loc in orderedLocations" :key="loc.id">
              <td class="week-td-loc">
                <div class="wk-loc-name"><MapPin :size="11" />{{ loc.name }}</div>
              </td>
              <td v-for="d in weekDates" :key="d" class="week-td-day" :class="{ today: isToday(d) }">
                <div
                  v-for="bk in bookingsOnDay(loc.id, d)" :key="bk.id"
                  class="week-chip"
                  :style="{ '--c': bookingColor(bk), '--tc': contrastText(bookingColor(bk)) }"
                  :title="`${bookingLabel(bk)} · ${fmtRange12(bk.start_time, bk.end_time)}${hasPendingPayment(bk) ? ' · ⚠ Payment pending' : ''}`"
                  @click.stop="onEventClick(bk)"
                >
                  <span class="wk-inner">
                    <span class="wk-name"><GraduationCap v-if="bk.origin === 'class_program'" :size="10" class="ev-prog-icon" />{{ bookingLabel(bk) }}</span>
                    <span class="wk-time">{{ fmt12(bk.start_time) }}</span>
                  </span>
                  <span v-if="hasPendingPayment(bk)" class="pay-dot wk-pay-dot" title="Payment pending"></span>
                </div>
              </td>
            </tr>
            <tr v-if="!locations.length">
              <td colspan="8" class="no-data">No active locations found.</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- ── MONTH VIEW ────────────────────────────────────────────────── -->
      <div v-else class="month-wrap">
        <div class="month-grid">
          <div v-for="day in ['Mon','Tue','Wed','Thu','Fri','Sat','Sun']" :key="day" class="month-dow">{{ day }}</div>
          <div
            v-for="cell in monthDates" :key="cell.date"
            class="month-cell"
            :class="{ 'other-month': !cell.inMonth, today: isToday(cell.date) }"
            @click="selectedDate = cell.date; view = 'day'"
          >
            <div class="month-day-num">{{ new Date(cell.date + 'T00:00:00').getDate() }}</div>
            <div v-if="specialsOn(cell.date).length" class="month-sd"
              :class="{ closed: specialsOn(cell.date).some(r => r.is_closed) }"
              :title="specialsOn(cell.date).map(r => r.name || (r.is_closed ? 'Closed' : 'Special hours')).join(', ')">
              <component :is="specialsOn(cell.date).some(r => r.is_closed) ? CalendarX : CalendarClock" :size="10" />
              <span class="month-sd-label">{{ specialsOn(cell.date)[0].name || (specialsOn(cell.date)[0].is_closed ? 'Closed' : 'Special') }}</span>
            </div>
            <div class="month-events">
              <div
                v-for="bk in bookingsOnDate(cell.date).slice(0, 4)" :key="bk.id"
                class="month-event"
                :style="{ '--c': bookingColor(bk) }"
                :title="`${bookingLabel(bk)} · ${fmt12(bk.start_time)}${hasPendingPayment(bk) ? ' · ⚠ Payment pending' : ''}`"
                @click.stop="onEventClick(bk)"
              >
                <GraduationCap v-if="bk.origin === 'class_program'" :size="10" class="me-prog-icon" />
                <span v-else class="me-dot"></span>
                <span class="me-label">{{ bookingLabel(bk) }}</span>
                <span v-if="hasPendingPayment(bk)" class="me-pay-dot" title="Payment pending"></span>
              </div>
              <div v-if="bookingsOnDate(cell.date).length > 4" class="month-more">
                +{{ bookingsOnDate(cell.date).length - 4 }} more
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- crowded-slot chooser: the "N bookings" block lists its bookings here -->
      <div v-if="groupSheet" class="cal-group-back" @click.self="groupSheet = null">
        <div class="cal-group-sheet" v-dialog="() => groupSheet = null" :aria-label="groupSheet.label">
          <div class="cal-group-head">
            <b>{{ groupSheet.label }}</b>
            <button class="cal-group-x" @click="groupSheet = null" aria-label="Close"><X :size="18" /></button>
          </div>
          <div class="cal-group-list">
            <button v-for="bk in groupSheet.items" :key="bk.id" type="button" class="cal-group-row" @click="pickFromGroup(bk)">
              <span class="cgr-dot" :style="{ background: bookingColor(bk) }"></span>
              <span class="cgr-main">
                <span class="cgr-name">{{ bookingLabel(bk) }}</span>
                <span class="cgr-sub">{{ fmtRange12(bk.start_time, bk.end_time) }} · {{ statusLabel(bk.booking_status) }}</span>
              </span>
              <span v-if="hasPendingPayment(bk)" class="cgr-pay">⚠ payment</span>
            </button>
          </div>
        </div>
      </div>

      <BookingDetailModal
        :bookingId="viewingBookingId"
        @close="viewingBookingId = null"
        @viewBooking="viewingBookingId = $event"
      />

      <!-- Quick-add booking drawer (from Quick Booking button or a calendar slot) -->
      <NewBookingDrawer
        :open="showNewBooking"
        :presetVenueId="presetVenueId"
        :presetListingId="presetListingId"
        :presetDate="presetDate"
        :presetStartTime="presetStartTime"
        @close="showNewBooking = false"
        @saved="onBookingSaved"
      />

      <!-- Program session → subscribers/attendees -->
      <Teleport to="body">
        <div v-if="sessionModal" class="sess-backdrop" @click.self="closeSession">
          <div class="sess-panel" v-dialog>
            <div class="sess-header">
              <div>
                <div class="sess-title"><GraduationCap :size="16" />{{ sessionModal.program?.name || sessionModal.bk.notes || 'Program Session' }}</div>
                <div class="sess-sub">
                  <Clock :size="12" />{{ fmtRange12(sessionModal.bk.start_time, sessionModal.bk.end_time) }}
                  · {{ new Date(sessionModal.bk.booking_date + 'T00:00:00').toLocaleDateString('en-US',{weekday:'short',month:'short',day:'numeric'}) }}
                </div>
              </div>
              <button class="sess-close" @click="closeSession" aria-label="Close"><X :size="18" /></button>
            </div>
            <div class="sess-body">
              <div v-if="sessionModal.loading" class="sess-empty">Loading attendees…</div>
              <template v-else>
                <div class="sess-count">
                  <Users :size="14" />
                  <strong>{{ sessionModal.subscribers.length }}</strong> attending
                  <span v-if="sessionModal.program?.capacity"> / {{ sessionModal.program.capacity }} capacity</span>
                </div>
                <div v-if="!sessionModal.subscribers.length" class="sess-empty">No active members for this program yet.</div>
                <div v-else class="sess-list">
                  <div v-for="s in sessionModal.subscribers" :key="s.id" class="sess-row">
                    <div class="sess-avatar">{{ (s.customer_name || s.customer_email || '?')[0].toUpperCase() }}</div>
                    <div class="sess-info">
                      <div class="sess-name">{{ s.customer_name || '—' }}</div>
                      <div class="sess-contact">
                        <span v-if="s.customer_email"><Mail :size="11" />{{ s.customer_email }}</span>
                      </div>
                    </div>
                    <span class="sess-badge" :class="s.trial_status === 'in_trial' ? 'badge-warning' : 'badge-success'">
                      {{ s.trial_status === 'in_trial' ? 'In trial' : 'Active' }}
                    </span>
                  </div>
                </div>
                <a v-if="sessionModal.program" :href="`/memberships?edit=${sessionModal.program.id}`" class="sess-link" @click="closeSession">
                  Open in Membership Programs →
                </a>
                <button v-if="canManagePrograms" class="sess-cancel-btn" @click="cancelProgramSession">
                  <X :size="14" /> Cancel this session
                </button>
              </template>
            </div>
          </div>
        </div>
      </Teleport>

      <div v-if="listings.length" class="legend">
        <div v-for="lst in listings" :key="lst.id" class="legend-item">
          <span class="leg-dot" :style="{ background: lst.booking_color || '#94a3b8' }"></span>
          {{ lst.name }}
        </div>
        <div class="legend-item">
          <span class="leg-dot" style="background:#d97706"></span>
          Membership Program Block
        </div>
        <div class="legend-sep"></div>
        <div class="legend-item">
          <span class="leg-pay-dot"></span>
          Payment pending
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.cal-head-actions { display: flex; flex-wrap: wrap; gap: 10px; }
@media (max-width: 600px) { .cal-head-actions { width: 100%; }.cal-head-actions :deep(.btn) { flex: 1 1 auto; justify-content: center; } }
/* ── Shared ───────────────────────────────────────────────────────────────── */
.cal-card { padding: 0; overflow: hidden; }

/* ── Toolbar ──────────────────────────────────────────────────────────────── */
.cal-toolbar {
  padding: 14px 20px; border-bottom: 1px solid var(--border);
  display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;
}
.date-nav { display: flex; align-items: center; gap: 8px; }
.date-label {
  font-weight: 600; font-size: 15px;
  display: flex; align-items: center; gap: 7px; padding: 0 10px;
}
/* Clickable date label that opens a native date picker */
.date-picker {
  position: relative; cursor: pointer; border-radius: 8px;
  min-width: 180px; justify-content: center; transition: background .12s;
}
.date-picker:hover { background: var(--bg); }
.date-picker-input {
  position: absolute; inset: 0; width: 100%; height: 100%;
  opacity: 0; cursor: pointer; border: 0; padding: 0;
}
/* hide the native text but keep the picker indicator clickable across the label */
.date-picker-input::-webkit-calendar-picker-indicator {
  position: absolute; inset: 0; width: 100%; height: 100%; margin: 0; cursor: pointer;
}
.view-toggle {
  display: flex; background: var(--bg); border-radius: 8px;
  padding: 3px; border: 1px solid var(--border);
}
.view-toggle button {
  padding: 6px 14px; border-radius: 6px;
  font-size: 13px; font-weight: 500; color: var(--text-muted);
}
.view-toggle button.active { background: var(--surface, #ffffff); color: var(--text); box-shadow: var(--shadow-sm); }
.btn-icon { padding: 8px; border-radius: 8px; color: var(--text-muted); }
.btn-icon:hover { background: var(--bg); }

/* Granularity toggle (day view) */
.gran-toggle { display: flex; background: var(--bg); border-radius: 8px; padding: 3px; border: 1px solid var(--border); }
.gran-toggle button { padding: 6px 11px; border-radius: 6px; font-size: 12.5px; font-weight: 500; color: var(--text-muted); }
.gran-toggle button.active { background: var(--surface, #ffffff); color: var(--primary-ink); box-shadow: var(--shadow-sm); }

/* Toolbar controls wrap on phones instead of overflowing off-screen. */
@media (max-width: 600px) {
  .cal-toolbar { padding: 12px 14px; }
  .cal-controls { flex-wrap: wrap; gap: 8px; }
  .date-picker { min-width: 150px; }
}

/* ── Day view — table grid ────────────────────────────────────────────────── */
.cal-scroll { overflow-x: auto; overflow-y: auto; max-height: 78vh; }
.cal-table { border-collapse: collapse; width: 100%; min-width: max-content; }

/* Sticky header row */
.cal-th-gutter {
  width: 72px; min-width: 72px;
  background: var(--bg); border-bottom: 2px solid var(--border);
  position: sticky; top: 0; z-index: 20;
}
.cal-th-loc {
  min-width: 210px; padding: 10px 14px;
  text-align: left; vertical-align: top;
  background: var(--bg);
  border-left: 1px solid var(--border);
  border-bottom: 2px solid var(--border);
  position: sticky; top: 0; z-index: 20;
}
.th-loc-name {
  font-weight: 700; font-size: 13px;
  display: flex; align-items: center; gap: 5px;
}
.th-pin { color: var(--primary-ink); flex-shrink: 0; }
.th-listings { margin-top: 6px; display: flex; flex-wrap: wrap; gap: 4px 10px; }
.th-lst { display: flex; align-items: center; gap: 4px; font-size: 11px; color: var(--text-muted); }
.th-dot { width: 7px; height: 7px; border-radius: 50%; flex-shrink: 0; }

/* Sticky time gutter */
.cal-td-gutter {
  width: 72px; min-width: 72px;
  padding: 0 12px; padding-top: 6px;
  font-size: 11px; font-weight: 500; color: var(--text-muted);
  vertical-align: top; white-space: nowrap;
  border-bottom: 1px solid var(--border);
  background: var(--bg);
  position: sticky; left: 0; z-index: 10;
}

/* Slot cells — fixed height so all rows stay aligned */
.cal-td-slot {
  height: 64px;
  padding: 3px 4px;
  vertical-align: top;
  border-left: 1px solid var(--border);
  border-bottom: 1px solid var(--border);
  transition: background 0.1s;
}
.cal-td-slot:hover { background: var(--surface-hover, #f8fafc); }
/* Finer granularity → shorter rows so the day fits comfortably */
.cal-table.gran-30 .cal-td-slot,
.cal-table.gran-30 .cal-td-gutter { height: 46px; }
.cal-table.gran-15 .cal-td-slot,
.cal-table.gran-15 .cal-td-gutter { height: 34px; }
.cal-table.gran-15 .cal-td-gutter,
.cal-table.gran-30 .cal-td-gutter { font-size: 10px; padding-top: 3px; }
.cal-td-empty {
  border-left: 1px solid var(--border);
  border-bottom: 1px solid var(--border);
  color: var(--text-muted); font-size: 13px;
  padding: 20px; text-align: center;
}

/* Chips side by side, scrolling horizontally when many bookings */
.slot-chips {
  display: flex;
  flex-direction: row;
  flex-wrap: nowrap;
  gap: 3px;
  height: 100%;
  align-items: stretch;
  overflow-x: auto;
}
.slot-chips::-webkit-scrollbar { height: 3px; }
.slot-chips::-webkit-scrollbar-thumb { background: var(--border, #e5e7eb); border-radius: 2px; }

/* Booking chip: white card with colored left accent */
.bk-chip {
  display: flex;
  flex: 1;
  min-width: 100px;
  flex-shrink: 0;
  border-radius: 5px;
  overflow: hidden;
  cursor: pointer;
  background: var(--surface, #ffffff);
  border: 1px solid var(--border, #e5e7eb);
  transition: box-shadow 0.12s;
  position: relative;
}
.bk-chip:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.13); }
.bk-bar { width: 4px; flex-shrink: 0; background: var(--c); }
.bk-inner {
  flex: 1; min-width: 0;
  padding: 3px 5px;
  display: flex; flex-direction: column; justify-content: center;
}
.bk-name {
  font-size: 11px; font-weight: 600; color: var(--text);
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.bk-time { font-size: 10px; color: var(--text-muted); white-space: nowrap; margin-top: 1px; }

/* Payment-pending indicator dot — day & week chips */
.pay-dot {
  width: 8px; height: 8px; border-radius: 50%;
  background: #f59e0b;
  flex-shrink: 0;
  align-self: flex-start;
  margin: 4px 4px 0 0;
  box-shadow: 0 0 0 1.5px #fff;
}
.wk-pay-dot {
  margin: 0 4px 0 2px;
  align-self: center;
}

/* ── Day view: positioned event grid ──────────────────────────────────────── */
.day-grid { display: flex; min-width: max-content; }

/* Time gutter */
.day-gutter {
  position: sticky; left: 0; z-index: 6;
  width: 64px; min-width: 64px; flex-shrink: 0; background: var(--surface, #ffffff);
  border-right: 1px solid var(--border);
}
.day-corner {
  height: 40px; background: var(--bg);
  border-bottom: 2px solid var(--border);
  position: sticky; top: 0; z-index: 7;
}
.day-gutter-body { position: relative; }
.day-gutter-slot {
  position: absolute; left: 0; right: 0;
  padding: 2px 8px 0 0; text-align: right;
  font-size: 11px; color: var(--text-muted); white-space: nowrap;
}
.day-gutter-slot.is-hour { font-weight: 600; color: var(--text); }
.cal-table.gran-15 .day-gutter-slot, .day-gutter-slot { font-size: 10.5px; }

/* Columns */
.day-cols { display: flex; flex: 1; }
.day-col {
  flex: 1; min-width: 200px;
  border-left: 1px solid var(--border);
}
.day-col-head {
  height: 40px; display: flex; align-items: center; gap: 5px;
  padding: 0 12px; font-weight: 700; font-size: 13px;
  background: var(--bg); border-bottom: 2px solid var(--border);
  position: sticky; top: 0; z-index: 5; white-space: nowrap;
  cursor: grab; user-select: none;
}
.day-col-head:active { cursor: grabbing; }
.col-grip { color: var(--text-light, #cbd5e1); flex-shrink: 0; }
.col-grip-tap { display: inline-flex; align-items: center; padding: 6px 4px; margin: -6px -2px; touch-action: none; }
.day-col-head:hover .col-grip { color: var(--text-light, #94a3b8); }
.day-col.col-dragging { opacity: .5; }
.day-col.col-dragover .day-col-head { box-shadow: inset 3px 0 0 var(--primary); background: var(--info-bg, #eef2ff); }
.day-col-body { position: relative; }
.day-col-body.is-bookable { cursor: copy; }

/* Crowded slot: one counted block instead of unreadable slivers */
.day-event-group {
  position: absolute; left: 2px; right: 2px; z-index: 2;
  display: flex; flex-direction: column; justify-content: center; gap: 2px;
  padding: 4px 10px; border-radius: 8px; cursor: pointer; overflow: hidden;
  background: repeating-linear-gradient(45deg, var(--info-bg, #eef2ff), var(--info-bg, #eef2ff) 8px, #e0e7ff 8px, #e0e7ff 16px);
  border: 1.5px solid #a5b4fc; color: var(--purple-ink, #3730a3); text-align: left;
}
.day-event-group:hover { border-color: #6366f1; }
.deg-count { display: inline-flex; align-items: center; gap: 5px; font-size: 12.5px; font-weight: 800; }
.deg-time { font-size: 11px; font-weight: 600; opacity: .8; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

/* Chooser sheet for a crowded slot's bookings */
.cal-group-back { position: fixed; inset: 0; background: rgba(15,23,42,.5); display: flex; align-items: center; justify-content: center; z-index: 70; padding: 20px; }
.cal-group-sheet { background: var(--surface, #ffffff); border-radius: 16px; width: 100%; max-width: 420px; max-height: 80vh; display: flex; flex-direction: column; box-shadow: 0 20px 50px rgba(0,0,0,.25); }
.cal-group-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 16px 18px; border-bottom: 1px solid var(--border); font-size: 15px; }
.cal-group-x { display: inline-flex; padding: 6px; border-radius: 8px; color: var(--text-muted); }
.cal-group-x:hover { background: var(--bg); color: var(--text); }
.cal-group-list { overflow-y: auto; -webkit-overflow-scrolling: touch; padding: 8px; display: flex; flex-direction: column; gap: 4px; }
.cal-group-row { display: flex; align-items: center; gap: 10px; width: 100%; text-align: left; padding: 11px 12px; border-radius: 10px; background: none; border: none; cursor: pointer; min-height: 48px; }
.cal-group-row:hover { background: var(--bg); }
.cgr-dot { width: 10px; height: 10px; border-radius: 3px; flex-shrink: 0; }
.cgr-main { flex: 1; min-width: 0; display: flex; flex-direction: column; }
.cgr-name { font-size: 14px; font-weight: 700; color: var(--text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.cgr-sub { font-size: 12px; color: var(--text-muted); }
.cgr-pay { font-size: 11px; font-weight: 700; color: var(--warning-ink, #b45309); background: var(--warning-bg, #fef3c7); padding: 3px 8px; border-radius: 999px; white-space: nowrap; }
@media (max-width: 640px) {
  /* Phone: chooser becomes a bottom sheet */
  .cal-group-back { align-items: flex-end; padding: 0; }
  .cal-group-sheet { max-width: none; border-radius: 18px 18px 0 0; max-height: 75dvh; padding-bottom: env(safe-area-inset-bottom); }
}

/* Special-day banner under a column head (custom hours = indigo, closed = red).
   The wrapper is sticky but ZERO-HEIGHT (no layout space), and the chip hangs
   from it absolutely — the column body below keeps the exact same y-origin as
   the time gutter and chip-less columns. */
.day-sd-wrap { position: sticky; top: 40px; height: 0; z-index: 4; }
.day-sd-chip {
  position: absolute; top: 0; left: 0; right: 0;
  display: flex; align-items: center; gap: 5px; padding: 4px 10px;
  font-size: 11px; font-weight: 700; color: var(--purple-ink, #4338ca); background: var(--info-bg, #eef2ff);
  border-bottom: 1px solid var(--purple-border, #c7d2fe);
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.day-sd-chip.closed { color: var(--danger-ink, #b91c1c); background: var(--danger-bg, #fef2f2); border-bottom-color: var(--danger-border, #fecaca); }

/* Month-view special-day marker */
.month-sd {
  display: inline-flex; align-items: center; gap: 3px; max-width: 100%;
  padding: 1px 5px; margin-top: 2px; border-radius: 5px;
  font-size: 10px; font-weight: 700; color: var(--purple-ink, #4338ca); background: var(--info-bg, #eef2ff);
}
.month-sd.closed { color: var(--danger-ink, #b91c1c); background: var(--danger-bg, #fef2f2); }
.month-sd-label { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
/* Only the hovered slot lights up (see .day-hover-band), not the whole column. */

/* Closed-hours shading — outside the venue's operating hours for this weekday */
.day-closed-band {
  position: absolute; left: 0; right: 0; z-index: 1; pointer-events: none;
  background: repeating-linear-gradient(135deg, rgba(148,163,184,.10) 0 8px, rgba(148,163,184,.16) 8px 16px);
  border-top: 1px dashed rgba(148,163,184,.5);
}
.day-closed-tag {
  position: absolute; top: 3px; left: 6px; display: inline-flex; align-items: center; gap: 3px;
  font-size: 9.5px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase;
  color: var(--text-light, #94a3b8);
}
/* Hovered-slot highlight band (follows the cursor, snapped to the slot) */
.day-hover-band {
  position: absolute; left: 0; right: 0; z-index: 1; pointer-events: none;
  background: rgba(16,185,129,.10); border: 1px solid rgba(16,185,129,.35);
}
.day-hover-band.closed { background: rgba(148,163,184,.14); border-color: rgba(148,163,184,.5); }

/* Program session modal */
.sess-backdrop { position: fixed; inset: 0; background: rgba(15,23,42,.45); z-index: 1000; display: flex; justify-content: flex-end; backdrop-filter: blur(2px); }
.sess-panel { width: 100%; max-width: 440px; height: 100%; background: var(--surface, #ffffff); display: flex; flex-direction: column; box-shadow: -8px 0 40px rgba(0,0,0,.18); animation: slideInRight .22s cubic-bezier(.22,1,.36,1); }
@keyframes slideInRight { from { transform: translateX(40px); opacity: 0 } to { transform: translateX(0); opacity: 1 } }
.sess-header { display: flex; align-items: flex-start; justify-content: space-between; padding: 18px 20px 14px; border-bottom: 1px solid var(--border); }
.sess-title { font-size: 16px; font-weight: 700; display: flex; align-items: center; gap: 7px; }
.sess-sub { font-size: 12.5px; color: var(--text-muted); display: flex; align-items: center; gap: 5px; margin-top: 4px; }
.sess-close { width: 32px; height: 32px; border-radius: 8px; border: none; background: var(--bg, #f1f5f9); display: flex; align-items: center; justify-content: center; cursor: pointer; color: var(--text-muted, #64748b); }
.sess-close:hover { background: var(--border, #e2e8f0); }
.sess-body { flex: 1; overflow-y: auto; padding: 16px 20px; }
.sess-count { display: flex; align-items: center; gap: 6px; font-size: 13px; color: var(--text); margin-bottom: 14px; }
.sess-empty { color: var(--text-muted); font-size: 13px; padding: 20px 0; text-align: center; }
.sess-list { display: flex; flex-direction: column; gap: 8px; }
.sess-row { display: flex; align-items: center; gap: 10px; padding: 10px 12px; background: var(--bg); border-radius: 10px; }
.sess-avatar { width: 34px; height: 34px; border-radius: 50%; background: #0f172a; color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 12px; flex-shrink: 0; }
.sess-info { flex: 1; min-width: 0; }
.sess-name { font-size: 13.5px; font-weight: 600; }
.sess-contact { font-size: 11.5px; color: var(--text-muted); display: flex; align-items: center; gap: 4px; }
.sess-contact span { display: inline-flex; align-items: center; gap: 3px; }
.sess-badge { font-size: 10.5px; font-weight: 600; padding: 2px 8px; border-radius: 99px; }
.sess-link { display: inline-block; margin-top: 14px; font-size: 13px; font-weight: 600; color: var(--primary-ink); }
.sess-cancel-btn { display: flex; align-items: center; gap: 6px; margin-top: 12px; padding: 8px 12px; width: 100%; justify-content: center; border: 1px solid var(--danger-border, #fecaca); background: var(--danger-bg, #fef2f2); color: var(--danger-ink, #dc2626); border-radius: 9px; font-size: 13px; font-weight: 600; cursor: pointer; transition: background .12s; }
.sess-cancel-btn:hover { background: var(--danger-bg, #fee2e2); }

/* Gridlines */
.day-gridline {
  position: absolute; left: 0; right: 0; height: 0;
  border-top: 1px solid var(--border, #f1f5f9);
}
.day-gridline.is-hour { border-top-color: var(--border); }

/* Event block — full booking colour, text auto-contrasted, height ∝ duration */
.day-event {
  position: absolute; overflow: hidden;
  border-radius: 6px; cursor: pointer;
  background: var(--c); color: var(--tc);
  border: 1px solid rgba(0,0,0,.08);
  display: flex; box-shadow: 0 1px 2px rgba(0,0,0,.10);
  transition: box-shadow .12s, filter .12s; z-index: 2;
}
.day-event:hover { box-shadow: 0 3px 12px rgba(0,0,0,.22); filter: brightness(.95); z-index: 3; }
.day-event-inner {
  flex: 1; min-width: 0; padding: 3px 7px;
  display: flex; flex-direction: column; gap: 1px; overflow: hidden;
}
.day-event-name {
  font-size: 11.5px; font-weight: 700; color: var(--tc);
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.day-event-time { font-size: 10.5px; color: var(--tc); opacity: .82; white-space: nowrap; }
.day-event .pay-dot {
  position: absolute; top: 4px; right: 4px;
  width: 8px; height: 8px; border-radius: 50%;
  background: #f59e0b; box-shadow: 0 0 0 1.5px var(--c);
}
.cal-empty-note { padding: 40px; text-align: center; color: var(--text-muted); font-size: 14px; }

/* ── Week view ────────────────────────────────────────────────────────────── */
.week-scroll { overflow-x: auto; }
.week-table { width: 100%; border-collapse: collapse; }
.week-th-loc {
  padding: 10px 14px; background: var(--bg);
  border-bottom: 2px solid var(--border); border-right: 1px solid var(--border);
  font-size: 12px; color: var(--text-muted); font-weight: 600;
  text-transform: uppercase; letter-spacing: .04em;
  min-width: 140px; text-align: left;
}
.week-th-day {
  padding: 10px 8px; background: var(--bg);
  border-bottom: 2px solid var(--border); border-right: 1px solid var(--border);
  text-align: center; min-width: 110px;
}
.week-th-day.today { background: var(--positive-bg, #f0fdf4); }
.week-day-name { font-size: 11px; color: var(--text-muted); font-weight: 600; text-transform: uppercase; }
.week-day-num {
  font-size: 18px; font-weight: 700; color: var(--text);
  width: 32px; height: 32px;
  display: flex; align-items: center; justify-content: center;
  margin: 4px auto 0; border-radius: 50%;
}
.week-day-num.today { background: var(--primary); color: #fff; }
.week-td-loc {
  padding: 10px 14px;
  border-bottom: 1px solid var(--border); border-right: 1px solid var(--border);
  background: var(--bg); vertical-align: top;
}
.wk-loc-name { display: flex; align-items: center; gap: 5px; font-size: 12.5px; font-weight: 600; white-space: nowrap; }
.week-td-day {
  padding: 4px 5px;
  border-bottom: 1px solid var(--border); border-right: 1px solid var(--border);
  vertical-align: top; min-height: 64px;
}
.week-td-day.today { background: var(--primary-soft); }

.week-chip {
  display: flex; align-items: stretch; position: relative;
  border-radius: 5px; overflow: hidden;
  cursor: pointer; margin-bottom: 3px;
  background: var(--c); color: var(--tc); border: 1px solid rgba(0,0,0,.08);
  transition: box-shadow 0.1s, filter .12s;
}
.week-chip:hover { box-shadow: 0 1px 6px rgba(0,0,0,0.2); filter: brightness(.95); }
.wk-inner { display: flex; justify-content: space-between; align-items: center; gap: 4px; padding: 3px 7px; flex: 1; min-width: 0; }
.wk-name { font-size: 11px; font-weight: 700; color: var(--tc); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.wk-time { font-size: 10px; color: var(--tc); opacity: .82; white-space: nowrap; flex-shrink: 0; }

/* ── Month view ───────────────────────────────────────────────────────────── */
.month-wrap { }
.month-grid {
  display: grid; grid-template-columns: repeat(7, 1fr);
  border-left: 1px solid var(--border); border-top: 1px solid var(--border);
}
.month-dow {
  padding: 8px; text-align: center;
  font-size: 12px; font-weight: 600; color: var(--text-muted);
  text-transform: uppercase; letter-spacing: .04em;
  background: var(--bg);
  border-right: 1px solid var(--border); border-bottom: 1px solid var(--border);
}
.month-cell {
  min-height: 100px; padding: 6px 7px; background: var(--surface, #ffffff); cursor: pointer;
  border-right: 1px solid var(--border); border-bottom: 1px solid var(--border);
  transition: background 0.1s;
}
.month-cell:hover { background: var(--surface-hover, #f8fafc); }
.month-cell.other-month { background: var(--surface-hover, #fafafa); }
.month-cell.today { background: var(--positive-bg, #f0fdf4); }
.month-day-num { font-size: 13px; font-weight: 600; margin-bottom: 4px; color: var(--text); }
.month-cell.today .month-day-num {
  width: 24px; height: 24px; background: var(--primary); color: #fff;
  border-radius: 50%; display: flex; align-items: center; justify-content: center;
}
.month-cell.other-month .month-day-num { color: var(--text-muted); }
.month-events { display: flex; flex-direction: column; gap: 2px; }
.month-event {
  display: flex; align-items: center; gap: 4px;
  border-radius: 3px; padding: 1px 5px;
  font-size: 11px; font-weight: 500; color: var(--text);
  background: var(--bg, #f3f4f6);
  overflow: hidden;
  cursor: pointer;
}
.month-event:hover { background: var(--border, #e5e7eb); }
.me-dot { width: 6px; height: 6px; border-radius: 50%; background: var(--c); flex-shrink: 0; }
.me-prog-icon { color: var(--c); flex-shrink: 0; }
.ev-prog-icon { vertical-align: -2px; margin-right: 3px; opacity: .92; }
.me-label { flex: 1; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
/* Payment pending dot for month events */
.me-pay-dot {
  width: 6px; height: 6px; border-radius: 50%;
  background: #f59e0b;
  flex-shrink: 0;
  margin-left: auto;
}
.month-more { font-size: 11px; color: var(--text-muted); padding-left: 3px; }

/* ── Legend ───────────────────────────────────────────────────────────────── */
.legend {
  padding: 12px 20px; display: flex; gap: 16px; flex-wrap: wrap; align-items: center;
  border-top: 1px solid var(--border); background: var(--bg);
}
.legend-item { display: flex; align-items: center; gap: 6px; font-size: 13px; color: var(--text-muted); }
.leg-dot { width: 10px; height: 10px; border-radius: 3px; flex-shrink: 0; }
.legend-sep { width: 1px; height: 16px; background: var(--border); flex-shrink: 0; margin: 0 4px; }
.leg-pay-dot {
  width: 9px; height: 9px; border-radius: 50%;
  background: #f59e0b; flex-shrink: 0;
  box-shadow: 0 0 0 1.5px #fff, 0 0 0 2.5px #f59e0b;
}

.no-data { padding: 40px; text-align: center; color: var(--text-muted); font-size: 14px; }

/* ── Phone (≤600px) — 360px-wide fixes; desktop untouched ─────────────────── */
@media (max-width: 600px) {
  /* Toolbar: date nav takes the full first row, label ellipsizes instead of
     pushing past 360px; controls row spreads across the full width. */
  .date-nav { flex: 1; min-width: 0; }
  .date-label {
    font-size: 14px; min-width: 0; flex: 1; justify-content: center;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  }
  .date-picker { min-width: 0; }
  .cal-controls { width: 100%; justify-content: space-between; }

  /* Tap targets ≥44px */
  .view-toggle button, .gran-toggle button { padding-top: 10px; padding-bottom: 10px; }
  .btn-icon { padding: 12px; }
  .sess-cancel-btn { min-height: 44px; }
  .sess-close { width: 40px; height: 40px; }

  /* iOS zoom guard — input is opacity:0, no visual change */
  .date-picker-input { font-size: 16px; }

  /* Program-session panel → bottom sheet per app convention */
  .sess-backdrop { align-items: flex-end; justify-content: stretch; }
  .sess-panel {
    max-width: none; height: 92vh; height: 92dvh; align-self: flex-end;
    border-radius: 16px 16px 0 0; animation: none;
  }
  .sess-header, .sess-body { padding-left: 14px; padding-right: 14px; }

  /* Month view: shorter cells, dots instead of truncated 1-2 char labels */
  .month-cell { min-height: 72px; padding: 4px; }
  .month-event .me-label { display: none; }

  /* Type floor: nothing below 11px */
  .day-closed-tag { font-size: 11px; }
  .bk-time, .wk-time { font-size: 11px; }
  .day-gutter-slot { font-size: 11px; }
  .day-event-time { font-size: 11px; }
  .sess-badge { font-size: 11px; }

  /* Day view: narrower gutter + columns keep more of the event area visible */
  .day-col { min-width: 170px; }
  .day-col-head { font-size: 12px; padding: 0 8px; }
  .day-gutter { width: 56px; min-width: 56px; }
}
.cal-venue-filter { min-width: 170px; max-width: 220px; }
</style>
