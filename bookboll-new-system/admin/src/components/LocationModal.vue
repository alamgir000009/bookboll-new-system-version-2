<script setup>
import VenueCommercial from './VenueCommercial.vue'
import VenueTransfer from './VenueTransfer.vue'
import { isBookbollVenue } from '../lib/finance.js'
import PhotoOrderEditor from './PhotoOrderEditor.vue'
/**
 * LocationModal.vue — the ONE shared "New / Edit Location" form.
 *
 * Used by both Listings.vue and ClassesSubscriptions.vue so there is a single
 * source of truth for creating locations (no more duplicate quick-add form).
 *
 * Upgrades over the old inline modal:
 *   - Interactive Leaflet + OpenStreetMap picker (click map / drag pin) in
 *     addition to the "Detect my location" button.
 *   - Google Maps share-link field that can auto-drop the pin from the link.
 *   - Address is optional.
 *   - Multiple images via drag-and-drop / file picker with client-side
 *     compression, stored on our own backend, replacing the single photo-URL field.
 *
 * Props : open (Boolean), editing (location row | null)
 * Emits : close, saved(savedRow)
 */
import { ref, computed, watch, nextTick, onBeforeUnmount } from 'vue'
import {
  fetchVenueDashboard, createVenue, updateVenue, applyVenueHoursToListings, firstError,
} from '../lib/venues.js'
import { fetchGeographyCountries, fetchGeographyCities } from '../lib/geography.js'
import { useCurrentUser } from '../lib/currentUser.js'
import { useToast } from '../lib/toast.js'
import { markDirty, clearDirty, confirmDiscardIfDirty, pushDirtyScope, popDirtyScope, registerDraftSaver } from '../lib/unsavedGuard.js'
import { saveFormDraft, loadFormDraft, clearFormDraft, draftAgeLabel } from '../lib/formDrafts.js'
import { askConfirm } from '../lib/confirm.js'
import WeeklyHours from './WeeklyHours.vue'
import CreationSteps from './CreationSteps.vue'
import { VENUE_STEPS, venueIssue } from '../lib/creationFlow.js'
import { adminBrandVars } from '../lib/adminBrand.js'
import { fmtRange12 } from '../lib/slots.js'
import { useEditorDialog } from '../lib/editorDialog.js'
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'
import {
  X, MapPin, LocateFixed, Check, Clock, DollarSign, Building2,
  Upload, Trash2, Star, TriangleAlert, Loader, Image as ImageIcon, Map as MapIcon, ChevronDown,
} from 'lucide-vue-next'
import { uploadImage } from '../lib/imageUpload.js'

const props = defineProps({
  open: { type: Boolean, default: false },
  editing: { type: Object, default: null },
})
const emit = defineEmits(['close', 'saved'])

const { isSuperAdmin, isCountryAdmin, isProviderScoped, currentOwnerUserId, currentCountryId, allBusinessOwners } = useCurrentUser()
const toast = useToast()

// ── Option lists (small + self-contained so the component is portable) ──
const COMMON_TIMEZONES = [
  'Africa/Cairo','Africa/Lagos','Africa/Nairobi','America/Chicago','America/Los_Angeles',
  'America/New_York','America/Sao_Paulo','America/Toronto','Asia/Baghdad','Asia/Bangkok',
  'Asia/Colombo','Asia/Dhaka','Asia/Dubai','Asia/Hong_Kong','Asia/Jakarta','Asia/Karachi',
  'Asia/Kolkata','Asia/Kuala_Lumpur','Asia/Kuwait','Asia/Manila','Asia/Muscat','Asia/Riyadh',
  'Asia/Seoul','Asia/Shanghai','Asia/Singapore','Asia/Tehran','Asia/Tokyo','Asia/Beirut',
  'Australia/Melbourne','Australia/Sydney','Europe/Amsterdam','Europe/Berlin','Europe/Istanbul',
  'Europe/London','Europe/Madrid','Europe/Moscow','Europe/Paris','Europe/Rome','Pacific/Auckland',
  'Pacific/Honolulu','UTC',
]
const ISO_CURRENCIES = [
  { code:'AED', name:'UAE Dirham' },{ code:'SAR', name:'Saudi Riyal' },{ code:'QAR', name:'Qatari Riyal' },
  { code:'BHD', name:'Bahraini Dinar' },{ code:'KWD', name:'Kuwaiti Dinar' },{ code:'OMR', name:'Omani Rial' },
  { code:'EGP', name:'Egyptian Pound' },{ code:'JOD', name:'Jordanian Dinar' },{ code:'LBP', name:'Lebanese Pound' },
  { code:'USD', name:'US Dollar' },{ code:'EUR', name:'Euro' },{ code:'GBP', name:'British Pound' },
  { code:'TRY', name:'Turkish Lira' },{ code:'INR', name:'Indian Rupee' },{ code:'PKR', name:'Pakistani Rupee' },
  { code:'NGN', name:'Nigerian Naira' },{ code:'KES', name:'Kenyan Shilling' },{ code:'ZAR', name:'South African Rand' },
  { code:'BRL', name:'Brazilian Real' },{ code:'AUD', name:'Australian Dollar' },{ code:'CAD', name:'Canadian Dollar' },
  { code:'SGD', name:'Singapore Dollar' },{ code:'MYR', name:'Malaysian Ringgit' },{ code:'JPY', name:'Japanese Yen' },
  { code:'CNY', name:'Chinese Yuan' },
]

