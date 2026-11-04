import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'

/* A listing can hide "3 left" on its slots. Presentation only — capacity is
 * still enforced and a full slot still stops selling. */

const page = readFileSync(new URL('../src/customer/pages/ListingDetail.vue', import.meta.url), 'utf8')

test('the label is hidden when the listing says so', () => {
  assert.match(page, /v-if="showSpacesLeft && s\.capacity > 1"/)
})

test('a listing that says nothing still shows it, as before', () => {
  // Absent means show: an older server must behave exactly as it used to.
  assert.match(page, /show_slot_capacity !== false/)
})

test('hiding the label changes nothing about what can be booked', () => {
  // The flag is read only where the label renders; no booking rule consults it.
  const uses = [...page.matchAll(/showSpacesLeft/g)].length
  assert.equal(uses, 2, 'the flag should be declared once and read once')
  assert.equal(/showSpacesLeft[^\n]*(disabled|available|canBook|full)/.test(page), false)
})
