/**
 * FilterBar — the filter panel is part of the page, not an overlay.
 *
 * It used to be absolutely positioned and closed on any outside click, so
 * reading a filter and then reaching for the table made it vanish and the rows
 * jump. These tests pin the two things that caused that, because both are easy
 * to reintroduce from a stylesheet that still thinks it is styling a popup.
 */

import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { parse, compileScript, compileTemplate } from '@vue/compiler-sfc'

const url = p => new URL(p, import.meta.url)
const SOURCE = readFileSync(url('../src/components/FilterBar.vue'), 'utf8')
const descriptor = parse(SOURCE).descriptor
const TEMPLATE = descriptor.template.content
const STYLE = SOURCE.slice(SOURCE.indexOf('<style'))

const css = ['../src/style.css', '../src/workspaceReview.css', '../src/adminWorkspace.css']
  .map(p => readFileSync(url(p), 'utf8')).join('\n')

/** Every declaration block whose selector mentions `.lf-panel` itself. */
function panelRules(source) {
  return [...source.matchAll(/([^{}]+)\{([^}]*)\}/g)]
    .filter(([, sel]) => /\.lf-panel(?![\w-])/.test(sel))
    .map(([, sel, body]) => ({ selector: sel.trim(), body }))
}

test('it compiles', () => {
  compileScript(descriptor, { id: 'fb' })
  const result = compileTemplate({ source: TEMPLATE, id: 'fb', filename: 'FilterBar.vue' })
  assert.deepEqual(result.errors, [])
})

test('the panel is never positioned out of the page flow', () => {
  // `position: absolute` floats it over the rows; `fixed` turns it into a
  // bottom sheet. Either one brings back the layer the user has to dismiss.
  for (const source of [STYLE, css]) {
    for (const rule of panelRules(source)) {
      assert.doesNotMatch(rule.body, /position:\s*(absolute|fixed)/,
        `${rule.selector} puts the panel back on a layer of its own`)
    }
  }
})

