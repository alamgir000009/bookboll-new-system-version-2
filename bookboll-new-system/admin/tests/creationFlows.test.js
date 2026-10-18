import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { parse, compileScript } from '@vue/compiler-sfc'
import { parse as parseJs } from '@babel/parser'
import * as vue from 'vue'
import * as flow from '../src/lib/creationFlow.js'
import * as finance from '../src/lib/finance.js'
import { movePhoto } from '../src/lib/photoOrder.js'
import { contentChanged } from '../src/lib/approvalState.js'
import { markDirty, formDirty, clearDirty, registerDraftSaver, confirmDiscardIfDirty } from '../src/lib/unsavedGuard.js'
import { resolveConfirm } from '../src/lib/confirm.js'

// Run the actual venue component with an in-memory API; never create real venues.
const venueSource = readFileSync(new URL('../src/components/LocationModal.vue', import.meta.url), 'utf8')
let compiled = compileScript(parse(venueSource).descriptor, { id: 'venue-flow-test' }).content
for (const node of parseJs(compiled, { sourceType: 'module' }).program.body.slice().reverse()) {
  if (node.type === 'ImportDeclaration') {
    const declarations = node.specifiers.map(s => `const ${s.local.name} = imports[${JSON.stringify(node.source.value)}][${JSON.stringify(s.type === 'ImportDefaultSpecifier' ? 'default' : s.imported.name)}];`).join('\n')
    compiled = compiled.slice(0,node.start) + declarations + compiled.slice(node.end)
  } else if (node.type === 'ExportDefaultDeclaration') {
    compiled = compiled.slice(0,node.start) + 'return ' + compiled.slice(node.declaration.start)
  }
}
const component = new Function('imports', compiled)
function venue(t) {
  const noop = () => {}
  const scope = vue.effectScope()
  t.after(() => scope.stop())
  const writes = [], events = [], drafts = []
  let write = async payload => ({ success: true, data: { location: { ...payload, id: 999 } } })
  let draftWorks = true
  const imports = new Proxy({
    vue: { ...vue, onBeforeUnmount: noop },
    '../lib/creationFlow.js': flow,
    '../lib/finance.js': finance,
    '../lib/venues.js': {
      createVenue: async payload => { writes.push(JSON.parse(JSON.stringify(payload))); return write(payload) },
      updateVenue: async (id,payload) => { writes.push({ id, ...JSON.parse(JSON.stringify(payload)) }); return write(payload) },
      firstError: res => res.message,
    },
    '../lib/currentUser.js': { useCurrentUser: () => ({ isSuperAdmin: vue.ref(false), isCountryAdmin: vue.ref(false), isProviderScoped: vue.ref(true), currentOwnerUserId: vue.ref(10), currentCountryId: vue.ref(1), allBusinessOwners: vue.ref([]) }) },
    '../lib/toast.js': { useToast: () => ({ success: noop, error: noop }) },
    '../lib/editorDialog.js': { useEditorDialog: () => noop },
    '../lib/formDrafts.js': { saveFormDraft: (key,data) => { if (!draftWorks) return false; drafts.push(JSON.parse(JSON.stringify(data))); return true }, loadFormDraft: () => null, clearFormDraft: noop },
  }, { get: (target,key) => target[key] || new Proxy({}, { get: () => noop }) })
  const state = scope.run(() => component(imports).setup({open:false, editing:null}, { expose: noop, emit: (...args) => events.push(args) }))
  return { state, writes, events, drafts, setWriter: fn => write = fn, failDraft: () => draftWorks = false }
}

test('venue Continue moves through all steps without creating a record', async t => {
  const { state, writes, events } = venue(t)
  state.form.value.name = 'Test venue'
  state.form.value.city_id = '1'
  state.form.value.latitude = 0
  state.form.value.longitude = 0
  state.form.value.photos = ['cover.jpg']
  for (const step of ['map','schedule','review']) {
    await state.saveAndContinue()
    assert.equal(state.tab.value, step)
    assert.equal(writes.length, 0)
    assert.equal(events.length, 0)
  }
  await state.save()
  assert.equal(writes.length, 1)
  assert.equal(writes[0].latitude, 0)
  assert.equal(writes[0].longitude, 0)
  assert.deepEqual(writes[0].photos, ['cover.jpg'])
  assert.equal(writes[0].hours.length, 7)
  assert.equal(events[0][0], 'saved')
})

