import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { parse, compileScript } from '@vue/compiler-sfc'
import { parse as parseJs } from '@babel/parser'
import * as vue from 'vue'
import QRCode from 'qrcode'
import jsQR from 'jsqr'
import { MOBILE_APPS, mobilePlatform, insideInstalledApp, appPromptContext, promptKey } from '../src/lib/mobileApps.js'

const freshPolicy = () => import(`../src/lib/mobileApps.js?test=${Math.random()}`)
function storage() { const data = new Map(); return { getItem: k => data.get(k) || null, setItem: (k,v) => data.set(k,v) } }

test('both portals ship identical app links, targeting, cadence and card behavior', () => {
  for (const path of ['lib/mobileApps.js', 'components/AppDownloadCard.vue']) {
    assert.equal(readFileSync(new URL(`../src/${path}`, import.meta.url), 'utf8'), readFileSync(new URL(`../../admin/src/${path}`, import.meta.url), 'utf8'))
  }
})

test('Android, iPhone, iPad desktop mode and real desktops select the right store path', () => {
  assert.equal(mobilePlatform({ userAgent: 'Mozilla/5.0 (Linux; Android 14) Chrome/120' }), 'android')
  assert.equal(mobilePlatform({ userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X)' }), 'ios')
  assert.equal(mobilePlatform({ userAgent: 'Mozilla/5.0 (iPad; CPU OS 18_0)' }), 'ios')
  assert.equal(mobilePlatform({ userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X)', platform: 'MacIntel', maxTouchPoints: 5 }), 'ios')
  assert.equal(mobilePlatform({ userAgent: 'Mozilla/5.0 (Windows NT 10.0)', maxTouchPoints: 10 }), 'desktop')
  assert.equal(mobilePlatform({ platform: 'MacIntel', maxTouchPoints: 0 }), 'desktop')
  assert.equal(mobilePlatform({}), 'desktop')
})

test('installed web apps and recognized native app shells do not get installation prompts', () => {
  assert.equal(insideInstalledApp({}, { standalone: true }), true)
  assert.equal(insideInstalledApp({ matchMedia: () => ({ matches: true }) }), true)
  assert.equal(insideInstalledApp({ ReactNativeWebView: {} }), true)
  assert.equal(insideInstalledApp({ Capacitor: { isNativePlatform: () => true } }), true)
  assert.equal(insideInstalledApp({}, { userAgent: 'BookbollAdminApp/1.0' }), true)
  assert.equal(insideInstalledApp({}, {}), false)
})

test('customer prompts only appear at signed-in marketplace booking moments', () => {
  const base = { audience: 'customer', signedIn: true }
  for (const path of ['/my-bookings','/my-games','/my-memberships','/my-communities','/account','/ar/my-bookings/']) assert.ok(appPromptContext({ ...base, path }))
  for (const path of ['/', '/facilities', '/host-game', '/listings/57', '/pay/10', '/thank-you', '/payment/cancel']) assert.equal(appPromptContext({ ...base, path }), null)
  assert.ok(appPromptContext({ ...base, completed: true }))
  assert.ok(appPromptContext({ ...base, path:'/u/me', accountId:'me' }))
  assert.equal(appPromptContext({ ...base, path:'/u/another', accountId:'me' }), null)
  for (const excluded of [{ whiteLabel: true }, { embedded: true }, { signedIn: false }]) {
    assert.equal(appPromptContext({ ...base, path: '/my-bookings', completed: true, ...excluded }), null)
  }
})

test('admin prompts wait for a business user and only show on booking-management pages', () => {
  const base = { audience: 'admin', signedIn: true, businessUser: true }
  for (const path of ['/calendar','/bookings']) assert.ok(appPromptContext({ ...base, path }))
  assert.equal(appPromptContext({ ...base, path: '/bookings', businessUser: false }), null)
  assert.equal(appPromptContext({ ...base, path: '/calendar', signedIn: false }), null)
  for (const path of ['/listings','/settings','/login','/customers']) assert.equal(appPromptContext({ ...base, path }), null)
})

test('showing, closing or clicking a download never suppresses a later page visit', async () => {
  const p = await freshPolicy(), s = storage()
  for (const audience of ['customer','admin']) for (const action of ['shown','dismissed','download']) {
    p.rememberAppPrompt(audience, action, s, 'account-one')
    assert.equal((await freshPolicy()).canShowAppPrompt(audience, s, 'account-one'), true)
    assert.equal(s.getItem(promptKey(audience,'account-one')), null)
  }
})

test('already-have is a self-report scoped to the account and app, never download evidence', async () => {
  const p = await freshPolicy(), s = storage()
  p.rememberAppPrompt('customer', 'installed', s, 'account-one')
  assert.deepEqual(JSON.parse(s.getItem(promptKey('customer','account-one'))), {selfReported:true})
  assert.equal((await freshPolicy()).canShowAppPrompt('customer',s,'account-one'),false)
  assert.equal(p.canShowAppPrompt('customer',s,'account-two'),true)
  assert.equal(p.canShowAppPrompt('admin',s,'account-one'),true)
  assert.equal(p.canShowAppPrompt('unknown',s,'account-one'),false)
})

test('old browser-wide cooldowns cannot suppress the new per-visit policy or another account', async () => {
  const p = await freshPolicy(), s = storage()
  s.setItem('bb_app_prompt_v1_customer',JSON.stringify({until:Date.now()+99999999,installed:true}))
  assert.equal(p.canShowAppPrompt('customer',s,'account-one'),true)
})

test('malformed and blocked storage remain usable while remembering a self-report in this tab', async () => {
  const p = await freshPolicy(), unavailable = { getItem() { throw Error('blocked') }, setItem() { throw Error('blocked') } }
  assert.equal(p.canShowAppPrompt('customer',{getItem:()=>'{bad json'},'one'),true)
  p.rememberAppPrompt('customer','installed',unavailable,'one')
  assert.equal(p.canShowAppPrompt('customer',unavailable,'one'),false)
  assert.equal(p.canShowAppPrompt('customer',unavailable,'two'),true)
})

test('both QR codes decode to their exact OneLink URL, with no localhost or user identifiers', () => {
  assert.equal(MOBILE_APPS.customer.download, 'https://onelink.to/m8a4mk')
  assert.equal(MOBILE_APPS.admin.download, 'https://onelink.to/e8duqh')
  for (const url of Object.values(MOBILE_APPS).map(app => app.download)) {
    const matrix = QRCode.create(url, { errorCorrectionLevel: 'M' }).modules
    const margin = 4, scale = 5, size = (matrix.size + margin * 2) * scale
    const pixels = new Uint8ClampedArray(size * size * 4).fill(255)
    for (let y = 0; y < matrix.size; y++) for (let x = 0; x < matrix.size; x++) {
      if (!matrix.get(y,x)) continue
      for (let dy = 0; dy < scale; dy++) for (let dx = 0; dx < scale; dx++) {
        const i = (((y + margin) * scale + dy) * size + (x + margin) * scale + dx) * 4
        pixels[i] = 16; pixels[i+1] = 29; pixels[i+2] = 42
      }
    }
    assert.equal(jsQR(pixels,size,size)?.data,url)
    assert.match(url,/^https:\/\/onelink.to\//)
  }
})

async function cardSetup(t, { platform = 'desktop', installed = false, audience = 'customer', accountId = 'account-one', saved = null } = {}) {
  const source = readFileSync(new URL('../src/components/AppDownloadCard.vue',import.meta.url),'utf8')
  let code = compileScript(parse(source).descriptor,{id:'app-download-test'}).content
  for (const node of parseJs(code,{sourceType:'module'}).program.body.slice().reverse()) {
    if (node.type === 'ImportDeclaration') {
      const declarations = node.specifiers.map(s => `const ${s.local.name} = imports[${JSON.stringify(node.source.value)}][${JSON.stringify(s.type === 'ImportDefaultSpecifier' ? 'default' : s.imported.name)}];`).join('\n')
      code = code.slice(0,node.start) + declarations + code.slice(node.end)
    } else if (node.type === 'ExportDefaultDeclaration') code = code.slice(0,node.start) + 'return ' + code.slice(node.declaration.start)
  }
  const p = await freshPolicy(), s = storage(), mounted = [], unmounted = [], events = [], timers = new Map()
  if (saved) s.setItem(promptKey(audience, accountId), JSON.stringify(saved))
  let timerId = 0
  const document = { editing: false, overlays: [], activeElement: { matches: () => document.editing }, querySelectorAll: () => document.overlays, body: {}, addEventListener(){}, removeEventListener(){} }
  const env = {
    document, window: { localStorage:s, addEventListener(){},removeEventListener(){},ReactNativeWebView:installed || undefined },
    navigator: { userAgent: platform === 'ios' ? 'iPhone' : platform === 'android' ? 'Android' : 'Windows NT 10.0' },
    MutationObserver: class { observe(){} disconnect(){} }, ResizeObserver: class { observe(){} disconnect(){} },
    requestAnimationFrame: () => 1, cancelAnimationFrame(){}, getComputedStyle: () => ({visibility:'visible'}),
    setTimeout: fn => { timers.set(++timerId,fn); return timerId }, clearTimeout: id => timers.delete(id),
  }
  const imports = { vue:{...vue,onMounted:fn=>mounted.push(fn),onUnmounted:fn=>unmounted.push(fn)}, qrcode:{ default:{toDataURL:async url=>`qr:${url}`} }, 'lucide-vue-next':{}, '../lib/mobileApps.js':p }
  for (const name of ['bookboll-customer.png','bookboll-admin.png','app-store.webp','google-play.png']) imports[`../assets/mobile-apps/${name}`] = {default:name}
  const props = vue.reactive({ audience,accountId,context:{ title:'Bookings',body:'Get the app' },blocked:false })
  const scope = vue.effectScope()
  const state = scope.run(() => new Function('imports','env',`const {${Object.keys(env).join(',')}} = env;\n${code}`)(imports,env).setup(props,{expose(){},emit:(...e)=>events.push(e)}))
  mounted.forEach(fn=>fn())
  await vue.nextTick()
  const tick = async () => { for (const [id, fn] of [...timers]) { timers.delete(id); fn() } await vue.nextTick(); await vue.nextTick() }
  t.after(() => { unmounted.forEach(fn=>fn()); scope.stop() })
  return {state,props,document,s,events,tick}
}

test('card waits, shows the customer QR and keeps dismissal only for this visit', async t => {
  const c = await cardSetup(t)
  assert.equal(c.state.visible.value,false)
  await c.tick()
  assert.equal(c.state.visible.value,true)
  assert.equal(c.state.downloadUrl.value,MOBILE_APPS.customer.download)
  assert.equal(c.state.qr.value,`qr:${MOBILE_APPS.customer.download}`)
  assert.equal(c.state.appIcon.value,'bookboll-customer.png')
  c.state.dismiss(); await vue.nextTick()
  assert.equal(c.state.visible.value,false)
  c.props.blocked = true; await vue.nextTick()
  c.props.blocked = false; await vue.nextTick(); await c.tick()
  assert.equal(c.state.visible.value,false)
  c.props.context = null; await vue.nextTick()
  c.props.context = {title:'My events'}; await vue.nextTick(); await c.tick()
  assert.equal(c.state.visible.value,true)
  assert.equal(c.s.getItem(promptKey('customer','account-one')),null)
})

test('typing, dialogs and explicit blocking suppress a card without consuming its opportunity', async t => {
  const c = await cardSetup(t)
  c.props.blocked = true; await vue.nextTick(); await c.tick()
  assert.equal(c.state.visible.value,false)
  assert.equal(c.s.getItem(promptKey('customer','account-one')),null)
  c.props.blocked = false
  c.document.overlays = [{ closest:()=>null, getClientRects:()=>[{}] }]; c.state.detectObstruction()
  await vue.nextTick(); await c.tick(); assert.equal(c.state.visible.value,false)
  c.document.overlays = []; c.state.detectObstruction(); await vue.nextTick(); await c.tick()
  assert.equal(c.state.visible.value,true)
  c.document.editing = true; c.state.detectObstruction(); assert.equal(c.state.visible.value,false)
  c.document.editing = false; c.state.detectObstruction(); assert.equal(c.state.visible.value,true)
  c.props.context = null; await vue.nextTick(); assert.equal(c.state.visible.value,false)
  c.props.context = {title:'My events'}; await vue.nextTick(); await c.tick()
  assert.equal(c.state.visible.value,true)
})

test('Android and iOS both use the business smart link, green icon and no QR', async t => {
  for (const platform of ['android','ios']) {
    const c = await cardSetup(t,{platform})
    c.props.audience = 'admin'; await vue.nextTick(); await c.tick()
    assert.equal(c.state.visible.value,true)
    assert.equal(c.state.platform.value,platform)
    assert.equal(c.state.downloadUrl.value,MOBILE_APPS.admin.download)
    assert.equal(c.state.appIcon.value,'bookboll-admin.png')
    assert.equal(c.state.qr.value,'')
  }
})

test('native app wrapper stays quiet even when a route would otherwise qualify', async t => {
  const c = await cardSetup(t,{installed:true}); await c.tick()
  assert.equal(c.state.visible.value,false)
  c.state.open(); await vue.nextTick()
  assert.equal(c.state.visible.value,false)
})

test('admin Calendar and Bookings prompts return after each page visit', async t => {
  const c = await cardSetup(t, { audience: 'admin' })
  await c.tick(); assert.equal(c.state.visible.value, true)
  assert.equal(c.s.getItem(promptKey('admin','account-one')), null)
  c.props.context = null; await vue.nextTick()
  assert.equal(c.state.visible.value, false)
  c.props.context = { title: 'Calendar' }; await vue.nextTick(); await c.tick()
  assert.equal(c.state.visible.value, true)
  c.state.dismiss(); await vue.nextTick()
  c.props.context = null; await vue.nextTick()
  c.props.context = { title: 'Bookings' }; await vue.nextTick(); await c.tick()
  assert.equal(c.state.visible.value, true)
})

test('explicitly reopening the admin card honors native exclusion and retains the self-report', async t => {
  for (const saved of [{ selfReported: true }]) {
    const c = await cardSetup(t, { audience: 'admin', saved })
    await c.tick(); assert.equal(c.state.visible.value, false)
    c.state.open(); await vue.nextTick(); await c.tick()
    assert.equal(c.state.visible.value, true)
    assert.deepEqual(JSON.parse(c.s.getItem(promptKey('admin','account-one'))), saved)
    c.props.blocked = true; await vue.nextTick()
    assert.equal(c.state.visible.value, false)
    c.props.blocked = false; await vue.nextTick()
    assert.equal(c.state.visible.value, true)
  }
})

test('the closed mobile navigation drawer does not block prompts; an open drawer does', async t => {
  const c = await cardSetup(t, { audience: 'admin' })
  let inert = true
  c.document.overlays = [{ closest: () => inert ? {} : null, getClientRects: () => [{}] }]
  c.state.detectObstruction(); await vue.nextTick(); await c.tick()
  assert.equal(c.state.visible.value, true)
  inert = false; c.state.detectObstruction(); await vue.nextTick()
  assert.equal(c.state.visible.value, false)
  inert = true; c.state.detectObstruction(); await vue.nextTick()
  assert.equal(c.state.visible.value, true)
})


test('switching accounts resets the visit and does not inherit another account self-report', async t => {
  const c = await cardSetup(t, { saved: {selfReported:true} })
  await c.tick(); assert.equal(c.state.visible.value,false)
  c.props.accountId = 'account-two'; await vue.nextTick(); await c.tick()
  assert.equal(c.state.visible.value,true)
  c.state.dismiss('installed'); await vue.nextTick()
  assert.equal(c.state.visible.value,false)
  c.props.accountId = 'account-three'; await vue.nextTick(); await c.tick()
  assert.equal(c.state.visible.value,true)
})


test('same-copy pages still re-trigger by visit key while profile refreshes do not undo dismissal', async t => {
  const c = await cardSetup(t)
  await c.tick(); c.state.dismiss(); await vue.nextTick()
  c.props.context = {...c.props.context}; await vue.nextTick(); await c.tick()
  assert.equal(c.state.visible.value,false)
  c.props.visitKey = '/another-trigger'; await vue.nextTick(); await c.tick()
  assert.equal(c.state.visible.value,true)
})
