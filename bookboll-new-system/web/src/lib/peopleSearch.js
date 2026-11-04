// The People tab's only source. Profiles come from the public people-search
// endpoint, never from admin customer data — that is tenant-scoped staff data
// carrying email, phone and booking history.
//
// Three outcomes have to stay distinguishable, because they read very
// differently to someone searching for a friend: the server could not be
// reached, the query was too short to search, or the search ran and found
// nobody. Collapsing them into "no people found" says something about the
// database that nobody checked.

export const PEOPLE_MIN_QUERY = 2
export const PEOPLE_PAGE_SIZE = 24

/** Only these fields are read, so a wider server payload cannot leak through. */
function personCard(row) {
  if (!row || typeof row.id !== 'string' || !row.id) return null
  // The link is built from the id we trust, not from a server-supplied path.
  return {
    id: row.id,
    url: `/u/${encodeURIComponent(row.id)}`,
    name: typeof row.name === 'string' && row.name.trim() ? row.name.trim() : 'Player',
    avatar: typeof row.avatar === 'string' ? row.avatar : '',
    isPrivate: row.isPrivate === true,
    hostedCount: Number.isFinite(Number(row.hostedCount)) ? Math.max(0, Number(row.hostedCount)) : 0,
    joinedCount: Number.isFinite(Number(row.joinedCount)) ? Math.max(0, Number(row.joinedCount)) : 0,
  }
}

function coverageOf(data) {
  const status = ['complete', 'at_least', 'not_searched'].includes(data?.coverage?.status) ? data.coverage.status : 'unknown'
  const total = Number(data?.coverage?.total)
  // A number next to a status we do not recognise cannot be interpreted, so it
  // is dropped rather than left where a caller might quote it as a total.
  const usable = status === 'complete' || status === 'at_least'
  return { status, total: usable && Number.isInteger(total) && total >= 0 ? total : null }
}

/**
 * One page of people.
 *
 * `unsupported` is its own outcome: a server that predates this endpoint is not
 * a failure the customer should see as an error — the tab keeps saying the
 * feature is coming rather than claiming something broke.
 */
export async function fetchPeople(request, { q = '', cursor = null, limit = PEOPLE_PAGE_SIZE } = {}) {
  const query = { q: String(q).trim().slice(0, 120), limit }
  if (cursor) query.cursor = cursor

  let res
  try {
    res = await request('/marketplace/users', { query })
  } catch {
    return { ok: false, unsupported: false, searched: false, users: [], coverage: { status: 'unknown', total: null }, nextCursor: null }
  }

  if ([404, 405, 501].includes(res?.status)) {
    return { ok: false, unsupported: true, searched: false, users: [], coverage: { status: 'unknown', total: null }, nextCursor: null }
  }
  if (!res?.success || !Array.isArray(res.data?.users)) {
    return { ok: false, unsupported: false, searched: false, users: [], coverage: { status: 'unknown', total: null }, nextCursor: null, message: typeof res?.message === 'string' ? res.message : '' }
  }

  const data = res.data
  return {
    ok: true,
    unsupported: false,
    // The server decides whether a query was searchable; the client's own
    // minimum is only there to avoid a pointless request.
    searched: data.searched === true,
    reason: typeof data.reason === 'string' ? data.reason : null,
    users: data.users.map(personCard).filter(Boolean),
    coverage: coverageOf(data),
    nextCursor: typeof data.next_cursor === 'string' && data.next_cursor ? data.next_cursor : null,
  }
}

/**
 * What to tell someone about a people result.
 *
 * A count is only quoted when the server said it counted everything. "at
 * least" is quoted as a floor, and anything else is quoted as what is on
 * screen — never as a total, which would imply the whole database was checked.
 */
export function peopleSummary(result, shown) {
  if (!result?.ok) return ''
  if (!result.searched) return `Enter at least ${PEOPLE_MIN_QUERY} characters to search for people.`
  const { status, total } = result.coverage
  if (status === 'complete' && total !== null) return `${total} ${total === 1 ? 'person' : 'people'}`
  if (status === 'at_least' && total !== null) return `More than ${total} people — keep typing to narrow it down`
  return `${shown} ${shown === 1 ? 'person' : 'people'} shown`
}
