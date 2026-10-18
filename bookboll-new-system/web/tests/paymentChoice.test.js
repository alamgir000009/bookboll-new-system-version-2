import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { parse, compileScript } from '@vue/compiler-sfc'
import { parse as parseJs } from '@babel/parser'
import * as vue from 'vue'
import * as booking from '../src/customer/booking.js'
import * as slots from '../src/lib/slots.js'

// Exercise the actual checkout setup with its I/O replaced. No live slots,
// customer accounts, booking endpoints or payment sessions are touched.
const source = readFileSync(new URL('../src/customer/pages/ListingDetail.vue', import.meta.url), 'utf8')
const compiled = compileScript(parse(source).descriptor, { id: 'payment-choice-test' }).content
let setupSource = compiled
for (const node of parseJs(compiled, { sourceType: 'module' }).program.body.slice().reverse()) {
  if (node.type === 'ImportDeclaration') {
    const replacements = node.specifiers.map(s => `const ${s.local.name} = imports[${JSON.stringify(node.source.value)}][${JSON.stringify(s.type === 'ImportDefaultSpecifier' ? 'default' : s.imported.name)}];`).join('\n')
    setupSource = setupSource.slice(0, node.start) + replacements + setupSource.slice(node.end)
  } else if (node.type === 'ExportDefaultDeclaration') {
    setupSource = setupSource.slice(0, node.start) + 'return ' + setupSource.slice(node.declaration.start)
  }
}
const componentFactory = new Function('imports', 'window', setupSource)
const options = { online_payment_enabled: true, deposit_enabled: true, deposit_percentage: 20, cash_on_arrival: true }

function checkout(t, paymentOptions = options, {site = false, providerHasPayout = false, mobile = true} = {}) {
  const calls = []
  const scope = vue.effectScope()
  t.after(() => scope.stop())
  const noop = () => {}
  const route = vue.reactive({ params: { id: 'fixture' }, query: {}, path: '/listings/fixture' })
  const session = {
    selectedCountry: vue.ref({ id: 1, iso_code: 'QA', currency_code: 'QAR' }),
    customer: vue.ref({ id: 'test-customer', full_name: 'Test Customer' }),
    isCustomer: vue.ref(true),
  }
  const modules = {
    vue: { ...vue, onMounted: noop, onBeforeUnmount: noop },
    'vue-router': { useRoute: () => route, useRouter: () => ({ push: noop }), onBeforeRouteLeave: noop },
    '../../lib/customerSession.js': session,
    '../../lib/brand.js': { isWhiteLabel: vue.ref(site) },
    '../../customer/bookingSession.js': { checkoutSession: vue.ref(null) },
    '../../customer/hostDraft.js': { hostDraft: {} },
    '../../customer/booking.js': booking,
    '../../lib/slots.js': slots,
    '../../customer/apiBooking.js': {
      holdToken: () => 'test', PAY_MAP: { online: 'online_payment', deposit: 'deposit_remaining_on_arrival', cash: 'cash_on_arrival' },
      createCustomerBookingsMulti: async input => { calls.push(input); return { redirect: true } },
    },
    '../../lib/promocodes.js': { validatePromo: async () => { calls.push('promo'); return { ok: false, reason: 'Test' } } },
  }
  const imports = new Proxy(modules, { get: (target, key) => target[key] || new Proxy({}, { get: () => noop }) })
  const state = scope.run(() => componentFactory(imports, { matchMedia: () => ({ matches: mobile }) }).setup({}, { expose: noop }))
  state.data.value = { listing: { id: 'fixture', slot_duration: 60, listing_type: 'court' }, location: { id: 1, owner_user_id: 1 }, paymentOptions, providerHasPayout }
  state.selectedByDate.value = { '2026-10-13': [{ start_time: '10:00', end_time: '11:00', price: 125 }] }
  state.phone.value = '12345678'
  state.step.value = 2
  let focused = false
  state.paymentMethods.value = { scrollIntoView: noop, querySelector: () => ({ focus: () => { focused = true } }) }
  return { state, calls, focused: () => focused }
}

test('Book Now reveals availability without scrolling desktop and focuses the mobile picker', async t => {
  for (const mobile of [false, true]) {
    const { state, calls } = checkout(t, options, { mobile })
    state.selectedByDate.value = {}
    state.bookingOpen.value = false
    const actions = []
    state.bookingHeading.value = { scrollIntoView: () => actions.push('scroll'), focus: args => actions.push(args) }
    await state.openBooking()
    assert.equal(state.bookingOpen.value, true)
    assert.deepEqual(actions, mobile ? ['scroll', { preventScroll: true }] : [{ preventScroll: true }])
    assert.deepEqual(calls, [])
  }
})

