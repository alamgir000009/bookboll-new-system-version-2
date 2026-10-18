import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { compile, createSSRApp } from 'vue'
import { renderToString } from '@vue/server-renderer'
import { Check, Sun } from 'lucide-vue-next'

// Render the real slot template with isolated availability; never place real holds for UI checks.
const source=readFileSync(new URL('../src/customer/pages/ListingDetail.vue',import.meta.url),'utf8')
const template=source.match(/<button v-for="s in visibleSlots"[\s\S]*?<\/button>/)[0]
const render=compile(template)
async function slot(overrides={},extra={}) {
  const s={start_time:'6:00 PM',end_time:'7:00 PM',available:true,price:100,spacesLeft:2,capacity:4,...overrides}
  return renderToString(createSSRApp({components:{Check},render,setup:()=>({visibleSlots:[s],selectedSet:new Set(),tierClass:()=> 'tier-mid',toggleSlot:()=>{},periodIcon:()=>Sun,dayPeriod:()=> 'night',fmtTime:t=>t,cur:'QAR',selectMode:false,isDisc:()=>false,priceFor:s=>s.price,...extra})}))
}
test('slots preserve both times, price, tier, day-period icon and remaining capacity', async()=>{
  const html=await slot()
  for(const text of ['6:00 PM','7:00 PM','QAR 100','2 left','tier-mid','p-night','aria-pressed="false"']) assert.ok(html.includes(text),text)
})
test('selected extension shows discounted and original prices plus capacity at the same time', async()=>{
  const html=await slot({}, {selectedSet:new Set(['6:00 PM']),isDisc:()=>true,priceFor:()=>80})
  for(const text of ['aria-pressed="true"','QAR 80','>100</s>','↓ Extension','2 left','lucide-check']) assert.ok(html.includes(text),text)
})
test('host selection retains base price and unavailable reasons are disabled', async()=>{
  const host=await slot({}, {selectMode:true,isDisc:()=>true,priceFor:()=>80})
  assert.ok(host.includes('QAR 100'));assert.ok(!host.includes('↓ Extension'))
  for(const [props,label] of [[{siblingTaken:true},'In this event'],[{past:true},'Passed'],[{capacity:4},'Full'],[{capacity:1},'Booked']]){
    const html=await slot({available:false,...props})
    assert.match(html,/disabled/);assert.ok(html.includes(label));assert.ok(!html.includes('QAR'))
  }
})
