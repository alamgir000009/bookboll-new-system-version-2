import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { parse, compileScript } from '@vue/compiler-sfc'
import { parse as parseJs } from '@babel/parser'
import * as vue from 'vue'
import * as countries from '../src/lib/countryRewards.js'
import * as rewards from '../src/lib/checkoutRewards.js'

// Actual component setup, with I/O mocked: no account or payment writes.
let code=compileScript(parse(readFileSync(new URL('../src/customer/components/CheckoutRewards.vue',import.meta.url),'utf8')).descriptor,{id:'checkout-rewards-test'}).content
for(const node of parseJs(code,{sourceType:'module'}).program.body.slice().reverse()) {
  if(node.type==='ImportDeclaration') code=code.slice(0,node.start)+node.specifiers.map(s=>`const ${s.local.name}=imports[${JSON.stringify(node.source.value)}][${JSON.stringify(s.type==='ImportDefaultSpecifier'?'default':s.imported.name)}];`).join('\n')+code.slice(node.end)
  else if(node.type==='ExportDefaultDeclaration') code=code.slice(0,node.start)+'return '+code.slice(node.declaration.start)
}
const factory=new Function('imports','setTimeout','clearTimeout',code)
const tick=()=>new Promise(resolve=>setImmediate(resolve))
function setup(t,{account={country_id:179,balance:5000,redemption_checkout_v1:true},quoteHandler}={}) {
  const calls=[],cleanups=[],timers=new Map(),scope=vue.effectScope()
  const props=vue.reactive({kind:'listing',countryId:179,currency:'QAR',checkout:{listing_id:2},total:100,dueNow:20,active:true,modelValue:null})
  const session={customer:vue.ref({id:'customer-a'}),isCustomer:vue.ref(true)}
  const quote={quote_id:'confirmed',country_id:179,currency:'QAR',points:2000,discount:20,bookboll_share:{commission:18,event_fee:0,booking_fee:2},order_total_before:100,due_now_before:20,expires_at:new Date(Date.now()+60000).toISOString()}
  const apiFetch=async(path,options)=>{
    calls.push({path,options})
    if(path==='/customer/loyalty') return {success:true,data:account}
    if(path==='/marketplace/loyalty-config') return {success:true,data:{loyalty:{country_id:179,is_active:true,earn_rate:1,redeem_rate:100,min_redeem:100,max_redeem_pct:100}}}
    return quoteHandler ? quoteHandler(path,options) : {success:true,data:quote}
  }
  const modules={vue:{...vue,onBeforeUnmount:fn=>cleanups.push(fn)},'../../lib/api.js':{apiFetch,getCustomerToken:()=> 'test-token'},'../../lib/customerSession.js':session,'../../lib/countryRewards.js':countries,'../../lib/checkoutRewards.js':rewards,'lucide-vue-next':{}}
  let timerId=0
  const state=scope.run(()=>factory(modules,fn=>{timers.set(++timerId,fn);return timerId},id=>timers.delete(id)).setup(props,{expose:()=>{},emit:(_,value)=>props.modelValue=value}))
  t.after(()=>{cleanups.forEach(fn=>fn());scope.stop()})
  return {state,props,calls,timers,quote,session}
}

test('an older unscoped or unsupported API cannot display or apply a checkout discount',async t=>{
  for(const account of [{balance:5000},{country_id:179,balance:5000}]) {
    const {state:s,props,calls}=setup(t,{account})
    await tick()
    assert.equal(s.quote.value,null)
    assert.equal(props.modelValue,null)
    assert.equal(calls.some(c=>c.path.endsWith('checkout-quote')),false)
    assert.match(s.message.value,/not available|cannot be applied/)
  }
})
test('a confirmed country quote can be applied and removed; expiry removes an applied quote',async t=>{
  const {state:s,props,timers}=setup(t)
  await tick()
  s.toggle();assert.equal(props.modelValue.amount,20);assert.equal(s.validate(),true)
  s.toggle();assert.equal(props.modelValue,null)
  s.toggle();const expire=[...timers.values()][0];expire()
  assert.equal(props.modelValue,null);assert.equal(s.quote.value,null)
  assert.match(s.message.value,/expired/)
})
test('changing the checkout clears applied points synchronously and rejects an older response',async t=>{
  const pending=[]
  const {state:s,props,quote}=setup(t,{quoteHandler:()=>new Promise(resolve=>pending.push(resolve))})
  await tick();assert.equal(pending.length,1)
  props.checkout={listing_id:3};await tick();assert.equal(pending.length,2)
  pending[0]({success:true,data:quote});await tick();assert.equal(s.quote.value,null)
  pending[1]({success:true,data:{...quote,quote_id:'new'}});await tick()
  s.toggle();assert.equal(props.modelValue.quoteId,'new')
  props.checkout={listing_id:3,promocode:'CHANGED'}
  assert.equal(props.modelValue,null);assert.equal(s.quote.value,null)
})
test('switching country or signing out removes points and never reuses the other balance',async t=>{
  const {state:s,props,session}=setup(t)
  await tick();s.toggle();assert.equal(props.modelValue.points,2000)
  props.countryId=167;assert.equal(props.modelValue,null)
  await tick();assert.equal(s.balance.value,null);assert.equal(s.quote.value,null)
  props.countryId=179;await tick();s.toggle();assert.ok(props.modelValue)
  session.isCustomer.value=false
  assert.equal(props.modelValue,null);assert.equal(s.quote.value,null)
})
