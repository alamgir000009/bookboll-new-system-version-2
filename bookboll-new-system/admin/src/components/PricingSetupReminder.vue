<script setup>
import { computed } from 'vue'
import { TrendingUp, Clock, X, ArrowRight } from 'lucide-vue-next'
import { adminBrandVars } from '../lib/adminBrand.js'
const props = defineProps({ features: { type:Array, default:() => [] }, basePrice:Number, currency:String })
const emit = defineEmits(['setup','skip','close'])
const base = computed(() => Number(props.basePrice) > 0 ? Number(props.basePrice) : 100)
// No stand-in code: with no resolvable currency the example prints bare rather
// than quoting a figure in money nobody charges.
const money = value => [props.currency, Number(value.toFixed(2)).toLocaleString()].filter(Boolean).join(' ')
</script>
<template>
  <Teleport to="body"><div v-if="features.length" class="pricing-reminder-overlay" @click.self="emit('close')">
    <section v-dialog="() => emit('close')" class="pricing-reminder" :style="adminBrandVars" aria-labelledby="pricing-reminder-title">
      <header><div><p class="pricing-reminder-eyebrow">Before you continue</p><h2 id="pricing-reminder-title">Make more of your available time</h2><p>These pricing tools are optional. Set them up now, or continue with your current prices.</p></div><button type="button" class="pricing-reminder-close" aria-label="Close pricing reminder" @click="emit('close')"><X :size="20" /></button></header>
      <div class="pricing-reminder-features">
        <article v-if="features.includes('dynamic')"><div class="pricing-reminder-heading"><TrendingUp :size="21" /><h3>Dynamic pricing</h3></div><p>Set a higher price for popular days or peak hours.</p><div class="pricing-reminder-example"><small>Example: a peak slot priced 40% higher</small><strong>{{ money(base) }} <ArrowRight :size="16" /> {{ money(base * 1.4) }}</strong><span>{{ money(base * .4) }} more for that booking.</span></div><button type="button" class="btn btn-primary" @click="emit('setup','dynamic')">Set up dynamic pricing <ArrowRight :size="16" /></button></article>
        <article v-if="features.includes('extension')"><div class="pricing-reminder-heading"><Clock :size="21" /><h3>Session extension discount</h3></div><p>Encourage a longer visit when the next slot is free.</p><div class="pricing-reminder-example"><small>Example: 20% off the next slot</small><strong>+ {{ money(base * .8) }}</strong><span>Extra revenue if a customer books an otherwise empty slot.</span></div><button type="button" class="btn btn-primary" @click="emit('setup','extension')">Set up session extensions <ArrowRight :size="16" /></button></article>
      </div>
      <p class="pricing-reminder-note">Illustrative examples based on your base price, not a revenue forecast. You choose the actual prices and discounts.</p>
      <footer><button type="button" class="btn btn-secondary" @click="emit('close')">Back to pricing</button><button type="button" class="btn btn-secondary" @click="emit('skip')">Skip for now</button></footer>
    </section>
  </div></Teleport>
</template>
<style scoped>
.pricing-reminder-overlay { position:fixed; inset:0; z-index:4500; display:flex; align-items:center; justify-content:center; padding:24px; background:#07131ea6; }
.pricing-reminder { width:100%; max-width:680px; max-height:calc(100dvh - 48px); overflow:auto; color:var(--text); background:var(--surface); border:1px solid var(--border); border-radius:18px; box-shadow:0 24px 70px #0004; }
header { display:flex; align-items:flex-start; gap:16px; justify-content:space-between; padding:26px 26px 20px; }header h2 { font-size:24px; line-height:1.25; margin:0 0 10px; }header p { color:var(--text-muted); font-size:14px; line-height:1.6; margin:0; }header .pricing-reminder-eyebrow { color:var(--primary-ink,var(--primary)); font-size:12px; font-weight:700; margin-bottom:8px; }
.pricing-reminder-close { display:grid; place-items:center; width:44px; height:44px; flex-shrink:0; background:var(--bg); color:var(--text); border:1px solid var(--border); border-radius:8px; cursor:pointer; }
.pricing-reminder-features { display:grid; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); gap:14px; padding-inline:26px; }.pricing-reminder-features article { display:flex; flex-direction:column; border:1px solid var(--border); border-radius:12px; padding:18px; }.pricing-reminder-heading { display:flex; align-items:flex-start; gap:9px; }.pricing-reminder-heading svg { color:var(--primary-ink,var(--primary)); flex-shrink:0; }h3 { font-size:15px; line-height:1.4; margin:0; }article p { color:var(--text-muted); font-size:13px; line-height:1.5; margin:10px 0 14px; }article button { margin-top:auto; font-size:12px; min-height:44px; }
.pricing-reminder-example { padding:14px; background:var(--bg); border-radius:8px; margin-bottom:18px; }.pricing-reminder-example small,.pricing-reminder-example span { display:block; font-size:12px; line-height:1.5; color:var(--text-muted); }.pricing-reminder-example strong { display:flex; align-items:center; flex-wrap:wrap; gap:8px; font-size:21px; margin-block:8px; }
.pricing-reminder-note { font-size:12px; line-height:1.6; color:var(--text-muted); padding:18px 26px; margin:0; }footer { display:flex; justify-content:flex-end; gap:12px; padding:16px 26px; border-top:1px solid var(--border); background:var(--surface); position:sticky; bottom:0; }footer button { min-height:44px; }.pricing-reminder button:focus-visible { outline:2px solid var(--primary); outline-offset:3px; }
@media(max-width:600px) { .pricing-reminder-overlay { padding:12px; }.pricing-reminder { max-height:calc(100dvh - 24px); }header { padding:20px 16px 16px; }header h2 { font-size:21px; }.pricing-reminder-features { padding-inline:16px; gap:10px; }.pricing-reminder-features article { padding:14px; }.pricing-reminder-example { margin-bottom:12px; padding:10px; }.pricing-reminder-note { padding:14px 16px; }footer { padding:14px 16px; gap:8px; }footer button { flex:1; font-size:13px; } }
</style>
