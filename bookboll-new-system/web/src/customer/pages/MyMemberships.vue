<script setup>
/**
 * MyMemberships.vue — ONE page for everything membership (/my-memberships).
 * Top: the membership PASS (one QR member card, or legacy per-membership cards)
 * that staff scan at the door. Below: the manage list — plan, price, renewal,
 * cancel. The old /pass page redirects here.
 */
import { ref, onMounted, onBeforeUnmount, watch, computed } from 'vue'
import { customer, isCustomer } from '../../lib/customerSession.js'
import { ensureSignedIn } from '../../lib/authGate.js'
import { loadMySubscriptions, cancelMySubscription } from '../../customer/data.js'
import { loadCustomerPasses, loadMemberPassBundle, passQrDataUrl } from '../../lib/entryPass.js'
import { Dumbbell, CreditCard, CalendarClock, Gift, QrCode, ShieldCheck, AlertTriangle, RefreshCw } from 'lucide-vue-next'
import { useSeo } from '../../lib/seo.js'
import PersonalPage from '../components/PersonalPage.vue'
useSeo({ title: 'My memberships', noindex: true, path: '/my-memberships' })

const subs = ref([])
const loading = ref(true)
const passes = ref([])   // legacy fallback: [{ pass, kind: 'membership', title, status, qr }]
const bundle = ref(null) // one-QR member card: { pass, qr, memberships: [...] }

async function load() {
  if (!isCustomer.value) { subs.value = []; passes.value = []; bundle.value = null; loading.value = false; return }
  loading.value = true
  const [mySubs, b] = await Promise.all([
    loadMySubscriptions(customer.value.id, customer.value.email),
    loadMemberPassBundle({ customerId: customer.value?.id, email: customer.value?.email }),
  ])
  subs.value = mySubs
  // ONE member card covering every membership. Pre-migration (member pass kind
  // not enabled yet) → fall back to the legacy one-card-per-membership view.
  if (b.pass) {
    b.qr = await passQrDataUrl(b.pass.code)
    bundle.value = b
    passes.value = []
  } else {
    bundle.value = null
    const rows = await loadCustomerPasses({ customerId: customer.value?.id, email: customer.value?.email })
    for (const r of rows) r.qr = await passQrDataUrl(r.pass.code)
    passes.value = rows
  }
  loading.value = false
}
onMounted(load)
watch(() => customer.value?.id, load)

// Re-check on focus: a just-renewed / just-cancelled state should reflect at the
// door without a manual reload (the member may have fixed payment on another tab).
function onVisible() { if (document.visibilityState === 'visible' && isCustomer.value) load() }
onMounted(() => document.addEventListener('visibilitychange', onVisible))
onBeforeUnmount(() => document.removeEventListener('visibilitychange', onVisible))

const cancelling = ref('')
async function cancelSub(s) {
  // Say what will actually happen. A trial ends on the spot (nothing was paid);
  // a paid membership runs to the end of the period the member already bought.
  const inTrial = s.trialStatus === 'in_trial'
  const msg = inTrial
    ? `Cancel your ${s.name} membership? Your free trial ends now, your pass is removed and no payment will be taken. This can't be undone.`
    : `Cancel your ${s.name} membership? No further payments will be collected. You keep access until ${fmtDate(s.endsAt || s.nextBilling) || 'the end of the current period'}. This can't be undone.`
  if (!window.confirm(msg)) return
  cancelling.value = s.id
  const { error } = await cancelMySubscription(s.id)
  cancelling.value = ''
  if (error) { window.alert(error); return }
  await load()
}

const active = computed(() => subs.value.filter(s => s.status !== 'cancelled'))
const ended = computed(() => subs.value.filter(s => s.status === 'cancelled'))
const hasPass = computed(() => !!bundle.value || passes.value.length > 0)
function fmtDate(d) { return d ? new Date(d + 'T00:00:00').toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : '' }
function fmtD(d) { return d ? new Date(String(d).slice(0, 10) + 'T00:00:00').toLocaleDateString('en-US', { month: 'short', day: 'numeric' }) : '' }
function statusLabel(s) {
  if (s.status === 'cancelled') return 'Cancelled'
  if (s.cancelPending) return 'Cancelling'
  if (s.trialStatus === 'in_trial') return 'Free trial'
  return { active: 'Active', past_due: 'Payment overdue', paused: 'Paused', expired: 'Expired', pending: 'Pending' }[s.status] || s.status || 'Status unavailable'
}

