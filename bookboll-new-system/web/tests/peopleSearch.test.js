import test from 'node:test'
import assert from 'node:assert/strict'
import { PEOPLE_MIN_QUERY, fetchPeople, peopleSummary } from '../src/lib/peopleSearch.js'

/* Three outcomes must stay distinguishable, because they read completely
 * differently to someone looking for a friend: the server could not be
 * reached, the query was too short to search, or the search ran and found
 * nobody. "No people found" for all three says something about the database
 * that nobody checked. */

const ok = (data) => async () => ({ success: true, status: 200, data })

test('a search that ran and found nobody is not the same as one that never ran', async () => {
  const short = await fetchPeople(ok({ users: [], searched: false, reason: 'query_too_short', coverage: { status: 'not_searched', total: null }, next_cursor: null }), { q: 'a' })
  assert.equal(short.ok, true)
  assert.equal(short.searched, false)
  assert.equal(short.reason, 'query_too_short')
  assert.equal(peopleSummary(short, 0), `Enter at least ${PEOPLE_MIN_QUERY} characters to search for people.`)

  const none = await fetchPeople(ok({ users: [], searched: true, reason: null, coverage: { status: 'complete', total: 0 }, next_cursor: null }), { q: 'zzzz' })
  assert.equal(none.searched, true)
  assert.equal(peopleSummary(none, 0), '0 people')
})

test('an unreachable or failed server is reported as a failure, never as zero people', async () => {
  const thrown = await fetchPeople(async () => { throw new Error('offline') }, { q: 'ali' })
  assert.equal(thrown.ok, false)
  assert.equal(thrown.unsupported, false)
  assert.equal(peopleSummary(thrown, 0), '')

  const refused = await fetchPeople(async () => ({ success: false, status: 500, message: 'Server error' }), { q: 'ali' })
  assert.equal(refused.ok, false)
  assert.equal(refused.unsupported, false)

  // A 422 on a stale cursor is a failure to show, not an empty page.
  const stale = await fetchPeople(async () => ({ success: false, status: 422, message: 'That page reference is no longer valid.' }), { q: 'ali', cursor: 'old' })
  assert.equal(stale.ok, false)
  assert.equal(stale.users.length, 0)
})

test('a server without the endpoint keeps the tab honest instead of showing an error', async () => {
  for (const status of [404, 405, 501]) {
    const result = await fetchPeople(async () => ({ success: false, status }), { q: 'ali' })
    assert.equal(result.ok, false)
    assert.equal(result.unsupported, true)
  }
})

test('only the public card fields are read, and the link is built from the id we trust', async () => {
  const result = await fetchPeople(ok({
    searched: true,
    users: [{
      id: 'abc-123', name: ' Ali Raza ', avatar: 'https://cdn/x.jpg', isPrivate: true,
      hostedCount: '4', joinedCount: -2,
      // A wider payload must not flow into the UI, whatever the server sends.
      email: 'leak@example.com', phone: '+97450001111', bio: 'private bio',
      url: 'https://evil.example.com/u/abc-123',
    }],
    coverage: { status: 'complete', total: 1 }, next_cursor: null,
  }), { q: 'ali' })

  assert.deepEqual(Object.keys(result.users[0]), ['id', 'url', 'name', 'avatar', 'isPrivate', 'hostedCount', 'joinedCount'])
  assert.equal(result.users[0].name, 'Ali Raza')
  assert.equal(result.users[0].url, '/u/abc-123')
  assert.equal(result.users[0].hostedCount, 4)
  assert.equal(result.users[0].joinedCount, 0)
  assert.equal(JSON.stringify(result.users[0]).includes('leak@example.com'), false)
})

test('a row without a usable id is dropped rather than linking nowhere', async () => {
  const result = await fetchPeople(ok({ searched: true, users: [{ name: 'No Id' }, { id: '', name: 'Empty' }, { id: 'ok-1', name: 'Real' }], coverage: { status: 'complete', total: 3 } }), { q: 'x' })
  assert.deepEqual(result.users.map(u => u.name), ['Real'])
})

test('a count is only quoted as a total when the server said it counted everything', async () => {
  const complete = await fetchPeople(ok({ searched: true, users: [{ id: 'a', name: 'A' }], coverage: { status: 'complete', total: 1 } }), { q: 'a' })
  assert.equal(peopleSummary(complete, 1), '1 person')

  const floor = await fetchPeople(ok({ searched: true, users: [{ id: 'a', name: 'A' }], coverage: { status: 'at_least', total: 500 } }), { q: 'a' })
  assert.equal(peopleSummary(floor, 1), 'More than 500 people — keep typing to narrow it down')

  // An older or unknown coverage shape describes the screen, not the database.
  const unknown = await fetchPeople(ok({ searched: true, users: [{ id: 'a', name: 'A' }, { id: 'b', name: 'B' }], coverage: { status: 'guessed', total: 9999 } }), { q: 'a' })
  assert.equal(unknown.coverage.status, 'unknown')
  assert.equal(unknown.coverage.total, null)
  assert.equal(peopleSummary(unknown, 2), '2 people shown')
})

test('paging passes the cursor the server gave and stops when it stops', async () => {
  const calls = []
  const pages = {
    '': { users: [{ id: 'a', name: 'A' }], next_cursor: 'c1' },
    c1: { users: [{ id: 'b', name: 'B' }], next_cursor: null },
  }
  const request = async (path, { query }) => {
    calls.push(query)
    return { success: true, status: 200, data: { searched: true, coverage: { status: 'complete', total: 2 }, ...pages[query.cursor || ''] } }
  }

  const first = await fetchPeople(request, { q: 'ali', limit: 1 })
  assert.equal(first.nextCursor, 'c1')
  const second = await fetchPeople(request, { q: 'ali', cursor: first.nextCursor, limit: 1 })
  assert.equal(second.nextCursor, null)

  assert.deepEqual(calls, [{ q: 'ali', limit: 1 }, { q: 'ali', limit: 1, cursor: 'c1' }])
  assert.equal(calls.every(c => !('city_id' in c)), true) // people search is not city-scoped
})

test('the query is trimmed and capped before it leaves the browser', async () => {
  const calls = []
  await fetchPeople(async (path, { query }) => { calls.push({ path, query }); return { success: true, status: 200, data: { searched: true, users: [], coverage: { status: 'complete', total: 0 } } } }, { q: '  ' + 'a'.repeat(200) + '  ' })
  assert.equal(calls[0].path, '/marketplace/users')
  assert.equal(calls[0].query.q.length, 120)
})
