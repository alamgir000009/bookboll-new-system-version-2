<script setup>
/**
 * Where a community is based: Country, then City — the same lists the header's
 * location gate uses, so a community can only live somewhere Bookboll serves
 * and the Communities page (scoped by city) will actually show it.
 */
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import { MapPin, Loader } from 'lucide-vue-next'
import { apiFetch } from '../../lib/api.js'
import { createCountryCities, sameLocationId } from '../../lib/locationCities.js'
import { isWhiteLabel, activeBrand } from '../../lib/brand.js'

const props = defineProps({
  modelValue: { type: Object, default: () => ({ country_id: null, city_id: null }) },
  idPrefix: { type: String, default: 'community-location' },
  required: { type: Boolean, default: true },
})
const emit = defineEmits(['update:modelValue'])

const countries = ref([])
const brandCities = ref([])
const loaded = ref(false)
const countryCities = createCountryCities(cid => apiFetch('/marketplace/cities', { query: { country_id: cid } }))
onBeforeUnmount(countryCities.cancel)

const countryId = computed(() => props.modelValue?.country_id ?? '')
const cityId = computed(() => props.modelValue?.city_id ?? '')

const citiesForCountry = computed(() => {
  if (!countryId.value) return []
  if (isWhiteLabel.value) return brandCities.value.filter(c => sameLocationId(c.country_id, countryId.value))
  return countryCities.cities.value
})

function loadCities(cid) {
  if (isWhiteLabel.value || !cid) return
  countryCities.load(cid)
}
onMounted(async () => {
  if (isWhiteLabel.value) {
    const r = activeBrand.value?.regions || { countries: [], cities: [] }
    countries.value = r.countries || []
    brandCities.value = r.cities || []
  } else {
    const res = await apiFetch('/marketplace/countries')
    countries.value = res.success ? (res.data?.countries || []) : []
  }
  loaded.value = true
  loadCities(countryId.value)
})
// A country change empties the city: the old city belongs to the old country.
watch(countryId, (cid, prev) => { if (!sameLocationId(cid, prev)) loadCities(cid) })

function pickCountry(value) {
  emit('update:modelValue', { country_id: value || null, city_id: null })
}
function pickCity(value) {
  emit('update:modelValue', { country_id: countryId.value || null, city_id: value || null })
}
const countryName = computed(() => countries.value.find(c => sameLocationId(c.id, countryId.value))?.name || '')
const cityName = computed(() => citiesForCountry.value.find(c => sameLocationId(c.id, cityId.value))?.name || '')
defineExpose({ countryName, cityName })
</script>

<template>
  <div class="clp">
    <div class="clp-row">
      <label class="clp-field" :for="idPrefix + '-country'">
        <span>Country<em v-if="required" aria-hidden="true">*</em></span>
        <select :id="idPrefix + '-country'" :value="countryId" :required="required" :disabled="!loaded" @change="pickCountry($event.target.value)">
          <option value="" disabled>{{ loaded ? 'Choose a country' : 'Loading…' }}</option>
          <option v-for="c in countries" :key="c.id" :value="c.id">{{ c.name }}</option>
        </select>
      </label>
      <label class="clp-field" :for="idPrefix + '-city'">
        <span>City<em v-if="required" aria-hidden="true">*</em></span>
        <select :id="idPrefix + '-city'" :value="cityId" :required="required" :disabled="!countryId || countryCities.loading.value" @change="pickCity($event.target.value)">
          <option value="" disabled>{{ !countryId ? 'Choose a country first' : countryCities.loading.value ? 'Loading cities…' : citiesForCountry.length ? 'Choose a city' : 'No cities served here yet' }}</option>
          <option v-for="c in citiesForCountry" :key="c.id" :value="c.id">{{ c.name }}</option>
        </select>
      </label>
    </div>
    <p v-if="countryCities.error.value" class="clp-error" role="alert">{{ countryCities.error.value }} <button type="button" @click="loadCities(countryId)">Retry</button></p>
    <p v-else class="clp-hint"><template v-if="countryCities.loading.value"><Loader :size="13" /> Loading cities…</template><template v-else><MapPin :size="13" /> People browsing {{ cityName || 'this city' }} will find your community. You can change this later.</template></p>
  </div>
</template>

<style scoped>
.clp { display: flex; flex-direction: column; gap: 8px; }
.clp-row { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
.clp-field { display: flex; flex-direction: column; gap: 6px; font-size: 13px; font-weight: 600; color: var(--text); }
.clp-field em { color: var(--danger-ink, #dc2626); font-style: normal; margin-inline-start: 3px; }
.clp-field select { min-height: 46px; padding: 10px 12px; border: 1px solid var(--border); border-radius: 10px; background: var(--surface); color: var(--text); font: inherit; font-size: 15px; }
.clp-field select:disabled { opacity: .6; }
.clp-hint, .clp-error { display: flex; align-items: center; gap: 6px; font-size: 12.5px; color: var(--text-muted); margin: 0; line-height: 1.5; }
.clp-error { color: var(--danger-ink, #dc2626); }
.clp-error button { color: inherit; text-decoration: underline; background: none; border: 0; font: inherit; font-weight: 600; min-height: 32px; cursor: pointer; }
@media (max-width: 560px) { .clp-row { grid-template-columns: 1fr; } }
</style>
