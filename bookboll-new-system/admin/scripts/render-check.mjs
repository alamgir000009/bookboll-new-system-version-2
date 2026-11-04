/*
 * Render the pricing grid for real, with listing 16's actual schedule.
 *
 * `npm run build` only bundles — it does not execute the component, so a
 * reference to a function that no longer exists sails through and crashes the
 * page the moment it renders. This runs the component through Vue's server
 * renderer and asserts what the grid must show, so a green build means the page
 * works, not merely that it parsed.
 */
import { createSSRApp } from 'vue'
import { renderToString } from '@vue/server-renderer'
import { createServer } from 'vite'

const vite = await createServer({ root: process.cwd(), server: { middlewareMode: true }, appType: 'custom', logLevel: 'error' })
const { default: PricingGrid } = await vite.ssrLoadModule('/src/components/PricingGrid.vue')
const { bookableSlots, daysLabel, fmtRange12 } = await vite.ssrLoadModule('/src/lib/slots.js')

let failures = 0
const check = (ok, msg) => { console.log(`  ${ok ? '✓' : '✗'} ${msg}`); if (!ok) failures++ }

// Listing 16, exactly as the live dump holds it: 2-hour slots; every day has a
// 00:00-01:00 period no 2-hour slot fits in; Fri/Sat open 07:00, others 15:00.
const day = (d, ps) => ({ day_of_week: d, is_open: true, periods: ps.map(([o, c]) => ({ open_time: o, close_time: c })) })
const hours16 = [
  day(0, [['00:00', '01:00'], ['15:00', '00:00']]), day(1, [['00:00', '01:00'], ['15:00', '00:00']]),
  day(2, [['00:00', '01:00'], ['15:00', '00:00']]), day(3, [['00:00', '01:00'], ['15:00', '00:00']]),
  day(4, [['00:00', '01:00'], ['15:00', '00:00']]), day(5, [['00:00', '01:00'], ['07:00', '00:00']]),
  day(6, [['00:00', '01:00'], ['07:00', '00:00']]),
]
const groups16 = [
  { id: '1174', name: 'Group 1', price: 150, sort_order: 0, start_time: '10:00', end_time: '14:00', days: '5,6' },
  { id: '1175', name: 'Group 2', price: 125, sort_order: 0, start_time: '14:00', end_time: '16:00', days: '0,1,2,3,4,5,6' },
  { id: '1177', name: 'Group 4', price: 200, sort_order: 0, start_time: '20:00', end_time: '00:00', days: '4,5,6' },
  { id: '1181', name: 'Group 8', price: 175, sort_order: 0, start_time: '18:00', end_time: '20:00', days: '0,1,2,3' },
]

