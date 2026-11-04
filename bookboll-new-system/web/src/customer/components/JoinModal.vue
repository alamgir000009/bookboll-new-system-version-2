<script setup>
/**
 * JoinModal — the whole "request to join" flow in one tidy dialog: the contact form
 * PLUS the condensed marketplace waiver, instead of a form sitting inline on the page.
 *
 *  props: open, kind ('game'|'tournament'), defaultIso, busy, error, initial {name,whatsapp,phone}
 *  emits: 'submit' ({ name, phone, whatsapp, message }), 'close'
 *
 * Validation (name + at least one number) happens here; the parent runs the request and
 * passes back a server `error` if any, and flips `open` to false on success.
 */
import { ref, computed, watch } from 'vue'
import PhoneInput from './PhoneInput.vue'
import CustomFormFields from './CustomFormFields.vue'
import { asFields, blankAnswers, validateAnswers } from '../../lib/customForms.js'
import { Store, Wallet, ShieldAlert, X } from 'lucide-vue-next'

const props = defineProps({
  open: { type: Boolean, default: false },
  kind: { type: String, default: 'game' },
  defaultIso: { type: String, default: '' },
  busy: { type: Boolean, default: false },
  error: { type: String, default: '' },
  initial: { type: Object, default: () => ({}) },
  customForm: { type: Array, default: () => [] },   // the event's optional sign-up questions
  instant: { type: Boolean, default: false },       // instant-join event: no host approval step
})
const emit = defineEmits(['submit', 'close'])

const isTourn = computed(() => props.kind === 'tournament')
const fields = computed(() => asFields(props.customForm))
const form = ref({ name: '', whatsapp: '', phone: '', message: '' })
const answers = ref({})
const ansErrors = ref({})
const localErr = ref('')

// Seed the form from the parent's prefill each time the modal opens.
watch(() => props.open, (o) => {
  if (o) {
    form.value = { name: props.initial.name || '', whatsapp: props.initial.whatsapp || '', phone: props.initial.phone || '', message: '' }
    answers.value = blankAnswers(fields.value)
    ansErrors.value = {}
    localErr.value = ''
  }
})

function submit() {
  localErr.value = ''
  if (!form.value.name.trim()) { localErr.value = isTourn.value ? 'Enter your team name.' : 'Enter your name.'; return }
  if (!form.value.whatsapp.trim() && !form.value.phone.trim()) { localErr.value = 'Add a WhatsApp or phone number so the host can reach you.'; return }
  if (fields.value.length) {
    const v = validateAnswers(fields.value, answers.value)
    ansErrors.value = v.errors
    if (!v.ok) { localErr.value = 'Please answer the required questions.'; return }
  }
  emit('submit', { ...form.value, form_answers: answers.value })
}
</script>

<template>
  <div v-if="open" class="jm-overlay" @click.self="emit('close')">
    <div class="jm" role="dialog" aria-modal="true">
      <button type="button" class="jm-x" @click="emit('close')" aria-label="Close"><X :size="18" /></button>
      <div class="jm-head">{{ isTourn ? 'Enter your team' : instant ? 'Join this event' : 'Request to join' }}</div>
      <p class="jm-sub">{{ instant ? 'No approval needed — your spot is confirmed the moment you join.' : 'The host confirms your request and reaches out.' }}</p>

      <label class="jm-l">{{ isTourn ? 'Team name' : 'Your name' }} *</label>
      <input v-model="form.name" class="jm-in" :placeholder="isTourn ? 'e.g. Marina Strikers' : 'Your name'" />

      <label class="jm-l">WhatsApp *</label>
      <PhoneInput v-model="form.whatsapp" :default-iso="defaultIso" placeholder="WhatsApp number" />

      <label class="jm-l">Phone <span class="jm-opt">optional</span></label>
      <PhoneInput v-model="form.phone" :default-iso="defaultIso" placeholder="Phone number" />

      <label class="jm-l">Message <span class="jm-opt">optional</span></label>
      <textarea v-model="form.message" class="jm-in jm-ta" rows="2" :placeholder="isTourn ? 'Anything for the host?' : 'Say hi to the host'"></textarea>

      <!-- host's custom sign-up questions -->
      <div v-if="fields.length" class="jm-custom">
        <div class="jm-custom-h">A few questions from the host</div>
        <CustomFormFields :fields="fields" v-model="answers" :errors="ansErrors" />
      </div>

      <!-- condensed marketplace waiver -->
      <div class="jm-waiver">
        <div class="jm-w-row"><span class="jm-w-ico"><Store :size="14" /></span> Bookboll just connects you — the host runs it.</div>
        <div class="jm-w-row"><span class="jm-w-ico"><Wallet :size="14" /></span> Any fee or prize is paid directly with the host.</div>
        <div class="jm-w-row"><span class="jm-w-ico"><ShieldAlert :size="14" /></span> You join at your own risk, not Bookboll's.</div>
      </div>

      <div v-if="localErr || error" class="jm-err">{{ localErr || error }}</div>

      <div class="jm-actions">
        <button type="button" class="jm-cancel" @click="emit('close')">Cancel</button>
        <button type="button" class="jm-send" :disabled="busy" @click="submit">{{ busy ? (instant ? 'Joining…' : 'Sending…') : (instant ? 'Agree & join' : 'Agree & send request') }}</button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.jm-overlay { position: fixed; inset: 0; z-index: 300; background: rgba(15,23,42,.55); display: flex; align-items: center; justify-content: center; padding: 18px; overflow-y: auto; }
