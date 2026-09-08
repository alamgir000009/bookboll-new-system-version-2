<script setup>
/**
 * WhatsApp.vue — GUIDED Meta WhatsApp Business setup wizard.
 *
 * PER-PROVIDER, like the Email sender:
 *  - A provider connects THEIR OWN number + access token (stored on businesses.whatsapp_config)
 *    and markets their own customers from it. Booking notifications always ride Bookboll's
 *    number, so providers never see webhook / app-secret plumbing.
 *  - Bookboll (super admin) configures its OWN number here (platform settings, token included —
 *    the backend uses dashboard values first, the WHATSAPP_ACCESS_TOKEN env value as fallback)
 *    — used for marketplace notifications, Bookboll marketing and the booking bot.
 *
 * REAL infrastructure: the test send delivers an actual WhatsApp message via Meta's Cloud API.
 */
import { ref, computed, onMounted, watch } from 'vue'
import { useCurrentUser } from '../lib/currentUser.js'
import { pushToast } from '../lib/toast.js'
import { markDirty, clearDirty } from '../lib/unsavedGuard.js'
import {
  blankWhatsappConfig, loadWhatsappConfig, saveWhatsappConfig, isWhatsappConfigured, invokeWhatsapp,
  webhookCallbackUrl, generateVerifyToken, loadWaOutbound,
} from '../lib/whatsapp.js'
import WhatsappIcon from '../components/WhatsappIcon.vue'
import WaTemplateManager from '../components/WaTemplateManager.vue'
import {
  Check, Info, Loader, Send, ShieldCheck, RefreshCw, ChevronDown, HelpCircle, AlertTriangle,
  MessageSquare, Megaphone, CalendarCheck, Copy, Eye, EyeOff,
} from 'lucide-vue-next'

const { currentOwnerUserId, currentUser, allUsers } = useCurrentUser()
const ownerId = computed(() => currentOwnerUserId.value || null)
const isPlatform = computed(() => !ownerId.value)          // Bookboll (super admin) vs a provider
const businessName = computed(() => {
  const o = allUsers.value.find(u => u.id === ownerId.value) || currentUser.value
  return o?.business_name || o?.brand_name || 'your business'
})

const cfg = ref(blankWhatsappConfig())
const loading = ref(true)
const saving = ref(false)
const showToken = ref(false)
const showSecret = ref(false)

// Live function status (per this business)
const fnStatus = ref(null)
const checking = ref(false)

// Test send
const testTo = ref('')
const testTemplate = ref('hello_world')
const testLang = ref('en_US')
const testing = ref(false)

// Recent outbound log
const outbound = ref([])
const outboundMissing = ref(false)

const configured = computed(() => isWhatsappConfigured(cfg.value, ownerId.value))
const connectedOk = computed(() => !!(fnStatus.value?.ok && fnStatus.value.hasToken && fnStatus.value.configured && fnStatus.value.phone))

// ── The wizard ────────────────────────────────────────────────────────────────
const filled = (v) => !!String(v || '').trim()
const CALLBACK_URL = webhookCallbackUrl()

const steps = computed(() => {
  const c = cfg.value
  const tokenOk = filled(c.access_token) || (isPlatform.value && fnStatus.value?.tokenSource === 'secret')
  const list = [
    {
      key: 'app',
      title: 'Create your Meta app',
      // Provider: the App ID field is optional, so a filled phone number ID proves the app exists.
      done: isPlatform.value ? (filled(c.app_id) && filled(c.app_secret)) : (filled(c.app_id) || filled(c.phone_number_id)),
    },
    // WABA ID is REQUIRED here: without it Meta won't let us list/create/send templates,
    // so marketing simply can't work. (status can go green on phone+token alone — that only
    // proves you can send — but this step must reflect marketing-readiness.)
    { key: 'product', title: isPlatform.value ? 'Add the WhatsApp product' : 'Add your WhatsApp number', done: filled(c.phone_number_id) && filled(c.waba_id) },
    { key: 'token', title: 'Create a PERMANENT access token', done: tokenOk },
  ]
  if (isPlatform.value) list.push({ key: 'webhook', title: 'Connect the webhook (inbound messages + booking bot)', done: filled(c.verify_token) })
  list.push({ key: 'test', title: 'Save & test', done: connectedOk.value })
  return list
})
const stepMap = computed(() => {
  const m = {}
  steps.value.forEach((s, i) => { m[s.key] = { ...s, num: i + 1 } })
  return m
})
const doneCount = computed(() => steps.value.filter(s => s.done).length)
const progressPct = computed(() => Math.round((doneCount.value / steps.value.length) * 100))

const openStep = ref('')
function toggleStep(key) { openStep.value = openStep.value === key ? '' : key }
function autoOpen() {
  const first = steps.value.find(s => !s.done)
  openStep.value = first ? first.key : 'test'
}

function genVerify() { cfg.value.verify_token = generateVerifyToken() }

// ── Status / data loading (unchanged logic) ───────────────────────────────────
async function refreshStatus() {
  checking.value = true
  fnStatus.value = await invokeWhatsapp({ action: 'status', ownerId: ownerId.value })
  checking.value = false
}

