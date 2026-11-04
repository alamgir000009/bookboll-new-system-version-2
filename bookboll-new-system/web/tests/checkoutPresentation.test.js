import test from 'node:test'
import assert from 'node:assert/strict'
import { renderCheckout } from './helpers/checkoutFixture.js'

test('checkout keeps dates, times, amount and both unselected payment choices visible',async()=>{
  const html=await renderCheckout()
  for(const value of ['Wed, Oct 14','4:00 PM','5:00 PM','QAR 85','Pay online','Deposit + on arrival','Edit date &amp; time','for="checkout-phone-number"','Remove Wed, Oct 14, 4:00 PM to 5:00 PM']) assert.ok(html.includes(value),value)
  assert.equal((html.match(/type="radio"/g)||[]).length,2)
  assert.doesNotMatch(html,/ checked/)
})
test('deposit selection retains the upfront amount, remaining balance and non-refundable terms',async()=>{
  const html=await renderCheckout({payMethod:'deposit',paymentSelected:true,paySplit:{method:'deposit',arrival:68}})
  for(const value of ['QAR 17','QAR 68','Remaining on arrival','non-refundable']) assert.ok(html.includes(value),value)
  assert.equal((html.match(/ checked/g)||[]).length,1)
})
