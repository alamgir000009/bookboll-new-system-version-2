import test from 'node:test'
import assert from 'node:assert/strict'
import { createCountryCities, sameLocationId } from '../src/lib/locationCities.js'

const deferred = () => { let resolve; const promise = new Promise(r => { resolve = r }); return { promise, resolve } }
const response = cities => ({ success: true, data: { cities } })

test('switching countries clears old options immediately and ignores late responses', async () => {
  const qa = deferred(), pk = deferred()
  const state = createCountryCities(id => id === 'qa' ? qa.promise : pk.promise)
  state.cities.value = [{ id: 4, name: 'Previous city' }]
  const first = state.load('qa')
  assert.deepEqual(state.cities.value, [])
  const second = state.load('pk')
  pk.resolve(response([{ id: 1, name: 'Lahore', country_id: 'pk' }]))
  await second
  qa.resolve(response([{ id: 2, name: 'Doha', country_id: 'qa' }]))
  assert.equal(await first, null)
  assert.equal(state.cities.value[0].name, 'Lahore')
  assert.equal(state.loading.value, false)
})

test('an earlier failure cannot replace the new country state or stop its loader', async () => {
  const old = deferred(), current = deferred()
  const state = createCountryCities(id => id === 'old' ? old.promise : current.promise)
  const a = state.load('old'), b = state.load('current')
  old.resolve({ success: false }); await a
  assert.equal(state.loading.value, true); assert.equal(state.error.value, '')
  current.resolve(response([])); await b
  assert.deepEqual(state.cities.value, []); assert.equal(state.error.value, '')
})

test('failed city requests can be retried and do not retain old data', async () => {
  let succeed = false
  const state = createCountryCities(async () => succeed ? response([{id: 1, country_id: 2}, {id: 3, country_id: 9}]) : {success:false})
  await state.load('2'); assert.ok(state.error.value); assert.equal(state.loading.value, false)
  succeed = true; await state.load('2')
  assert.equal(state.error.value, ''); assert.deepEqual(state.cities.value.map(c => c.id), [1])
  assert.equal(sameLocationId(2,'2'), true); assert.equal(sameLocationId(null,''), false)
})

test('clearing the country or unmounting prevents pending data from returning', async () => {
  const pending = deferred(), state = createCountryCities(() => pending.promise)
  const first = state.load('qa'); await state.load('')
  pending.resolve(response([{id:1}]))
  await first; assert.deepEqual(state.cities.value, []); assert.equal(state.loading.value, false)
  const next = deferred(), unmounted = createCountryCities(() => next.promise)
  const load = unmounted.load('qa'); unmounted.cancel(); next.resolve(response([{id:1}]))
  await load; assert.deepEqual(unmounted.cities.value, [])
})
