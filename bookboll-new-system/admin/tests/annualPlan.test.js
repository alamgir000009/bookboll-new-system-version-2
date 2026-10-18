import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'

test('Premium checkout always requests annual billing, including stale callers requesting monthly', async () => {
  const source = readFileSync(new URL('../src/lib/plan.js', import.meta.url), 'utf8').replace(/^import .*$/gm, '').replace(/\bexport /g, '')
  const requests = []
  const { startPlanCheckout } = new Function('apiFetch', source + '\nreturn { startPlanCheckout };')((path, options) => { requests.push({ path, ...options }); return Promise.resolve({ success: true }) })
  await startPlanCheckout({ billing: 'monthly', promoCode: 'SAVE', returnPath: '/settings' })
  await startPlanCheckout()
  assert.equal(requests[0].body.billing, 'yearly')
  assert.equal(requests[0].body.promo_code, 'SAVE')
  assert.equal(requests[0].body.return_path, '/settings')
  assert.equal(requests[1].body.billing, 'yearly')
})
