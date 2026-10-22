<script setup>
/**
 * FilterBar — a search box, a Filters button, and an INLINE panel.
 *
 * Three decisions, all about the panel being part of the page rather than a
 * layer over it:
 *
 *  - It sits in flow and pushes the results down. It used to be absolutely
 *    positioned, floating over the rows it filtered.
 *  - Nothing closes it but the controls that say they close it. An outside
 *    click used to dismiss it, so reaching for the table mid-read made it
 *    vanish and the page appear to jump.
 *  - What is currently filtering the list is visible without opening it, as
 *    removable chips. A number on a button says how many filters are on; it
 *    does not say which, which is the question people actually have.
 *
 * Built-in Country + City (multi-select; city options auto-scoped to the chosen
 * countries). Pass page-specific filters via the default slot, and
 * right-aligned actions (Export / New / view toggle) via the #actions slot.
 *
 * Country/City v-models are ARRAYS of ids (empty = all).
 *
 *   <FilterBar v-model:search="search" v-model:country="filterCountry" v-model:city="filterCity"
 *              :countries="countries" :cities="cities" :extra-count="myExtraCount"
 *              search-placeholder="Search…" @clear="resetMyFilters">
 *     …page filters (use .lf-field / .lf-row / .lf-chips or <MultiSelectDropdown>)…
 *     <template #actions> …buttons… </template>
 *   </FilterBar>
 */
import { ref, computed, useId } from 'vue'
import { Search, SlidersHorizontal, ChevronDown, X } from 'lucide-vue-next'
import MultiSelectDropdown from './MultiSelectDropdown.vue'

const props = defineProps({
  search: { type: String, default: '' },
  country: { type: Array, default: () => [] },       // array of country ids ('' = none)
  city: { type: Array, default: () => [] },           // array of city ids
  countries: { type: Array, default: () => [] },      // [{ id, name }]
  countryLabel: { type: String, default: 'Country' }, // pages relabel when semantics differ
  cityLabel: { type: String, default: 'City' },
  cities: { type: Array, default: () => [] },          // [{ id, name, country_id }]
  searchPlaceholder: { type: String, default: 'Search…' },
  extraCount: { type: Number, default: 0 },           // active page-specific filters (for the badge)
})
const emit = defineEmits(['update:search', 'update:country', 'update:city', 'clear'])

const searchVal = computed({ get: () => props.search, set: v => emit('update:search', v) })

const open = ref(false)
const panelId = `lf-panel-${useId()}`
const showCity = computed(() => (props.cities || []).length > 1)
// A single-country list offers no real choice — hide the control entirely.
const showCountry = computed(() => (props.countries || []).length > 1)

const countryOptions = computed(() => (props.countries || []).map(c => ({ value: c.id, label: c.name })))
// City options narrow to the selected countries (all cities when none chosen).
const cityOptions = computed(() => {
  const sel = (props.country || []).map(String)
  const list = sel.length ? (props.cities || []).filter(c => sel.includes(String(c.country_id))) : (props.cities || [])
  return list.map(c => ({ value: c.id, label: c.name }))
})

const extra = computed(() => Math.max(0, Number(props.extraCount) || 0))
const activeCount = computed(() =>
  ((props.country || []).length ? 1 : 0) + ((props.city || []).length ? 1 : 0) + extra.value
)

// Setting countries prunes selected cities that no longer belong to a chosen country.
function setCountries(ids) {
  emit('update:country', ids)
  if (ids.length) {
    const allowed = new Set((props.cities || []).filter(c => ids.map(String).includes(String(c.country_id))).map(c => String(c.id)))
    emit('update:city', (props.city || []).filter(id => allowed.has(String(id))))
  }
}
function setCities(ids) { emit('update:city', ids) }
function clearAll() { emit('update:country', []); emit('update:city', []); emit('clear') }

/**
 * What is filtering the list right now, by name.
 *
 * Only values this component can actually name are shown. A chip reading "42"
 * is worse than no chip — the count on the Filters button still includes it,
 * so nothing goes unreported.
 */
const nameOf = (list, id) => (list || []).find(x => String(x.id) === String(id))?.name || ''
const chips = computed(() => [
  ...(props.country || []).map(id => ({ id: `country:${id}`, group: props.countryLabel, label: nameOf(props.countries, id), remove: () => setCountries((props.country || []).filter(v => String(v) !== String(id))) })),
  ...(props.city || []).map(id => ({ id: `city:${id}`, group: props.cityLabel, label: nameOf(props.cities, id), remove: () => setCities((props.city || []).filter(v => String(v) !== String(id))) })),
].filter(c => c.label))

