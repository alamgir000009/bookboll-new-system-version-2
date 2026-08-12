<script setup>
/**
 * EmailSender.vue — connect your own email-sending address (SMTP).
 *
 * One place that governs BOTH:
 *  - automated notification emails (optional — a toggle here; otherwise Bookboll's shared sender)
 *  - marketing emails to your customer list (mandatory — the Customers composer requires this)
 *
 * Provider-scoped: super admins don't need it (Bookboll's marketplace uses its own infra),
 * so platform mode shows an explanatory note instead of the form.
 */
import { ref, computed, onMounted, watch } from 'vue'
import { useCurrentUser } from '../lib/currentUser.js'
import { pushToast } from '../lib/toast.js'
import { markDirty, clearDirty } from '../lib/unsavedGuard.js'
import {
  blankSmtpConfig, SMTP_ENCRYPTION, SMTP_PRESETS,
  loadSmtpConfig, saveSmtpConfig, validateSmtpConfig, isSmtpConfigured, sendTestEmail,
} from '../lib/emailSender.js'
import {
  MailCheck, Mail, Server, Lock, Eye, EyeOff, Loader, Check, Info, Send,
  ShieldCheck, Megaphone, Bell, ChevronRight,
} from 'lucide-vue-next'

const { isSuperAdmin, isCountryAdmin, role, currentOwnerUserId, currentUser, allUsers } = useCurrentUser()
const isPlatformMode = computed(() =>
  (isSuperAdmin.value || role.value === 'platform_staff' || isCountryAdmin.value) && !currentOwnerUserId.value)

const cfg = ref(blankSmtpConfig())
const loading = ref(true)
const saving = ref(false)
const triedSave = ref(false)
const showPassword = ref(false)
const testTo = ref('')
const testing = ref(false)

const ownerRow = computed(() => allUsers.value.find(u => u.id === currentOwnerUserId.value) || currentUser.value)
const businessName = computed(() => ownerRow.value?.business_name || ownerRow.value?.brand_name || 'your business')

async function load() {
  loading.value = true
  cfg.value = await loadSmtpConfig(currentOwnerUserId.value)
  if (!cfg.value.from_name) cfg.value.from_name = businessName.value
  if (!testTo.value) testTo.value = ownerRow.value?.email || ''
  loading.value = false
  clearDirty()
}
onMounted(load)
watch(currentOwnerUserId, load)
watch(cfg, () => { if (!loading.value) markDirty() }, { deep: true })

const validation = computed(() => validateSmtpConfig(cfg.value))
const connected = computed(() => isSmtpConfigured(cfg.value))
const err = (f) => (triedSave.value ? validation.value.errors[f] : '')

const activePreset = ref('')
function applyPreset(p) {
  activePreset.value = p.key
  if (p.key !== 'custom') { cfg.value.host = p.host; cfg.value.port = p.port; cfg.value.encryption = p.encryption }
}
const presetHint = computed(() => SMTP_PRESETS.find(p => p.key === activePreset.value)?.hint || '')

async function runTest() {
  testing.value = true
  const res = await sendTestEmail(cfg.value, testTo.value)
  testing.value = false
  if (!res.ok) { pushToast(res.error, 'error'); return }
  cfg.value.verified_at = new Date().toISOString()
  pushToast(`Test email sent to ${testTo.value} (simulated).`, 'success')
}

async function save() {
  triedSave.value = true
  // Only require full details when the provider is actually turning the sender ON.
  if (cfg.value.enabled && !validation.value.ok) {
    pushToast('Please complete the highlighted fields.', 'error')
    return
  }
  saving.value = true
  const { error } = await saveSmtpConfig(currentOwnerUserId.value, cfg.value)
  saving.value = false
  if (error) {
    pushToast(/could not find|does not exist|schema cache/i.test(error.message || '')
      ? 'Run the provider_smtp migration (20260728000000) first, then save.'
      : (error.message || 'Could not save.'), 'error')
    return
  }
  clearDirty()
  pushToast(cfg.value.enabled ? 'Your email sender is connected.' : 'Email sender settings saved.', 'success')
}
</script>

