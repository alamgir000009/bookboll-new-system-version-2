<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { isCustomer, customer, selectedCountry, loadCustomerRewards } from '../../lib/customerSession.js'
import { apiFetch, getCustomerToken } from '../../lib/api.js'
import { verifiedRewardRules, rewardExample } from '../../lib/countryRewards.js'
import { Trophy, Sparkles, Shield, Star, Flame, Crown, ArrowRight, Globe2, Info, Check, RotateCcw } from 'lucide-vue-next'

const countries = ref([])
const countryId = ref(selectedCountry.value?.id || '')
const selected = computed(() => countries.value.find(c => String(c.id) === String(countryId.value)) || (String(selectedCountry.value?.id) === String(countryId.value) ? selectedCountry.value : null))
const currency = computed(() => selected.value?.currency_code || '')
const countryName = computed(() => selected.value?.name || 'this country')
const rules = ref(null)
const loy = ref(null)
const loading = ref(false)
const error = ref('')
const rulesError = ref('')
const countriesError = ref('')
const countryUnavailable = ref(false)
const showAll = ref(false)
const exampleAmount = ref(100)
const signedIn = computed(() => isCustomer.value && !!getCustomerToken())
const ledger = computed(() => loy.value?.ledger || [])
const shownLedger = computed(() => showAll.value ? ledger.value : ledger.value.slice(0,5))
const tier = computed(() => loy.value?.ranksConfigured ? loy.value.tier : null)
const multiplier = computed(() => Number(tier.value?.multiplier) > 0 ? Number(tier.value.multiplier) : 1)
const examplePoints = computed(() => rules.value ? rewardExample(exampleAmount.value, rules.value, multiplier.value) : 0)
const icons = { bronze: Shield, silver: Star, gold: Flame, platinum: Crown, rookie: Shield, legend: Flame, goat: Crown }
function tierIcon(t) { return icons[String(t?.slug || t?.name).toLowerCase()] || Shield }
const n = value => Number(value || 0).toLocaleString()
function activityLabel(entry) { return entry.description || entry.reason || (entry.booking_id ? 'Venue booking' : Number(entry.points) < 0 ? 'Points used or adjusted' : 'Points earned') }
function date(value) { const d = new Date(value); return Number.isNaN(d.getTime()) ? '' : d.toLocaleDateString(undefined,{day:'numeric',month:'short',year:'numeric'}) }
let revision = 0
async function load() {
  const id = ++revision, cid = countryId.value
  loy.value = null; rules.value = null; error.value = ''; rulesError.value = ''; countryUnavailable.value = false; showAll.value = false
  if (!cid) { loading.value = false; return }
  loading.value = true
  const [config, rewards] = await Promise.all([
    apiFetch('/marketplace/loyalty-config', { query: { country_id: cid } }),
    signedIn.value ? loadCustomerRewards(cid) : Promise.resolve(null),
  ])
  if (id !== revision) return
  rules.value = config.success ? verifiedRewardRules(config.data, cid) : null
  if (!rules.value) rulesError.value = 'The rewards rules for this country are not available right now.'
  if (rewards?.error) error.value = rewards.error
  else if (rewards?.countryUnavailable) countryUnavailable.value = true
  else loy.value = rewards
  loading.value = false
}
async function loadCountries() {
  countriesError.value = ''
  const res = await apiFetch('/marketplace/countries')
  if (res.success) countries.value = res.data?.countries || []
  else countriesError.value = 'Could not load countries.'
}
onMounted(loadCountries)
watch(() => selectedCountry.value?.id, id => { countryId.value = id || '' })
watch([countryId, signedIn, () => customer.value?.id], load, { immediate: true })
</script>

