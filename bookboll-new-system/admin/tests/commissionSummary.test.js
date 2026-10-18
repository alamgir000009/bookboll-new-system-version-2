import test from 'node:test'
import assert from 'node:assert/strict'
import { commissionCurrency, commissionSummary } from '../src/lib/commissionSummary.js'

const summarize = rows => commissionSummary(rows, b => b.currency)
test('zero commission retains every actual currency instead of defaulting to AED', () => {
  const result = summarize([{ currency: 'QAR', commission_amount: 0 }, { currency: 'PKR', commission_amount: '0.00' }])
  assert.deepEqual([result.value, result.sub].sort(), ['PKR 0', 'QAR 0'])
  assert.equal(result.incomplete, false)
})
test('missing, null, blank and malformed amounts are unavailable, never zero', () => {
  for (const amount of [undefined, null, '', ' ', false, {}, 'invalid', Infinity]) {
    const result = summarize([{ currency: 'QAR', commission_amount: amount }])
    assert.equal(result.value, 'Unavailable')
    assert.equal(result.incomplete, true)
  }
})
test('mixed currency totals preserve minor units and flag partial coverage', () => {
  const result = summarize([
    { currency: 'QAR', commission_amount: '12.50' },
    { currency: 'QAR', commission_amount: 4.25 },
    { currency: 'PKR', commission_amount: 10 },
    { currency: 'BHD', commission_amount: 0.125 },
    { currency: 'QAR', commission_amount: null },
  ])
  assert.equal(result.value, 'QAR 16.75')
  assert.equal(result.sub, 'PKR 10 · BHD 0.125')
  assert.match(result.note, /Partial total · 1 booking missing/)
})
test('empty views and unknown currency never manufacture AED totals', () => {
  assert.equal(summarize([]).value, '—')
  assert.equal(summarize([{ commission_amount: 12 }]).value, 'Unavailable')
})
test('currency resolution honors booking snapshots, location overrides and country relations', () => {
  const locations = [{ id: '10', city_id: '20' }, { id: 11, country_id: '40', currency_override: 'usd' }]
  const cities = [{ id: 20, country_id: '30' }]
  const countries = [{ id: 30, currency_code: 'QAR' }, { id: 40, currency_code: 'PKR' }]
  const resolve = booking => commissionCurrency(booking, locations, cities, countries)
  assert.equal(resolve({ location_id: 10 }), 'QAR')
  assert.equal(resolve({ location_id: 11 }), 'USD')
  assert.equal(resolve({ location_id: 11, currency: 'pkr' }), 'PKR')
  assert.equal(resolve({ location_id: 99 }), null)
  assert.equal(resolve({ location_id: 99, currency: 'invalid' }), null)
})