async function loadOutbound() {
  // Scoped server-side to this caller's own sender — a provider sees their own
  // messages, Bookboll sees the platform number's.
  const res = await loadWaOutbound(15)
  if (!res.ok) { outboundMissing.value = true; return }
  outboundMissing.value = false
  outbound.value = res.outbound
}

async function load() {
  loading.value = true
  cfg.value = await loadWhatsappConfig()
  if (!testTo.value) testTo.value = (allUsers.value.find(u => u.id === ownerId.value) || currentUser.value)?.phone?.replace(/\D/g, '') || ''
  loading.value = false
  clearDirty()
  autoOpen()
  refreshStatus()
  loadOutbound()
}
onMounted(load)
watch(currentOwnerUserId, load)
watch(cfg, () => { if (!loading.value) markDirty() }, { deep: true })

async function save() {
  saving.value = true
  // Provider: mark connected when the required fields are present.
  if (ownerId.value) cfg.value.enabled = !!(String(cfg.value.phone_number_id || '').trim() && String(cfg.value.access_token || '').trim())
  const { error, config } = await saveWhatsappConfig(null, cfg.value)
  saving.value = false
  if (error) { pushToast(error.message || 'Could not save.', 'error'); return }
  if (config) cfg.value = { ...blankWhatsappConfig(), ...config }
  clearDirty()
  pushToast('WhatsApp settings saved.', 'success')
  refreshStatus()
}

async function sendTest() {
  const to = testTo.value.replace(/\D/g, '')
  if (!to) { pushToast('Enter the destination number (digits with country code).', 'error'); return }
  testing.value = true
  const res = await invokeWhatsapp({ action: 'send_template', ownerId: ownerId.value, to, template: { name: testTemplate.value.trim() || 'hello_world', language: testLang.value.trim() || 'en_US' } })
  testing.value = false
  if (!res.ok) { pushToast(res.error || 'Send failed.', 'error'); return }
  pushToast(`Test message sent to ${to} — check WhatsApp.`, 'success')
  loadOutbound()
}

const copied = ref('')
async function copyCmd(text, key) {
  try { await navigator.clipboard.writeText(text) } catch {}
  copied.value = key
  setTimeout(() => { if (copied.value === key) copied.value = '' }, 1200)
}

const statusLabel = (s) => ({ sent: 'Sent', delivered: 'Delivered', read: 'Read', failed: 'Failed' }[s] || s)
const fmtWhen = (iso) => new Date(iso).toLocaleString()
</script>