// ── State ──
const countries = ref([])
const cities = ref([])
const form = ref(defaultForm())
const editingId = ref(null)
const tab = ref('details')
const commercialDirty = ref(false)
const commercialBusy = ref(false)
const transferOpen = ref(false)
const originalOwnership = ref({ owner_user_id:null, business_id:null })
const originalBookboll = computed(() => isBookbollVenue(originalOwnership.value))
function transferred(venue) { transferOpen.value=false; toast.success('Venue transferred'); emit('saved',venue) }
function beginTransfer() { if (commercialDirty.value) { errorMsg.value='Save or discard the agreement before transferring this venue.'; return }; transferOpen.value=true }
const bodyEl = ref(null)
const dialogEl = ref(null)
const editorKeydown = useEditorDialog(computed(() => props.open), dialogEl)
const venueDayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday']
const venueStepIndex = computed(() => VENUE_STEPS.findIndex(step => step.id === tab.value))
const venueStep = computed(() => VENUE_STEPS[venueStepIndex.value] || VENUE_STEPS[0])
const venueStepDescription = computed(() => ({ details: 'Start with the essentials. Required fields are marked with an asterisk.', map: 'Add directions and venue photos. These are optional and can be updated later.', schedule: 'Choose when this venue is open for bookings.', review: 'Check your venue before saving. You can return to any step to make changes.' }[tab.value]))
const hasMapPin = computed(() => form.value.latitude != null && form.value.latitude !== '' && form.value.longitude != null && form.value.longitude !== '' && !coordsInvalid.value)
async function goVenueStep(step) {
  if (saving.value || !VENUE_STEPS.some(item => item.id === step)) return
  if (step === 'review' && !(await validateVenue())) return
  tab.value = step
  await nextTick()
  if (bodyEl.value) bodyEl.value.scrollTop = 0
}
async function validateVenue() {
  const issue = venueIssue(form.value, coordsInvalid.value)
  if (!issue) { errorMsg.value = ''; return true }
  triedSave.value = true
  errorMsg.value = issue.message
  tab.value = issue.step
  await nextTick()
  const field = bodyEl.value?.querySelector('.input-invalid')
  field?.scrollIntoView({ block: 'center' })
  field?.focus({ preventScroll: true })
  return false
}
function saveLocationDraftNow() {
  if (commercialBusy.value) return
  if (commercialDirty.value) { errorMsg.value='Save or discard the operating agreement before saving a venue draft.'; return }
  if (saving.value || uploading.value) return
  if (!saveFormDraft(locationDraftKey(), locationDraftSnapshot())) { errorMsg.value = 'The draft could not be saved in this browser. Keep this form open and try again.'; return }
  clearDirty()
  toast.success('Draft saved in this browser')
  emit('close')
}
const selectedCountryId = ref('')
const hours = ref(defaultHours())
const saving = ref(false)
const loading = ref(false)
const loadFailed = ref(false)
const savedFlag = ref(false)
const errorMsg = ref('')
// Photo upload state. All four were dropped when image upload moved to the
// Laravel backend, but handleFiles() and the template still use them:
// `imgError` threw on open, and `fileInput` being undefined made
// `fileInput && fileInput.click()` permanently falsy — the picker never opened.
const uploading = ref(false)
const imgError = ref('')
const fileInput = ref(null)     // the hidden <input type="file">
const dropActive = ref(false)   // drag-and-drop hover state
const triedSave = ref(false)   // failed save attempt → red-flag the missing required fields
// Apply-schedule-to-listings prompt (shown after a schedule change on a location
// that has connected listings).
const connectedListings = ref([])
const initialHoursJson = ref('')
const applyModal = ref(false)
const applyTargets = ref([])     // listing ids that will follow the new schedule
const applySaved = ref(null)     // the saved location row to emit when done
const applyClose = ref(true)
const applying = ref(false)
// True only when opened on an EXISTING location (stable; not affected by editingId
// being set after a brand-new location is created via "Save & Continue").
const editingExisting = ref(false)

function defaultForm() {
  return {
    owner_user_id: '', name: '', city_id: '', address: '', phone: '', description: '',
    photo_url: '', photos: [], google_maps_url: '', status: 'active',
    latitude: null, longitude: null, timezone_override: null, currency_override: null,
  }
}
function defaultHours() {
  // New venues start open 24/7 (close '00:00' = midnight at the END of the day) —
  // providers close days / trim hours from there, which is less work than building up.
  return Array.from({ length: 7 }, (_, i) => ({
    day_of_week: i, is_open: true, periods: [{ open_time: '00:00', close_time: '00:00' }],
  }))
}

const citiesForCountry = computed(() =>
  selectedCountryId.value
    ? cities.value.filter(c => String(c.country_id) === String(selectedCountryId.value))
    : cities.value
)

// Providers can now operate in MULTIPLE countries — each country needs its own
// wallet for full online payments (see My Wallet). The country picker is always
// available; we just default a provider to their home country.
const selectedCountryName = computed(() => countries.value.find(c => String(c.id) === String(selectedCountryId.value))?.name || '')

const resolvedGeo = computed(() => {
  const city = cities.value.find(c => String(c.id) === String(form.value.city_id))
  const country = countries.value.find(c => String(c.id) === String(city?.country_id || selectedCountryId.value))
  return {
    timezone: form.value.timezone_override || city?.timezone || country?.timezone || null,
    currency: form.value.currency_override || country?.currency_code || null,
    currencySymbol: country?.currency_symbol || null,
    countryName: country?.name || null,
    cityName: city?.name || null,
  }
})

// String(), not ===. The venue API returns ids as strings ('42') while this
// list holds numbers, so a strict compare never matched and an assigned venue
// showed a dash where its operator's name belongs.
const providerName = (id) => {
  if (id == null || id === '') return ''
  const p = allBusinessOwners.value.find(x => String(x.id) === String(id))
  return p?.business_name || p?.full_name || ''
}

/**
 * Who operates this venue, in the order we can actually trust.
 *
 * The server's own business_name first — it is the only source that still
 * works when the venue belongs to a business with no owner user, which is
 * exactly what a transfer can produce. The local lookup is the fallback for an
 * older server, and "External business" the last resort so the chip never
 * shows a bare dash where a name belongs.
 */
const ownerLabel = computed(() => {
  if (originalBookboll.value) return 'Operated by Bookboll'
  return form.value.business_name
    || providerName(form.value.owner_user_id)
    || 'External business'
})

const canSave = computed(() => !!form.value.name && !!form.value.city_id && !coordsInvalid.value)

/** Track which country_ids already have cities fetched (lazy load). */
const citiesLoadedFor = new Set()

function mapCountry(c) {
  return {
    id: String(c.id),
    name: c.name,
    iso_code: c.iso_code || c.iso2 || '',
    currency_code: c.currency_code || null,
    currency_symbol: c.currency_symbol || null,
    timezone: c.timezone || null,
    status: c.status || 'active',
  }
}

function mapCity(c) {
  return {
    id: String(c.id),
    name: c.name,
    country_id: String(c.country_id),
    state_id: c.state_id != null ? String(c.state_id) : null,
    timezone: c.timezone || null,
    status: c.status || 'active',
  }
}

async function loadRefData() {
  if (countries.value.length) return
  const all = []
  let page = 1
  let lastPage = 1
  do {
    const res = await fetchGeographyCountries({ status: 'active', page, records_per_page: 250 })
    if (!res.success) break
    all.push(...(res.data?.countries || []).map(mapCountry))
    lastPage = Number(res.data?.pagination?.last_page || 1)
    page += 1
  } while (page <= lastPage)
  countries.value = all
}

async function ensureCities(countryId) {
  if (!countryId) return
  const cid = String(countryId)
  if (citiesLoadedFor.has(cid)) return
  const res = await fetchGeographyCities(cid)
  if (!res.success) return
  const mapped = (res.data?.cities || []).map(mapCity)
  cities.value = [
    ...cities.value.filter(c => String(c.country_id) !== cid),
    ...mapped,
  ]
  citiesLoadedFor.add(cid)
}

function normalizePhotos(row) {
  if (Array.isArray(row?.photos) && row.photos.length) return [...row.photos]
  if (row?.photo_url) return [row.photo_url]
  return []
}

/** Normalize dashboard hours (flat rows or periods shape) → WeeklyHours UI shape. */
function normalizeHoursFromApi(raw) {
  if (!Array.isArray(raw) || !raw.length) return defaultHours()
  if (raw.some(r => Array.isArray(r?.periods))) {
    return Array.from({ length: 7 }, (_, i) => {
      const found = raw.find(r => Number(r.day_of_week) === i)
      if (!found) return { day_of_week: i, is_open: false, periods: [] }
      return {
        day_of_week: i,
        is_open: !!found.is_open,
        periods: found.is_open && found.periods?.length
          ? found.periods.map(p => ({ open_time: p.open_time, close_time: p.close_time }))
          : [],
      }
    })
  }
  const map = new Map()
  for (const r of raw) {
    const dow = Number(r.day_of_week)
    if (Number.isNaN(dow)) continue
    if (!map.has(dow)) map.set(dow, { day_of_week: dow, is_open: false, periods: [] })
    const e = map.get(dow)
    if (r.is_open) {
      e.is_open = true
      e.periods.push({ open_time: r.open_time, close_time: r.close_time })
    }
  }
  return Array.from({ length: 7 }, (_, i) => map.get(i) || { day_of_week: i, is_open: false, periods: [] })
}

