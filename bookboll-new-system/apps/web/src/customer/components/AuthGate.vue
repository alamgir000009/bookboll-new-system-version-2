<script setup>
/**
 * AuthGate — the global "sign in or create your account" modal (opened by lib/authGate.js
 * ensureSignedIn). Mounted once in CustomerLayout. Two ways in:
 *   • Continue with Google (DEMO) → new accounts go through "Complete your account"
 *     (review name + phone) → phone OTP over WhatsApp/SMS → account created.
 *   • Email-first (no password): existing email signs in, new email creates instantly.
 */
import { ref, watch, computed } from 'vue'
import { authGateOpen, resolveAuthGate } from '../../lib/authGate.js'
import {
  customerSignIn, customerGoogleDemo, otpSend, otpVerify, completeCustomerSignup, selectedCountry,
} from '../../lib/customerSession.js'
import { supabase } from '../../lib/supabase.js'
import PhoneInput from './PhoneInput.vue'
import { X, Loader2, Sparkles, Check, ShieldCheck, ArrowLeft } from 'lucide-vue-next'

const step = ref('choose')   // choose | google | complete | otp
const busy = ref(false)
const err = ref('')

// ── email-first flow ────────────────────────────────────────────────────────────
const form = ref({ email: '', full_name: '', phone: '' })
const acceptOffers = ref(true)    // single marketing consent (covers email/SMS/WhatsApp) — ON by default
const acceptTerms = ref(false)    // mandatory — must be ticked to CREATE an account
const marketing = computed(() => ({ email: acceptOffers.value, sms: acceptOffers.value, whatsapp: acceptOffers.value }))
const knownCustomer = ref(null)
const checkingEmail = ref(false)
const EMAIL_RE = /^[^@\s]+@[^@\s]+\.[^@\s]+$/
let emailTimer = null

// ── google + otp flow ────────────────────────────────────────────────────────────
const googleForm = ref({ email: '', firstName: '', lastName: '' })
const completeForm = ref({ email: '', firstName: '', lastName: '', phone: '', channel: 'whatsapp' })
const otpForm = ref({ code: '' })
const otpInfo = ref({ channel: 'whatsapp', phone: '', demoCode: '', delivered: false, simulated: false })

function reset() {
  step.value = 'choose'; busy.value = false; err.value = ''
  form.value = { email: '', full_name: '', phone: '' }
  acceptOffers.value = true; acceptTerms.value = false
  knownCustomer.value = null; checkingEmail.value = false
  googleForm.value = { email: '', firstName: '', lastName: '' }
  completeForm.value = { email: '', firstName: '', lastName: '', phone: '', channel: 'whatsapp' }
  otpForm.value = { code: '' }
}
watch(authGateOpen, (open) => { if (open) reset() })

watch(() => form.value.email, (v) => {
  const email = (v || '').trim().toLowerCase()
  clearTimeout(emailTimer); knownCustomer.value = null
  if (!EMAIL_RE.test(email)) { checkingEmail.value = false; return }
  checkingEmail.value = true
  emailTimer = setTimeout(async () => {
    const { data } = await supabase.from('customers').select('id, full_name').ilike('email', email).maybeSingle()
    knownCustomer.value = data || null; checkingEmail.value = false
  }, 450)
})

const emailValid = computed(() => EMAIL_RE.test((form.value.email || '').trim().toLowerCase()))
const isNewEmail = computed(() => emailValid.value && !checkingEmail.value && !knownCustomer.value)
const phoneDigits = computed(() => (completeForm.value.phone || '').replace(/\D/g, ''))
const channelLabel = computed(() => otpInfo.value.channel === 'sms' ? 'SMS' : 'WhatsApp')

