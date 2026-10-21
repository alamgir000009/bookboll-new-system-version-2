import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { parse as parseJs } from '@babel/parser'

/* The owner review path for own-site listings that take no online bookings.
 *
 * Payment on arrival was removed from that channel, so a listing set to cash
 * only now takes nothing. The spec is explicit about both halves: give the
 * owner a way to pick a supported method, and never pick for them.
 *
 * So the things worth pinning are the refusals: no method, no listings, a
 * method that cannot work for every listing picked, and a bulk apply that
 * partly failed reported as partly failed. */

function load(apiFetch) {
  let code = readFileSync(new URL('../src/lib/ownSiteReview.js', import.meta.url), 'utf8')
  for (const node of parseJs(code, { sourceType: 'module' }).program.body.slice().reverse()) {
    if (node.type === 'ImportDeclaration') {
      code = code.slice(0, node.start) + code.slice(node.end)
    } else if (node.type === 'ExportNamedDeclaration') {
      code = code.slice(0, node.start) + code.slice(node.start + 'export '.length, node.end) + code.slice(node.end)
    }
  }
  return new Function('apiFetch', `${code}; return { RESOLUTIONS, EMPTY_QUEUE, loadOwnSiteReview, applyOwnSiteMethod, applySummary }`)(apiFetch)
}

const queueRow = (over = {}) => ({
  id: 7, name: 'Cash Only Court', venue_name: 'North Club', reason: 'legacy_cash_only',
  on_marketplace: true,
  preview: { currency: 'QAR', base_price: 100, effective_rate_pct: 20, plan_eligible: false, deposit_due_now: 20, arrival_balance: 80 },
  available: { deposit: true, online: false, hide: true },
  online_blocked_reason: 'payouts_not_connected',
  ...over,
})

const queueBody = (rows, over = {}) => ({
  success: true, status: 200,
  data: { listings: rows, total: rows.length, coverage: { status: 'complete', total: rows.length }, resolutions: ['deposit', 'online', 'hide'], ...over },
})

test('the queue reports whether its count covers everything', async () => {
  const { loadOwnSiteReview } = load(async () => queueBody([queueRow()], { total: 42, coverage: { status: 'partial', total: null } }))
  const partial = await loadOwnSiteReview()

  assert.equal(partial.ok, true)
  assert.equal(partial.queue.total, 42)
  // "3 listings need attention" meaning "3 on this page" sends an owner away
  // believing they are done.
  assert.equal(partial.queue.complete, false)

  const { loadOwnSiteReview: loadComplete } = load(async () => queueBody([queueRow()]))
  assert.equal((await loadComplete()).queue.complete, true)
})

test('a method the server did not confirm is unavailable, not offered', async () => {
  const { loadOwnSiteReview } = load(async () => queueBody([
    queueRow({ available: { deposit: true, hide: true } }),          // online absent
    queueRow({ id: 8, available: undefined }),                        // nothing stated
  ]))

  const { queue } = await loadOwnSiteReview()
  assert.deepEqual(queue.listings[0].available, { deposit: true, online: false, hide: true })
  assert.deepEqual(queue.listings[1].available, { deposit: false, online: false, hide: false })
})

test('a row without a usable id is dropped rather than offered as a target', async () => {
  const { loadOwnSiteReview } = load(async () => queueBody([
    queueRow(), { name: 'No Id' }, { id: '9', name: 'String Id' },
  ], { total: 3 }))

  const { queue } = await loadOwnSiteReview()
  assert.deepEqual(queue.listings.map(l => l.id), [7])
})

test('an older server without the endpoint is not shown as an error', async () => {
  for (const status of [404, 405]) {
    const { loadOwnSiteReview } = load(async () => ({ success: false, status }))
    const result = await loadOwnSiteReview()
    assert.equal(result.unsupported, true)
    assert.equal(result.queue.total, 0)
  }

  // A real failure is a different outcome and does get reported.
  const { loadOwnSiteReview } = load(async () => ({ success: false, status: 500, message: 'Server error' }))
  const failed = await loadOwnSiteReview()
  assert.equal(failed.unsupported, false)
  assert.equal(failed.error, 'Server error')
})

test('nothing is ever sent without a method the owner named', async () => {
  let called = 0
  const { applyOwnSiteMethod } = load(async () => { called++; return queueBody([]) })

  for (const method of [undefined, '', 'auto', 'cash', 'deposit_or_online']) {
    const result = await applyOwnSiteMethod(method, [7])
    assert.equal(result.ok, false)
  }
  // Not even a request: there is no default to fall back on.
  assert.equal(called, 0)
})

