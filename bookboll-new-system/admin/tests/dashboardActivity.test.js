import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { parse, compileScript } from '@vue/compiler-sfc'
import { parse as parseJs } from '@babel/parser'
import * as vue from 'vue'
import * as permissions from '../src/lib/permissions.js'
import * as bookingMeta from '../src/lib/bookingMeta.js'
import * as bookingFrequency from '../src/lib/bookingFrequency.js'
import * as finance from '../src/lib/finance.js'

let code = compileScript(parse(readFileSync(new URL('../src/pages/Dashboard.vue', import.meta.url), 'utf8')).descriptor, { id: 'dashboard' }).content
for (const node of parseJs(code, { sourceType: 'module' }).program.body.slice().reverse()) {
  if (node.type === 'ImportDeclaration') code = code.slice(0,node.start) + node.specifiers.map(s => `const ${s.local.name}=imports[${JSON.stringify(node.source.value)}][${JSON.stringify(s.type === 'ImportDefaultSpecifier' ? 'default' : s.imported.name)}];`).join('\n') + code.slice(node.end)
  else if (node.type === 'ExportDefaultDeclaration') code = code.slice(0,node.start) + 'return ' + code.slice(node.declaration.start)
}

function mount(t, superAdmin = true) {
  const scope = vue.effectScope(), noop = () => {}
  const context = {
    role: vue.ref(superAdmin ? 'super_admin' : 'country_admin'), isSuperAdmin: vue.ref(superAdmin), isCountryAdmin: vue.ref(!superAdmin),
    currentOwnerUserId: vue.ref(null), currentCountryId: vue.ref(1), assignedCountryIds: vue.ref([1]), assignedLocationIds: vue.ref([]),
    currentUser: vue.ref({}), can: () => true, scopeLocations: rows => rows,
  }
  const imports = new Proxy({
    vue: { ...vue, onMounted: noop },
    '../lib/currentUser.js': { useCurrentUser: () => context },
    '../lib/permissions.js': permissions,
    '../lib/bookingMeta.js': bookingMeta,
    '../lib/bookingFrequency.js': bookingFrequency,
    '../lib/finance.js': finance,
  }, { get: (o,k) => o[k] || new Proxy({}, { get: () => noop }) })
  const s = scope.run(() => new Function('imports', code)(imports).setup({}, { expose: noop }))
  t.after(() => scope.stop())
  s.loading.value = false
  s.countries.value = [{ id: 1, currency_code: 'QAR' }, { id: 2, currency_code: 'PKR' }]
  s.cities.value = [{ id: 1, country_id: 1 }, { id: 2, country_id: 2 }]
  s.locations.value = [{ id: 1, city_id: 1 }, { id: 2, city_id: 2 }]
  s.bookings.value = [
    { id: 'a', location_id: 1, commission_amount: 12.5, booking_date: '2026-10-14', booking_status: 'confirmed' },
    { id: 'b', location_id: 1, commission_amount: 2.25, booking_date: '2026-10-13', booking_status: 'confirmed', origin: 'customer_game' },
    { id: 'c', location_id: 1, commission_amount: 100, booking_date: '2026-10-14', booking_status: 'cancelled' },
    { id: 'd', location_id: 1, commission_amount: 100, booking_date: '2026-10-14', origin: 'class_program' },
    { id: 'e', location_id: 2, commission_amount: 50, booking_date: '2026-10-14', booking_status: 'confirmed' },
  ]
  return { s, card: () => s.platformKpis.value.find(k => k.label === 'Bookings per customer') }
}

