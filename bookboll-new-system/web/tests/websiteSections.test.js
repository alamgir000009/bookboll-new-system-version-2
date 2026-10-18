import test from 'node:test'
import assert from 'node:assert/strict'
import { websiteSectionEnabled } from '../src/lib/websiteSections.js'
import { WEBSITE_SECTIONS, readWebsiteSections, withWebsiteSections } from '../../admin/src/lib/websiteSections.js'

test('existing provider sites keep every section visible without new settings', () => {
  for (const pages of [undefined, {}, { home: {} }, { home: { hero_image: 'welcome.jpg' } }]) {
    const controls = readWebsiteSections(pages)
    for (const { key } of WEBSITE_SECTIONS) {
      assert.equal(controls[key], true)
      assert.equal(websiteSectionEnabled(pages, key), true)
    }
  }
})

test('visibility survives a site_pages JSON round trip without deleting content', () => {
  const original = {
    home: { hero_image: 'welcome.jpg', sections: { future_section: false } },
    about: { text: 'Our story', hero_title: 'About us' },
    contact: { phone: '+123456', address: 'Saved business address', maps_url: 'https://example.com/map' },
    future_page: { text: 'Keep this too' },
  }
  const draft = { ...readWebsiteSections(original), locations: false, about: false }
  const saved = JSON.parse(JSON.stringify(withWebsiteSections(original, draft)))
  assert.deepEqual(saved.about, original.about)
  assert.deepEqual(saved.contact, original.contact)
  assert.deepEqual(saved.future_page, original.future_page)
  assert.equal(saved.home.hero_image, original.home.hero_image)
  assert.equal(saved.home.sections.future_section, false)
  for (const { key } of WEBSITE_SECTIONS) assert.equal(websiteSectionEnabled(saved, key), draft[key])
  assert.deepEqual(readWebsiteSections(saved), draft)
  assert.equal(original.home.sections.locations, undefined, 'draft does not mutate persisted pages')
})

test('re-enabling a section restores its saved content and stays provider scoped', () => {
  const hidden = withWebsiteSections({ contact: { address: 'Saved address' } }, { locations: false })
  const shown = withWebsiteSections(hidden, { locations: true })
  assert.equal(websiteSectionEnabled(hidden, 'locations'), false)
  assert.equal(websiteSectionEnabled(shown, 'locations'), true)
  assert.equal(shown.contact.address, 'Saved address')
  assert.equal(websiteSectionEnabled(undefined, 'locations'), true, 'marketplace defaults are unchanged')
  assert.equal(websiteSectionEnabled({ home: {} }, 'locations'), true, 'other providers are unchanged')
})
