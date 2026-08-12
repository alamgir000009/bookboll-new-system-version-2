<script setup>
import { ref, onMounted, computed } from 'vue'
import { supabase } from '../lib/supabase.js'
import { askConfirm } from '../lib/confirm.js'
import { pushToast } from '../lib/toast.js'
import { Landmark, Plus, Lock, CircleCheck as CheckCircle, X, Trash2, Pencil as Edit2, Building2 } from 'lucide-vue-next'
import { useCurrentUser } from '../lib/currentUser.js'

const { isSuperAdmin, currentOwnerUserId, allBusinessOwners } = useCurrentUser()

const accounts = ref([])
const showModal = ref(false)
const editing = ref(null)
const form = ref(defaultForm())

function defaultForm() {
  return { owner_user_id: '', account_holder: '', bank_name: '', iban: '', swift: '', currency: 'AED', is_primary: false, verified: false }
}

async function load() {
  let aQ = supabase.from('payment_accounts').select('*').order('created_at', { ascending: false })
  if (!isSuperAdmin.value && currentOwnerUserId.value) aQ = aQ.eq('owner_user_id', currentOwnerUserId.value)
  const { data } = await aQ
  accounts.value = data || []
}
onMounted(load)

const providerName = (id) => {
  const p = allBusinessOwners.value.find(x => x.id === id)
  return p?.username ? `@${p.username}` : p?.full_name || '—'
}

function openNew() {
  editing.value = null
  form.value = defaultForm()
  if (!isSuperAdmin.value && currentOwnerUserId.value) form.value.owner_user_id = currentOwnerUserId.value
  showModal.value = true
}
function openEdit(a) { editing.value = a; form.value = { ...a }; showModal.value = true }

async function save() {
  if (editing.value) await supabase.from('payment_accounts').update(form.value).eq('id', editing.value.id)
  else await supabase.from('payment_accounts').insert(form.value)
  showModal.value = false
  load()
}

async function remove(id) {
  if (!(await askConfirm({
    title: 'Delete payment account?',
    message: 'This payment account will be permanently removed.',
    confirmText: 'Delete',
  }))) return
  const { error } = await supabase.from('payment_accounts').delete().eq('id', id)
  if (error) { pushToast('Could not delete account.', 'error'); return }
  pushToast('Payment account deleted', 'success')
  load()
}
</script>

