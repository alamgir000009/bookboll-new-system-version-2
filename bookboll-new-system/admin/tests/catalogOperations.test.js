import test from 'node:test'
import assert from 'node:assert/strict'
import { movePhoto } from '../src/lib/photoOrder.js'
import { fetchExportPages } from '../src/lib/exportPages.js'
import { customerExportFields } from '../src/lib/customerExport.js'
test('customer export aggregates include rows beyond the visible customer page', () => {
  const fields = customerExportFields({ bookings:[{customer_id:99,location_id:1,total:120},{customer_id:99,location_id:1,total:80}], locations:[{id:1,currency_override:'QAR'}], countries:[{id:2,name:'Qatar'}], customer_loyalty:[{customer_id:99,country_id:2,points:200,lifetime_points:2500}] })
  assert.equal(fields.count({id:99}),2)
  assert.equal(fields.spend({id:99}),'QAR 200')
  assert.equal(fields.points({id:99}),'Qatar 200 (Star)')
  assert.equal(fields.tier({id:99}),'Star')
  assert.equal(customerExportFields({}).count({id:99}),'—')
})
test('photo ordering moves any photo, including the cover, without changing the original gallery', () => {
  const photos = ['cover','second','third','fourth']
  assert.deepEqual(movePhoto(photos,3,0),['fourth','cover','second','third'])
  assert.deepEqual(movePhoto(photos,0,1),['second','cover','third','fourth'])
  assert.deepEqual(movePhoto(photos,1,2),['cover','third','second','fourth'])
  assert.deepEqual(movePhoto(photos,0,-1),photos)
  assert.deepEqual(photos,['cover','second','third','fourth'])
})
test('payment export preserves all filters, loads later pages and merges their lookups', async () => {
  const calls=[]
  const fetch = async query => {
    calls.push(query)
    return {success:true,data:{payments:{data:[{id:query.page}],current_page:query.page,last_page:2,total:2},customers:[{id:query.page,full_name:'Customer '+query.page}]}}
  }
  const {rows,lookups} = await fetchExportPages(fetch,{status:'paid',search:'court',country_id:'1,2',program_id:'3',page:9},'payments')
  assert.deepEqual(rows.map(r=>r.id),[1,2])
  assert.deepEqual(lookups.customers.map(r=>r.id),[1,2])
  assert.deepEqual(calls.map(q=>q.page),[1,2])
  assert.ok(calls.every(q=>q.status==='paid' && q.country_id==='1,2' && q.program_id==='3' && q.search==='court'))
})
test('unfiltered export sends pagination only and failures never return partial CSV data', async () => {
  let query
  await fetchExportPages(async q => {query=q;return {success:true,data:{payments:[]}}},{},'payments')
  assert.deepEqual(query,{page:1,records_per_page:100})
  await assert.rejects(fetchExportPages(async q => q.page===1 ? {success:true,data:{payments:{data:[{id:1}],total:2,last_page:2}}} : {success:false,message:'Unavailable'}, {}, 'payments'),/Unavailable/)
  await assert.rejects(fetchExportPages(async () => ({success:true,data:{payments:{data:[{id:1}],total:2,last_page:1}}}),{},'payments'),/Records changed/)
  await assert.rejects(fetchExportPages(async () => ({success:true,data:{payments:[{id:1}],truncated:true}}),{},'payments'),/Too many/)
})
