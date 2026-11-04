import test from 'node:test'
import assert from 'node:assert/strict'
import { BANNER_STARTERS, createBannerDraft, bannerDraftError } from '../src/lib/bannerEditor.js'
import { normalizeBanners, bannerButtons } from '../src/lib/heroBanners.js'

test('starter drafts survive saved banner normalization with their destination and audience', () => {
  const drafts = BANNER_STARTERS.map(item => createBannerDraft(item.key, true))
  assert.equal(new Set(drafts.map(item => item.id)).size, drafts.length)
  assert.equal(createBannerDraft('business', false), null)
  for (const b of drafts) {
    const saved = normalizeBanners([b])[0]
    assert.equal(saved.heading, b.heading)
    assert.equal(saved.audience, b.audience)
    assert.deepEqual(saved.actions, b.actions)
  }
  const booking = drafts[0], membership = drafts[1], recruitment = drafts[3]
  assert.equal(bannerButtons(booking)[0].key, 'book_venue')
  assert.equal(bannerButtons(membership)[0].key, 'get_membership')
  assert.equal(recruitment.audience, 'out')
  assert.notEqual(createBannerDraft('business', true).id, recruitment.id)
})
test('incomplete headings and destinations cannot be saved silently', () => {
  const b = createBannerDraft('blank')
  assert.match(bannerDraftError(b), /heading/)
  b.heading = 'Club news'
  assert.equal(bannerDraftError(b), '')
  b.actions = ['venue']
  assert.match(bannerDraftError(b), /Choose/)
  b.target = '12'
  assert.equal(bannerDraftError(b), '')
  b.actions = ['custom']
  for (const link of ['', 'javascript:alert(1)', '//example.com', '/\\example.com']) {
    b.link = link
    assert.notEqual(bannerDraftError(b), '')
  }
  for (const link of ['/facilities', 'https://example.com/offers']) {
    b.link = link
    assert.equal(bannerDraftError(b), '')
  }
})
