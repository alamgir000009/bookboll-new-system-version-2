<script setup>
import { financeRequest } from '../lib/financeApi.js'
import { ref, watch, computed } from 'vue'
import { agreementRevisionError, agreementPayload, nextAgreementDate, money, validDate, newRequestKey } from '../lib/finance.js'
import { FileText, Check, Plus, RefreshCw, X } from 'lucide-vue-next'
import './finance.css'
const props = defineProps({ venueId:[String,Number], currency:String })
const emit = defineEmits(['dirty','busy'])
const empty = () => ({deal_type:'fixed_rent',monthly_rent:'',bookboll_share_percent:'',currency:props.currency || '',landlord_name:'',effective_from:'',effective_to:'',notes:'',change_type:'initial',change_reason:''})
const form=ref(empty()), current=ref(null), versions=ref([]), options=ref(null), busy=ref(false), loading=ref(false), error=ref(''), notice=ref(''), saved=ref(''), file=ref(null), fileInput=ref(null), editing=ref(false), predecessor=ref(null), reuseContract=ref(false)
let initial='', key=newRequestKey(), uploadId=null, requestId=0
const dirty=computed(()=>editing.value && (JSON.stringify(form.value)!==initial || !!file.value || reuseContract.value))
const timeline=computed(()=>[...new Map([current.value,...versions.value].filter(Boolean).map(row=>[row.id,row])).values()].sort((a,b)=>b.effective_from.localeCompare(a.effective_from)))
const latest=computed(()=>timeline.value[0] || null)
const supported=computed(()=>options.value?.can_manage && options.value.agreement_history_version===2)
const costExample=computed(()=>{
  if(form.value.deal_type==='revenue_share') {
    const n=Number(form.value.bookboll_share_percent)
    return form.value.bookboll_share_percent!=='' && Number.isFinite(n) && n>=0 && n<=100 ? `Bookboll keeps ${n}%; the landlord receives ${Math.round((100-n)*100)/100}% of on-arrival venue revenue, after Bookboll’s platform fees.` : 'Enter Bookboll’s share to see the landlord’s percentage.'
  }
  if(form.value.deal_type==='direct_operation') return 'No rent or revenue-share cost accrues during these agreement dates.'
  if(!validDate(form.value.effective_from) || !(Number(form.value.monthly_rent)>0)) return 'Monthly rent ÷ calendar days in each month × active agreement days in the report period.'
  const [year,month]=form.value.effective_from.split('-').map(Number), days=new Date(Date.UTC(year,month,0)).getUTCDate()
  return `Starting month: ${money(form.value.monthly_rent,form.value.currency)} ÷ ${days} days = ${money(Number(form.value.monthly_rent)/days,form.value.currency)} per day (rounded preview).`
})
watch(dirty,value=>emit('dirty',value),{flush:'sync'})
watch(busy,value=>emit('busy',value),{flush:'sync'})
watch([form,reuseContract],()=>{key=newRequestKey()},{deep:true,flush:'sync'})
function reset(){editing.value=false;form.value=empty();initial=JSON.stringify(form.value);file.value=null;reuseContract.value=false;predecessor.value=null;if(fileInput.value)fileInput.value.value='';uploadId=null;key=newRequestKey();error.value=''}
function startAgreement(){
  reset();saved.value='';predecessor.value=latest.value
  form.value={...empty(),...(latest.value ? {deal_type:latest.value.deal_type,monthly_rent:latest.value.monthly_rent ?? '',bookboll_share_percent:latest.value.bookboll_share_percent ?? '',currency:latest.value.currency,landlord_name:latest.value.landlord_name || '',effective_from:nextAgreementDate(latest.value.effective_to),change_type:'renewal'} : {})}
  initial=JSON.stringify(form.value);editing.value=true
}
async function load(){
  const id=++requestId;loading.value=true;options.value=null;error.value='';notice.value='';current.value=null;versions.value=[]
  const result=await financeRequest(`/admin/venues/${props.venueId}/operating-agreements`)
  if(id!==requestId)return
  loading.value=false
  if(!result.ok)notice.value=result.message
  else {options.value=result.data;current.value=result.data.current;versions.value=result.data.history || [];if(result.data.agreement_history_version!==2)notice.value='Agreement renewals need the updated backend. You can prepare the terms below.'}
  reset()
}
function pickFile(event){
  file.value=null;uploadId=null;error.value='';key=newRequestKey()
  const selected=event.target.files?.[0]
  if(!selected)return
  if(!['application/pdf','image/jpeg','image/png'].includes(selected.type) || selected.size>10*1024*1024){error.value='Choose a PDF, JPG or PNG up to 10 MB.';event.target.value='';return}
  file.value=selected;reuseContract.value=false
}
async function save(){
  error.value=agreementRevisionError(form.value,predecessor.value);saved.value=''
  if(error.value || busy.value || !supported.value)return
  busy.value=true
  if(file.value && !uploadId){
    const body=new FormData();body.append('file',file.value);body.append('idempotency_key',`${key}-contract`)
    const result=await financeRequest(`/admin/venues/${props.venueId}/contracts`,{method:'POST',body})
    if(!result.ok || !result.data?.document_id){error.value=result.message || 'Contract upload was not confirmed.';busy.value=false;return}
    uploadId=result.data.document_id
  }
  const result=await financeRequest(`/admin/venues/${props.venueId}/operating-agreements`,{method:'POST',body:{...agreementPayload(form.value),change_type:form.value.change_type,change_reason:form.value.change_reason.trim() || null,supersedes_agreement_id:predecessor.value?.id || null,expected_version:options.value.version,contract_document_id:uploadId || (reuseContract.value?predecessor.value?.contract_document_id:null) || null,idempotency_key:key}})
  if(!result.ok || !result.data?.agreement?.id){error.value=result.message || 'The agreement was not confirmed. Your draft is still here.';busy.value=false;return}
  const savedId=result.data.agreement.id
  await load();busy.value=false
  if(timeline.value.some(item=>String(item.id)===String(savedId)))saved.value='Agreement recorded. Reporting will use its effective dates.'
  else notice.value='The server accepted the agreement, but its saved history could not be verified. Refresh to check before adding another version.'
}
function dealLabel(item){return item.deal_type==='fixed_rent' ? `${money(item.monthly_rent,item.currency)} / month` : item.deal_type==='revenue_share' ? `Bookboll ${item.bookboll_share_percent}% · landlord ${Math.round((100-Number(item.bookboll_share_percent))*100)/100}%` : 'No landlord payment'}
function appliedThrough(item){
  const successor=timeline.value.find(row=>String(row.supersedes_agreement_id)===String(item.id))
  if(!successor || !validDate(successor.effective_from))return item.effective_to || 'ongoing'
  const day=new Date(`${successor.effective_from}T00:00:00Z`);day.setUTCDate(day.getUTCDate()-1)
  const cutoff=day.toISOString().slice(0,10)
  return item.effective_to && item.effective_to<cutoff ? item.effective_to : cutoff
}
watch(()=>props.venueId,load,{immediate:true})
</script>
<template>
  <section class="agreement-workspace" aria-label="Operating agreements">
    <div class="fin-heading"><div><h3>Operating agreements</h3><p>Track rent, revenue share and contract renewals over time.</p></div><button type="button" class="btn-icon" aria-label="Refresh agreements" :disabled="loading || busy || dirty" @click="load"><RefreshCw :size="17"/></button></div>
    <p v-if="loading" class="fin-help" role="status">Loading agreement history…</p>
    <p v-if="notice" class="fin-notice">{{ notice }}</p>
    <div v-if="!editing && !loading" class="agreement-overview">
      <div class="agreement-current"><FileText :size="24"/><div><span class="fin-help">{{ current ? 'Current agreement' : 'No current agreement' }}</span><h4>{{ current ? dealLabel(current) : 'Add the landlord terms' }}</h4><p v-if="current">{{ current.landlord_name || 'Direct operation' }} · {{ current.effective_from }} – {{ appliedThrough(current) }}</p><p v-else>Record the first agreement to calculate landlord costs. Missing terms stay unavailable in reporting.</p><p v-if="latest && latest.id !== current?.id" class="fin-help">Latest recorded terms start {{ latest.effective_from }}. New terms will follow that version.</p></div></div>
      <button type="button" class="btn btn-primary btn-sm" @click="startAgreement"><Plus :size="15"/>{{ latest ? 'Renew or change terms' : 'Add agreement' }}</button>
    </div>
    <details class="agreement-explainer"><summary>How landlord costs are calculated</summary><p><strong>Fixed rent:</strong> monthly rent ÷ days in that month × agreement days within your report. It accrues even with no bookings.</p><p><strong>Revenue share:</strong> on-arrival venue revenue × the landlord’s percentage, using the terms active on each service date. Online payments and deposits are Bookboll’s platform fees and are deducted before the share is calculated. Refunds adjust the original allocation.</p><p>Bookboll’s remainder is on-arrival venue revenue less landlord cost. Paying a landlord reduces the outstanding balance without charging the cost twice.</p><p>Renewals create a new dated version. Earlier terms and contract files stay in history. Historical corrections require a reviewed adjustment.</p></details>
    <div v-if="editing" class="agreement-editor">
      <div class="fin-heading"><div><h4>{{ predecessor ? 'Record the next agreement' : 'Record the first agreement' }}</h4><p>{{ predecessor ? 'Copy the previous terms, then choose when the new terms begin.' : 'Set the commercial terms and their start date.' }}</p></div><button type="button" class="btn-icon" aria-label="Cancel agreement" :disabled="busy" @click="reset"><X :size="18"/></button></div>
      <fieldset :disabled="busy || loading" class="agreement-fields">
        <div v-if="predecessor" class="fin-form-grid agreement-group">
          <label class="fin-field">Type of change<select v-model="form.change_type"><option value="renewal">Contract renewal</option><option value="amendment">Changed commercial terms</option></select></label>
          <label class="fin-field">Reason *<input v-model="form.change_reason" maxlength="500" placeholder="e.g. Annual renewal, revised rent"/></label>
        </div>
        <div class="agreement-group"><h4>Commercial terms</h4>
          <div class="agreement-models"><label v-for="[value,title,description] in [['fixed_rent','Fixed rent','One monthly amount'],['revenue_share','Revenue share','A percentage of on-arrival venue revenue'],['direct_operation','No landlord payment','Bookboll retains venue income']]" :key="value" class="fin-choice"><input v-model="form.deal_type" type="radio" :value="value"/><span><strong>{{ title }}</strong><small>{{ description }}</small></span></label></div>
          <div class="fin-form-grid"><label v-if="form.deal_type!=='direct_operation'" class="fin-field fin-wide">Landlord / counterparty *<input v-model="form.landlord_name" maxlength="200" placeholder="Person or company name"/></label><label class="fin-field">Currency *<select v-model="form.currency"><option value="">Select currency</option><option v-for="code in options?.currencies || (currency?[currency]:[])" :key="code">{{ code }}</option></select></label><label v-if="form.deal_type==='fixed_rent'" class="fin-field">Monthly rent *<input v-model="form.monthly_rent" type="number" min="0" step="any"/></label><label v-if="form.deal_type==='revenue_share'" class="fin-field">Bookboll’s share (%) *<input v-model="form.bookboll_share_percent" type="number" min="0" max="100" step="0.01"/></label></div>
        </div>
        <div class="agreement-group"><h4>Agreement dates</h4><div class="fin-form-grid"><label class="fin-field">New terms start *<input v-model="form.effective_from" type="date" :min="predecessor ? nextAgreementDate(predecessor.effective_from) : undefined"/></label><label class="fin-field">Last day (optional)<input v-model="form.effective_to" type="date" :min="form.effective_from"/></label></div><p class="fin-help">{{ predecessor ? 'The previous version ends before these terms start, or keeps its earlier contracted end date. Gaps stay visible; they do not imply free rent.' : 'Dates are inclusive. Leave the last day empty for an ongoing agreement.' }}</p></div>
        <div class="fin-notice agreement-calculation"><strong>Calculation preview</strong><p>{{ costExample }}</p><p v-if="form.deal_type==='revenue_share'">Based on on-arrival venue revenue after refunds; Bookboll’s platform fees (online payments and deposits) and tax are deducted first.</p><small>This explains the formula. Saved, dated agreements drive the backend report totals.</small></div>
        <details class="agreement-group agreement-documents"><summary>Contract & notes <span class="fin-help">Optional</span></summary><label class="fin-field">Upload contract<input ref="fileInput" type="file" accept=".pdf,.jpg,.jpeg,.png" @change="pickFile"/><span class="fin-help">Private PDF, JPG or PNG · up to 10 MB</span></label><label v-if="predecessor?.contract_document_id && !file" class="fin-checkbox"><input v-model="reuseContract" type="checkbox"/><span>Use the previous contract: {{ predecessor.contract_name || 'Saved document' }}</span></label><label class="fin-field">Notes<textarea v-model="form.notes" rows="2" maxlength="2000"/></label></details>
      </fieldset>
      <p v-if="error" class="fin-error" role="alert">{{ error }}</p>
      <div class="fin-actions"><button type="button" class="btn btn-primary" :disabled="busy || loading || !supported" @click="save"><Check :size="15"/>{{ busy ? 'Saving…' : predecessor ? 'Save new agreement' : 'Save agreement' }}</button><button type="button" class="btn btn-secondary" :disabled="busy" @click="reset">Cancel</button><span class="fin-help">Saved separately from venue details.</span></div>
    </div>
    <p v-if="saved" class="fin-success" role="status">{{ saved }}</p>
    <section v-if="timeline.length" class="agreement-history" aria-label="Agreement history"><h4>Agreement timeline <span class="fin-help">{{ timeline.length }} versions</span></h4><details v-for="item in timeline" :key="item.id"><summary><span><strong>{{ dealLabel(item) }}</strong><small>Applied {{ item.effective_from }} – {{ appliedThrough(item) }}</small></span><span class="agreement-version">{{ item.id===current?.id ? 'Current' : 'Version '+item.version }}</span></summary><dl class="fin-review-list"><dt>Contract dates</dt><dd>{{ item.effective_from }} – {{ item.effective_to || 'ongoing' }}</dd><dt>Landlord</dt><dd>{{ item.landlord_name || 'No landlord payment' }}</dd><dt>Change</dt><dd>{{ item.change_type || 'Initial agreement' }}</dd><dt>Reason</dt><dd>{{ item.change_reason || '—' }}</dd><dt>Contract</dt><dd>{{ item.contract_name || 'No contract attached' }}</dd><dt>Notes</dt><dd>{{ item.notes || '—' }}</dd></dl></details></section>
  </section>
