import test from 'node:test'
import assert from 'node:assert/strict'
import { sampleListingPhotos, catalogCategories, filterCatalog, groupCatalogVenues, readBrowseQuery, browseReturnPath } from '../src/lib/browseCatalog.js'

const listings = [
  { id: 1, locationId: 10, name: 'Padel court one', pinText: 'North Club', sport: 'Padel', category: 'sports', price: 120, photo: 'a.jpg' },
  { id: 2, locationId: 10, name: 'Football pitch', pinText: 'North Club', sport: 'Football', category: 'sports', price: 70, photo: 'b.jpg' },
  { id: 3, locationId: 20, name: 'Padel court two', pinText: 'South Club', sport: 'Padel', category: 'sports', price: 90, photo: 'c.jpg' },
]

test('photo stacks use distinct images from up to three different listings', () => {
  const photos = sampleListingPhotos([...listings, { id: 4, photo: 'a.jpg' }], () => 0.5)
  assert.equal(photos.length, 3)
  assert.equal(new Set(photos).size, 3)
  assert.deepEqual(sampleListingPhotos([{ photo: 'a', photos: ['b', 'c'] }]), ['a'])
  assert.deepEqual(sampleListingPhotos([{ photo: '' }, { photos: [] }]), [])
})

test('category cards count all matching listings without merging unrelated Other categories', () => {
  const categories = catalogCategories([...listings, { category: 'work', sport: 'Other' }, { category: 'sports', sport: 'Other' }])
  assert.equal(categories.find(c => c.label === 'Padel').listings.length, 2)
  assert.equal(categories.filter(c => c.label === 'Other').length, 2)
})

test('category order uses aggregate clicks rather than listing totals, with alphabetical ties', () => {
  assert.deepEqual(catalogCategories(listings, { 'sports:Football': 30, 'sports:Padel': 5 }).map(c => c.label), ['Football', 'Padel'])
  assert.deepEqual(catalogCategories(listings, { 'sports:Football': 5, 'sports:Padel': 30 }).map(c => c.label), ['Padel', 'Football'])
  for (const counts of [{}, { 'sports:Football': 0, 'sports:Padel': 0 }, { 'sports:Padel': -5 }, { 'sports:Padel': 'invalid' }]) {
    assert.deepEqual(catalogCategories(listings, counts).map(c => c.label), ['Football', 'Padel'])
  }
  assert.deepEqual(listings.map(l => l.id), [1, 2, 3])
})

test('search combines venue words, listing words and activity filters', () => {
  assert.deepEqual(filterCatalog(listings, { q: 'NORTH court', type: 'Padel' }).map(l => l.id), [1])
  assert.deepEqual(filterCatalog(listings, { q: 'Football', type: 'Padel' }), [])
})

test('venue prices and counts describe matching listings, not cheaper non-matching sports', () => {
  const results = groupCatalogVenues(filterCatalog(listings, { type: 'Padel' }), [{ id: '10', name: 'North Sports Club', price: 70 }])
  assert.equal(results.length, 2)
  assert.equal(results[0].name, 'North Sports Club')
  assert.equal(results[0].price, 120)
  assert.equal(results[0].listingCount, 1)
  assert.equal(results[0].sportsText, 'Padel')
})

test('venue grouping preserves zero prices and handles missing price data', () => {
  assert.equal(groupCatalogVenues([{ locationId: 1, price: 0 }, { locationId: 1, price: 25 }])[0].price, 0)
  assert.equal(groupCatalogVenues([{ locationId: 1 }])[0].price, null)
})

test('a direct visit defaults to listings and supports older explicit venue links', () => {
  assert.equal(readBrowseQuery({}).view, 'listings')
  assert.equal(readBrowseQuery({ view: 'location' }).view, 'venues')
  assert.equal(readBrowseQuery({ view: ['venues'], sort: 'invalid' }).sort, 'rec')
  assert.equal(readBrowseQuery({ type: 'Padel', q: 'North' }).type, 'Padel')
})

test('detail back links preserve local catalog filters without accepting external destinations', () => {
  assert.equal(browseReturnPath('/facilities?type=Padel&view=venues'), '/facilities?type=Padel&view=venues')
  assert.equal(browseReturnPath('/venues/doha/padel?q=North'), '/venues/doha/padel?q=North')
  for (const bad of ['https://example.com', '//example.com', '/account', ['/facilities'], null]) assert.equal(browseReturnPath(bad), '/facilities')
})
