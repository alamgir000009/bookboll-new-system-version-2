<script setup>
import { financeRequest } from '../lib/financeApi.js'
import { ref, watch } from 'vue'
import { ArrowRight, X } from 'lucide-vue-next'
import { dateRangeError, validDate, money, count, previewReady, transferPreviewValid, newRequestKey } from '../lib/finance.js'
import './finance.css'
const props = defineProps({ venueId:[String,Number], venueName:String })
const emit = defineEmits(['close','transferred'])
const options=ref(null), form=ref({business_id:'',effective_from:'',history_mode:'preserve',from_date:'',to_date:''}), preview=ref(null), agreed=ref(false), busy=ref(false), error=ref(''), notice=ref(''), key=ref(newRequestKey())
let generation=0
watch(form,()=>{generation++;preview.value=null;agreed.value=false;key.value=newRequestKey()},{deep:true,flush:'sync'})
async function load() { const result=await financeRequest(`/admin/venues/${props.venueId}/transfer-options`); if(result.ok) options.value=result.data; else notice.value=result.message }
async function review() {
  error.value=''
  if (!form.value.business_id || !validDate(form.value.effective_from)) { error.value='Choose the destination business and transfer date.'; return }
  if(form.value.history_mode==='legacy_deposit_fee') {
    error.value=dateRangeError(form.value.from_date,form.value.to_date)
    if(!error.value && form.value.to_date >= form.value.effective_from) error.value='Legacy history must end before the transfer date.'
  }
  if(error.value || busy.value || !options.value?.can_transfer) return
  busy.value=true; const current=++generation
  const result=await financeRequest(`/admin/venues/${props.venueId}/transfer-preview`,{method:'POST',body:{...form.value,from_date:form.value.history_mode==='preserve'?null:form.value.from_date,to_date:form.value.history_mode==='preserve'?null:form.value.to_date}})
  busy.value=false
  if(current!==generation)return
  if(!result.ok || !transferPreviewValid(result.data,form.value)) {error.value=result.message || 'The server did not return a complete transfer preview.';return}
  preview.value=result.data;agreed.value=false
}
async function commit() {
  if(busy.value || !agreed.value || !previewReady(preview.value)) {error.value='Review the transfer again if its preview has expired.';return}
  busy.value=true; error.value=''
  const result=await financeRequest(`/admin/venues/${props.venueId}/transfers`,{method:'POST',body:{preview_token:preview.value.preview_token,idempotency_key:key.value,acknowledge_legacy_cash:form.value.history_mode==='legacy_deposit_fee'}})
  busy.value=false
  if(!result.ok || !result.data?.transfer?.id) {error.value=result.message || 'The transfer was not confirmed. Refresh its preview before continuing.';return}
  emit('transferred',result.data.venue)
}
load()
</script>
<template>
  <div class="modal-backdrop" style="z-index:3300" @click.self="!busy && emit('close')">
    <div class="modal fin-dialog" v-dialog="() => !busy && emit('close')" role="dialog" aria-modal="true" aria-labelledby="transfer-title" @input.stop @change.stop>
      <div class="modal-header"><div><div class="fin-eyebrow">Venue ownership</div><h2 id="transfer-title" class="modal-title">Move to an external business</h2></div><button class="btn-icon" :disabled="busy" aria-label="Close transfer" @click="emit('close')"><X :size="18"/></button></div>
      <div class="modal-body"><p class="fin-help" style="margin-bottom:20px">{{ venueName }} · Review ownership and historical payments together.</p><p v-if="notice" class="fin-notice">{{ notice }}</p>
        <fieldset v-show="!preview" :disabled="busy" style="border:0;padding:0;margin:0"><div class="fin-form-grid">
          <label class="fin-field fin-wide">Destination business *<select v-model="form.business_id"><option value="">Choose a business</option><option v-for="item in options?.businesses || []" :key="item.id" :value="item.id">{{ item.name }}{{ item.owner_user_id ? '' : ' · owner not assigned' }}</option></select></label>
          <label class="fin-field fin-wide">Transfer effective from *<input v-model="form.effective_from" type="date"/></label>
        </div><h3 style="font-size:15px;margin-top:24px">What happens to previous payments?</h3>
        <label class="fin-choice"><input v-model="form.history_mode" type="radio" value="preserve"/><span><strong>Keep history unchanged</strong><small>Only future activity uses the new business. Existing fees, receipts and landlord obligations stay as recorded.</small></span></label>
        <label class="fin-choice"><input v-model="form.history_mode" type="radio" value="legacy_deposit_fee"/><span><strong>Reconcile legacy provider bookings</strong><small>Paid deposits become Bookboll fees. Arrival balances are recorded as cash collected by the destination provider, including balances still marked pending.</small></span></label>
        <div v-if="form.history_mode==='legacy_deposit_fee'"><div class="fin-form-grid"><label class="fin-field">History from *<input v-model="form.from_date" type="date"/></label><label class="fin-field">History to *<input v-model="form.to_date" type="date"/></label></div><p class="fin-notice" style="margin-top:14px">This confirms historical cash collection. It does not send money to the provider. Skipped-payment slot blocks are excluded. Cancelled, refunded, disputed or ambiguous records must be excluded or resolved during review.</p></div>
        </fieldset>
        <section v-if="preview" class="fin-section"><div class="fin-eyebrow">Review before applying</div><h3 style="font-size:16px">{{ preview.destination_name }}</h3><dl class="fin-review-list"><dt>Transfer date</dt><dd>{{ form.effective_from }}</dd><dt>Historical payments</dt><dd>{{ form.history_mode === 'preserve' ? 'Keep unchanged' : `${form.from_date} – ${form.to_date} · legacy cash reconciliation` }}</dd><dt>Eligible bookings</dt><dd>{{ count(preview.eligible_count) }}</dd><dt>Pending arrivals to mark paid cash</dt><dd>{{ count(preview.pending_arrival_count) }}</dd><dt>Excluded / needs review</dt><dd>{{ count(preview.excluded_count) }}</dd><dt>Open landlord obligations</dt><dd>{{ count(preview.landlord_obligation_count) }}</dd></dl>
          <div v-for="total in preview.totals" :key="total.currency" class="fin-panel" style="margin-top:12px"><strong>{{ total.currency }}</strong><div class="fin-row"><span>Paid deposits → Bookboll fee</span><strong>{{ money(total.deposit_to_fee,total.currency) }}</strong></div><div class="fin-row"><span>Arrival cash → collected directly by provider</span><strong>{{ money(total.provider_cash,total.currency) }}</strong></div><div class="fin-row"><span>Change in Bookboll earnings</span><strong>{{ money(total.earnings_delta,total.currency) }}</strong></div><p class="fin-help" style="margin-top:12px">This records the provider’s own cash collection. Bookboll owes no payout for these arrival balances.</p></div>
          <p v-for="warning in preview.warnings || []" :key="warning" class="fin-notice">{{ warning }}</p><p v-for="blocker in preview.blockers || []" :key="blocker" class="fin-error">{{ blocker }}</p>
          <p class="fin-help">Existing landlord liabilities are preserved. Original payments remain in the audit history with linked correction entries. Review expires at {{ preview.expires_at }}.</p>
          <label class="fin-checkbox"><input v-model="agreed" type="checkbox" :disabled="busy"/><span>{{ form.history_mode==='legacy_deposit_fee' ? 'I confirm the reviewed deposits were Bookboll fees and the reviewed arrival balances were collected by this provider as cash.' : 'I confirm this venue should move to the selected business, keeping historical payments unchanged.' }}</span></label>
        </section><p v-if="error" class="fin-error" role="alert">{{ error }}</p>
      </div><div class="modal-footer"><button class="btn btn-secondary" :disabled="busy" @click="emit('close')">Cancel</button><button v-if="preview" class="btn btn-secondary" :disabled="busy" @click="preview=null;agreed=false">Back to details</button><button class="btn btn-secondary" :disabled="busy || !options?.can_transfer" @click="review">{{ busy ? 'Working…' : preview ? 'Refresh review' : 'Review transfer' }}</button><button v-if="preview" class="btn btn-primary" :disabled="busy || !agreed || !previewReady(preview)" @click="commit">Confirm transfer<ArrowRight :size="14"/></button></div>
    </div>
  </div>
</template>