test('nothing is sent without listings either', async () => {
  let called = 0
  const { applyOwnSiteMethod } = load(async () => { called++; return queueBody([]) })

  for (const ids of [undefined, [], ['abc'], [null], [1.5]]) {
    assert.equal((await applyOwnSiteMethod('deposit', ids)).ok, false)
  }
  assert.equal(called, 0)
})

test('the owner choice is sent exactly as picked, deduplicated', async () => {
  let sent = null
  const { applyOwnSiteMethod } = load(async (path, opts) => {
    sent = { path, method: opts.method, body: opts.body }
    return { success: true, status: 200, data: { applied: [{ listing_id: 7, name: 'Cash Only Court', method: 'deposit' }], refused: [], remaining: 0 } }
  })

  const result = await applyOwnSiteMethod('deposit', [7, 7, 8])
  assert.deepEqual(sent, { path: '/admin/listings/own-site-review', method: 'POST', body: { method: 'deposit', listing_ids: [7, 8] } })
  assert.equal(result.ok, true)
  assert.equal(result.remaining, 0)
})

test('a partial apply is reported as partial, with each refusal named', async () => {
  const { applyOwnSiteMethod, applySummary } = load(async () => ({
    success: true, status: 200,
    data: {
      applied: [{ listing_id: 7, name: 'Cash Only Court', method: 'deposit' }],
      refused: [
        { listing_id: 8, name: 'Other Court', reason: 'already_resolved' },
        { listing_id: 9, name: 'Third Court', reason: 'payouts_not_connected' },
        { listing_id: 10, reason: 'not_found' },
      ],
      remaining: 2,
    },
  }))

  const result = await applyOwnSiteMethod('deposit', [7, 8, 9, 10])
  assert.equal(result.ok, true)
  assert.equal(result.applied.length, 1)
  // Named, because "3 left unchanged" is not something an owner can act on.
  assert.deepEqual(result.refused.map(r => r.id), [8, 9, 10])
  assert.match(result.refused[1].label, /Connect payouts/)
  assert.match(result.refused[2].label, /No longer available/)
  assert.equal(applySummary(result), '1 listing updated · 3 left unchanged')
  assert.equal(result.remaining, 2)
})

test('an apply that changed nothing is not a success, whatever the status code', async () => {
  const { applyOwnSiteMethod } = load(async () => ({
    success: true, status: 200,
    data: { applied: [], refused: [{ listing_id: 7, name: 'Cash Only Court', reason: 'payouts_not_connected' }], remaining: 1 },
  }))

  const result = await applyOwnSiteMethod('online', [7])
  assert.equal(result.ok, false)
  assert.match(result.error, /None of those listings/)
})

test('a thrown or refused request never reads as applied', async () => {
  for (const request of [
    async () => { throw new Error('offline') },
    async () => ({ success: false, status: 422, message: 'Choose what should happen to these listings.' }),
  ]) {
    const { applyOwnSiteMethod } = load(request)
    const result = await applyOwnSiteMethod('deposit', [7])
    assert.equal(result.ok, false)
    assert.ok(result.error)
  }
})

