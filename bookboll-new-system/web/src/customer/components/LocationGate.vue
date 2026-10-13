<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { apiFetch } from '../../lib/api.js'
import { selectedCountry, selectedCity, setLocation, setUserCoords } from '../../lib/customerSession.js'
import { isWhiteLabel, activeBrand, marketplaceUrl } from '../../lib/brand.js'
import { MapPin, LocateFixed, Loader, Check, X, Globe2, ArrowRight } from 'lucide-vue-next'

const props = defineProps({ open: { type: Boolean, default: false } })
const emit = defineEmits(['done', 'dismiss'])
function dismiss() { emit('dismiss') }

const countries = ref([])
const cities = ref([])           // white-label: all served cities
const cityListForCountry = ref([]) // apex: cities loaded per country
const countryId = ref(selectedCountry.value?.id || '')
const cityId = ref(selectedCity.value?.id || '')
const detecting = ref(false)
const detectMsg = ref('')
const loaded = ref(false)

const brandName = computed(() => activeBrand.value?.name || 'This site')
const servedEmpty = computed(() => isWhiteLabel.value && loaded.value && !cities.value.length)

async function loadCitiesForCountry(cid) {
  if (!cid || isWhiteLabel.value) return
  const res = await apiFetch('/marketplace/cities', { query: { country_id: cid } })
  cityListForCountry.value = res.success ? (res.data?.cities || []) : []
}

onMounted(async () => {
  if (isWhiteLabel.value) {
    const r = activeBrand.value?.regions || { countries: [], cities: [] }
    countries.value = r.countries || []
    cities.value = r.cities || []
  } else {
    const res = await apiFetch('/marketplace/countries')
    countries.value = res.success ? (res.data?.countries || []) : []
    if (countryId.value) await loadCitiesForCountry(countryId.value)
  }
  loaded.value = true
})

const citiesForCountry = computed(() => {
  if (!countryId.value) return []
  if (isWhiteLabel.value) return cities.value.filter(c => c.country_id === countryId.value)
  return cityListForCountry.value
})

watch(countryId, async (cid) => {
  if (!isWhiteLabel.value) await loadCitiesForCountry(cid)
  if (!citiesForCountry.value.some(c => c.id === cityId.value)) cityId.value = ''
})

watch(() => props.open, (o) => {
  if (o) {
    countryId.value = selectedCountry.value?.id || ''
    cityId.value = selectedCity.value?.id || ''
    if (!isWhiteLabel.value && countryId.value) loadCitiesForCountry(countryId.value)
  }
})

const canContinue = computed(() => !!cityId.value)

async function matchGeoCountry(addr, iso) {
  const res = await apiFetch('/marketplace/countries')
  const list = res.success ? (res.data?.countries || []) : countries.value
  return list.find(c => (c.name || '').toLowerCase() === (addr.country || '').toLowerCase())
    || list.find(c => (c.iso_code || '').toUpperCase() === iso)
    || null
}

async function matchGeoCity(country, cityName) {
  if (!country?.id || !cityName) return null
  const res = await apiFetch('/marketplace/cities', { query: { country_id: country.id } })
  const list = res.success ? (res.data?.cities || []) : []
  const lower = cityName.toLowerCase()
  return list.find(c => c.name.toLowerCase() === lower || lower.includes(c.name.toLowerCase())) || null
}

