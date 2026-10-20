import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { parse as parseJs } from '@babel/parser'

/* Copying a listing into a venue. The venue the user picks is sent explicitly
 * and the server authorizes it, so the adapter's job is simply never to send a
 * request that cannot mean anything. */

function load(apiFetch) {
  let code = readFileSync(new URL('../src/lib/listings.js', import.meta.url), 'utf8')
  for (const node of parseJs(code, { sourceType: 'module' }).program.body.slice().reverse()) {
    if (node.type === 'ImportDeclaration') code = code.slice(0, node.start) + code.slice(node.end)
    else if (node.type === 'ExportNamedDeclaration') code = code.slice(0, node.start) + code.slice(node.start + 'export '.length, node.end) + code.slice(node.end)
  }
  return new Function('apiFetch', `${code}; return { duplicateListing }`)(apiFetch)
}

test('a copy is never requested without a venue to copy into', async () => {
  let called = 0
  const { duplicateListing } = load(async () => { called++; return { success: true } })

  for (const args of [undefined, {}, { venueId: '' }, { venueId: null }]) {
    const res = await duplicateListing(7, args)
    assert.equal(res.success, false)
  }
  // Not even a request: there is no sensible venue to guess.
  assert.equal(called, 0)

  assert.equal((await duplicateListing(null, { venueId: '3' })).success, false)
  assert.equal(called, 0)
})

test('the chosen venue is sent, and a blank name is left to the server', async () => {
  let sent = null
  const { duplicateListing } = load(async (path, opts) => { sent = { path, ...opts }; return { success: true, data: {} } })

  await duplicateListing(7, { venueId: '12' })
  assert.equal(sent.path, '/admin/listings/dashboard/7/duplicate')
  assert.equal(sent.method, 'POST')
  // No name key at all, so the server picks "… (copy)" rather than receiving ''.
  assert.deepEqual(sent.body, { venue_id: '12' })

  await duplicateListing(7, { venueId: '12', name: '  9v9 - Abu Hamor  ' })
  assert.deepEqual(sent.body, { venue_id: '12', name: '9v9 - Abu Hamor' })
})

test('the dialog offers the venue list the server already scoped', () => {
  // An admin sees every venue and a provider only their own, because the page
  // reuses the scoped venue list rather than deciding scope for itself.
  const page = readFileSync(new URL('../src/pages/Listings.vue', import.meta.url), 'utf8')
  assert.match(page, /v-for="v in locations"[^>]*:value="String\(v\.id\)"/)
  // Copy is disabled until a venue is chosen.
  assert.match(page, /:disabled="!copyVenueId \|\| copyBusy"/)
})
