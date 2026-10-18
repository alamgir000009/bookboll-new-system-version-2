import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { ref } from 'vue'
import { parse, compileScript, compileTemplate, compileStyle } from '@vue/compiler-sfc'
import { CONSENT_MAX_AGE, parseConsent, restrictTracking, consentRevoked, removableTrackingCookie } from '../src/lib/cookieConsent.js'

const config = {ga4:'G-ABC123',metaPixel:'123456789',gtm:'GTM-ABC123',head:'<script>custom()</script>'}
test('unknown, outdated, expired and malformed consent deny optional storage', () => {
  for (const value of [null,'bad','{}',JSON.stringify({version:0,analytics:true,marketing:true,savedAt:100}),JSON.stringify({version:1,analytics:'true',marketing:true,savedAt:100})]) assert.equal(parseConsent(value,100),null)
  const saved = JSON.stringify({version:1,analytics:true,marketing:false,savedAt:100})
  assert.deepEqual(parseConsent(saved,101),{version:1,necessary:true,analytics:true,marketing:false,savedAt:100})
  assert.equal(parseConsent(saved,100+CONSENT_MAX_AGE),null)
  assert.equal(parseConsent(saved,99),null)
})
test('categories control their own tags; unclassified containers require both', () => {
  assert.deepEqual(restrictTracking(config,null),{ga4:'',metaPixel:'',gtm:'',head:''})
  assert.deepEqual(restrictTracking(config,{analytics:true,marketing:false}),{ga4:config.ga4,metaPixel:'',gtm:'',head:''})
  assert.deepEqual(restrictTracking(config,{analytics:false,marketing:true}),{ga4:'',metaPixel:config.metaPixel,gtm:'',head:''})
  assert.deepEqual(restrictTracking(config,{analytics:true,marketing:true}),config)
  assert.equal(consentRevoked({analytics:true,marketing:true},{analytics:true,marketing:false}),true)
  assert.equal(consentRevoked(null,{analytics:true}),false)
})
test('cleanup only removes known cookies in denied categories', () => {
  for (const name of ['_ga','_ga_ABC','_gid','_gat_ABC','_fbp','_fbc','_gcl_au']) assert.equal(removableTrackingCookie(name,{}),true)
  for (const name of ['laravel_session','XSRF-TOKEN','bb_checkout','session','_garden']) assert.equal(removableTrackingCookie(name,{}),false)
  assert.equal(removableTrackingCookie('_ga',{analytics:true}),false)
  assert.equal(removableTrackingCookie('_fbp',{marketing:true}),false)
})

function policyEnvironment() {
  const source=readFileSync(new URL('../src/lib/cookieConsent.js',import.meta.url),'utf8').replace(/^import .*$/gm,'').replace(/export /g,'')
  const saved=new Map(), listeners={}, reloads=[], removals=[]
  const storage={getItem:key=>saved.get(key)||null,setItem:(key,value)=>saved.set(key,value),removeItem:key=>saved.delete(key)}
  const window={localStorage:storage,location:{hostname:'www.example.com',pathname:'/bookings',reload:()=>reloads.push([...saved])},addEventListener:(event,fn)=>listeners[event]=fn}
  const document={get cookie(){return '_ga=test; laravel_session=keep'},set cookie(value){removals.push(value)}}
  const api=new Function('ref','window','document',source+'\nreturn {initCookieConsent,saveCookieConsent,cookieConsent,consentKey,markTrackingStarted}')(ref,window,document)
  return {api,saved,listeners,reloads,removals,window}
}
test('decisions persist per storefront, reload safely on withdrawal and follow other tabs', () => {
  const {api,saved,listeners,reloads,removals}=policyEnvironment()
  api.initCookieConsent('marketplace')
  assert.equal(api.cookieConsent.value,null)
  api.saveCookieConsent({analytics:true,marketing:true})
  api.markTrackingStarted()
  api.saveCookieConsent({analytics:false,marketing:true})
  assert.equal(reloads.length,1)
  assert.equal(JSON.parse(saved.get(api.consentKey())).analytics,false)
  assert.ok(removals.some(v=>v.startsWith('_ga=;')))
  assert.ok(removals.every(v=>!v.includes('laravel_session')))
  listeners.storage({key:api.consentKey(),newValue:null})
  assert.equal(api.cookieConsent.value,null)
  api.initCookieConsent('storefront:42')
  assert.equal(api.cookieConsent.value,null)
  assert.notEqual(api.consentKey(),api.consentKey('marketplace'))
})
test('blocked browser storage still applies the choice for the current visit', () => {
  const {api,window}=policyEnvironment()
  Object.defineProperty(window,'localStorage',{get(){throw Error('blocked')}})
  api.initCookieConsent('marketplace')
  assert.equal(api.saveCookieConsent({analytics:false,marketing:false}),false)
  assert.equal(api.cookieConsent.value.analytics,false)
})