// A membership that has been cancelled but still runs to the end of the period
// the member already paid for.
function endLabel(s) {
  const when = s.endsAt || s.nextBilling || s.trialEndsAt
  return when ? `Access until ${fmtDate(when)}` : 'Cancelled — no further payments'
}

// Retry a QR that failed to render (network blip at the door).
async function retryBundleQr() { if (bundle.value) bundle.value.qr = await passQrDataUrl(bundle.value.pass.code) }
async function retryQr(p) { p.qr = await passQrDataUrl(p.pass.code) }

// Human, actionable state for a non-active membership on a legacy pass card.
function stateInfo(status) {
  if (status === 'past_due') return { cls: 'bad', text: 'Payment failed — update your card to keep access.' }
  if (status === 'paused')   return { cls: 'warn', text: 'Membership paused — resume it to check in.' }
  if (status === 'cancelled') return { cls: 'bad', text: 'Membership cancelled.' }
  return null
}

async function signIn() { if (await ensureSignedIn('see your memberships')) load() }
</script>

<template>
  <PersonalPage title="My memberships" description="Manage your plans, check renewal dates and find your entry pass.">
    <template #actions><router-link to="/programs" class="personal-button primary">Browse memberships</router-link></template>
    <div v-if="!isCustomer" class="mm-signin">
      <ShieldCheck :size="30" />
      <p>Sign in to see your memberships and your door pass.</p>
      <button type="button" class="mm-cta" @click="signIn">Sign in</button>
    </div>
    <div v-else-if="loading" class="cmsg">Loading…</div>
    <template v-else>


      <div v-if="!subs.length && !hasPass" class="personal-empty">
        <Dumbbell :size="40" />
        <h2>A regular place to play</h2><p>Find a plan that fits your routine. Once you join, your membership details and entry pass will be ready here.</p>
        <router-link to="/programs" class="personal-button primary">Explore membership plans</router-link>
      </div>

      <div v-else class="mm-workspace" :class="{ 'with-pass': hasPass }">
        <!-- ── The PASS: what you show at the door ── -->
        <section v-if="hasPass" class="mm-pass-panel">
          <h2 class="mm-h2 mm-h2-first"><QrCode :size="16" /> Membership pass</h2>

          <!-- The photo IS the identity check — nudge members who haven't set one -->
          <router-link v-if="!customer.avatar_url" to="/account" class="mp-photo-nudge">
            <ShieldCheck :size="16" />
            <span><b>Add a profile photo</b> — staff use it to confirm the pass is really yours. Without one you may be asked for ID.</span>
          </router-link>

          <!-- ONE member card: a single QR covering every membership -->
          <div v-if="bundle" class="mp-list">
            <div class="mp-card">
              <div class="mp-card-head">
                <span class="mp-kind membership"><Dumbbell :size="12" /> Member card</span>
              </div>
              <div class="mp-member">
                <div v-if="customer.avatar_url" class="mp-member-photo" :style="{ backgroundImage: `url(${customer.avatar_url})` }"></div>
                <div v-else class="mp-member-photo mp-member-nophoto">{{ (customer.full_name || '?').trim().split(' ').map(s => s[0]).slice(0, 2).join('').toUpperCase() }}</div>
                <div class="mp-member-info">
                  <div class="mp-member-name" data-no-translate>{{ customer.full_name }}</div>
                  <div class="mp-member-lbl">Member</div>
                </div>
              </div>
              <div class="mp-qr-wrap">
                <img v-if="bundle.qr" :src="bundle.qr" class="mp-qr" alt="Member card QR code" />
                <button v-else type="button" class="mp-qr-retry" @click="retryBundleQr"><RefreshCw :size="16" /> Tap to load QR</button>
                <div class="mp-code" :aria-label="`Pass code ${bundle.pass.code}`" data-no-translate>{{ bundle.pass.code }}</div>
              </div>
              <!-- Every membership this one card covers -->
              <div class="mp-mems">
                <div v-for="m in bundle.memberships" :key="m.sub.id" class="mp-mem">
                  <span class="mp-mem-name">{{ m.title }}</span>
                  <span class="mp-mem-chips">
                    <em class="mp-chip" :class="m.status === 'trial' ? 'trial' : (['paused', 'past_due', 'cancelled'].includes(m.status) ? 'warn' : 'ok')">{{ m.status === 'trial' ? 'Free trial' : m.status }}</em>
                    <em v-if="m.nextBilling" class="mp-chip soft">{{ m.status === 'trial' ? 'First payment' : 'Paid until' }} {{ fmtD(m.nextBilling) }}</em>
                    <em v-if="m.joiningFee > 0" class="mp-chip" :class="m.feePaid ? 'ok' : 'warn'">{{ m.feePaid ? 'Joining fee paid' : 'Joining fee due' }}</em>
                  </span>
                </div>
              </div>
              <div class="mp-hint">One card for all your memberships — staff scan it and check the photo matches you.</div>
            </div>
          </div>

          <!-- Legacy fallback: one card per membership -->
          <div v-else class="mp-list">
            <div v-for="p in passes" :key="p.pass.id" class="mp-card">
              <div class="mp-card-head">
                <span class="mp-kind membership"><Dumbbell :size="12" /> Membership</span>
                <span v-if="p.status === 'trial'" class="mp-status trial">Free trial</span>
                <span v-else-if="p.status && p.status !== 'active'" class="mp-status">{{ p.status }}</span>
              </div>
              <div class="mp-title">{{ p.title }}</div>
              <div v-if="p.joiningFee > 0" class="mp-fee" :class="p.feePaid ? 'paid' : 'due'">
                <ShieldCheck v-if="p.feePaid" :size="13" />
                {{ p.feePaid ? 'Joining fee paid' : 'Joining fee due with your first payment' }}
              </div>
              <div v-if="stateInfo(p.status)" class="mp-state" :class="stateInfo(p.status).cls">
                <AlertTriangle :size="15" />
                <span>{{ stateInfo(p.status).text }}</span>
              </div>
              <div class="mp-member">
                <div v-if="customer.avatar_url" class="mp-member-photo" :style="{ backgroundImage: `url(${customer.avatar_url})` }"></div>
                <div v-else class="mp-member-photo mp-member-nophoto">{{ (customer.full_name || '?').trim().split(' ').map(s => s[0]).slice(0, 2).join('').toUpperCase() }}</div>
                <div class="mp-member-info">
                  <div class="mp-member-name" data-no-translate>{{ customer.full_name }}</div>
                  <div class="mp-member-lbl">Member</div>
                </div>
              </div>
              <div class="mp-qr-wrap">
                <img v-if="p.qr" :src="p.qr" class="mp-qr" :alt="`Entry pass QR code for ${p.title}`" />
                <button v-else type="button" class="mp-qr-retry" @click="retryQr(p)"><RefreshCw :size="16" /> Tap to load QR</button>
                <div class="mp-code" :aria-label="`Pass code ${p.pass.code}`" data-no-translate>{{ p.pass.code }}</div>
              </div>
              <div class="mp-hint">Staff scan the QR (or type the code) and check the photo matches you.</div>
            </div>
          </div>
        </section>

        <section class="mm-plans-panel">
        <!-- ── The MANAGE list: plans, price, renewal, cancel ── -->
        <h2 v-if="subs.length" class="mm-h2" :class="{ 'mm-h2-first': !hasPass }"><CreditCard :size="16" /> Your plans</h2>
        <div v-if="active.length" class="mm-list">
          <div v-for="s in active" :key="s.id" class="mm-row">
            <div class="mm-icon" :style="{ background: s.image ? undefined : `color-mix(in srgb, ${s.color} 12%, white)`, color: s.color, backgroundImage: s.image ? `url(${s.image})` : undefined }">
              <Dumbbell v-if="!s.image" :size="20" />
            </div>
            <div class="mm-info">
              <div class="mm-title">{{ s.name }}</div>
              <div v-if="s.sport" class="mm-sub">{{ s.sport }}</div>
              <div class="mm-meta">
                <span v-if="s.cancelPending"><CalendarClock :size="12" /> {{ endLabel(s) }}</span>
                <span v-else><CalendarClock :size="12" /> {{ s.nextBilling ? `Renews ${fmtDate(s.nextBilling)}` : 'No renewal' }}</span>
              </div>
            </div>
            <div class="mm-right">
              <div class="mm-price">{{ s.currency }} {{ s.amount.toLocaleString() }}<span v-if="s.interval">/{{ s.interval }}</span></div>
              <span class="mm-badge" :class="s.cancelPending ? 'ending' : (s.trialStatus === 'in_trial' ? 'trial' : s.status)">
                <Gift v-if="!s.cancelPending && s.trialStatus === 'in_trial'" :size="11" /><CreditCard v-else :size="11" /> {{ statusLabel(s) }}
              </span>
              <!-- Already winding down: offering Cancel again just looks broken. -->
              <div v-if="s.cancelPending" class="mm-cancelled-note">No further payments will be taken.</div>
              <button v-else type="button" class="mm-cancel" :disabled="cancelling === s.id" @click="cancelSub(s)">
                {{ cancelling === s.id ? 'Cancelling…' : 'Cancel membership' }}
              </button>
            </div>
          </div>
        </div>

        <template v-if="ended.length">
          <h2 class="mm-h2">Past</h2>
          <div class="mm-list">
            <div v-for="s in ended" :key="s.id" class="mm-row past">
              <div class="mm-icon" :style="{ color: s.color }"><Dumbbell :size="20" /></div>
              <div class="mm-info"><div class="mm-title">{{ s.name }}</div><div class="mm-sub">Cancelled</div></div>
              <div class="mm-right"><div class="mm-price">{{ s.currency }} {{ s.amount.toLocaleString() }}</div></div>
            </div>
          </div>
        </template>
        </section>
      </div>
    </template>
  </PersonalPage>
