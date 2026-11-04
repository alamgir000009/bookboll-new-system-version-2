import { ref } from 'vue'

export function sameLocationId(a, b) {
  return a !== null && a !== undefined && b !== null && b !== undefined && String(a) === String(b)
}

// Keep only the latest country's response. An older request must never replace
// the options or loading/error state after the user picks a different country.
export function createCountryCities(request) {
  const cities = ref([]), loading = ref(false), error = ref('')
  let revision = 0
  async function load(countryId) {
    const current = ++revision
    cities.value = []; error.value = ''; loading.value = !!countryId
    if (!countryId) return []
    try {
      const result = await request(countryId)
      if (current !== revision) return null
      if (!result.success) throw new Error('City request failed')
      cities.value = (result.data?.cities || []).filter(city =>
        city.country_id == null || sameLocationId(city.country_id, countryId))
      return cities.value
    } catch {
      if (current !== revision) return null
      error.value = 'Cities could not be loaded. Try again.'
      return []
    } finally {
      if (current === revision) loading.value = false
    }
  }
  function cancel() { revision++; loading.value = false }
  return { cities, loading, error, load, cancel }
}
