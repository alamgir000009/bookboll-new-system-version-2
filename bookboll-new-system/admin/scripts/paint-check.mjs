/*
 * Exercise the grid's painting logic — the part the render check cannot reach,
 * because painting is a pointer gesture.
 *
 * Pulls the pure functions out of PricingGrid.vue by name and runs them against
 * listing 16's real schedule, so a change to how squares become groups is
 * checked against the cases that have actually gone wrong on the live site: a
 * window the grid cannot draw being destroyed by an unrelated paint, adjacent
 * squares failing to merge, a lone paint re-pricing the hours around it.
 */
import fs from 'fs'

const src = fs.readFileSync('src/components/PricingGrid.vue', 'utf8')
function grab(name) {
  const i = src.indexOf('function ' + name + '(')
  if (i < 0) throw new Error('missing function ' + name)
  let d = 0, k = src.indexOf('{', i)
  for (;; k++) {
    if (src[k] === '{') d++
    else if (src[k] === '}' && --d === 0) break
  }
  return src.slice(i, k + 1)
}
const names = ['listingDay', 'venueDay', 'openPeriods', 'inPeriods', 'slotsForDay', 'slotInOpen', 'groupDays', 'groupEndMin',
  'groupCoversSlot', 'windowGroupFor', 'resolvedCell', 'squaresOf', 'rectanglesOf', 'rebuild', 'numberParts',
  'applyPaint', 'paintWithPrice', 'rejectPrice', 'typedPrice', 'paintCell', 'fillDay']

const slots = await import('../src/lib/slots.js')
const harness = `
const { toMinutes, toTimeStr, endMinutes, periodsOf, effectivePeriods, periodSlotStarts } = slots
const DAY_ORDER = [1, 2, 3, 4, 5, 6, 0]
const DAY_INDEX = { sun: 0, sunday: 0, mon: 1, monday: 1, tue: 2, tuesday: 2, wed: 3, wednesday: 3, thu: 4, thursday: 4, fri: 5, friday: 5, sat: 6, saturday: 6 }
const minutesOf = toMinutes, endMin = endMinutes
const hm = (m) => (m >= 24 * 60 ? '00:00' : toTimeStr(m))
const props = { groups: [], basePrice: 100, slotDuration: 120, pattern: 'B', hours: [], venueHours: [] }
const slotMins = { get value() { return props.slotDuration } }
const daySlots = { get value() { const m = {}; for (const d of DAY_ORDER) m[d] = new Set(slotsForDay(d)); return m } }
const activeGroupId = { value: null }, directPriceInput = { value: null }
const priceInputError = { value: false }, priceErrorMsg = { value: '' }
const PART = / · \\d+$/
const baseName = (n) => String(n || 'Group').replace(PART, '')
const isGridGroup = (g) => /^Grid\\s/.test(baseName(g.name))
const touchesAny = (g, keys) => keys.some(key => { const [d, slot] = key.split('|'); return groupCoversSlot(g, Number(d), slot) })
const toast = { error: (m) => { toasts.push(m) } }, toasts = []
const priceInputEl = { value: { scrollIntoView: () => { scrolled++ }, focus: () => {} } }
let scrolled = 0
function markDirty() {}
function emit(ev, v) { if (ev === 'update:groups') props.groups = v }
${names.map(grab).join('\n')}
return { props, activeGroupId, directPriceInput, paintCell, fillDay, resolvedCell, groupDays, slotsForDay, toasts, scrolled: () => scrolled }
`
const g = new Function('slots', harness)(slots)
const { props, activeGroupId, directPriceInput } = g

const day = (d, ps) => ({ day_of_week: d, is_open: true, periods: ps.map(([o, c]) => ({ open_time: o, close_time: c })) })
props.hours = [
  day(0, [['00:00', '01:00'], ['15:00', '00:00']]), day(1, [['00:00', '01:00'], ['15:00', '00:00']]),
  day(2, [['00:00', '01:00'], ['15:00', '00:00']]), day(3, [['00:00', '01:00'], ['15:00', '00:00']]),
  day(4, [['00:00', '01:00'], ['15:00', '00:00']]), day(5, [['00:00', '01:00'], ['07:00', '00:00']]),
  day(6, [['00:00', '01:00'], ['07:00', '00:00']]),
]
const N = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']
const show = () => props.groups.map(x => `${x.name} ${x.start_time}-${x.end_time} @${x.price} ${g.groupDays(x).map(d => N[d]).join(',')}`)
let failures = 0
const check = (ok, msg) => { console.log(`  ${ok ? '✓' : '✗'} ${msg}`); if (!ok) { failures++; console.log('      now: ' + show().join(' | ')) } }

