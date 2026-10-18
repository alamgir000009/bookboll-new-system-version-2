import test from 'node:test'
import assert from 'node:assert/strict'
import {readFileSync} from 'node:fs'
import {customerTypeInfo,customerTypeFilterConfirmed} from '../src/lib/customerTypes.js'
import {isProviderSlotBlock,bookingChannel,isCommercialBooking} from '../src/lib/bookingMeta.js'

test('legacy skipped payments are provider slot blocks, while membership sessions remain membership blocks',()=>{
  for(const row of [{payment_method:'payment_skipped'},{payment_status:'skipped'},{record_purpose:'provider_slot_block'}]) {
    assert.equal(isProviderSlotBlock(row),true);assert.equal(isCommercialBooking(row),false);assert.equal(bookingChannel(row),'manual')
  }
  assert.equal(isProviderSlotBlock({origin:'class_program',payment_method:'payment_skipped'}),false)
  assert.equal(isCommercialBooking({origin:'class_program',payment_method:'payment_skipped'}),false)
})
test('customer can have all product types, but slot blocks and canceled bookings confer no customer type',()=>{
  const customer={id:1,email:'one@example.test'}
  assert.deepEqual(customerTypeInfo(customer,{bookings:[{customer_id:1,payment_method:'payment_skipped'},{customer_id:1,booking_status:'cancelled'},{customer_id:1,origin:'class_program'}]}),{types:[],complete:false})
  assert.deepEqual(customerTypeInfo(customer,{bookings:[{customer_id:1},{customer_id:1,origin:'customer_game'},{customer_id:2}],subscriptions:[{customer_email:'ONE@example.test',status:'active'}]}),{types:['booking','membership','event'],complete:false})
  assert.deepEqual(customerTypeInfo({...customer,customer_types:['event','membership','booking','event'],customer_types_complete:true}),{types:['booking','membership','event'],complete:true})
  assert.deepEqual(customerTypeInfo({...customer,customer_types:[],customer_types_complete:true}),{types:[],complete:true})
})
test('customer-type filters must be confirmed by the server across pages, exports and ID selections',async()=>{
  const query={customer_types:['booking','event'],customer_types_match:'all',page:2}
  assert.equal(customerTypeFilterConfirmed({},query),false)
  assert.equal(customerTypeFilterConfirmed({customer_type_filter:{version:1,types:['event','booking'],match:'any'}},query),false)
  assert.equal(customerTypeFilterConfirmed({customer_type_filter:{version:1,types:['event','booking'],match:'all'}},query),true)
  let response={success:true,data:{customers:[{id:999}]}}
  const requests=[]
  const source=readFileSync(new URL('../src/lib/customers.js',import.meta.url),'utf8').replace(/^import .*$/gm,'').replace(/export /g,'')
  const fetch=new Function('apiFetch','customerTypeFilterConfirmed',source+'\nreturn fetchCustomersDashboard;')(async(path,options)=>{requests.push(options.query);return response},customerTypeFilterConfirmed)
  for(const extra of [{page:2},{export:1},{ids_only:1}]) assert.equal((await fetch({...query,...extra})).success,false)
  response={success:true,data:{customer_type_filter:{version:1,types:['event','booking'],match:'all'},customers:[{id:2}]}}
  assert.deepEqual((await fetch(query)).data.customers,[{id:2}])
  assert.deepEqual(requests[0].customer_types,['booking','event'])
})
