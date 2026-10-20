<script setup>
import { ref, computed, onMounted } from 'vue'
import { AlertTriangle, ChevronDown, ChevronUp, Loader } from 'lucide-vue-next'
import { pushToast } from '../lib/toast.js'
import {
  EMPTY_QUEUE, RESOLUTION_LABELS, REASON_LABELS,
  applyOwnSiteMethod, applySummary, loadOwnSiteReview,
} from '../lib/ownSiteReview.js'

/*
 * Own-site listings that can no longer take bookings.
 *
 * Payment on arrival was removed from that channel, so a listing set to cash
 * only now takes nothing. The listing editor already guides one at a time;
 * this is the list, so an owner with forty courts is not left opening forty
 * editors to find which ones broke.
 *
 * Deliberately offers no "fix all for me". The owner picks a method and the
 * listings it applies to — enabling deposits for them would start charging
 * their customers a fee they never agreed to collect.
 */

const emit = defineEmits(['resolved'])

const queue = ref(EMPTY_QUEUE)
const loading = ref(true)
const unsupported = ref(false)
const error = ref('')
const open = ref(false)
const busy = ref('')
const selected = ref(new Set())

const visible = computed(() => !loading.value && !unsupported.value && (queue.value.total > 0 || !!error.value))

// Platform staff see every business's listings. Saying "your website" to them
// is wrong, and offering the buttons would invite one person to start charging
// dozens of businesses' customers a deposit. The server refuses it either way;
// this stops the dashboard asking for something that cannot happen.
const isPlatform = computed(() => queue.value.scope !== 'business')
const canApplyAtAll = computed(() => queue.value.canApply === true)
const heading = computed(() => isPlatform.value
  ? `${queue.value.total} ${queue.value.total === 1 ? 'listing' : 'listings'} can’t take bookings on their provider’s website`
  : `${queue.value.total} ${queue.value.total === 1 ? 'listing' : 'listings'} on your website can’t take bookings`)
const explanation = computed(() => isPlatform.value
  ? 'Payment on arrival is no longer available for bookings made on a provider’s own website. Each business chooses how its listings take payment — open a listing to change one.'
  : 'Payment on arrival is no longer available for bookings made on your own website. Choose how each listing should take payment, or remove it from your site.')
const selectedIds = computed(() => [...selected.value])
const allSelected = computed(() => queue.value.listings.length > 0 && selected.value.size === queue.value.listings.length)

// Only offered when every selected listing can actually take it. A button that
// half-works reads as a failure the owner caused.
const canApply = method => canApplyAtAll.value && selectedIds.value.length > 0 && !busy.value
  && queue.value.listings.filter(l => selected.value.has(l.id)).every(l => l.available[method])

function toggle(id) {
  const next = new Set(selected.value)
  next.has(id) ? next.delete(id) : next.add(id)
  selected.value = next
}
function toggleAll() {
  selected.value = allSelected.value ? new Set() : new Set(queue.value.listings.map(l => l.id))
}

async function load() {
  loading.value = true
  error.value = ''
  const result = await loadOwnSiteReview()
  unsupported.value = result.unsupported === true
  queue.value = result.queue
  if (!result.ok && !result.unsupported) error.value = result.error
  selected.value = new Set()
  loading.value = false
}

async function apply(method) {
  if (!canApply(method)) return
  busy.value = method
  const result = await applyOwnSiteMethod(method, selectedIds.value)
  busy.value = ''

  const summary = applySummary(result)
  if (!result.ok) {
    error.value = result.error || summary
    pushToast(error.value, 'error')
  } else {
    pushToast(summary, result.refused?.length ? 'info' : 'success')
    // Named one by one, because "3 left unchanged" is not actionable.
    for (const row of result.refused || []) {
      pushToast(`${row.name || 'A listing'}: ${row.label}`, 'info')
    }
  }

  await load()
  emit('resolved')
}

function money(row) {
  const { currency, depositDueNow, arrivalBalance } = row.preview
  return `${currency} ${depositDueNow.toFixed(2)} now · ${currency} ${arrivalBalance.toFixed(2)} on arrival`
}

onMounted(load)
defineExpose({ reload: load })
</script>

