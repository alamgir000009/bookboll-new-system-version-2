import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { parse, compileScript } from '@vue/compiler-sfc'
import { parse as parseJs } from '@babel/parser'
import * as vue from 'vue'
import * as storefrontSort from '../src/lib/storefrontSort.js'
import * as photoOrder from '../src/lib/photoOrder.js'

function setup(t, file, props, extraImports = {}) {
  const source = readFileSync(new URL(`../src/components/${file}.vue`, import.meta.url), 'utf8')
  let code = compileScript(parse(source).descriptor, { id: 'creation-controls' }).content
  for (const node of parseJs(code, { sourceType: 'module' }).program.body.slice().reverse()) {
    if (node.type === 'ImportDeclaration') {
      const declarations = node.specifiers.map(s => `const ${s.local.name} = imports[${JSON.stringify(node.source.value)}][${JSON.stringify(s.type === 'ImportDefaultSpecifier' ? 'default' : s.imported.name)}];`).join('\n')
      code = code.slice(0, node.start) + declarations + code.slice(node.end)
    } else if (node.type === 'ExportDefaultDeclaration') code = code.slice(0, node.start) + 'return ' + code.slice(node.declaration.start)
  }
  const noop = () => {}
  const imports = new Proxy({ vue, ...extraImports }, { get: (target, key) => target[key] || new Proxy({}, { get: () => noop }) })
  const scope = vue.effectScope()
  t.after(() => scope.stop())
  const events = []
  const reactiveProps = vue.reactive(props)
  const state = scope.run(() => new Function('imports', code)(imports).setup(reactiveProps, {
    expose: noop,
    emit: (event, value) => { events.push([event, value]); if (event.startsWith('update:')) reactiveProps[event.slice(7)] = value },
  }))
  return { state, events, props: reactiveProps }
}
function pricing(t) {
  const result = setup(t, 'PricingGrid', { groups: [], cells: [], basePrice: 100, slotDuration: 60, pattern: 'B', currency: 'QAR', hours: [
    { day_of_week: 1, is_open: true, periods: [{ open_time: '09:00', close_time: '18:00' }] },
    { day_of_week: 2, is_open: false, periods: [] },
  ] })
  result.state.directPriceInput.value = 150
  return result
}

test('searching filter options preserves independent multiple selections and clears the entire filter', t => {
  const { state, props } = setup(t, 'MultiSelect', { modelValue: [], options: [{value:1,label:'Qatar'}, {value:2,label:'Pakistan'}, {value:3,label:'United Arab Emirates'}], placeholder:'All countries', maxDisplay:2 })
  state.query.value = 'qa'
  assert.deepEqual(state.matches.value.map(o => o.value), [1])
  state.toggle(1); state.query.value = 'PAK'; state.toggle(2)
  assert.deepEqual(props.modelValue,[1,2])
  assert.deepEqual(state.matches.value.map(o => o.value),[2])
  state.query.value = 'no results'
  assert.equal(state.matches.value.length,0)
  assert.equal(state.selected.value.length,2)
  state.toggle(1)
  assert.deepEqual(props.modelValue,[2])
})

test('photo control emits the complete reordered gallery and announces its cover', t => {
  const { state, props } = setup(t, 'PhotoOrderEditor', { modelValue:['a','b','c'] }, {'../lib/photoOrder.js':photoOrder})
  state.move(2,0)
  assert.deepEqual(props.modelValue,['c','a','b'])
  assert.match(state.announcement.value,/set as the cover/)
  state.remove(1)
  assert.deepEqual(props.modelValue,['c','b'])
})

test('photo dragging stages an order and only commits on drop; cancellation preserves the gallery', t => {
  const { state, props, events } = setup(t, 'PhotoOrderEditor', {modelValue:['a','b','c']}, {'../lib/photoOrder.js':photoOrder})
  const key = key => ({key,preventDefault(){},stopPropagation(){}})
  state.keyDrag(key(' '),2)
  state.keyDrag(key('Home'),2)
  assert.equal(state.drag.value.to,0)
  assert.deepEqual(props.modelValue,['a','b','c'])
  state.keyDrag(key('Escape'),2)
  assert.equal(events.length,0)
  assert.equal(state.drag.value,null)
  state.keyDrag(key('Enter'),2)
  state.keyDrag(key('ArrowLeft'),2)
  state.keyDrag(key('Enter'),2)
  assert.deepEqual(props.modelValue,['a','c','b'])
  assert.equal(events.length,1)
})

test('pointer drop outside the gallery cancels, and a touch drop commits the indicated order', t => {
  const { state, props } = setup(t, 'PhotoOrderEditor', {modelValue:['a','b','c']}, {'../lib/photoOrder.js':photoOrder})
  state.drag.value={from:0,to:2,keyboard:false,overGallery:false}
  state.stopDrag()
  assert.deepEqual(props.modelValue,['a','b','c'])
  state.drag.value={from:2,to:0,keyboard:false,overGallery:true}
  state.stopDrag()
  assert.deepEqual(props.modelValue,['c','a','b'])
  assert.match(state.announcement.value,/cover/)
})

