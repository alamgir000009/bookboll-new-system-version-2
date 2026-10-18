import test from 'node:test'
import assert from 'node:assert/strict'
import { validateHostMedia } from '../src/lib/hostMedia.js'

test('games and hangouts need a non-empty description and at least one uploaded photo', () => {
  assert.deepEqual(Object.keys(validateHostMedia({ description: '  ', images: [] })), ['description', 'images'])
  assert.deepEqual(Object.keys(validateHostMedia({ description: 'Friendly doubles.', images: ['', '  ', null] })), ['images'])
  assert.deepEqual(Object.keys(validateHostMedia({ description: '', images: ['https://uploads.test/photo.jpg'] })), ['description'])
  assert.deepEqual(validateHostMedia({ description: 'Friendly doubles.', images: ['https://uploads.test/photo.jpg'] }), {})
})

test('removing the last photo makes an otherwise complete draft invalid again', () => {
  const form = { description: 'Bring a racket.', images: ['cover.jpg'] }
  assert.deepEqual(validateHostMedia(form), {})
  form.images.splice(0, 1)
  assert.ok(validateHostMedia(form).images)
})

test('tournament descriptions are required without introducing an unsupported photo field', () => {
  assert.deepEqual(validateHostMedia({ description: 'Knockout tournament.' }, { photosRequired: false }), {})
  assert.ok(validateHostMedia({}, { photosRequired: false }).description)
})