</template>

<style scoped>
.mm { max-width: 640px; margin: 0 auto; }
.cmsg { text-align: center; padding: 50px; color: var(--text-light, #94a3b8); }
.mm-h1 { font-size: 26px; font-weight: 900; color: var(--text, #0f172a); margin: 0 0 6px; display: flex; align-items: center; gap: 10px; }
.mm-h1 svg { color: var(--brand-ink, var(--brand)); flex-shrink: 0; }
.mm-sub-h { font-size: 14px; color: var(--text-muted, #64748b); margin: 0 0 20px; line-height: 1.5; }
.mm-h2 { display: flex; align-items: center; gap: 7px; font-size: 16px; font-weight: 800; color: var(--text, #0f172a); margin: 26px 0 12px; }
.mm-h2 svg { color: var(--brand-ink, var(--brand)); }
.mm-h2-first { margin-top: 0; }
.mm-signin, .mm-empty { display: flex; flex-direction: column; align-items: center; gap: 14px; padding: 56px 20px; text-align: center; color: var(--text-light, #94a3b8); }
.mm-signin p { font-size: 14px; color: var(--text-muted, #64748b); margin: 0; max-width: 320px; line-height: 1.55; }
.mm-cta { display: inline-flex; align-items: center; justify-content: center; background: var(--brand); color: #fff; font-weight: 800; padding: 11px 20px; border-radius: 999px; border: none; cursor: pointer; text-decoration: none; font-size: 14px; }
.mm-list { display: flex; flex-direction: column; gap: 10px; }
.mm-row { display: flex; align-items: center; gap: 14px; background: var(--surface, #ffffff); border: 1px solid var(--border, #e2e8f0); border-radius: 14px; padding: 14px 16px; }
.mm-row.past { opacity: .72; }
.mm-icon { width: 46px; height: 46px; border-radius: 12px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; background-size: cover; background-position: center; background-color: var(--bg, #f1f5f9); }
.mm-info { flex: 1; min-width: 0; }
.mm-title { font-size: 15.5px; font-weight: 800; color: var(--text, #0f172a); }
.mm-sub { font-size: 12.5px; color: var(--text-light, #94a3b8); margin-top: 1px; }
.mm-meta { display: flex; align-items: center; gap: 10px; font-size: 12.5px; color: var(--text-muted, #64748b); margin-top: 4px; }
.mm-meta span { display: inline-flex; align-items: center; gap: 4px; }
.mm-right { text-align: right; flex-shrink: 0; }
.mm-price { font-size: 15px; font-weight: 800; color: var(--text, #0f172a); }
.mm-badge { display: inline-flex; align-items: center; gap: 4px; margin-top: 5px; font-size: 11px; font-weight: 800; padding: 3px 9px; border-radius: 999px; }
.mm-badge.active { background: var(--success-bg, #dcfce7); color: var(--success-ink, #166534); }
.mm-badge.trial { background: var(--warning-bg, #fef3c7); color: var(--warning-ink, #92400e); }
.mm-cancel { display: block; margin-top: 7px; margin-left: auto; background: none; border: none; padding: 2px 0; font-size: 12px; font-weight: 700; color: var(--danger-ink, #dc2626); cursor: pointer; text-decoration: underline; }
.mm-cancel:disabled { opacity: .6; cursor: default; }
.mm-badge.ending { background: var(--warning-bg, #fff7ed); color: var(--warning-ink, #c2410c); }
.mm-cancelled-note { margin-top: 7px; font-size: 11px; font-weight: 600; color: var(--text-muted, #64748b); text-align: right; }

/* ── Pass cards (merged from the old /pass page) ── */
.mp-state { display: flex; align-items: center; gap: 8px; padding: 10px 12px; border-radius: 10px; font-size: 13px; font-weight: 600; margin: 10px 0 4px; }
.mp-state.bad { background: var(--danger-bg, #fef2f2); border: 1px solid var(--danger-border, #fecaca); color: var(--danger-ink, #b91c1c); }
.mp-state.warn { background: var(--warning-bg, #fffbeb); border: 1px solid var(--warning-border, #fde68a); color: var(--warning-ink, #92400e); }
.mp-state svg { flex-shrink: 0; }
.mp-status { font-size: 11.5px; font-weight: 800; color: var(--warning-ink, #b45309); background: var(--warning-bg, #fef3c7); padding: 4px 10px; border-radius: 999px; text-transform: capitalize; }
.mp-status.trial { background: var(--success-bg, #ecfdf5); color: var(--success-ink, #047857); }
.mp-qr-retry { display: inline-flex; align-items: center; gap: 7px; padding: 40px 20px; border: 2px dashed var(--border-strong, #cbd5e1); border-radius: 12px; background: var(--canvas, #f8fafc); color: var(--text-muted, #64748b); font-weight: 700; font-size: 14px; cursor: pointer; }
.mp-list { display: flex; flex-direction: column; gap: 16px; }
.mp-card { background: var(--surface, #ffffff); border: 1px solid var(--border, #e2e8f0); border-radius: 18px; padding: 18px 18px 16px; }
.mp-card-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.mp-kind { display: inline-flex; align-items: center; gap: 5px; font-size: 11.5px; font-weight: 800; padding: 4px 10px; border-radius: 999px; }
.mp-kind.membership { color: var(--violet-ink, #7c3aed); background: var(--violet-bg, #f5f3ff); }
.mp-title { font-size: 17px; font-weight: 800; color: var(--text, #0f172a); margin-top: 9px; }
.mp-fee { display: inline-flex; align-items: center; gap: 5px; margin-top: 6px; font-size: 12px; font-weight: 700; padding: 3px 10px; border-radius: 999px; }
.mp-fee.paid { color: var(--success-ink, #047857); background: var(--success-bg, #ecfdf5); }
.mp-fee.due { color: var(--warning-ink, #92400e); background: var(--warning-bg, #fffbeb); }
.mp-photo-nudge { display: flex; align-items: flex-start; gap: 10px; background: var(--warning-bg, #fffbeb); border: 1px solid var(--warning-border, #fde68a); border-radius: 12px; padding: 12px 14px; margin-bottom: 16px; font-size: 13px; color: var(--warning-ink, #92400e); text-decoration: none; line-height: 1.5; }
.mp-photo-nudge svg { flex-shrink: 0; margin-top: 2px; }
.mp-photo-nudge b { color: var(--warning-ink, #78350f); }
.mp-member { display: flex; align-items: center; gap: 12px; margin-top: 12px; padding: 10px 12px; background: color-mix(in srgb, var(--brand) 6%, var(--surface, white)); border: 1px solid color-mix(in srgb, var(--brand) 18%, white); border-radius: 12px; }
.mp-member-photo { width: 56px; height: 56px; border-radius: 12px; background-size: cover; background-position: center; flex-shrink: 0; border: 2px solid #fff; box-shadow: 0 2px 8px rgba(15,23,42,.12); }
.mp-member-nophoto { display: flex; align-items: center; justify-content: center; background: var(--brand); color: #fff; font-weight: 900; font-size: 20px; }
.mp-member-name { font-size: 15.5px; font-weight: 800; color: var(--text, #0f172a); }
.mp-member-lbl { font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: .06em; color: var(--brand-ink, var(--brand)); margin-top: 2px; }
.mp-qr-wrap { display: flex; flex-direction: column; align-items: center; gap: 10px; margin-top: 12px; padding: 16px 12px 12px; background: var(--canvas, #f8fafc); border-radius: 14px; }
.mp-qr { width: 200px; height: 200px; max-width: 70vw; border-radius: 10px; background: var(--surface, #ffffff); padding: 8px; border: 1px solid var(--border, #e2e8f0); }
.mp-code { font-size: 26px; font-weight: 900; letter-spacing: .35em; color: var(--text, #0f172a); padding-inline-start: .35em; font-variant-numeric: tabular-nums; }
.mp-hint { text-align: center; font-size: 12px; color: var(--text-light, #94a3b8); margin-top: 9px; }
.mp-mems { display: flex; flex-direction: column; gap: 8px; margin-top: 12px; }
.mp-mem { display: flex; flex-direction: column; gap: 5px; padding: 10px 12px; background: var(--canvas, #f8fafc); border: 1px solid var(--border, #e2e8f0); border-radius: 12px; }
.mp-mem-name { font-size: 14px; font-weight: 800; color: var(--text, #0f172a); }
.mp-mem-chips { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.mp-chip { font-style: normal; font-size: 11px; font-weight: 800; padding: 2px 9px; border-radius: 999px; text-transform: capitalize; }
.mp-chip.ok { background: var(--success-bg, #dcfce7); color: var(--success-ink, #166534); }
.mp-chip.trial { background: var(--warning-bg, #fef3c7); color: var(--warning-ink, #92400e); }
.mp-chip.warn { background: var(--danger-bg, #fee2e2); color: var(--danger-ink, #b91c1c); }
.mp-chip.soft { background: var(--bg, #eef2f7); color: var(--text-secondary, #475569); text-transform: none; }

@media (max-width: 760px) {
  .mm-h1 { font-size: 21px; }
  .mm-empty, .mm-signin { padding: 40px 20px; gap: 12px; }
  .mm-cta { min-height: 48px; padding: 14px 24px; font-size: 15px; }
  .mm-row { gap: 10px; padding: 12px 13px; }
  .mm-icon { width: 48px; height: 48px; }
  .mm-title { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
  .mm-sub { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .cmsg { padding: 40px 16px; }
  .mp-card { padding: 15px 15px 13px; }
  .mp-qr { width: 220px; height: 220px; }   /* bigger scan target on the phone you'll actually show */
  .mp-code { font-size: 24px; }
}
.mm-workspace { display: grid; gap: 28px; }.mm-workspace.with-pass { grid-template-columns: minmax(280px, 340px) minmax(0, 1fr); align-items: start; }.mm-pass-panel, .mm-plans-panel { min-width: 0; }.mm-h2 { margin-top: 0; font-size: 18px; }.mm-h2:not(:first-child) { margin-top: 28px; }.mm-row { padding: 22px; flex-wrap: wrap; }.mm-row.past { opacity: 1; }.mm-cancel { min-height: 44px; }.mm-badge.past_due { background: var(--danger-bg); color: var(--danger-ink); }.mm-badge.paused, .mm-badge.pending { background: var(--warning-bg); color: var(--warning-ink); }.mm-badge.expired { background: var(--canvas); color: var(--text-muted); }.mp-card { width: 100%; box-sizing: border-box; }.mp-list { grid-template-columns: 1fr; }
@media(max-width:900px) { .mm-workspace.with-pass { grid-template-columns: 1fr; }.mm-pass-panel { max-width: 560px; width: 100%; }.mm-right { flex: 1 1 100%; text-align: left; margin-inline-start: 60px; }.mm-cancel { margin-left: 0; }.mm-row { padding: 18px 14px; } }
</style>