<template>
  <div class="page">
    <div class="page-header">
      <div>
        <h1 class="page-title"><MailCheck :size="22" style="vertical-align:-3px" /> Email sender</h1>
        <p class="page-sub">Send your automated and marketing emails from your own professional address, so they look like they come from {{ businessName }} — not Bookboll.</p>
      </div>
      <button v-if="!isPlatformMode" class="btn btn-primary" :disabled="saving" @click="save">
        <Loader v-if="saving" :size="15" class="es-spin" /> {{ saving ? 'Saving…' : 'Save' }}
      </button>
    </div>

    <!-- Platform admins: nothing to configure -->
    <div v-if="isPlatformMode" class="card es-platform">
      <ShieldCheck :size="20" />
      <div>
        <b>Bookboll marketplace emails use Bookboll's own sending infrastructure.</b>
        <p>This page is for provider businesses connecting their own email address. Switch to a business account to set one up.</p>
      </div>
    </div>

    <template v-else>
      <!-- What it powers -->
      <div class="es-uses">
        <div class="es-use">
          <span class="es-use-ic amber"><Bell :size="15" /></span>
          <div><b>Automated emails</b><small>Booking &amp; membership notifications. <em class="es-opt">Optional</em> — falls back to Bookboll's shared sender.</small></div>
        </div>
        <div class="es-use">
          <span class="es-use-ic teal"><Megaphone :size="15" /></span>
          <div><b>Marketing emails</b><small>Campaigns to your customer list. <em class="es-req">Required</em> — you can only email your own list from your own sender.</small></div>
        </div>
      </div>

      <!-- Enable + status -->
      <div class="card es-card">
        <div class="es-enable">
          <label class="es-switch">
            <input type="checkbox" v-model="cfg.enabled" />
            <span class="es-slider"></span>
          </label>
          <div class="es-enable-txt">
            <b>Send emails from my own address</b>
            <small>Turn this on and enter your mail provider's SMTP details below.</small>
          </div>
          <span class="es-status" :class="connected ? 'ok' : 'off'">
            <Check v-if="connected" :size="13" />{{ connected ? 'Connected' : (cfg.enabled ? 'Incomplete' : 'Not connected') }}
          </span>
        </div>

        <div v-if="cfg.enabled" class="es-form">
          <!-- Quick presets -->
          <div class="es-presets">
            <span class="es-presets-l">Quick setup:</span>
            <button v-for="p in SMTP_PRESETS" :key="p.key" class="es-preset" :class="{ active: activePreset === p.key }" @click="applyPreset(p)">{{ p.label }}</button>
          </div>
          <div v-if="presetHint" class="es-hint"><Info :size="13" /> {{ presetHint }}</div>

          <!-- Sender identity -->
          <div class="es-section">Sender identity</div>
          <div class="es-grid">
            <label class="es-field">
              <span>From email</span>
              <input class="es-input" :class="{ bad: err('from_email') }" v-model.trim="cfg.from_email" placeholder="bookings@yourdomain.com" spellcheck="false" />
              <em v-if="err('from_email')" class="es-err">{{ err('from_email') }}</em>
            </label>
            <label class="es-field">
              <span>From name</span>
              <input class="es-input" :class="{ bad: err('from_name') }" v-model="cfg.from_name" :placeholder="businessName" />
              <em v-if="err('from_name')" class="es-err">{{ err('from_name') }}</em>
            </label>
          </div>

          <!-- Server -->
          <div class="es-section"><Server :size="14" /> Mail server (SMTP)</div>
          <div class="es-grid">
            <label class="es-field es-col-2">
              <span>SMTP host</span>
              <input class="es-input" :class="{ bad: err('host') }" v-model.trim="cfg.host" placeholder="smtp.yourprovider.com" spellcheck="false" />
              <em v-if="err('host')" class="es-err">{{ err('host') }}</em>
            </label>
            <label class="es-field">
              <span>Port</span>
              <input class="es-input" :class="{ bad: err('port') }" v-model.number="cfg.port" type="number" placeholder="587" />
              <em v-if="err('port')" class="es-err">{{ err('port') }}</em>
            </label>
            <label class="es-field">
              <span>Encryption</span>
              <select class="es-input" v-model="cfg.encryption">
                <option v-for="e in SMTP_ENCRYPTION" :key="e.value" :value="e.value">{{ e.label }}</option>
              </select>
            </label>
          </div>

          <!-- Auth -->
          <div class="es-section"><Lock :size="14" /> Authentication</div>
          <div class="es-grid">
            <label class="es-field">
              <span>SMTP username</span>
              <input class="es-input" :class="{ bad: err('username') }" v-model.trim="cfg.username" placeholder="Usually your email address" spellcheck="false" autocomplete="off" />
              <em v-if="err('username')" class="es-err">{{ err('username') }}</em>
            </label>
            <label class="es-field">
              <span>SMTP password / API key</span>
              <div class="es-pass">
                <input class="es-input" :class="{ bad: err('password') }" :type="showPassword ? 'text' : 'password'" v-model="cfg.password" placeholder="••••••••••••" autocomplete="new-password" />
                <button type="button" class="es-eye" @click="showPassword = !showPassword"><component :is="showPassword ? EyeOff : Eye" :size="15" /></button>
              </div>
              <em v-if="err('password')" class="es-err">{{ err('password') }}</em>
            </label>
          </div>
          <div class="es-secure"><ShieldCheck :size="12" /> Your credentials are stored securely and only used to send your emails.</div>

          <!-- Use for automated (optional) -->
          <label class="es-toggle-row">
            <input type="checkbox" v-model="cfg.use_for_automated" />
            <span class="es-check"><Check :size="12" /></span>
            <span class="es-toggle-txt"><b>Also send automated notification emails from this address</b><small>Off = booking/membership emails keep coming from Bookboll's shared sender. Marketing always uses your address.</small></span>
          </label>

          <!-- Test -->
          <div class="es-section"><Send :size="14" /> Send a test</div>
          <div class="es-test">
            <input class="es-input" v-model.trim="testTo" placeholder="you@example.com" spellcheck="false" />
            <button class="btn btn-secondary" :disabled="testing" @click="runTest">
              <Loader v-if="testing" :size="14" class="es-spin" /><Send v-else :size="14" /> {{ testing ? 'Sending…' : 'Send test email' }}
            </button>
          </div>
          <div v-if="cfg.verified_at" class="es-verified"><Check :size="13" /> Last test succeeded — your sender looks good.</div>
        </div>
      </div>

      <div class="es-foot"><Info :size="13" /> Sending is simulated in this demo — your settings and test are saved, and drive real delivery in production.</div>
    </template>
  </div>
