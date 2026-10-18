import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { parse, compileScript } from '@vue/compiler-sfc'
import { parse as parseJs } from '@babel/parser'
import * as vue from 'vue'
import { renderToString } from '@vue/server-renderer'

const source = readFileSync(new URL('../src/customer/components/EventFeeSummary.vue', import.meta.url), 'utf8')
let code = compileScript(parse(source).descriptor, { id: 'event-fee-test', inlineTemplate: true }).content
for (const node of parseJs(code, { sourceType: 'module' }).program.body.slice().reverse()) {
  if (node.type === 'ImportDeclaration') {
    const declarations = node.specifiers.map(s => `const ${s.local.name} = imports[${JSON.stringify(node.source.value)}][${JSON.stringify(s.imported.name)}];`).join('\n')
    code = code.slice(0, node.start) + declarations + code.slice(node.end)
  } else if (node.type === 'ExportDefaultDeclaration') code = code.slice(0, node.start) + 'return ' + code.slice(node.declaration.start)
}
const icon = { render: () => vue.h('svg') }
const component = new Function('imports', code)({ vue, 'lucide-vue-next': {CreditCard:icon, Info:icon} })
const render = props => renderToString(vue.createSSRApp(component, {currency:'QAR', rate:10, price:100, perHead:10, total:100, net:90, count:10, unit:'player', units:'players', ...props}))

test('arrival fee summary clearly separates guest price, per-player fee and upfront roster fee', async () => {
  const html = await render({online:false})
  assert.match(html, /Guest pays per player/)
  assert.match(html, /Bookboll fee per player/)
  assert.match(html, /Hosting fee due at publish<\/dt><dd>QAR 100/)
  assert.match(html, /Based on 10 players/)
  assert.match(html, /non-refundable/)
  assert.doesNotMatch(html, /QAR 90/)
})

test('online tickets show per-ticket net and no upfront hosting fee', async () => {
  const html = await render({online:true,ownSpot:true})
  assert.match(html, /You receive per player<\/dt><dd>QAR 90/)
  assert.match(html, /No hosting fee upfront/)
  assert.match(html, /no venue charge for your own spot/)
  assert.doesNotMatch(html, /Hosting fee due at publish|non-refundable/)
})

test('unknown tournament size never presents zero as the final hosting fee', async () => {
  const html = await render({count:0,unit:'team',units:'teams',total:0})
  assert.match(html, /Hosting fee due at publish<\/dt><dd>To be calculated/)
  assert.match(html, /Choose the number of teams next/)
})
