<script setup>
// Multi-country payout bank-details fields. Extracted from Wallet.vue so the same
// form can be used both in the Payout Details card and in the wallet-setup flow.
// The parent owns the data (`form`), validation errors (`errors`), the derived
// field layout (`fieldMode`), and the save/cancel buttons. This component only
// renders inputs bound directly to the shared `form` object.
defineProps({
  form: { type: Object, required: true },
  errors: { type: Object, default: () => ({}) },
  fieldMode: { type: String, default: 'default' },        // 'iban' | 'us' | 'au' | 'default'
  countriesGrouped: { type: Array, default: () => [] },
})
</script>

<template>
  <div class="payout-bank-fields">
    <!-- Row 1: Country + Bank Name -->
    <div class="grid grid-2" style="margin-bottom:14px">
      <div class="form-group" :class="{ 'field-error': errors.country }">
        <label class="form-label">Receiving Country <span class="req">*</span></label>
        <select v-model="form.payout_bank_country" class="payout-select">
          <option value="" disabled>Select a country…</option>
          <optgroup v-for="g in countriesGrouped" :key="g.group" :label="g.group">
            <option v-for="c in g.list" :key="c.code" :value="c.code">
              {{ c.name }} ({{ c.code }})
            </option>
          </optgroup>
        </select>
        <div class="field-err-msg" v-if="errors.country">{{ errors.country }}</div>
      </div>
      <div class="form-group" :class="{ 'field-error': errors.bank_name }">
        <label class="form-label">Bank Name <span class="req">*</span></label>
        <input v-model="form.payout_bank_name" placeholder="e.g. Emirates NBD" />
        <div class="field-err-msg" v-if="errors.bank_name">{{ errors.bank_name }}</div>
      </div>
    </div>

    <!-- Account Holder -->
    <div class="form-group" :class="{ 'field-error': errors.holder }" style="margin-bottom:14px">
      <label class="form-label">Account Holder Name <span class="req">*</span></label>
      <input v-model="form.payout_account_holder" placeholder="Full legal name exactly as it appears on your bank account" />
      <div class="field-err-msg" v-if="errors.holder">{{ errors.holder }}</div>
    </div>

    <!-- Conditional fields: IBAN -->
    <template v-if="fieldMode === 'iban'">
      <div class="form-group" :class="{ 'field-error': errors.iban }" style="margin-bottom:14px">
        <label class="form-label">IBAN <span class="req">*</span></label>
        <input v-model="form.payout_iban" placeholder="e.g. AE07 0331 2345 6789 0123 456" class="mono-input" @input="form.payout_iban = form.payout_iban.toUpperCase()" />
        <div class="form-hint" v-if="!errors.iban">Starts with your 2-letter country code followed by 2 check digits, then your account number.</div>
        <div class="field-err-msg" v-else>{{ errors.iban }}</div>
      </div>
    </template>

    <!-- Conditional fields: US -->
    <template v-else-if="fieldMode === 'us'">
      <div class="grid grid-2" style="margin-bottom:14px">
        <div class="form-group" :class="{ 'field-error': errors.routing }">
          <label class="form-label">ABA Routing Number <span class="req">*</span></label>
          <input v-model="form.payout_routing_number" placeholder="9-digit number" class="mono-input" maxlength="9" />
          <div class="form-hint" v-if="!errors.routing">Found at the bottom-left of a US check.</div>
          <div class="field-err-msg" v-else>{{ errors.routing }}</div>
        </div>
        <div class="form-group" :class="{ 'field-error': errors.account }">
          <label class="form-label">Account Number <span class="req">*</span></label>
          <input v-model="form.payout_account_number" placeholder="Bank account number" class="mono-input" />
          <div class="field-err-msg" v-if="errors.account">{{ errors.account }}</div>
        </div>
      </div>
      <div class="form-group" style="margin-bottom:14px">
        <label class="form-label">Account Type <span class="req">*</span></label>
        <select v-model="form.payout_account_type" class="payout-select">
          <option value="checking">Checking</option>
          <option value="savings">Savings</option>
          <option value="business_checking">Business Checking</option>
        </select>
      </div>
    </template>

    <!-- Conditional fields: Australia / NZ -->
    <template v-else-if="fieldMode === 'au'">
      <div class="grid grid-2" style="margin-bottom:14px">
        <div class="form-group" :class="{ 'field-error': errors.routing }">
          <label class="form-label">BSB Code <span class="req">*</span></label>
          <input v-model="form.payout_routing_number" placeholder="e.g. 062-000" class="mono-input" />
          <div class="form-hint" v-if="!errors.routing">6-digit Bank State Branch code.</div>
          <div class="field-err-msg" v-else>{{ errors.routing }}</div>
        </div>
        <div class="form-group" :class="{ 'field-error': errors.account }">
          <label class="form-label">Account Number <span class="req">*</span></label>
          <input v-model="form.payout_account_number" placeholder="Bank account number" class="mono-input" />
          <div class="field-err-msg" v-if="errors.account">{{ errors.account }}</div>
        </div>
      </div>
    </template>

    <!-- Conditional fields: default -->
    <template v-else>
      <div class="form-group" :class="{ 'field-error': errors.account }" style="margin-bottom:14px">
        <label class="form-label">Account Number <span class="req">*</span></label>
        <input v-model="form.payout_account_number" placeholder="Your bank account number" class="mono-input" />
        <div class="field-err-msg" v-if="errors.account">{{ errors.account }}</div>
      </div>
    </template>

    <!-- SWIFT (shown for IBAN and default, not US/AU) -->
    <div v-if="fieldMode === 'iban' || fieldMode === 'default'" class="form-group" style="margin-bottom:14px">
      <label class="form-label">SWIFT / BIC <span class="form-opt">optional — needed for international transfers</span></label>
      <input v-model="form.payout_swift" placeholder="8 or 11 characters, e.g. ENBAAEADXXX" class="mono-input" @input="form.payout_swift = form.payout_swift.toUpperCase()" />
      <div class="form-hint">Leave blank for domestic transfers only.</div>
    </div>
  </div>
</template>

<style scoped>
.payout-bank-fields { display:flex; flex-direction:column; gap:0; }
.req { color:#ef4444; }
.form-opt { font-size:11px; font-weight:400; color:var(--text-muted); margin-left:4px; }
.form-hint { font-size:12px; color:var(--text-muted); margin-top:4px; }
.payout-select {
  width:100%; height:38px; padding:0 10px; border:1px solid var(--border);
  border-radius:8px; font-size:13px; color:var(--text); background:var(--surface, #ffffff); cursor:pointer;
}
.payout-select:focus { outline:none; border-color:var(--primary); box-shadow:0 0 0 3px rgba(59,130,246,.1); }
.mono-input { font-family:'SF Mono',Monaco,monospace; font-size:13px; letter-spacing:.04em; }
.field-error input, .field-error select { border-color:#ef4444 !important; }
.field-err-msg { font-size:12px; color:#dc2626; margin-top:4px; display:flex; align-items:center; gap:4px; }
.field-err-msg::before { content:'⚠'; font-size:11px; }

/* Phone: keep inputs/selects at 16px (avoid iOS auto-zoom) + 44px touch targets */
@media (max-width: 768px) {
  .mono-input { font-size:16px; letter-spacing:.02em; }
  .payout-select { font-size:16px; height:44px; }
  .form-opt { display:block; margin-left:0; margin-top:2px; font-size:12px; }
}
</style>