</template>

<style scoped>
.page-sub { color: var(--text-muted); font-size: 13.5px; margin-top: 4px; max-width: 640px; }
.es-spin { animation: esspin 1s linear infinite; }
@keyframes esspin { to { transform: rotate(360deg); } }

.es-platform { display: flex; gap: 14px; align-items: flex-start; padding: 18px 20px; }
.es-platform svg { color: var(--primary); flex-shrink: 0; margin-top: 2px; }
.es-platform p { font-size: 13px; color: var(--text-muted); margin: 4px 0 0; }

/* Uses strip */
.es-uses { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin: 14px 0; }
@media (max-width: 640px) { .es-uses { grid-template-columns: 1fr; } }
.es-use { display: flex; gap: 11px; align-items: flex-start; background: var(--surface, #ffffff); border: 1px solid var(--border); border-radius: 12px; padding: 13px 15px; }
.es-use-ic { width: 30px; height: 30px; border-radius: 9px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.es-use-ic.amber { background: #fef3c7; color: #b45309; }
.es-use-ic.teal { background: #ccfbf1; color: #0f766e; }
.es-use b { font-size: 13.5px; display: block; }
.es-use small { font-size: 12px; color: var(--text-muted); line-height: 1.45; }
.es-opt { font-style: normal; font-weight: 700; color: #b45309; }
.es-req { font-style: normal; font-weight: 700; color: #0f766e; }

.es-card { padding: 4px 20px 18px; }

/* Enable row */
.es-enable { display: flex; align-items: center; gap: 13px; padding: 16px 0; border-bottom: 1px solid var(--border); }
.es-enable-txt { flex: 1; min-width: 0; }
.es-enable-txt b { font-size: 14px; }
.es-enable-txt small { display: block; font-size: 12px; color: var(--text-muted); }
.es-status { display: inline-flex; align-items: center; gap: 4px; font-size: 11.5px; font-weight: 800; text-transform: uppercase; letter-spacing: .03em; padding: 4px 10px; border-radius: 999px; flex-shrink: 0; }
.es-status.ok { color: #166534; background: #dcfce7; }
.es-status.off { color: var(--text-muted, #64748b); background: #f1f5f9; }

/* Switch */
.es-switch { position: relative; display: inline-block; width: 40px; height: 22px; flex-shrink: 0; }
.es-switch input { opacity: 0; width: 0; height: 0; }
.es-slider { position: absolute; inset: 0; border-radius: 999px; background: var(--border, #e2e8f0); transition: background .15s; cursor: pointer; }
.es-slider::before { content: ""; position: absolute; width: 18px; height: 18px; left: 2px; top: 2px; border-radius: 50%; background: var(--surface, #ffffff); box-shadow: 0 1px 3px rgba(0,0,0,.25); transition: transform .15s; }
.es-switch input:checked + .es-slider { background: var(--primary); }
.es-switch input:checked + .es-slider::before { transform: translateX(18px); }

.es-form { padding-top: 16px; }
.es-presets { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
.es-presets-l { font-size: 12px; font-weight: 700; color: var(--text-muted); margin-right: 3px; }
.es-preset { border: 1.5px solid var(--border); background: var(--surface, #ffffff); border-radius: 999px; padding: 6px 12px; font-size: 12.5px; font-weight: 600; color: var(--text-muted); cursor: pointer; }
.es-preset:hover { border-color: var(--primary); color: var(--primary); }
.es-preset.active { border-color: var(--primary); background: #f0fdfa; color: var(--primary); }
.es-hint { display: flex; align-items: center; gap: 6px; font-size: 12px; color: var(--text-muted); margin-top: 8px; }

.es-section { display: flex; align-items: center; gap: 6px; font-size: 11.5px; font-weight: 800; text-transform: uppercase; letter-spacing: .04em; color: var(--text-muted); margin: 18px 0 10px; }
.es-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.es-col-2 { grid-column: span 2; }
@media (max-width: 640px) { .es-grid { grid-template-columns: 1fr; } .es-col-2 { grid-column: span 1; } }
.es-field { display: flex; flex-direction: column; gap: 5px; }
.es-field > span { font-size: 11.5px; font-weight: 700; color: var(--text-muted); }
.es-input { width: 100%; border: 1px solid var(--border); border-radius: 10px; padding: 9px 12px; font-size: 13.5px; background: var(--surface, #ffffff); font-family: inherit; }
.es-input:focus { outline: none; border-color: var(--primary); }
.es-input.bad { border-color: #f87171; background: #fef2f2; }
.es-err { font-size: 11.5px; color: #dc2626; }
.es-pass { position: relative; }
.es-eye { position: absolute; right: 6px; top: 50%; transform: translateY(-50%); border: none; background: transparent; color: var(--text-light, #94a3b8); cursor: pointer; padding: 4px; display: inline-flex; }
.es-eye:hover { color: var(--text); }
.es-secure { display: flex; align-items: center; gap: 5px; font-size: 11.5px; color: var(--text-muted); margin-top: 8px; }

.es-toggle-row { display: flex; gap: 10px; align-items: flex-start; margin-top: 16px; padding: 12px 14px; border: 1px solid var(--border); border-radius: 12px; background: var(--bg); cursor: pointer; }
.es-toggle-row input { position: absolute; opacity: 0; }
.es-check { width: 18px; height: 18px; border-radius: 5px; border: 1.5px solid var(--border); background: var(--surface, #ffffff); display: inline-flex; align-items: center; justify-content: center; color: #fff; flex-shrink: 0; margin-top: 1px; }
.es-toggle-row input:checked + .es-check { background: var(--primary); border-color: var(--primary); }
.es-toggle-txt b { font-size: 13px; }
.es-toggle-txt small { display: block; font-size: 11.5px; color: var(--text-muted); line-height: 1.45; }

.es-test { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.es-test .es-input { max-width: 300px; }
.es-verified { display: flex; align-items: center; gap: 6px; font-size: 12px; color: #166534; margin-top: 8px; }

.es-foot { display: flex; align-items: center; gap: 6px; font-size: 12px; color: var(--text-muted); margin-top: 14px; }

/* Phone (360px-class) fixes — mobile only, desktop untouched */
@media (max-width: 640px) {
  /* iOS auto-zoom: all inputs/selects using .es-input must be >=16px */
  .es-input { font-size: 16px; }

  /* Enable row: let it wrap so the text keeps full width; pill drops to its own line under the text (40px switch + 13px gap = 53px).
     min-width floor on the text forces the actual wrap (flex:1 = basis 0%, which never triggers wrapping on its own). */
  .es-enable { flex-wrap: wrap; }
  .es-enable-txt { min-width: 60%; }
  .es-status { order: 3; margin-left: 53px; }

  /* Test row: stack input + full-width 44px button */
  .es-test { flex-direction: column; align-items: stretch; }
  .es-test .es-input { max-width: none; }
  .es-test .btn { width: 100%; min-height: 44px; justify-content: center; }

  /* Bigger touch targets for quick-setup preset pills */
  .es-preset { padding: 10px 14px; }

  /* Password reveal: comfortable tap target + keep text clear of the icon */
  .es-eye { padding: 10px; right: 2px; }
  .es-pass .es-input { padding-right: 40px; }
}

/* Enable toggle: taller tap band without moving the pill — mobile only */
@media (max-width: 600px) {
  /* Grow the label's hit area to 40px tall and center the pill inside it.
     The pill (.es-slider) is pinned to its true 22px height and vertically
     centered so the absolutely-positioned thumb (::before) is not shifted. */
  .es-switch { min-height: 40px; display: inline-flex; align-items: center; }
  .es-slider { inset: auto 0; top: 50%; transform: translateY(-50%); height: 22px; }
}
</style>