<template>
  <div class="rewards">
    <header class="rewards-head"><div><span class="rewards-heading-icon"><Trophy :size="24" /></span><h1>My Rewards</h1><p>Your points, your progress and how it all works.</p></div><div class="rewards-country"><label for="rewards-country"><Globe2 :size="15" /> Rewards country</label><select id="rewards-country" v-model="countryId"><option value="" disabled>Choose a country</option><option v-if="selected && !countries.length" :value="selected.id">{{ selected.name }}</option><option v-for="c in countries" :key="c.id" :value="c.id">{{ c.name }}{{ c.currency_code ? ` (${c.currency_code})` : '' }}</option></select><small>Defaults to your website country. Each country has its own rewards.</small><span v-if="countriesError" role="alert">{{ countriesError }} <button @click="loadCountries">Retry</button></span></div></header>
    <div v-if="!countryId" class="rewards-notice"><Globe2 :size="20" />Choose a country to explore its rewards.</div>
    <div v-else-if="loading" class="rewards-loading" role="status">Loading rewards for {{ countryName }}…</div>
    <template v-else>
      <section v-if="!signedIn" class="rewards-notice"><Sparkles :size="24" /><div><h2>Keep track of your rewards</h2><p>Sign in to see your points and rank for {{ countryName }}.</p></div><router-link to="/account">Sign in <ArrowRight :size="16" /></router-link></section>
      <div v-else-if="error || countryUnavailable" class="rewards-notice"><Info :size="22" /><div><h2>{{ countryUnavailable ? 'Your country balance is not available yet' : 'Could not load your points' }}</h2><p>{{ countryUnavailable ? `We can’t confirm your points and rank for ${countryName} yet. The country’s program guide is below.` : error }}</p></div><button @click="load"><RotateCcw :size="15" /> Retry</button></div>
      <section v-else-if="loy" class="rewards-overview">
        <div class="rewards-balance"><span>{{ countryName }} balance</span><strong>{{ n(loy.points) }} <small>points</small></strong><p>{{ n(loy.lifetime) }} points earned all-time in {{ countryName }}</p></div>
        <div v-if="tier" class="rewards-rank"><span class="rewards-badge" :style="{color:tier.color,background:tier.bg}"><component :is="tierIcon(tier)" :size="28" /></span><div><span>Your rank</span><h2>{{ tier.name }}</h2><p v-if="loy.next">{{ n(loy.next.threshold - loy.lifetime) }} points to {{ loy.next.name }}</p><p v-else>You've reached the highest rank.</p><div class="rewards-progress" role="progressbar" :aria-valuenow="loy.progress" aria-valuemin="0" aria-valuemax="100" aria-label="Progress to next rank"><i :style="{width:loy.progress+'%'}"></i></div></div></div><p v-else class="rewards-unconfigured">Ranks for this country are not available yet.</p>
      </section>
      <div v-if="rulesError" class="rewards-notice"><Info :size="20" /><p>{{ rulesError }}</p><button @click="load">Try again</button></div>
      <template v-if="rules">
        <div class="rewards-guide-title"><h2>How rewards work in {{ countryName }}</h2><span :class="{paused:!rules.is_active}">{{ rules.is_active ? 'Program active' : 'Program paused' }}</span></div>
        <div class="rewards-guide">
          <section><span class="rewards-step"><Sparkles :size="20" /></span><h3>Earn points</h3><p class="rewards-rate">{{ n(rules.earn_rate) }} {{ rules.earn_rate === 1 ? 'point' : 'points' }} per {{ currency }} 1</p><p>The standard earning rate for this country's program. Points are rounded down to whole numbers.</p><p v-if="multiplier !== 1">Your {{ tier.name }} rank has a {{ multiplier }}× earning multiplier.</p><div class="rewards-example"><label for="rewards-spend">Example eligible spend ({{ currency }})</label><div><input id="rewards-spend" v-model="exampleAmount" type="number" min="0" max="1000000" /><ArrowRight :size="17" /><strong>{{ n(examplePoints) }} points</strong></div><small>Amount × {{ rules.earn_rate }}{{ multiplier !== 1 ? ` × ${multiplier}` : '' }}, rounded down.</small></div><p class="rewards-note">Some listings specify their own points. Your confirmed booking and points history show the actual award. {{ !rules.is_active ? 'Earning is paused for this country.' : '' }}</p></section>
          <section><span class="rewards-step"><Trophy :size="20" /></span><h3>Use your points</h3><p class="rewards-rate">{{ n(rules.redeem_rate) }} points = {{ currency }} 1</p><p>Configured redemption value for {{ countryName }}. Points stay with the country where they were earned.</p><dl><div><dt>Minimum redemption</dt><dd>{{ n(rules.min_redeem) }} points</dd></div><div><dt>Discount limit</dt><dd>Up to the booking's platform fee{{ Number(rules.max_redeem_pct) > 0 && Number(rules.max_redeem_pct) < 100 ? ` or ${rules.max_redeem_pct}% of the total, whichever is lower` : '' }}</dd></div><div v-if="rules.expiry_months != null"><dt>Configured expiry</dt><dd>{{ rules.expiry_months ? `${rules.expiry_months} months` : 'No expiry' }}</dd></div></dl><div class="rewards-redemption-note"><Info :size="18" /><p><strong>Venue-booking redemption is not available yet.</strong> You can track your points here. When redemption is supported, eligible checkout pages will show a points option and the discount before you confirm.</p></div></section>
        </div>
      </template>
      <section v-if="loy?.ranksConfigured" class="rewards-ladder"><h2>Your ranks in {{ countryName }}</h2><p>Ranks use all-time points earned in this country, rather than your remaining balance.</p><ol><li v-for="t in loy.tiers" :key="t.slug || t.name" :class="{current:t.name === tier?.name}"><span :style="{color:t.color,background:t.bg}"><component :is="tierIcon(t)" :size="23" /></span><div><h3>{{ t.name }} <small v-if="t.name === tier?.name">Your rank</small></h3><p>{{ n(t.threshold) }} lifetime points<span v-if="t.multiplier"> · {{ t.multiplier }}× earning</span></p></div><Check v-if="loy.lifetime >= t.threshold" :size="18" /></li></ol></section>
      <section v-if="loy" class="rewards-activity"><div><h2>Points activity</h2><button v-if="ledger.length > 5" @click="showAll = !showAll">{{ showAll ? 'Show less' : 'View all activity' }}</button></div><p v-if="!ledger.length" class="rewards-empty">No points activity in {{ countryName }} yet. Confirmed awards and adjustments will appear here.</p><ul v-else><li v-for="e in shownLedger" :key="e.id"><div><strong>{{ activityLabel(e) }}</strong><span>{{ date(e.created_at) }}</span></div><b :class="{debit:Number(e.points)<0}">{{ Number(e.points)>0 ? '+' : '' }}{{ n(e.points) }} pts</b></li></ul></section>
    </template>
  </div>