test('venue saves preserve the chosen gallery order and mirror the first image as cover', async t => {
  const { state, writes } = venue(t)
  state.form.value.name = 'Gallery venue'; state.form.value.city_id = 1
  state.form.value.photos = movePhoto(['original','middle','last'],2,0)
  await state.save()
  assert.deepEqual(writes[0].photos,['last','original','middle'])
  assert.equal(writes[0].photo_url,'last')
})

test('reordering non-cover listing photos remains a content change for approval', () => {
  const live = {name:'Court',photo_url:'cover',photos:['cover','second','third']}
  const next = {...live,photos:movePhoto(live.photos,2,1)}
  assert.equal(contentChanged(next,live),true)
  assert.deepEqual(live.photos,['cover','second','third'])
})

test('venue validation sends users to the step needing attention', async t => {
  const { state, writes } = venue(t)
  await state.goVenueStep('review')
  assert.equal(state.tab.value, 'details')
  assert.match(state.errorMsg.value, /venue name/)
  state.form.value.name = 'Venue'
  state.form.value.city_id = 1
  state.form.value.latitude = 95
  state.form.value.longitude = 12
  await state.save()
  assert.equal(state.tab.value, 'map')
  assert.match(state.errorMsg.value, /latitude/)
  assert.equal(writes.length, 0)
})

test('venue cannot save after its settings fail to load', async t => {
  const { state, writes } = venue(t)
  state.form.value.name = 'Existing venue'
  state.form.value.city_id = 1
  state.loadFailed.value = true
  await state.save()
  assert.equal(writes.length, 0)
})

test('generic venue save cannot bypass the reviewed business transfer',async t=>{
  const {state:s,writes}=venue(t)
  s.form.value.name='Existing venue';s.form.value.city_id=1;s.editingExisting.value=true;s.editingId.value=1
  s.form.value.owner_user_id=99
  await s.save();assert.equal(writes.length,0);assert.match(s.errorMsg.value,/reviewed venue transfer/)
  s.form.value.owner_user_id='';s.form.value.business_id=20
  await s.save();assert.equal(writes.length,0)
})
test('existing venue detail updates omit ownership and unsaved agreement changes block save',async t=>{
  const {state:s,writes,drafts}=venue(t)
  s.form.value.name='Existing venue';s.form.value.city_id=1;s.editingExisting.value=true;s.editingId.value=1
  s.commercialDirty.value=true;await s.save();s.saveLocationDraftNow()
  assert.equal(writes.length,0);assert.equal(drafts.length,0)
  s.commercialDirty.value=false;await s.save()
  assert.equal(writes.length,1);assert.equal('owner_user_id' in writes[0],false)
})

test('venue clears a corrected map error when continuing to opening hours', async t => {
  const { state, writes } = venue(t)
  state.form.value.name = 'Venue'
  state.form.value.city_id = 1
  state.tab.value = 'map'
  state.form.value.latitude = 95
  state.form.value.longitude = 50
  await state.saveAndContinue()
  assert.equal(state.tab.value, 'map')
  assert.ok(state.errorMsg.value)
  state.form.value.latitude = ''
  state.form.value.longitude = ''
  await state.saveAndContinue()
  assert.equal(state.tab.value, 'schedule')
  assert.equal(state.errorMsg.value, '')
  assert.equal(writes.length, 0)
})

test('clearing optional map coordinates does not replace them with zero', t => {
  const { state } = venue(t)
  state.form.value.latitude = ''
  state.form.value.longitude = ''
  state.applyTypedCoords()
  assert.equal(state.form.value.latitude, '')
  assert.equal(state.form.value.longitude, '')
  assert.equal(state.hasMapPin.value, false)
  state.form.value.latitude = 0
  state.form.value.longitude = 0
  assert.equal(state.hasMapPin.value, true)
})

