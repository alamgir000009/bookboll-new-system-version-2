import test from 'node:test'
import assert from 'node:assert/strict'
import { scopedRewards, verifiedRewardRules, rewardExample } from '../src/lib/countryRewards.js'

test('unscoped or wrong-country balances are never relabeled as the requested country', () => {
  assert.equal(scopedRewards({balance:500},179),null)
  assert.equal(scopedRewards({country_id:167,balance:500},179),null)
  const scoped = scopedRewards({country_id:'179',balance:85,ledger:[{id:1,country_id:179},{id:2,country_id:167},{id:3}]},179)
  assert.equal(scoped.balance,85)
  assert.deepEqual(scoped.ledger.map(e=>e.id),[1,3])
})
test('country rates come from that country response and unavailable rules are not invented', () => {
  const raw = {country_id:179,earn_rate:2.5,redeem_rate:120,min_redeem:240,expiry_months:6,is_active:1}
  const rules = verifiedRewardRules({loyalty:raw},'179')
  assert.equal(rules.earn_rate,2.5)
  assert.equal(rules.redeem_rate,120)
  assert.equal(rewardExample(11,rules),27)
  assert.equal(rewardExample(11,rules,2),55)
  assert.equal(verifiedRewardRules({loyalty:raw},167),null)
  assert.equal(verifiedRewardRules({},179),null)
  assert.equal(verifiedRewardRules({loyalty:{...raw,earn_rate:'invalid'}},179),null)
  assert.equal(verifiedRewardRules({loyalty:{...raw,is_active:'0'}},179).is_active,false)
})
