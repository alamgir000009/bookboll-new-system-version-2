<script setup>
// SECURITY: no prefilled or hardcoded credentials — everyone (super admin
// included) types their email and password like any normal sign-in.
import { ref, onMounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { signIn, authError } from '../lib/auth.js'
import { Eye, EyeOff, Loader } from 'lucide-vue-next'
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

onMounted(() => {
  if (route.query.email) email.value = String(route.query.email)
})

async function handleLogin() {
  loading.value = true; localErr.value = ''
  const ok = await signIn(email.value.trim(), password.value)
  loading.value = false
  if (ok) { router.replace('/admin') }
  else { localErr.value = authError.value || 'Invalid email or password.' }
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
        </div>

        <div v-if="localErr" class="auth-error">{{ localErr }}</div>

        <button type="submit" class="auth-btn" :disabled="loading">
          <Loader v-if="loading" :size="16" class="auth-spin" />
          {{ loading ? 'Signing in…' : 'Sign in' }}
        </button>
      </form>

      <div class="auth-footer">
        New business? <a :href="businessSignupUrl()" class="auth-link">Create an account</a>
      </div>
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
.auth-brand-name { font-size: 18px; font-weight: 700; color: #0f172a; }
.auth-brand-sub  { font-size: 12px; color: var(--text-muted, #64748b); }

.auth-heading { font-size: 22px; font-weight: 700; color: #0f172a; margin: 0 0 4px; }
.auth-sub { font-size: 14px; color: var(--text-muted, #64748b); margin: 0 0 24px; }

.auth-form { display: flex; flex-direction: column; gap: 16px; }
.auth-field { display: flex; flex-direction: column; gap: 6px; }
.auth-label { font-size: 13px; font-weight: 600; color: #374151; }
.auth-input {
  width: 100%; padding: 10px 14px; border: 1.5px solid var(--border, #e2e8f0); border-radius: 10px;
  font-size: 14px; color: #0f172a; background: var(--surface, #ffffff); box-sizing: border-box;
  transition: border-color .15s, box-shadow .15s;
}
.auth-input:focus { outline: none; border-color: #14b8a6; box-shadow: 0 0 0 3px rgba(20,184,166,.12); }

.auth-pw-wrap { position: relative; }
.auth-pw-wrap .auth-input { padding-right: 42px; }
.auth-pw-eye {
  position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
  background: none; border: none; cursor: pointer; color: var(--text-light, #94a3b8); display: flex;
}
.auth-pw-eye:hover { color: #0f172a; }

.auth-error { font-size: 13px; color: #dc2626; background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 8px 12px; }

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
.auth-link { color: #0f766e; font-weight: 600; text-decoration: none; }
.auth-link:hover { text-decoration: underline; }

/* Mobile (narrow phones, 344-600px) */
@media (max-width: 600px) {
  .auth-shell { padding: 16px; }
  .auth-card { padding: 24px 20px 20px; }
  .auth-input { font-size: 16px; } /* >=16px prevents iOS focus zoom */
  .auth-error, .auth-info { overflow-wrap: anywhere; } /* long emails in error text */
}
</style>

