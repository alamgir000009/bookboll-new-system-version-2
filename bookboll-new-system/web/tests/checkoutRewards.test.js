import test from 'node:test'
import assert from 'node:assert/strict'
import { bookbollShare, validateRedemptionQuote, redemptionFields } from '../src/lib/checkoutRewards.js'

const now=Date.parse('2026-10-14T12:00:00Z')
const args={countryId:179,currency:'QAR',balance:10000,total:100,dueNow:100,now,rules:{is_active:true,redeem_rate:100,min_redeem:100,max_redeem_pct:100}}
const quote={quote_id:'server-quote',country_id:179,currency:'QAR',points:1500,discount:15,bookboll_share:{commission:15,event_fee:0,booking_fee:0},due_now_before:100,order_total_before:100,expires_at:'2026-10-14T12:05:00Z'}
test('full online payment never becomes the platform share',()=>{
 assert.equal(validateRedemptionQuote(quote,args).amount,15)
 assert.equal(validateRedemptionQuote({...quote,points:10000,discount:100},args),null)
 assert.equal(bookbollShare({commission:15,event_fee:4,booking_fee:2}),21)
 assert.equal(validateRedemptionQuote({...quote,bookboll_share:{commission:15,event_fee:4,booking_fee:2},points:2100,discount:21},args).amount,21)
})
test('zero commission and absent fee metadata cannot become a default fifteen percent',()=>{
 assert.equal(validateRedemptionQuote({...quote,bookboll_share:{commission:0,event_fee:0,booking_fee:0}},args),null)
 assert.equal(validateRedemptionQuote({...quote,bookboll_share:{commission:15}},args),null)
 assert.equal(bookbollShare({commission:-10,event_fee:5,booking_fee:0}),null)
 assert.equal(bookbollShare({commission:NaN,event_fee:0,booking_fee:0}),null)
})
test('balance, remaining online payment, minimum and percentage cap all constrain redemption',()=>{
 assert.equal(validateRedemptionQuote(quote,{...args,balance:1499}),null)
 assert.equal(validateRedemptionQuote({...quote,due_now_before:10},{...args,dueNow:10}),null)
 assert.equal(validateRedemptionQuote(quote,{...args,rules:{...args.rules,min_redeem:1600}}),null)
 assert.equal(validateRedemptionQuote(quote,{...args,rules:{...args.rules,max_redeem_pct:10}}),null)
 assert.equal(validateRedemptionQuote(quote,{...args,rules:{...args.rules,max_redeem_pct:0}}),null)
 assert.equal(validateRedemptionQuote(quote,{...args,rules:{...args.rules,is_active:false}}),null)
})
test('deposit points leave the provider arrival amount unchanged',()=>{
 const valid=validateRedemptionQuote({...quote,due_now_before:15},{...args,dueNow:15})
 assert.equal(15-valid.amount,0)
 assert.equal(100-15,85)
 assert.equal(validateRedemptionQuote({...quote,due_now_before:0},{...args,dueNow:0}),null)
})
test('country/currency, totals, conversion and expiry must match the current checkout',()=>{
 for(const patch of [{country_id:167},{currency:'PKR'},{due_now_before:99},{order_total_before:99},{points:1400},{discount:15.5},{expires_at:'invalid'},{expires_at:'2026-10-14T11:00:00Z'}]) assert.equal(validateRedemptionQuote({...quote,...patch},args),null)
 assert.equal(validateRedemptionQuote(quote,{...args,rules:{...args.rules,redeem_rate:200}}),null)
})
test('only the quote identifier and whole points travel to the payment API',()=>{
 assert.deepEqual(redemptionFields(null),{})
 assert.deepEqual(redemptionFields({quoteId:'q',points:100,amount:9999,share:99999,expiresAt:Date.now()+10000}),{loyalty_quote_id:'q',loyalty_points:100})
 assert.throws(()=>redemptionFields({quoteId:'q',points:100,expiresAt:Date.now()-1}),/expired/)
 assert.throws(()=>redemptionFields({quoteId:'q',points:100}),/expired/)
})
