/**
 * "Pay AED 100" on a Lahore booking.
 *
 * The confirmation panel takes its currency from whatever createCustomerBookingsMulti
 * hands back, and that used to be `location?.currency_override || 'AED'`. The override
 * is NULL for most venues — it only exists to override the country — so a Pakistani
 * venue fell straight through to the hardcoded dirhams, whichever country the visitor
 * had picked. The server has always returned the created rows with the currency it
 * actually charged in; nothing read it.
 *
 * So: the server's answer wins, the venue is the fallback, and an unknown currency
 * comes back EMPTY. A bare amount is honest; someone else's money is not.
 */

import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { compile, createSSRApp } from 'vue'
import { renderToString } from '@vue/server-renderer'
import * as icons from 'lucide-vue-next'
import { createdBookingCurrency } from '../src/lib/bookingCurrency.js'

// ── Where the currency comes from ────────────────────────────────────────────

test('takes the currency the server priced the booking in', () => {
  const rows = [{ id: 1, currency: 'PKR' }, { id: 2, currency: 'PKR' }]

  assert.equal(createdBookingCurrency(rows, { currency_override: null }), 'PKR')
})

test('server currency beats the venue and the visitor alike', () => {
  // The exact shape of the bug: a Pakistani booking made from a Doha-set picker.
  const rows = [{ id: 1, currency: 'PKR' }]

  assert.equal(createdBookingCurrency(rows, { currency: 'QAR', currency_override: 'QAR' }), 'PKR')
})

test('falls back to the venue resolved currency before its nullable override', () => {
  // No rows (the Stripe redirect branch): the venue's resolved currency is next.
  assert.equal(createdBookingCurrency(null, { currency: 'PKR', currency_override: null }), 'PKR')
})

test('still honours an explicit venue override when the server said nothing', () => {
  assert.equal(createdBookingCurrency([], { currency: '', currency_override: 'USD' }), 'USD')
})

test('returns empty rather than guessing a currency', () => {
  for (const location of [null, undefined, {}, { currency: null, currency_override: null }]) {
    assert.equal(createdBookingCurrency(null, location), '', JSON.stringify(location))
  }
})

test('ignores rows that carry no currency instead of inventing one', () => {
  // `bookings` is sometimes a list of bare ids rather than objects.
  assert.equal(createdBookingCurrency([7, 8, 9], {}), '')
  assert.equal(createdBookingCurrency([{ id: 1 }, { id: 2, currency: 'PKR' }], {}), 'PKR')
})

test('never falls back to AED', () => {
  const guesses = [
    createdBookingCurrency(null, null),
    createdBookingCurrency([], {}),
    createdBookingCurrency([{ id: 1 }], { currency_override: null }),
  ]

  for (const value of guesses) assert.notEqual(value, 'AED')
})

// ── What the confirmation panel prints ───────────────────────────────────────

const source = readFileSync(new URL('../src/customer/pages/ListingDetail.vue', import.meta.url), 'utf8')
const fragment = source
  .split('<!-- SUCCESS — full width, replaces the flow -->')[1]
  .split('<AppDownloadCard')[0]
  .replace('v-if="success"', '')

async function renderSuccess(success) {
  const render = compile(fragment)
  return renderToString(createSSRApp({
    render,
    components: { ...icons },
    setup: () => ({
      success,
      fmtDate: () => 'Sat, Oct 24',
      fmtTime: t => (t === '08:00' ? '8:00 AM' : '9:00 AM'),
      customer: { id: 1 },
      bookingAppContext: null,
      bookAnother: () => {},
    }),
  }))
}

test('the confirmation prints the currency the booking was made in', async () => {
  const html = await renderSuccess({
    method: 'cash', total: 100, currency: 'PKR',
    perDay: [{ date: '2026-10-24', blocks: [{ start: '08:00', end: '09:00' }] }],
  })

  assert.ok(html.includes('PKR'), 'expected PKR in the confirmation')
  assert.ok(!html.includes('AED'), 'the confirmation must not invent AED')
})

test('an unknown currency leaves the amount bare, not mislabelled', async () => {
  const html = await renderSuccess({
    method: 'cash', total: 100, currency: '',
    perDay: [{ date: '2026-10-24', blocks: [{ start: '08:00', end: '09:00' }] }],
  })

  assert.ok(html.includes('100'), 'the amount still shows')
  assert.ok(!html.includes('AED'), 'no currency is better than the wrong one')
})