</template>

<style scoped>
.rewards{max-width:1060px;margin:0 auto;padding:12px 0 30px;color:var(--text)}.rewards h1,.rewards h2,.rewards h3,.rewards p{margin:0}.rewards-head{display:flex;align-items:flex-start;justify-content:space-between;gap:40px;margin-bottom:28px}.rewards-heading-icon{display:inline-flex;vertical-align:middle;color:var(--warning-ink);margin-right:10px}.rewards h1{display:inline;font-size:30px;letter-spacing:-.7px}.rewards-head p{font-size:14px;color:var(--text-muted);margin-top:8px}.rewards-country{width:280px;flex-shrink:0}.rewards-country label{display:flex;gap:6px;align-items:center;font-size:13px;font-weight:700;margin-bottom:8px}.rewards-country select{width:100%;min-height:44px;padding:10px 12px;background:var(--surface);color:var(--text);border:1px solid var(--border-strong);border-radius:9px;font:inherit;font-size:14px}.rewards-country small{display:block;font-size:11px;color:var(--text-muted);line-height:1.5;margin-top:7px}.rewards-overview{display:grid;grid-template-columns:1fr 1fr;background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:28px;margin-bottom:30px;gap:30px}.rewards-balance>span{font-size:13px;color:var(--text-muted)}.rewards-balance>strong{display:block;font-size:40px;line-height:1.3;margin:7px 0;color:var(--text);letter-spacing:-1px}.rewards-balance strong small{font-size:16px;font-weight:500;letter-spacing:0}.rewards-balance p{font-size:12px;color:var(--text-muted)}.rewards-rank{display:flex;gap:16px;border-left:1px solid var(--border);padding-left:30px;align-items:center}.rewards-rank>div{flex:1}.rewards-rank h2{font-size:23px}.rewards-rank p,.rewards-rank div>span{font-size:12px;color:var(--text-muted)}.rewards-badge{width:60px;height:60px;display:grid;place-items:center;border-radius:15px;flex-shrink:0}.rewards-progress{height:6px;background:var(--border);border-radius:10px;overflow:hidden;margin-top:13px}.rewards-progress i{display:block;height:100%;background:var(--brand)}.rewards-notice{display:flex;gap:14px;align-items:flex-start;padding:22px;border:1px solid var(--info-border);background:var(--info-bg);border-radius:12px;margin-bottom:26px}.rewards-notice>svg{color:var(--info-ink);flex-shrink:0}.rewards-notice h2{font-size:17px}.rewards-notice p{color:var(--text-muted);font-size:14px;line-height:1.6;margin-top:4px}.rewards-notice>div,.rewards-notice>p{flex:1}.rewards-notice a,.rewards-notice button,.rewards-activity button,.rewards-country button{display:inline-flex;gap:6px;align-items:center;border:0;background:transparent;color:var(--brand-ink);font:inherit;font-size:13px;font-weight:700;cursor:pointer;text-decoration:none}.rewards-guide-title{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:18px}.rewards h2{font-size:21px;letter-spacing:-.3px}.rewards-guide-title>span{background:var(--success-bg);color:var(--success-ink);border-radius:5px;font-size:11px;padding:4px 8px;white-space:nowrap}.rewards-guide-title>span.paused{background:var(--warning-bg);color:var(--warning-ink)}.rewards-guide{display:grid;grid-template-columns:1fr 1fr;gap:20px}.rewards-guide section{background:var(--surface);border:1px solid var(--border);border-radius:13px;padding:24px}.rewards-step{display:grid;place-items:center;width:36px;height:36px;background:var(--brand-tint);border-radius:10px;color:var(--brand-ink);margin-bottom:14px}.rewards h3{font-size:18px}.rewards-guide p{font-size:13px;line-height:1.65;color:var(--text-muted);margin-top:10px;max-width:60ch}.rewards-guide .rewards-rate{font-size:22px;font-weight:750;color:var(--text);letter-spacing:-.5px;margin-top:14px}.rewards-example{padding:16px;background:var(--canvas);border:1px solid var(--border);border-radius:9px;margin-top:18px}.rewards-example label{font-size:12px;color:var(--text-muted)}.rewards-example>div{display:flex;align-items:center;gap:12px;margin-top:10px}.rewards-example input{min-width:0;width:100px;min-height:42px;padding:8px;border:1px solid var(--border-strong);background:var(--surface);color:var(--text);border-radius:6px;font:inherit;font-size:16px}.rewards-example strong{font-size:16px;color:var(--brand-ink)}.rewards-example small{display:block;margin-top:8px;font-size:11px;color:var(--text-muted)}.rewards-guide .rewards-note{font-size:12px}.rewards dl{margin:18px 0}.rewards dl>div{display:flex;justify-content:space-between;gap:18px;padding:10px 0;border-bottom:1px solid var(--border);font-size:12px}.rewards dt{color:var(--text-muted);flex:1}.rewards dd{margin:0;text-align:end;font-weight:600;max-width:58%}.rewards-redemption-note{display:flex;gap:9px;padding:13px;border-radius:8px;background:var(--warning-bg);color:var(--warning-ink)}.rewards-redemption-note svg{flex-shrink:0;margin-top:2px}.rewards-redemption-note p{margin:0;font-size:12px;color:var(--warning-ink)}.rewards-redemption-note strong{display:block;margin-bottom:4px}.rewards-ladder,.rewards-activity{margin-top:30px}.rewards-ladder>p{font-size:13px;color:var(--text-muted);margin-top:6px}.rewards-ladder ol{padding:0;list-style:none;display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:18px}.rewards-ladder li{padding:15px;display:flex;align-items:center;gap:12px;border:1px solid var(--border);background:var(--surface);border-radius:10px}.rewards-ladder li.current{border-color:var(--brand-ink)}.rewards-ladder li>span{width:40px;height:40px;display:grid;place-items:center;border-radius:10px;flex-shrink:0}.rewards-ladder li>div{flex:1}.rewards-ladder h3{font-size:14px}.rewards-ladder h3 small{font-size:10px;color:var(--brand-ink);font-weight:600;margin-left:5px}.rewards-ladder p{font-size:12px;color:var(--text-muted);margin-top:4px}.rewards-ladder li>svg{color:var(--success-ink)}.rewards-activity>div{display:flex;align-items:center;justify-content:space-between}.rewards-activity ul{padding:0;list-style:none;margin-top:16px;border-top:1px solid var(--border)}.rewards-activity li{display:flex;gap:20px;justify-content:space-between;padding:16px 0;border-bottom:1px solid var(--border)}.rewards-activity li strong{font-size:14px;font-weight:600}.rewards-activity li span{display:block;font-size:12px;color:var(--text-muted);margin-top:4px}.rewards-activity b{font-size:14px;color:var(--success-ink)}.rewards-activity b.debit{color:var(--text-muted)}.rewards-empty,.rewards-unconfigured{font-size:14px;line-height:1.6;color:var(--text-muted);padding:22px 0}.rewards-loading{padding:35px;text-align:center;color:var(--text-muted)}.rewards :is(select,input,button,a):focus-visible{outline:3px solid var(--brand-ink);outline-offset:3px}
@media(max-width:700px){.rewards{padding-top:0}.rewards-head{flex-direction:column;gap:20px}.rewards-country{width:100%}.rewards h1{font-size:26px}.rewards-overview{grid-template-columns:1fr;padding:22px;gap:20px}.rewards-rank{border-left:0;border-top:1px solid var(--border);padding:20px 0 0}.rewards-guide{grid-template-columns:1fr}.rewards-guide section{padding:20px}.rewards-guide-title{align-items:flex-start}.rewards-guide-title h2{font-size:19px}.rewards-notice{flex-wrap:wrap;padding:18px}.rewards-notice>div{flex-basis:75%}.rewards-notice>a,.rewards-notice>button{margin-left:36px}.rewards-ladder ol{grid-template-columns:1fr}.rewards-example input{width:90px}.rewards-example strong{font-size:15px}}
</style>
