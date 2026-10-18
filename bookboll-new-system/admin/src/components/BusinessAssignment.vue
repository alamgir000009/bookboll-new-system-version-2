<script setup>
import { financeRequest } from '../lib/financeApi.js'
import { computed, ref, watch } from 'vue'
import { X, ArrowRight } from 'lucide-vue-next'
import { previewReady, newRequestKey } from '../lib/finance.js'
import { roleLabel, PROVIDER_ROLES } from '../lib/permissions.js'
import './finance.css'
const props=defineProps({ business:{type:Object,required:true} })
const emit=defineEmits(['close','assigned'])
const options=ref(null), notice=ref(''), error=ref(''), busy=ref(false), query=ref(''), preview=ref(null), agreed=ref(false)
const form=ref({user_id:'',role:'provider_admin',scope:'all',location_ids:[]})
let generation=0, key=newRequestKey()
const people=computed(()=>(options.value?.candidates || []).filter(person=>`${person.name} ${person.email}`.toLowerCase().includes(query.value.toLowerCase())))
const roles=computed(()=>(options.value?.roles || []).filter(role=>PROVIDER_ROLES.includes(role)))
const scopeRequired=computed(()=>form.value.role!=='provider_admin' && form.value.scope==='selected')
watch(form,()=>{generation++;preview.value=null;agreed.value=false;key=newRequestKey()},{deep:true,flush:'sync'})
watch(()=>form.value.role,()=>{form.value.location_ids=[];form.value.scope='all'})
async function load(){const result=await financeRequest(`/admin/businesses/${props.business.business_id}/assignment-options`);if(!result.ok)notice.value=result.message.replace('finance backend','business management backend');else{options.value=result.data;form.value.role=roles.value[0] || 'staff'}}
async function review(){
  error.value=''
  if(!form.value.user_id || !roles.value.includes(form.value.role)){error.value='Choose an existing account and business role.';return}
  if(scopeRequired.value && !form.value.location_ids.length){error.value='Select at least one venue, or choose all business venues.';return}
  if(busy.value || !options.value?.can_assign)return
  busy.value=true;const current=++generation
  const result=await financeRequest(`/admin/businesses/${props.business.business_id}/assignment-preview`,{method:'POST',body:{user_id:form.value.user_id,role:form.value.role,scope:form.value.role==='provider_admin'?'all':form.value.scope,location_ids:scopeRequired.value?form.value.location_ids:[]}})
  busy.value=false;if(current!==generation)return
  if(!result.ok || !result.data?.preview_token || String(result.data.user_id)!==String(form.value.user_id) || String(result.data.business_id)!==String(props.business.business_id) || result.data.role!==form.value.role || !Array.isArray(result.data.blockers)){error.value=result.message || 'Assignment review was not confirmed.';return}preview.value=result.data
}
async function assign(){
  if(busy.value || !agreed.value || !previewReady(preview.value)){error.value='Refresh the review before confirming.';return}
  busy.value=true;error.value=''
  const result=await financeRequest(`/admin/businesses/${props.business.business_id}/assignments`,{method:'POST',body:{preview_token:preview.value.preview_token,idempotency_key:key}})
  busy.value=false
  if(!result.ok || !result.data?.membership?.id){error.value=result.message || 'The assignment was not confirmed.';return}emit('assigned')
}
load()
</script>
<template>
  <div class="modal-backdrop" @click.self="!busy && emit('close')"><div class="modal fin-dialog" v-dialog="() => !busy && emit('close')" role="dialog" aria-modal="true" aria-labelledby="assign-title">
    <div class="modal-header"><div><div class="fin-eyebrow">{{ business.business_name }}</div><h2 id="assign-title" class="modal-title">Assign an existing platform account</h2></div><button class="btn-icon" :disabled="busy" aria-label="Close assignment" @click="emit('close')"><X :size="18"/></button></div>
    <div class="modal-body"><p class="fin-help" style="margin-bottom:18px">Move an existing account into this business. Its login stays the same; platform access is removed.</p><p v-if="notice" class="fin-notice">{{ notice }}</p>
      <fieldset v-show="!preview" :disabled="busy" style="border:0;padding:0;margin:0"><div class="fin-form-grid">
        <label class="fin-field fin-wide">Find a platform account<input v-model="query" type="search" placeholder="Search name or email"/></label>
        <label class="fin-field fin-wide">Account *<select v-model="form.user_id"><option value="">Select account</option><option v-for="person in people" :key="person.id" :value="person.id">{{ person.name }} · {{ person.email }}</option></select></label>
        <label class="fin-field">Business role *<select v-model="form.role"><option v-for="role in roles" :key="role" :value="role">{{ roleLabel(role) }}</option></select></label>
        <label v-if="form.role!=='provider_admin'" class="fin-field">Venue access<select v-model="form.scope"><option value="all">All venues in this business</option><option value="selected">Selected business venues</option></select></label>
      </div><div v-if="scopeRequired" style="margin-top:16px"><label v-for="venue in options?.venues || []" :key="venue.id" class="fin-checkbox"><input v-model="form.location_ids" type="checkbox" :value="venue.id"/>{{ venue.name }}</label><p v-if="!options?.venues?.length" class="fin-help">This business has no venues yet. Choose all business venues or add a venue first.</p></div></fieldset>
      <section v-if="preview" class="fin-section"><div class="fin-eyebrow">Review account move</div><dl class="fin-review-list"><dt>Account</dt><dd>{{ preview.user_name }}<br/>{{ preview.user_email }}</dd><dt>Business</dt><dd>{{ business.business_name }}</dd><dt>New role</dt><dd>{{ roleLabel(preview.role) }}</dd><dt>Access</dt><dd>{{ preview.scope_label }}</dd><dt>Platform access</dt><dd>Removed · existing sessions refreshed</dd></dl><p v-for="warning in preview.warnings || []" :key="warning" class="fin-notice">{{ warning }}</p><p v-for="blocker in preview.blockers || []" :key="blocker" class="fin-error">{{ blocker }}</p><label class="fin-checkbox"><input v-model="agreed" type="checkbox" :disabled="busy"/><span>I confirm this account should leave the platform team and receive the reviewed business role.</span></label></section>
      <p v-if="error" class="fin-error" role="alert">{{ error }}</p>
    </div><div class="modal-footer"><button class="btn btn-secondary" :disabled="busy" @click="emit('close')">Cancel</button><button v-if="preview" class="btn btn-secondary" :disabled="busy" @click="preview=null;agreed=false">Back to details</button><button class="btn btn-secondary" :disabled="busy || !options?.can_assign" @click="review">{{ busy ? 'Working…' : preview ? 'Refresh review' : 'Review assignment' }}</button><button v-if="preview" class="btn btn-primary" :disabled="busy || !agreed || !previewReady(preview)" @click="assign">Move account<ArrowRight :size="14"/></button></div>
  </div></div>
</template>
