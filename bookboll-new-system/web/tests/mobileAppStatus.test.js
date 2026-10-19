import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import {
  APP_AUDIENCES, clearMobileAppStatus, fetchMobileAppStatus,
  setMobileAppSelfReport, shouldOfferApp,
} from '../src/lib/mobileAppStatus.js'

/* The browser can only keep a per-device self-report. The server knows whether
 * this ACCOUNT has signed in from the native app, so it decides whether to
 * prompt — but a server that cannot answer must never make things worse than
 * the prompt already in place, and silence is not consent. */

const ok = (status) => async () => ({ success: true, status: 200, data: { status } })

test('an unanswered server leaves the existing prompt exactly as it was', async () => {
  for (const request of [
    async () => { throw new Error('offline') },
    async () => ({ success: false, status: 404 }),
    async () => ({ success: false, status: 403 }),
    async () => ({ success: true, status: 200, data: {} }),
  ]) {
    clearMobileAppStatus()
    const result = await fetchMobileAppStatus(request, 'customer', 'acc-1')
    assert.equal(result.known, false)
    // The page keeps its own behaviour in both directions.
    assert.equal(shouldOfferApp(result, true), true)
    assert.equal(shouldOfferApp(result, false), false)
  }
})

test('only an explicit suppression silences the prompt', async () => {
  clearMobileAppStatus()
  const suppressed = await fetchMobileAppStatus(ok({ app: 'customer', usage_status: 'used_app', suppress_prompt: true, last_authenticated_use_at: '2026-10-01T00:00:00Z' }), 'customer', 'acc-1')
  assert.equal(shouldOfferApp(suppressed, true), false)

  clearMobileAppStatus()
  // A partial payload with no suppression field must not be read as consent.
  const partial = await fetchMobileAppStatus(ok({ usage_status: 'used_app' }), 'customer', 'acc-1')
  assert.equal(partial.known, true)
  assert.equal(partial.status.suppress_prompt, false)
  assert.equal(shouldOfferApp(partial, true), true)
})

test('the server can take the prompt away but never add one the page would not show', async () => {
  clearMobileAppStatus()
  const unknown = await fetchMobileAppStatus(ok({ app: 'customer', usage_status: 'unknown', suppress_prompt: false }), 'customer', 'acc-1')
  assert.equal(shouldOfferApp(unknown, false), false)
})

test('unknown carries no evidence timestamps, whatever the payload says', async () => {
  clearMobileAppStatus()
  const { status } = await fetchMobileAppStatus(ok({
    app: 'customer', usage_status: 'unknown', suppress_prompt: false,
    first_authenticated_use_at: '2026-01-01T00:00:00Z',
    last_authenticated_use_at: '2026-02-01T00:00:00Z',
  }), 'customer', 'acc-1')

  // A date beside "unknown" would read as evidence nobody has.
  assert.equal(status.usage_status, 'unknown')
  assert.equal(status.first_authenticated_use_at, null)
  assert.equal(status.last_authenticated_use_at, null)
})

test('a self-report is never promoted to evidence of use', async () => {
  clearMobileAppStatus()
  const { status } = await fetchMobileAppStatus(ok({ app: 'customer', usage_status: 'unknown', self_reported: true, suppress_prompt: true }), 'customer', 'acc-1')

  assert.equal(status.self_reported, true)
  assert.equal(status.usage_status, 'unknown')
})

test('one identity never sees another account answer, and sign-out drops it', async () => {
  clearMobileAppStatus()
  const calls = []
  const request = async (path) => {
    calls.push(path)
    return { success: true, status: 200, data: { status: { app: 'customer', usage_status: 'used_app', suppress_prompt: true } } }
  }

  const first = await fetchMobileAppStatus(request, 'customer', 'acc-1')
  assert.equal(first.status.suppress_prompt, true)
  await fetchMobileAppStatus(request, 'customer', 'acc-1')
  assert.equal(calls.length, 1) // cached for this identity

  // A different account asks for itself, not for the cached answer.
  await fetchMobileAppStatus(request, 'customer', 'acc-2')
  assert.equal(calls.length, 2)

  clearMobileAppStatus('acc-1')
  await fetchMobileAppStatus(request, 'customer', 'acc-1')
  assert.equal(calls.length, 3)

  // And with no identity there is nothing to ask about.
  const anonymous = await fetchMobileAppStatus(request, 'customer', '')
  assert.equal(anonymous.known, false)
  assert.equal(calls.length, 3)
})

