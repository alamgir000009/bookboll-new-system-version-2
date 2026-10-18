<script setup>
import { computed, ref, watch, nextTick } from 'vue'
import { useRouter } from 'vue-router'
import { Search, ArrowUpRight, X } from 'lucide-vue-next'
import { useCurrentUser } from '../lib/currentUser.js'
import { searchFeatures } from '../lib/featureSearch.js'

const router = useRouter()
const { role, currentOverrides, isProviderScoped } = useCurrentUser()
const query = ref(''), open = ref(false), active = ref(-1), input = ref(null)
const results = computed(() => searchFeatures(query.value, { role: role.value, overrides: currentOverrides.value, providerScoped: isProviderScoped.value }))
watch(query, () => { active.value = -1; open.value = true })
watch(results, () => { active.value = -1 })
watch(active, async index => {
  if (index < 0) return
  await nextTick()
  document.getElementById(`feature-result-${index}`)?.scrollIntoView({ block: 'nearest' })
})
function navigate(item) { open.value = false; router.push(item.to) }
function onKey(event) {
  if (event.key === 'Escape') { open.value = false; active.value = -1 }
  if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
    event.preventDefault(); open.value = true
    const count = results.value.length
    if (count) active.value = active.value < 0
      ? (event.key === 'ArrowDown' ? 0 : count - 1)
      : (active.value + (event.key === 'ArrowDown' ? 1 : count - 1)) % count
  }
  if (event.key === 'Enter' && open.value && results.value.length) { event.preventDefault(); navigate(results.value[Math.max(0, active.value)]) }
}
function clear() { query.value = ''; input.value?.focus() }
function onBlur(event) { if (!event.currentTarget.contains(event.relatedTarget)) open.value = false }
</script>

<template>
  <section class="feature-search" aria-label="Find a page or feature" @focusout="onBlur">
    <label for="feature-search-input">What would you like to do?</label>
    <div class="feature-search-field">
      <Search :size="21" aria-hidden="true" />
      <input ref="input" id="feature-search-input" v-model="query" type="search" role="combobox" autocomplete="off"
        placeholder="Search pages, settings or tasks…" aria-autocomplete="list" :aria-expanded="open"
        aria-controls="feature-search-results" :aria-activedescendant="open && active >= 0 ? `feature-result-${active}` : undefined"
        @focus="open = true" @keydown="onKey" />
      <button v-if="query" type="button" aria-label="Clear search" @click="clear"><X :size="18" /></button>
    </div>
    <div v-if="open" class="feature-search-panel">
      <p class="feature-search-caption" role="status">{{ query ? `${results.length} matching ${results.length === 1 ? 'destination' : 'destinations'}` : 'Quick destinations' }}</p>
      <ul id="feature-search-results" role="listbox" aria-label="Pages and actions">
        <li v-for="(item, i) in results" :key="item.id" role="presentation">
          <a :id="`feature-result-${i}`" :href="item.to" role="option" :aria-selected="active === i" :class="{ active: active === i }" @click.prevent="navigate(item)" @mousemove="active = i">
            <span><small>{{ item.group }}</small><strong>{{ item.title }}</strong><span>{{ item.description }}</span></span><ArrowUpRight :size="18" aria-hidden="true" />
          </a>
        </li>
      </ul>
      <p v-if="!results.length" class="feature-search-empty">No matching page available for your account. Try “website”, “bookings” or “payments”.</p>
      <div class="feature-search-keys">Use ↑ ↓ to choose, Enter to open, Esc to close.</div>
    </div>
    <div class="feature-search-examples"><span>Try</span><button v-for="example in ['Online payments', 'Website colours', 'Opening hours']" :key="example" type="button" @click="query = example; input?.focus()">{{ example }}</button></div>
  </section>
</template>

<style scoped>
.feature-search { position:relative; margin:24px 0; max-width:840px; }
.feature-search > label { display:block; font-size:16px; font-weight:750; color:var(--text); margin-bottom:10px; }
.feature-search-field { display:flex; align-items:center; gap:12px; padding:0 16px; min-height:58px; border:1px solid var(--border-strong, #94aca8); border-radius:12px; background:var(--surface); color:var(--primary-ink); }
.feature-search-field:focus-within { outline:3px solid var(--primary-soft); border-color:var(--primary); }
.feature-search-field input { min-width:0; flex:1; width:100%; border:0; outline:none; box-shadow:none; background:none; color:var(--text); font:inherit; font-size:16px; padding:16px 0; }
.feature-search-field input::-webkit-search-cancel-button { display:none; }
.feature-search-field button { display:grid; place-items:center; width:40px; height:44px; border:0; background:none; color:var(--text-muted); cursor:pointer; }
.feature-search-examples { display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-top:10px; color:var(--text-muted); font-size:12px; }
.feature-search-examples button { border:0; background:none; color:var(--primary-ink); text-decoration:underline; text-underline-offset:3px; padding:5px; font:inherit; cursor:pointer; }
.feature-search-panel { position:absolute; z-index:35; top:96px; width:100%; border:1px solid var(--border); border-radius:12px; background:var(--surface); box-shadow:0 14px 36px #12332f20; overflow:hidden; }
.feature-search-caption { margin:0; padding:12px 16px 8px; font-size:12px; color:var(--text-muted); }
.feature-search-panel ul { list-style:none; padding:0 6px; margin:0; max-height:420px; overflow-y:auto; }
.feature-search-panel a { display:flex; align-items:center; gap:14px; padding:12px; text-decoration:none; color:var(--text); border-radius:7px; }
.feature-search-panel a > span { min-width:0; flex:1; display:grid; gap:3px; }
.feature-search-panel a.active, .feature-search-panel a:hover { background:var(--primary-soft); }
.feature-search-panel small { color:var(--primary-ink); font-size:11px; }
.feature-search-panel strong { font-size:14px; }.feature-search-panel a span span { color:var(--text-muted); font-size:12px; line-height:1.5; }
.feature-search-keys { padding:10px 16px; font-size:11px; color:var(--text-muted); border-top:1px solid var(--border); }
.feature-search-empty { padding:12px 16px; color:var(--text-muted); font-size:14px; line-height:1.6; }
.feature-search :is(a,button):focus-visible { outline:2px solid var(--primary); outline-offset:2px; }
@media(max-width:600px) { .feature-search-panel { position:static; margin-top:8px; }.feature-search-examples button { min-height:40px; }.feature-search-keys { display:none; } }
</style>
