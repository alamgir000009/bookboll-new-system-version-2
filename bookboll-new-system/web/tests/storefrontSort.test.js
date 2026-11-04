import test from 'node:test'
import assert from 'node:assert/strict'
import { applyStorefrontSort, resolveStorefrontSort, hasCoordinates, SORT_OPTIONS } from '../src/lib/storefrontSort.js'
import { readBrowseQuery, groupCatalogVenues } from '../src/lib/browseCatalog.js'
const rows = [
  { id: 'A', name: 'Zulu', price: '50', sortOrder: 1, createdAt: '2025-01-01', lat: 25.5, lng: 51.5 },
  { id: 'B', name: 'Alpha', price: 20, sortOrder: 2, createdAt: '2026-01-01', lat: 25.1, lng: 51.1 },
  { id: 'C', name: 'Beta', price: 0, sortOrder: 3, createdAt: '2024-01-01', lat: null, lng: null },
  { id: 'D', name: 'Delta', price: null, sortOrder: 4, createdAt: 'invalid', lat: '', lng: '' },
]
const ids = (key, coords = { lat: 25.1, lng: 51.1 }) => applyStorefrontSort(rows, { key, coords }).map(r => r.id)
test('all configured defaults stay authoritative even when location is known', () => {
  assert.deepEqual(ids('recommended'), ['A','B','C','D'])
  assert.deepEqual(ids('newest'), ['B','A','C','D'])
  assert.deepEqual(ids('price_asc'), ['C','B','A','D'])
  assert.deepEqual(ids('price_desc'), ['A','B','C','D'])
  assert.deepEqual(ids('name'), ['B','C','D','A'])
  assert.deepEqual(rows.map(r => r.id), ['A','B','C','D'])
})
test('closest is explicit and missing or malformed coordinates sort last', () => {
  assert.deepEqual(ids('nearest'), ['B','A','C','D'])
  assert.deepEqual(ids('nearest', null), ['A','B','C','D'])
  assert.equal(hasCoordinates({lat:0,lng:0}), true)
  for (const point of [null, {lat:'',lng:''}, {lat:'abc',lng:1}, {lat:91,lng:2}]) assert.equal(hasCoordinates(point), false)
})
test('provider and marketplace defaults are isolated for both views', () => {
  const platform = {space:'newest',venue:'name'}, provider = {space:'price_asc',venue:'price_desc'}
  assert.equal(resolveStorefrontSort('space', null, platform), 'newest')
  assert.equal(resolveStorefrontSort('venue', null, platform), 'name')
  assert.equal(resolveStorefrontSort('space', provider, platform), 'price_asc')
  assert.equal(resolveStorefrontSort('venue', provider, platform), 'price_desc')
  assert.equal(resolveStorefrontSort('space', {}, platform), 'recommended')
  assert.equal(resolveStorefrontSort('venue', {venue:'invalid'}, platform), 'recommended')
})
test('each customer view keeps its override and an absent override uses the admin default', () => {
  const query = { listing_sort:'nearest', venue_sort:'name', bid:'provider', preview:'1' }
  assert.equal(readBrowseQuery(query).sort, 'nearest')
  assert.equal(readBrowseQuery({...query,view:'venues'}).sort, 'name')
  assert.equal(readBrowseQuery({listing_sort:'price_desc',view:'venues'}).sort, 'rec')
  assert.equal(readBrowseQuery({sort:'price-asc'}).sort, 'price-asc')
  for (const option of SORT_OPTIONS) assert.equal(readBrowseQuery({listing_sort:option.key}).sort, option.key)
})
test('venue results use venue ranks and the starting price of matching listings', () => {
  const listings = rows.slice(0,3).map((r,i) => ({...r, locationId:i === 2 ? 2 : 1}))
  const venues = groupCatalogVenues(listings,[{id:1,name:'North',sortOrder:2},{id:2,name:'South',sortOrder:1}])
  assert.deepEqual(applyStorefrontSort(venues,{key:'recommended'}).map(v => v.id), ['2','1'])
  assert.deepEqual(applyStorefrontSort(venues,{key:'price_asc'}).map(v => v.price), [0,20])
})
