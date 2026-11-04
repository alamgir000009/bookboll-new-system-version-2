import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { compile, createSSRApp } from 'vue'
import { renderToString } from '@vue/server-renderer'
import { validateHostBasics } from '../src/lib/hostBasics.js'

const base = {title: '', sport: '', max_players: 10, price_per_person: ''}
test('empty and whitespace-only required fields are reported together', () => {
  assert.deepEqual(Object.keys(validateHostBasics(base)), ['title', 'sport'])
  assert.deepEqual(Object.keys(validateHostBasics({...base,title:'  ',sport:'  '})), ['title','sport'])
  assert.deepEqual(validateHostBasics({...base,title:'Friday football',sport:'Football'}), {})
})
test('hangout activity stays optional and tournaments do not require a player limit', () => {
  assert.deepEqual(validateHostBasics({...base,title:'Sunday picnic'}, 'event'), {})
  assert.deepEqual(validateHostBasics({title:'Ramadan Cup',sport:'Football',price_per_person:0}, 'tournament'), {})
  assert.deepEqual(Object.keys(validateHostBasics({...base,max_players:1,price_per_person:-1})), ['title','sport','max_players','price_per_person'])
})
const source = readFileSync(new URL('../src/customer/pages/HostGame.vue',import.meta.url),'utf8')
test('real title and sport fields expose both errors after Continue and clear independently', async () => {
  const template = ['titleInput','sportInput'].map(ref => source.match(new RegExp(`<input ref="${ref}"[\\s\\S]*?/>`))[0]).join('')
  async function render(form, attempted) {
    return renderToString(createSSRApp({render:compile(template),setup:()=>({f:form,type:'game',mediaAttempted:attempted,basicsErrors:validateHostBasics(form),sportOpen:false,onSportBlur:()=>{}})}))
  }
  const before=await render({...base},false), after=await render({...base},true)
  assert.equal((before.match(/aria-invalid="true"/g)||[]).length,0)
  assert.equal((after.match(/aria-invalid="true"/g)||[]).length,2)
  assert.match(after,/aria-describedby="host-title-error"/)
  assert.match(after,/aria-describedby="host-sport-error"/)
  const partial=await render({...base,title:'Friday football'},true)
  assert.equal((partial.match(/aria-invalid="true"/g)||[]).length,1)
})
