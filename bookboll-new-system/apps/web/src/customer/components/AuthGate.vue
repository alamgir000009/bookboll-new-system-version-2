<script setup>
/**
 * AuthGate — the global "sign in or create your account" modal (opened by lib/authGate.js
 * ensureSignedIn). Mounted once in CustomerLayout.
 *   • Continue with Google → GIS id_token → POST /customer/google-login
 *   • Email + password login → POST /customer/login
 *   • Sign up → POST /register
 *   • Forgot / reset password via email OTP
 */
import { ref, watch, computed } from 'vue'
import { GoogleSignInButton } from 'vue3-google-signin'
import { authGateOpen, resolveAuthGate } from '../../lib/authGate.js'
import {
  customerLogin, customerRegister, customerGoogleLogin,
  customerForgotPassword, customerResetPassword, selectedCountry,
} from '../../lib/customerSession.js'
import PhoneInput from './PhoneInput.vue'
import { X, Loader2, Sparkles, ArrowLeft, ShieldCheck } from 'lucide-vue-next'

const step = ref('choose')   // choose | forgot | reset
const mode = ref('login')    // login | signup
const busy = ref(false)
const err = ref('')
const info = ref('')
const adminReset = ref(false)

const loginForm = ref({ email: '', password: '' })
const signupForm = ref({
  name: '', email: '', phone: '', password: '', password_confirmation: '',
})
const forgotForm = ref({ email: '', otp: '', password: '', password_confirmation: '' })
const acceptOffers = ref(true)
const acceptTerms = ref(false)
const marketing = computed(() => ({ email: acceptOffers.value, sms: acceptOffers.value, whatsapp: acceptOffers.value }))

const EMAIL_RE = /^[^@\s]+@[^@\s]+\.[^@\s]+$/
const googleReady = computed(() => !!import.meta.env.VITE_GOOGLE_CLIENT_ID)
const emailValid = computed(() => EMAIL_RE.test((loginForm.value.email || '').trim().toLowerCase()))
const signupEmailValid = computed(() => EMAIL_RE.test((signupForm.value.email || '').trim().toLowerCase()))
const phoneDigits = computed(() => (signupForm.value.phone || '').replace(/\D/g, ''))

function reset() {
  step.value = 'choose'; mode.value = 'login'; busy.value = false; err.value = ''; info.value = ''
  adminReset.value = false
  loginForm.value = { email: '', password: '' }
  signupForm.value = { name: '', email: '', phone: '', password: '', password_confirmation: '' }
  forgotForm.value = { email: '', otp: '', password: '', password_confirmation: '' }
  acceptOffers.value = true; acceptTerms.value = false
}
watch(authGateOpen, (open) => { if (open) reset() })

function finishOk() { resolveAuthGate(true) }
function cancel() { resolveAuthGate(false) }

async function submitLogin() {
  err.value = ''
  busy.value = true
  const res = await customerLogin(loginForm.value)
  busy.value = false
  if (res.resetPassword) {
    adminReset.value = true
    forgotForm.value.email = loginForm.value.email
    step.value = 'reset'
    info.value = 'Your password was reset. Enter the OTP from your email and choose a new password.'
    return
  }
  if (res.error) { err.value = res.error; return }
  finishOk()
}

async function submitSignup() {
  err.value = ''
  if (!signupForm.value.name.trim()) { err.value = 'Please enter your name.'; return }
  if (!signupEmailValid.value) { err.value = 'Please enter a valid email.'; return }
  if (phoneDigits.value.length < 6) { err.value = 'Please enter a valid phone number.'; return }
  if ((signupForm.value.password || '').length < 8) { err.value = 'Password must be at least 8 characters.'; return }
  if (signupForm.value.password !== signupForm.value.password_confirmation) { err.value = 'Passwords do not match.'; return }
  if (!acceptTerms.value) { err.value = 'Please accept the Terms & Conditions to continue.'; return }
  busy.value = true
  const res = await customerRegister({
    name: signupForm.value.name,
    email: signupForm.value.email,
    phone: signupForm.value.phone,
    password: signupForm.value.password,
    passwordConfirmation: signupForm.value.password_confirmation,
    marketing: marketing.value,
  })
  busy.value = false
  if (res.error) { err.value = res.error; return }
  finishOk()
}

async function onGoogleSuccess(response) {
  err.value = ''
  busy.value = true
  const res = await customerGoogleLogin(response?.credential)
  busy.value = false
  if (res.error) { err.value = res.error; return }
  finishOk()
}