<template>
  <div class="page">
    <div class="page-header">
      <div>
        <h1 class="page-title"><WhatsappIcon :size="22" style="vertical-align:-3px;color:#25d366" /> WhatsApp</h1>
        <p class="page-sub">
          {{ isPlatform
            ? "Bookboll's WhatsApp Business connection — for Bookboll's own campaigns and the (upcoming) venue-booking funnel. Follow the numbered steps; each one tells you exactly where to click in Meta."
            : `Connect ${businessName}'s WhatsApp Business number to send marketing campaigns to your opted-in customers. Follow the numbered steps — each one tells you exactly where to click in Meta.` }}
        </p>
      </div>
      <button class="btn btn-primary" :disabled="saving" @click="save">
        <Loader v-if="saving" :size="15" class="wa-spin" /> {{ saving ? 'Saving…' : 'Save' }}
      </button>
    </div>

    <!-- What it powers -->
    <div class="wa-uses" :class="{ single: !isPlatform }">
      <div class="wa-use"><span class="wa-use-ic on"><Megaphone :size="15" /></span><div><b>Marketing campaigns</b><small>Template messages to your opted-in customers — live now.</small></div></div>
      <div v-if="isPlatform" class="wa-use"><span class="wa-use-ic dim"><CalendarCheck :size="15" /></span><div><b>WhatsApp venue booking</b><small>Marketplace customers book a venue over WhatsApp via a guided menu of buttons &amp; lists (not an AI chatbot). Next phase.</small></div></div>
    </div>

    <!-- A) Connection checklist -->
    <div class="card wa-card">
      <div class="wa-check-top">
        <div class="wa-prog">
          <div class="wa-prog-label"><b>Connection checklist</b><span class="wa-prog-count">{{ doneCount }} of {{ steps.length }} done</span></div>
          <div class="wa-prog-bar"><i :style="{ width: progressPct + '%' }"></i></div>
        </div>
        <div class="wa-pill" :class="checking ? 'busy' : (connectedOk ? 'ok' : 'off')">
          <Loader v-if="checking" :size="13" class="wa-spin" />
          <template v-else-if="connectedOk"><Check :size="14" /> Connected</template>
          <template v-else>Not connected yet</template>
        </div>
      </div>
      <div class="wa-check-sub">
        <div class="wa-status-main">
          <template v-if="checking">Checking the connection…</template>
          <template v-else-if="fnStatus && fnStatus.ok === false"><b>WhatsApp service unavailable.</b>&nbsp;{{ fnStatus.error || 'Try again in a moment.' }}</template>
          <template v-else-if="connectedOk"><b>{{ fnStatus.phone.name }}</b>&nbsp;({{ fnStatus.phone.number }})<span v-if="fnStatus.phone.quality" class="wa-quality">quality: {{ String(fnStatus.phone.quality).toLowerCase() }}</span></template>
          <template v-else-if="fnStatus && !fnStatus.hasToken"><b>Token missing.</b>&nbsp;Add a permanent access token in step {{ stepMap.token.num }}, then Save.</template>
          <template v-else-if="fnStatus && !fnStatus.configured"><b>Almost there.</b>&nbsp;Enter your Phone number ID in step {{ stepMap.product.num }}, then Save.</template>
          <template v-else-if="fnStatus?.phoneError"><b>Token/number problem:</b>&nbsp;{{ fnStatus.phoneError }}</template>
          <template v-else>Work through the steps below — no Meta experience needed.</template>
          <span v-if="fnStatus?.tokenSource === 'dashboard'" class="wa-chip">token from dashboard</span>
          <span v-else-if="fnStatus?.tokenSource === 'secret'" class="wa-chip">token from function secret</span>
        </div>
        <button class="btn btn-secondary btn-sm" :disabled="checking" @click="refreshStatus"><RefreshCw :size="13" /> Recheck</button>
      </div>
      <!-- Connected for sending, but the WABA ID is missing → marketing templates can't load.
           This is the exact state that makes the composer show the "couldn't load templates" fallback. -->
      <div v-if="connectedOk && !filled(cfg.waba_id)" class="wa-warn">
        <Info :size="14" />
        <span>Your number is connected, but the <b>WhatsApp Business Account ID</b> is still empty — so marketing templates can't be listed, created or sent yet. Add it in step {{ stepMap.product?.num }} below (same Meta “API Setup” screen as your Phone number ID), then Save.</span>
      </div>
      <div v-if="!isPlatform" class="wa-note">
        <Info :size="13" />
        <span>This connects your number for <b>your own WhatsApp marketing</b>. Booking notifications to your customers are always sent from Bookboll's number — nothing to set up for those.</span>
      </div>
    </div>

    <!-- B) Setup steps -->
    <div class="wa-wizard">

      <!-- Step: Create your Meta app -->
      <div class="card wa-step-card" :class="{ done: stepMap.app.done, open: openStep === 'app' }">
        <button type="button" class="wa-step-head" @click="toggleStep('app')">
          <span class="wa-step-num"><Check v-if="stepMap.app.done" :size="14" /><template v-else>{{ stepMap.app.num }}</template></span>
          <span class="wa-step-title">Create your Meta app</span>
          <ChevronDown :size="16" class="wa-chev" />
        </button>
        <div v-show="openStep === 'app'" class="wa-step-body">
          <p class="wa-lead">Everything WhatsApp runs through a free Meta “app” — a container you create once on Meta's developer site. No coding involved.</p>
          <details class="wa-help">
            <summary><HelpCircle :size="14" /> Where do I find this?</summary>
            <ol>
              <li>Open <b>developers.facebook.com/apps</b> and log in with the Facebook account that manages your business.</li>
              <li>Click <b>Create app</b> → choose the <b>Business</b> app type → <b>Next</b>.</li>
              <li>Give it a name (e.g. “Bookboll”) and click <b>Create app</b>.</li>
              <li>In the left menu open <b>App settings → Basic</b>.</li>
              <li>Copy the <b>App ID</b> shown at the top → paste it below.</li>
              <li v-if="isPlatform">On the same page click <b>Show</b> next to <b>App secret</b>, copy it → paste it below.</li>
            </ol>
          </details>
          <div class="wa-grid">
            <label class="wa-field">
              <span>App ID <i v-if="!isPlatform" class="wa-opt">optional</i> <Check v-if="filled(cfg.app_id)" :size="13" class="wa-okck" /></span>
              <input class="wa-input" v-model.trim="cfg.app_id" placeholder="e.g. 1089216549371284" spellcheck="false" inputmode="numeric" />
              <em>{{ isPlatform ? 'App settings → Basic → “App ID”.' : 'Just for your own reference — everything works without it.' }}</em>
            </label>
            <label v-if="isPlatform" class="wa-field">
              <span>App secret <Check v-if="filled(cfg.app_secret)" :size="13" class="wa-okck" /></span>
              <div class="wa-pass">
                <input class="wa-input" :type="showSecret ? 'text' : 'password'" v-model.trim="cfg.app_secret" placeholder="Click “Show” in Meta, then paste" spellcheck="false" autocomplete="off" />
                <button type="button" class="wa-eye" @click="showSecret = !showSecret"><component :is="showSecret ? EyeOff : Eye" :size="15" /></button>
              </div>
              <em><ShieldCheck :size="11" style="vertical-align:-1px" /> Used to verify that webhook calls really come from Meta.</em>
            </label>
          </div>
        </div>
      </div>

      <!-- Step: Add the WhatsApp product / your number -->
      <div class="card wa-step-card" :class="{ done: stepMap.product.done, open: openStep === 'product' }">
        <button type="button" class="wa-step-head" @click="toggleStep('product')">
          <span class="wa-step-num"><Check v-if="stepMap.product.done" :size="14" /><template v-else>{{ stepMap.product.num }}</template></span>
          <span class="wa-step-title">{{ stepMap.product.title }}</span>
          <ChevronDown :size="16" class="wa-chev" />
        </button>
        <div v-show="openStep === 'product'" class="wa-step-body">
          <p class="wa-lead">Adding the free WhatsApp product to your app gives you a sender phone number and the two IDs below.</p>
          <details class="wa-help">
            <summary><HelpCircle :size="14" /> Where do I find this?</summary>
            <ol>
              <li>In your app's dashboard scroll to <b>Add product</b> (or “Add products to your app”), find <b>WhatsApp</b> and click <b>Set up</b>. Meta automatically creates a WhatsApp Business Account for you.</li>
              <li>In the left menu open <b>WhatsApp → API Setup</b> — you'll see a sender (“From”) number.</li>
              <li>Copy <b>Phone number ID</b> (shown right under the number) → paste it below.</li>
              <li>Copy <b>WhatsApp Business account ID</b> (same screen) → paste it below.</li>
              <li>Type the phone number itself into “Your WhatsApp number” below.</li>
              <li v-if="isPlatform"><i>Optional:</i> your <b>Business Manager ID</b> is at <b>business.facebook.com → Business settings → Business info</b>.</li>
            </ol>
          </details>
          <div class="wa-grid">
            <label class="wa-field">
              <span>Phone number ID <Check v-if="filled(cfg.phone_number_id)" :size="13" class="wa-okck" /></span>
              <input class="wa-input" v-model.trim="cfg.phone_number_id" placeholder="e.g. 1194070043783253" spellcheck="false" inputmode="numeric" />
              <em>WhatsApp → API Setup → “Phone number ID”. Not the phone number itself.</em>
            </label>
            <label class="wa-field">
              <span>WhatsApp Business account ID <i class="wa-req">required for marketing</i> <Check v-if="filled(cfg.waba_id)" :size="13" class="wa-okck" /></span>
              <input class="wa-input" :class="{ 'wa-input-need': !filled(cfg.waba_id) }" v-model.trim="cfg.waba_id" placeholder="WABA ID — same API Setup screen" spellcheck="false" inputmode="numeric" />
              <em><b>Templates won't load or send without this.</b> It's on the same WhatsApp → API Setup screen as the Phone number ID, labelled “WhatsApp Business Account ID” (a long number). Paste it, then Save.</em>
            </label>
            <label class="wa-field">
              <span>Your WhatsApp number <i class="wa-opt">optional</i> <Check v-if="filled(cfg.display_number)" :size="13" class="wa-okck" /></span>
              <input class="wa-input" v-model.trim="cfg.display_number" placeholder="e.g. +974 5031 8233" spellcheck="false" />
              <em>The sender number itself — just for display here.</em>
            </label>
            <label v-if="isPlatform" class="wa-field">
              <span>Business Manager ID <i class="wa-opt">optional</i> <Check v-if="filled(cfg.business_id)" :size="13" class="wa-okck" /></span>
              <input class="wa-input" v-model.trim="cfg.business_id" placeholder="business.facebook.com → Business info" spellcheck="false" inputmode="numeric" />
              <em>business.facebook.com → Business settings → Business info.</em>
            </label>
          </div>
        </div>
      </div>

      <!-- Step: Permanent access token -->
      <div class="card wa-step-card" :class="{ done: stepMap.token.done, open: openStep === 'token' }">
        <button type="button" class="wa-step-head" @click="toggleStep('token')">
          <span class="wa-step-num"><Check v-if="stepMap.token.done" :size="14" /><template v-else>{{ stepMap.token.num }}</template></span>
          <span class="wa-step-title">Create a PERMANENT access token</span>
          <ChevronDown :size="16" class="wa-chev" />
        </button>
        <div v-show="openStep === 'token'" class="wa-step-body">
          <p class="wa-lead">The access token is the password used to send your messages. The token Meta shows on the API Setup page <b>expires after 24 hours</b> — don't launch with it. Make a permanent one instead:</p>
          <details class="wa-help">
            <summary><HelpCircle :size="14" /> Where do I find this?</summary>
            <ol>
              <li>Go to <b>business.facebook.com → Business settings</b> (gear icon).</li>
              <li>Open <b>Users → System users</b> and click <b>Add</b>. Name it anything (e.g. “bookboll-sender”), set the role to <b>Admin</b>, and create it.</li>
              <li>Click <b>Add assets → Apps</b>, select the app from step 1, turn on <b>Full control</b> → <b>Save changes</b>.</li>
              <li>Click <b>Generate token</b>, select the same app, and set <b>Token expiration: Never</b> (no expiry).</li>
              <li>Tick <b>both</b> permissions: <code>whatsapp_business_messaging</code> and <code>whatsapp_business_management</code>.</li>
              <li>Click <b>Generate token</b> and copy it right away — Meta shows it only once. Paste it below.</li>
            </ol>
          </details>
          <div class="wa-grid">
            <label class="wa-field wa-col-2">
              <span>Access token <Check v-if="filled(cfg.access_token)" :size="13" class="wa-okck" /></span>
              <div class="wa-pass">
                <input class="wa-input" :type="showToken ? 'text' : 'password'" v-model.trim="cfg.access_token" placeholder="EAAa… (permanent System-User token)" spellcheck="false" autocomplete="off" />
                <button type="button" class="wa-eye" @click="showToken = !showToken"><component :is="showToken ? EyeOff : Eye" :size="15" /></button>
              </div>
              <em v-if="isPlatform"><ShieldCheck :size="11" style="vertical-align:-1px" /> Bookboll stores it and the send function uses it. A developer can alternatively set it as the <code>WHATSAPP_ACCESS_TOKEN</code> function secret — that stays as a fallback.</em>
              <em v-else><ShieldCheck :size="11" style="vertical-align:-1px" /> Stored securely and only used to send your messages.</em>
            </label>
          </div>
          <div v-if="isPlatform && !filled(cfg.access_token) && fnStatus?.tokenSource === 'secret'" class="wa-note ok">
            <Check :size="13" />
            <span>A token is already set as a function secret — you can leave this empty, or paste one here to manage it from the dashboard instead.</span>
          </div>
        </div>
      </div>

      <!-- Step: Webhook (SUPERADMIN ONLY) -->
      <div v-if="isPlatform" class="card wa-step-card" :class="{ done: stepMap.webhook.done, open: openStep === 'webhook' }">
        <button type="button" class="wa-step-head" @click="toggleStep('webhook')">
          <span class="wa-step-num"><Check v-if="stepMap.webhook.done" :size="14" /><template v-else>{{ stepMap.webhook.num }}</template></span>
          <span class="wa-step-title">Connect the webhook <small class="wa-step-sub">inbound messages + the booking bot</small></span>
          <ChevronDown :size="16" class="wa-chev" />
        </button>
        <div v-show="openStep === 'webhook'" class="wa-step-body">
          <p class="wa-lead">The webhook is how Meta delivers incoming customer messages and template-review updates to Bookboll — it powers the inbox and the booking bot. Copy the two values below into Meta.</p>
          <details class="wa-help">
            <summary><HelpCircle :size="14" /> Where do I paste these?</summary>
            <ol>
              <li>Click <b>Generate</b> below (if empty), then <b>Save</b> this page first.</li>
              <li>In your Meta app open <b>WhatsApp → Configuration</b>.</li>
              <li>Next to <b>Webhook</b> click <b>Edit</b>, paste the <b>Callback URL</b> and <b>Verify token</b> exactly as shown here, then click <b>Verify and save</b>.</li>
              <li>Under <b>Webhook fields</b> click <b>Manage</b> and subscribe to <b>messages</b> and <b>message_template_status_update</b>.</li>
            </ol>
          </details>
          <div class="wa-grid">
            <div class="wa-field wa-col-2">
              <span>Callback URL <Check v-if="!!CALLBACK_URL" :size="13" class="wa-okck" /></span>
              <div class="wa-copy-row">
                <input class="wa-input wa-ro" :value="CALLBACK_URL" readonly spellcheck="false" @focus="$event.target.select()" />
                <button type="button" class="btn btn-secondary" @click="copyCmd(CALLBACK_URL, 'cb')"><Check v-if="copied === 'cb'" :size="14" /><Copy v-else :size="14" /> Copy</button>
              </div>
              <em>Paste into Meta exactly as shown.</em>
            </div>
            <div class="wa-field wa-col-2">
              <span>Verify token <Check v-if="filled(cfg.verify_token)" :size="13" class="wa-okck" /></span>
              <div class="wa-copy-row">
                <input class="wa-input" v-model.trim="cfg.verify_token" placeholder="Click Generate →" spellcheck="false" />
                <button v-if="!filled(cfg.verify_token)" type="button" class="btn btn-secondary" @click="genVerify">Generate</button>
                <button v-else type="button" class="btn btn-secondary" @click="copyCmd(cfg.verify_token, 'vt')"><Check v-if="copied === 'vt'" :size="14" /><Copy v-else :size="14" /> Copy</button>
              </div>
              <em>Any secret phrase works — Generate makes a strong one.</em>
            </div>
          </div>
          <div class="wa-note warn">
            <Info :size="13" />
            <span><b>Save this page BEFORE clicking “Verify and save” in Meta.</b> Meta checks the verify token against what's stored here — if you haven't saved yet, verification fails.</span>
          </div>
          <button class="btn btn-primary wa-inline-save" :disabled="saving" @click="save">
            <Loader v-if="saving" :size="14" class="wa-spin" /> {{ saving ? 'Saving…' : 'Save now' }}
          </button>
        </div>
      </div>

      <!-- Step: Save & test -->
      <div class="card wa-step-card" :class="{ done: stepMap.test.done, open: openStep === 'test' }">
        <button type="button" class="wa-step-head" @click="toggleStep('test')">
          <span class="wa-step-num"><Check v-if="stepMap.test.done" :size="14" /><template v-else>{{ stepMap.test.num }}</template></span>
          <span class="wa-step-title">Save &amp; test</span>
          <ChevronDown :size="16" class="wa-chev" />
        </button>
        <div v-show="openStep === 'test'" class="wa-step-body">
          <p class="wa-lead">Save your details, recheck the connection, then send yourself a real test message.</p>
          <div class="wa-save-row">
            <button class="btn btn-primary" :disabled="saving" @click="save">
              <Loader v-if="saving" :size="14" class="wa-spin" /> {{ saving ? 'Saving…' : 'Save settings' }}
            </button>
            <button class="btn btn-secondary" :disabled="checking" @click="refreshStatus"><RefreshCw :size="13" /> Recheck connection</button>
            <span v-if="connectedOk" class="wa-chip ok"><Check :size="12" /> Connected</span>
          </div>

          <div class="wa-sec wa-sec-gap"><Send :size="14" /> Send a test message</div>
          <div class="wa-test">
            <input class="wa-input" v-model.trim="testTo" placeholder="97450318233 (digits, with country code)" spellcheck="false" />
            <input class="wa-input wa-test-tpl" v-model.trim="testTemplate" placeholder="hello_world" spellcheck="false" title="Template name" />
            <input class="wa-input wa-test-lang" v-model.trim="testLang" placeholder="en_US" spellcheck="false" title="Template language" />
            <button class="btn btn-primary" :disabled="testing" @click="sendTest">
              <Loader v-if="testing" :size="14" class="wa-spin" /><Send v-else :size="14" /> {{ testing ? 'Sending…' : 'Send test' }}
            </button>
          </div>
          <div class="wa-note">
            <Info :size="13" />
            <span>Sends a <b>real</b> WhatsApp message using the built-in <code>hello_world</code> template. While your Meta app is in test mode, only numbers on your test-recipient list can receive it — add yours on WhatsApp → API Setup (the “To” field).</span>
          </div>

          <div class="wa-sec wa-sec-gap"><MessageSquare :size="14" /> Recent sends</div>
          <div v-if="outboundMissing" class="wa-note"><Info :size="13" /> <span>Run the <code>20260730000000_whatsapp_channel</code> migration to enable the send log.</span></div>
          <div v-else-if="!outbound.length" class="wa-empty">Nothing sent yet — try a test message above.</div>
          <div v-else class="table-scroll">
            <table class="wa-log">
              <thead><tr><th>To</th><th>Type</th><th>Message</th><th>Status</th><th>When</th></tr></thead>
              <tbody>
                <tr v-for="(m, i) in outbound" :key="i">
                  <td class="wa-log-to">{{ m.to_number }}</td>
                  <td>{{ m.template_name || m.kind }}</td>
                  <td class="wa-log-body">{{ m.body }}</td>
                  <td><span class="wa-badge" :class="m.status">{{ statusLabel(m.status) }}</span><em v-if="m.error" class="wa-err" :title="m.error">why?</em></td>
                  <td class="wa-log-when">{{ fmtWhen(m.created_at) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- D) Where the keys take effect (platform only) -->
    <div v-if="isPlatform" class="wa-amber">
      <AlertTriangle :size="16" />
      <div class="wa-amber-body">
        <b>Note:</b> keys entered on this page take effect immediately — they are read by the Bookboll
        backend on every send. Leave the access token blank to fall back to the server's
        <code>WHATSAPP_ACCESS_TOKEN</code> setting instead.
      </div>
    </div>

    <!-- C) Author + submit message templates and track queued auto-send campaigns -->
    <WaTemplateManager />
  </div>