async function onCountryChange() {
  form.value.city_id = ''
  await ensureCities(selectedCountryId.value)
}

// ── Open / close lifecycle ──
watch(() => props.open, async (v) => {
  // This modal can open ON TOP of the listing editor ("Edit location schedule"),
  // so it gets its own dirty scope: closing it must not wipe the outer form's
  // unsaved-changes state.
  if (!v) { destroyMap(); popDirtyScope(); return }
  loading.value = true
  loadFailed.value = false
  try {
  pushDirtyScope()
  errorMsg.value = ''
  triedSave.value = false
  geoStatus.value = 'idle'
  mapsLinkStatus.value = ''
  imgError.value = ''
  tab.value = 'details'
  commercialDirty.value=false
  transferOpen.value=false
  originalOwnership.value={owner_user_id:null,business_id:null}
  await loadRefData()
  if (props.editing) {
    editingExisting.value = true
    editingId.value = props.editing.id
    const res = await fetchVenueDashboard(props.editing.id)
    if (!res.success) {
      throw new Error(firstError(res, 'Could not load venue settings. Close this form and try again.'))
    } else {
      const loc = res.data?.location || props.editing
      originalOwnership.value={owner_user_id:loc.owner_user_id || null,business_id:loc.business_id || null,operator_type:loc.operator_type || null}
      form.value = {
        ...defaultForm(),
        ...loc,
        photos: normalizePhotos(loc),
        owner_user_id: loc.owner_user_id || '',
        city_id: loc.city_id != null ? String(loc.city_id) : '',
      }
      hours.value = normalizeHoursFromApi(res.data?.hours)
      connectedListings.value = res.data?.listings || []
      selectedCountryId.value = loc.country_id != null ? String(loc.country_id) : ''
    }
    if (selectedCountryId.value) await ensureCities(selectedCountryId.value)
    if (!selectedCountryId.value && form.value.city_id) {
      const city = cities.value.find(c => String(c.id) === String(form.value.city_id))
      selectedCountryId.value = city?.country_id || ''
      if (selectedCountryId.value) await ensureCities(selectedCountryId.value)
    }
    initialHoursJson.value = JSON.stringify(hours.value)
  } else {
    editingExisting.value = false
    form.value = defaultForm()
    editingId.value = null
    selectedCountryId.value = isProviderScoped.value ? (currentCountryId.value ? String(currentCountryId.value) : '') : ''
    hours.value = defaultHours()
    connectedListings.value = []
    initialHoursJson.value = ''
    if (currentOwnerUserId.value) form.value.owner_user_id = currentOwnerUserId.value
    if (selectedCountryId.value) await ensureCities(selectedCountryId.value)
  }
  clearDirty()   // preset population is programmatic; start clean
  // Local draft: offer an unfinished snapshot back, then arm the guard's
  // "Save draft" choice for this modal's own dirty scope.
  await offerLocationDraft()
  if (selectedCountryId.value) await ensureCities(selectedCountryId.value)
  registerDraftSaver(() => commercialDirty.value || commercialBusy.value ? false : saveFormDraft(locationDraftKey(), locationDraftSnapshot()))
  await nextTick()
  if (tab.value === 'map') initMap()
  } catch (error) {
    loadFailed.value = true
    clearDirty()
    errorMsg.value = error?.message || 'Could not load venue settings. Close this form and try again.'
  } finally { loading.value = false }
})

// ── Local "save as draft" for the location form ──────────────────────────────
function locationDraftKey() { return props.editing?.id ? `location_${props.editing.id}` : 'location_new' }
function locationDraftSnapshot() { return { form: form.value, hours: hours.value, selectedCountryId: selectedCountryId.value } }
async function offerLocationDraft() {
  const key = locationDraftKey()
  const d = loadFormDraft(key)
  if (!d) return
  const resume = await askConfirm({
    title: 'Resume your draft?', danger: false,
    message: `You saved an unfinished version of this form ${draftAgeLabel(d.at)}. Continue where you left off?`,
    confirmText: 'Resume draft', cancelText: 'Start fresh',
  })
  if (resume) {
    if (d.data.form) form.value = { ...form.value, ...d.data.form }
    if (Array.isArray(d.data.hours)) hours.value = d.data.hours
    if (d.data.selectedCountryId !== undefined) selectedCountryId.value = d.data.selectedCountryId
    markDirty()   // restored draft is still unsaved — keep the guard armed
  } else {
    clearFormDraft(key)
  }
}

// Re-fit the map whenever we return to the details tab (it was display:none).
watch(tab, async (t) => {
  if (t === 'map') { await nextTick(); if (mapInst) mapInst.invalidateSize(); else initMap() }
})

// ── Leaflet map picker ──
const mapEl = ref(null)
let mapInst = null
let markerInst = null

function pinIcon() {
  return L.divIcon({
    className: 'bb-pin',
    html: '<svg viewBox="0 0 24 24" width="34" height="34" fill="#0891b2" stroke="#fff" stroke-width="1.5"><path d="M12 2C8.1 2 5 5.1 5 9c0 5.2 7 13 7 13s7-7.8 7-13c0-3.9-3.1-7-7-7z"/><circle cx="12" cy="9" r="2.5" fill="#fff" stroke="none"/></svg>',
    iconSize: [34, 34],
    iconAnchor: [17, 34],
  })
}
function addMarker(lat, lng) {
  markerInst = L.marker([lat, lng], { draggable: true, icon: pinIcon() }).addTo(mapInst)
  markerInst.on('dragend', () => { const p = markerInst.getLatLng(); setPoint(p.lat, p.lng, true) })
}
function setPoint(lat, lng, reverse = false) {
  markDirty()   // map clicks/drags fire no input event, so mark explicitly
  form.value.latitude = parseFloat(Number(lat).toFixed(7))
  form.value.longitude = parseFloat(Number(lng).toFixed(7))
  if (!mapInst) return
  if (!markerInst) addMarker(lat, lng)
  else markerInst.setLatLng([lat, lng])
  if (reverse) reverseGeocode(lat, lng)
}
/* ── Typing the coordinates in by hand ────────────────────────────────────────
   The pin is the easy path, but it is not always the available one: a provider
   may have the figures from a survey, a GPS reading or a colleague, and dropping
   a pin accurately enough to matter (a court inside a school compound) is fiddly.
   These two inputs bind to the SAME form fields the map writes, so the map and
   the boxes stay in step both ways without a watcher — clicking the map fills the
   numbers in, and committing a number moves the pin. */
function inRange(v, max) {
  const n = Number(v)
  return Number.isFinite(n) && Math.abs(n) <= max
}
const coordsInvalid = computed(() => {
  const blank = (v) => v === null || v === undefined || v === ''
  const la = form.value.latitude, ln = form.value.longitude
  if (blank(la) && blank(ln)) return false          // no pin yet is allowed
  if (blank(la) !== blank(ln)) return true          // half a coordinate is not
  return !inRange(la, 90) || !inRange(ln, 180)
})
/** Commit a typed value: move the pin to it. */
function applyTypedCoords() {
  if (coordsInvalid.value) return
  if (!hasMapPin.value) {
    markerInst?.remove()
    markerInst = null
    return
  }
  const lat = Number(form.value.latitude)
  const lng = Number(form.value.longitude)
  if (!Number.isFinite(lat) || !Number.isFinite(lng)) return
  // No reverse geocode: someone who typed an exact coordinate did not ask for
  // their address field to be rewritten underneath them.
  setPoint(lat, lng)
  if (mapInst) mapInst.setView([lat, lng], Math.max(mapInst.getZoom() || 0, 15))
}