console.log('Slot Grid: type a price, click, drag')
props.groups = [{ id: '1177', name: 'Group 4', price: 200, sort_order: 0, start_time: '23:00', end_time: '00:00', days: '5,6' }]
directPriceInput.value = 150
g.paintCell(6, '07:00', 'paint')
check(show().includes('Grid 150 07:00-09:00 @150 Sat'), 'one square → a Grid 150 group for exactly that slot')
g.paintCell(6, '09:00', 'paint'); g.paintCell(6, '11:00', 'paint')
check(show().includes('Grid 150 07:00-13:00 @150 Sat'), 'dragging across adjacent squares merges them into one window')
check(g.resolvedCell(6, '13:00').price === 100 && g.resolvedCell(6, '15:00').price === 100, 'the squares after the drag are untouched (base price)')
check(show().includes('Group 4 23:00-00:00 @200 Fri,Sat'), 'a group whose window the 2-hour grid cannot draw (23:00-00:00) is left alone')
g.paintCell(5, '07:00', 'paint')
check(show().some(s => s.startsWith('Grid 150') && s.endsWith('Fri')) && show().some(s => s.includes('07:00-13:00') && s.endsWith('Sat')), 'a different day with a different run becomes its own rectangle, not a widening')
directPriceInput.value = 100
g.paintCell(6, '09:00', 'paint')
check(g.resolvedCell(6, '09:00').price === 100 && g.resolvedCell(6, '07:00').price === 150 && g.resolvedCell(6, '11:00').price === 150, 'painting the base price erases one square and splits the run around it')
directPriceInput.value = 300
g.fillDay(0)
check(show().some(s => s.includes('15:00-00:00 @300') && s.endsWith('Sun')), '"Apply all" on Sunday prices exactly its real slots, 15:00-00:00')
check(g.resolvedCell(0, '23:00').price === 300 && !g.slotsForDay(0).includes('13:00'), 'the Sunday 11 PM slot is included; 1 PM (closed) is not')
directPriceInput.value = 50
const before = show().join('|')
g.paintCell(6, '07:00', 'paint')
check(show().join('|') === before, 'a price below base is refused and changes nothing')
check(g.toasts.at(-1)?.includes('lower than the base price') && g.scrolled() > 0, '…and says so in a toast, scrolling the price box into view')
directPriceInput.value = null
g.paintCell(6, '07:00', 'paint')
check(g.toasts.at(-1) === 'Enter a price first, then click or drag the slots to apply it.' && g.scrolled() > 1, 'clicking with no price typed toasts and scrolls to the price box')

console.log('\nGroups tab: select a group, click, drag')
props.pattern = 'A'
props.groups = [
  { id: '1181', name: 'Group 8', price: 175, sort_order: 0, start_time: '18:00', end_time: '20:00', days: '0,1,2,3' },
  { id: '1175', name: 'Group 2', price: 125, sort_order: 1, start_time: '15:00', end_time: '17:00', days: '0,1,2,3,4,5,6' },
]
activeGroupId.value = '1181'
g.paintCell(1, '21:00', 'paint')
check(show().some(s => s.startsWith('Group 8') && s.includes('Mon')) && g.resolvedCell(1, '21:00').price === 175, 'painting with the selected group gives it that square')
check(g.resolvedCell(1, '19:00').price === 175 && g.resolvedCell(2, '21:00').price === 100, 'nothing else moved: Mon 7 PM still Group 8, Tue 9 PM still base')
g.paintCell(1, '15:00', 'paint')
check(g.resolvedCell(1, '15:00').price === 175 && g.resolvedCell(2, '15:00').price === 125, 'taking a square from another group removes it there only (one price per square)')
g.paintCell(1, '15:00', 'erase')
check(g.resolvedCell(1, '15:00').price === 100, 'clicking a square the group owns clears it back to base')

console.log(failures ? `\n${failures} CHECK(S) FAILED` : '\nAll paint checks passed')
process.exit(failures ? 1 : 0)
