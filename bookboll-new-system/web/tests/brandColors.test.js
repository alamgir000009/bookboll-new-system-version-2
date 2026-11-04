import test from 'node:test'
import assert from 'node:assert/strict'
import { brandButtonText } from '../src/lib/brandColors.js'
import { brandButtonText as adminButtonText } from '../../admin/src/lib/brandColors.js'

test('provider button labels contrast with pale, gold and dark brand colors', () => {
  for (const color of ['#d7a754', '#fff', '#ffffff', '#ffff00']) assert.equal(brandButtonText(color), '#142132')
  for (const color of ['#0f766e', '#000', '#142132', '#1a66ff']) assert.equal(brandButtonText(color), '#ffffff')
  assert.equal(brandButtonText('invalid'), '#ffffff')
  for (const color of ['#ffffff', '#f8fafc', '#fde047', '#0f766e', '#1a66ff']) {
    assert.equal(adminButtonText(color), brandButtonText(color), `editor and storefront agree for ${color}`)
  }
})
