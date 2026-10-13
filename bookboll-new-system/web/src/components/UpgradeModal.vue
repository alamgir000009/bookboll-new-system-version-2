<script setup>
/**
 * UpgradeModal — the global "go Premium" flow, opened by requirePremium() from any
 * gated button (lib/premium.js holds the state). Three clean steps:
 *   1) gate      — "Premium feature" prompt for the feature they just hit
 *   2) plans     — Free vs Premium cards + Monthly/Yearly toggle (yearly default)
 *   3) checkout  — full detail, billing toggle, what's included, trial breakdown
 * Subscribe is SIMULATED (no real charge).
 */
import { computed, ref, watch } from 'vue'
import {
  upgradeModal, PREMIUM_FEATURES, PREMIUM_PRICE, PREMIUM_YEARLY_PER_MONTH,
  PREMIUM_YEARLY_TOTAL, PREMIUM_YEARLY_SAVE_PCT, PREMIUM_TRIAL_DAYS, billedAmount, usePremium,
} from '../lib/premium.js'
import { validatePromo } from '../lib/promocodes.js'
import { Crown, Check, X, Sparkles, Loader, Tag, ArrowRight, Gift, ShieldCheck, CreditCard, Lock } from 'lucide-vue-next'

const { subscribePremium, upgrading, isPremium, ownerRow } = usePremium()
const open = computed(() => upgradeModal.value.open)
const highlight = computed(() => upgradeModal.value.feature)
const featureLabel = computed(() => PREMIUM_FEATURES.find(f => f.key === highlight.value)?.label || 'This')

// Core platform (free plan) highlights.
const FREE_FEATURES = [
  'Free branded booking website',
  'Bookboll marketplace listing',
  'Court, venue & activity bookings',
  'Memberships & academy programs',
  'Online payments & payout wallet',
  'One calendar + team accounts',
]

const step = ref('gate')            // 'gate' | 'plans' | 'checkout'
const billing = ref('yearly')       // yearly by default (best value)
watch(open, o => { if (o) { step.value = 'gate'; billing.value = 'yearly'; clearPromo() } })

// Pricing helpers reactive to the billing toggle.
const perMonth = computed(() => billing.value === 'yearly' ? PREMIUM_YEARLY_PER_MONTH : PREMIUM_PRICE)
const billed = computed(() => billedAmount(billing.value))         // upfront charge after trial
const trialDays = computed(() => PREMIUM_TRIAL_DAYS[billing.value] || PREMIUM_TRIAL_DAYS.monthly)
const finalBilled = computed(() => Math.max(0, billed.value - (promo.value?.discount || 0)))
const afterTrialLine = computed(() => billing.value === 'yearly'
  ? `US$${finalBilled.value.toLocaleString()} billed annually after trial`
  : `charged monthly after trial`)

// Promo code (optional, super-admin provider-plan codes).
const promoInput = ref('')
const checking = ref(false)
const promo = ref(null)
const promoError = ref('')
async function applyPromo() {
  promoError.value = ''
  const raw = promoInput.value.trim()
  if (!raw) return
  checking.value = true
  const res = await validatePromo(raw, {
    applicableTo: 'provider_plan', amount: billed.value,
    ownerUserId: ownerRow.value?.id || null, customerEmail: ownerRow.value?.email || '',
  })
  checking.value = false
  if (res.ok) { promo.value = { code: res.code, discount: res.discount }; promoError.value = '' }
  else { promo.value = null; promoError.value = res.reason || 'Invalid code.' }
}
function clearPromo() { promo.value = null; promoInput.value = ''; promoError.value = '' }

async function doSubscribe() {
  const ok = await subscribePremium(promo.value, billing.value)
  if (ok) { clearPromo(); step.value = 'gate' }
}
function close() { clearPromo(); step.value = 'gate'; upgradeModal.value = { open: false, feature: '' } }
</script>

