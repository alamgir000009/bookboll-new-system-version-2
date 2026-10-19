import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { parse as parseJs } from '@babel/parser'

/**
 * The analytics adapter (readmeAlamgir "Activity dashboard").
 *
 * The page used to add up whatever rows the server sent, which answers "how
 * many of the ones I received" rather than "how many". It now asks the server,
 * and the server says whether its answer is complete.
 *
 * "The frontend must reject stale or mismatched filter responses" — painting a
 * total for one selection over a different one is worse than showing nothing.
 */

/** Load the adapter with apiFetch replaced, so no network or env is needed. */
function loadAdapter(apiFetch) {
  const src = readFileSync(new URL('../src/lib/analytics.js', import.meta.url), 'utf8')
  let code = src
  for (const node of parseJs(code, { sourceType: 'module' }).program.body.slice().reverse()) {
    if (node.type === 'ImportDeclaration') {
      code = code.slice(0, node.start) + code.slice(node.end)
    } else if (node.type === 'ExportNamedDeclaration') {
      code = code.slice(0, node.start) + code.slice(node.start + 'export '.length, node.end) + code.slice(node.end)
    }
  }
  const factory = new Function('apiFetch', `${code}; return { loadAnalyticsAggregates }`)
  return factory(apiFetch)
}

const ok = (data) => ({ success: true, data })

test('reads the server totals when the echo matches what was asked', async () => {
  const { loadAnalyticsAggregates } = loadAdapter(async () => ok({
    aggregates_version: 1,
    as_of: '2026-10-19T00:00:00Z',
    filters: { from_date: '2026-03-01', to_date: '2026-03-31' },
    coverage: { status: 'complete', matched: 5200, included: 5200, missing_reason: null },
    sales: [{ currency: 'QAR', gross: 52000, bookings: 5200 }],
    bookings: { eligible: 3, customers: 2, per_customer: 1.5, repeat_customers: 1, repeat_pct: 50 },
    provider_slot_blocks: 7,
    series: [],
  }))

  const res = await loadAnalyticsAggregates({ from_date: '2026-03-01', to_date: '2026-03-31' })

  assert.equal(res.ok, true)
  assert.equal(res.coverage.status, 'complete')
  assert.equal(res.bookings.per_customer, 1.5)
  assert.equal(res.providerSlotBlocks, 7)
})

test('rejects a response that answers a different selection', async () => {
  // The filters changed while the request was in flight.
  const { loadAnalyticsAggregates } = loadAdapter(async () => ok({
    aggregates_version: 1,
    filters: { from_date: '2020-01-01' },
    coverage: { status: 'complete' },
    bookings: { eligible: 999 },
  }))

  const res = await loadAnalyticsAggregates({ from_date: '2026-03-01' })

  assert.equal(res.ok, false)
  assert.equal(res.stale, true)
  // Nothing from the stale payload leaks through.
  assert.equal(res.bookings, undefined)
})

test('refuses a server that does not know this contract', async () => {
  const { loadAnalyticsAggregates } = loadAdapter(async () => ok({
    // An older backend, or a half-finished deploy.
    filters: {}, coverage: { status: 'complete' }, bookings: { eligible: 5 },
  }))

  const res = await loadAnalyticsAggregates({})

  assert.equal(res.ok, false)
  assert.match(res.error, /does not provide complete totals/i)
})

test('ignores empty filter values when comparing the echo', async () => {
  // An unset control must not make every response look stale.
  const { loadAnalyticsAggregates } = loadAdapter(async () => ok({
    aggregates_version: 1,
    filters: { from_date: '2026-03-01', status: null },
    coverage: { status: 'complete' },
    bookings: { eligible: 1 },
  }))

  const res = await loadAnalyticsAggregates({ from_date: '2026-03-01', status: '', venue_id: null })

  assert.equal(res.ok, true)
})

test('reports a failed request instead of pretending the totals are zero', async () => {
  const { loadAnalyticsAggregates } = loadAdapter(async () => ({ success: false, message: 'Server exploded' }))

  const res = await loadAnalyticsAggregates({})

  assert.equal(res.ok, false)
  assert.equal(res.error, 'Server exploded')
  assert.equal(res.bookings, undefined)
})

test('the dashboard reads coverage from the server, not from how many rows arrived', () => {
  const raw = readFileSync(new URL('../src/pages/Dashboard.vue', import.meta.url), 'utf8')

  // Strip comments: the code explains WHY it no longer uses the row count, and
  // the explanation naturally quotes the test it replaced.
  const page = raw.replace(/\/\*[\s\S]*?\*\//g, '').replace(/\/\/.*$/gm, '')

  // The old test was the cap talking, not the data.
  assert.ok(!/bookings\.(value\.)?length\s*>=\s*5000/.test(page),
    'Dashboard still infers coverage from the row count')
  assert.ok(page.includes("coverage?.status === 'complete'"),
    'Dashboard should take coverage from the server response')
})
