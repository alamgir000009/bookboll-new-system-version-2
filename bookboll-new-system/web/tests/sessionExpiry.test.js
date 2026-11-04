/**
 * A customer signed out from the admin panel, while their tab is open.
 *
 * Revoking a token invalidates it on the SERVER. The browser kept the stale
 * token and the page it had already fetched, so bookings would not submit and
 * lists never refreshed, while the header still showed the customer as signed
 * in. Only the first page load ever checked.
 *
 * The storefront is PUBLIC, which decides the shape of the answer here: nobody
 * is bounced to a login screen, because every route renders for a guest and a
 * reader part-way down a venue page should stay on it. The session is dropped
 * and the page reloads in place, signed out.
 */

import test from 'node:test'
import assert from 'node:assert/strict'

import {
  endExpiredSession,
  isSessionExpired,
  isSessionExpiryHandled,
  resetSessionExpiryLatch,
} from '../src/lib/sessionExpiry.js'

/** A browser just real enough for the module under test. */
function stubBrowser() {
  const store = new Map()
  let reloads = 0

  globalThis.localStorage = {
    getItem: key => (store.has(key) ? store.get(key) : null),
    setItem: (key, value) => store.set(key, String(value)),
    removeItem: key => store.delete(key),
  }
  globalThis.window = { location: { reload: () => { reloads += 1 } } }

  return { store, reloadCount: () => reloads }
}

function seedSession(store) {
  store.set('bb_customer_token', 'tok_live_123')
  store.set('bb_customer_identity', '{"id":9}')
  store.set('bb_customer_location', '{"country":{"id":167}}')
  store.set('bb_customer_theme', 'dark')
  store.set('bb_locale', 'ar')
}

test.beforeEach(() => resetSessionExpiryLatch())

// ── When it should fire ──────────────────────────────────────────────────────

test('a 401 on a request that carried a token ends the session', () => {
  assert.equal(isSessionExpired(401, 'tok_live_123'), true)
})

test('a guest browsing the marketplace is never reloaded', () => {
  // Most readers here have no account at all. A 401 on an anonymous call means
  // "sign in to do that" — it must not reload the page under them.
  assert.equal(isSessionExpired(401, ''), false)
  assert.equal(isSessionExpired(401, null), false)
  assert.equal(isSessionExpired(401, undefined), false)
})

test('no other status ends the session', () => {
  for (const status of [200, 400, 403, 404, 422, 500, 0]) {
    assert.equal(isSessionExpired(status, 'tok_live_123'), false, String(status))
  }
})

// ── What it clears ───────────────────────────────────────────────────────────

test('it clears the account keys', () => {
  const { store } = stubBrowser()
  seedSession(store)

  endExpiredSession()

  assert.equal(store.has('bb_customer_token'), false)
  assert.equal(store.has('bb_customer_identity'), false)
})

test('it keeps the location, theme and language this device chose', () => {
  // These belong to the browser, not to the account that just expired. Wiping
  // them would dump the reader back on the country chooser for something they
  // did not do.
  const { store } = stubBrowser()
  seedSession(store)

  endExpiredSession()

  assert.equal(store.get('bb_customer_location'), '{"country":{"id":167}}')
  assert.equal(store.get('bb_customer_theme'), 'dark')
  assert.equal(store.get('bb_locale'), 'ar')
})

test('it drops the token BEFORE reloading, so the reload cannot loop', () => {
  const { store } = stubBrowser()
  seedSession(store)

  let tokenAtReload = 'not captured'
  globalThis.window.location.reload = () => { tokenAtReload = store.get('bb_customer_token') }

  endExpiredSession()

  assert.equal(tokenAtReload, undefined)
})

// ── How it recovers ──────────────────────────────────────────────────────────

test('it reloads in place rather than sending anyone to a login screen', () => {
  const { store, reloadCount } = stubBrowser()
  seedSession(store)

  endExpiredSession()

  assert.equal(reloadCount(), 1)
})

test('it only acts once however many requests fail together', () => {
  // A revoked token 401s every in-flight request in the same tick.
  const { store, reloadCount } = stubBrowser()
  seedSession(store)

  endExpiredSession()
  endExpiredSession()
  endExpiredSession()

  assert.equal(reloadCount(), 1)
  assert.equal(isSessionExpiryHandled(), true)
})

test('it survives a browser that refuses storage', () => {
  let reloads = 0
  globalThis.localStorage = {
    getItem: () => { throw new Error('denied') },
    setItem: () => { throw new Error('denied') },
    removeItem: () => { throw new Error('denied') },
  }
  globalThis.window = { location: { reload: () => { reloads += 1 } } }

  assert.doesNotThrow(() => endExpiredSession())
  assert.equal(reloads, 1)
})