</template>
<style scoped>
.agreement-workspace { margin:20px 0; border:1px solid var(--border); border-radius:12px; padding:20px; background:var(--surface); }
.agreement-workspace h4 { margin:0 0 8px; font-size:15px; }
.agreement-overview { display:flex; gap:16px; align-items:center; justify-content:space-between; margin:18px 0; }
.agreement-current { display:flex; align-items:flex-start; gap:12px; }
.agreement-current > svg { flex-shrink:0; color:var(--primary-ink); margin-top:4px; }
.agreement-current h4 { margin:5px 0; }
.agreement-current p { font-size:13px; color:var(--text-muted); line-height:1.6; margin:0; }
.agreement-overview > button { flex-shrink:0; }
.agreement-explainer { background:var(--bg); border:1px solid var(--border); padding:14px; border-radius:8px; font-size:13px; line-height:1.7; }
.agreement-explainer summary,.agreement-documents summary { font-weight:600; cursor:pointer; }
.agreement-explainer p { margin:12px 0 0; color:var(--text-muted); }
.agreement-editor { margin-top:22px; border-top:1px solid var(--border); padding-top:22px; }
.agreement-fields { border:0; padding:0; margin:0; min-width:0; }
.agreement-group { padding:0 0 20px; margin-bottom:20px; border-bottom:1px solid var(--border); }
.agreement-group .fin-help { margin-top:10px; }
.agreement-models { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:8px; margin-bottom:16px; }
.agreement-models .fin-choice { margin:0; padding:12px; gap:8px; }
.agreement-models strong { font-size:13px; }
.agreement-calculation p { margin:7px 0; }
.agreement-documents { margin-top:20px; }
.agreement-documents .fin-field { margin-top:16px; }
.agreement-history { margin-top:24px; border-top:1px solid var(--border); padding-top:20px; }
.agreement-history details { border-bottom:1px solid var(--border); padding:14px 0; }
.agreement-history summary { display:flex; justify-content:space-between; gap:16px; cursor:pointer; font-size:13px; }
.agreement-history summary small { display:block; margin-top:5px; color:var(--text-muted); }
.agreement-version { color:var(--primary-ink); white-space:nowrap; }
@media(max-width:900px) { .agreement-overview { flex-wrap:wrap; }.agreement-models { grid-template-columns:1fr; } }
@media(max-width:600px) { .agreement-workspace { padding:14px; }.agreement-history summary { flex-wrap:wrap; } }
</style>
