<script setup>
import { CreditCard, Info } from 'lucide-vue-next'
defineProps({
  currency: String, rate: Number, price: Number, perHead: Number, total: Number, net: Number,
  count: Number, unit: String, units: String, online: Boolean, ownSpot: Boolean,
})
const amount = value => Number(value || 0).toLocaleString(undefined, { maximumFractionDigits: 2 })
</script>

<template>
  <section class="event-fee" aria-label="Hosting fee breakdown">
    <div class="event-fee-heading"><h3><CreditCard :size="18" /> Bookboll hosting fee</h3><span>{{ rate }}% of {{ online ? 'each ticket' : 'total entry prices' }}</span></div>
    <dl class="event-fee-amounts">
      <div><dt>Guest pays per {{ unit }}</dt><dd>{{ currency }} {{ amount(price) }}</dd><p>{{ online ? 'Online via Stripe' : 'Paid to you on arrival' }}</p></div>
      <div><dt>Bookboll fee per {{ unit }}</dt><dd>{{ currency }} {{ amount(perHead) }}</dd><p>{{ rate }}% of the entry price</p></div>
      <div class="event-fee-total" v-if="online"><dt>You receive per {{ unit }}</dt><dd>{{ currency }} {{ amount(net) }}</dd><p>After Bookboll’s fee</p></div>
      <div class="event-fee-total" v-else><dt>Hosting fee due at publish</dt><dd>{{ count > 0 ? currency + ' ' + amount(total) : 'To be calculated' }}</dd><p>{{ count > 0 ? 'Based on ' + count + ' ' + (count === 1 ? unit : units) : 'Choose the number of ' + units + ' next' }}</p></div>
    </dl>
    <div class="event-fee-note"><Info :size="16" /><p v-if="online">No hosting fee upfront. Bookboll’s fee is deducted from each paid ticket.</p><p v-else>The hosting fee is paid when you publish and is non-refundable, even if fewer guests join or you cancel.</p></div>
    <p class="event-fee-venue">{{ ownSpot ? 'There is no venue charge for your own spot.' : 'Any venue charges are shown separately before you publish.' }}</p>
  </section>
</template>

<style scoped>
.event-fee { margin:16px 0 24px; border:1px solid var(--border); border-radius:12px; overflow:hidden; background:var(--surface); color:var(--text); }
.event-fee-heading { display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:8px 16px; padding:16px 18px; border-bottom:1px solid var(--border); }
.event-fee-heading h3 { display:flex; align-items:center; gap:8px; margin:0; font-size:15px; font-weight:700; }
.event-fee-heading h3 svg { color:var(--brand-ink, var(--brand)); flex-shrink:0; }
.event-fee-heading > span { color:var(--text-muted); font-size:12px; }
.event-fee-amounts { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); margin:0; }
.event-fee-amounts > div { padding:18px; min-width:0; }
.event-fee-amounts > div + div { border-inline-start:1px solid var(--border); }
.event-fee-amounts dt { color:var(--text-muted); font-size:12px; line-height:1.5; }
.event-fee-amounts dd { margin:7px 0; font-size:22px; line-height:1.25; font-weight:750; overflow-wrap:anywhere; font-variant-numeric:tabular-nums; }
.event-fee-amounts p,.event-fee-venue { margin:0; color:var(--text-muted); font-size:12px; line-height:1.5; }
.event-fee-total { background:color-mix(in srgb,var(--brand) 7%,var(--surface)); }
.event-fee-total dd { color:var(--brand-ink,var(--brand)); }
.event-fee-note { display:flex; align-items:flex-start; gap:8px; padding:14px 18px 0; border-top:1px solid var(--border); font-size:13px; line-height:1.55; }
.event-fee-note svg { flex-shrink:0; margin-top:2px; color:var(--text-muted); }
.event-fee-note p { margin:0; }
.event-fee-venue { padding:8px 18px 16px 42px; }
@media(max-width:600px) {
  .event-fee-heading { padding:14px; }
  .event-fee-amounts { grid-template-columns:1fr; }
  .event-fee-amounts > div { padding:14px; }
  .event-fee-amounts > div + div { border-inline-start:0; border-top:1px solid var(--border); }
  .event-fee-amounts dd { font-size:23px; margin:4px 0; }
  .event-fee-note { padding:14px 14px 0; }
  .event-fee-venue { padding:8px 14px 14px 38px; }
}
</style>