test('close guard preserves unsaved changes when draft storage fails', async t => {
  t.after(() => { clearDirty(); registerDraftSaver(null) })
  markDirty()
  registerDraftSaver(() => false)
  const failed = confirmDiscardIfDirty()
  resolveConfirm('extra')
  assert.equal(await failed, false)
  assert.equal(formDirty.value, true)
  registerDraftSaver(() => true)
  const saved = confirmDiscardIfDirty()
  resolveConfirm('extra')
  assert.equal(await saved, 'draft')
  assert.equal(formDirty.value, false)
})

test('venue final save blocks duplicate clicks and preserves state on API failure', async t => {
  const { state, writes, events, setWriter } = venue(t)
  state.form.value.name = 'Venue'
  state.form.value.city_id = 1
  let finish
  setWriter(() => new Promise(resolve => { finish = resolve }))
  const first = state.save()
  await state.save()
  assert.equal(writes.length, 1)
  finish({success:false, message:'Test save failure'})
  await first
  assert.equal(state.saving.value, false)
  assert.equal(state.form.value.name, 'Venue')
  assert.equal(events.length, 0)
  assert.match(state.errorMsg.value, /Test save failure/)
})

test('failed local draft save keeps the venue form open', t => {
  const { state, events, failDraft } = venue(t)
  failDraft()
  state.form.value.name = 'Unfinished venue'
  state.saveLocationDraftNow()
  assert.equal(events.length, 0)
  assert.match(state.errorMsg.value, /could not be saved/)
  assert.equal(state.form.value.name, 'Unfinished venue')
})

const validListing = () => ({ form: { name:'Tour', location_id:1, category:'hangout', sport_category:'Tour', description:'Guided tour.', base_price:200, slot_duration:60 }, hours:[{is_open:true, periods:[{open_time:'09:00', close_time:'18:00'}]}] })
test('listing review validates every required group and keeps optional extras optional', () => {
  const valid = validListing()
  for (const step of flow.LISTING_STEPS) assert.equal(flow.listingStepIssue(step.id, valid), null)
  assert.equal(flow.listingStepIssue('details', {...valid, form:{...valid.form, name:'  '}}).section, 'basic')
  assert.equal(flow.listingStepIssue('availability', {...valid, hours:[]}).section, 'schedule')
  assert.equal(flow.listingStepIssue('availability', {...valid, form:{...valid.form, slot_duration:0}}).section, 'timeslot')
  assert.equal(flow.listingStepIssue('availability', {...valid, hoursOutOfBounds:['Monday']}).section, 'schedule')
  assert.equal(flow.listingStepIssue('pricing', {...valid, form:{...valid.form, base_price:'Infinity'}}).section, 'pricing')
  assert.equal(flow.listingStepIssue('payments', {...valid, paymentError:'Choose a payment method'}).section, 'placement')
  for (const section of ['basic','images','schedule','timeslot','pricing','addons','resources','placement']) assert.ok(flow.listingStepFor(section))
})

// Exercise the component's real final-action handler with a delayed save.
const listingScript = parse(readFileSync(new URL('../src/pages/Listings.vue',import.meta.url),'utf8')).descriptor.scriptSetup.content
const handler = parseJs(listingScript, {sourceType:'module'}).program.body.find(node => node.type === 'FunctionDeclaration' && node.id.name === 'saveAndClose')
test('listing final action prevents duplicate saves and only closes after success', async () => {
  const listingSaving = vue.ref(false), savedFlag = vue.ref(false), view = vue.ref('editor'), listingSaveError = vue.ref('')
  let writes = 0, finish
  const save = () => { writes++; return new Promise(resolve => { finish = () => { savedFlag.value = true; resolve() } }) }
  const submit = new Function('listingSaving','imageUploading','savedFlag','view','listingSaveError','save','toast', listingScript.slice(handler.start,handler.end)+'; return saveAndClose')(listingSaving,vue.ref(false),savedFlag,view,listingSaveError,save,{error:()=>{}})
  const pending = submit()
  await submit()
  assert.equal(writes,1)
  assert.equal(view.value,'editor')
  finish()
  await pending
  assert.equal(view.value,'list')
  assert.equal(listingSaving.value,false)
})
