import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { parse, compileScript } from '@vue/compiler-sfc'
import { parse as parseJs } from '@babel/parser'
import * as vue from 'vue'
import * as banners from '../src/lib/heroBanners.js'
import * as editor from '../src/lib/bannerEditor.js'

let code = compileScript(parse(readFileSync(new URL('../src/pages/Banners.vue', import.meta.url), 'utf8')).descriptor, { id: 'banners-test' }).content
for (const node of parseJs(code, { sourceType: 'module' }).program.body.slice().reverse()) {
  if (node.type === 'ImportDeclaration') code = code.slice(0, node.start) + node.specifiers.map(s => `const ${s.local.name}=imports[${JSON.stringify(node.source.value)}][${JSON.stringify(s.type === 'ImportDefaultSpecifier' ? 'default' : s.imported.name)}];`).join('\n') + code.slice(node.end)
  else if (node.type === 'ExportDefaultDeclaration') code = code.slice(0, node.start) + 'return ' + code.slice(node.declaration.start)
}
async function mount(t, { premium = true, loadSuccess = true, saveSuccess = true } = {}) {
  const scope = vue.effectScope(), writes = [], noop = () => {}
  t.after(() => scope.stop())
  const imports = new Proxy({
    vue: { ...vue, onMounted: noop },
    '../lib/currentUser.js': { useCurrentUser: () => ({ currentOwnerUserId: vue.ref(1), isSuperAdmin: vue.ref(false), role: vue.ref('provider_admin') }) },
    '../lib/premium.js': { usePremium: () => ({ isPremium: vue.ref(premium), requirePremium: () => premium }) },
    '../lib/toast.js': { useToast: () => ({ success: noop, error: noop }) },
    '../lib/heroBanners.js': banners,
    '../lib/bannerEditor.js': editor,
    '../lib/referenceData.js': { loadReferenceData: async () => ({ countries: [], cities: [], listings: [] }) },
    '../lib/subscriptions.js': { fetchSubscriptionsDashboard: async () => ({ success: true, data: { programs: [] } }) },
    '../lib/siteSettings.js': {
      loadSiteSettings: async () => ({ success: loadSuccess, settings: { hero_banners: [] } }),
      saveSiteSettings: async patch => { writes.push(structuredClone(patch)); return { success: saveSuccess } },
    },
  }, { get: (o, key) => o[key] || new Proxy({}, { get: () => noop }) })
  const s = scope.run(() => new Function('imports', 'document', code)(imports, { getElementById: () => null }).setup({}, { expose: noop }))
  await s.load()
  return { s, writes }
}
test('starter, reorder and undo stay in the draft until save', async t => {
  const { s, writes } = await mount(t)
  await s.chooseStarter('booking'); await s.chooseStarter('membership')
  assert.equal(writes.length, 0)
  assert.equal(s.expandedIds.value.size, 1)
  s.moveBanner(1, -1)
  assert.equal(s.banners.value[0].action, 'get_membership')
  s.removeBanner(0); s.undoRemove()
  assert.equal(s.banners.value[0].action, 'get_membership')
  await s.save()
  assert.equal(writes.length, 1)
  assert.equal(writes[0].hero_banners.length, 2)
  assert.equal(writes[0].hero_banners[0].action, 'get_membership')
  assert.equal(s.dirty.value, false)
})
test('premium restriction and load failure do not create or overwrite banners', async t => {
  const free = await mount(t, { premium: false })
  await free.s.chooseStarter('booking')
  assert.equal(free.s.banners.value.length, 0)
  const failed = await mount(t, { loadSuccess: false })
  await failed.s.save()
  assert.ok(failed.s.loadError.value)
  assert.equal(failed.writes.length, 0)
})
test('validation and failed saves keep the edited draft', async t => {
  const { s, writes } = await mount(t, { saveSuccess: false })
  await s.chooseStarter('blank'); await s.save()
  assert.equal(writes.length, 0)
  assert.ok(s.editorErrors.value[s.banners.value[0].id])
  s.banners.value[0].heading = 'News from the club'
  await s.save()
  assert.equal(writes.length, 1)
  assert.equal(s.dirty.value, true)
  assert.equal(s.saving.value, false)
  assert.equal(s.banners.value[0].heading, 'News from the club')
})