<template>
  <div class="page">
    <div class="page-header">
      <div>
        <div class="page-title">Payment Accounts</div>
        <div class="page-subtitle">Bank accounts that receive provider payouts</div>
      </div>
      <button class="btn btn-primary" @click="openNew"><Plus :size="16" />Add Account</button>
    </div>

    <div class="info-banner">
      <Lock :size="16" />
      <div><strong>Platform commission: 20%</strong> on Bookboll-originated bookings. Manual bookings are commission-free. Commission is set by platform administrators.</div>
    </div>

    <div class="grid grid-2" style="margin-top: 20px;">
      <div v-for="a in accounts" :key="a.id" class="card acct-card">
        <div class="acct-header">
          <div class="acct-icon"><Landmark :size="22" /></div>
          <div style="flex:1;">
            <div class="acct-name">{{ a.bank_name || 'Bank Account' }}</div>
            <div class="acct-sub">{{ providerName(a.owner_user_id) }}</div>
          </div>
          <span v-if="a.verified" class="badge badge-success"><CheckCircle :size="11" />verified</span>
          <span v-else class="badge badge-warning">unverified</span>
        </div>
        <div class="acct-body">
          <div class="acct-row"><span>Account Holder</span><strong>{{ a.account_holder }}</strong></div>
          <div class="acct-row"><span>IBAN</span><strong class="mono">{{ a.iban }}</strong></div>
          <div class="acct-row"><span>SWIFT</span><strong class="mono">{{ a.swift }}</strong></div>
          <div class="acct-row"><span>Currency</span><strong>{{ a.currency }}</strong></div>
          <div class="acct-row"><span>Primary</span><strong>{{ a.is_primary ? 'Yes' : 'No' }}</strong></div>
        </div>
        <div class="acct-footer">
          <button class="btn btn-secondary btn-sm" @click="openEdit(a)"><Edit2 :size="14" />Edit</button>
          <button class="btn btn-sm btn-danger" @click="remove(a.id)"><Trash2 :size="14" />Delete</button>
        </div>
      </div>
      <div v-if="!accounts.length" class="empty card" style="grid-column: 1 / -1;">No payment accounts configured</div>
    </div>

    <div v-if="showModal" class="modal-backdrop" @click.self="showModal=false">
      <div class="modal">
        <div class="modal-header">
          <div class="modal-title">{{ editing ? 'Edit Account' : 'New Payment Account' }}</div>
          <button class="btn-icon" @click="showModal=false"><X :size="18" /></button>
        </div>
        <div class="modal-body">
          <div v-if="isSuperAdmin" class="form-group"><label class="form-label">Company</label>
            <select v-model="form.owner_user_id">
              <option value="">@bookboll</option>
              <option v-for="p in allBusinessOwners" :key="p.id" :value="p.id">@{{ p.username || p.full_name }}</option>
            </select>
          </div>
          <div v-else-if="form.owner_user_id" class="assigned-chip"><Building2 :size="13" />Assigned to: <strong>{{ providerName(form.owner_user_id) }}</strong></div>
          <div class="form-group"><label class="form-label">Account Holder</label><input v-model="form.account_holder" /></div>
          <div class="form-group"><label class="form-label">Bank Name</label><input v-model="form.bank_name" /></div>
          <div class="form-group"><label class="form-label">IBAN</label><input v-model="form.iban" /></div>
          <div class="grid grid-2">
            <div class="form-group"><label class="form-label">SWIFT</label><input v-model="form.swift" /></div>
            <div class="form-group"><label class="form-label">Currency</label><input v-model="form.currency" /></div>
          </div>
          <label class="chk"><input type="checkbox" v-model="form.is_primary" /> Primary account</label>
          <label class="chk"><input type="checkbox" v-model="form.verified" /> Verified</label>
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" @click="showModal=false">Cancel</button>
          <button class="btn btn-primary" @click="save">Save</button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.info-banner { display: flex; align-items: flex-start; gap: 12px; padding: 14px 18px; background: #ccfbf1; border: 1px solid #5eead4; border-radius: 10px; color: #0f766e; font-size: 14px; }
.acct-card { padding: 20px; }
.acct-header { display: flex; align-items: center; gap: 12px; margin-bottom: 16px; }
.acct-icon { width: 44px; height: 44px; border-radius: 10px; background: linear-gradient(135deg, #ccfbf1, #99f6e4); color: var(--primary); display: flex; align-items: center; justify-content: center; }
.acct-name { font-weight: 600; font-size: 15px; }
.acct-sub { font-size: 13px; color: var(--text-muted); margin-top: 2px; }
.acct-body { border-top: 1px solid var(--border); padding-top: 14px; }
.acct-row { display: flex; justify-content: space-between; align-items: center; padding: 7px 0; font-size: 13px; gap: 12px; }
.acct-row span { color: var(--text-muted); }
.acct-row strong { text-align: right; word-break: break-all; }
.mono { font-family: 'SF Mono', Monaco, monospace; font-size: 12px; }
.acct-footer { margin-top: 14px; display: flex; gap: 8px; justify-content: flex-end; }
.chk { display: flex; align-items: center; gap: 8px; padding: 6px 0; font-size: 14px; }
.chk input { width: auto; }
.assigned-chip { display: inline-flex; align-items: center; gap: 6px; padding: 8px 12px; background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; border-radius: 8px; font-size: 13px; margin-bottom: 14px; }
</style>