</template>

<style scoped>
.page-sub { color: var(--text-muted); font-size: 13.5px; margin-top: 4px; max-width: 640px; }
.wa-spin { animation: waspin 1s linear infinite; }
@keyframes waspin { to { transform: rotate(360deg); } }

.wa-uses { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; margin: 14px 0; }
.wa-uses.single { grid-template-columns: 1fr; max-width: 420px; }
@media (max-width: 800px) { .wa-uses { grid-template-columns: 1fr; } }
.wa-use { display: flex; gap: 11px; align-items: flex-start; background: var(--surface, #ffffff); border: 1px solid var(--border); border-radius: 12px; padding: 13px 15px; }
.wa-use-ic { width: 30px; height: 30px; border-radius: 9px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.wa-use-ic.on { background: #dcfce7; color: #16a34a; }
.wa-use-ic.dim { background: var(--bg); color: var(--text-muted); }
.wa-use b { font-size: 13.5px; display: block; }
.wa-use small { font-size: 12px; color: var(--text-muted); line-height: 1.45; }

.wa-card { padding: 16px 20px; margin-bottom: 14px; }

/* Connection checklist header */
.wa-check-top { display: flex; align-items: center; justify-content: space-between; gap: 14px; flex-wrap: wrap; }
.wa-prog { min-width: 200px; }
.wa-prog-label { display: flex; align-items: baseline; gap: 8px; margin-bottom: 7px; }
.wa-prog-label b { font-size: 14.5px; }
.wa-prog-count { font-size: 12px; font-weight: 700; color: var(--text-muted); }
.wa-prog-bar { width: 220px; max-width: 100%; height: 7px; background: var(--border, #e2e8f0); border-radius: 999px; overflow: hidden; }
.wa-prog-bar i { display: block; height: 100%; background: #25d366; border-radius: 999px; transition: width .3s; }
.wa-pill { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 800; border-radius: 999px; padding: 7px 15px; }
.wa-pill.ok { background: #dcfce7; color: #166534; }
.wa-pill.off { background: var(--bg); color: var(--text-muted); border: 1px solid var(--border); }
.wa-pill.busy { background: var(--bg); color: var(--text-muted); }
.wa-check-sub { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; border-top: 1px solid var(--border); margin-top: 13px; padding-top: 12px; }
.wa-status-main { display: flex; align-items: center; gap: 8px; flex: 1; min-width: 0; font-size: 13px; flex-wrap: wrap; }
.wa-quality { margin-left: 8px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .03em; color: var(--text-muted); background: var(--bg); border-radius: 999px; padding: 2px 8px; }
.wa-chip { font-size: 10.5px; font-weight: 800; text-transform: uppercase; letter-spacing: .03em; color: var(--text-muted); background: var(--bg); border: 1px solid var(--border); border-radius: 999px; padding: 2px 9px; white-space: nowrap; }
.wa-chip.ok { background: #dcfce7; color: #166534; border-color: #86efac; display: inline-flex; align-items: center; gap: 4px; }

/* Wizard step cards */
.wa-wizard { display: flex; flex-direction: column; gap: 10px; margin-bottom: 14px; }
.wa-step-card { padding: 0; overflow: hidden; }
.wa-step-head { width: 100%; display: flex; align-items: center; gap: 12px; padding: 13px 16px; min-height: 50px; background: none; border: none; cursor: pointer; text-align: left; font: inherit; color: var(--text); }
.wa-step-head:hover { background: var(--bg); }
.wa-step-num { width: 27px; height: 27px; border-radius: 50%; background: var(--bg); border: 1px solid var(--border); display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 12.5px; flex-shrink: 0; color: var(--text-muted); }
.wa-step-card.done .wa-step-num { background: #dcfce7; border-color: #86efac; color: #16a34a; }
.wa-step-title { flex: 1; font-weight: 700; font-size: 14px; line-height: 1.35; }
.wa-step-sub { display: block; font-weight: 600; font-size: 11.5px; color: var(--text-muted); }
.wa-chev { color: var(--text-muted); flex-shrink: 0; transition: transform .15s; }
.wa-step-card.open .wa-chev { transform: rotate(180deg); }
.wa-step-body { border-top: 1px solid var(--border); padding: 14px 16px 16px; }
.wa-lead { font-size: 13px; color: var(--text-muted); line-height: 1.55; margin: 0 0 11px; }
.wa-lead b { color: var(--text); }

/* "Where do I find this?" help */
.wa-help { background: var(--bg); border: 1px solid var(--border); border-radius: 10px; margin-bottom: 13px; font-size: 12.5px; }
.wa-help summary { display: flex; align-items: center; gap: 7px; padding: 10px 13px; min-height: 40px; cursor: pointer; font-weight: 700; color: var(--text); list-style: none; user-select: none; }
.wa-help summary::-webkit-details-marker { display: none; }
.wa-help ol { margin: 0; padding: 0 16px 12px 32px; line-height: 1.75; color: var(--text); }
.wa-help li + li { margin-top: 3px; }
.wa-help code { background: var(--surface, #ffffff); border: 1px solid var(--border); border-radius: 5px; padding: 1px 5px; font-size: 11.5px; }

.wa-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.wa-col-2 { grid-column: span 2; }
@media (max-width: 720px) { .wa-grid { grid-template-columns: 1fr; } .wa-col-2 { grid-column: span 1; } }
.wa-field { display: flex; flex-direction: column; gap: 5px; }
.wa-field > span { display: flex; align-items: center; gap: 5px; font-size: 11.5px; font-weight: 700; color: var(--text-muted); }
.wa-okck { color: #16a34a; flex-shrink: 0; }
.wa-opt { font-style: normal; font-weight: 600; color: var(--text-light, #94a3b8); text-transform: none; }
.wa-req { font-style: normal; font-weight: 700; color: #b45309; background: #fffbeb; border: 1px solid #fde68a; border-radius: 999px; padding: 1px 7px; text-transform: none; font-size: 11px; }
.wa-input-need { border-color: #fcd34d; background: #fffdf5; }
.wa-warn { display: flex; align-items: flex-start; gap: 7px; font-size: 12.5px; color: #92400e; background: #fffbeb; border: 1px solid #fde68a; border-radius: 10px; padding: 10px 12px; margin-top: 10px; line-height: 1.5; }
.wa-warn svg { flex-shrink: 0; margin-top: 1px; }
.wa-warn b { color: #0f172a; }
.wa-field em { font-size: 11.5px; color: var(--text-light, #94a3b8); font-style: normal; }
.wa-field em code { font-size: 10.5px; }
.wa-input { width: 100%; border: 1px solid var(--border); border-radius: 10px; padding: 9px 12px; font-size: 13.5px; background: var(--surface, #ffffff); font-family: inherit; }
.wa-input:focus { outline: none; border-color: #25d366; }
.wa-input.wa-ro { background: var(--bg); color: var(--text-muted); font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12.5px; }
.wa-pass { position: relative; }
.wa-pass .wa-input { padding-right: 38px; }
.wa-eye { position: absolute; right: 6px; top: 50%; transform: translateY(-50%); border: none; background: transparent; color: var(--text-light, #94a3b8); cursor: pointer; padding: 4px; display: inline-flex; }
.wa-eye:hover { color: var(--text); }
.wa-copy-row { display: flex; gap: 8px; }
.wa-copy-row .wa-input { flex: 1; min-width: 0; }
.wa-copy-row .btn { flex-shrink: 0; }
.wa-inline-save { margin-top: 12px; }
.wa-save-row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }

.wa-note { display: flex; align-items: flex-start; gap: 6px; font-size: 12px; color: var(--text-muted); margin-top: 10px; line-height: 1.55; }
.wa-note svg { flex-shrink: 0; margin-top: 2px; }
.wa-note.ok { color: #166534; }
.wa-note.warn { color: #92400e; background: #fffbeb; border: 1px solid #fde68a; border-radius: 10px; padding: 10px 12px; }
.wa-note code { background: var(--bg); border: 1px solid var(--border); border-radius: 5px; padding: 1px 5px; font-size: 11px; }

.wa-sec { display: flex; align-items: center; gap: 6px; font-size: 11.5px; font-weight: 800; text-transform: uppercase; letter-spacing: .04em; color: var(--text-muted); margin-bottom: 12px; }
.wa-sec-gap { margin-top: 18px; }

/* Amber deployment note */
.wa-amber { display: flex; gap: 11px; align-items: flex-start; background: #fffbeb; border: 1px solid #fde68a; border-radius: 12px; padding: 13px 15px; margin-bottom: 14px; color: #92400e; font-size: 12.5px; line-height: 1.6; }
.wa-amber > svg { flex-shrink: 0; margin-top: 2px; }
.wa-amber-body { flex: 1; min-width: 0; }
.wa-amber-body code { background: #fef3c7; border: 1px solid #fde68a; border-radius: 5px; padding: 1px 5px; font-size: 11.5px; }
.wa-cmd { position: relative; background: #0f172a; border-radius: 10px; padding: 12px 40px 12px 14px; margin-top: 9px; }
.wa-cmd pre { margin: 0; color: var(--border, #e2e8f0); font-size: 12px; line-height: 1.7; white-space: pre-wrap; word-break: break-all; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
.wa-cmd .btn-icon { position: absolute; top: 8px; right: 8px; color: var(--text-light, #94a3b8); }

.wa-test { display: flex; gap: 8px; flex-wrap: wrap; }
.wa-test .wa-input { max-width: 260px; }
.wa-test-tpl { max-width: 170px !important; }
.wa-test-lang { max-width: 90px !important; }

.wa-empty { font-size: 13px; color: var(--text-muted); }
.wa-log { width: 100%; border-collapse: collapse; font-size: 12.5px; }
.wa-log th { font-size: 10.5px; }
.wa-log td, .wa-log th { padding: 8px 10px; }
.wa-log-to { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; white-space: nowrap; }
.wa-log-body { max-width: 340px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: var(--text-muted); }
.wa-log-when { white-space: nowrap; color: var(--text-muted); }
.wa-badge { font-size: 10.5px; font-weight: 800; text-transform: uppercase; letter-spacing: .03em; border-radius: 999px; padding: 2px 8px; }
.wa-badge.sent { background: #dbeafe; color: #1e40af; }
.wa-badge.delivered { background: #dcfce7; color: #166534; }
.wa-badge.read { background: #ccfbf1; color: #0f766e; }
.wa-badge.failed { background: #fee2e2; color: #b91c1c; }
.wa-err { margin-left: 6px; font-size: 11px; color: #b91c1c; cursor: help; font-style: normal; text-decoration: underline dotted; }

/* Phone (≤600px) — 344px-safe tweaks, mobile only */
@media (max-width: 600px) {
  .wa-input, .wa-input.wa-ro { font-size: 16px; }    /* iOS: prevent zoom-on-focus (incl. readonly callback URL, which is focusable) */
  .wa-card { padding: 14px; }
  .wa-step-head { padding: 13px 14px; }
  .wa-step-body { padding: 13px 14px 15px; }
  .wa-eye { padding: 10px 8px; }                     /* bigger touch target, still absolute */
  .wa-check-top { flex-direction: column; align-items: stretch; }
  .wa-prog-bar { width: 100%; }
  .wa-pill { justify-content: center; min-height: 40px; }
  .wa-check-sub .btn { width: 100%; justify-content: center; min-height: 44px; }
  .wa-copy-row { flex-wrap: wrap; }
  .wa-copy-row .btn { flex: 1; min-height: 44px; justify-content: center; }
  .wa-save-row .btn, .wa-inline-save { width: 100%; min-height: 44px; justify-content: center; }
  .wa-test { flex-direction: column; }
  .wa-test .wa-input, .wa-test-tpl, .wa-test-lang { max-width: 100% !important; }
  .wa-test .btn { width: 100%; min-height: 44px; justify-content: center; }
  .table-scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; }   /* undefined on this page → table must scroll in its own box, not the page */
  .wa-log-body { max-width: 200px; }
}
</style>
