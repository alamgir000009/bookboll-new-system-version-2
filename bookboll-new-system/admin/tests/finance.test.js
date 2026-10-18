import test from 'node:test'
import assert from 'node:assert/strict'
import { financeResponse, money, agreementError, agreementPayload, agreementRevisionError, nextAgreementDate, dateRangeError, previewReady, transferPreviewValid, isBookbollVenue } from '../src/lib/finance.js'

test('missing financial values stay unavailable and real zero preserves currency',()=>{
  for(const value of [null,undefined,'',' ',false,[],NaN,'unknown']) assert.equal(money(value,'QAR'),'—')
  assert.match(money('0','QAR'),/QAR/)
  assert.equal(money(100,''),'—')
  assert.notEqual(money('100','PKR'),money('100','QAR'))
})
test('ownerless provider venues cannot receive Bookboll payment exemptions',()=>{
  assert.equal(isBookbollVenue({owner_user_id:null,business_id:20}),false)
  assert.equal(isBookbollVenue({operator_type:'provider',owner_user_id:null}),false)
  assert.equal(isBookbollVenue({operator_type:'bookboll',business_id:null}),true)
  assert.equal(isBookbollVenue(null),false)
})
test('only explicit supported response contracts can enable financial writes',()=>{
  for(const data of [null,{}, {contract_version:'1'}, {contract_version:2}]) assert.equal(financeResponse({success:true,data}).ok,false)
  assert.equal(financeResponse({success:false,data:{contract_version:1}}).ok,false)
  assert.equal(financeResponse({success:true,data:{contract_version:1}}).ok,true)
})
test('agreements handle zero/full revenue share, reject invalid rent and omit irrelevant amounts',()=>{
  const base={deal_type:'revenue_share',bookboll_share_percent:'0',monthly_rent:'5000',currency:'QAR',landlord_name:'Example',effective_from:'2026-10-01'}
  assert.equal(agreementError(base),'')
  assert.equal(agreementError({...base,bookboll_share_percent:'100'}),'')
  for(const value of ['','101','-1','bad']) assert.ok(agreementError({...base,bookboll_share_percent:value}))
  assert.equal(agreementPayload(base).monthly_rent,null)
  assert.equal(agreementPayload(base).bookboll_share_percent,'0')
  assert.equal(agreementPayload(base).revenue_basis,'on_arrival_after_platform_fees','landlord share is calculated after Bookboll platform fees')
  assert.ok(agreementError({...base,deal_type:'fixed_rent',monthly_rent:0}))
  assert.equal(agreementError({...base,deal_type:'direct_operation',landlord_name:''}),'')
  assert.equal(agreementPayload({...base,deal_type:'direct_operation'}).landlord_name,null)
  assert.ok(agreementError({...base,effective_to:'2026-09-30'}))
})
test('renewals need a new effective period and explicit reason, with calendar-safe default dates',()=>{
  const previous={id:4,effective_from:'2024-01-01'}
  const form={deal_type:'fixed_rent',monthly_rent:'3100',currency:'QAR',landlord_name:'Example',effective_from:'2025-01-01',change_type:'renewal',change_reason:'Annual renewal'}
  assert.equal(agreementRevisionError(form,previous),'')
  assert.ok(agreementRevisionError({...form,effective_from:'2024-01-01'},previous))
  assert.ok(agreementRevisionError({...form,change_reason:''},previous))
  assert.equal(nextAgreementDate('2024-02-29'),'2024-03-01')
  assert.equal(nextAgreementDate('2026-12-31'),'2027-01-01')
  assert.equal(nextAgreementDate(null),'')
})
test('legacy preview requires complete counts, matching destination and separate currency amounts',()=>{
  const form={business_id:4,effective_from:'2026-10-15',history_mode:'legacy_deposit_fee'}
  const data={...form,preview_token:'opaque',expires_at:'2030-01-01T00:00:00Z',eligible_count:1,pending_arrival_count:1,excluded_count:0,landlord_obligation_count:0,totals:[{currency:'QAR',deposit_to_fee:'20',provider_cash:'80',earnings_delta:'0'}],warnings:[],blockers:[]}
  assert.equal(transferPreviewValid(data,form),true)
  for(const change of [{business_id:9},{eligible_count:null},{totals:[{currency:'QAR',deposit_to_fee:null}]}])assert.equal(transferPreviewValid({...data,...change},form),false)
  assert.equal(previewReady(data,Date.parse('2026-10-14')),true)
  assert.equal(previewReady({...data,blockers:['Already settled']},Date.parse('2026-10-14')),false)
  assert.equal(previewReady({...data,expires_at:'2020-01-01'},Date.now()),false)
  assert.equal(previewReady({...data,blockers:undefined},Date.now()),false)
})