test('select all covers matching options and preserves selections outside the search', t => {
  const { state, props } = setup(t, 'MultiSelect', {modelValue:['outside'],options:[{value:'outside',label:'Qatar'},{value:'a',label:'North club'},{value:'b',label:'North court'}],placeholder:'All venues',maxDisplay:2})
  state.query.value='North'
  assert.equal(state.allMatchesSelected.value,false)
  state.toggle('a')
  assert.equal(state.someMatchesSelected.value,true)
  assert.equal(state.allMatchesSelected.value,false)
  state.toggleAllMatches(true)
  assert.deepEqual(props.modelValue,['outside','a','b'])
  assert.equal(state.allMatchesSelected.value,true)
  state.toggleAllMatches(false)
  assert.deepEqual(props.modelValue,['outside'])
  state.query.value=''
  state.toggleAllMatches(true)
  assert.deepEqual(props.modelValue,['outside','a','b'])
  state.toggleAllMatches(true)
  assert.equal(props.modelValue.length,3)
  state.toggleAllMatches(false)
  assert.deepEqual(props.modelValue,[])
  state.query.value='no results'
  assert.equal(state.allMatchesSelected.value,false)
})

test('separate admin sort choices persist and must be read back before reporting success', async t => {
  let server={space:'recommended',venue:'name'}, writes=[]
  const api={fetchStorefrontSort:async()=>({...server}),saveStorefrontSortValue:async(view,value)=>{writes.push([view,value]);server[view]=value;return {success:true}}}
  const {state}=setup(t,'StorefrontOrderSettings',{marketplace:true},{'../lib/paymentAccounts.js':api,'../lib/storefrontSort.js':storefrontSort})
  await state.load()
  state.current.value.space='price_desc';state.current.value.venue='newest'
  await state.save()
  assert.deepEqual(writes,[['space','price_desc'],['venue','newest']])
  assert.equal(state.changed.value,false)
  assert.equal(state.message.value,'Default orders saved.')
})

test('an older server that ignores a sort change cannot produce a success message', async t => {
  const api={fetchStorefrontSort:async()=>({space:'recommended',venue:'recommended'}),saveStorefrontSortValue:async()=>({success:true})}
  const {state}=setup(t,'StorefrontOrderSettings',{marketplace:false},{'../lib/paymentAccounts.js':api,'../lib/storefrontSort.js':storefrontSort})
  await state.load();state.current.value.venue='price_asc';await state.save()
  assert.equal(state.message.value,'')
  assert.match(state.error.value,/could not be confirmed/)
  assert.equal(state.changed.value,true)
})

/* Painted squares live in pricing GROUPS here, not in a separate `cells` array:
 * the grid pools each price's squares and cuts them back into hour-range
 * rectangles, so a contiguous drag is one group rather than one row per hour. */
const painted = (groups) => groups.map(g => [g.price, g.start_time, g.end_time, String(g.days)])

test('touch scroll does not paint prices; a completed tap paints once', t => {
  const { state, props } = pricing(t)
  state.startPointerPaint({ pointerType: 'touch' }, 1, '09:00')
  state.continuePointerPaint({ pointerType: 'touch' }, 1, '10:00')
  assert.equal(props.groups.length, 0)
  state.activateCell({ pointerType: 'touch', detail: 1 }, 1, '09:00')
  assert.deepEqual(painted(props.groups), [[150, '09:00', '10:00', '1']])
  assert.equal(state.painting.value, false)
})

test('mouse click and drag paint once without the click erasing the price', t => {
  const { state, props } = pricing(t)
  state.startPointerPaint({ pointerType: 'mouse', preventDefault() {} }, 1, '09:00')
  state.continuePointerPaint({ pointerType: 'mouse' }, 1, '10:00')
  state.onMouseUp()
  // Both hours merge into one rectangle, and the click that ends the drag must
  // not be read as a second press — that would erase what was just painted.
  assert.deepEqual(painted(props.groups), [[150, '09:00', '11:00', '1']])
  state.activateCell({ pointerType: 'mouse', detail: 1 }, 1, '09:00')
  assert.deepEqual(painted(props.groups), [[150, '09:00', '11:00', '1']])
})

test('keyboard activation respects closed times and minimum price', t => {
  const { state, props } = pricing(t)
  state.activateCell({ detail: 0 }, 2, '09:00')
  assert.equal(props.groups.length, 0)
  state.directPriceInput.value = 50
  state.activateCell({ detail: 0 }, 1, '09:00')
  assert.equal(props.groups.length, 0)
  assert.equal(state.priceInputError.value, true)
  state.directPriceInput.value = 150
  state.activateCell({ detail: 0 }, 1, '09:00')
  assert.deepEqual(painted(props.groups), [[150, '09:00', '10:00', '1']])
})

test('copying hours requires confirmation and creates independent day periods', async t => {
  let answer = false
  const { state, props, events } = setup(t, 'WeeklyHours', {
    modelValue: [{ day_of_week: 1, is_open: true, periods: [{ open_time: '10:00', close_time: '17:00' }] }],
    parentHours: [], showInherit: false, slotDuration: 60,
  }, { '../lib/confirm.js': { askConfirm: async () => answer } })
  await state.clone(1)
  assert.equal(events.length, 0)
  answer = true
  await state.clone(1)
  assert.equal(props.modelValue.length, 7)
  assert.ok(props.modelValue.every(day => day.periods[0].open_time === '10:00'))
  props.modelValue[0].periods[0].open_time = '11:00'
  assert.equal(props.modelValue[1].periods[0].open_time, '10:00')
})