async function render(props) {
  const app = createSSRApp({
    components: { PricingGrid },
    data: () => props,
    template: `<PricingGrid :groups="groups" :base-price="basePrice" :slot-duration="slotDuration"
                 :pattern="pattern" :hours="hours" :venue-hours="venueHours" currency="QAR" />`,
  })
  return renderToString(app)
}
const cellsOf = (html) => [...html.matchAll(/<button[^>]*class="([^"]*\bgrid-cell\b[^"]*)"[^>]*aria-label="([^"]+)"[^>]*>([\s\S]*?)<\/button>/g)]
  .map(m => ({ cls: m[1].trim(), label: m[2], text: m[3].trim(), style: (m[0].match(/style="([^"]*)"/) || [])[1] || '' }))
const timeLabels = (html) => [...html.matchAll(/<div class="[^"]*\bgrid-time\b[^"]*"[^>]*><span[^>]*>([^<]+)<\/span>/g)].map(m => m[1])

for (const pattern of ['A', 'B']) {
  console.log(`\n${pattern === 'A' ? 'Groups' : 'Slot Grid'} tab — listing 16`)
  const html = await render({ groups: groups16, basePrice: 100, slotDuration: 120, pattern, hours: hours16, venueHours: [] })
  const cells = cellsOf(html)
  const open = cells.filter(c => !c.cls.includes('closed'))
  const closed = cells.filter(c => c.cls.includes('closed'))
  const labels = timeLabels(html)

  check(labels.length === 24 && labels[0] === '12:00 AM' && labels[23] === '11:00 PM', `24 hour rows, 12:00 AM → 11:00 PM (got ${labels.length})`)
  check(open.filter(c => c.label.startsWith('Sat ')).map(c => c.label.split(' ')[1]).join(',') === '7:00,9:00,11:00,1:00,3:00,5:00,7:00,9:00,11:00',
    'Saturday slots are 7 AM … 11 PM, matching the live API')
  check(open.filter(c => c.label.startsWith('Sun ')).map(c => c.label.split(' ')[1]).join(',') === '3:00,5:00,7:00,9:00,11:00',
    'Sunday slots are 3 PM … 11 PM, matching the live API')
  check(!open.some(c => /^\w{3} 12:00 AM/.test(c.label)), 'no fake 12:00 AM slot (1-hour window cannot fit a 2-hour slot)')
  check(open.some(c => c.label.startsWith('Sat 11:00 PM – 1:00 AM')), 'the 11 PM slot reads "11:00 PM – 1:00 AM", not "– 12:00 AM"')
  check(closed.some(c => c.label === 'Sat 12:00 AM, closed') && closed.some(c => c.label === 'Mon 10:00 AM, closed'),
    'closed hours are drawn as closed cells, not left blank')
  check(open.filter(c => c.label.startsWith('Mon 9:00 PM')).every(c => c.cls.includes('faded') && c.text === '100'),
    'base price shown dim where no group applies')
  const price = (lbl) => open.find(c => c.label.startsWith(lbl))
  check(price('Sat 11:00 AM')?.text === '150' && price('Sat 9:00 PM')?.text === '200' && price('Mon 7:00 PM')?.text === '175',
    'group prices resolve on the right squares')
  // Colour ladder: 125 green, 150 yellow, 175 orange, 200 dark red.
  const colour = (lbl) => (price(lbl)?.style.match(/border-left:3px solid (#[0-9a-f]{6})/) || [])[1]
  check(colour('Sat 3:00 PM') === '#16a34a', 'lowest price (125) is green')
  check(colour('Sat 11:00 AM') === '#ca8a04', 'next price (150) is yellow')
  check(colour('Mon 7:00 PM') === '#ea580c', 'next price (175) is orange')
  check(colour('Sat 9:00 PM') === '#991b1b', 'highest price (200) is dark red')
}

console.log('\nA night that crosses midnight — 23:00 → 11:00 on Monday, 2-hour slots')
{
  const hours = [day(1, [['00:00', '11:00'], ['23:00', '00:00']])]
  const html = await render({ groups: [], basePrice: 100, slotDuration: 120, pattern: 'B', hours, venueHours: [] })
  const starts = cellsOf(html).filter(c => !c.cls.includes('closed')).map(c => c.label.split(' ')[1] + c.label.split(' ')[2].slice(0, 2))
  check(starts.join(',') === '12:00AM,2:00AM,4:00AM,6:00AM,8:00AM,11:00PM', `rows 12 AM, 2, 4, 6, 8 AM and 11 PM are bookable (got ${starts.join(',')})`)
}

console.log('\nVenue hours cut the listing down, the way the server does')
{
  const hours = [day(1, [['00:00', '11:00'], ['23:00', '00:00']])]
  const venue = [day(1, [['06:00', '00:00']])]
  const html = await render({ groups: [], basePrice: 100, slotDuration: 120, pattern: 'B', hours, venueHours: venue })
  const cells = cellsOf(html)
  const starts = cells.filter(c => !c.cls.includes('closed')).map(c => c.label.split(' ')[1] + c.label.split(' ')[2].slice(0, 2))
  check(starts.join(',') === '6:00AM,8:00AM,11:00PM', `only 6 AM, 8 AM, 11 PM survive the venue's 06:00 opening (got ${starts.join(',')})`)
  check(cells.some(c => c.label === 'Mon 2:00 AM, closed'), '2 AM is now a closed cell (venue shut), not a slot')
}

console.log('\nBookable start times (listing 16, 2-hour slots)')
for (const r of bookableSlots(hours16, 120)) console.log('  ' + fmtRange12(r.start_time, r.end_time).padEnd(22) + daysLabel(r.days))

await vite.close()
console.log(failures ? `\n${failures} CHECK(S) FAILED` : '\nAll checks passed')
process.exit(failures ? 1 : 0)
