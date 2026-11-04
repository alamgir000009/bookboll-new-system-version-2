<script setup>
import { ref, computed, watch, onBeforeUnmount } from 'vue'
import { Gift, Check, RotateCcw } from 'lucide-vue-next'
import { apiFetch, getCustomerToken } from '../../lib/api.js'
import { customer, isCustomer } from '../../lib/customerSession.js'
import { scopedRewards, verifiedRewardRules } from '../../lib/countryRewards.js'
import { validateRedemptionQuote } from '../../lib/checkoutRewards.js'

const props = defineProps({ modelValue: Object, kind: String, countryId: [Number, String], currency: String, checkout: Object, total: Number, dueNow: Number, active: {type:Boolean,default:true} })
const emit = defineEmits(['update:modelValue'])
const loading = ref(false), balance = ref(null), quote = ref(null), message = ref(''), retryable = ref(false)
const scope = computed(() => JSON.stringify([props.kind,props.countryId,props.currency,props.checkout,props.total,props.dueNow,props.active,customer.value?.id,isCustomer.value]))
let generation = 0, expiryTimer
const n = value => Number(value).toLocaleString()
function clear() { quote.value = null; clearTimeout(expiryTimer); emit('update:modelValue', null) }
async function load() {
  const current = ++generation, key = scope.value, token = getCustomerToken()
  clear(); balance.value = null; message.value = ''; retryable.value = false; loading.value = false
  if (!props.active || !isCustomer.value) return
  if (!props.countryId) { message.value = 'Country rewards are not available for this checkout yet.'; return }
  loading.value = true
  try {
    const [account, config] = await Promise.all([
      apiFetch('/customer/loyalty', {query:{country_id:props.countryId}}),
      apiFetch('/marketplace/loyalty-config', {query:{country_id:props.countryId}}),
    ])
    if (current !== generation || scope.value !== key || token !== getCustomerToken()) return
    const scoped = account.success && scopedRewards(account.data, props.countryId)
    const rules = config.success && verifiedRewardRules(config.data, props.countryId)
    if (!account.success || !config.success) { message.value = 'Could not check your rewards. You can retry or continue without points.'; retryable.value = true; return }
    if (!scoped || !Number.isFinite(Number(scoped.balance))) { message.value = 'Your balance for this country is not available yet. No points have been applied.'; return }
    balance.value = Number(scoped.balance)
    if (!rules) { message.value = 'Rewards are not available for this country yet.'; return }
    if (!rules.is_active) { message.value = 'Rewards are paused in this country.'; return }
    if (props.dueNow <= 0) { message.value = 'Points apply to eligible payments collected online today.'; return }
    if (balance.value < rules.min_redeem || balance.value < rules.redeem_rate) { message.value = `You need at least ${n(Math.max(rules.min_redeem,rules.redeem_rate))} points to use rewards.`; return }
    // Older APIs ignore redemption fields. Do not show a discount until the API
    // explicitly supports this contract and has priced this exact checkout.
    if (scoped.redemption_checkout_v1 !== true) { message.value = 'Points cannot be applied to this checkout yet. Your balance stays available for future eligible bookings.'; return }
    const res = await apiFetch('/customer/loyalty/checkout-quote', {method:'POST',body:{kind:props.kind,country_id:props.countryId,currency:props.currency,checkout:props.checkout}})
    if (current !== generation || scope.value !== key || token !== getCustomerToken()) return
    const valid = res.success && validateRedemptionQuote(res.data, {countryId:props.countryId,currency:props.currency,balance:balance.value,rules,total:props.total,dueNow:props.dueNow})
    if (!valid) { message.value = 'No points discount is confirmed for this checkout. You can retry or continue without points.'; retryable.value = true; return }
    quote.value = {...valid, contextKey:key}
    expiryTimer = setTimeout(() => { clear(); message.value = 'Your points quote expired. Check it again before applying points.'; retryable.value = true }, Math.min(2147483647, valid.expiresAt - Date.now()))
  } catch { if (current === generation) { message.value = 'Could not check your rewards. No points have been applied.'; retryable.value = true } }
  finally { if (current === generation) loading.value = false }
}
function toggle() {
  if (props.modelValue) emit('update:modelValue',null)
  else if (quote.value?.expiresAt > Date.now() && quote.value.contextKey === scope.value) emit('update:modelValue',{...quote.value})
  else load()
}
function validate() {
  if (!props.modelValue) return true
  if (quote.value?.expiresAt > Date.now() && props.modelValue.contextKey === scope.value && isCustomer.value) return true
  clear(); load(); return false
}
defineExpose({validate})
watch(scope,load,{immediate:true,flush:'sync'})
onBeforeUnmount(() => { generation++; clearTimeout(expiryTimer) })
</script>
<template>
  <section v-if="active && isCustomer" class="checkout-rewards" aria-label="Use Bookboll rewards">
    <div class="cr-head"><Gift :size="20" /><div><h3>Use your rewards</h3><p v-if="balance !== null">{{ n(balance) }} points available in this country</p></div></div>
    <p v-if="loading" role="status">Checking your points…</p>
    <template v-else-if="quote">
      <p>Use {{ n(quote.points) }} points for <strong>{{ currency }} {{ n(quote.amount) }} off</strong> today's payment.</p>
      <button type="button" class="cr-apply" :class="{applied:modelValue}" :aria-pressed="!!modelValue" @click="toggle"><Check v-if="modelValue" :size="16" />{{ modelValue ? 'Points applied · Remove' : `Use ${n(quote.points)} points` }}</button>
      <small>Up to {{ currency }} {{ n(quote.share) }} is funded by Bookboll's share. Your venue or host receives their full share.</small>
    </template>
    <p v-else>{{ message }}</p>
    <div class="cr-links"><button v-if="retryable" type="button" @click="load"><RotateCcw :size="13" /> Check again</button><router-link to="/rewards" target="_blank" rel="noopener">How rewards work</router-link></div>
  </section>
</template>
<style scoped>
.checkout-rewards{margin:18px 0;padding:16px;border:1px solid var(--border-strong);border-radius:12px;background:var(--canvas);color:var(--text);text-align:start}.cr-head{display:flex;gap:10px;align-items:center}.cr-head>svg{color:var(--brand-ink);flex-shrink:0}.checkout-rewards h3{font-size:15px;line-height:1.4;margin:0}.checkout-rewards p{font-size:12px;line-height:1.6;color:var(--text-muted);margin:9px 0}.cr-head p{margin:2px 0 0}.checkout-rewards strong{color:var(--text)}.cr-apply{display:flex;align-items:center;justify-content:center;gap:7px;min-height:42px;width:100%;padding:10px;border:0;border-radius:8px;background:var(--brand);color:white;font:inherit;font-size:13px;font-weight:700;cursor:pointer}.cr-apply.applied{background:var(--success-bg);color:var(--success-ink);border:1px solid var(--success-ink)}.checkout-rewards small{display:block;font-size:11px;line-height:1.55;color:var(--text-muted);margin-top:10px}.cr-links{display:flex;gap:16px;margin-top:10px}.cr-links button,.cr-links a{display:inline-flex;align-items:center;gap:5px;background:none;border:0;padding:0;color:var(--brand-ink);font:inherit;font-size:12px;text-decoration:underline;text-underline-offset:3px;cursor:pointer}.checkout-rewards :is(button,a):focus-visible{outline:3px solid var(--brand-ink);outline-offset:3px}
</style>