test('the panel has no width or height of its own', () => {
  // A width makes it a card floating next to the search box rather than a
  // section of the page; a max-height makes it scroll inside itself while the
  // page beneath it does not move.
  for (const source of [STYLE, css]) {
    for (const rule of panelRules(source)) {
      assert.doesNotMatch(rule.body, /(^|;)\s*width:\s*min\(/, `${rule.selector} pins a popup width`)
      assert.doesNotMatch(rule.body, /max-height:/, `${rule.selector} makes the panel scroll inside itself`)
    }
  }
})

test('nothing closes the panel except the controls that say so', () => {
  // The outside-click listener is what made it disappear mid-use. Reaching for
  // the table is not a request to close the filters.
  assert.doesNotMatch(SOURCE, /addEventListener\(\s*['"]click['"]/,
    'a document click listener would close the panel while it is being read')
  assert.doesNotMatch(SOURCE, /onDocClick/)

  // The deliberate ones, all still present.
  assert.match(TEMPLATE, /aria-label="Close filters"/)
  assert.match(TEMPLATE, /Hide filters/)
  assert.match(TEMPLATE, /keydown\.esc\.stop="open = false"/)
})

test('the toggle says whether it is expanded, and what it expands', () => {
  assert.match(TEMPLATE, /:aria-expanded="open"/)
  assert.match(TEMPLATE, /:aria-controls="panelId"/)
  assert.match(TEMPLATE, /<section v-if="open" :id="panelId"/)
})

test('a closed FilterBar occupies exactly the space the old toolbar did', () => {
  // Pages were written against `.toolbar { margin-bottom: 18px }`. The new
  // wrapper takes that over; leaving it on the inner bar as well would add a
  // second gap on every page that uses this.
  assert.match(STYLE, /\.lf-shell\s*\{[^}]*margin-bottom:\s*18px/)
  assert.match(STYLE, /\.lf-shell\s*>\s*\.toolbar\.lf-bar\s*\{[^}]*margin-bottom:\s*0/)
})

test('the search box fills the row rather than sitting at a fixed width', () => {
  // style.css caps every .lf-search-wrap at 380px and lets .lf-spacer eat the
  // rest, which left a short input with dead space after it.
  assert.match(STYLE, /\.lf-shell \.lf-search-wrap\s*\{[^}]*flex:\s*1 1/)
  assert.match(STYLE, /\.lf-shell \.lf-spacer\s*\{[^}]*flex:\s*0 0 0/)
})

test('it styles itself from theme tokens, defined in both palettes', () => {
  const theme = readFileSync(url('../src/dashboardTheme.css'), 'utf8')
  const [light, dark] = theme.split('html.adm-dark')
  const tokens = [...new Set([...STYLE.matchAll(/var\((--[a-z0-9-]+)/g)].map(m => m[1]))]

  assert.ok(tokens.length > 3, 'the panel should read theme tokens, not literals')
  for (const token of tokens) {
    assert.ok(light.includes(`${token}:`), `${token} is used but never defined`)
    assert.ok(dark.includes(`${token}:`), `${token} has no dark value`)
  }
})

test('motion is optional', () => {
  assert.match(STYLE, /@media \(prefers-reduced-motion: reduce\)/)
})

// ── what is filtering the list, without opening anything ───────────────────

import * as vue from 'vue'
import { parse as parseJs } from '@babel/parser'

function setup(t, props = {}) {
  let code = compileScript(parse(SOURCE).descriptor, { id: 'fb-run' }).content
  for (const node of parseJs(code, { sourceType: 'module' }).program.body.slice().reverse()) {
    if (node.type === 'ImportDeclaration') {
      code = code.slice(0, node.start) + node.specifiers.map(s =>
        `const ${s.local.name}=imports[${JSON.stringify(node.source.value)}][${JSON.stringify(s.type === 'ImportDefaultSpecifier' ? 'default' : s.imported.name)}];`
      ).join('\n') + code.slice(node.end)
    } else if (node.type === 'ExportDefaultDeclaration') {
      code = code.slice(0, node.start) + 'return ' + code.slice(node.declaration.start)
    }
  }
  const noop = () => {}
  const imports = new Proxy({ vue }, { get: (o, k) => o[k] || new Proxy({}, { get: () => noop }) })
  const emits = []
  const scope = vue.effectScope()
  t.after(() => scope.stop())
  const state = scope.run(() => new Function('imports', code)(imports)
    // setup() is called directly, so Vue's prop defaults are not applied —
    // the harness supplies the ones the component would otherwise receive.
    .setup(vue.reactive({
      countryLabel: 'Country', cityLabel: 'City',
      countries: [{ id: 1, name: 'Qatar' }, { id: 2, name: 'Pakistan' }],
      cities: [{ id: 10, name: 'Doha', country_id: 1 }, { id: 11, name: 'Lahore', country_id: 2 }],
      ...props,
    }), { expose: noop, emit: (...a) => emits.push(a) }))

  return { state, emits }
}

test('active filters are named, not just counted', t => {
  const { state } = setup(t, { country: [1], city: [10], extraCount: 2 })
  const chips = state.chips.value

  assert.deepEqual(chips.map(c => c.label), ['Qatar', 'Doha'])
  assert.deepEqual(chips.map(c => c.group), ['Country', 'City'])
  assert.equal(state.activeCount.value, 4, 'country + city + the 2 the page owns')
  assert.equal(state.extra.value, 2, 'filters this component cannot name get their own chip')
})

test('an id with no matching name is counted but never shown as a chip', t => {
  // A chip reading "9999" is worse than no chip. The Filters badge still says 1.
  const { state } = setup(t, { country: [9999] })

  assert.deepEqual(state.chips.value, [])
  assert.equal(state.activeCount.value, 1)
  assert.equal(state.showSummary.value, false)
})

test('removing one chip keeps the rest', t => {
  const { state, emits } = setup(t, { country: [1, 2] })
  state.chips.value.find(c => c.label === 'Qatar').remove()

  assert.deepEqual(emits.find(([e]) => e === 'update:country'), ['update:country', [2]])
})

test('removing a country drops the cities that belonged to it', t => {
  const { state, emits } = setup(t, { country: [1, 2], city: [10, 11] })
  state.chips.value.find(c => c.label === 'Pakistan').remove()

  assert.deepEqual(emits.find(([e]) => e === 'update:country'), ['update:country', [1]])
  // Lahore is in Pakistan, which is no longer selected; Doha stays.
  assert.deepEqual(emits.find(([e]) => e === 'update:city'), ['update:city', [10]])
})

test('nothing is summarised when nothing is filtering', t => {
  const { state } = setup(t, {})
  assert.equal(state.showSummary.value, false)
  assert.equal(state.activeCount.value, 0)
})

// ── the dropdowns inside the panel ─────────────────────────────────────────

test('a filter dropdown never locks the page the way a modal does', () => {
  // v-dialog sets document.body.style.overflow = 'hidden', marks the element
  // aria-modal and traps Tab. On a dropdown that froze the whole page while
  // the menu was open, and releasing the lock jumped it back to the top.
  const ms = readFileSync(url('../src/components/MultiSelect.vue'), 'utf8')
  // Comments stripped: the one above the dropdown names what it must not use.
  const markup = ms.replace(/<!--[\s\S]*?-->/g, '')

  assert.doesNotMatch(markup, /v-dialog/, 'the modal directive is back on the dropdown')
  assert.doesNotMatch(markup, /aria-modal/, 'a dropdown is a disclosure, not a modal')

  // The behaviour it was standing in for, all still present.
  assert.match(ms, /@keydown\.esc\.stop\.prevent="close\(\)"/)
  assert.match(ms, /document\.addEventListener\('pointerdown', outside\)/)
  assert.match(ms, /function close\(focus = true\) \{[^}]*trigger\.value\?\.focus\(\)/)
})

test('the modal directive still owns the body scroll lock for real modals', () => {
  // Removing it from the dropdown must not have removed it from dialogs.
  const dialog = readFileSync(url('../src/lib/dialogFocus.js'), 'utf8')
  assert.match(dialog, /document\.body\.style\.overflow = 'hidden'/)
})