async function submitEmail() {
  err.value = ''
  if (!form.value.email.trim()) { err.value = 'Please enter your email.'; return }
  if (isNewEmail.value && !form.value.full_name.trim()) { err.value = 'Please enter your name.'; return }
  if (isNewEmail.value && !acceptTerms.value) { err.value = 'Please accept the Terms & Conditions to continue.'; return }
  busy.value = true
  const res = await customerSignIn({
    email: form.value.email, fullName: form.value.full_name, phone: form.value.phone,
    countryId: selectedCountry.value?.id, marketing: isNewEmail.value ? marketing.value : undefined,
  })
  busy.value = false
  if (res.error) { err.value = res.error; return }
  resolveAuthGate(true)
}

async function googleContinue() {
  err.value = ''
  const email = (googleForm.value.email || '').trim().toLowerCase()
  if (!EMAIL_RE.test(email)) { err.value = 'Enter the Google email you want to use.'; return }
  busy.value = true
  const res = await customerGoogleDemo({ email, firstName: googleForm.value.firstName, lastName: googleForm.value.lastName })
  busy.value = false
  if (res.error) { err.value = res.error; return }
  if (res.existing) { resolveAuthGate(true); return }            // account exists → straight in
  completeForm.value = { email: res.prefill.email, firstName: res.prefill.firstName, lastName: res.prefill.lastName, phone: '', channel: 'whatsapp' }
  step.value = 'complete'
}

async function sendCode() {
  err.value = ''
  if (!completeForm.value.firstName.trim()) { err.value = 'Please enter your first name.'; return }
  if (phoneDigits.value.length < 8) { err.value = 'Please enter a valid phone number.'; return }
  if (!acceptTerms.value) { err.value = 'Please accept the Terms & Conditions to continue.'; return }
  busy.value = true
  const res = await otpSend({ phone: completeForm.value.phone, channel: completeForm.value.channel })
  busy.value = false
  if (!res.ok) { err.value = res.error || 'Could not send the code.'; return }
  otpInfo.value = { channel: res.channel || completeForm.value.channel, phone: completeForm.value.phone, demoCode: res.demoCode || '', delivered: !!res.delivered, simulated: !!res.simulated }
  otpForm.value.code = ''
  step.value = 'otp'
}

async function verifyCode() {
  err.value = ''
  if (!/^\d{4,8}$/.test((otpForm.value.code || '').trim())) { err.value = 'Enter the code we sent you.'; return }
  busy.value = true
  const v = await otpVerify({ phone: completeForm.value.phone, code: otpForm.value.code })
  if (!v.ok) { busy.value = false; err.value = v.error || 'Incorrect code.'; return }
  const res = await completeCustomerSignup({
    email: completeForm.value.email, firstName: completeForm.value.firstName, lastName: completeForm.value.lastName,
    phone: completeForm.value.phone, countryId: selectedCountry.value?.id, provider: 'google', marketing: marketing.value,
  })
  busy.value = false
  if (res.error) { err.value = res.error; return }
  resolveAuthGate(true)
}

function cancel() { resolveAuthGate(false) }
</script>