test('checkout starts blank and blocks confirmation and promo requests until a choice is made', async t => {
  const { state, calls, focused } = checkout(t)
  await vue.nextTick()
  assert.equal(state.payMethod.value, '')
  assert.equal(state.paySplit.value, null)
  assert.equal(state.confirmLabel.value, 'Continue')
  await state.confirm()
  await vue.nextTick()
  assert.match(state.paymentError.value, /choose a payment method/i)
  assert.equal(focused(), true)
  assert.deepEqual(calls, [])
  await state.applyPromo()
  assert.deepEqual(calls, [])
})

test('a single available payment method is automatic and retains the simple checkout flow', async t => {
  const { state, calls } = checkout(t, { cash_on_arrival: true })
  await vue.nextTick()
  assert.deepEqual(state.plan.value.methods, ['cash'])
  assert.equal(state.payMethod.value, 'cash')
  assert.equal(state.paymentError.value, '')
  assert.equal(state.confirmLabel.value, 'Confirm booking')
  await state.confirm()
  assert.equal(calls[0].payment.method, 'cash')
})

test('explicit choices produce the correct breakdown and returning to checkout clears the choice', async t => {
  const { state } = checkout(t)
  await vue.nextTick()
  state.payMethod.value = 'online'
  await vue.nextTick()
  assert.equal(state.paySplit.value.online, 125)
  assert.equal(state.paySplit.value.arrival, 0)
  state.payMethod.value = 'deposit'
  await vue.nextTick()
  assert.equal(state.paySplit.value.online, 25)
  assert.equal(state.paySplit.value.arrival, 100)
  state.step.value = 1
  await vue.nextTick()
  state.step.value = 2
  await vue.nextTick()
  assert.equal(state.payMethod.value, '')
  assert.equal(state.paySplit.value, null)
})

test('a removed option is cleared when multiple alternatives remain', async t => {
  const { state } = checkout(t)
  await vue.nextTick()
  state.payMethod.value = 'online'
  await vue.nextTick()
  state.data.value.paymentOptions = { cash_on_arrival: true, deposit_enabled: true, deposit_percentage: 20 }
  await vue.nextTick()
  assert.equal(state.payMethod.value, '')
  assert.equal(state.paySplit.value, null)
})

test('an automatic single method is cleared if more choices become available', async t => {
  const { state } = checkout(t, { online_payment_enabled: true })
  await vue.nextTick()
  assert.equal(state.payMethod.value, 'online')
  state.data.value.paymentOptions = options
  await vue.nextTick()
  assert.equal(state.payMethod.value, '')
  assert.equal(state.paySplit.value, null)
})

const adapterSource = readFileSync(new URL('../src/customer/apiBooking.js', import.meta.url), 'utf8').replace(
  "import { apiFetch, getCustomerToken, setCustomerToken } from '../lib/api.js'",
  `export const requests = [];
   const getCustomerToken = () => 'test-token';
   const setCustomerToken = () => {};
   const apiFetch = async (path, options) => { requests.push({ path, ...options }); return { success: false, message: 'Stopped by test adapter' } };`,
).replace("'../lib/checkoutRewards.js'", JSON.stringify(new URL('../src/lib/checkoutRewards.js',import.meta.url).href))
const adapter = await import('data:text/javascript;base64,' + Buffer.from(adapterSource).toString('base64'))
const request = { listing: { id: 'fixture' }, location: { id: 1 }, selectionByDate: { '2026-10-13': [{ start_time: '10:00', end_time: '11:00' }] } }

test('booking adapter rejects missing and invalid methods before any network request', async () => {
  adapter.requests.length = 0
  for (const method of [undefined, null, '', 'unknown', 'toString']) {
    const result = await adapter.createCustomerBookingsMulti({ ...request, payment: method === undefined ? undefined : { method } })
    assert.match(result.error, /choose a payment method/i)
  }
  assert.deepEqual(adapter.requests, [])
})

test('booking adapter keeps the explicitly selected method in each request', async () => {
  adapter.requests.length = 0
  for (const method of ['online', 'deposit', 'cash']) {
    await adapter.createCustomerBookingsMulti({ ...request, payment: { method } })
    const sent = adapter.requests.at(-1)
    assert.equal(sent.body.payment_method, adapter.PAY_MAP[method])
    assert.equal(sent.path, method === 'cash' ? '/customer/bookings/create' : '/customer/payments/create-checkout-session')
  }
  assert.equal(adapter.requests.length, 3)
})

