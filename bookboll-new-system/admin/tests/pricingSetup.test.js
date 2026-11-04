import test from 'node:test'
import assert from 'node:assert/strict'
import { missingPricingSetup, pricingSetupGate } from '../src/lib/pricingSetup.js'
const base = () => ({ enabled:false, cells:[], groups:[], form:{base_price:100,extension_pricing:'none',extension_value:0} })
test('enabling toggles without configuring prices still needs the reminder', () => {
  const state=base(); state.enabled=true; state.form.extension_pricing='percent'
  assert.deepEqual(missingPricingSetup(state),['dynamic','extension'])
  state.cells=[{direct_price:100}]; state.groups=[{id:'unused',price:150}]
  assert.deepEqual(missingPricingSetup(state),['dynamic','extension'])
})
test('direct and group prices count only when applied; valid extension discounts count as configured', () => {
  const state=base(); state.enabled=true; state.cells=[{direct_price:140}]
  assert.deepEqual(missingPricingSetup(state),['extension'])
  state.cells=[{pricing_group_id:'peak'}]; state.groups=[{id:'peak',price:140}]
  state.form.extension_pricing='percent'; state.form.extension_value=20
  assert.deepEqual(missingPricingSetup(state),[])
  state.form.extension_value=100
  assert.deepEqual(missingPricingSetup(state),['extension'])
  state.form.extension_pricing='flat'; state.form.extension_value=25
  assert.deepEqual(missingPricingSetup(state),[])
  state.enabled=false
  assert.deepEqual(missingPricingSetup(state),['dynamic'])
})
test('skip is remembered for the open editor and reset for another listing', () => {
  const gate=pricingSetupGate()
  gate.skip(['dynamic'])
  assert.deepEqual(gate.missing(['dynamic','extension']),['extension'])
  gate.skip(['extension'])
  assert.deepEqual(gate.missing(['dynamic','extension']),[])
  gate.reset()
  assert.deepEqual(gate.missing(['dynamic','extension']),['dynamic','extension'])
})