<template>
  <section v-if="visible" class="osr" role="region" aria-labelledby="osr-title">
    <header class="osr-head">
      <AlertTriangle :size="18" aria-hidden="true" />
      <div class="osr-head-text">
        <h2 id="osr-title">{{ heading }}</h2>
        <p>
          {{ explanation }}
          <span v-if="!queue.complete && queue.total"> Showing the first {{ queue.listings.length }}.</span>
        </p>
      </div>
      <button type="button" class="btn btn-secondary" :aria-expanded="open" aria-controls="osr-body" @click="open = !open">
        {{ open ? 'Hide' : 'Review' }}
        <component :is="open ? ChevronUp : ChevronDown" :size="16" />
      </button>
    </header>

    <div v-if="error" class="osr-error" role="alert">{{ error }}</div>

    <div v-show="open" id="osr-body" class="osr-body">
      <div v-if="canApplyAtAll" class="osr-actions">
        <label class="osr-all">
          <input type="checkbox" :checked="allSelected" aria-label="Select every listing" @change="toggleAll" />
          Select all {{ queue.listings.length }}
        </label>
        <span class="osr-count">{{ selectedIds.length }} selected</span>
        <button
          v-for="method in queue.resolutions" :key="method" type="button"
          class="btn" :class="method === 'hide' ? 'btn-secondary' : 'btn-primary'"
          :disabled="!canApply(method)"
          :title="canApply(method) ? RESOLUTION_LABELS[method] : 'Not available for every selected listing'"
          @click="apply(method)"
        >
          <Loader v-if="busy === method" :size="15" class="osr-spin" />
          {{ RESOLUTION_LABELS[method] }}
        </button>
      </div>

      <ul class="osr-list">
        <li v-for="row in queue.listings" :key="row.id">
          <label class="osr-row" :class="{ 'osr-row-static': !canApplyAtAll }">
            <input v-if="canApplyAtAll" type="checkbox" :checked="selected.has(row.id)" :aria-label="`Select ${row.name}`" @change="toggle(row.id)" />
            <span class="osr-row-main">
              <strong>{{ row.name }}</strong>
              <small v-if="row.venueName">{{ row.venueName }}</small>
              <small>{{ REASON_LABELS[row.reason] }}</small>
            </span>
            <span class="osr-row-side">
              <!-- What a deposit would mean, at this business's current rate. -->
              <small>Deposit: {{ money(row) }}</small>
              <small v-if="!row.available.online" class="osr-warn">Full online payment needs connected payouts</small>
              <small v-if="!row.onMarketplace" class="osr-warn">Not on the Bookboll marketplace either</small>
            </span>
          </label>
        </li>
      </ul>
    </div>
  </section>
</template>

<style scoped>
/* The dashboard already ships light AND dark values for these three tokens
 * (dashboardTheme.css). An earlier version invented --warn-bg / --warn-ink,
 * which exist nowhere, so every rule fell through to a hardcoded light
 * fallback — a cream panel that kept its cream in dark mode while the text
 * turned light, leaving the heading unreadable. Never name a token that is
 * not defined, and never let a fallback decide the colour scheme. */
.osr { border: 1px solid var(--warning-border); border-radius: 12px; background: var(--warning-bg); margin-bottom: 18px; }
.osr-head { display: flex; align-items: flex-start; gap: 12px; padding: 16px 18px; }
.osr-head > svg { flex-shrink: 0; margin-top: 2px; color: var(--warning-ink); }
.osr-head-text { flex: 1; min-width: 0; }
/* Explicit, not inherited: the panel keeps its own background in both themes,
 * so its text has to be stated against that background rather than the page. */
.osr-head h2 { font-size: 15px; font-weight: 600; margin: 0 0 4px; color: var(--warning-ink); }
.osr-head p { font-size: 13px; line-height: 1.6; color: var(--text); margin: 0; }
.osr-head button { flex-shrink: 0; display: inline-flex; align-items: center; gap: 6px; min-height: 40px; }
.osr-error { margin: 0 18px 14px; font-size: 13px; color: var(--danger-ink); font-weight: 500; }
.osr-body { border-top: 1px solid var(--warning-border); padding: 14px 18px 18px; }
.osr-actions { display: flex; align-items: center; flex-wrap: wrap; gap: 10px; margin-bottom: 14px; }
.osr-all { display: inline-flex; align-items: center; gap: 7px; font-size: 13px; font-weight: 500; color: var(--text); }
.osr-count { font-size: 12px; color: var(--text-secondary); margin-right: auto; }
.osr-actions .btn { display: inline-flex; align-items: center; gap: 7px; min-height: 40px; font-size: 13px; }
.osr-spin { animation: osr-rotate 1s linear infinite; }
@keyframes osr-rotate { to { transform: rotate(360deg); } }
.osr-list { display: flex; flex-direction: column; gap: 8px; }
.osr-row { display: flex; align-items: flex-start; gap: 11px; padding: 11px 13px; border: 1px solid var(--border); border-radius: 10px; background: var(--surface); min-height: 44px; }
/* Read-only for platform staff: nothing to tick, so it must not look clickable. */
.osr-row-static { cursor: default; }
.osr-row-main { display: flex; flex-direction: column; gap: 2px; flex: 1; min-width: 0; }
.osr-row-main strong { font-size: 14px; font-weight: 600; color: var(--text); }
.osr-row-side { display: flex; flex-direction: column; gap: 2px; text-align: right; flex-shrink: 0; }
.osr-row small { font-size: 12px; color: var(--text-secondary); }
.osr-row .osr-warn { color: var(--warning-ink); }
@media (max-width: 720px) {
  .osr-head { flex-wrap: wrap; }
  .osr-row { flex-wrap: wrap; }
  .osr-row-side { text-align: left; }
}
</style>