function detect() {
  if (!navigator.geolocation) { detectMsg.value = 'Location not available — pick from the list.'; return }
  detecting.value = true; detectMsg.value = 'Finding you…'
  navigator.geolocation.getCurrentPosition(async (pos) => {
    try {
      const { latitude, longitude } = pos.coords
      setUserCoords(latitude, longitude)
      const res = await fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${latitude}&lon=${longitude}&zoom=10&addressdetails=1`, { headers: { 'Accept-Language': 'en' } })
      const data = await res.json()
      const addr = data.address || {}
      const iso = (addr.country_code || '').toUpperCase()
      const cityName = (addr.city || addr.town || addr.village || addr.county || '').toLowerCase()

      if (isWhiteLabel.value) {
        const realCountry = await matchGeoCountry(addr, iso)
        const realCity = realCountry ? await matchGeoCity(realCountry, cityName) : null
        if (realCountry && realCity) { setLocation(realCountry, realCity); emit('done'); return }
        detectMsg.value = realCountry ? `Found ${realCountry.name} — pick your city below.` : "Couldn't match your area — pick from the list."
        return
      }

      const matchedCountry = countries.value.find(c => (c.name || '').toLowerCase() === (addr.country || '').toLowerCase())
        || countries.value.find(c => (c.iso_code || '').toUpperCase() === iso)
      if (matchedCountry) {
        countryId.value = matchedCountry.id
        await loadCitiesForCountry(matchedCountry.id)
        const matchedCity = cityListForCountry.value.find(c =>
          c.name.toLowerCase() === cityName || cityName.includes(c.name.toLowerCase()))
        if (matchedCity) { cityId.value = matchedCity.id; detectMsg.value = `Found you in ${matchedCity.name}!` }
        else detectMsg.value = `Found ${matchedCountry.name} — pick your city.`
      } else {
        detectMsg.value = "Couldn't match your area — pick from the list."
      }
    } catch { detectMsg.value = "Couldn't detect — pick from the list." }
    finally { detecting.value = false }
  }, () => { detecting.value = false; detectMsg.value = 'Location blocked — pick from the list.' }, { timeout: 10000 })
}

function confirm() {
  const country = countries.value.find(c => c.id === countryId.value) || null
  const city = citiesForCountry.value.find(c => c.id === cityId.value) || null
  setLocation(country, city)
  emit('done')
}
</script>

<template>
  <div v-if="open" class="lg-backdrop" @click.self="dismiss">
    <div class="lg-card">
      <button class="lg-close" @click="dismiss" aria-label="Close"><X :size="18" /></button>

      <template v-if="servedEmpty">
        <div class="lg-pin"><Globe2 :size="30" /></div>
        <h1 class="lg-title">No services here yet</h1>
        <p class="lg-sub">{{ brandName }} hasn’t listed anything yet. Browse thousands of venues and classes on Bookboll instead.</p>
        <a class="lg-continue" :href="marketplaceUrl('/')"><Globe2 :size="18" /> Browse on Bookboll</a>
      </template>

      <template v-else>
      <div class="lg-pin"><MapPin :size="30" /></div>
      <h1 class="lg-title">Where are you?</h1>
      <p class="lg-sub">{{ isWhiteLabel ? `Pick a city ${brandName} operates in.` : "We'll show you venues, games and classes near you." }}</p>

      <button class="lg-detect" :disabled="detecting" @click="detect">
        <Loader v-if="detecting" :size="18" class="lg-spin" />
        <LocateFixed v-else :size="18" />
        {{ detecting ? 'Finding you…' : 'Use my current location' }}
      </button>
      <div v-if="detectMsg" class="lg-detect-msg">{{ detectMsg }}</div>

      <div class="lg-or"><span>or pick it yourself</span></div>

      <label class="lg-label">Country</label>
      <select v-model="countryId" class="lg-select">
        <option value="">Select your country</option>
        <option v-for="c in countries" :key="c.id" :value="c.id">{{ c.name }}</option>
      </select>

      <label class="lg-label">City</label>
      <select v-model="cityId" class="lg-select" :disabled="!countryId">
        <option value="">{{ countryId ? 'Select your city' : 'Pick a country first' }}</option>
        <option v-for="c in citiesForCountry" :key="c.id" :value="c.id">{{ c.name }}</option>
      </select>

      <button class="lg-continue" :disabled="!canContinue" @click="confirm">
        <Check :size="18" /> Show me what's near me
      </button>

      <p v-if="isWhiteLabel" class="lg-elsewhere">
        Can’t find the country or city you’re looking for? <span class="lg-elsewhere-more">{{ brandName }} is only available in the
        countries and cities listed above. To see anywhere else,</span>
        <a :href="marketplaceUrl('/')">browse on Bookboll <ArrowRight :size="13" /></a>
      </p>
      </template>
    </div>
  </div>
</template>

<style scoped>
.lg-backdrop { position: fixed; inset: 0; z-index: 4000; background: rgba(15, 23, 42, .55); backdrop-filter: blur(3px); display: flex; align-items: center; justify-content: center; padding: 20px; }
.lg-card { position: relative; width: 100%; max-width: 420px; background: var(--surface, #ffffff); border-radius: 22px; padding: 32px 28px; box-shadow: 0 24px 60px rgba(0,0,0,.4); text-align: center; }
.lg-close { position: absolute; top: 14px; right: 14px; width: 32px; height: 32px; border-radius: 50%; border: none; background: #f1f5f9; color: var(--text-muted, #64748b); display: flex; align-items: center; justify-content: center; cursor: pointer; }
.lg-close:hover { background: var(--border, #e2e8f0); color: #0f172a; }
.lg-pin { width: 60px; height: 60px; border-radius: 50%; background: linear-gradient(135deg,var(--brand-accent),var(--brand)); color: #fff; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px; }
.lg-title { font-size: 24px; font-weight: 800; color: #0f172a; margin: 0 0 6px; }
.lg-sub { font-size: 14px; color: var(--text-muted, #64748b); margin: 0 0 22px; line-height: 1.5; }
.lg-detect { width: 100%; display: flex; align-items: center; justify-content: center; gap: 10px; padding: 14px; border: none; border-radius: 12px; background: linear-gradient(135deg,var(--brand-accent),var(--brand)); color: #fff; font-size: 15px; font-weight: 700; cursor: pointer; }
.lg-detect:disabled { opacity: .7; }
.lg-spin { animation: spin 1s linear infinite; } @keyframes spin { to { transform: rotate(360deg); } }
.lg-detect-msg { font-size: 13px; color: var(--brand); margin-top: 10px; font-weight: 600; }
.lg-or { display: flex; align-items: center; gap: 12px; margin: 20px 0 16px; color: var(--text-light, #94a3b8); font-size: 12.5px; }
.lg-or::before, .lg-or::after { content: ''; flex: 1; height: 1px; background: var(--border, #e2e8f0); }
.lg-label { display: block; text-align: left; font-size: 13px; font-weight: 700; color: #334155; margin: 0 0 6px; }
.lg-select { width: 100%; padding: 13px 14px; border: 2px solid var(--border, #e2e8f0); border-radius: 12px; font-size: 15px; color: #0f172a; background: var(--surface, #ffffff); margin-bottom: 16px; cursor: pointer; }
.lg-select:focus { outline: none; border-color: var(--brand-accent); }
.lg-select:disabled { background: #f8fafc; color: var(--text-light, #94a3b8); }
.lg-continue { width: 100%; display: flex; align-items: center; justify-content: center; gap: 8px; padding: 15px; border: none; border-radius: 12px; background: #0f172a; color: #fff; font-size: 16px; font-weight: 800; cursor: pointer; margin-top: 6px; }
.lg-continue:disabled { opacity: .4; cursor: not-allowed; }
.lg-continue:hover:not(:disabled) { background: #1e293b; }
.lg-elsewhere { margin: 16px 0 0; font-size: 12.5px; color: var(--text-light, #94a3b8); line-height: 1.55; text-align: center; }
.lg-elsewhere a { color: var(--brand); font-weight: 700; text-decoration: none; white-space: nowrap; display: inline-flex; align-items: center; gap: 2px; vertical-align: -2px; }
.lg-elsewhere a:hover { text-decoration: underline; }
@media (max-width: 760px) {
  .lg-card { padding: 22px 18px; border-radius: 18px; }
  .lg-close { width: 44px; height: 44px; top: 8px; right: 8px; }
  .lg-pin { width: 46px; height: 46px; margin-bottom: 10px; }
  .lg-title { font-size: 20px; }
  .lg-sub { font-size: 13px; margin-bottom: 14px; }
  .lg-select { font-size: 16px; margin-bottom: 10px; }
  .lg-label { margin-bottom: 4px; }
  .lg-or { margin: 14px 0 10px; font-size: 12px; }
  .lg-detect { background: #f1f5f9; color: var(--brand); }
  .lg-elsewhere a { white-space: normal; }
  .lg-elsewhere-more { display: none; }
  .lg-continue { font-size: 15px; font-weight: 700; }
}
</style>