<template>
  <div v-if="open" class="ug-backdrop" @click.self="close">
    <div class="ug-card" :class="'step-' + step">
      <button class="ug-x" @click="close" aria-label="Close"><X :size="18" /></button>

      <!-- ── STEP 1 · Premium feature gate ─────────────────────────────── -->
      <template v-if="step === 'gate'">
        <div class="ug-ic"><Crown :size="26" /></div>
        <h3 class="ug-title">Premium feature</h3>
        <p class="ug-sub"><b>{{ featureLabel }}</b> is part of Bookboll Premium — unlock it and the full business toolkit.</p>
        <ul class="ug-gate-feats">
          <li v-for="ft in PREMIUM_FEATURES.slice(0, 4)" :key="ft.key" :class="{ hot: ft.key === highlight }">
            <span class="ug-feat-ic"><Check :size="12" /></span>{{ ft.label }}
            <span v-if="ft.key === highlight" class="ug-feat-tag">this one</span>
          </li>
        </ul>
        <div class="ug-trial-chip"><Gift :size="14" /> Start with a free trial — no charge today</div>
        <div class="ug-actions ug-actions-col">
          <button class="ug-btn" @click="step = 'plans'"><Sparkles :size="15" /> See plans &amp; start free trial</button>
          <button class="ug-btn-ghost" @click="close">Maybe later</button>
        </div>
      </template>

      <!-- ── STEP 2 · Choose your plan ─────────────────────────────────── -->
      <template v-else-if="step === 'plans'">
        <h3 class="ug-title">Choose your plan</h3>
        <p class="ug-sub">Free forever, or go Premium for the full toolkit — with a free trial to start.</p>
        <div class="ug-toggle ug-toggle-center">
          <button type="button" class="ug-tg" :class="{ on: billing === 'monthly' }" @click="billing = 'monthly'">Monthly</button>
          <button type="button" class="ug-tg" :class="{ on: billing === 'yearly' }" @click="billing = 'yearly'">Yearly <span class="ug-save">Save {{ PREMIUM_YEARLY_SAVE_PCT }}%</span></button>
        </div>

        <div class="ug-plans">
          <!-- Free -->
          <div class="ug-plan">
            <div class="ug-plan-name">Free</div>
            <div class="ug-plan-price"><b>$0</b><span>/month</span></div>
            <div class="ug-plan-tag current">Your current plan</div>
            <ul class="ug-plan-list">
              <li v-for="f in FREE_FEATURES.slice(0, 5)" :key="f"><Check :size="13" /> {{ f }}</li>
            </ul>
            <button class="ug-plan-btn ghost" @click="close">Stay on Free</button>
          </div>
          <!-- Premium -->
          <div class="ug-plan premium">
            <div class="ug-plan-ribbon"><Crown :size="12" /> Recommended</div>
            <div class="ug-plan-name">Premium</div>
            <div class="ug-plan-price"><b>${{ perMonth }}</b><span>/month</span></div>
            <div class="ug-plan-tag">{{ billing === 'yearly' ? `billed $${PREMIUM_YEARLY_TOTAL}/year` : 'billed monthly' }}</div>
            <ul class="ug-plan-list">
              <li class="everything">Everything in Free, plus:</li>
              <li v-for="ft in PREMIUM_FEATURES.slice(0, 6)" :key="ft.key"><Check :size="13" /> {{ ft.label }}</li>
            </ul>
            <button class="ug-plan-btn" @click="step = 'checkout'"><Sparkles :size="14" /> Choose Premium</button>
          </div>
        </div>
      </template>

      <!-- ── STEP 3 · Checkout ─────────────────────────────────────────── -->
      <template v-else>
        <button class="ug-back" @click="step = 'plans'"><ArrowRight :size="15" class="ug-back-ic" /> Back to plans</button>
        <div class="ug-co">
          <!-- left: plan + what's included -->
          <div class="ug-co-main">
            <div class="ug-co-head">
              <div class="ug-ic sm"><Sparkles :size="18" /></div>
              <div>
                <div class="ug-co-eyebrow">Selected plan</div>
                <div class="ug-co-plan">Premium</div>
              </div>
              <div class="ug-co-price">
                <b>${{ perMonth }}</b><span>/mo</span>
                <div v-if="billing === 'yearly'" class="ug-co-annual">billed annually</div>
              </div>
            </div>

            <div class="ug-toggle">
              <button type="button" class="ug-tg" :class="{ on: billing === 'monthly' }" @click="billing = 'monthly'">Monthly</button>
              <button type="button" class="ug-tg" :class="{ on: billing === 'yearly' }" @click="billing = 'yearly'">Yearly <span class="ug-save">Save {{ PREMIUM_YEARLY_SAVE_PCT }}%</span></button>
            </div>

            <div class="ug-co-inc-h"><ShieldCheck :size="15" /> What's included</div>
            <div class="ug-co-grid">
              <div v-for="ft in PREMIUM_FEATURES" :key="ft.key" class="ug-co-inc" :class="{ hot: ft.key === highlight }">
                <span class="ug-feat-ic"><Check :size="12" /></span>{{ ft.label }}
              </div>
            </div>
          </div>

          <!-- right: order summary + trial breakdown -->
          <aside class="ug-co-side">
            <div class="ug-os-title"><CreditCard :size="16" /> Order summary</div>
            <div class="ug-os-row"><span>Plan</span><b>Premium</b></div>
            <div class="ug-os-row"><span>Billing</span><b>${{ perMonth }}/mo<small>{{ billing === 'yearly' ? ' (billed annually)' : '' }}</small></b></div>

            <!-- promo -->
            <div class="ug-promo">
              <div v-if="!promo" class="ug-promo-row">
                <span class="ug-promo-ic"><Tag :size="14" /></span>
                <input v-model="promoInput" class="ug-promo-input" placeholder="Promo code (optional)" @keydown.enter.prevent="applyPromo" />
                <button class="ug-promo-btn" :disabled="checking || !promoInput.trim()" @click="applyPromo">{{ checking ? '…' : 'Apply' }}</button>
              </div>
              <div v-else class="ug-promo-ok"><Check :size="13" /> <b>{{ promo.code.code }}</b> — ${{ promo.discount }} off <button class="ug-promo-x" @click="clearPromo">remove</button></div>
              <div v-if="promoError" class="ug-promo-err">{{ promoError }}</div>
            </div>

            <!-- trial breakdown -->
            <div class="ug-trial-card">
              <div class="ug-trial-hd"><Gift :size="15" /> Pay nothing until your trial ends</div>
              <div class="ug-trial-row"><span>Today</span><b>US$0.00</b></div>
              <div class="ug-trial-free"><Gift :size="13" /> Free for {{ trialDays }} days · Cancel anytime</div>
              <div class="ug-trial-row"><span>After trial</span><b class="teal">US${{ finalBilled.toLocaleString() }}<small>{{ billing === 'yearly' ? '/year' : '/month' }}</small></b></div>
              <div class="ug-trial-note">{{ afterTrialLine }}. Simulated — no real charge is made.</div>
              <div class="ug-trial-due"><span>Due now</span><b>US$0.00</b></div>
            </div>

            <button class="ug-btn ug-co-cta" :disabled="upgrading || isPremium" @click="doSubscribe">
              <Loader v-if="upgrading" :size="15" class="ug-spin" /><CreditCard v-else :size="15" />
              {{ isPremium ? 'Already Premium' : upgrading ? 'Starting…' : `Start ${trialDays}-day free trial` }}
            </button>
            <div class="ug-fine"><Lock :size="11" /> Encrypted checkout · Payment simulated in this demo.</div>
          </aside>
        </div>
      </template>
    </div>
  </div>
