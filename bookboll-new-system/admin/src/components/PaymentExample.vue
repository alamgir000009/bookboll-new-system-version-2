<script setup>
defineProps({ currency: String, total: Number, rows: Array })
const amount = value => Number(value).toLocaleString(undefined, { maximumFractionDigits: 2 })
</script>
<template>
  <div class="payment-example">
    <div class="payment-example-title">For a {{ currency }} {{ amount(total) }} booking</div>
    <dl>
      <div v-for="row in rows" :key="row.label">
        <dt>{{ row.label }}<small>{{ row.detail }}</small></dt>
        <dd>{{ currency }} {{ amount(row.amount) }}</dd>
      </div>
    </dl>
  </div>
</template>
<style scoped>
.payment-example { border-top: 1px solid var(--border); padding-top: 14px; margin-top: 14px; }
.payment-example-title { color: var(--text-muted); font-size: 12px; margin-bottom: 10px; }
dl { margin: 0; }
dl > div { display: flex; justify-content: space-between; gap: 16px; padding: 7px 0; }
dt { font-size: 13px; color: var(--text); line-height: 1.45; }
dt small { display: block; font-size: 12px; color: var(--text-muted); margin-top: 3px; }
dd { margin: 0; white-space: nowrap; font-size: 15px; color: var(--text); font-weight: 650; }
@media(max-width:420px) { dl > div { gap: 8px; } dd { font-size: 14px; } }
</style>
