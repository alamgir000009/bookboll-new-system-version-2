import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { parse, compileScript } from '@vue/compiler-sfc'
import { parse as parseJs } from '@babel/parser'
import * as vue from 'vue'
import * as sectionSettings from '../../admin/src/lib/websiteSections.js'

// Exercise the dashboard's real save/load logic with a local in-memory API.
// No provider account, server settings or production data is changed.
const source = readFileSync(new URL('../../admin/src/pages/Brand.vue', import.meta.url), 'utf8')
let compiled = compileScript(parse(source).descriptor, { id: 'section-saving-test' }).content
for (const node of parseJs(compiled, { sourceType: 'module' }).program.body.slice().reverse()) {
  if (node.type === 'ImportDeclaration') {
    const declarations = node.specifiers.map(s => `const ${s.local.name} = imports[${JSON.stringify(node.source.value)}][${JSON.stringify(s.type === 'ImportDefaultSpecifier' ? 'default' : s.imported.name)}];`).join('\n')
    compiled = compiled.slice(0, node.start) + declarations + compiled.slice(node.end)
  } else if (node.type === 'ExportDefaultDeclaration') {
    compiled = compiled.slice(0, node.start) + 'return ' + compiled.slice(node.declaration.start)
  }
}
const createComponent = new Function('imports', compiled)

async function dashboard(t) {
  const scope = vue.effectScope()
  t.after(() => scope.stop())
  const noop = () => {}
  const messages = [], requests = []
  let failure = '', stored = { brand_name: 'Test business', site_pages: {
    home: { hero_image: 'hero.jpg', custom_home_field: 'keep' },
    about: { text: 'Business story' }, contact: { phone: '123', address: 'Saved address' },
  } }
  const imports = new Proxy({
    vue: { ...vue, onMounted: noop },
    'vue-router': { useRoute: () => vue.reactive({ query: { tab: 'sections' } }) },
    '../lib/websiteSections.js': sectionSettings,
    '../lib/siteSettings.js': {
      loadSiteSettings: async () => ({ success: true, settings: structuredClone(stored), username: 'test', counts: { venues: 1 } }),
      loadPublicSitePages: async () => ({
        success: failure !== 'public-network',
        pages: failure === 'public-stripped' ? { home: { hero_image: 'hero.jpg' } } : structuredClone(stored.site_pages),
      }),
      saveSiteSettings: async patch => {
        requests.push(JSON.parse(JSON.stringify(patch)))
        if (failure === 'network') return { success: false, message: 'Test network error' }
        if (failure !== 'stripped') stored = JSON.parse(JSON.stringify({ ...stored, ...patch }))
        return { success: true }
      },
    },
    '../lib/currentUser.js': { useCurrentUser: () => ({ currentOwnerUserId: vue.ref(1), isSuperAdmin: vue.ref(false), role: vue.ref('provider'), allUsers: vue.ref([]) }) },
    '../lib/premium.js': { usePremium: () => ({ isPremium: vue.ref(false), requirePremium: noop }) },
    '../lib/toast.js': { useToast: () => ({ success: message => messages.push(['success', message]), error: message => messages.push(['error', message]) }) },
    '../lib/i18n.js': { SUPPORTED_LOCALES: [] },
    '../lib/brand.js': { DEFAULT_PRIMARY: '#0f766e', DEFAULT_ACCENT: '#14b8a6' },
  }, { get: (target, key) => target[key] || new Proxy({}, { get: () => noop }) })
  const state = scope.run(() => createComponent(imports).setup({}, { expose: noop }))
  await state.load()
  return { state, messages, requests, fail: reason => { failure = reason } }
}

test('section save persists only page settings, retains content, refreshes preview and survives reload', async t => {
  const { state, requests, messages } = await dashboard(t)
  assert.equal(state.websiteTab.value, 'sections')
  state.sections.value.locations = false
  await state.saveSections()
  assert.deepEqual(Object.keys(requests[0]), ['site_pages'])
  assert.equal(requests[0].site_pages.contact.address, 'Saved address')
  assert.equal(requests[0].site_pages.home.hero_image, 'hero.jpg')
  assert.equal(requests[0].site_pages.home.custom_home_field, 'keep')
  assert.equal(state.sectionsDirty.value, false)
  assert.equal(state.iframeKey.value, 1)
  assert.equal(messages.at(-1)[0], 'success')
  await state.load()
  assert.equal(state.sections.value.locations, false)
  // Editing another page must not turn a hidden section back on.
  state.pages.value.about.text = 'Updated story'
  await state.savePages()
  assert.equal(requests.at(-1).site_pages.home.sections.locations, false)
})

test('failed or silently stripped section saves retain the draft and never claim success', async t => {
  for (const reason of ['network', 'stripped']) {
    const { state, messages, fail } = await dashboard(t)
    state.sections.value.locations = false
    fail(reason)
    await state.saveSections()
    assert.equal(state.sectionsDirty.value, true)
    assert.equal(state.sectionsSaving.value, false)
    assert.equal(state.sections.value.locations, false)
    assert.equal(state.iframeKey.value, 0)
    assert.equal(messages.at(-1)[0], 'error')
    fail('')
    await state.saveSections()
    assert.equal(state.sectionsDirty.value, false)
    assert.equal(messages.at(-1)[0], 'success')
  }
})

test('the main Save changes action includes dirty visibility settings', async t => {
  const { state, requests } = await dashboard(t)
  state.sections.value.blog = false
  await state.save()
  assert.equal(requests[0].site_pages.home.sections.blog, false)
  assert.equal(state.sectionsDirty.value, false)
  assert.equal(state.saving.value, false)
})

test('section saves wait for page or hero saves to prevent overwriting visibility', async t => {
  const { state, requests } = await dashboard(t)
  state.sections.value.locations = false
  state.pagesSaving.value = true
  await state.saveSections()
  await state.save()
  assert.equal(requests.length, 0)
  state.pagesSaving.value = false
  state.sectionsSaving.value = true
  await state.savePages()
  assert.equal(requests.length, 0)
})

test('an admin save is not reported applied when the public website omits its hidden settings', async t => {
  const { state, messages, requests, fail } = await dashboard(t)
  state.sections.value.locations = false
  fail('public-stripped')
  await state.saveSections()
  assert.equal(state.sectionsDirty.value, false, 'the admin setting was saved')
  assert.equal(state.sectionsVerification.value, 'mismatch')
  assert.deepEqual(state.sectionsMismatched.value.map(s => s.key), ['locations'])
  assert.equal(state.sectionsStatus.value, 'Saved · not applied to website')
  assert.equal(messages.at(-1)[0], 'error')
  await state.load()
  assert.equal(state.sections.value.locations, false)
  assert.equal(state.sectionsVerification.value, 'mismatch', 'the mismatch remains visible after reopening settings')
  fail('')
  await state.checkWebsiteSections()
  assert.equal(state.sectionsVerification.value, 'applied')
  assert.equal(requests.length, 1, 'checking the website never changes saved settings')
})

test('public API failures leave saved choices intact and offer a read-only retry', async t => {
  const { state, messages, fail } = await dashboard(t)
  state.sections.value.locations = false
  fail('public-network')
  await state.saveSections()
  assert.equal(state.sectionsVerification.value, 'unavailable')
  assert.equal(state.sections.value.locations, false)
  assert.equal(messages.at(-1)[0], 'error')
})
