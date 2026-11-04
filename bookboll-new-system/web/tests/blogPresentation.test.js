import test from 'node:test'
import assert from 'node:assert/strict'
import { browsePosts, readableTags } from '../src/lib/blogPresentation.js'

test('blog topics use names, hide imported IDs and remove duplicates', () => {
  assert.deepEqual(readableTags(['1789558765694', {name:'Football'}, 'Football', ' Guides ', '0025f1db-ea42-4328-8b15-f16d5b70ae60', null]), ['Football', 'Guides'])
})
test('blog search finds all query terms without mutating newest-first source order', () => {
  const posts = [{id:1,title:'Old football guide',tags:['Qatar'],published_at:'2025-01-01'}, {id:2,title:'Café spaces',excerpt:'Book a studio',published_at:'2026-01-01'}, {id:3,title:'No date',tags:['1789558765694']}]
  assert.deepEqual(browsePosts(posts).map(p=>p.id),[2,1,3])
  assert.deepEqual(browsePosts(posts,' QATAR football ').map(p=>p.id),[1])
  assert.deepEqual(browsePosts(posts,'cafe studio').map(p=>p.id),[2])
  assert.deepEqual(browsePosts(posts,'1789558765694'),[])
  assert.deepEqual(posts.map(p=>p.id),[1,2,3])
})
