<script setup>
/**
 * FilterBar — a compact search box with a filter icon at its right end that opens
 * a dropdown holding all filters. Built-in Country + City (multi-select; city options
 * auto-scoped to the chosen countries). Pass page-specific filters via the default
 * slot, and right-aligned actions (Export / New / view toggle) via the #actions slot.
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
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { Search, SlidersHorizontal } from 'lucide-vue-next'
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

const activeCount = computed(() =>
  ((props.country || []).length ? 1 : 0) + ((props.city || []).length ? 1 : 0) + (Number(props.extraCount) || 0)
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
// Close on a genuine outside click — but NOT on clicks inside a teleported
// dropdown menu (MultiSelect renders its options on <body>, outside .lf-panel).
function onDocClick(e) {
  if (e?.target?.closest?.('.ms-dropdown')) return
  open.value = false
}
onMounted(() => document.addEventListener('click', onDocClick))
onBeforeUnmount(() => document.removeEventListener('click', onDocClick))
</script>

<template>
  <div class="toolbar lf-bar">
    <div class="lf-search-wrap">
      <div class="search lf-search">
        <Search :size="16" />
        <input v-model="searchVal" :placeholder="searchPlaceholder" />
        <button type="button" class="lf-filter-btn" :class="{ on: open || activeCount }" @click.stop="open = !open" title="Filters">
          <SlidersHorizontal :size="16" />
          <span v-if="activeCount" class="lf-filter-n">{{ activeCount }}</span>
        </button>
      </div>

      <div v-if="open" class="lf-panel" @click.stop>
        <div class="lf-selects">
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

        <div class="lf-panel-foot">
          <button v-if="activeCount" type="button" class="lf-clear" @click="clearAll">Clear all</button>
          <span v-else></span>
          <button type="button" class="btn btn-primary btn-sm" @click="open = false">Done</button>
        </div>
      </div>
    </div>

    <div class="lf-spacer"></div>
    <slot name="actions" />
  </div>
</template>

<!-- Unscoped on purpose: slotted page filters (.lf-row/.lf-label/.lf-chips) compile in the
     parent's scope and wouldn't match scoped selectors. Phone-only additions — desktop
     .lf-* styles in src/style.css are untouched. -->
<style>
@media (max-width: 600px) {
  /* Filter dropdown becomes a bottom sheet fixed to the viewport, so it can never be
     clipped by the admin shell's .content overflow-x:clip when the search wrap isn't
     flush with the page gutter, and long slotted filter lists scroll inside it. */
  .lf-panel {
    position: fixed;
    left: 12px;
    right: 12px;
    top: auto;
    bottom: 12px;
    width: auto;
    max-height: 70vh;
    overflow-y: auto;
    overscroll-behavior: contain;
    z-index: 200;
  }

  /* Comfortable tap targets on the bar and panel footer.
     (Input font-size 16px is already forced by the global phone layer in style.css.) */
  .lf-filter-btn { padding: 10px; }
  .lf-search input { padding-right: 48px; }
  .lf-panel-foot .btn { min-height: 44px; }
  .lf-clear { padding: 10px 6px; }

  /* Panel fields stack one per row instead of squeezing side-by-side at ~150px each. */
  .lf-panel .lf-selects { flex-direction: column; }
  .lf-panel .lf-row { flex-direction: column; align-items: stretch; gap: 8px; }
  .lf-panel .lf-label { width: auto; padding-top: 0; }
}
</style>
