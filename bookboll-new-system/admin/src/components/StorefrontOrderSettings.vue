<script setup>
import { ref, computed, onMounted } from 'vue'
import { ArrowDownWideNarrow } from 'lucide-vue-next'
import { SORT_OPTIONS, normalizeSortKey } from '../lib/storefrontSort.js'
import { fetchStorefrontSort, saveStorefrontSortValue } from '../lib/paymentAccounts.js'
defineProps({ marketplace: Boolean })
const current = ref({ space: 'recommended', venue: 'recommended' }), saved = ref(null)
const loading = ref(true), saving = ref(false), error = ref(''), message = ref('')
const changed = computed(() => saved.value && ['space', 'venue'].some(k => current.value[k] !== saved.value[k]))
async function load() {
  loading.value = true; error.value = ''
  try { const value = await fetchStorefrontSort(); current.value = { space: normalizeSortKey(value.space), venue: normalizeSortKey(value.venue) }; saved.value = { ...current.value } }
  catch (e) { error.value = e.message }
  finally { loading.value = false }
}
async function save() {
  if (!changed.value || saving.value) return
  const wanted = { ...current.value }; saving.value = true; error.value = ''; message.value = ''
  try {
    for (const view of ['space', 'venue']) {
      if (wanted[view] === saved.value[view]) continue
      const res = await saveStorefrontSortValue(view, wanted[view])
      if (!res.success) throw new Error(res.message || 'Could not save customer ordering.')
    }
    const confirmed = await fetchStorefrontSort()
    saved.value = { ...confirmed }
    if (['space', 'venue'].some(k => confirmed[k] !== wanted[k])) throw new Error('The saved order could not be confirmed. Please retry.')
    message.value = 'Default orders saved.'
  } catch (e) { error.value = e.message }
  finally { saving.value = false }
}
onMounted(load)
</script>
<template>
  <section class="customer-order" aria-label="Customer default ordering" :aria-busy="loading || saving">
    <div class="order-intro"><ArrowDownWideNarrow :size="20" /><div><h2>{{ marketplace ? 'Marketplace default order' : 'Your website’s default order' }}</h2><p>Customers start with these orders and can choose a different sort.</p></div></div>
    <div class="order-controls">
      <label>Venue view<select v-model="current.venue" :disabled="loading || saving || !saved" @change="message = ''"><option v-for="o in SORT_OPTIONS" :key="o.key" :value="o.key">{{ o.label }}</option></select></label>
      <label>Listing view<select v-model="current.space" :disabled="loading || saving || !saved" @change="message = ''"><option v-for="o in SORT_OPTIONS" :key="o.key" :value="o.key">{{ o.label }}</option></select></label>
      <button class="btn btn-primary" type="button" :disabled="!changed || saving || loading" @click="save">{{ saving ? 'Saving…' : 'Save defaults' }}</button>
    </div>
    <p class="order-help">Recommended uses the manual order set with the reorder controls below.</p>
    <p v-if="error" class="order-error" role="alert">{{ error }} <button v-if="!saved" type="button" @click="load">Retry</button></p>
    <p v-else-if="message" class="order-success" role="status">{{ message }}</p>
  </section>
</template>
<style scoped>
.customer-order { padding:20px; margin-bottom:20px; background:var(--surface); border:1px solid var(--border); border-radius:12px; }
.order-intro { display:flex; gap:12px; align-items:flex-start; margin-bottom:16px; }
.order-intro > svg { color:var(--primary-ink, var(--primary)); flex:none; margin-top:2px; }
.order-intro h2 { font-size:16px; margin:0 0 4px; color:var(--text); }
.order-intro p,.order-help { font-size:13px; color:var(--text-muted); margin:0; line-height:1.5; }
.order-controls { display:flex; align-items:flex-end; gap:16px; flex-wrap:wrap; }
.order-controls label { flex:1; min-width:180px; display:grid; gap:7px; font-size:13px; font-weight:600; color:var(--text); }
.order-controls select { width:100%; min-height:44px; background:var(--bg); color:var(--text); border:1px solid var(--border); border-radius:8px; padding:10px; font:inherit; }
.order-controls button { min-height:44px; }
.order-help { margin-top:12px; }
.order-error,.order-success { margin:12px 0 0; font-size:14px; color:var(--danger-ink, #b91c1c); }
.order-error button { color:inherit; text-decoration:underline; background:none; border:0; padding:8px; }
.order-success { color:var(--positive-ink); }
@media(max-width:600px) { .customer-order { padding:16px; } .order-controls { display:grid; grid-template-columns:1fr; gap:12px; } .order-controls select { font-size:16px; } }
</style>
