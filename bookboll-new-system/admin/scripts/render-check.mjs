import { createSSRApp } from 'vue'
import { renderToString } from '@vue/server-renderer'
import { createServer } from 'vite'

const vite = await createServer({ root: process.cwd(), server: { middlewareMode: true }, appType: 'custom', logLevel: 'error' })
const { default: PricingGrid } = await vite.ssrLoadModule('/src/components/PricingGrid.vue')

// Listing 16, exactly as the live dump holds it.
const hours = [
  [0, [['00:00','01:00'],['15:00','00:00']]], [1, [['00:00','01:00'],['15:00','00:00']]],
  [2, [['00:00','01:00'],['15:00','00:00']]], [3, [['00:00','01:00'],['15:00','00:00']]],
  [4, [['00:00','01:00'],['15:00','00:00']]], [5, [['00:00','01:00'],['07:00','00:00']]],
  [6, [['00:00','01:00'],['07:00','00:00']]],
].map(([d, ps]) => ({ day_of_week: d, is_open: true, periods: ps.map(([o, c]) => ({ open_time: o, close_time: c })) }))

const groups = [
  { id: '1174', name: 'Group 1', price: 150, sort_order: 0, start_time: '10:00', end_time: '14:00', days: '5,6' },
  { id: '1177', name: 'Group 4 · 1', price: 200, sort_order: 0, start_time: '20:00', end_time: '00:00', days: '4,5,6' },
  { id: '1274', name: 'Grid 150 · 1', price: 150, sort_order: 7, start_time: '00:00', end_time: '02:00', days: '5,6' },
]

for (const [label, pattern] of [['Groups tab', 'A'], ['Slot Grid tab', 'B']]) {
  const app = createSSRApp({
    components: { PricingGrid },
    data: () => ({ groups, cells: [], pattern, hours }),
    template: `<PricingGrid :groups="groups" :cells="cells" :base-price="100" :slot-duration="120"
                 :pattern="pattern" :hours="hours" currency="QAR" />`,
  })
  const html = await renderToString(app)
  const rows = [...html.matchAll(/grid-time[^>]*>([^<]+)</g)].map(m => m[1].trim())
  console.log(`${label}: rendered OK — ${rows.length} rows: ${rows.join(', ')}`)
}
await vite.close()

// The Timeslot Settings chips: full slot range + the days each one runs on.
const { bookableSlots, daysLabel, fmtRange12 } = await vite.ssrLoadModule('/src/lib/slots.js')
console.log('\nBookable start times (listing 16, 2-hour slots):')
for (const r of bookableSlots(hours, 120)) {
  console.log('  ' + fmtRange12(r.start_time, r.end_time).padEnd(22) + daysLabel(r.days))
}