test('activity frequency respects jurisdiction, venue and inclusive custom dates', t => {
  const { s, card } = mount(t)
  s.bookings.value=[
    {id:1,customer_id:1,location_id:1,booking_date:'2026-10-13',booking_status:'confirmed'},
    {id:2,customer_id:'1',location_id:1,booking_date:'2026-10-14T22:00:00',booking_status:'completed'},
    {id:3,customer_id:2,location_id:2,booking_date:'2026-10-14',booking_status:'confirmed'},
    {id:4,customer_id:3,location_id:1,booking_date:'2026-10-14',payment_method:'payment_skipped'},
  ]
  assert.equal(card().value,'1.5×');assert.equal(card().sub,'50% booked more than once')
  s.fLocations.value = [1]
  assert.equal(card().value,'2×')
  s.fRange.value='custom';s.setActivityPeriod()
  s.fDateFrom.value = '2026-10-14';s.fDateTo.value='2026-10-14'
  assert.equal(card().value,'1×');assert.equal(s.chartBuckets.value.length,1)
  assert.equal(s.chartBuckets.value[0].from,'2026-10-14')
  s.fKinds.value = ['membership']
  assert.equal(card().value,'—')
  const countryAdmin = mount(t, false)
  countryAdmin.s.bookings.value=s.bookings.value
  assert.equal(countryAdmin.card().value,'2×')
})

test('custom dates validate and presets replace them without intersecting stale dates', t => {
  const {s}=mount(t)
  s.fRange.value='custom';s.setActivityPeriod()
  assert.equal(s.activityDateError.value,'')
  s.fDateFrom.value='2026-10-15';s.fDateTo.value='2026-10-14'
  assert.match(s.activityDateError.value,/end date/);assert.deepEqual(s.filteredBookings.value,[]);assert.deepEqual(s.chartBuckets.value,[])
  s.fDateFrom.value='';assert.match(s.activityDateError.value,/valid/)
  s.fRange.value='today';s.setActivityPeriod()
  assert.equal(s.fDateFrom.value,'');assert.equal(s.fDateTo.value,'');assert.equal(s.activityDateError.value,'')
  s.fDateFrom.value='2026-10-01';s.fDateTo.value='2026-10-14';s.useCustomDates()
  assert.equal(s.fRange.value,'custom')
  s.chartFilters.value.revenue={range:'90d'}
  assert.ok(s.bucketsFor('revenue').every(b=>b.from>='2026-10-01' && b.to<='2026-10-14'))
})
test('slot blocks carry availability but never inflate sales, customer counts or acquisition charts', t => {
  const {s}=mount(t)
  s.bookings.value=[{id:'block',location_id:1,customer_id:1,total:9000,payment_method:'payment_skipped',booking_date:'2026-10-14'},{id:'sale',location_id:1,customer_id:2,total:100,booking_status:'confirmed',booking_date:'2026-10-14'},{id:'cancelled',location_id:1,total:5000,booking_status:'cancelled'}]
  assert.equal(s.platformKpis.value[0].value,'QAR 100')
  assert.equal(s.platformKpis.value[1].value,1)
  assert.equal(s.platformKpis.value[2].value,1)
  assert.equal(s.chartData('newcust').some(row=>row.id==='block'),false)
  assert.equal(s.chartData('hours').some(row=>row.id==='block'),true)
  assert.equal(s.pendingPaymentBookings.value.some(row=>row.id==='block'),false)
})
test('loading, failure and zero customers never manufacture frequency', t => {
  const { s, card } = mount(t)
  s.loading.value = true
  assert.equal(card().value, 'Loading…')
  s.loading.value = false
  s.loadError.value = 'Server unavailable'
  assert.equal(card().value, 'Unavailable')
  assert.match(card().note, /retry/)
  s.loadError.value='';s.bookings.value=[];assert.equal(card().value,'—')
})

test('frequency counts distinct confirmed venue bookings and excludes non-customer activity', () => {
  const row={id:'a',customer_id:1,booking_status:'confirmed'}
  const rows=[row,{...row},{...row,id:'b',customer_id:'1'},{...row,id:'c',customer_id:2},
    {...row,id:'skip',payment_status:'skipped'}, {...row,id:'membership',origin:'class_program'},
    {...row,id:'event',origin:'customer_game'}, {...row,id:'cancelled',booking_status:'cancelled'},
    {...row,id:'pending',booking_status:'pending'}, {...row,id:'refund',payment_status:'refunded'},
    {...row,id:'anonymous',customer_id:null}]
  assert.deepEqual(bookingFrequency.bookingFrequency(rows),{bookings:3,customers:2,repeatCustomers:1,average:1.5,repeatRate:50})
})