const showSummary = computed(() => chips.value.length > 0 || extra.value > 0)

// No outside-click listener on purpose. An inline panel that closes when the
// user clicks the results it is filtering is the behaviour this replaced.
defineExpose({ open })
</script>

<template>
  <div class="lf-shell">
    <div class="toolbar lf-bar">
      <div class="lf-search-wrap">
        <div class="search lf-search">
          <Search :size="16" />
          <input v-model="searchVal" :placeholder="searchPlaceholder" :aria-label="searchPlaceholder" />
        </div>
      </div>

      <button
        type="button" class="lf-toggle" :class="{ on: open || activeCount }"
        :aria-expanded="open" :aria-controls="panelId"
        @click="open = !open"
      >
        <SlidersHorizontal :size="15" aria-hidden="true" />
        <span>Filters</span>
        <span v-if="activeCount" class="lf-filter-n">{{ activeCount }}</span>
        <ChevronDown :size="14" class="lf-toggle-chev" :class="{ up: open }" aria-hidden="true" />
      </button>

      <div class="lf-spacer"></div>
      <slot name="actions" />
    </div>

    <!-- What is filtering the list, without having to open anything. -->
    <div v-if="showSummary" class="lf-active" aria-label="Active filters">
      <button v-for="chip in chips" :key="chip.id" type="button" class="lf-active-chip"
        :aria-label="`Remove ${chip.group} ${chip.label}`" @click="chip.remove()">
        <span class="lf-active-group">{{ chip.group }}</span>{{ chip.label }}<X :size="13" aria-hidden="true" />
      </button>
      <button v-if="extra" type="button" class="lf-active-chip lf-active-more" @click="open = true">
        +{{ extra }} more {{ extra === 1 ? 'filter' : 'filters' }}
      </button>
      <button type="button" class="lf-clear" @click="clearAll">Clear all</button>
    </div>

    <section v-if="open" :id="panelId" class="lf-panel" @keydown.esc.stop="open = false">
      <div class="lf-panel-head">
        <strong>Filter results</strong>
        <button type="button" class="lf-panel-x" aria-label="Close filters" @click="open = false"><X :size="17" /></button>
      </div>

      <div class="lf-panel-body">
        <div v-if="showCountry || showCity" class="lf-selects">
          <div v-if="showCountry" class="lf-field">
            <label>{{ countryLabel }}</label>
            <MultiSelectDropdown :model-value="country" :options="countryOptions" all-label="All countries"
              @update:model-value="setCountries" />
          </div>
          <div v-if="showCity" class="lf-field">
            <label>{{ cityLabel }}</label>
            <MultiSelectDropdown :model-value="city" :options="cityOptions" all-label="All cities"
              @update:model-value="setCities" />
          </div>
        </div>

        <slot />
      </div>

      <div class="lf-panel-foot">
        <span class="lf-panel-note">Results update as you choose.</span>
        <button type="button" class="btn btn-secondary btn-sm" @click="open = false">Hide filters</button>
      </div>
    </section>
  </div>
</template>

<!-- Unscoped on purpose: slotted page filters (.lf-row/.lf-label/.lf-chips) compile in the
     parent's scope and wouldn't match scoped selectors. Every custom property used here is
     defined by dashboardTheme.css in BOTH palettes; a test walks this block to keep it so. -->
<style>
/* The shell keeps the spacing .toolbar used to own, so a closed FilterBar sits
   exactly where the old one did and no page needed changing. */
.lf-shell { margin-bottom: 18px; }
.lf-shell > .toolbar.lf-bar { margin-bottom: 0; }
/* The global rule reserves room for a button inside the input. Nothing sits
   there any more here — Listings.vue still has its own in-input button, so the
   reservation stays global and is only undone inside this shell. */
.lf-shell .lf-search input { padding-right: 12px; }

/* The search box fills the row and the controls sit at its end. The global
   `flex: 0 1 380px` left a short box stranded in a wide toolbar with empty
   space after it; with the spacer collapsed, the input takes that space
   instead and Filters stays alongside it. */
.lf-shell .lf-search-wrap { flex: 1 1 260px; }
.lf-shell .lf-spacer { flex: 0 0 0; min-width: 0; }

/* ── the Filters button ────────────────────────────────────────────────────
   Beside the search box, not floating inside it: a labelled control with a
   real hit area, which is also what makes room for the count and the chevron.
   (.lf-filter-btn stays untouched — Listings.vue has its own copy of it.) */