.jm { position: relative; width: 440px; max-width: 100%; background: var(--surface, #ffffff); border-radius: 20px; padding: 24px 22px 20px; box-shadow: 0 24px 60px rgba(0,0,0,.3); margin: auto; }
.jm-x { position: absolute; top: 12px; right: 12px; width: 34px; height: 34px; border-radius: 50%; border: none; background: var(--bg, #f1f5f9); color: var(--text-secondary, #475569); display: flex; align-items: center; justify-content: center; cursor: pointer; }
.jm-x:hover { background: var(--border, #e2e8f0); }
.jm-head { font-size: 20px; font-weight: 900; color: var(--text, #0f172a); }
.jm-sub { font-size: 13px; color: var(--text-muted, #64748b); margin: 5px 0 14px; line-height: 1.5; }
.jm-l { display: block; font-size: 12.5px; font-weight: 700; color: var(--text-secondary, #334155); margin: 11px 0 5px; }
.jm-opt { font-size: 10.5px; font-weight: 700; color: var(--text-light, #94a3b8); }
.jm-in { width: 100%; box-sizing: border-box; border: 1.5px solid var(--border, #e2e8f0); border-radius: 10px; padding: 11px 13px; font-size: 15px; outline: none; }
.jm-in:focus { border-color: var(--brand-accent); }
.jm-ta { resize: vertical; font-family: inherit; }
.jm-custom { margin-top: 16px; padding-top: 14px; border-top: 1px solid var(--border, #eef2f6); }
.jm-custom-h { font-size: 12.5px; font-weight: 800; color: var(--text, #0f172a); margin-bottom: 10px; }
.jm-waiver { margin-top: 14px; background: var(--canvas, #f8fafc); border: 1px solid var(--border, #e2e8f0); border-radius: 12px; padding: 12px 14px; display: flex; flex-direction: column; gap: 8px; }
.jm-w-row { display: flex; align-items: center; gap: 8px; font-size: 12.5px; color: var(--text-secondary, #475569); }
.jm-w-ico { flex-shrink: 0; color: var(--brand-ink, var(--brand)); display: inline-flex; }
.jm-err { font-size: 13px; color: var(--danger-ink, #dc2626); margin-top: 12px; }
.jm-actions { display: flex; gap: 10px; margin-top: 18px; }
.jm-cancel { flex-shrink: 0; background: var(--surface, #ffffff); border: 1.5px solid var(--border, #e2e8f0); color: var(--text-secondary, #334155); font-weight: 700; font-size: 14.5px; padding: 13px 18px; border-radius: 12px; cursor: pointer; }
.jm-send { flex: 1; background: var(--brand); color: #fff; font-weight: 800; font-size: 14.5px; padding: 13px 18px; border: none; border-radius: 12px; cursor: pointer; }
.jm-send:disabled { opacity: .6; }

/* Phone: modal becomes a bottom sheet pinned to the thumb, tighter chrome so the
   "Agree & send request" CTA sits within reach with minimal scroll. */
@media (max-width: 760px) {
  .jm-overlay { align-items: flex-end; padding: 0; }
  .jm {
    width: 100%; max-width: 100%; margin: 0;
    border-radius: 20px 20px 0 0;
    max-height: 92vh; overflow-y: auto;
    padding: 18px 16px calc(16px + env(safe-area-inset-bottom));
  }
  .jm-x { width: 40px; height: 40px; top: 10px; right: 10px; }
  .jm-head { font-size: 20px; line-height: 1.2; }
  .jm-sub { font-size: 12.5px; margin: 4px 0 10px; }
  .jm-l { margin-top: 8px; }
  .jm-opt { font-size: 11px; font-weight: 600; }
  .jm-in { font-size: 16px; }
  /* One visual level + one accent: waiver flattens to its tint alone, icons go neutral
     so the brand CTA below is the only brand-colored element in the lower half. */
  .jm-waiver { margin-top: 10px; padding: 10px 12px; gap: 6px; border: none; }
  .jm-w-row { font-size: 12px; }
  .jm-w-ico { color: var(--text-light, #94a3b8); }
  /* One obvious primary action: full-width CTA on top, Cancel de-emphasized below. */
  .jm-actions { flex-direction: column-reverse; gap: 10px; }
  .jm-send, .jm-cancel { width: 100%; }
  .jm-cancel { background: transparent; border: none; color: var(--text-muted, #64748b); }
}
</style>