<template>
  <div v-if="authGateOpen" class="ag-overlay" @click.self="cancel">
    <div class="ag" role="dialog" aria-modal="true">
      <button type="button" class="ag-x" @click="cancel" aria-label="Close"><X :size="18" /></button>

      <!-- ── STEP: choose (Google + email) ─────────────────────────────────── -->
      <template v-if="step === 'choose'">
        <div class="ag-ic"><Sparkles :size="22" /></div>
        <div class="ag-head">Sign in or create your account</div>
        <p class="ag-sub">You'll need a free Bookboll account to continue. <span class="ag-sub-extra">It only takes a moment.</span></p>

        <button type="button" class="ag-google" @click="err=''; step='google'">
          <svg class="ag-g" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 0 1-2.2 3.32v2.77h3.57c2.08-1.92 3.27-4.74 3.27-8.1z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.99.66-2.26 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84A11 11 0 0 0 12 23z"/><path fill="#FBBC05" d="M5.84 14.1a6.6 6.6 0 0 1 0-4.2V7.06H2.18a11 11 0 0 0 0 9.88l3.66-2.84z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84C6.71 7.31 9.14 5.38 12 5.38z"/></svg>
          Continue with Google
        </button>
        <div class="ag-or"><span>or with email</span></div>

        <label class="ag-l">Email</label>
        <div class="ag-emailwrap">
          <input v-model="form.email" type="email" class="ag-in" placeholder="you@example.com" autocomplete="email" @keyup.enter="submitEmail" />
          <Loader2 v-if="checkingEmail" :size="16" class="ag-spin ag-email-end" />
          <Check v-else-if="knownCustomer" :size="16" class="ag-email-end ag-ok" />
        </div>
        <div v-if="knownCustomer" class="ag-known">
          <Check :size="14" /> Welcome back{{ knownCustomer.full_name ? ', ' + knownCustomer.full_name.split(' ')[0] : '' }}! We'll sign you in.
        </div>
        <template v-if="isNewEmail">
          <label class="ag-l">Full name *</label>
          <input v-model="form.full_name" class="ag-in" placeholder="Your name" autocomplete="name" />
          <label class="ag-l">Phone <span class="ag-opt">optional</span></label>
          <PhoneInput v-model="form.phone" :default-iso="selectedCountry?.iso_code" placeholder="Phone number" />
          <div class="ag-mkt">
            <div class="ag-mkt-head">Keep me posted with offers &amp; news <span class="ag-opt">optional</span></div>
            <label class="ag-check"><input type="checkbox" v-model="marketing.email" /> <span>Email</span></label>
            <label class="ag-check"><input type="checkbox" v-model="marketing.sms" /> <span>SMS</span></label>
            <label class="ag-check"><input type="checkbox" v-model="marketing.whatsapp" /> <span>WhatsApp</span></label>
          </div>
          <div class="ag-consent">
            <label class="ag-crow"><input type="checkbox" v-model="acceptTerms" /> <span>I agree to the <a href="/terms" target="_blank" rel="noopener" @click.stop>Terms &amp; Conditions</a> <span class="ag-req">required</span></span></label>
          </div>
        </template>
        <div v-if="err" class="ag-err">{{ err }}</div>
        <button type="button" class="ag-go" :disabled="busy || !emailValid" @click="submitEmail">
          <Loader2 v-if="busy" :size="16" class="ag-spin" /> {{ busy ? 'Just a moment…' : (knownCustomer ? 'Sign in' : (isNewEmail ? 'Create my account' : 'Continue')) }}
        </button>
        <button type="button" class="ag-cancel" @click="cancel">Cancel</button>
      </template>

      <!-- ── STEP: google (DEMO account chooser) ───────────────────────────── -->
      <template v-else-if="step === 'google'">
        <div class="ag-ic ag-ic-g"><svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 0 1-2.2 3.32v2.77h3.57c2.08-1.92 3.27-4.74 3.27-8.1z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.99.66-2.26 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84A11 11 0 0 0 12 23z"/><path fill="#FBBC05" d="M5.84 14.1a6.6 6.6 0 0 1 0-4.2V7.06H2.18a11 11 0 0 0 0 9.88l3.66-2.84z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84C6.71 7.31 9.14 5.38 12 5.38z"/></svg></div>
        <div class="ag-head">Continue with Google</div>
        <p class="ag-sub"><span class="ag-demo-tag">Demo</span> Enter the Google account you'd sign in with. <span class="ag-sub-extra">(Real Google sign-in drops in here later — no code changes to this flow.)</span></p>
        <label class="ag-l">Google email *</label>
        <input v-model="googleForm.email" type="email" class="ag-in" placeholder="you@gmail.com" autocomplete="email" @keyup.enter="googleContinue" />
        <div class="ag-row2">
          <div><label class="ag-l">First name</label><input v-model="googleForm.firstName" class="ag-in" placeholder="First" /></div>
          <div><label class="ag-l">Last name</label><input v-model="googleForm.lastName" class="ag-in" placeholder="Last" /></div>
        </div>
        <div v-if="err" class="ag-err">{{ err }}</div>
        <button type="button" class="ag-go" :disabled="busy" @click="googleContinue">
          <Loader2 v-if="busy" :size="16" class="ag-spin" /> {{ busy ? 'Checking…' : 'Continue' }}
        </button>
        <button type="button" class="ag-cancel" @click="err=''; step='choose'"><ArrowLeft :size="14" /> Back</button>
      </template>

      <!-- ── STEP: complete your account ───────────────────────────────────── -->
      <template v-else-if="step === 'complete'">
        <div class="ag-ic"><Check :size="22" /></div>
        <div class="ag-head">Complete your account</div>
        <p class="ag-sub">Review your name and verify your phone number to finish. <span class="ag-sub-extra">We'll text you a quick code.</span></p>
        <div class="ag-row2">
          <div><label class="ag-l">First name *</label><input v-model="completeForm.firstName" class="ag-in" placeholder="First" /></div>
          <div><label class="ag-l">Last name</label><input v-model="completeForm.lastName" class="ag-in" placeholder="Last" /></div>
        </div>
        <label class="ag-l">Phone number *</label>
        <PhoneInput v-model="completeForm.phone" :default-iso="selectedCountry?.iso_code" placeholder="Phone number" />
        <label class="ag-l">Send my code by</label>
        <div class="ag-chan">
          <label class="ag-chan-opt" :class="{ on: completeForm.channel === 'whatsapp' }">
            <input type="radio" value="whatsapp" v-model="completeForm.channel" />
            <span>WhatsApp <em>preferred</em></span>
          </label>
          <label class="ag-chan-opt" :class="{ on: completeForm.channel === 'sms' }">
            <input type="radio" value="sms" v-model="completeForm.channel" />
            <span>SMS</span>
          </label>
        </div>
        <div class="ag-consent">
          <label class="ag-crow"><input type="checkbox" v-model="acceptOffers" /> <span>Send me promocodes &amp; offers <span class="ag-opt">optional</span></span></label>
          <label class="ag-crow"><input type="checkbox" v-model="acceptTerms" /> <span>I agree to the <a href="/terms" target="_blank" rel="noopener" @click.stop>Terms &amp; Conditions</a> <span class="ag-req">required</span></span></label>
        </div>
        <div v-if="err" class="ag-err">{{ err }}</div>
        <button type="button" class="ag-go" :disabled="busy" @click="sendCode">
          <Loader2 v-if="busy" :size="16" class="ag-spin" /> {{ busy ? 'Sending…' : `Send code via ${completeForm.channel === 'sms' ? 'SMS' : 'WhatsApp'}` }}
        </button>
        <button type="button" class="ag-cancel" @click="err=''; step='google'"><ArrowLeft :size="14" /> Back</button>
      </template>

      <!-- ── STEP: OTP ─────────────────────────────────────────────────────── -->
      <template v-else-if="step === 'otp'">
        <div class="ag-ic"><ShieldCheck :size="22" /></div>
        <div class="ag-head">Enter your code</div>
        <p class="ag-sub">We sent a 6-digit code to <strong>{{ completeForm.phone }}</strong> on {{ channelLabel }}.</p>
        <div v-if="otpInfo.demoCode" class="ag-demo-code">
          Testing code: <strong>{{ otpInfo.demoCode }}</strong>
          <span v-if="otpInfo.simulated"> · SMS is simulated</span>
          <span v-else-if="!otpInfo.delivered" class="ag-demo-extra"> · (WhatsApp needs an open chat or an approved template to deliver to a new number)</span>
        </div>
        <input v-model="otpForm.code" class="ag-in ag-otp" inputmode="numeric" maxlength="6" placeholder="••••••" @keyup.enter="verifyCode" />
        <div v-if="err" class="ag-err">{{ err }}</div>
        <button type="button" class="ag-go" :disabled="busy" @click="verifyCode">
          <Loader2 v-if="busy" :size="16" class="ag-spin" /> {{ busy ? 'Verifying…' : 'Verify & finish' }}
        </button>
        <div class="ag-resend">
          <button type="button" @click="sendCode" :disabled="busy">Resend code</button>
          <span>·</span>
          <button type="button" @click="err=''; step='complete'">Change number</button>
        </div>
      </template>
    </div>
  </div>
