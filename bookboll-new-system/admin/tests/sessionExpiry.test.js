/**
 * Being signed out from the admin panel, while a tab is open.
 *
 * Revoking a token invalidates it on the SERVER. The browser kept holding the
 * stale token and the screen it had already fetched, so every action after
 * that quietly failed and the operator carried on working against a session
 * that no longer existed. Only the first page load ever checked.
 *
 * These pin the three judgements that make the new check safe to run on every
 * single response: it fires only for requests that carried a token, it fires
 * once however many requests fail together, and it clears the account's keys
 * without touching the ones that belong to the device.
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
function stubBrowser(pathname = '/bookings') {
  const store = new Map()
  const replaced = []

  globalThis.localStorage = {
    getItem: key => (store.has(key) ? store.get(key) : null),
    setItem: (key, value) => store.set(key, String(value)),
    removeItem: key => store.delete(key),
  }
  globalThis.window = {
    location: { pathname, replace: url => replaced.push(url) },
  }

  return { store, replaced }
}

function seedSession(store) {
  store.set('bb_admin_token', 'tok_live_123')
  store.set('bb_active_user', '{"id":1}')
  store.set('bb_customer_identity', '{"id":9}')
  store.set('bb_locale', 'ar')
  store.set('bb_admin_theme', 'dark')
}

test.beforeEach(() => resetSessionExpiryLatch())

// ── When it should fire ──────────────────────────────────────────────────────

test('a 401 on a request that carried a token ends the session', () => {
  assert.equal(isSessionExpired(401, 'tok_live_123'), true)
})

test('a 401 on an anonymous request does not', () => {
  // "Sign in to do that" is a normal answer, not a revoked session.
  assert.equal(isSessionExpired(401, ''), false)
  assert.equal(isSessionExpired(401, null), false)
  assert.equal(isSessionExpired(401, undefined), false)
})

test('no other status ends the session', () => {
  // A wrong password is a 400 from this API, so the login screen cannot put
  // itself into a redirect loop.
  for (const status of [200, 400, 403, 404, 422, 500, 0]) {
    assert.equal(isSessionExpired(status, 'tok_live_123'), false, String(status))
  }
})

// ── What it clears ───────────────────────────────────────────────────────────

test('it clears the account keys and leaves the device ones alone', () => {
  const { store } = stubBrowser()
  seedSession(store)

  endExpiredSession()

  assert.equal(store.has('bb_admin_token'), false)
  assert.equal(store.has('bb_active_user'), false)
  assert.equal(store.has('bb_customer_identity'), false)

  // Language and theme are properties of this browser, not of the account that
  // just expired — resetting them would be an unexplained change to the user.
  assert.equal(store.get('bb_locale'), 'ar')
  assert.equal(store.get('bb_admin_theme'), 'dark')
})

test('it drops the token BEFORE redirecting, so the reload cannot loop', () => {
  // Boot calls /me with the stored token. If the redirect happened first, the
  // reloaded page would send the same dead token and bounce again, forever.
  const { store, replaced } = stubBrowser()
  seedSession(store)

  let tokenAtRedirect = 'not captured'
  globalThis.window.location.replace = () => { tokenAtRedirect = store.get('bb_admin_token') }

  endExpiredSession()

  assert.equal(tokenAtRedirect, undefined)
  assert.deepEqual(replaced, [])
})

// ── How it redirects ─────────────────────────────────────────────────────────

test('it sends an expired session to the login screen', () => {
  const { store, replaced } = stubBrowser('/bookings')
  seedSession(store)

  endExpiredSession()

  assert.deepEqual(replaced, ['/login'])
})

test('it only acts once however many requests fail together', () => {
  // A revoked token 401s every in-flight request in the same tick.
  const { store, replaced } = stubBrowser('/bookings')
  seedSession(store)

  endExpiredSession()
  endExpiredSession()
  endExpiredSession()

  assert.deepEqual(replaced, ['/login'])
  assert.equal(isSessionExpiryHandled(), true)
})

test('it does not reload the login screen out from under a half-typed email', () => {
  const { store, replaced } = stubBrowser('/login')
  seedSession(store)

  endExpiredSession()

  assert.deepEqual(replaced, [])
  // Clearing still happened — that was the whole job on this page.
  assert.equal(store.has('bb_admin_token'), false)
  // And the latch is released, so a later expiry elsewhere still works.
  assert.equal(isSessionExpiryHandled(), false)
})

test('it survives a browser that refuses storage', () => {
  // Private windows and blocked site data throw on every access.
  const replaced = []
  globalThis.localStorage = {
    getItem: () => { throw new Error('denied') },
    setItem: () => { throw new Error('denied') },
    removeItem: () => { throw new Error('denied') },
  }
  globalThis.window = { location: { pathname: '/bookings', replace: url => replaced.push(url) } }

  assert.doesNotThrow(() => endExpiredSession())
  assert.deepEqual(replaced, ['/login'])
})