test('the panel offers a method only when every selected listing can take it', () => {
  // The panel's own guard, read from the component so it cannot drift from the
  // availability the server reported per listing.
  const raw = readFileSync(new URL('../src/components/OwnSiteReviewPanel.vue', import.meta.url), 'utf8')
  assert.match(raw, /every\(l => l\.available\[method\]\)/)

  // Comments stripped: the prose in this component explains why there is no
  // "fix all", and would match the check meant to prove there isn't one.
  const code = raw
    .replace(/<!--[\s\S]*?-->/g, '')
    .replace(/\/\*[\s\S]*?\*\//g, '')
    .replace(/^\s*\/\/.*$/gm, '')

  // No shortcut that picks for the owner, and nothing that switches a method
  // on without one being named.
  assert.equal(/fix ?all/i.test(code), false)
  assert.equal(/site_deposit_enabled/.test(code), false)
})

/* Two things a super admin found on the live dashboard. */

test('a token the theme never defines is a bug, not a fallback', () => {
  // The panel invented --warn-bg / --warn-ink / --warn-border, which exist
  // nowhere, so every rule fell through to a hardcoded LIGHT fallback. In dark
  // mode the cream panel kept its cream while the text turned light, and the
  // heading became unreadable. The dashboard already ships light and dark
  // values for --warning-*; the fix is to use the tokens that exist.
  const css = readFileSync(new URL('../src/components/OwnSiteReviewPanel.vue', import.meta.url), 'utf8')
    .split('<style')[1] || ''
  const theme = readFileSync(new URL('../src/dashboardTheme.css', import.meta.url), 'utf8')

  // Every custom property the panel reads must be defined by the theme.
  const used = [...css.matchAll(/var\((--[\w-]+)/g)].map(m => m[1])
  assert.ok(used.length > 0)
  for (const token of new Set(used)) {
    assert.ok(theme.includes(`${token}:`), `${token} is used but never defined`)
  }

  // And defined for BOTH themes, so the panel cannot be light-only again.
  const [light, dark] = theme.split('html.adm-dark')
  for (const token of ['--warning-bg', '--warning-ink', '--warning-border']) {
    assert.ok(light.includes(`${token}:`), `${token} missing from the light palette`)
    assert.ok(dark.includes(`${token}:`), `${token} missing from the dark palette`)
  }
})

test('the panel states its own text colours instead of inheriting the page', () => {
  // It keeps its own background in both themes, so text stated against the
  // page rather than against that background is how the heading disappeared.
  const css = readFileSync(new URL('../src/components/OwnSiteReviewPanel.vue', import.meta.url), 'utf8')
  assert.match(css, /\.osr-head h2 \{[^}]*color: var\(--warning-ink\)/)
  assert.match(css, /\.osr-head p \{[^}]*color: var\(--text\)/)
})

test('platform staff may restore a channel but not reroute money', async () => {
  // The deposit IS Bookboll's commission, so the venue is paid the same on
  // arrival. Full online payment settles into that business's own wallet, so
  // the server withholds it and the dashboard never offers it.
  const { loadOwnSiteReview } = load(async () => queueBody([queueRow()], {
    viewer: { scope: 'platform', allowed_methods: ['deposit', 'hide'] },
  }))

  const { queue } = await loadOwnSiteReview()
  assert.equal(queue.scope, 'platform')
  assert.deepEqual(queue.allowedMethods, ['deposit', 'hide'])
})

test('a business keeps all three', async () => {
  const { loadOwnSiteReview } = load(async () => queueBody([queueRow()], {
    viewer: { scope: 'business', allowed_methods: ['deposit', 'online', 'hide'] },
  }))

  const { queue } = await loadOwnSiteReview()
  assert.deepEqual(queue.allowedMethods, ['deposit', 'online', 'hide'])
})

test('a server that names no methods offers none', async () => {
  // Showing a button for an action the server would refuse is worse than
  // showing nothing; silence is never read as permission.
  const { loadOwnSiteReview } = load(async () => queueBody([queueRow()]))

  const { queue } = await loadOwnSiteReview()
  assert.deepEqual(queue.allowedMethods, [])
})

test('a method the server did not name is dropped, not trusted', async () => {
  const { loadOwnSiteReview } = load(async () => queueBody([queueRow()], {
    viewer: { scope: 'platform', allowed_methods: ['deposit', 'transfer_ownership'] },
  }))

  const { queue } = await loadOwnSiteReview()
  assert.deepEqual(queue.allowedMethods, ['deposit'])
})

test('the panel only renders the methods the server allowed, and confirms when acting for others', () => {
  const source = readFileSync(new URL('../src/components/OwnSiteReviewPanel.vue', import.meta.url), 'utf8')

  // Buttons come from the allowed list, not from every resolution.
  assert.match(source, /v-for="method in offeredMethods"/)
  assert.match(source, /allowedMethods\.value\.includes\(method\)/)
  // One click can span several businesses, so its scope is stated first.
  assert.match(source, /if \(isPlatform\.value\) \{[\s\S]{0,400}askConfirm\(/)
  assert.match(source, /on their behalf/)
  // And no shortcut that picks for anyone.
  const code = source
    .replace(/<!--[\s\S]*?-->/g, '')
    .replace(/\/\*[\s\S]*?\*\//g, '')
    .replace(/^\s*\/\/.*$/gm, '')
  assert.equal(/fix ?all/i.test(code), false)
  assert.equal(/site_deposit_enabled/.test(code), false)
})