function onGoogleError() {
  err.value = 'Google sign-in failed. Please try again.'
  busy.value = false
}

async function sendForgotOtp() {
  err.value = ''; info.value = ''
  busy.value = true
  const res = await customerForgotPassword(forgotForm.value.email)
  busy.value = false
  if (res.error) { err.value = res.error; return }
  info.value = res.message || 'OTP has been sent to your email.'
  step.value = 'reset'
}

async function submitReset() {
  err.value = ''; info.value = ''
  busy.value = true
  const res = await customerResetPassword({
    email: forgotForm.value.email,
    otp: forgotForm.value.otp,
    password: forgotForm.value.password,
    passwordConfirmation: forgotForm.value.password_confirmation,
  })
  busy.value = false
  if (res.error) { err.value = res.error; return }
  if (res.customer) { finishOk(); return }
  info.value = res.message || 'Password has been reset. You can sign in now.'
  step.value = 'choose'
  mode.value = 'login'
  loginForm.value.email = forgotForm.value.email
}

function openForgot() {
  err.value = ''; info.value = ''
  adminReset.value = false
  forgotForm.value = { email: loginForm.value.email, otp: '', password: '', password_confirmation: '' }
  step.value = 'forgot'
}
</script>

<template>
  <div v-if="authGateOpen" class="ag-overlay" @click.self="cancel">
    <div class="ag" role="dialog" aria-modal="true">
      <button type="button" class="ag-x" @click="cancel" aria-label="Close"><X :size="18" /></button>

      <!-- ── STEP: login / signup ─────────────────────────────────────────── -->
      <template v-if="step === 'choose'">
        <div class="ag-ic"><Sparkles :size="22" /></div>
        <div class="ag-head">Sign in or create your account</div>
        <p class="ag-sub">You'll need a free Bookboll account to continue. <span class="ag-sub-extra">It only takes a moment.</span></p>

        <div v-if="googleReady" class="ag-gbtn">
          <GoogleSignInButton
            type="standard"
            size="large"
            theme="outline"
            text="continue_with"
            shape="rectangular"
            :width="360"
            @success="onGoogleSuccess"
            @error="onGoogleError"
          />
        </div>
        <p v-else class="ag-hint">Google sign-in is not configured on this site.</p>
        <div class="ag-or"><span>or with email</span></div>

        <div class="ag-tabs">
          <button type="button" class="ag-tab" :class="{ on: mode === 'login' }" @click="err=''; mode='login'">Login</button>
          <button type="button" class="ag-tab" :class="{ on: mode === 'signup' }" @click="err=''; mode='signup'">Sign up</button>
        </div>

        <template v-if="mode === 'login'">
          <label class="ag-l">Email</label>
          <input v-model="loginForm.email" type="email" class="ag-in" placeholder="you@example.com" autocomplete="email" @keyup.enter="submitLogin" />
          <label class="ag-l">Password</label>
          <input v-model="loginForm.password" type="password" class="ag-in" placeholder="Your password" autocomplete="current-password" @keyup.enter="submitLogin" />
          <button type="button" class="ag-forgot" @click="openForgot">Forgot password?</button>
          <div v-if="info" class="ag-info">{{ info }}</div>
          <div v-if="err" class="ag-err">{{ err }}</div>
          <button type="button" class="ag-go" :disabled="busy || !emailValid" @click="submitLogin">
            <Loader2 v-if="busy" :size="16" class="ag-spin" /> {{ busy ? 'Signing in…' : 'Sign in' }}
          </button>
        </template>

        <template v-else>
          <label class="ag-l">Full name *</label>
          <input v-model="signupForm.name" class="ag-in" placeholder="Your name" autocomplete="name" />
          <label class="ag-l">Email *</label>
          <input v-model="signupForm.email" type="email" class="ag-in" placeholder="you@example.com" autocomplete="email" />
          <label class="ag-l">Phone *</label>
          <PhoneInput v-model="signupForm.phone" :default-iso="selectedCountry?.iso_code" placeholder="Phone number" />
          <label class="ag-l">Password *</label>
          <input v-model="signupForm.password" type="password" class="ag-in" placeholder="At least 8 characters" autocomplete="new-password" />
          <label class="ag-l">Confirm password *</label>
          <input v-model="signupForm.password_confirmation" type="password" class="ag-in" placeholder="Repeat password" autocomplete="new-password" @keyup.enter="submitSignup" />
          <div class="ag-mkt">
            <label class="ag-crow"><input type="checkbox" v-model="acceptOffers" /> <span>Keep me posted with offers &amp; news <span class="ag-opt">optional</span></span></label>
          </div>
          <div class="ag-consent">
            <label class="ag-crow"><input type="checkbox" v-model="acceptTerms" /> <span>I agree to the <a href="/terms" target="_blank" rel="noopener" @click.stop>Terms &amp; Conditions</a> <span class="ag-req">required</span></span></label>
          </div>
          <div v-if="err" class="ag-err">{{ err }}</div>
          <button type="button" class="ag-go" :disabled="busy" @click="submitSignup">
            <Loader2 v-if="busy" :size="16" class="ag-spin" /> {{ busy ? 'Creating account…' : 'Create my account' }}
          </button>
        </template>
        <button type="button" class="ag-cancel" @click="cancel">Cancel</button>
      </template>

      <!-- ── STEP: request OTP ────────────────────────────────────────────── -->
      <template v-else-if="step === 'forgot'">
        <div class="ag-ic"><ShieldCheck :size="22" /></div>
        <div class="ag-head">Forgot password</div>
        <p class="ag-sub">Enter your email and we'll send a one-time code to reset your password.</p>
        <label class="ag-l">Email</label>
        <input v-model="forgotForm.email" type="email" class="ag-in" placeholder="you@example.com" autocomplete="email" @keyup.enter="sendForgotOtp" />
        <div v-if="err" class="ag-err">{{ err }}</div>
        <button type="button" class="ag-go" :disabled="busy" @click="sendForgotOtp">
          <Loader2 v-if="busy" :size="16" class="ag-spin" /> {{ busy ? 'Sending…' : 'Send OTP' }}
        </button>
        <button type="button" class="ag-cancel" @click="err=''; info=''; step='choose'; mode='login'"><ArrowLeft :size="14" /> Back to login</button>
      </template>

      <!-- ── STEP: reset with OTP ─────────────────────────────────────────── -->
      <template v-else-if="step === 'reset'">
        <div class="ag-ic"><ShieldCheck :size="22" /></div>
        <div class="ag-head">{{ adminReset ? 'Reset your password' : 'Enter your code' }}</div>
        <p class="ag-sub">
          <template v-if="adminReset">Your account password was reset. Enter the OTP sent to <strong>{{ forgotForm.email }}</strong> and choose a new password.</template>
          <template v-else>We sent a code to <strong>{{ forgotForm.email }}</strong>. Enter it below with your new password.</template>
        </p>
        <label class="ag-l">OTP</label>
        <input v-model="forgotForm.otp" class="ag-in ag-otp" inputmode="numeric" maxlength="6" placeholder="••••••" />
        <label class="ag-l">New password</label>
        <input v-model="forgotForm.password" type="password" class="ag-in" placeholder="At least 8 characters" autocomplete="new-password" />
        <label class="ag-l">Confirm new password</label>
        <input v-model="forgotForm.password_confirmation" type="password" class="ag-in" placeholder="Repeat password" autocomplete="new-password" @keyup.enter="submitReset" />
        <div v-if="info" class="ag-info">{{ info }}</div>
        <div v-if="err" class="ag-err">{{ err }}</div>
        <button type="button" class="ag-go" :disabled="busy" @click="submitReset">
          <Loader2 v-if="busy" :size="16" class="ag-spin" /> {{ busy ? 'Saving…' : 'Reset password' }}
        </button>
        <button type="button" class="ag-cancel" @click="err=''; info=''; step='forgot'"><ArrowLeft :size="14" /> Back</button>
      </template>
    </div>
  </div>
