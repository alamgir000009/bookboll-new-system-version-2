<script setup>
import { ref, watch } from 'vue'
import { Download, ListFilter, List, X } from 'lucide-vue-next'
import { adminBrandVars } from '../lib/adminBrand.js'
const props = defineProps({ open: Boolean, title: { type: String, default: 'Export CSV' }, noun: { type: String, default: 'records' }, filteredCount: { type: Number, default: null }, allCount: { type: Number, default: null }, busy: Boolean })
const emit = defineEmits(['close', 'export'])
const scope = ref('filtered')
watch(() => props.open, value => { if (value) scope.value = 'filtered' })
function close() { if (!props.busy) emit('close') }
</script>
<template>
  <Teleport to="body"><div v-if="open" class="export-overlay" @click.self="close">
    <form v-dialog="close" class="export-dialog" :style="adminBrandVars" :aria-busy="busy" @submit.prevent="emit('export', scope === 'filtered')">
      <header><div><h2>{{ title }}</h2><p>Choose which {{ noun }} to include.</p></div><button type="button" :disabled="busy" aria-label="Close export" @click="close"><X :size="20" /></button></header>
      <fieldset :disabled="busy"><legend>Export scope</legend>
        <label :class="{ selected: scope === 'filtered' }"><input v-model="scope" type="radio" value="filtered" /><ListFilter :size="21" /><span><strong>With current filters</strong><small>Include your search and filters across all pages.</small><b v-if="filteredCount !== null">{{ filteredCount.toLocaleString() }} {{ noun }}</b></span></label>
        <label :class="{ selected: scope === 'all' }"><input v-model="scope" type="radio" value="all" /><List :size="21" /><span><strong>Without filters</strong><small>All {{ noun }} you have permission to access.</small><b v-if="allCount !== null">{{ allCount.toLocaleString() }} {{ noun }}</b></span></label>
      </fieldset>
      <footer><button type="button" class="btn btn-secondary" :disabled="busy" @click="close">Cancel</button><button type="submit" class="btn btn-primary" :disabled="busy || (scope === 'filtered' ? filteredCount : allCount) === 0"><Download :size="16" />{{ busy ? 'Preparing CSV…' : 'Download CSV' }}</button></footer>
    </form>
  </div></Teleport>
</template>
<style scoped>
.export-overlay { position:fixed; inset:0; z-index:1200; background:#07131e80; display:flex; align-items:center; justify-content:center; padding:20px; }
.export-dialog { width:100%; max-width:500px; max-height:calc(100dvh - 40px); overflow:auto; color:var(--text); background:var(--surface); border:1px solid var(--border); border-radius:16px; box-shadow:0 24px 70px #0003; }
header { display:flex; justify-content:space-between; align-items:flex-start; gap:16px; padding:24px 24px 16px; }
header h2 { font-size:20px; margin:0 0 6px; }
header p { font-size:14px; line-height:1.5; margin:0; color:var(--text-muted); }
header button { display:grid; place-items:center; min-width:44px; height:44px; background:var(--bg); color:var(--text); border:1px solid var(--border); border-radius:8px; }
fieldset { border:0; margin:0; padding:0 24px 24px; display:grid; gap:12px; }
legend { font-size:13px; font-weight:600; margin-bottom:10px; }
fieldset label { display:flex; align-items:flex-start; gap:12px; border:1px solid var(--border); border-radius:10px; padding:16px; cursor:pointer; }
fieldset label.selected { border-color:var(--primary); background:var(--primary-soft,var(--bg)); }
fieldset input { width:18px; height:18px; flex:none; margin-top:2px; accent-color:var(--primary); }
fieldset svg { flex:none; color:var(--text-muted); }
fieldset span { display:grid; gap:6px; min-width:0; }
fieldset strong { font-size:14px; } fieldset small { font-size:13px; line-height:1.5; color:var(--text-muted); } fieldset b { font-size:12px; font-weight:500; }
footer { display:flex; justify-content:flex-end; gap:10px; padding:16px 24px; border-top:1px solid var(--border); }
footer button { min-height:44px; }
.export-dialog button:disabled { opacity:.6; cursor:not-allowed; background:var(--surface-hover); color:var(--text-muted); border-color:var(--border); }
@media(max-width:600px) { .export-overlay { padding:12px; align-items:flex-end; } .export-dialog { max-height:calc(100dvh - 24px); } header { padding:20px 16px 16px; } fieldset { padding:0 16px 20px; } footer { padding:16px; } footer button { flex:1; } }
</style>