</template>

<style scoped>
.ug-backdrop { position: fixed; inset: 0; z-index: 4300; background: rgba(15,23,42,.55); backdrop-filter: blur(3px); display: flex; align-items: center; justify-content: center; padding: 20px; overflow-y: auto; }
.ug-card { position: relative; width: 100%; background: var(--surface, #ffffff); border-radius: 20px; padding: 28px 26px 22px; box-shadow: 0 24px 60px rgba(0,0,0,.4); text-align: center; }
.ug-card.step-gate { max-width: 440px; }
.ug-card.step-plans { max-width: 680px; }
.ug-card.step-checkout { max-width: 860px; text-align: start; }
.ug-x { position: absolute; top: 12px; right: 12px; width: 32px; height: 32px; border-radius: 50%; border: none; background: #f1f5f9; color: var(--text-muted, #64748b); display: flex; align-items: center; justify-content: center; cursor: pointer; z-index: 2; }
.ug-x:hover { background: var(--border, #e2e8f0); color: #0f172a; }
.ug-ic { width: 56px; height: 56px; border-radius: 16px; margin: 0 auto 12px; background: linear-gradient(135deg, #14b8a6, #0d9488); color: #fff; display: flex; align-items: center; justify-content: center; }
.ug-ic.sm { width: 44px; height: 44px; margin: 0; border-radius: 12px; flex-shrink: 0; }
.ug-title { font-size: 21px; font-weight: 900; color: #0f172a; margin: 0 0 6px; }
.ug-sub { font-size: 13.5px; color: var(--text-muted, #64748b); line-height: 1.5; margin: 0 0 16px; }

/* Gate feature mini-list */
.ug-gate-feats { list-style: none; margin: 0 0 14px; padding: 0; display: flex; flex-direction: column; gap: 7px; text-align: start; }
.ug-gate-feats li { display: flex; align-items: center; gap: 9px; font-size: 13.5px; font-weight: 700; color: #334155; padding: 9px 12px; border: 1px solid #f1f5f9; border-radius: 10px; }
.ug-gate-feats li.hot { border-color: #99f6e4; background: #f0fdfa; }
.ug-feat-ic { width: 19px; height: 19px; border-radius: 50%; background: #f0fdfa; color: #0f766e; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }
.ug-feat-tag { margin-inline-start: auto; font-size: 10px; font-weight: 800; text-transform: uppercase; color: #0f766e; background: #ccfbf1; border-radius: 999px; padding: 2px 8px; }
.ug-trial-chip { display: inline-flex; align-items: center; gap: 7px; font-size: 12.5px; font-weight: 800; color: #0f766e; background: #f0fdfa; border: 1px solid #99f6e4; border-radius: 999px; padding: 7px 14px; margin: 0 auto 16px; }

/* Buttons */
.ug-actions { display: flex; gap: 10px; }
.ug-actions-col { flex-direction: column; }
.ug-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 13px; border-radius: 12px; border: none; background: linear-gradient(135deg, #14b8a6, #0d9488); color: #fff; font-weight: 800; font-size: 14.5px; cursor: pointer; width: 100%; }
.ug-btn:disabled { opacity: .6; cursor: default; }
.ug-btn:hover:not(:disabled) { filter: brightness(.97); }
.ug-btn-ghost { padding: 12px; border-radius: 12px; border: 1px solid var(--border, #e2e8f0); background: var(--surface, #ffffff); color: var(--text-muted, #64748b); font-weight: 700; font-size: 14px; cursor: pointer; width: 100%; }

/* Billing toggle */
.ug-toggle { display: flex; gap: 4px; background: #f1f5f9; border-radius: 12px; padding: 4px; margin-bottom: 16px; }
.ug-toggle-center { max-width: 320px; margin: 0 auto 18px; }
.ug-tg { flex: 1; display: inline-flex; align-items: center; justify-content: center; gap: 7px; border: none; background: none; color: var(--text-muted, #64748b); font: inherit; font-weight: 800; font-size: 13.5px; padding: 9px 10px; border-radius: 9px; cursor: pointer; }
.ug-tg.on { background: var(--surface, #ffffff); color: #0f172a; box-shadow: 0 1px 3px rgba(15,23,42,.12); }
.ug-save { font-size: 10px; font-weight: 800; text-transform: uppercase; color: #15803d; background: #dcfce7; padding: 2px 7px; border-radius: 999px; }

/* Plan cards */
.ug-plans { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; text-align: start; }
.ug-plan { position: relative; border: 1.5px solid var(--border, #e2e8f0); border-radius: 16px; padding: 18px 16px; display: flex; flex-direction: column; }
.ug-plan.premium { border-color: #0d9488; box-shadow: 0 8px 26px rgba(13,148,136,.14); }
.ug-plan-ribbon { position: absolute; top: -11px; left: 16px; display: inline-flex; align-items: center; gap: 4px; font-size: 10.5px; font-weight: 800; text-transform: uppercase; letter-spacing: .03em; color: #fff; background: linear-gradient(135deg,#14b8a6,#0d9488); padding: 3px 10px; border-radius: 999px; }
.ug-plan-name { font-size: 17px; font-weight: 900; color: #0f172a; }
.ug-plan-price { display: flex; align-items: baseline; gap: 3px; margin: 4px 0 2px; }
.ug-plan-price b { font-size: 30px; font-weight: 900; color: #0f172a; line-height: 1; }
.ug-plan-price span { font-size: 14px; font-weight: 700; color: var(--text-muted, #64748b); }
.ug-plan-tag { font-size: 12px; color: var(--text-muted, #64748b); margin-bottom: 12px; }
.ug-plan-tag.current { color: #0f766e; font-weight: 700; }
.ug-plan-list { list-style: none; margin: 0 0 14px; padding: 0; display: flex; flex-direction: column; gap: 7px; flex: 1; }
.ug-plan-list li { display: flex; align-items: flex-start; gap: 7px; font-size: 12.5px; color: #334155; }
.ug-plan-list li svg { color: #0d9488; flex-shrink: 0; margin-top: 1px; }
.ug-plan-list li.everything { font-weight: 800; color: #0f172a; }
.ug-plan-btn { display: inline-flex; align-items: center; justify-content: center; gap: 7px; padding: 12px; border-radius: 11px; border: none; background: linear-gradient(135deg,#14b8a6,#0d9488); color: #fff; font-weight: 800; font-size: 14px; cursor: pointer; }
.ug-plan-btn.ghost { background: none; border: 1.5px solid var(--border, #e2e8f0); color: #475569; }

/* Checkout */
.ug-back { display: inline-flex; align-items: center; gap: 6px; background: none; border: none; color: var(--text-muted, #64748b); font: inherit; font-weight: 700; font-size: 13px; cursor: pointer; padding: 0 0 12px; }
.ug-back-ic { transform: rotate(180deg); }
.ug-co { display: grid; grid-template-columns: 1fr 320px; gap: 18px; align-items: start; }
.ug-co-head { display: flex; align-items: center; gap: 12px; padding-bottom: 14px; border-bottom: 1px solid var(--border, #e2e8f0); margin-bottom: 14px; }
.ug-co-eyebrow { font-size: 10.5px; font-weight: 800; text-transform: uppercase; letter-spacing: .04em; color: var(--text-light, #94a3b8); }
.ug-co-plan { font-size: 19px; font-weight: 900; color: #0f172a; }
.ug-co-price { margin-inline-start: auto; text-align: end; }
.ug-co-price b { font-size: 26px; font-weight: 900; color: #0f172a; }
.ug-co-price span { font-size: 13px; font-weight: 700; color: var(--text-muted, #64748b); }
.ug-co-annual { font-size: 11px; font-weight: 700; color: #0f766e; background: #f0fdfa; border-radius: 999px; padding: 2px 8px; display: inline-block; margin-top: 2px; }
.ug-co-inc-h { display: flex; align-items: center; gap: 7px; font-size: 13.5px; font-weight: 800; color: #0f172a; margin-bottom: 10px; }
.ug-co-inc-h svg { color: #0d9488; }
.ug-co-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
.ug-co-inc { display: flex; align-items: center; gap: 8px; font-size: 12.5px; font-weight: 600; color: #334155; padding: 9px 11px; border: 1px solid #f1f5f9; border-radius: 10px; }
.ug-co-inc.hot { border-color: #99f6e4; background: #f0fdfa; }

.ug-co-side { background: #f8fafc; border: 1px solid var(--border, #e2e8f0); border-radius: 16px; padding: 16px; }
.ug-os-title { display: flex; align-items: center; gap: 7px; font-size: 14px; font-weight: 800; color: #0f172a; margin-bottom: 12px; }
.ug-os-title svg { color: #0d9488; }
.ug-os-row { display: flex; align-items: baseline; justify-content: space-between; gap: 10px; font-size: 13px; color: var(--text-muted, #64748b); padding: 5px 0; }
.ug-os-row b { color: #0f172a; font-weight: 800; text-align: end; }
.ug-os-row small { font-weight: 600; color: var(--text-muted, #64748b); }

.ug-trial-card { background: linear-gradient(135deg,#f0fdfa,#ecfeff); border: 1.5px solid #99f6e4; border-radius: 14px; padding: 13px; margin: 12px 0; }
.ug-trial-hd { display: flex; align-items: center; gap: 7px; font-size: 13px; font-weight: 800; color: #0f766e; margin-bottom: 8px; }
.ug-trial-row { display: flex; align-items: baseline; justify-content: space-between; font-size: 13.5px; color: #334155; padding: 3px 0; }
.ug-trial-row b { font-size: 16px; font-weight: 900; color: #0f172a; }
.ug-trial-row b.teal { color: #0d9488; }
.ug-trial-row b small { font-size: 12px; font-weight: 700; }
.ug-trial-free { display: flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 800; color: #0f766e; background: #ccfbf1; border-radius: 8px; padding: 6px 10px; margin: 6px 0; }
.ug-trial-note { font-size: 11px; color: var(--text-muted, #64748b); line-height: 1.4; margin: 4px 0 8px; }
.ug-trial-due { display: flex; align-items: baseline; justify-content: space-between; border-top: 1px dashed #99f6e4; padding-top: 8px; font-size: 14px; font-weight: 800; color: #0f172a; }
.ug-trial-due b { color: #0d9488; font-size: 17px; font-weight: 900; }
.ug-co-cta { margin-top: 4px; }

/* Promo */
.ug-promo { margin: 10px 0; }
.ug-promo-row { display: flex; align-items: center; gap: 6px; border: 1px solid var(--border, #e2e8f0); border-radius: 11px; padding: 4px 4px 4px 10px; background: var(--surface, #ffffff); }
.ug-promo-ic { color: var(--text-light, #94a3b8); display: inline-flex; flex-shrink: 0; }
.ug-promo-input { flex: 1; border: none; outline: none; font-size: 13px; background: transparent; padding: 7px 2px; color: #0f172a; min-width: 0; }
.ug-promo-btn { border: none; background: #0f172a; color: #fff; font-weight: 700; font-size: 12.5px; padding: 8px 13px; border-radius: 8px; cursor: pointer; flex-shrink: 0; }
.ug-promo-btn:disabled { opacity: .45; cursor: default; }
.ug-promo-ok { display: flex; align-items: center; gap: 6px; font-size: 12px; color: #0f766e; background: #f0fdfa; border: 1px solid #99f6e4; border-radius: 10px; padding: 8px 11px; }
.ug-promo-ok b { color: #0f172a; }
.ug-promo-x { margin-inline-start: auto; border: none; background: transparent; color: var(--text-muted, #64748b); font-size: 12px; cursor: pointer; text-decoration: underline; }
.ug-promo-err { font-size: 12px; color: #dc2626; margin-top: 6px; }

.ug-fine { display: flex; align-items: center; justify-content: center; gap: 5px; font-size: 11px; color: var(--text-light, #94a3b8); margin-top: 10px; }
.ug-spin { animation: ugspin 1s linear infinite; }
@keyframes ugspin { to { transform: rotate(360deg); } }

@media (max-width: 760px) {
  .ug-backdrop { align-items: flex-end; padding: 0; }
  .ug-card, .ug-card.step-gate, .ug-card.step-plans, .ug-card.step-checkout { max-width: none; border-radius: 18px 18px 0 0; padding: 24px 16px 16px; max-height: 94dvh; overflow-y: auto; }
  .ug-plans { grid-template-columns: 1fr; }
  .ug-co { grid-template-columns: 1fr; }
  .ug-co-grid { grid-template-columns: 1fr; }
  .ug-promo-input { font-size: 16px; }
}
</style>