function initMap() {
  if (!mapEl.value) return
  if (mapInst) { mapInst.invalidateSize(); return }
  const hasPoint = hasMapPin.value
  const lat = hasPoint ? Number(form.value.latitude) : 25.2048
  const lng = hasPoint ? Number(form.value.longitude) : 55.2708
  mapInst = L.map(mapEl.value, { zoomControl: true }).setView([lat, lng], hasPoint ? 14 : 5)
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; OpenStreetMap contributors',
  }).addTo(mapInst)
  if (hasPoint) addMarker(lat, lng)
  mapInst.on('click', (e) => setPoint(e.latlng.lat, e.latlng.lng, true))
  setTimeout(() => mapInst && mapInst.invalidateSize(), 250)
}
function destroyMap() {
  if (mapInst) { mapInst.remove(); mapInst = null; markerInst = null }
}
onBeforeUnmount(destroyMap)

// ── Reverse geocode (OpenStreetMap Nominatim) → auto-fill country/city/address ──
const geoStatus = ref('idle')   // idle | detecting | done | error
async function reverseGeocode(lat, lng) {
  try {
    const res = await fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}&zoom=12&addressdetails=1`, {
      headers: { 'Accept-Language': 'en' },
    })
    const data = await res.json()
    const addr = data.address || {}
    const iso = (addr.country_code || '').toUpperCase()
    const matchedCountry = countries.value.find(c => c.iso_code === iso)
    if (matchedCountry) {
      // A provider's country is fixed to their business — don't let a dropped pin change it.
      selectedCountryId.value = matchedCountry.id
      const activeCountryId = selectedCountryId.value
      await ensureCities(activeCountryId)
      const cityNameRaw = addr.city || addr.town || addr.village || addr.county || ''
      const matchedCity = cities.value.find(c =>
        String(c.country_id) === String(activeCountryId) &&
        (c.name.toLowerCase() === cityNameRaw.toLowerCase() || cityNameRaw.toLowerCase().includes(c.name.toLowerCase()))
      )
      if (matchedCity) form.value.city_id = matchedCity.id
    }
    if (!form.value.address && data.display_name) {
      form.value.address = data.display_name.split(',').slice(0, 3).join(',').trim()
    }
  } catch { /* keep coords even if reverse geocode fails */ }
}

function detectGeoLocation() {
  if (!navigator.geolocation) { geoStatus.value = 'error'; return }
  geoStatus.value = 'detecting'
  navigator.geolocation.getCurrentPosition(
    (pos) => {
      geoStatus.value = 'done'
      const { latitude, longitude } = pos.coords
      setPoint(latitude, longitude, true)
      if (mapInst) mapInst.setView([latitude, longitude], 15)
    },
    () => { geoStatus.value = 'error' },
    { timeout: 10000 }
  )
}

// ── Google Maps share-link → try to extract coordinates ──
const mapsLinkStatus = ref('')   // '' | 'ok' | 'nocoords'
function applyMapsLink() {
  const url = (form.value.google_maps_url || '').trim()
  if (!url) { mapsLinkStatus.value = ''; return }
  const m =
    url.match(/@(-?\d+\.\d+),(-?\d+\.\d+)/) ||
    url.match(/[?&]q=(-?\d+\.\d+),(-?\d+\.\d+)/) ||
    url.match(/[?&]ll=(-?\d+\.\d+),(-?\d+\.\d+)/) ||
    url.match(/!3d(-?\d+\.\d+)!4d(-?\d+\.\d+)/)
  if (m) {
    const lat = parseFloat(m[1]); const lng = parseFloat(m[2])
    setPoint(lat, lng, true)
    if (mapInst) mapInst.setView([lat, lng], 15)
    mapsLinkStatus.value = 'ok'
  } else {
    mapsLinkStatus.value = 'nocoords'
  }
}

async function handleFiles(files) {
  imgError.value = ''
  const list = Array.from(files).filter(f => f.type.startsWith('image/'))
  if (!list.length) { imgError.value = 'Please choose image files.'; return }
  uploading.value = true
  try {
    for (const f of list) {
      const { url, error } = await uploadImage(f, 'venues')
      if (error) { imgError.value = error; break }
      if (url) form.value.photos.push(url)
    }
  } finally {
    uploading.value = false
  }
}
function onFileChange(e) { if (e.target.files?.length) handleFiles(e.target.files); if (fileInput.value) fileInput.value.value = '' }
function onDrop(e) { dropActive.value = false; if (e.dataTransfer?.files?.length) handleFiles(e.dataTransfer.files) }
function removePhoto(i) { form.value.photos.splice(i, 1) }
function makePrimary(i) { if (i === 0) return; const [p] = form.value.photos.splice(i, 1); form.value.photos.unshift(p) }

// ── Save ──
// Continue only moves between steps; the final action performs the write.
async function saveAndContinue() {
  if (saving.value || uploading.value) return
  if (tab.value === 'details' && !(await validateVenue())) return
  if (tab.value === 'map' && coordsInvalid.value) { await validateVenue(); return }
  errorMsg.value = ''
  triedSave.value = false
  await goVenueStep(VENUE_STEPS[Math.min(venueStepIndex.value + 1, VENUE_STEPS.length - 1)].id)
}
async function save(andClose = true) {
  if (saving.value || uploading.value || loading.value || loadFailed.value) return
  if (commercialDirty.value) { errorMsg.value='Save or discard the operating agreement before saving venue details.'; tab.value='details'; return }
  if (editingExisting.value && (String(form.value.owner_user_id || '') !== String(originalOwnership.value.owner_user_id || '') || String(form.value.business_id || '') !== String(originalOwnership.value.business_id || ''))) { errorMsg.value='Use the reviewed venue transfer process to change business ownership.'; tab.value='details'; return }
  if (venueIssue(form.value, coordsInvalid.value)) { await validateVenue(); return }
  triedSave.value = false
  saving.value = true
  errorMsg.value = ''
  try {
    const payload = {
      // Empty owner = Bookboll-owned facility (owner_user_id NULL drives the
      // payment-split logic in NewBookingDrawer / wallet).
      ...(editingExisting.value ? {} : { owner_user_id: form.value.owner_user_id || null }),
      name: form.value.name,
      city_id: form.value.city_id || null,
      // These columns are NOT NULL in the DB — store '' (not null) when empty.
      address: form.value.address || '',
      phone: form.value.phone || '',
      description: form.value.description || '',
      google_maps_url: form.value.google_maps_url || null,   // nullable
      photos: form.value.photos || [],
      photo_url: form.value.photos?.[0] || '',   // keep legacy single field mirrored
      status: form.value.status || 'active',
      latitude: form.value.latitude === '' || form.value.latitude == null ? null : Number(form.value.latitude),
      longitude: form.value.longitude === '' || form.value.longitude == null ? null : Number(form.value.longitude),
      timezone_override: form.value.timezone_override || null,
      currency_override: form.value.currency_override || null,
      hours: hours.value,
    }
    const res = editingId.value
      ? await updateVenue(editingId.value, payload)
      : await createVenue(payload)
    if (!res.success) throw new Error(firstError(res, 'Could not save venue.'))
    const saved = res.data?.location
    const locId = saved?.id || editingId.value
    const wasNew = !editingId.value
    const scheduleChanged = JSON.stringify(hours.value) !== initialHoursJson.value
    initialHoursJson.value = JSON.stringify(hours.value)
    editingId.value = locId   // keep editing the same row so a follow-up save updates (no duplicate)
    clearDirty()
    clearFormDraft(locationDraftKey())   // a real save supersedes any local draft
    savedFlag.value = true; setTimeout(() => { savedFlag.value = false }, 2000)

    // Existing location whose schedule changed and that has listings → ask whether
    // to push the new hours onto them. Hold off closing until they answer.
    if (!wasNew && scheduleChanged && connectedListings.value.length) {
      applySaved.value = saved
      applyClose.value = andClose
      applyTargets.value = connectedListings.value.map(l => l.id)
      applyModal.value = true
      saving.value = false
      return
    }

    if (andClose) {
      toast.success(wasNew ? 'Venue created' : 'Venue updated')
      emit('saved', saved)
    } else {
      toast.success(wasNew ? 'Venue created — now set weekly hours' : 'Saved')
    }
  } catch (e) {
    errorMsg.value = e.message || 'Could not save venue. Please try again.'
    toast.error('Could not save venue')
  } finally {
    saving.value = false
  }
}

async function close() {
  if (saving.value || commercialBusy.value) return
  const res = await confirmDiscardIfDirty()   // false = stay · true = discard · 'draft' = saved a draft
  if (!res) return
  if (res === 'draft') toast.success('Draft saved — it will be offered when you reopen this form')
  emit('close')
}

// ── Apply-schedule-to-listings prompt handlers ──
function toggleApplyTarget(id) {
  applyTargets.value = applyTargets.value.includes(id)
    ? applyTargets.value.filter(x => x !== id)
    : [...applyTargets.value, id]
}
function finishApply() {
  applyModal.value = false
  if (applyClose.value) emit('saved', applySaved.value)
}
async function applyScheduleToSelected() {
  applying.value = true
  try {
    if (applyTargets.value.length) {
      const locId = applySaved.value?.id || editingId.value
      const res = await applyVenueHoursToListings(locId, {
        listing_ids: applyTargets.value.map(id => Number(id) || id),
        hours: hours.value,
      })
      if (!res.success) throw new Error(firstError(res, 'Could not update listings.'))
      const n = applyTargets.value.length
      toast.success(`Updated ${n} listing${n !== 1 ? 's' : ''} to the new schedule`)
    }
  } catch (e) {
    toast.error(e.message || 'Could not update some listings.')
  } finally {
    applying.value = false
    finishApply()
  }
}
</script>

<template>
  <teleport to="body">
  <VenueTransfer v-if="open && transferOpen" :venue-id="editingId" :venue-name="form.name" @close="transferOpen=false" @transferred="transferred"/>
  <div v-if="open" class="bb-drawer-backdrop" style="z-index:3200" @click.self="close">
    <div ref="dialogEl" class="bb-drawer creation-drawer" :style="adminBrandVars" role="dialog" aria-modal="true" aria-labelledby="venue-editor-title" @keydown="editorKeydown" @keydown.esc.stop="close" @input="markDirty" @change="markDirty">
      <div class="bb-drawer-header">
        <div>
          <div id="venue-editor-title" class="bb-drawer-title">{{ editingExisting ? 'Edit venue' : 'Add a venue' }}</div>
          <div class="bb-drawer-subtitle">Set up your venue, then add the listings customers can book.</div>
        </div>
        <button class="bb-drawer-close" aria-label="Close venue editor" :disabled="saving" @click="close"><X :size="18" /></button>
      </div>

      <div v-if="loading" class="cf-loading" role="status">Loading venue settings…</div>
      <div v-else-if="loadFailed" class="cf-loading" role="alert">{{ errorMsg }} Your existing venue has not been changed.</div>
      <div v-show="!loading && !loadFailed" class="cf-workspace" :inert="saving || loading || loadFailed">
        <CreationSteps :steps="VENUE_STEPS" :active="tab" @select="goVenueStep" />
        <div ref="bodyEl" class="bb-drawer-body lm-body">
          <div class="cf-context"><h2>{{ venueStep.label }}</h2><p>{{ venueStepDescription }}</p></div>
          <div v-show="tab === 'details'" class="cf-form-card">
          <!-- Facility ownership (admins only) -->
          <div v-if="isSuperAdmin || isCountryAdmin" class="form-group" style="margin-bottom:16px;">
            <label class="form-label">Facility ownership</label>
            <select v-if="!editingExisting" v-model="form.owner_user_id">
              <option value="">Bookboll (own facility)</option>
              <option v-for="p in allBusinessOwners" :key="p.id" :value="p.id">
                {{ p.business_name || p.full_name }} (@{{ p.username || p.email }})
              </option>
            </select>
            <div v-else class="assigned-chip"><Building2 :size="14"/>{{ ownerLabel }}</div>
            <div v-if="!form.owner_user_id && !form.business_id" class="form-hint">Bookboll collects venue income. The operating agreement determines rent or the landlord’s share.</div>
            <div v-else class="form-hint">Bookboll earns the agreed platform fees. The provider’s share is recorded separately.</div>
            <button v-if="editingExisting && originalBookboll" type="button" class="btn btn-secondary btn-sm" style="margin-top:12px" @click="beginTransfer">Move to an external business</button>
          </div>
          <div v-else class="assigned-chip" style="margin-bottom:16px;">
            <Building2 :size="13" />Business: <strong>{{ providerName(form.owner_user_id) || 'Your business' }}</strong>
          </div>

          <p v-if="!editingExisting && !form.owner_user_id && (isSuperAdmin || isCountryAdmin)" class="fin-notice" style="margin-bottom:16px">Create this venue first, then open it to add rent, revenue sharing and an optional contract.</p>
          <!-- Venue name -->
          <div class="form-group">
            <label for="venue-name" class="form-label">Venue name <span style="color:var(--danger-ink)">*</span></label>
            <input id="venue-name" v-model="form.name" :class="{ 'input-invalid': triedSave && !form.name }" placeholder="e.g. Marina Sports Complex" />
          </div>

          <!-- Country → City -->
          <div class="form-row" style="margin-top:14px;">
            <div class="form-group">
              <label for="venue-country" class="form-label">Country <span style="color:var(--danger-ink)">*</span></label>
              <select id="venue-country" v-model="selectedCountryId" :class="{ 'input-invalid': triedSave && !form.city_id && !selectedCountryId }" @change="onCountryChange">
                <option value="">Select country</option>
                <option v-for="co in countries" :key="co.id" :value="co.id">{{ co.name }}</option>
              </select>
            </div>
            <div class="form-group">
              <label for="venue-city" class="form-label">City <span style="color:var(--danger-ink)">*</span></label>
              <select id="venue-city" v-model="form.city_id" :class="{ 'input-invalid': triedSave && !form.city_id }" :disabled="!selectedCountryId">
                <option value="">{{ selectedCountryId ? 'Select city' : 'Select country first' }}</option>
                <option v-for="c in citiesForCountry" :key="c.id" :value="c.id">{{ c.name }}</option>
              </select>
            </div>
          </div>

          <!-- A payout wallet (any country) enables full online payments anywhere; a local
               wallet for this country is created automatically so earnings stay in local currency. -->
          <p v-if="isProviderScoped && selectedCountryId" class="lm-country-hint">
            Prices use this venue’s currency. Choose payment options when you create a listing.
          </p>

          <!-- Inherited geo preview -->
          <div v-if="resolvedGeo.timezone || resolvedGeo.currency" class="geo-inherited-preview">
            <div class="geo-inherited-title">Currency &amp; time zone</div>
            <div class="geo-inherited-pills">
              <span v-if="resolvedGeo.timezone" class="geo-pill"><Clock :size="11" />{{ resolvedGeo.timezone }}</span>
              <span v-if="resolvedGeo.currency" class="geo-pill"><DollarSign :size="11" />{{ resolvedGeo.currencySymbol }} {{ resolvedGeo.currency }}</span>
              <span v-if="resolvedGeo.countryName" class="geo-pill geo-pill-muted">{{ resolvedGeo.countryName }}</span>
            </div>
            <!-- Currency is the only override that changes anything (listing/program prices).
                 The old free-text timezone override drove nothing, so it's gone — timezone
                 simply follows the city/country shown above. -->
            <details style="margin-top:10px;">
              <summary style="font-size:12px; color:var(--text-muted); cursor:pointer;">Charge prices in a different currency</summary>
              <div class="form-row" style="margin-top:10px;">
                <div class="form-group">
                  <label for="venue-currency" class="form-label">Price currency</label>
                  <select id="venue-currency" v-model="form.currency_override">
                    <option :value="null">{{ resolvedGeo.currency ? `${resolvedGeo.currency} — ${resolvedGeo.countryName || 'country'} default` : 'Country default' }}</option>
                    <option v-for="c in ISO_CURRENCIES" :key="c.code" :value="c.code">{{ c.code }} — {{ c.name }}</option>
                  </select>
                  <div class="form-hint">All listing &amp; program prices at this venue use this currency.</div>
                </div>
              </div>
            </details>
          </div>

          <!-- Phone (address is derived from the map pin) -->
          <div class="form-group" style="margin-top:14px;">
            <label for="venue-phone" class="form-label">Phone <span class="opt-tag">optional</span></label>
            <input id="venue-phone" v-model="form.phone" type="tel" placeholder="Business contact number" />
          </div>

          <!-- Description -->
          <div class="form-group" style="margin-top:14px;">
            <label for="venue-description" class="form-label">Description <span class="opt-tag">optional</span></label>
            <textarea id="venue-description" v-model="form.description" rows="3" placeholder="Describe the facility, parking, amenities…"></textarea>
          </div>


          </div>
          <VenueCommercial v-if="editingExisting && originalBookboll && (isSuperAdmin || isCountryAdmin)" v-show="tab === 'details'" :venue-id="editingId" :currency="resolvedGeo.currency" @dirty="commercialDirty=$event" @busy="commercialBusy=$event" />
          <div v-show="tab === 'map'" class="cf-form-card">
          <!-- Map picker -->
          <div class="form-group" style="margin-top:14px;">
            <label class="form-label"><MapIcon :size="13" style="vertical-align:-2px;" /> Pin the location on the map</label>
            <div class="map-toolbar">
              <span class="map-hint">Tap the map or drag the pin to your venue.</span>
              <button class="btn btn-secondary btn-sm" :disabled="geoStatus === 'detecting'" @click="detectGeoLocation">
                <LocateFixed :size="13" />
                {{ geoStatus === 'detecting' ? 'Detecting…' : 'Use my location' }}
              </button>
            </div>
            <div ref="mapEl" class="bb-map"></div>
            <div v-if="geoStatus === 'error'" class="geo-error" style="margin-top:6px;">Location access denied or unavailable — pin it manually on the map.</div>

            <!-- Editable coordinates, bound to the same fields the map writes: drag
                 the pin and these fill in; type a value and the pin moves. -->
            <details class="manual-coordinates" :open="coordsInvalid">
            <summary>Enter coordinates manually</summary>
            <div class="coord-row">
              <div class="coord-field">
                <label for="venue-latitude" class="form-label">Latitude</label>
                <input
                  id="venue-latitude"
                  v-model="form.latitude"
                  type="number" step="any" min="-90" max="90" inputmode="decimal"
                  placeholder="−90 to 90"
                  :class="{ 'input-invalid': coordsInvalid }"
                  @change="applyTypedCoords" @keyup.enter="applyTypedCoords"
                />
              </div>
              <div class="coord-field">
                <label for="venue-longitude" class="form-label">Longitude</label>
                <input
                  id="venue-longitude"
                  v-model="form.longitude"
                  type="number" step="any" min="-180" max="180" inputmode="decimal"
                  placeholder="−180 to 180"
                  :class="{ 'input-invalid': coordsInvalid }"
                  @change="applyTypedCoords" @keyup.enter="applyTypedCoords"
                />
              </div>
            </div>
            </details>
            <div v-if="coordsInvalid" class="geo-error" style="margin-top:6px;">
              Latitude must be between −90 and 90, longitude between −180 and 180 — and both are needed, not one.
            </div>
            <div v-else-if="hasMapPin" class="geo-success" style="margin-top:6px;">
              <Check :size="13" />Map pin added
            </div>
          </div>

          <!-- Google Maps link -->
          <div class="form-group" style="margin-top:14px;">
            <label for="venue-maps-link" class="form-label">Google Maps link <span class="opt-tag">optional</span></label>
            <div class="maps-link-row">
              <input id="venue-maps-link" type="url" v-model="form.google_maps_url" placeholder="Paste a Google Maps link…" @blur="applyMapsLink" />
              <button class="btn btn-secondary btn-sm" @click="applyMapsLink" :disabled="!form.google_maps_url">Locate</button>
            </div>
            <div v-if="mapsLinkStatus === 'ok'" class="form-hint" style="color:var(--positive-ink);">Pin moved to the link's coordinates.</div>
            <div v-else-if="mapsLinkStatus === 'nocoords'" class="form-hint" style="color:var(--warning-ink);">Link saved, but it has no coordinates to read — pin it on the map above.</div>
            <div v-else class="form-hint">Customers use this link for directions. Without a link, we use your map pin.</div>
          </div>

          <!-- Images -->
          <div class="form-group" style="margin-top:14px;">
            <label class="form-label"><ImageIcon :size="13" style="vertical-align:-2px;" /> Photos <span class="opt-tag">optional</span></label>
            <div
              class="img-dropzone" role="button" tabindex="0" aria-label="Upload venue photos" @keydown.enter.prevent="!uploading && fileInput?.click()" @keydown.space.prevent="!uploading && fileInput?.click()"
              :class="{ active: dropActive }"
              @dragover.prevent="dropActive = true"
              @dragleave.prevent="dropActive = false"
              @drop.prevent="onDrop"
              @click="!uploading && fileInput?.click()"
            >
              <input ref="fileInput" type="file" accept="image/*" multiple style="display:none" @change="onFileChange" />
              <template v-if="uploading">
                <Loader :size="20" class="spin" /><span>Uploading &amp; compressing…</span>
              </template>
              <template v-else>
                <Upload :size="20" /><strong>Choose photos</strong>
                <span class="img-sub">The first photo is your cover. You can change it later.</span>
              </template>
            </div>
            <div v-if="imgError" class="geo-error" style="margin-top:6px;">{{ imgError }}</div>
            <PhotoOrderEditor :model-value="form.photos" @update:model-value="photos => { form.photos = photos; form.photo_url = photos[0] || ''; markDirty() }" />
          </div>


          </div>
          <div v-if="tab === 'schedule'" class="cf-form-card">
            <div class="cf-note"><Clock :size="17" /><span><template v-if="!editingExisting">New venues start open 24 hours every day. Adjust the hours below. </template>New listings copy these hours and can have a shorter schedule.</span></div>
            <WeeklyHours v-model="hours" :show-inherit="false" />
          </div>
          <div v-if="tab === 'review'">
            <div class="cf-summary"><img v-if="form.photos[0]" :src="form.photos[0]" alt="Venue cover" /><div><h3>{{ form.name || 'Your venue' }}</h3><p>{{ resolvedGeo.cityName }}, {{ resolvedGeo.countryName }}</p></div></div>
            <div class="cf-review">
              <section class="cf-review-card"><div class="cf-review-head"><h3>Venue details</h3><button @click="goVenueStep('details')">Edit details</button></div>
                <dl><dt>Currency</dt><dd>{{ resolvedGeo.currency || 'Country default' }}</dd><dt>Time zone</dt><dd>{{ resolvedGeo.timezone || 'City default' }}</dd><dt>Phone</dt><dd>{{ form.phone || 'Not added' }}</dd></dl>
                <p v-if="form.description" class="cf-review-text" style="margin-top:14px">{{ form.description }}</p>
              </section>
              <section class="cf-review-card"><div class="cf-review-head"><h3>Map &amp; photos</h3><button @click="goVenueStep('map')">Edit map &amp; photos</button></div>
                <dl><dt>Map pin</dt><dd>{{ hasMapPin ? 'Added' : 'Not added' }}</dd><dt>Address</dt><dd>{{ form.address || 'Not added' }}</dd><dt>Photos</dt><dd>{{ form.photos.length }} added</dd></dl>
              </section>
              <section class="cf-review-card"><div class="cf-review-head"><h3>Opening hours</h3><button @click="goVenueStep('schedule')">Edit hours</button></div>
                <dl><template v-for="day in hours" :key="day.day_of_week"><dt>{{ venueDayNames[day.day_of_week] }}</dt><dd>{{ day.is_open ? day.periods.map(p => p.open_time === p.close_time ? 'Open 24 hours' : fmtRange12(p.open_time, p.close_time)).join(', ') : 'Closed' }}</dd></template></dl>
              </section>
              <div class="cf-note"><Building2 :size="17" /><span>A venue holds your address and opening hours. Add a listing to offer a service customers can book.</span></div>
            </div>
          </div>
        </div>
      </div>
      <div v-show="!loading && !loadFailed" class="cf-footer">
        <p v-if="errorMsg" class="cf-footer-error" role="alert">{{ errorMsg }}</p>
        <span class="cf-footer-note">{{ saving ? 'Saving your venue…' : 'Changes are saved only when you finish. Drafts stay in this browser.' }}</span>
        <button class="btn btn-secondary" :disabled="saving || uploading" @click="saveLocationDraftNow">Save draft &amp; exit</button>
        <button v-if="tab !== 'details'" class="btn btn-secondary" :disabled="saving" @click="goVenueStep(VENUE_STEPS[venueStepIndex - 1].id)">Back</button>
        <button v-if="tab !== 'review'" class="btn btn-primary" :disabled="saving || uploading" @click="saveAndContinue">{{ tab === 'schedule' ? 'Review venue' : 'Continue' }}</button>
        <button v-else class="btn btn-primary" :disabled="saving || uploading" @click="save()">{{ saving ? 'Saving…' : (editingExisting ? 'Save changes' : 'Create venue') }}</button>
      </div>
    </div>
  </div>

  <!-- Apply the new schedule to connected listings? -->
  <div v-if="applyModal" class="modal-backdrop" style="z-index:4200" @click.self="finishApply">
    <div class="modal apply-modal" v-dialog="() => !applying && finishApply()">
      <div class="apply-icon"><Clock :size="26" /></div>
      <div class="apply-title">Update connected listings too?</div>
      <p class="apply-text">You changed this venue's weekly schedule. Pick which listings should copy the new hours — unticked ones keep their current schedule. Listings stay independent either way.</p>
      <div class="apply-list">
        <label v-for="l in connectedListings" :key="l.id" class="apply-item" :class="{ on: applyTargets.includes(l.id) }">
          <input type="checkbox" :checked="applyTargets.includes(l.id)" @change="toggleApplyTarget(l.id)" />
          <span class="apply-item-main">
            <span class="apply-item-name">{{ l.name }}</span>
            <span v-if="l.sport_category" class="apply-item-sub">{{ l.sport_category }}</span>
          </span>
          <Check v-if="applyTargets.includes(l.id)" :size="16" class="apply-item-check" />
        </label>
      </div>
      <div class="apply-actions">
        <button class="btn btn-secondary" :disabled="applying" @click="finishApply">Keep existing hours</button>
        <button class="btn btn-primary" :disabled="applying || !applyTargets.length" @click="applyScheduleToSelected">{{ applying ? 'Updating…' : (applyTargets.length ? `Update ${applyTargets.length} listing${applyTargets.length !== 1 ? 's' : ''}` : 'Update') }}</button>
      </div>
    </div>
  </div>
  </teleport>
</template>

<style scoped>
/* Apply-schedule-to-listings modal */
.apply-modal { max-width: 460px; width: 100%; padding: 24px; text-align: center; }
.apply-icon { width: 54px; height: 54px; border-radius: 14px; background: var(--primary-soft, #f0fdfa); color: var(--primary-ink); display: inline-flex; align-items: center; justify-content: center; margin-bottom: 14px; }
.apply-title { font-size: 18px; font-weight: 700; color: var(--text); margin-bottom: 8px; }
.apply-text { font-size: 13.5px; color: var(--text-muted); line-height: 1.55; margin: 0 0 16px; }
.apply-list { display: flex; flex-direction: column; gap: 8px; text-align: left; margin-bottom: 18px; max-height: 280px; overflow-y: auto; }
.apply-item { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border: 1px solid var(--border); border-radius: 10px; cursor: pointer; transition: all .12s; }
.apply-item:hover { border-color: var(--border-strong, #cbd5e1); }
.apply-item.on { border-color: var(--primary); background: var(--primary-soft, #f0fdfa); }
.apply-item input { width: 16px; height: 16px; accent-color: var(--primary); flex-shrink: 0; cursor: pointer; }
.apply-item-main { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 1px; }
.apply-item-name { font-weight: 600; font-size: 13.5px; color: var(--text); overflow-wrap: anywhere; }
.apply-item-sub { font-size: 11.5px; color: var(--text-muted); text-transform: capitalize; }
.apply-item-check { color: var(--primary-ink); flex-shrink: 0; }
.apply-actions { display: flex; gap: 10px; }
.apply-actions .btn { flex: 1; justify-content: center; }

/* Accordion sections (matches the listing form's section cards) */
.lm-section { background:var(--surface, #ffffff); border:1px solid var(--border); border-radius:12px; overflow:hidden; transition:box-shadow .15s; }
.lm-section + .lm-section { margin-top:12px; }
.lm-section.open { box-shadow:0 4px 16px rgba(15,23,42,.06); }
/* Read-only field (e.g. country, fixed to the provider's business) */
.lm-locked-field { display:flex; align-items:center; height:40px; padding:0 12px; border:1px solid var(--border); border-radius:8px; background:var(--bg, #f8fafc); color:var(--text); font-size:14px; font-weight:600; }
.lm-country-hint { margin:10px 2px 0; font-size:12.5px; line-height:1.45; color:var(--text-muted); background:var(--primary-soft, #f0fdfa); border:1px solid #ccfbf1; border-radius:8px; padding:8px 11px; }
.lm-country-hint strong { color:var(--primary-ink, #0f766e); font-weight:700; }
/* Per-card action footer (Cancel + Save) */
.lm-foot { display:flex; flex-direction:column; gap:8px; margin-top:18px; padding-top:16px; border-top:1px solid var(--border); }
.lm-foot-btns { display:flex; justify-content:flex-end; gap:10px; }
.lm-foot-err { margin:0; }
.lm-section-head { width:100%; display:flex; align-items:center; gap:14px; padding:16px 18px; background:transparent; border:none; cursor:pointer; }
.lm-section-head:hover { background:var(--surface-hover, #fafafa); }
.lm-section-title { flex:1; text-align:left; }
.lm-section-title > div:first-child { font-weight:600; font-size:14px; color:var(--text); }
.lm-section-sub { font-size:12px; color:var(--text-muted); margin-top:2px; }
.lm-chev { color:var(--text-muted); transition:transform .15s; flex-shrink:0; }
.lm-chev.rot { transform:rotate(180deg); }
.lm-section-body { padding:6px 18px 18px; border-top:1px solid var(--border); }

.opt-tag { font-size:10px; font-weight:600; color:var(--text-muted); background:var(--bg-subtle, #f1f5f9); padding:1px 6px; border-radius:6px; margin-left:4px; text-transform:uppercase; letter-spacing:.03em; }

.map-toolbar { display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:8px; }
.map-hint { font-size:12px; color:var(--text-muted); }
.bb-map { height:240px; width:100%; border-radius:10px; border:1px solid var(--border); overflow:hidden; z-index:0; }

.manual-coordinates { margin-top: 10px; padding: 4px 0; }
.manual-coordinates summary { min-height: 44px; align-content: center; font-size: 13px; color: var(--primary-ink); cursor: pointer; }
.coord-row { display: grid; grid-template-columns: minmax(0,1fr) minmax(0,1fr); gap: 12px; margin-top: 10px; }
.maps-link-row input { min-width: 0; }
.apply-list { overscroll-behavior: contain; }
.coord-field { display: flex; flex-direction: column; gap: 5px; }
@media (max-width: 600px) { .coord-row { grid-template-columns: 1fr; } }
.maps-link-row { display:flex; gap:8px; }
.maps-link-row input { flex:1; }

.geo-error { display:flex; align-items:center; gap:6px; font-size:12.5px; color:var(--danger-ink, #dc2626); background:var(--danger-bg, #fef2f2); border:1px solid var(--danger-border, #fecaca); border-radius:8px; padding:7px 10px; }
.geo-success { display:flex; align-items:center; gap:6px; font-size:12.5px; color:var(--positive-ink, #059669); font-weight:600; }

.geo-inherited-preview { margin-top:14px; background:var(--bg-subtle, #f8fafc); border:1px solid var(--border); border-radius:10px; padding:12px 14px; }
.geo-inherited-title { font-size:12px; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:.03em; margin-bottom:8px; }
.geo-inherited-pills { display:flex; flex-wrap:wrap; gap:6px; }
.geo-pill { display:inline-flex; align-items:center; gap:4px; font-size:12px; font-weight:600; background:var(--surface, #ffffff); border:1px solid var(--border); border-radius:20px; padding:3px 10px; color:var(--text); }
.geo-pill-muted { color:var(--text-muted); font-weight:500; }

.img-dropzone { display:flex; flex-direction:column; align-items:center; justify-content:center; gap:6px; padding:22px; border:1.5px dashed var(--border); border-radius:10px; color:var(--text-muted); font-size:13px; cursor:pointer; text-align:center; transition:.15s; }
.img-dropzone:hover, .img-dropzone.active { border-color:var(--primary, #0891b2); background:rgba(8,145,178,.04); color:var(--primary-ink, #0891b2); }
.img-sub { font-size:11px; opacity:.8; }
.spin { animation:spin 1s linear infinite; }
@keyframes spin { to { transform:rotate(360deg); } }

.img-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(110px, 1fr)); gap:10px; margin-top:12px; }
.img-cell { position:relative; aspect-ratio:4/3; border-radius:9px; overflow:hidden; border:1px solid var(--border); }
.img-cell.primary { border-color:var(--primary, #0891b2); box-shadow:0 0 0 2px rgba(8,145,178,.2); }
.img-cell img { width:100%; height:100%; object-fit:cover; display:block; }
.primary-badge { position:absolute; top:6px; left:6px; display:inline-flex; align-items:center; gap:3px; font-size:10px; font-weight:700; background:var(--primary, #0891b2); color:#fff; padding:2px 7px; border-radius:20px; }
.img-cell-actions { position:absolute; top:6px; right:6px; display:flex; gap:4px; opacity:0; transition:.15s; }
.img-cell:hover .img-cell-actions { opacity:1; }
.img-mini-btn { display:inline-flex; align-items:center; justify-content:center; width:26px; height:26px; border-radius:7px; border:none; background:rgba(15,23,42,.78); color:#fff; cursor:pointer; }
.img-mini-btn:hover { background:rgba(15,23,42,.95); }
.img-mini-btn.danger:hover { background:#dc2626; }

.assigned-chip { display:inline-flex; align-items:center; gap:6px; font-size:13px; color:var(--text-muted); background:var(--bg-subtle, #f1f5f9); padding:6px 12px; border-radius:8px; }
.form-hint { font-size:12px; color:var(--text-muted); margin-top:5px; }

/* ── Phone (≤600px) — 360px-wide fixes, desktop untouched ── */
@media (max-width: 600px) {
  /* Map toolbar: hint + "Use my location" no longer share one crushed row */
  .map-toolbar { flex-wrap: wrap; align-items: flex-start; gap: 6px; }

  /* Photo tile actions: no hover on touch — always visible + 36px touch targets */
  .img-cell-actions { opacity: 1; }
  .img-mini-btn { width: 36px; height: 36px; }

  /* Section footer: stack full-width buttons, primary on top (sheet convention) */
  .lm-foot-btns { flex-direction: column-reverse; }
  .lm-foot-btns .btn { width: 100%; justify-content: center; min-height: 44px; }

  /* Apply-to-listings modal: stack Skip under full-width primary */
  .apply-actions { flex-direction: column-reverse; }
  .apply-actions .btn { width: 100%; min-height: 44px; }

  /* Maps-link row: let the URL input shrink cleanly at 344px */
  .maps-link-row input { min-width: 0; }
}
</style>
