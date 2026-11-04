<script setup>
// SECURITY: no prefilled or hardcoded credentials — everyone (super admin
// included) types their email and password like any normal sign-in.
import { ref, onMounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { signIn, authError, requestAdminPasswordReset, resetAdminPassword } from '../lib/auth.js'
import { Eye, EyeOff, Loader, ArrowLeft } from 'lucide-vue-next'
import BrandMark from '../components/BrandMark.vue'
import { adminBrand, adminLogoSquareStyle } from '../lib/adminBrand.js'
import { businessSignupUrl } from '../lib/crossApp.js'

const router = useRouter()
const route = useRoute()

const email    = ref('')
const password = ref('')
const showPw   = ref(false)
const loading  = ref(false)
const localErr = ref('')
const localInfo = ref('')

// Three states in the one card rather than a route each: a reset is a detour from
// signing in, and the admin should land back on the form they started at.
const step = ref('signin')   // 'signin' | 'forgot' | 'reset'
const otp  = ref('')
const newPassword = ref('')
const newPasswordConfirm = ref('')

onMounted(() => {
  if (route.query.email) email.value = String(route.query.email)
})

function go(next) {
  step.value = next
  localErr.value = ''
  localInfo.value = ''
}

async function handleLogin() {
  loading.value = true; localErr.value = ''
  const ok = await signIn(email.value.trim(), password.value)
  loading.value = false
  if (ok) { router.replace('/admin') }
  else { localErr.value = authError.value || 'Invalid email or password.' }
}

async function sendResetCode() {
  loading.value = true; localErr.value = ''; localInfo.value = ''
  const res = await requestAdminPasswordReset(email.value)
  loading.value = false
  if (res.error) { localErr.value = res.error; return }
  // Worded as a conditional on purpose: the API gives the same answer for an address
  // it does not know, so promising "sent" would be a lie half the time.
  localInfo.value = 'If that address belongs to an admin account, a 6-digit code is on its way. It expires in 10 minutes.'
  step.value = 'reset'
}

async function submitReset() {
  loading.value = true; localErr.value = ''; localInfo.value = ''
  const res = await resetAdminPassword({
    email: email.value,
    otp: otp.value,
    password: newPassword.value,
    passwordConfirmation: newPasswordConfirm.value,
  })
  loading.value = false
  if (res.error) { localErr.value = res.error; return }

  otp.value = ''; newPassword.value = ''; newPasswordConfirm.value = ''
  password.value = ''
  step.value = 'signin'
  localInfo.value = 'Password updated. Sign in with your new password.'
}
</script>

<template>
  <div class="auth-shell">
    <div class="auth-card">
      <!-- Brand -->
      <div class="auth-brand">
        <div class="auth-logo" :style="adminBrand.logo ? { backgroundImage: `url(${adminBrand.logo})`, backgroundSize: 'cover', backgroundPosition: 'center' } : adminLogoSquareStyle"><BrandMark v-if="!adminBrand.logo" /></div>
        <div>
          <div class="auth-brand-name">Bookboll</div>
          <div class="auth-brand-sub">Admin Portal</div>
        </div>
      </div>

      <!-- ── Sign in ──────────────────────────────────────────────────────── -->
      <template v-if="step === 'signin'">
        <h1 class="auth-heading">Welcome back</h1>
        <p class="auth-sub">Sign in to manage your platform</p>

        <form @submit.prevent="handleLogin" class="auth-form">
          <div class="auth-field">
            <label class="auth-label">Email</label>
            <input v-model="email" type="email" class="auth-input" placeholder="you@example.com" required autocomplete="email" />
          </div>

          <div class="auth-field">
            <label class="auth-label">Password</label>
            <div class="auth-pw-wrap">
              <input v-model="password" :type="showPw ? 'text' : 'password'" class="auth-input" placeholder="••••••••" required autocomplete="current-password" />
              <button type="button" class="auth-pw-eye" @click="showPw = !showPw">
                <component :is="showPw ? EyeOff : Eye" :size="16" />
              </button>
            </div>
            <button type="button" class="auth-forgot" @click="go('forgot')">Forgot password?</button>
          </div>

          <div v-if="localInfo" class="auth-info">{{ localInfo }}</div>
          <div v-if="localErr" class="auth-error">{{ localErr }}</div>

          <button type="submit" class="auth-btn" :disabled="loading">
            <Loader v-if="loading" :size="16" class="auth-spin" />
            {{ loading ? 'Signing in…' : 'Sign in' }}
          </button>
        </form>

        <div class="auth-footer">
          New business? <a :href="businessSignupUrl()" class="auth-link">Create an account</a>
        </div>
      </template>

      <!-- ── Ask for a code ───────────────────────────────────────────────── -->
      <template v-else-if="step === 'forgot'">
        <h1 class="auth-heading">Reset your password</h1>
        <p class="auth-sub">We'll email you a 6-digit code.</p>

        <form @submit.prevent="sendResetCode" class="auth-form">
          <div class="auth-field">
            <label class="auth-label">Email</label>
            <input v-model="email" type="email" class="auth-input" placeholder="you@example.com" required autocomplete="email" />
          </div>

          <div v-if="localErr" class="auth-error">{{ localErr }}</div>

          <button type="submit" class="auth-btn" :disabled="loading">
            <Loader v-if="loading" :size="16" class="auth-spin" />
            {{ loading ? 'Sending…' : 'Send code' }}
          </button>
        </form>

        <button type="button" class="auth-back" @click="go('signin')"><ArrowLeft :size="14" /> Back to sign in</button>
      </template>

      <!-- ── Code + new password ──────────────────────────────────────────── -->
      <template v-else>
        <h1 class="auth-heading">Enter your code</h1>
        <p class="auth-sub">Sent to {{ email }}</p>

        <form @submit.prevent="submitReset" class="auth-form">
          <div class="auth-field">
            <label class="auth-label">6-digit code</label>
            <input v-model="otp" type="text" inputmode="numeric" maxlength="6" class="auth-input auth-otp"
                   placeholder="000000" required autocomplete="one-time-code" />
          </div>

          <div class="auth-field">
            <label class="auth-label">New password</label>
            <div class="auth-pw-wrap">
              <input v-model="newPassword" :type="showPw ? 'text' : 'password'" class="auth-input"
                     placeholder="At least 8 characters" required autocomplete="new-password" />
              <button type="button" class="auth-pw-eye" @click="showPw = !showPw">
                <component :is="showPw ? EyeOff : Eye" :size="16" />
              </button>
            </div>
          </div>

          <div class="auth-field">
            <label class="auth-label">Confirm new password</label>
            <input v-model="newPasswordConfirm" :type="showPw ? 'text' : 'password'" class="auth-input"
                   placeholder="Repeat it" required autocomplete="new-password" />
          </div>

          <div v-if="localInfo" class="auth-info">{{ localInfo }}</div>
          <div v-if="localErr" class="auth-error">{{ localErr }}</div>

          <button type="submit" class="auth-btn" :disabled="loading">
            <Loader v-if="loading" :size="16" class="auth-spin" />
            {{ loading ? 'Saving…' : 'Set new password' }}
          </button>
        </form>

        <button type="button" class="auth-back" @click="go('forgot')"><ArrowLeft :size="14" /> Send a new code</button>
      </template>
    </div>
  </div>
</template>

<style scoped>
.auth-shell {
  min-height: 100vh; display: flex; align-items: center; justify-content: center;
  background: linear-gradient(135deg, #0f172a 0%, #1e293b 60%, #0f766e22 100%);
  padding: 24px;
}
.auth-card {
  width: 100%; max-width: 400px; background: var(--surface, #ffffff); border-radius: 20px;
  padding: 36px 36px 28px; box-shadow: 0 24px 60px rgba(0,0,0,.35);
}
.auth-brand { display: flex; align-items: center; gap: 12px; margin-bottom: 28px; }
.auth-logo {
  width: 44px; height: 44px; border-radius: 12px;
  background: linear-gradient(135deg, #14b8a6, #0f766e);
  color: #fff; display: flex; align-items: center; justify-content: center;
  font-weight: 700; font-size: 16px;
}
.auth-brand-name { font-size: 18px; font-weight: 700; color: var(--text, #0f172a); }
.auth-brand-sub  { font-size: 12px; color: var(--text-muted, #64748b); }

.auth-heading { font-size: 22px; font-weight: 700; color: var(--text, #0f172a); margin: 0 0 4px; }
.auth-sub { font-size: 14px; color: var(--text-muted, #64748b); margin: 0 0 24px; }

.auth-form { display: flex; flex-direction: column; gap: 16px; }
.auth-field { display: flex; flex-direction: column; gap: 6px; }
.auth-label { font-size: 13px; font-weight: 600; color: var(--text-secondary, #374151); }
.auth-input {
  width: 100%; padding: 10px 14px; border: 1.5px solid var(--border, #e2e8f0); border-radius: 10px;
  font-size: 14px; color: var(--text, #0f172a); background: var(--surface, #ffffff); box-sizing: border-box;
  transition: border-color .15s, box-shadow .15s;
}
.auth-input:focus { outline: none; border-color: #14b8a6; box-shadow: 0 0 0 3px rgba(20,184,166,.12); }

.auth-pw-wrap { position: relative; }
.auth-pw-wrap .auth-input { padding-right: 42px; }
.auth-pw-eye {
  position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
  background: none; border: none; cursor: pointer; color: var(--text-light, #94a3b8); display: flex;
}
.auth-pw-eye:hover { color: var(--text, #0f172a); }

.auth-error { font-size: 13px; color: var(--danger-ink, #dc2626); background: var(--danger-bg, #fef2f2); border: 1px solid var(--danger-border, #fecaca); border-radius: 8px; padding: 8px 12px; }
.auth-info { font-size: 13px; color: var(--primary-ink, #0f766e); background: var(--primary-soft, #f0fdfa); border: 1px solid var(--primary-border, #99f6e4); border-radius: 8px; padding: 8px 12px; }
.auth-forgot { align-self: flex-end; margin-top: 2px; background: none; border: none; padding: 0; color: var(--primary-ink, #0f766e); font-size: 12.5px; font-weight: 600; cursor: pointer; }
.auth-forgot:hover { text-decoration: underline; }
.auth-back { display: flex; align-items: center; justify-content: center; gap: 6px; width: 100%; margin-top: 18px; background: none; border: none; color: var(--text-muted, #64748b); font-size: 13px; font-weight: 600; cursor: pointer; }
.auth-back:hover { color: var(--text, #0f172a); }
.auth-otp { letter-spacing: 8px; font-size: 18px; font-weight: 700; text-align: center; }

.auth-btn {
  width: 100%; padding: 12px; border: none; border-radius: 10px;
  background: linear-gradient(135deg, #14b8a6, #0f766e); color: #fff;
  font-size: 15px; font-weight: 700; cursor: pointer; display: flex;
  align-items: center; justify-content: center; gap: 8px; transition: opacity .15s;
}
.auth-btn:hover:not(:disabled) { opacity: .92; }
.auth-btn:disabled { opacity: .6; cursor: not-allowed; }
.auth-spin { animation: spin 1s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }

.auth-footer { margin-top: 20px; text-align: center; font-size: 13.5px; color: var(--text-muted, #64748b); }
.auth-link { color: var(--primary-ink, #0f766e); font-weight: 600; text-decoration: none; }
.auth-link:hover { text-decoration: underline; }

/* Mobile (narrow phones, 344-600px) */
@media (max-width: 600px) {
  .auth-shell { padding: 16px; }
  .auth-card { padding: 24px 20px 20px; }
  .auth-input { font-size: 16px; } /* >=16px prevents iOS focus zoom */
  .auth-error, .auth-info { overflow-wrap: anywhere; } /* long emails in error text */
}
</style>

