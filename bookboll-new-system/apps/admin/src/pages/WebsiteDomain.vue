<script setup>
/**
 * WebsiteDomain.vue — "My Website → Custom domain".
 *
 * Moved out of Brand.vue so the main Site page stays calm. A provider connects their
 * own web address (e.g. book.myclub.com) instead of the Bookboll subdomain, for a
 * one-time fee. Flow: enter domain → pay $119 in Stripe → add the CNAME record →
 * verify → live.
 *
 * The fee is a real charge. The domain is NOT written when the button is pressed —
 * Stripe takes the payment and the backend connects it on the way back (and from the
 * webhook), so an abandoned checkout leaves nothing behind.
 */
import { ref, reactive, computed, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { loadSiteSettings, saveSiteSettings } from '../lib/siteSettings.js'
import { startDomainCheckout, confirmDomainCheckout, verifyDomain as verifyDomainApi } from '../lib/plan.js'
import { firstPlanError } from '../lib/premium.js'
import { useCurrentUser } from '../lib/currentUser.js'
import { useToast } from '../lib/toast.js'
import { brandHost } from '../lib/brand.js'
import { Link2, Globe2, Check, X, Loader, CreditCard, ShieldCheck, ArrowLeft } from 'lucide-vue-next'

const { currentOwnerUserId } = useCurrentUser()
const toast = useToast()
const route = useRoute()
const router = useRouter()
const DOMAIN_FEE = 119

const ownerId = computed(() => currentOwnerUserId.value)
const loading = ref(true)
const f = reactive({ brand_subdomain: '', brand_custom_domain: '', brand_domain_status: '', brand_domain_paid_at: null })

async function load() {
  loading.value = true
  if (!ownerId.value) { loading.value = false; return }
  const { settings, username } = await loadSiteSettings()
  f.brand_subdomain = String(settings.brand_subdomain || username || '').toLowerCase()
  f.brand_custom_domain = settings.brand_custom_domain || ''
  f.brand_domain_status = settings.brand_domain_status || ''
  f.brand_domain_paid_at = settings.brand_domain_paid_at || null
  loading.value = false
}
onMounted(load)
watch(ownerId, load)

const bookbollAddress = computed(() => brandHost(f.brand_subdomain || 'yourbusiness'))

// ── Connect (real one-time fee, taken by Stripe) ────────────────────────────────
const domainInput = ref('')
const DOMAIN_RE = /^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i
const domainValid = computed(() => DOMAIN_RE.test((domainInput.value || '').trim().toLowerCase()))
const savingDomain = ref(false)

async function payAndConnectDomain() {
  const domain = (domainInput.value || '').trim().toLowerCase().replace(/^https?:\/\//, '').replace(/\/.*$/, '')
  if (!DOMAIN_RE.test(domain)) { toast.error('Enter a valid domain like book.myclub.com'); return }
  if (!ownerId.value) return
  savingDomain.value = true
  const res = await startDomainCheckout(domain, '/website-domain')
  if (!res.success || !res.data?.url) {
    savingDomain.value = false
    toast.error(firstPlanError(res, 'Could not start the payment.'))
    return
  }
  // Hand the browser to Stripe; the domain is connected on the way back.
  window.location.assign(res.data.url)
}

/** Back from Stripe — reconcile the session, since the webhook may not have landed. */
async function handleReturn(session) {
  if (!session) return
  const clean = () => {
    const query = { ...route.query }
    delete query.domain_checkout
    router.replace({ path: route.path, query })
  }
  if (session === 'cancelled') {
    toast.info('Payment cancelled — nothing was charged and no domain was connected.')
    clean()
    return
  }
  const res = await confirmDomainCheckout(String(session))
  if (!res.success) { toast.error(firstPlanError(res, 'We could not confirm that payment yet.')); clean(); return }
  f.brand_custom_domain = res.data?.domain || ''
  f.brand_domain_status = res.data?.status || 'pending'
  f.brand_domain_paid_at = res.data?.paid_at || null
  domainInput.value = ''
  toast.success(`Domain connected — $${DOMAIN_FEE} one-time fee paid. Add the DNS record to finish.`)
  clean()
}
watch(() => route.query.domain_checkout, handleReturn, { immediate: true })

const verifying = ref(false)
async function verifyDomain() {
  if (!ownerId.value || verifying.value) return
  verifying.value = true
  // A real CNAME lookup on the server — "Live" means the address actually resolves.
  const res = await verifyDomainApi()
  verifying.value = false
  if (!res.success) { toast.error(firstPlanError(res, 'Could not verify the domain yet.')); return }
  f.brand_domain_status = 'active'
  toast.success('Domain verified — your site is live on your own address.')
}
async function removeDomain() {
  if (!ownerId.value) return
  const res = await saveSiteSettings({ brand_custom_domain: '', brand_domain_status: '' })
  if (!res.success) { toast.error(res.message || 'Could not remove.'); return }
  f.brand_custom_domain = ''; f.brand_domain_status = ''; f.brand_domain_paid_at = null
  toast.success('Custom domain removed.')
}
</script>

<template>
  <div class="page">
    <div class="page-header">
      <div>
        <div class="page-title">Custom domain</div>
        <div class="page-subtitle">Put your website on your own address instead of a Bookboll one.</div>
      </div>
      <router-link to="/brand" class="btn btn-secondary btn-sm"><ArrowLeft :size="14" /> Back to Site</router-link>
    </div>

    <div v-if="!ownerId" class="wd-empty">
      <Globe2 :size="30" />
      <p>Custom domains belong to a provider business — switch to a provider with the role switcher.</p>
    </div>
    <div v-else-if="loading" class="wd-empty"><Loader :size="20" class="spin" /> Loading…</div>

    <div v-else class="wd-wrap">
      <!-- Now vs after -->
      <div class="card wd-card">
        <div class="wd-compare">
          <div class="wd-cmp">
            <span class="wd-cmp-label">Your address today</span>
            <span class="wd-cmp-host">{{ bookbollAddress }}</span>
          </div>
          <span class="wd-cmp-arrow">→</span>
          <div class="wd-cmp on">
            <span class="wd-cmp-label">With your own domain</span>
            <span class="wd-cmp-host">{{ f.brand_custom_domain || 'book.myclub.com' }}</span>
          </div>
        </div>
        <div class="wd-hint">Your Bookboll address keeps working either way — links you've already shared never break.</div>
      </div>

      <!-- Not connected → connect + pay -->
      <div v-if="!f.brand_custom_domain" class="card wd-card">
        <div class="wd-title"><Link2 :size="15" /> Connect your domain</div>
        <div class="wd-sub">One-time setup fee of <strong>${{ DOMAIN_FEE }}</strong> — no monthly cost. You need to own the domain already.</div>
        <div class="wd-form">
          <input class="wd-input" v-model="domainInput" placeholder="book.myclub.com" spellcheck="false" @keyup.enter="domainValid && payAndConnectDomain()" />
          <button class="btn btn-primary" :disabled="!domainValid || savingDomain" @click="payAndConnectDomain">
            <Loader v-if="savingDomain" :size="15" class="spin" /><CreditCard v-else :size="15" />
            {{ savingDomain ? 'Opening checkout…' : `Pay $${DOMAIN_FEE} & connect` }}
          </button>
        </div>
        <div class="wd-hint">You'll be taken to Stripe to pay securely. The domain connects as soon as the payment goes through.</div>
      </div>

      <!-- Connected → status + DNS instructions -->
      <div v-else class="card wd-card">
        <div class="wd-title"><Globe2 :size="15" /> {{ f.brand_custom_domain }}
          <span class="wd-badge" :class="f.brand_domain_status === 'active' ? 'ok' : 'pending'">
            <Check v-if="f.brand_domain_status === 'active'" :size="12" /><Loader v-else :size="12" class="spin" />
            {{ f.brand_domain_status === 'active' ? 'Live' : 'Pending DNS' }}
          </span>
        </div>
        <div v-if="f.brand_domain_status !== 'active'" class="wd-dns">
          <div class="wd-dns-title">One step left — add this record where you bought your domain:</div>
          <div class="wd-dns-rec"><span>Type</span><b>CNAME</b></div>
          <div class="wd-dns-rec"><span>Name</span><b>{{ (f.brand_custom_domain || '').split('.')[0] }}</b></div>
          <div class="wd-dns-rec"><span>Value</span><b>sites.bookboll.com</b></div>
          <button class="btn btn-primary" style="margin-top:12px" :disabled="verifying" @click="verifyDomain">
            <Loader v-if="verifying" :size="15" class="spin" /><ShieldCheck v-else :size="15" />
            {{ verifying ? 'Checking DNS…' : "I've added it — verify" }}
          </button>
        </div>
        <div v-else class="wd-hint" style="color:#166534;">Your site is live on your own address. 🎉</div>
        <button class="btn btn-secondary btn-sm" style="margin-top:14px" @click="removeDomain"><X :size="13" /> Remove domain</button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.wd-empty { display: flex; flex-direction: column; align-items: center; gap: 10px; padding: 60px 20px; color: var(--text-muted); text-align: center; }
.wd-wrap { display: flex; flex-direction: column; gap: 14px; max-width: 640px; }
.wd-card { padding: 18px 20px; }
.wd-compare { display: flex; align-items: stretch; gap: 12px; flex-wrap: wrap; }
.wd-cmp { flex: 1; min-width: 200px; display: flex; flex-direction: column; gap: 4px; border: 1.5px solid var(--border); border-radius: 12px; padding: 12px 14px; }
.wd-cmp.on { border-color: var(--primary); background: #f0fdfa; }
.wd-cmp-label { font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: .04em; color: var(--text-muted); }
.wd-cmp-host { font-size: 14.5px; font-weight: 800; color: var(--text); word-break: break-all; }
.wd-cmp-arrow { align-self: center; font-size: 18px; color: var(--text-light, #94a3b8); }
.wd-hint { font-size: 12px; color: var(--text-muted); margin-top: 10px; line-height: 1.5; }
.wd-title { display: flex; align-items: center; gap: 8px; font-size: 15px; font-weight: 800; color: var(--text); flex-wrap: wrap; }
.wd-title svg { color: var(--primary); }
.wd-sub { font-size: 13px; color: var(--text-muted); margin-top: 4px; }
.wd-form { display: flex; gap: 10px; margin-top: 14px; flex-wrap: wrap; }
.wd-input { flex: 1; min-width: 200px; padding: 10px 13px; border: 1.5px solid var(--border); border-radius: 10px; font-size: 14px; }
.wd-input:focus { outline: none; border-color: var(--primary); }
.wd-badge { display: inline-flex; align-items: center; gap: 5px; font-size: 11px; font-weight: 800; border-radius: 999px; padding: 3px 10px; }
.wd-badge.ok { background: #dcfce7; color: #166534; }
.wd-badge.pending { background: #fef3c7; color: #92400e; }
.wd-dns { margin-top: 14px; background: #f8fafc; border: 1px solid var(--border); border-radius: 12px; padding: 14px 16px; }
.wd-dns-title { font-size: 13px; font-weight: 700; color: var(--text); margin-bottom: 10px; }
.wd-dns-rec { display: flex; gap: 12px; font-size: 13px; padding: 4px 0; }
.wd-dns-rec span { width: 52px; color: var(--text-muted); }
.spin { animation: wdspin 1s linear infinite; }
@keyframes wdspin { to { transform: rotate(360deg); } }

/* ── Phone (≤600px) ───────────────────────────────────────────────────────── */
@media (max-width: 600px) {
  .wd-card { padding: 14px; }
  .wd-dns { padding: 12px; }
  .wd-input { font-size: 16px; }
  .wd-form { flex-direction: column; }
  .wd-form .wd-input,
  .wd-form .btn { width: 100%; }
  .wd-form .btn { min-height: 44px; justify-content: center; }
  .wd-dns .btn { width: 100%; min-height: 44px; justify-content: center; }
  .wd-cmp-arrow { transform: rotate(90deg); align-self: center; }
  .wd-dns-rec b { word-break: break-all; min-width: 0; }
  .wd-title { word-break: break-all; overflow-wrap: anywhere; }
  .wd-card .btn-sm,
  .page-header .btn-sm { min-height: 40px; }
}
</style>
