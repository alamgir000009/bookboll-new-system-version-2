import test from 'node:test'
import assert from 'node:assert/strict'
import { readSearchQuery, searchCatalog, fetchSearchCatalog } from '../src/lib/marketplaceSearch.js'
import { durationLabel } from '../src/lib/slots.js'
import { browseReturnPath } from '../src/lib/browseCatalog.js'

test('search combines words across listing and venue fields and ignores case/accents', () => {
  const items = [
    { id: 1, name: 'Indoor Padel', pinText: 'Café Club', sport: 'Padel' },
    { id: 2, name: 'Padel court', pinText: 'South Club', sport: 'Padel' },
  ]
  assert.deepEqual(searchCatalog(items, ' CAFE  padel ', 'listings').map(x => x.id), [1])
  assert.deepEqual(searchCatalog(items, 'padel football', 'listings'), [])
  assert.deepEqual(searchCatalog(items, '', 'listings'), [])
})

test('exact names rank ahead of description matches and unrelated private fields are not searched', () => {
  const communities = [
    { id: 1, name: 'Weekend club', description: 'We play football' },
    { id: 2, name: 'Football' },
    { id: 3, name: 'Private notes', email: 'football@example.com' },
  ]
  assert.deepEqual(searchCatalog(communities, 'football', 'communities').map(x => x.id), [2, 1])
  assert.equal(searchCatalog([{ title: 'Open play', hostName: 'Aisha', sport: 'Padel' }], 'aisha padel', 'events').length, 1)
  assert.equal(searchCatalog([{ name: 'Junior academy', sport: 'Tennis', description: 'After school' }], 'tennis school', 'memberships').length, 1)
})

test('URL state rejects unsupported types and return links stay on local search/catalog pages', () => {
  assert.deepEqual(readSearchQuery({ q: ['one', 'two'], type: 'admin' }), { q: '', type: 'all' })
  assert.equal(readSearchQuery({ q: 'a'.repeat(140) }).q.length, 120)
  assert.equal(browseReturnPath('/search?q=padel&type=listings'), '/search?q=padel&type=listings')
  assert.equal(browseReturnPath('/ar/search?q=padel'), '/ar/search?q=padel')
  for (const url of ['//example.com/search', 'https://example.com/search', '/search-elsewhere', '/search/../admin']) {
    assert.equal(browseReturnPath(url), '/facilities')
  }
})

test('search uses the existing public catalog endpoints with the selected city and country', async () => {
  const requests = []
  const request = async (path, options) => {
    requests.push({ path, ...options })
    const key = path.split('/').pop()
    return { success: true, data: { [key]: [{ id: key }] } }
  }
  const result = await fetchSearchCatalog(request, { city: { id: 'city-uuid', name: 'Doha' }, country: { iso_code: 'QA' } })
  assert.deepEqual(requests.map(r => r.path), ['/marketplace/listings', '/marketplace/communities', '/marketplace/programs', '/marketplace/games'])
  assert.deepEqual(requests[0].query, { city: 'Doha', country_iso: 'QA' })
  assert.deepEqual(requests[1].query, { city_id: 'city-uuid' })
  assert.deepEqual(requests[2].query, { city_id: 'city-uuid', country_iso: 'QA' })
  assert.equal(result.catalog.memberships[0].id, 'programs')
  assert.equal(result.catalog.events[0].id, 'games')
  assert.deepEqual(result.failed, [])
})

test('a failing catalog does not hide successful results or turn failures into no matches', async () => {
  const result = await fetchSearchCatalog(async path => {
    if (path.endsWith('/communities')) throw new Error('offline')
    if (path.endsWith('/programs')) return { success: false }
    if (path.endsWith('/games')) return { success: true, data: {} }
    return { success: true, data: { listings: [{ id: 16 }] } }
  })
  assert.deepEqual(result.catalog.listings, [{ id: 16 }])
  assert.deepEqual(result.failed, ['communities', 'memberships', 'events'])
  const paths = []
  await fetchSearchCatalog(async path => { paths.push(path); return { success: true, data: { listings: [] } } }, { enabled: ['listings'] })
  assert.deepEqual(paths, ['/marketplace/listings'])
})

test('durations keep whole hours and remaining minutes without decimal rounding', () => {
  for (const [input, expected] of [[30, '30 min'], [60, '1 hr'], [75, '1 hr 15 min'], [90, '1 hr 30 min'], ['120', '2 hr'], [135, '2 hr 15 min']]) {
    assert.equal(durationLabel(input), expected)
  }
  for (const input of [undefined, null, 'bad', -30, Infinity, 0]) assert.equal(durationLabel(input), '')
})