</template>

<style scoped>
.ag-overlay { position: fixed; inset: 0; z-index: 400; background: rgba(15,23,42,.6); display: flex; align-items: center; justify-content: center; padding: 18px; overflow-y: auto; }
.ag { position: relative; width: 420px; max-width: 100%; background: var(--surface, #ffffff); border-radius: 20px; padding: 26px 24px 20px; box-shadow: 0 24px 60px rgba(0,0,0,.3); margin: auto; }
.ag-x { position: absolute; top: 12px; right: 12px; width: 34px; height: 34px; border-radius: 50%; border: none; background: #f1f5f9; color: #475569; display: flex; align-items: center; justify-content: center; cursor: pointer; }
.ag-ic { width: 48px; height: 48px; border-radius: 14px; background: color-mix(in srgb, var(--brand, #0f766e) 12%, white); color: var(--brand, #0f766e); display: flex; align-items: center; justify-content: center; margin-bottom: 12px; }
.ag-ic-g { background: var(--surface, #ffffff); border: 1.5px solid var(--border, #e2e8f0); }
.ag-head { font-size: 20px; font-weight: 900; color: #0f172a; }
.ag-sub { font-size: 13.5px; color: var(--text-muted, #64748b); margin: 6px 0 16px; line-height: 1.5; }
.ag-demo-tag { display: inline-block; background: #fef3c7; color: #b45309; font-size: 10px; font-weight: 800; padding: 1px 6px; border-radius: 5px; margin-right: 4px; vertical-align: middle; }
.ag-l { display: block; font-size: 12.5px; font-weight: 700; color: #334155; margin: 11px 0 5px; }
.ag-opt { font-size: 10.5px; font-weight: 700; color: var(--text-light, #94a3b8); }
.ag-in { width: 100%; box-sizing: border-box; border: 1.5px solid var(--border, #e2e8f0); border-radius: 10px; padding: 11px 13px; font-size: 15px; outline: none; }
.ag-in:focus { border-color: var(--brand, #0f766e); }
.ag-otp { text-align: center; letter-spacing: 8px; font-size: 22px; font-weight: 800; }
.ag-row2 { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.ag-emailwrap { position: relative; }
.ag-email-end { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); }
.ag-ok { color: #16a34a; }
.ag-google { display: flex; align-items: center; justify-content: center; gap: 10px; width: 100%; background: var(--surface, #ffffff); border: 1.5px solid #dadce0; color: #3c4043; font-weight: 700; font-size: 14.5px; padding: 12px; border-radius: 12px; cursor: pointer; }
.ag-google:hover { background: #f8fafc; }
.ag-or { display: flex; align-items: center; gap: 10px; margin: 16px 0 4px; color: var(--text-light, #94a3b8); font-size: 12px; font-weight: 600; }
.ag-or::before, .ag-or::after { content: ''; flex: 1; height: 1px; background: var(--border, #e2e8f0); }
.ag-known { display: flex; align-items: flex-start; gap: 7px; background: #f0fdf4; border: 1px solid #bbf7d0; color: #15803d; border-radius: 10px; padding: 10px 12px; font-size: 13px; font-weight: 600; line-height: 1.45; margin-top: 12px; }
.ag-known svg { flex-shrink: 0; margin-top: 1px; }
.ag-chan { display: flex; gap: 10px; }
.ag-chan-opt { flex: 1; display: flex; align-items: center; gap: 8px; border: 1.5px solid var(--border, #e2e8f0); border-radius: 10px; padding: 10px 12px; font-size: 13.5px; font-weight: 700; color: #334155; cursor: pointer; }
.ag-chan-opt.on { border-color: var(--brand, #0f766e); background: color-mix(in srgb, var(--brand, #0f766e) 7%, white); }
.ag-chan-opt em { font-style: normal; font-size: 10.5px; font-weight: 700; color: #16a34a; }
.ag-mkt { margin-top: 14px; background: #f8fafc; border: 1px solid var(--border, #e2e8f0); border-radius: 12px; padding: 12px 14px; }
.ag-mkt-head { font-size: 12.5px; font-weight: 800; color: #334155; margin-bottom: 8px; }
.ag-check { display: inline-flex; align-items: center; gap: 7px; font-size: 13.5px; font-weight: 600; color: #475569; margin-inline-end: 16px; cursor: pointer; }
.ag-check input { width: 16px; height: 16px; }
.ag-consent { margin-top: 14px; display: flex; flex-direction: column; gap: 9px; background: #f8fafc; border: 1px solid var(--border, #e2e8f0); border-radius: 12px; padding: 12px 14px; }
.ag-crow { display: flex; align-items: flex-start; gap: 9px; font-size: 13px; font-weight: 600; color: #475569; cursor: pointer; line-height: 1.4; }
.ag-crow input { width: 16px; height: 16px; margin-top: 1px; flex-shrink: 0; }
.ag-crow a { color: var(--brand, #0f766e); font-weight: 700; text-decoration: underline; }
.ag-req { font-size: 10.5px; font-weight: 800; color: #dc2626; }
.ag-demo-code { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; border-radius: 10px; padding: 9px 12px; font-size: 12.5px; margin-bottom: 10px; line-height: 1.4; }
.ag-err { font-size: 13px; color: #dc2626; margin-top: 12px; font-weight: 600; }
.ag-go { display: flex; align-items: center; justify-content: center; gap: 8px; width: 100%; margin-top: 18px; background: var(--brand, #0f766e); color: #fff; font-weight: 800; font-size: 15px; padding: 14px; border: none; border-radius: 12px; cursor: pointer; }
.ag-go:disabled { opacity: .6; cursor: not-allowed; }
.ag-spin { animation: agspin 1s linear infinite; }
@keyframes agspin { to { transform: rotate(360deg); } }
.ag-cancel { display: flex; align-items: center; justify-content: center; gap: 5px; width: 100%; margin-top: 8px; background: none; border: none; color: var(--text-muted, #64748b); font-weight: 700; font-size: 14px; padding: 10px; cursor: pointer; }
.ag-resend { display: flex; align-items: center; justify-content: center; gap: 10px; margin-top: 12px; font-size: 13px; color: var(--text-light, #94a3b8); }
.ag-resend button { background: none; border: none; color: var(--brand, #0f766e); font-weight: 700; font-size: 13px; cursor: pointer; }
.ag-resend button:disabled { opacity: .5; cursor: not-allowed; }

/* ── Mobile polish (pass 2) — phone-only, desktop untouched ─────────────── */
@media (max-width: 760px) {
  /* lift tiny tokens to the 11px floor */
  .ag-demo-tag, .ag-opt, .ag-chan-opt em, .ag-req { font-size: 11px; }
  /* helper text sits in the secondary band */
  .ag-sub { font-size: 13px; }
  /* trim meta copy that repeats what the UI already shows */
  .ag-sub-extra, .ag-demo-extra { display: none; }
  /* tighter 12-16px vertical rhythm so the complete step fits one viewport */
  .ag { padding: 20px 18px 16px; }
  .ag-go { margin-top: 14px; }
  .ag-or { margin: 12px 0 2px; }
  .ag-mkt, .ag-consent { margin-top: 12px; }
  /* 16px inputs so iOS doesn't auto-zoom on focus */
  .ag-in { font-size: 16px; }
  /* narrow phones (~288px inner): First/Last name fields stack */
  .ag-row2 { grid-template-columns: 1fr; }
  /* WhatsApp/SMS channel options stack instead of cramped side-by-side */
  .ag-chan { flex-direction: column; gap: 8px; }
  /* ≥44px tap targets for back/cancel + resend actions */
  .ag-cancel { padding: 13px 10px; }
  .ag-resend button { padding: 10px 4px; }
}
</style>
