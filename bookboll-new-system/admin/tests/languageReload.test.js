import test from 'node:test'
import assert from 'node:assert/strict'
import { reloadInLanguage as web } from '../../web/src/lib/languageReload.js'
import { reloadInLanguage as admin } from '../src/lib/languageReload.js'
import { reloadInLanguage as business } from '../../business/src/lib/languageReload.js'

for (const [app,reload] of Object.entries({web,admin,business})) {
  function env(href='https://example.test/listings/59?bid=36648&preview=1#times',baseUrl='/') {
    const calls=[]
    return { calls, storage:{setItem:(key,value)=>calls.push(['save',key,value])}, location:{href,reload:()=>calls.push(['reload']),assign:url=>calls.push(['assign',url])},baseUrl }
  }
  test(`${app}: save the selected language before reloading the current route`,()=>{
    const e=env(); assert.equal(reload('es','en',e),true)
    assert.deepEqual(e.calls,[['save','bb_locale','es'],['reload']])
  })
  test(`${app}: selecting the active language does not reload or write`,()=>{
    const e=env(); assert.equal(reload('en','en',e),false);assert.deepEqual(e.calls,[])
  })
  test(`${app}: leaving Arabic route preserves business preview and hash`,()=>{
    const e=env('https://example.test/ar/listings/59?bid=36648&preview=1#times')
    reload('en','ar',e)
    assert.deepEqual(e.calls,[['save','bb_locale','en'],['assign','https://example.test/listings/59?bid=36648&preview=1#times']])
  })
  test(`${app}: supports a deployment subdirectory and Arabic homepage`,()=>{
    const e=env('https://example.test/portal/ar?bid=7#home','/portal/')
    reload('fr','ar',e)
    assert.equal(e.calls[1][1],'https://example.test/portal/?bid=7#home')
  })
  test(`${app}: does not navigate if persisting language fails`,()=>{
    const e=env();e.storage.setItem=()=>{throw Error('blocked')}
    assert.throws(()=>reload('ar','en',e),/blocked/);assert.deepEqual(e.calls,[])
  })
  test(`${app}: Arabic selection and ordinary ar-prefixed slugs stay on their route`,()=>{
    const e=env('https://example.test/arabic-tours?preview=1');reload('ar','en',e)
    assert.deepEqual(e.calls,[['save','bb_locale','ar'],['reload']])
    const other=env('https://example.test/arabic-tours');reload('en','ar',other)
    assert.equal(other.calls[1][0],'reload')
  })
}