</template>

<style scoped>
.ag-overlay { position: fixed; inset: 0; z-index: 400; background: rgba(15,23,42,.6); display: flex; align-items: center; justify-content: center; padding: 18px; overflow-y: auto; }
.ag { position: relative; width: 420px; max-width: 100%; background: var(--surface, #ffffff); border-radius: 20px; padding: 26px 24px 20px; box-shadow: 0 24px 60px rgba(0,0,0,.3); margin: auto; }
.ag-x { position: absolute; top: 12px; right: 12px; width: 34px; height: 34px; border-radius: 50%; border: none; background: #f1f5f9; color: #475569; display: flex; align-items: center; justify-content: center; cursor: pointer; }
.ag-ic { width: 48px; height: 48px; border-radius: 14px; background: color-mix(in srgb, var(--brand, #0f766e) 12%, white); color: var(--brand, #0f766e); display: flex; align-items: center; justify-content: center; margin-bottom: 12px; }
.ag-head { font-size: 20px; font-weight: 900; color: #0f172a; }
.ag-sub { font-size: 13.5px; color: var(--text-muted, #64748b); margin: 6px 0 16px; line-height: 1.5; }
.ag-l { display: block; font-size: 12.5px; font-weight: 700; color: #334155; margin: 11px 0 5px; }
.ag-opt { font-size: 10.5px; font-weight: 700; color: var(--text-light, #94a3b8); }
.ag-in { width: 100%; box-sizing: border-box; border: 1.5px solid var(--border, #e2e8f0); border-radius: 10px; padding: 11px 13px; font-size: 15px; outline: none; }
.ag-in:focus { border-color: var(--brand, #0f766e); }
.ag-otp { text-align: center; letter-spacing: 8px; font-size: 22px; font-weight: 800; }
.ag-gbtn { display: flex; justify-content: center; width: 100%; min-height: 44px; }
.ag-hint { font-size: 12.5px; color: var(--text-muted, #64748b); text-align: center; margin: 0 0 4px; }
.ag-or { display: flex; align-items: center; gap: 10px; margin: 16px 0 4px; color: var(--text-light, #94a3b8); font-size: 12px; font-weight: 600; }
.ag-or::before, .ag-or::after { content: ''; flex: 1; height: 1px; background: var(--border, #e2e8f0); }
.ag-tabs { display: flex; background: #f1f5f9; border-radius: 10px; padding: 4px; margin: 14px 0 4px; }
.ag-tab { flex: 1; border: none; background: transparent; color: #64748b; font-weight: 700; font-size: 13.5px; padding: 9px; border-radius: 8px; cursor: pointer; }
.ag-tab.on { background: var(--surface, #ffffff); color: #0f172a; box-shadow: 0 1px 3px rgba(15,23,42,.08); }
.ag-forgot { display: block; margin: 8px 0 0 auto; background: none; border: none; color: var(--brand, #0f766e); font-weight: 700; font-size: 12.5px; cursor: pointer; padding: 0; }
.ag-mkt { margin-top: 14px; background: #f8fafc; border: 1px solid var(--border, #e2e8f0); border-radius: 12px; padding: 12px 14px; }
.ag-consent { margin-top: 14px; display: flex; flex-direction: column; gap: 9px; background: #f8fafc; border: 1px solid var(--border, #e2e8f0); border-radius: 12px; padding: 12px 14px; }
.ag-crow { display: flex; align-items: flex-start; gap: 9px; font-size: 13px; font-weight: 600; color: #475569; cursor: pointer; line-height: 1.4; }
.ag-crow input { width: 16px; height: 16px; margin-top: 1px; flex-shrink: 0; }
.ag-crow a { color: var(--brand, #0f766e); font-weight: 700; text-decoration: underline; }
.ag-req { font-size: 10.5px; font-weight: 800; color: #dc2626; }
.ag-info { font-size: 13px; color: #15803d; margin-top: 12px; font-weight: 600; line-height: 1.45; }
.ag-err { font-size: 13px; color: #dc2626; margin-top: 12px; font-weight: 600; }
.ag-go { display: flex; align-items: center; justify-content: center; gap: 8px; width: 100%; margin-top: 18px; background: var(--brand, #0f766e); color: #fff; font-weight: 800; font-size: 15px; padding: 14px; border: none; border-radius: 12px; cursor: pointer; }
.ag-go:disabled { opacity: .6; cursor: not-allowed; }
.ag-spin { animation: agspin 1s linear infinite; }
@keyframes agspin { to { transform: rotate(360deg); } }
.ag-cancel { display: flex; align-items: center; justify-content: center; gap: 5px; width: 100%; margin-top: 8px; background: none; border: none; color: var(--text-muted, #64748b); font-weight: 700; font-size: 14px; padding: 10px; cursor: pointer; }

@media (max-width: 760px) {
  .ag-opt, .ag-req { font-size: 11px; }
  .ag-sub { font-size: 13px; }
  .ag-sub-extra { display: none; }
  .ag { padding: 20px 18px 16px; }
  .ag-go { margin-top: 14px; }
  .ag-or { margin: 12px 0 2px; }
  .ag-mkt, .ag-consent { margin-top: 12px; }
  .ag-in { font-size: 16px; }
  .ag-cancel { padding: 13px 10px; }
}
</style>