.lf-toggle {
  display: inline-flex; align-items: center; gap: 7px;
  min-height: 40px; padding: 0 13px;
  border: 1px solid var(--border); border-radius: 10px;
  background: var(--surface); color: var(--text);
  font: inherit; font-size: 13px; font-weight: 600;
  cursor: pointer; white-space: nowrap;
  transition: border-color .12s, background .12s, color .12s;
}
.lf-toggle:hover { border-color: var(--border-strong); background: var(--surface-hover); }
.lf-toggle.on { border-color: var(--primary-border); background: var(--primary-soft); color: var(--primary-ink); }
.lf-toggle:focus-visible { outline: 2px solid var(--primary-ink); outline-offset: 2px; }
.lf-toggle-chev { transition: transform .15s ease; opacity: .7; }
.lf-toggle-chev.up { transform: rotate(180deg); }

/* ── active filters, stated rather than counted ───────────────────────────── */
.lf-active { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; margin-top: 10px; }
.lf-active-chip {
  display: inline-flex; align-items: center; gap: 6px;
  min-height: 30px; padding: 0 9px;
  border: 1px solid var(--primary-border); border-radius: 999px;
  background: var(--primary-soft); color: var(--primary-ink);
  font: inherit; font-size: 12px; font-weight: 600;
  cursor: pointer; max-width: 100%;
}
.lf-active-chip:hover { border-color: var(--primary-ink); }
.lf-active-chip svg { opacity: .65; flex-shrink: 0; }
.lf-active-chip:hover svg { opacity: 1; }
/* The field name in front of the value, so "Doha" is never a mystery chip. */
.lf-active-group { font-weight: 700; opacity: .62; text-transform: uppercase; letter-spacing: .04em; font-size: 10px; }
/* Filters this component cannot name — it opens the panel instead of pretending. */
.lf-active-more { border-style: dashed; background: transparent; color: var(--text-muted); border-color: var(--border-strong); }
.lf-active-more:hover { color: var(--text); border-color: var(--text-muted); }
.lf-active .lf-clear { margin-inline-start: 4px; }

/* ── the panel ─────────────────────────────────────────────────────────────
   Geometry lives in style.css now that this is an ordinary block. This is the
   part that is only true while it is open. */
.lf-shell > .lf-panel { animation: lf-open .16s ease-out; }
@keyframes lf-open { from { opacity: 0; transform: translateY(-4px); } }
@media (prefers-reduced-motion: reduce) {
  .lf-shell > .lf-panel { animation: none; }
  .lf-toggle-chev { transition: none; }
}

.lf-shell > .lf-panel > .lf-panel-head {
  display: flex; align-items: center; gap: 10px;
  padding: 12px 16px;
  background: var(--bg);
  border-bottom: 1px solid var(--border);
}
.lf-panel-head strong { font-size: 11px; font-weight: 700; letter-spacing: .07em; text-transform: uppercase; color: var(--text-muted); }
.lf-panel-x {
  margin-inline-start: auto; display: grid; place-items: center;
  width: 30px; height: 30px; border: 0; border-radius: 8px;
  background: transparent; color: var(--text-muted); cursor: pointer;
}
.lf-panel-x:hover { background: var(--surface-hover); color: var(--text); }

.lf-panel-body { display: flex; flex-direction: column; gap: 18px; padding: 18px 16px; }
/* One shape for every field, built-in or slotted, so a page's own filters line
   up with Country/City instead of wrapping into a second rhythm. */
.lf-panel-body .lf-selects { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; }

.lf-shell > .lf-panel > .lf-panel-foot {
  display: flex; align-items: center; justify-content: space-between; gap: 10px;
  padding: 11px 16px;
  background: var(--bg);
  border-top: 1px solid var(--border);
}
.lf-panel-note { font-size: 12px; color: var(--text-muted); }

@media (max-width: 600px) {
  .lf-toggle { flex: 1 1 auto; justify-content: center; min-height: 44px; }
  .lf-panel-foot .btn { min-height: 44px; }
  .lf-clear { padding: 10px 6px; }
  .lf-panel-note { display: none; }
  .lf-active-chip { min-height: 34px; }

  /* Fields stack one per row instead of squeezing side-by-side at ~150px each. */
  .lf-panel-body .lf-selects { grid-template-columns: 1fr; }
  .lf-panel .lf-row { flex-direction: column; align-items: stretch; gap: 8px; }
  .lf-panel .lf-label { width: auto; padding-top: 0; }
}
</style>