test('listing checkout applies points only to online payment and blocks expired quotes',async t=>{
  const {state:s,calls}=checkout(t)
  await vue.nextTick();s.payMethod.value='deposit';await vue.nextTick()
  s.rewardSelection.value={quoteId:'confirmed',points:2000,amount:20,expiresAt:Date.now()+60000}
  s.rewardsControl.value={validate:()=>true}
  assert.equal(s.chargeNow.value,5)
  assert.equal(s.paySplit.value.arrival,100)
  await s.confirm();assert.equal(calls[0].payment.rewards.quoteId,'confirmed')
  s.rewardsControl.value={validate:()=>false}
  await s.confirm();assert.equal(calls.length,1)
  assert.match(s.err.value,/refreshed points/)
})

test('booking adapter sends a rewards quote and points; expired proofs never reach payment',async()=>{
  adapter.requests.length=0
  const rewards={quoteId:'confirmed',points:2000,amount:20,share:9999,expiresAt:Date.now()+60000}
  await adapter.createCustomerBookingsMulti({...request,payment:{method:'deposit',rewards}})
  const body=adapter.requests[0].body
  assert.equal(body.loyalty_quote_id,'confirmed');assert.equal(body.loyalty_points,2000)
  assert.equal(body.loyalty_redeem,undefined);assert.equal(body.share,undefined)
  const rejected=await adapter.createCustomerBookingsMulti({...request,payment:{method:'deposit',rewards:{...rewards,expiresAt:Date.now()-1}}})
  assert.match(rejected.error,/expired/);assert.equal(adapter.requests.length,1)
})

test('own-site checkout ignores legacy cash and keeps the server deposit quote with or without payouts', async t => {
  for (const providerHasPayout of [false,true]) {
    const {state,calls} = checkout(t,{site_cash_enabled:true,site_deposit_enabled:true,site_deposit_percentage:11.1},{site:true,providerHasPayout})
    await vue.nextTick()
    assert.deepEqual(state.plan.value.methods,['deposit'])
    assert.equal(state.payMethod.value,'deposit')
    assert.equal(state.plan.value.depositPct,11.1)
    // 11.1% of 125.05 is 13.88. This asserted 13 while the split still used
    // Math.floor; readmeAlamgir required the server to move to minor-unit
    // rounding and the storefront to follow in the same release, so the number
    // beside the Pay button is the number Stripe takes. The own-site deposit is
    // now Bookboll's commission, so the floored version under-quoted the fee.
    assert.equal(state.paySplit.value.online,13.88)
    await state.confirm()
    assert.equal(calls[0].surface,'site')
    assert.equal(calls[0].payment.method,'deposit')
  }
})

test('own-site full-online needs payouts and two methods require an explicit choice', async t => {
  const po = {site_online_enabled:true,site_deposit_enabled:true,site_deposit_percentage:20,site_cash_enabled:true}
  const disconnected = checkout(t,po,{site:true})
  const connected = checkout(t,po,{site:true,providerHasPayout:true})
  await vue.nextTick()
  assert.deepEqual(disconnected.state.plan.value.methods,['deposit'])
  assert.deepEqual(connected.state.plan.value.methods,['online','deposit'])
  assert.equal(connected.state.payMethod.value,'')
  await connected.state.confirm()
  assert.deepEqual(connected.calls,[])
  connected.state.payMethod.value = 'online'
  await vue.nextTick()
  await connected.state.confirm()
  assert.equal(connected.calls[0].payment.method,'online')
})

test('cash-only, missing and unusable own-site settings cannot book or acquire holds', async t => {
  for (const po of [null,{site_cash_enabled:true},{site_online_enabled:true},{site_deposit_enabled:true,site_deposit_percentage:0}]) {
    const {state,calls} = checkout(t,po,{site:true})
    await vue.nextTick()
    assert.deepEqual(state.plan.value.methods,[])
    assert.equal(state.payMethod.value,'')
    assert.equal(state.confirmLabel.value,'Booking unavailable')
    assert.match(state.paymentError.value,/contact the venue/i)
    await state.confirm()
    await state.goCheckout()
    const selection = JSON.stringify(state.selectedByDate.value)
    await state.toggleSlot({available:true,start_time:'12:00',end_time:'13:00',price:125})
    await state.addExtension({slot:{start_time:'12:00',end_time:'13:00',price:125}})
    assert.equal(JSON.stringify(state.selectedByDate.value),selection)
    assert.deepEqual(calls,[])
  }
})

test('own-site adapter rejects cash before network and preserves the site for allowed methods', async () => {
  adapter.requests.length = 0
  const rejected = await adapter.createCustomerBookingsMulti({...request,surface:'site',payment:{method:'cash'}})
  assert.match(rejected.error,/full payment online or deposit/)
  assert.deepEqual(adapter.requests,[])
  for (const method of ['deposit','online']) {
    await adapter.createCustomerBookingsMulti({...request,surface:'site',payment:{method}})
    assert.equal(adapter.requests.at(-1).body.surface,'site')
    assert.equal(adapter.requests.at(-1).body.payment_method,adapter.PAY_MAP[method])
  }
})
