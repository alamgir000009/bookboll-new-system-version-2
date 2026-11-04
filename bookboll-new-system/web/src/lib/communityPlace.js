// "Doha, Qatar" for a community.
//
// The API sends `city_name` / `country_name` beside the ids; when it does, they
// are used as-is. A server that predates those fields still sends the ids, so
// the names are looked up here instead — from the same marketplace lists the
// location picker uses, fetched once and shared across every card on a page.
import { ref, computed, watch, unref } from 'vue'
import { apiFetch } from './api.js'
import { sameLocationId } from './locationCities.js'

let countriesPromise = null
const citiesPromises = new Map()   // country id → promise of its cities

function countries() {
  countriesPromise ||= apiFetch('/marketplace/countries')
    .then(res => (res.success ? res.data?.countries || [] : []))
    .catch(() => { countriesPromise = null; return [] })
  return countriesPromise
}
function citiesOf(countryId) {
  const key = String(countryId)
  if (!citiesPromises.has(key)) {
    citiesPromises.set(key, apiFetch('/marketplace/cities', { query: { country_id: countryId } })
      .then(res => (res.success ? res.data?.cities || [] : []))
      .catch(() => { citiesPromises.delete(key); return [] }))
  }
  return citiesPromises.get(key)
}

/** { city_name, country_name } for the ids, from the cached lists. */
export async function resolveCommunityPlace({ city_id = null, country_id = null } = {}) {
  const out = { city_name: '', country_name: '' }
  if (!country_id && !city_id) return out
  const list = await countries()
  if (country_id) out.country_name = list.find(c => sameLocationId(c.id, country_id))?.name || ''
  if (city_id && country_id) {
    const cities = await citiesOf(country_id)
    out.city_name = cities.find(c => sameLocationId(c.id, city_id))?.name || ''
  }
  return out
}

export function placeLabelOf(community) {
  return [community?.city_name, community?.country_name].filter(Boolean).join(', ')
}

/**
 * A reactive "City, Country" label for a community (ref, getter or plain object).
 * Immediate when the API named the place; otherwise filled in once the lists
 * have loaded, and empty until then.
 */
export function useCommunityPlace(source) {
  const resolved = ref({ city_name: '', country_name: '' })
  const community = computed(() => typeof source === 'function' ? source() : unref(source))
  watch(() => [community.value?.city_id, community.value?.country_id, community.value?.city_name, community.value?.country_name], async () => {
    const c = community.value
    resolved.value = { city_name: '', country_name: '' }
    if (!c || (c.city_name && c.country_name) || (!c.city_id && !c.country_id)) return
    const ids = { city_id: c.city_id, country_id: c.country_id }
    const names = await resolveCommunityPlace(ids)
    // Only keep the answer if the community has not changed under us.
    const same = (a, b) => String(a ?? '') === String(b ?? '')
    if (same(community.value?.city_id, ids.city_id) && same(community.value?.country_id, ids.country_id)) resolved.value = names
  }, { immediate: true })
  return computed(() => placeLabelOf({
    city_name: community.value?.city_name || resolved.value.city_name,
    country_name: community.value?.country_name || resolved.value.country_name,
  }))
}