test('a failed answer is not cached, because the deploy may land mid-session', async () => {
  clearMobileAppStatus()
  let answer = { success: false, status: 404 }
  const request = async () => answer

  assert.equal((await fetchMobileAppStatus(request, 'customer', 'acc-1')).known, false)
  answer = { success: true, status: 200, data: { status: { app: 'customer', usage_status: 'used_app', suppress_prompt: true } } }
  assert.equal((await fetchMobileAppStatus(request, 'customer', 'acc-1')).known, true)
})

test('the customer and admin audiences ask separate endpoints', async () => {
  clearMobileAppStatus()
  const paths = []
  const request = async (path) => {
    paths.push(path)
    return { success: true, status: 200, data: { status: { app: 'customer', usage_status: 'unknown', suppress_prompt: false } } }
  }

  for (const audience of APP_AUDIENCES) await fetchMobileAppStatus(request, audience, 'acc-1')
  assert.deepEqual(paths, ['/customer/mobile-app-status', '/admin/mobile-app-status'])

  // An audience nobody defined asks nothing at all.
  assert.equal((await fetchMobileAppStatus(request, 'kiosk', 'acc-1')).known, false)
  assert.equal(paths.length, 2)
})

test('a self-report is only synced when the server says it stored it', async () => {
  clearMobileAppStatus()
  let sent = null
  const accepted = async (path, opts) => {
    sent = { path, body: opts?.body, method: opts?.method }
    return { success: true, status: 200, data: { status: { app: 'customer', usage_status: 'unknown', self_reported: true, suppress_prompt: true } } }
  }

  const good = await setMobileAppSelfReport(accepted, 'customer', 'acc-1', true)
  assert.equal(good.ok, true)
  assert.deepEqual(sent, { path: '/customer/mobile-app-preferences', body: { self_reported: true }, method: 'PUT' })

  // An API that returns 200 and stores nothing is not a success.
  const ignored = await setMobileAppSelfReport(async () => ({ success: true, status: 200, data: { status: { app: 'customer', usage_status: 'unknown', self_reported: false } } }), 'customer', 'acc-1', true)
  assert.equal(ignored.ok, false)
  assert.equal(ignored.synced, false)

  for (const request of [async () => { throw new Error('offline') }, async () => ({ success: false, status: 404 })]) {
    const failed = await setMobileAppSelfReport(request, 'customer', 'acc-1', true)
    assert.equal(failed.ok, false)
  }
})

test('a self-report can be undone', async () => {
  clearMobileAppStatus()
  const result = await setMobileAppSelfReport(
    async (_path, opts) => ({ success: true, status: 200, data: { status: { app: 'customer', usage_status: 'unknown', self_reported: opts.body.self_reported, suppress_prompt: opts.body.self_reported } } }),
    'customer', 'acc-1', false,
  )
  assert.equal(result.ok, true)
  assert.equal(result.status.self_reported, false)
  assert.equal(shouldOfferApp({ known: true, status: result.status }, true), true)
})

test('the two portals keep the same adapter, so one cannot drift', () => {
  const web = readFileSync(new URL('../src/lib/mobileAppStatus.js', import.meta.url), 'utf8')
  const admin = readFileSync(new URL('../../admin/src/lib/mobileAppStatus.js', import.meta.url), 'utf8')
  // Only the mirror comment names the other copy; everything else is identical.
  const strip = source => source.replace(/^\/\/ Account-wide mobile-app status.*$/m, '')
  assert.equal(strip(admin), strip(web))
})
