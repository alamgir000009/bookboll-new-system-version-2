<script setup>
import { ref, onMounted, watch, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { loadProgram, intervalLabel, enrollInProgram, MEMBER_COUNT_MIN } from '../../customer/data.js'
import { checkoutState } from '../../customer/apiPrograms.js'
import { offerModeFor, discountedPrice } from '../../lib/billingPlans.js'
import { customer, isCustomer, selectedCountry, clearCustomerLoyaltyCache } from '../../lib/customerSession.js'
import { isWhiteLabel } from '../../lib/brand.js'
import CheckoutRewards from '../components/CheckoutRewards.vue'
import { ensureSignedIn } from '../../lib/authGate.js'
import { asFields, hasForm, blankAnswers, validateAnswers } from '../../lib/customForms.js'
import { validatePromo } from '../../lib/promocodes.js'
import { hasUsedIntroOffer } from '../../lib/offerGuard.js'
import { useSeo, productLd } from '../../lib/seo.js'
import { fmt12, durationLabel } from '../../lib/slots.js'
import CustomFormFields from '../components/CustomFormFields.vue'
import { ArrowLeft, MapPin, Dumbbell, Check, Phone, Clock, CalendarDays, Gift, X, CreditCard, Loader2, Tag, Expand, Users, ShieldCheck } from 'lucide-vue-next'
import Lightbox from '../components/Lightbox.vue'
import { categoryIsGym, audienceLabel, effectiveGenderPolicy, genderPolicyLabel } from '../../lib/membershipCategories.js'

const route = useRoute()
const router = useRouter()
const data = ref(null)
const loading = ref(true)

// Membership photo gallery (first = cover). activePhoto drives the hero; thumbnails switch it.
const activePhoto = ref(0)
const gallery = computed(() => data.value?.photos?.length ? data.value.photos : (data.value?.image ? [data.value.image] : []))
const heroPhoto = computed(() => gallery.value[activePhoto.value] || gallery.value[0] || '')
const lightbox = ref(false)   // full-screen photo viewer

async function load() { loading.value = true; activePhoto.value = 0; data.value = await loadProgram(route.params.id); loading.value = false }
onMounted(async () => { await load(); await resumeFromStripe() })
watch(() => route.params.id, load)

// The membership check is answered server-side and depends on who is asking, so
// the page has to re-ask when the viewer signs in or out — otherwise someone who
// signs in on this very page keeps seeing the sign-up CTA for a membership they
// already hold.
watch(() => customer.value?.id, (now, before) => { if (now !== before) load() })

// Already a member of THIS program (server's answer, same rule that refuses a
// duplicate enrolment). Enrolling again is refused anyway — this stops the page
// offering it in the first place.
const isMember = computed(() => !!data.value?.isMember)
function goToMyMemberships() { router.push('/my-memberships') }

useSeo(() => {
  if (!data.value) return { path: route.path, noindex: !loading.value }
  const venue = data.value.location?.name
  return {
    title: `${p.value.name}${venue ? ' · ' + venue : ''}`,
    description: `${p.value.description || `Join ${p.value.name}`}${venue ? ' at ' + venue : ''} — ${cur.value} ${payPrice.value.toLocaleString()}${oneTime.value ? '' : '/' + intervalLabel(p.value.billing_interval)} on Bookboll.`,
    image: data.value.image,
    path: route.path,
    type: 'product',
    jsonLd: productLd({
      name: p.value.name, description: p.value.description, image: data.value.image,
      price: payPrice.value || undefined, currency: cur.value, url: `https://bookboll.com${route.path}`,
    }),
  }
})

const p = computed(() => data.value?.program || {})
const offerMode = computed(() => offerModeFor(p.value))           // 'trial' | 'discount'
const oneTime = computed(() => p.value.billing_interval === 'one_time')
const isFixed = computed(() => p.value.program_type === 'fixed_duration')
// Anti-abuse: one intro TRIAL per customer per provider (by account email or card —
// see lib/offerGuard.js). When already used, the trial disappears from this page's
// pricing (badge, billing copy, pay button) and the first payment is taken at signup.
const introUsed = ref(false)
async function checkIntroUsed() {
  introUsed.value = false
  const owner = p.value?.owner_user_id
  if (!owner || !customer.value?.email || offerModeFor(p.value) !== 'trial') return
  introUsed.value = await hasUsedIntroOffer({ email: customer.value.email, ownerUserId: owner })
}
watch([() => data.value?.program?.id, () => customer.value?.email], checkIntroUsed, { immediate: true })
const trialSessions = computed(() => introUsed.value ? 0 : (Number(p.value.free_trial_sessions) || 0))
const trialDays = computed(() => introUsed.value ? 0 : (Number(p.value.free_trial_days) || 0))
const hasTrial = computed(() => offerMode.value === 'trial' && (trialDays.value > 0 || trialSessions.value > 0))
const discountMonths = computed(() => Number(p.value.discount_months) || 0)
const listPrice = computed(() => Number(p.value.monthly_price) || 0)
// What the customer actually pays (discount plans charge the reduced amount).
const payPrice = computed(() => offerMode.value === 'discount' ? discountedPrice(listPrice.value, p.value.billing_interval, discountMonths.value) : listPrice.value)
const cur = computed(() => p.value.currency || 'AED')
// Social proof: live active-member count (from loadProgram). Only surfaced once it clears
// the shared threshold so a brand-new program never brags "1 member".
const memberCount = computed(() => Number(data.value?.memberCount) || 0)
const showMembers = computed(() => memberCount.value >= MEMBER_COUNT_MIN)
// Capacity: a capped program (capacity > 0) is FULL once its active members reach the cap.
// capacity 0/falsy = unlimited (gym-access / open memberships) → never full. Blocks the
// join button; the server re-checks authoritatively in enrollInProgram.
const capacity = computed(() => Number(p.value.capacity) || 0)
const isFull = computed(() => capacity.value > 0 && memberCount.value >= capacity.value)
// One-time joining / sign-up fee (optional column on class_programs; undefined = 0).
const joiningFee = computed(() => Math.max(0, Number(p.value.joining_fee) || 0))
// Lead-in describing the free trial ("First session free, then " / "14 days free, then ").
const trialLead = computed(() => trialSessions.value > 0 ? 'First session free, then ' : (trialDays.value > 0 ? `${trialDays.value} days free, then ` : ''))
const offerBadge = computed(() => {
  if (offerMode.value === 'discount') return discountMonths.value > 0 ? `${discountMonths.value} month${discountMonths.value !== 1 ? 's' : ''} off` : ''
  return trialSessions.value > 0 ? '1 free session' : (trialDays.value > 0 ? `${trialDays.value}-day free trial` : '')
})
const fmtD = (d) => d ? new Date(d + 'T00:00:00').toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' }) : ''
// Plain billing line for the side panel.
const billingNote = computed(() => {
  const iv = intervalLabel(p.value.billing_interval).toLowerCase()
  if (offerMode.value === 'discount') return `Billed today, renews every ${iv}`
  const lead = trialLead.value
  // A "1 session" trial bills on the 2nd session, so say so instead of "until <end>".
  const onSecond = trialSessions.value > 0
  if (oneTime.value) {
    if (onSecond) return `${lead}one payment on the 2nd session`
    return lead ? `${lead}one payment for the whole program` : 'One payment for the whole program'
  }
  if (isFixed.value) {
    if (onSecond) return `${lead}billed monthly from the 2nd session`
    const end = p.value.end_date ? `until ${fmtD(p.value.end_date)}` : 'until the program ends'
    return lead ? `${lead}billed monthly ${end}` : `Billed monthly ${end}`
  }
  if (onSecond) return `${lead}billed every ${iv} from the 2nd session`
  return lead ? `${lead}billed every ${iv}` : `Billed every ${iv}`
})
const days = computed(() => {
  const d = p.value.session_days
  if (Array.isArray(d)) return d.join(', ')
  return d || ''
})
// Gym = facility access (no class sessions); show the venue's opening hours instead.
const isGym = computed(() => categoryIsGym(p.value.category))
const openingHours = computed(() => data.value?.openingHours || null)
// Audience badge — age only (kids/adults/both). Gym access values (ladies/men/everyone)
// are suppressed: the "Who can join" gender fact below carries that meaning now.
const audience = computed(() => !isGym.value && p.value.audience && p.value.audience !== 'everyone' ? audienceLabel(p.value.audience) : '')
// Gender policy fact — Mixed / Men only / Women only (gym-access fallback pre-migration).
const gender = computed(() => effectiveGenderPolicy(p.value))
const genderLabel = computed(() => genderPolicyLabel(gender.value))

// ── Membership checkout (plan → custom questions → details → dummy payment) ──────
const signupFields = computed(() => asFields(p.value.signup_form))
const showCheckout = ref(false)
const answers = ref({})
const ansErrors = ref({})
const contact = ref({ full_name: '', email: '', phone: '' })
// "When do I actually start?" — the next upcoming session from the weekly
// schedule (day mask + time). Gym-access plans have no discrete sessions, so
// access simply starts today. Shown while signing up AND on the success screen.
const firstClass = computed(() => {
  const mask = Array.isArray(p.value.day_of_week_mask) ? p.value.day_of_week_mask : []
  if (!mask.length || !p.value.session_time) return null
  const now = new Date()
  for (let i = 0; i < 8; i++) {
    const d = new Date(now); d.setDate(now.getDate() + i)
    if (!mask.includes(d.getDay())) continue
    if (i === 0) {   // today only counts if the session hasn't started yet
      const [h, m] = String(p.value.session_time).split(':').map(Number)
      const t = new Date(now); t.setHours(h || 0, m || 0, 0, 0)
      if (t <= now) continue
    }
    return d
  }
  return null
})
const firstClassLabel = computed(() => {
  if (!firstClass.value) return ''
  const day = firstClass.value.toLocaleDateString('en-US', { weekday: 'long', month: 'short', day: 'numeric' })
  return `${day} · ${fmt12(String(p.value.session_time).slice(0, 5))}`
})
// The subscription created by this checkout — the success screen shows its dates.
const enrolled = ref(null)
// Card-field masks: expiry auto-inserts " / " after the month (typing "1128" →
// "11 / 28"; a lone "9" becomes "09 / "), the number groups into 4s, CVC is
// digits-only. The separator is NOT re-added while deleting, so backspace
// doesn't fight the mask. Fingerprinting strips non-digits, so spaces are safe.
let prevExp = ''
const submitting = ref(false)
const done = ref(false)
const checkoutErr = ref('')
// ── Promo code (applies to the charge made TODAY; hidden when a trial defers it) ──
const promoInput = ref('')
const promo = ref(null)        // { code(row), discount }
const promoError = ref('')
const promoBusy = ref(false)
const promoEligible = computed(() => !hasTrial.value && payPrice.value > 0)   // something is charged now
const promoDiscount = computed(() => promoEligible.value ? Math.min(Number(promo.value?.discount || 0), payPrice.value) : 0)
// The one-time joining fee is collected TODAY only when there's no trial deferring the
// first charge (with a trial it rides the first post-trial charge, so nothing today).
const joiningNow = computed(() => hasTrial.value ? 0 : joiningFee.value)
// Charged today = (discounted plan price − promo) + joining fee.
const payNow = computed(() => Math.max(0, payPrice.value - promoDiscount.value + joiningNow.value))

// Provider proceeds are protected by the server quote, never a default commission.
const rewardSelection = ref(null)
const rewardsControl = ref(null)
const loyaltyCountryId = computed(() => p.value.country_id || data.value?.location?.country_id || null)
const rewardsCheckout = computed(() => ({program_id:p.value.id,promocode:promo.value?.code?.code || null,discount:promoDiscount.value,surface:'marketplace'}))
const loyaltyAmount = computed(() => rewardSelection.value?.amount || 0)
const chargeNow = computed(() => Math.max(0, +(payNow.value - loyaltyAmount.value).toFixed(2)))
async function applyPromo() {
  promoError.value = ''
  promoBusy.value = true
  const res = await validatePromo(promoInput.value, {
    amount: payPrice.value, applicableTo: 'program',
    ownerUserId: p.value.owner_user_id || null, locationId: p.value.location_id || null,
    programId: p.value.id || null,
    customerEmail: customer.value?.email || '',
  })
  promoBusy.value = false
  if (!res.ok) { promo.value = null; promoError.value = res.reason; return }
  promo.value = { code: res.code, discount: res.discount }
}
function removePromo() { promo.value = null; promoInput.value = ''; promoError.value = '' }
const payLabel = computed(() => hasTrial.value ? 'Start — free now' : `Pay ${cur.value} ${chargeNow.value.toLocaleString()}`)

async function openCheckout() {
  if (isFull.value) return   // capped program at capacity — nothing to open
  if (isMember.value) { goToMyMemberships(); return }
  // Mandatory: must have a Bookboll account before subscribing.
  if (!isCustomer.value && !(await ensureSignedIn())) return
  // The program was fetched anonymously, so membership was unknown until now.
  // Re-fetch as the signed-in viewer before offering to take their money.
  if (!data.value?.isMember && !isMember.value) {
    await load()
    if (isMember.value) { goToMyMemberships(); return }
  }
  answers.value = blankAnswers(signupFields.value)
  ansErrors.value = {}
  checkoutErr.value = ''
  done.value = false
  promo.value = null; promoInput.value = ''; promoError.value = ''
  contact.value = { full_name: customer.value?.full_name || '', email: customer.value?.email || '', phone: customer.value?.phone || '' }
  rewardSelection.value = null
  showCheckout.value = true
}
async function submitCheckout() {
  checkoutErr.value = ''
  const v = validateAnswers(signupFields.value, answers.value)
  ansErrors.value = v.errors
  if (!v.ok) { checkoutErr.value = 'Please answer the required questions.'; return }
  if (!isCustomer.value) {
    if (!contact.value.full_name.trim() || !contact.value.email.trim()) { checkoutErr.value = 'Please enter your name and email.'; return }
  }
  // openCheckout() gates on ensureSignedIn(), so getting here signed out means the
  // session expired. Enrolling needs a Sanctum token — re-gate instead of continuing.
  if (!isCustomer.value && !(await ensureSignedIn())) {
    checkoutErr.value = 'Please sign in to complete your sign-up.'
    return
  }
  if (rewardSelection.value && !rewardsControl.value?.validate()) { checkoutErr.value = 'Review your refreshed points discount before confirming.'; return }
  submitting.value = true
  // Never quietly charge a different amount than shown: if the trial is already used
  // at this business, surface the new total and make them confirm again.
  if (!introUsed.value && offerModeFor(p.value) === 'trial' && p.value.owner_user_id) {
    const used = await hasUsedIntroOffer({ email: customer.value?.email || '', ownerUserId: p.value.owner_user_id })
    if (used) {
      introUsed.value = true
      submitting.value = false
      checkoutErr.value = 'A free trial was already used at this business with this account, so the trial no longer applies. Review the updated total, then confirm to join at standard pricing.'
      return
    }
  }
  const res = await enrollInProgram(p.value, { customer: customer.value, answers: answers.value, discount: promoDiscount.value, promocode: promo.value?.code?.code || null, rewards: rewardSelection.value })
  if (res.error) { submitting.value = false; checkoutErr.value = res.error; return }

  // Paid memberships finish on Stripe; free ones complete here.
  if (res.url) { window.location.href = res.url; return }
  submitting.value = false
  clearCustomerLoyaltyCache()
  promo.value = null; promoInput.value = ''
  enrolled.value = res.subscription || null
  done.value = true
}

// Returning from Stripe: ?checkout=<session id> means paid, ?checkout=cancelled means
// they backed out. The webhook is authoritative, so poll briefly until it lands.
async function resumeFromStripe() {
  const q = route.query.checkout
  if (!q) return
  const clean = () => router.replace({ path: route.path, query: {} })
  if (q === 'cancelled') { clean(); return }

  submitting.value = true
  for (let i = 0; i < 6; i++) {
    const st = await checkoutState(String(q))
    if (st.status === 'ready') {
      enrolled.value = st.subscription
      done.value = true
      break
    }
    await new Promise(r => setTimeout(r, 1500))
  }
  submitting.value = false
  clearCustomerLoyaltyCache()
  clean()
}
</script>

<template>
  <div>
    <router-link to="/programs" class="cback"><ArrowLeft :size="16" /> All classes</router-link>
    <div v-if="loading" class="cmsg">Loading…</div>
    <div v-else-if="!data" class="cmsg">Membership not found.</div>
    <div v-else class="pd">
      <div class="pd-hero" :class="{ 'has-photo': heroPhoto }" :style="heroPhoto ? { backgroundImage: `url(${heroPhoto})` } : { background: p.color || '#7c3aed' }"
        :role="heroPhoto ? 'button' : undefined" :aria-label="heroPhoto ? 'View photos full screen' : undefined"
        @click="heroPhoto && (lightbox = true)">
        <div v-if="!heroPhoto" class="pd-hero-fallback"><Dumbbell :size="52" /></div>
        <span v-if="offerBadge" class="pd-trial"><Gift :size="13" /> {{ offerBadge }}</span>
        <span v-if="heroPhoto" class="pd-hero-expand"><Expand :size="15" /></span>
      </div>
      <div v-if="gallery.length > 1" class="pd-thumbs">
        <button v-for="(ph, i) in gallery" :key="i" type="button" class="pd-thumb"
          :class="{ on: i === activePhoto }" :style="{ backgroundImage: `url(${ph})` }"
          @click="activePhoto = i" :aria-label="`Photo ${i + 1}`"></button>
      </div>
      <Lightbox v-if="lightbox" :images="gallery" :start="activePhoto" :alt="p.name || 'Membership'" @close="lightbox = false" />

      <div class="pd-grid">
        <div class="pd-main">
          <h1>{{ p.name }}</h1>
          <div class="pd-tags">
            <span v-if="p.sport_category" class="pd-sport">{{ p.sport_category }}</span>
            <span v-if="audience" class="pd-aud" :class="'aud-' + p.audience">{{ audience }}</span>
          </div>
          <div v-if="data.location" class="pd-loc"><MapPin :size="16" />{{ data.location.name }}<span v-if="data.location.address"> · {{ data.location.address }}</span></div>
          <p v-if="p.description" class="pd-desc">{{ p.description }}</p>

          <div class="pd-facts">
            <!-- Who can join — always shown (Mixed included) so the policy is explicit. -->
            <div class="fact fact-gp" :class="'gp-' + gender"><Users :size="16" /><span>Who can join: {{ genderLabel }}</span></div>
            <template v-if="isGym">
              <div class="fact"><Clock :size="16" /><span>Access during the venue’s opening hours</span></div>
            </template>
            <template v-else>
              <div v-if="days" class="fact"><CalendarDays :size="16" /><span>{{ days }}</span></div>
              <div v-if="p.session_time" class="fact"><Clock :size="16" /><span>{{ fmt12(p.session_time) }}<span v-if="p.session_duration_minutes"> · {{ durationLabel(p.session_duration_minutes) }}</span></span></div>
            </template>
          </div>

          <!-- Gym opening hours (the venue's weekly hours members can train within). -->
          <div v-if="isGym && openingHours" class="pd-hours">
            <div class="pd-hours-title"><CalendarDays :size="15" /> Opening hours</div>
            <div v-for="h in openingHours" :key="h.day" class="pd-hours-row" :class="{ closed: !h.open }">
              <span class="pd-hours-day">{{ h.day }}</span>
              <span class="pd-hours-val">{{ h.label }}</span>
            </div>
          </div>

          <ul class="pd-perks">
            <li><Check :size="16" /> Cancel anytime</li>
            <li v-if="hasTrial && trialSessions > 0"><Check :size="16" /> First session free</li>
            <li v-else-if="hasTrial"><Check :size="16" /> Try free for {{ trialDays }} days</li>
            <li v-if="offerMode === 'discount' && discountMonths > 0"><Check :size="16" /> {{ discountMonths }} month{{ discountMonths !== 1 ? 's' : '' }} off</li>
            <li><Check :size="16" /> {{ billingNote }}</li>
          </ul>
        </div>

        <aside class="pd-side">
          <div class="pd-price">
            <span v-if="offerMode === 'discount' && discountMonths > 0" class="pd-was">{{ cur }} {{ listPrice.toLocaleString() }}</span>
            {{ cur }} {{ payPrice.toLocaleString() }}<span v-if="!oneTime">/{{ intervalLabel(p.billing_interval) }}</span>
          </div>
          <div class="pd-billing-note">{{ billingNote }}</div>
          <div v-if="firstClassLabel" class="pd-firstclass"><CalendarDays :size="14" /> Next class: {{ firstClassLabel }}</div>
          <div v-if="joiningFee > 0" class="pd-joinfee"><Tag :size="14" /> {{ cur }} {{ joiningFee.toLocaleString() }} one-time joining fee</div>
          <div v-if="showMembers" class="pd-members"><Users :size="14" /> {{ memberCount.toLocaleString() }} active members</div>
          <div v-if="introUsed" class="pd-introused"><Gift :size="14" /> Welcome back! You've already used a free trial at this business, so this membership starts with the first payment.</div>
          <template v-if="isMember">
            <div class="pd-member"><Check :size="14" /> You're already a member</div>
            <button class="pd-btn pd-btn-manage" @click="goToMyMemberships">Manage membership</button>
          </template>
          <button v-else-if="isFull" class="pd-btn pd-btn-full" disabled><X :size="16" /> Full</button>
          <button v-else class="pd-btn" @click="openCheckout"><Check :size="16" /> {{ hasTrial ? 'Start free trial' : 'Sign up' }}</button>
        </aside>
      </div>

      <!-- mobile-only docked signup bar — mirrors the sidebar CTA context exactly
           (renders only when the membership is loaded, same v-else branch; opens the
           same checkout via openCheckout). Desktop keeps the sticky sidebar CTA. -->
      <div class="pd-dock">
        <div class="pd-dock-price">{{ cur }} {{ payPrice.toLocaleString() }}<span v-if="!oneTime">/{{ intervalLabel(p.billing_interval) }}</span></div>
        <button v-if="isMember" class="pd-dock-btn pd-dock-btn-manage" @click="goToMyMemberships"><Check :size="15" /> Manage membership</button>
        <button v-else-if="isFull" class="pd-dock-btn pd-dock-btn-full" disabled><X :size="15" /> Full</button>
        <button v-else class="pd-dock-btn" @click="openCheckout"><Check :size="15" /> {{ hasTrial ? 'Start free trial' : 'Sign up' }}</button>
      </div>
    </div>

    <!-- Membership checkout -->
    <div v-if="showCheckout" class="co-overlay" @click.self="showCheckout=false">
      <div class="co-modal">
        <button class="co-x" @click="showCheckout=false"><X :size="18" /></button>
        <template v-if="!done">
          <h2 class="co-title">{{ hasTrial ? 'Start your free trial' : 'Sign up' }}</h2>
          <div class="co-prog">{{ p.name }}</div>

          <div class="co-plan">
            <div class="co-plan-price"><span v-if="offerMode==='discount' && discountMonths>0" class="co-was">{{ cur }} {{ listPrice.toLocaleString() }}</span>{{ cur }} {{ payPrice.toLocaleString() }}<span v-if="!oneTime">/{{ intervalLabel(p.billing_interval) }}</span></div>
            <div class="co-plan-note">{{ billingNote }}</div>
            <div class="co-firstclass"><CalendarDays :size="13" /> {{ firstClassLabel ? 'Your first class: ' : 'Access starts ' }}<strong>{{ firstClassLabel || 'today' }}</strong></div>
            <!-- One-time joining fee. Charged today when there's no trial; with a trial it's
                 collected on the first charge after the trial (so we say so). -->
            <div v-if="joiningFee > 0" class="co-joinfee">
              <span><Tag :size="13" /> Joining fee</span>
              <strong>{{ cur }} {{ joiningFee.toLocaleString() }}</strong>
            </div>
            <div v-if="joiningFee > 0 && hasTrial" class="co-joinfee-note">Charged with your first payment after the free trial.</div>
            <div v-if="introUsed" class="co-introused">You've already used a free trial at this business — this signup starts with the first payment.</div>
          </div>

          <div v-if="signupFields.length" class="co-section">
            <div class="co-h">A few questions</div>
            <CustomFormFields :fields="signupFields" v-model="answers" :errors="ansErrors" />
          </div>

          <div class="co-who">Signed in as <strong>{{ customer?.full_name || customer?.email }}</strong></div>

          <div class="co-section">
            <div class="co-h"><CreditCard :size="15" /> Payment</div>
            <div class="co-stripe">
              <ShieldCheck :size="15" />
              <span>You'll finish on Stripe's secure page — card, Apple&nbsp;Pay or Google&nbsp;Pay.
                <template v-if="hasTrial">Nothing is charged until your free trial ends.</template>
              </span>
            </div>
          </div>

          <div v-if="promoEligible" class="co-section">
            <div class="co-h"><Tag :size="15" /> Promo code</div>
            <div v-if="promoDiscount > 0" class="co-promo-on">
              <span class="co-promo-tag"><Tag :size="13" /> {{ promo.code.code }} · −{{ cur }} {{ Number(promoDiscount).toLocaleString() }}</span>
              <button type="button" class="co-promo-x" @click="removePromo">Remove</button>
            </div>
            <div v-else class="co-promo-form">
              <input class="co-in" v-model="promoInput" placeholder="Promo code" @keyup.enter="applyPromo" :disabled="promoBusy" style="text-transform:uppercase" />
              <button type="button" class="co-promo-btn" :disabled="promoBusy || !promoInput.trim()" @click="applyPromo">{{ promoBusy ? '…' : 'Apply' }}</button>
            </div>
            <div v-if="promoError" class="co-promo-err">{{ promoError }}</div>
          </div>

          <CheckoutRewards v-if="!isWhiteLabel" ref="rewardsControl" v-model="rewardSelection" kind="membership" :country-id="loyaltyCountryId" :currency="cur" :checkout="rewardsCheckout" :total="payNow" :due-now="hasTrial ? 0 : payNow" :active="showCheckout && !done" />

          <!-- Charged-today line: shown whenever a promo, joining fee or loyalty changes it. -->
          <div v-if="(joiningNow > 0 || promoDiscount > 0 || loyaltyAmount > 0) && !hasTrial" class="co-total">Charged today: <strong>{{ cur }} {{ Number(chargeNow).toLocaleString() }}</strong><span v-if="loyaltyAmount > 0" class="co-total-loy"> · {{ cur }} {{ Number(loyaltyAmount).toLocaleString() }} paid with points</span></div>

          <div v-if="checkoutErr" class="co-err">{{ checkoutErr }}</div>
          <button class="co-pay" :disabled="submitting" @click="submitCheckout">
            <Loader2 v-if="submitting" :size="16" class="co-spin" /><Check v-else :size="16" />
            {{ submitting ? 'Processing…' : payLabel }}
          </button>
          <div class="co-fine">{{ hasTrial ? `Free now, then ${cur} ${payPrice.toLocaleString()}.` : 'You can cancel anytime.' }}</div>
        </template>

        <div v-else class="co-done">
          <div class="co-done-ic"><Check :size="30" /></div>
          <h2 class="co-title">You're signed up!</h2>
          <p class="co-done-sub">{{ hasTrial ? 'Your free trial has started.' : `You're enrolled in ${p.name}.` }} The provider can see your details{{ signupFields.length ? ' and answers' : '' }}.</p>
          <div class="co-done-facts">
            <div class="co-done-fact"><CalendarDays :size="14" /> {{ firstClassLabel ? 'First class:' : 'Access starts:' }} <strong>{{ firstClassLabel || 'today' }}</strong></div>
            <div v-if="enrolled?.next_billing_at" class="co-done-fact"><CreditCard :size="14" /> {{ hasTrial ? 'First payment:' : 'Paid until:' }} <strong>{{ fmtD(enrolled.next_billing_at) }}</strong></div>
            <div class="co-done-fact"><Tag :size="14" /> Your pass is ready in <router-link to="/my-memberships" @click="showCheckout=false">My Memberships</router-link></div>
          </div>
          <button class="co-pay" @click="showCheckout=false">Done</button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.cback { display: inline-flex; align-items: center; gap: 6px; color: var(--brand-ink, var(--brand)); text-decoration: none; font-weight: 700; font-size: 14px; margin-bottom: 16px; }
.cmsg { text-align: center; padding: 50px; color: var(--text-light, #94a3b8); }
.pd-hero { height: 220px; border-radius: 20px; background-size: cover; background-position: center; position: relative; }
.pd-hero.has-photo { cursor: zoom-in; }
.pd-hero-fallback { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; color: rgba(255,255,255,.85); }
.pd-hero-expand { position: absolute; bottom: 10px; inset-inline-end: 10px; width: 32px; height: 32px; border-radius: 10px; background: rgba(8,12,20,.55); color: #fff; display: flex; align-items: center; justify-content: center; pointer-events: none; }
.pd-thumbs { display: flex; gap: 8px; margin-top: 10px; overflow-x: auto; padding-bottom: 2px; }
.pd-thumb { flex: 0 0 auto; width: 78px; height: 58px; border-radius: 10px; background-size: cover; background-position: center; border: 2px solid transparent; cursor: pointer; padding: 0; opacity: .75; transition: border-color .15s, opacity .15s; }
.pd-thumb:hover { opacity: 1; }
.pd-thumb.on { border-color: var(--brand); opacity: 1; }
.pd-trial { position: absolute; top: 14px; left: 14px; font-size: 13px; font-weight: 800; color: var(--success-ink, #065f46); background: var(--surface, #ffffff); border-radius: 999px; padding: 6px 14px; display: inline-flex; align-items: center; gap: 5px; }
.pd-grid { display: grid; grid-template-columns: 1fr 300px; gap: 24px; margin-top: 20px; }
.pd-main h1 { font-size: 28px; font-weight: 900; color: var(--text, #0f172a); margin: 0; }
.pd-tags { display: flex; flex-wrap: wrap; gap: 8px; margin: 10px 0; }
.pd-sport { display: inline-block; font-size: 13px; font-weight: 700; color: var(--violet-ink, #6d28d9); background: var(--violet-bg, #ede9fe); padding: 4px 12px; border-radius: 999px; }
.pd-aud { display: inline-block; font-size: 13px; font-weight: 800; color: #fff; padding: 4px 12px; border-radius: 999px; background: #475569; }
.pd-aud.aud-kids { background: #d97706; }
.pd-aud.aud-adults { background: #2563eb; }
.pd-aud.aud-both { background: #7c3aed; }
.pd-aud.aud-ladies { background: #db2777; }
.pd-aud.aud-men { background: #0d9488; }
.pd-loc { display: flex; align-items: center; gap: 8px; color: var(--text-muted, #64748b); font-size: 14.5px; }
.pd-introused { display: flex; align-items: flex-start; gap: 7px; font-size: 12.5px; line-height: 1.5; color: var(--warning-ink, #92400e); background: var(--warning-bg, #fffbeb); border: 1px solid var(--warning-border, #fde68a); border-radius: 10px; padding: 9px 11px; margin-bottom: 10px; text-align: start; }
.pd-introused svg { flex-shrink: 0; margin-top: 1px; }
.co-introused { font-size: 12.5px; line-height: 1.5; color: var(--warning-ink, #92400e); background: var(--warning-bg, #fffbeb); border: 1px solid var(--warning-border, #fde68a); border-radius: 8px; padding: 8px 10px; margin-top: 8px; }
.pd-hours { margin-top: 16px; border: 1px solid var(--border, #e2e8f0); border-radius: 12px; overflow: hidden; max-width: 360px; }
.pd-hours-title { display: flex; align-items: center; gap: 7px; font-size: 13px; font-weight: 800; color: var(--text, #0f172a); padding: 10px 14px; background: var(--canvas, #f8fafc); border-bottom: 1px solid var(--border, #eef2f6); }
.pd-hours-title svg { color: var(--brand-ink, var(--brand)); }
.pd-hours-row { display: flex; justify-content: space-between; gap: 12px; padding: 8px 14px; font-size: 13.5px; border-top: 1px solid var(--border, #f1f5f9); }
.pd-hours-row:first-of-type { border-top: none; }
.pd-hours-day { font-weight: 700; color: var(--text-secondary, #334155); }
.pd-hours-val { color: var(--text-secondary, #475569); }
.pd-hours-row.closed .pd-hours-val { color: var(--text-light, #94a3b8); }
.pd-desc { font-size: 15px; color: var(--text-secondary, #334155); line-height: 1.6; margin: 16px 0; }
.pd-facts { display: flex; flex-wrap: wrap; gap: 12px; margin: 16px 0; }
.fact { display: flex; align-items: center; gap: 7px; font-size: 14px; color: var(--text, #0f172a); font-weight: 600; background: var(--bg, #f1f5f9); padding: 9px 14px; border-radius: 12px; }
/* Gender policy fact — subtle tint: men blue-ish, women rose-ish, mixed neutral. */
.fact-gp.gp-men { background: var(--info-bg, #eff6ff); color: var(--info-ink, #1d4ed8); }
.fact-gp.gp-women { background: #fdf2f8; color: #be185d; }
.pd-perks { list-style: none; padding: 0; margin: 16px 0 0; display: flex; flex-direction: column; gap: 9px; }
.pd-perks li { display: flex; align-items: center; gap: 9px; font-size: 14.5px; color: var(--text-secondary, #334155); }
.pd-perks svg { color: var(--success-ink, #16a34a); }
.pd-side { background: var(--surface, #ffffff); border: 1px solid var(--border, #e2e8f0); border-radius: 18px; padding: 22px; height: fit-content; position: sticky; top: 20px; }
.pd-plans { display: flex; flex-direction: column; gap: 8px; margin-bottom: 16px; }
.pd-plans-label { font-size: 12px; font-weight: 700; color: var(--text-muted, #64748b); text-transform: uppercase; letter-spacing: .04em; }
.pd-plan { display: flex; flex-direction: column; align-items: flex-start; gap: 2px; text-align: left; padding: 10px 12px; border: 1.5px solid var(--border, #e2e8f0); border-radius: 12px; background: var(--surface, #ffffff); cursor: pointer; transition: border-color .12s, background .12s; }
.pd-plan:hover { border-color: var(--border-strong, #cbd5e1); }
.pd-plan.on { border-color: var(--brand, #7c3aed); background: #faf5ff; }
.pd-plan-int { font-size: 13px; font-weight: 700; color: var(--text, #0f172a); text-transform: capitalize; }
.pd-plan-price { font-size: 16px; font-weight: 900; color: var(--text, #0f172a); }
.pd-plan-trial { font-size: 11.5px; font-weight: 700; color: var(--success-ink, #16a34a); }
.pd-price { font-size: 28px; font-weight: 900; color: var(--text, #0f172a); }
.pd-price span { font-size: 15px; font-weight: 600; color: var(--text-light, #94a3b8); }
.pd-was { display: block; font-size: 15px; font-weight: 700; color: var(--text-light, #94a3b8); text-decoration: line-through; }
.pd-billing-note { font-size: 13px; color: var(--success-ink, #16a34a); font-weight: 600; margin-top: 6px; }
/* One-time joining fee note in the sidebar (neutral, informative). */
.pd-joinfee { display: flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 700; color: var(--text-secondary, #475569); margin-top: 8px; }
.pd-joinfee svg { color: var(--text-muted, #64748b); flex-shrink: 0; }
/* Social-proof line — "N active members" (subtle teal, matches the card pill). */
.pd-members { display: flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 700; color: var(--success-ink, #0f766e); margin-top: 8px; }
.pd-members svg { flex-shrink: 0; }
.pd-join-note { font-size: 13.5px; color: var(--text-muted, #64748b); margin: 12px 0 16px; line-height: 1.5; }
.pd-btn { display: flex; align-items: center; justify-content: center; gap: 8px; width: 100%; box-sizing: border-box; background: var(--brand, #7c3aed); color: #fff; font-weight: 800; padding: 14px; border-radius: 12px; border: none; cursor: pointer; text-decoration: none; font-size: 15px; margin-top: 8px; }
/* Full (at capacity) — clearly disabled, no brand fill. */
.pd-btn-full { background: var(--bg, #f1f5f9); color: var(--text-light, #94a3b8); cursor: not-allowed; }
.pd-member { display: flex; align-items: center; justify-content: center; gap: 6px; margin-bottom: 8px; padding: 8px 10px; border-radius: 10px; background: var(--success-bg, #ecfdf5); color: var(--success-ink, #047857); font-size: 13px; font-weight: 700; }
.pd-btn-manage { background: var(--surface, #fff); color: var(--text, #0f172a); border: 1px solid var(--border, #e2e8f0); }
/* mobile docked signup bar — desktop uses the sidebar CTA, so hidden by default */
.pd-dock { display: none; }
@media (max-width: 720px) {
  /* Reorder so price + primary CTA sit right under the hero, description below. */
  .pd-grid { display: flex; flex-direction: column; grid-template-columns: 1fr; margin-top: 14px; gap: 16px; }
  .pd-side { order: -1; position: static; padding: 16px 18px; }
  /* Shrink the decorative hero so the primary action rises toward the fold. */
  .pd-hero { height: auto; aspect-ratio: 16 / 9; max-height: 200px; border-radius: 14px; }
  .pd-thumbs { margin-top: 8px; }
  /* Tighten headings + block margins for less pre-action scroll (still breathable). */
  .pd-main h1 { font-size: 22px; }
  .pd-desc { margin: 12px 0; }
  .pd-facts { margin: 12px 0; }
  .pd-perks { margin-top: 12px; }
  /* Denser opening-hours table (info kept, just tighter). */
  .pd-hours-row { padding: 7px 12px; font-size: 13px; }
  /* Keep the sidebar price below the 22px/900 h1 on the type scale. */
  .pd-price { font-size: 24px; }
  /* One obvious action: de-emphasize the "Or call" link so Sign up clearly wins. */

  /* Primary Sign-up CTA docked flush to the screen bottom (flow route: shell nav is
     hidden and .cust-main already reserves calc(92px + safe-area) bottom padding for
     this bar — same pattern as GameDetail's .gd-dock). */
  .pd-dock { position: fixed; left: 0; right: 0; bottom: 0; z-index: 90; display: flex; align-items: center; justify-content: space-between; gap: 12px; background: var(--surface, #ffffff); border-top: 1px solid var(--border, #e2e8f0); box-shadow: 0 -8px 24px rgba(15,23,42,.10); padding: 10px 14px calc(10px + env(safe-area-inset-bottom, 0px)); border-radius: 0; /* full-bleed dock; stays under the z-index:200 checkout sheet */ }
  .pd-dock-price { font-size: 18px; font-weight: 900; color: var(--text, #0f172a); min-width: 0; }
  .pd-dock-price span { font-size: 12.5px; font-weight: 600; color: var(--text-light, #94a3b8); }
  .pd-dock-btn { flex-shrink: 0; display: inline-flex; align-items: center; gap: 7px; min-height: 44px; background: var(--brand, #7c3aed); color: #fff; font-weight: 800; font-size: 15px; padding: 13px 22px; border: none; border-radius: 12px; cursor: pointer; }
  .pd-dock-btn-full { background: var(--border, #e2e8f0); color: var(--text-light, #94a3b8); cursor: not-allowed; }
  .pd-dock-btn-manage { background: #047857; }
}

/* ── Membership checkout modal ── */
.co-overlay { position: fixed; inset: 0; z-index: 200; background: rgba(15,23,42,.55); display: flex; align-items: flex-start; justify-content: center; padding: 28px 16px; overflow-y: auto; }
.co-modal { position: relative; width: 100%; max-width: 440px; background: var(--surface, #ffffff); border-radius: 18px; padding: 24px; box-shadow: 0 24px 60px rgba(0,0,0,.25); }
.co-x { position: absolute; top: 14px; right: 14px; width: 34px; height: 34px; border: none; background: var(--bg, #f1f5f9); border-radius: 9px; color: var(--text-secondary, #475569); cursor: pointer; display: flex; align-items: center; justify-content: center; }
.co-title { font-size: 20px; font-weight: 900; color: var(--text, #0f172a); margin: 0 30px 2px 0; }
.co-prog { font-size: 14px; color: var(--text-muted, #64748b); margin-bottom: 14px; }
.co-plan { background: var(--canvas, #f8fafc); border: 1px solid var(--border, #e2e8f0); border-radius: 12px; padding: 12px 14px; margin-bottom: 16px; }
.co-plan-price { font-size: 22px; font-weight: 900; color: var(--text, #0f172a); }
.co-plan-price span { font-size: 14px; font-weight: 600; color: var(--text-light, #94a3b8); }
.co-was { display: inline; font-size: 14px; font-weight: 700; color: var(--text-light, #94a3b8); text-decoration: line-through; margin-right: 8px; }
.co-plan-note { font-size: 12.5px; color: var(--success-ink, #16a34a); font-weight: 600; margin-top: 3px; }
.co-firstclass { display: flex; align-items: center; gap: 6px; font-size: 12.5px; color: var(--text-secondary, #334155); margin-top: 7px; }
.co-firstclass strong { color: var(--text, #0f172a); }
.pd-firstclass { display: flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 600; color: var(--text-secondary, #334155); margin-top: 8px; }
.pd-firstclass svg { color: var(--brand-ink, var(--brand)); }
.co-done-facts { display: flex; flex-direction: column; gap: 7px; margin: 4px 0 16px; text-align: left; background: var(--canvas, #f8fafc); border: 1px solid var(--border, #e2e8f0); border-radius: 12px; padding: 12px 14px; }
.co-done-fact { display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--text-secondary, #334155); flex-wrap: wrap; }
.co-done-fact strong { color: var(--text, #0f172a); }
.co-done-fact a { color: var(--brand-ink, var(--brand)); font-weight: 700; }
/* Joining-fee line inside the checkout plan card. */
.co-joinfee { display: flex; align-items: center; justify-content: space-between; gap: 10px; font-size: 13px; color: var(--text-secondary, #334155); margin-top: 8px; padding-top: 8px; border-top: 1px dashed var(--border, #e2e8f0); }
.co-joinfee span { display: inline-flex; align-items: center; gap: 5px; font-weight: 600; }
.co-joinfee span svg { color: var(--text-muted, #64748b); }
.co-joinfee strong { font-weight: 900; color: var(--text, #0f172a); }
.co-joinfee-note { font-size: 11.5px; color: var(--text-light, #94a3b8); margin-top: 4px; }
/* Standalone "Charged today" total (joining fee, no promo shown). */
.co-total { font-size: 13.5px; color: var(--text, #0f172a); text-align: right; margin-bottom: 10px; }
.co-total-loy { color: var(--warning-ink, #b45309); font-weight: 700; }
/* Loyalty checkbox in the membership checkout */
.co-loy { display: flex; align-items: flex-start; gap: 10px; background: var(--warning-bg, #fffbeb); border: 1px solid var(--warning-border, #fde68a); border-radius: 12px; padding: 11px 13px; margin-bottom: 12px; cursor: pointer; }
.co-loy-cb { margin-top: 2px; width: 17px; height: 17px; accent-color: var(--brand); flex-shrink: 0; }
.co-loy-txt { display: flex; flex-direction: column; gap: 2px; }
.co-loy-lbl { display: inline-flex; align-items: center; gap: 6px; font-size: 13.5px; font-weight: 800; color: var(--warning-ink, #92400e); }
.co-loy-sub { font-size: 12px; color: var(--warning-ink, #b45309); font-weight: 600; }
.co-total strong { font-weight: 900; }
.co-who { font-size: 13px; color: var(--text-secondary, #475569); background: var(--canvas, #f8fafc); border: 1px solid var(--border, #e2e8f0); border-radius: 10px; padding: 10px 12px; margin-bottom: 16px; }
.co-who strong { color: var(--text, #0f172a); }
.co-section { margin-bottom: 16px; }
.co-h { display: flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 800; color: var(--text, #0f172a); margin-bottom: 9px; }
.co-in { width: 100%; box-sizing: border-box; height: 44px; border: 1.5px solid var(--border, #e2e8f0); border-radius: 10px; padding: 0 12px; font-size: 14px; color: var(--text, #0f172a); margin-bottom: 8px; }
.co-in:focus { outline: none; border-color: var(--brand, #7c3aed); }
.co-row { display: flex; gap: 8px; }
.co-row .co-in { margin-bottom: 0; }
.co-stripe {
  display: flex; align-items: flex-start; gap: 8px; padding: 10px 12px;
  background: var(--success-bg, #f0fdf4); border: 1px solid var(--success-border, #bbf7d0); border-radius: 10px;
  font-size: 12.5px; color: var(--success-ink, #166534); line-height: 1.5;
}
.co-stripe svg { flex-shrink: 0; margin-top: 1px; }
.co-demo { font-size: 11.5px; color: var(--text-light, #94a3b8); margin-top: 6px; }
.co-promo-form { display: flex; gap: 8px; }
.co-promo-form .co-in { margin-bottom: 0; }
.co-promo-btn { flex-shrink: 0; height: 44px; border: 1.5px solid var(--brand); background: var(--surface, #ffffff); color: var(--brand-ink, var(--brand)); font-weight: 800; font-size: 13px; padding: 0 16px; border-radius: 10px; cursor: pointer; }
.co-promo-btn:disabled { opacity: .5; cursor: default; }
.co-promo-on { display: flex; align-items: center; justify-content: space-between; background: var(--success-bg, #ecfdf5); border: 1px solid var(--success-border, #a7f3d0); border-radius: 10px; padding: 9px 12px; }
.co-promo-tag { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 800; color: var(--success-ink, #065f46); }
.co-promo-x { background: none; border: none; color: var(--success-ink, #047857); font-size: 12.5px; font-weight: 700; cursor: pointer; text-decoration: underline; }
.co-promo-total { font-size: 13px; color: var(--text, #0f172a); margin-top: 8px; }
.co-promo-total strong { font-weight: 900; }
.co-promo-err { font-size: 12.5px; color: var(--danger-ink, #dc2626); margin-top: 6px; }
.co-err { font-size: 13px; color: var(--danger-ink, #dc2626); font-weight: 600; margin-bottom: 10px; }
.co-pay { display: flex; align-items: center; justify-content: center; gap: 8px; width: 100%; background: var(--brand, #7c3aed); color: #fff; font-weight: 800; padding: 14px; border-radius: 12px; border: none; cursor: pointer; font-size: 15px; }
.co-pay:disabled { opacity: .6; cursor: not-allowed; }
.co-spin { animation: cospin 1s linear infinite; }
@keyframes cospin { to { transform: rotate(360deg); } }
.co-fine { text-align: center; font-size: 12px; color: var(--text-light, #94a3b8); margin-top: 10px; }
.co-done { text-align: center; padding: 12px 4px; }
.co-done-ic { width: 60px; height: 60px; border-radius: 50%; background: var(--success-bg, #dcfce7); color: var(--success-ink, #16a34a); display: flex; align-items: center; justify-content: center; margin: 0 auto 14px; }
.co-done-sub { font-size: 14px; color: var(--text-secondary, #475569); line-height: 1.5; margin: 0 0 18px; }

/* Mobile checkout: bottom-sheet + 16px inputs + bigger close target.
   Placed after the base .co-* rules so source order wins the cascade. */
@media (max-width: 720px) {
  .co-overlay { align-items: flex-end; padding: 0; }
  .co-modal { max-width: 100%; border-radius: 18px 18px 0 0; max-height: 92vh; overflow-y: auto; padding: 20px 16px 24px; padding-bottom: calc(24px + env(safe-area-inset-bottom)); } /* clear the home indicator */
  .co-in { font-size: 16px; }               /* stops iOS focus-zoom */
  .co-x { width: 40px; height: 40px; }      /* 44px-ish tap target */
  .co-who { overflow-wrap: anywhere; }      /* long signed-in emails can't widen the sheet */
}
</style>
