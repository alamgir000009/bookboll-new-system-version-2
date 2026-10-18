import test from 'node:test'
import assert from 'node:assert/strict'
import { defaultBanners, visibleBanners, recruitmentBanner, bannerButtons } from '../src/lib/heroBanners.js'

test('default guest slideshow includes editable business recruitment as its second slide', () => {
  const defaults = defaultBanners({platform:true})
  assert.equal(defaults[1].action,'business')
  assert.equal(visibleBanners(defaults,{signedIn:false}).length,2)
  assert.equal(visibleBanners(defaults,{signedIn:true}).some(b=>b.action==='business'),false)
  assert.equal(visibleBanners([{...recruitmentBanner(),audience:'all'}],{signedIn:true}).length,0)
  assert.equal(defaultBanners({platform:false}).some(b=>b.action==='business'),false)
})
test('saved recruitment copy and geography survive normalization and are respected', () => {
  const saved = [{...recruitmentBanner(),heading:'Bring your club to Doha',countries:[179],cities:[123],button_text:'List your club',bg:'#123456'}]
  const banners = visibleBanners(saved,{countryId:'179',cityId:'123',signedIn:false})
  assert.equal(banners.length,1)
  assert.equal(banners[0].heading,saved[0].heading)
  assert.equal(banners[0].bg,'#123456')
  assert.equal(bannerButtons(banners[0])[0].text,'List your club')
  assert.equal(visibleBanners(saved,{countryId:167,cityId:123,signedIn:false}).length,0)
})