function trackingEnvironment(choice) {
  const source=readFileSync(new URL('../src/lib/tracking.js',import.meta.url),'utf8').replace(/^import .*$/gm,'').replace(/export /g,'')
  const nodes=[],events=[]
  const document={head:{appendChild:n=>nodes.push(n)},body:{insertBefore:n=>nodes.push(n)},querySelectorAll:()=>[],createElement:tag=>({tag,attrs:{},setAttribute(k,v){this.attrs[k]=v},remove(){}})}
  const window={gtag:(...args)=>events.push(['ga',...args]),fbq:(...args)=>events.push(['meta',...args])}
  const api=new Function('document','window','restrictTracking','trackingAllowed','markTrackingStarted',source+'\nreturn {injectTracking,trackPageView}')(document,window,c=>restrictTracking(c,choice),key=>choice?.[key]===true,()=>{})
  return {api,nodes,events}
}
test('no tracking scripts or page views run before consent, including preexisting globals', () => {
  const {api,nodes,events}=trackingEnvironment(null)
  api.injectTracking(config);api.trackPageView('/my-bookings')
  assert.equal(nodes.length,0);assert.equal(events.length,0)
})
test('analytics-only injection excludes ads and custom code and defaults advertising consent to denied', () => {
  const {api,nodes,events}=trackingEnvironment({analytics:true,marketing:false})
  api.injectTracking(config);api.trackPageView('/my-bookings')
  assert.ok(nodes.some(n=>n.attrs.src?.includes('/gtag/js?id=G-ABC123')))
  const text=nodes.map(n=>n.textContent||'').join('\n')
  assert.match(text,/ad_storage:'denied'/)
  assert.ok(!text.includes('fbq')&&!text.includes('custom()')&&!text.includes('gtm.js'))
  assert.deepEqual(events.map(e=>e[0]),['ga'])
})
test('marketing-only injection excludes analytics and mixed-purpose containers', () => {
  const {api,nodes,events}=trackingEnvironment({analytics:false,marketing:true})
  api.injectTracking(config);api.trackPageView('/my-bookings')
  assert.ok(nodes.some(n=>n.textContent?.includes('fbevents.js')))
  assert.ok(nodes.every(n=>!n.attrs.src?.includes('googletagmanager')))
  assert.deepEqual(events.map(e=>e[0]),['meta'])
})
test('all portals share consent behavior and the preferences UI compiles', () => {
  for (const path of ['lib/cookieConsent.js','components/CookieConsent.vue']) {
    const source=readFileSync(new URL('../src/'+path,import.meta.url),'utf8')
    for (const app of ['admin','business']) assert.equal(readFileSync(new URL('../../'+app+'/src/'+path,import.meta.url),'utf8'),source)
  }
  const source=readFileSync(new URL('../src/components/CookieConsent.vue',import.meta.url),'utf8')
  const {descriptor,errors}=parse(source)
  assert.deepEqual(errors,[])
  const script=compileScript(descriptor,{id:'cookie-consent-test'})
  assert.deepEqual(compileTemplate({source:descriptor.template.content,filename:'CookieConsent.vue',id:'cookie-consent-test',compilerOptions:{bindingMetadata:script.bindings}}).errors,[])
})

test('contrast palettes apply to their cards without changing the page theme variables', () => {
  for (const name of ['AppDownloadCard','CookieConsent']) {
    const {descriptor}=parse(readFileSync(new URL(`../src/components/${name}.vue`,import.meta.url),'utf8'))
    const css=compileStyle({source:descriptor.styles[0].content,id:'data-v-theme-check',scoped:true}).code
    const rules=css.match(/html\.[^{}]+\{[^}]+\}/g)
    assert.ok(rules?.length)
    for (const rule of rules) assert.match(rule.split('{')[0],/\[data-v-theme-check\]/)
  }
})
