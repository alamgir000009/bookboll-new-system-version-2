<script setup>
import { ref, onMounted, onBeforeUnmount, computed, watch, nextTick } from 'vue'
import { loadReferenceData } from '../lib/referenceData.js'
import { uploadImage } from '../lib/imageUpload.js'
import {
  fetchPaymentAccount, createPaymentAccount, updatePaymentAccount, deletePaymentAccount,
  fetchStorefrontSort, saveStorefrontSortValue,
} from '../lib/paymentAccounts.js'
import { fetchWallets } from '../lib/walletPayouts.js'
import {
  fetchVenuesDashboard, fetchVenueDashboard, deleteVenue, patchVenue, sortVenues,
  firstError as venueFirstError,
} from '../lib/venues.js'
import {
  fetchListingsDashboard, fetchListingDashboard, createListing, updateListing,
  patchListing, deleteListing, sortListings,
  fetchAddOns, createAddOn, updateAddOn, deleteAddOn as apiDeleteAddOn,
  fetchResources, createResource, updateResource, deleteResource as apiDeleteResource,
  mapAddOnRow, mapResourceRow,
  firstError as listingFirstError,
} from '../lib/listings.js'
import { appBaseUrl } from '../lib/appBase.js'
import { Search, Plus, Minus, Trash2, Pencil as Edit2, ChevronDown, ChevronUp, ChevronRight, Eye, EyeOff, ArrowLeft, Check, Info, Wallet, Clock, DollarSign, PackagePlus, Boxes, Image as ImgIcon, X, GripVertical, LayoutList, Layers, CirclePause as PauseCircle, CirclePlay as PlayCircle, TriangleAlert as AlertTriangle, RefreshCw, LocateFixed, MapPin as MapPinIcon, Landmark, Building2 as BldIcon, Upload, Download, SlidersHorizontal, Store, Globe, TrendingDown, ExternalLink, CircleCheck, ShieldCheck, Lock, Bell, Users } from 'lucide-vue-next'
import { useRouter, useRoute } from 'vue-router'
import { VENUE_CATEGORIES, subcategoriesFor, normCategory, categoryLabel } from '../lib/venueCategories.js'
import { loadApprovedTypes, approvedTypesFor, addPendingType } from '../lib/customTypes.js'
import { isWalletReady, hasActivePayoutMethodFor } from '../lib/walletSetup.js'
import { usePremium } from '../lib/premium.js'
import PayoutSetupModal from '../components/PayoutSetupModal.vue'
import { askConfirm } from '../lib/confirm.js'
import { idIn } from '../lib/geoFilter.js'
import { contentChanged } from '../lib/approvalState.js'
import MultiSelectDropdown from '../components/MultiSelectDropdown.vue'
const router = useRouter()

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

const SPORTS = [
  'Football','Futsal','American Football','Australian Rules Football','Rugby Union','Rugby League','Touch Rugby','Gaelic Football',
  'Basketball','3x3 Basketball','Netball','Handball','Volleyball','Beach Volleyball','Water Polo','Dodgeball','Korfball',
  'Tennis','Padel','Pickleball','Squash','Racquetball','Badminton','Table Tennis','Platform Tennis','Real Tennis',
  'Cricket','Baseball','Softball','Kickball','Lacrosse','Field Hockey','Ice Hockey','Roller Hockey','Floorball','Polo','Bandy','Hurling','Shinty',
  'Swimming','Diving','Synchronized Swimming','Open Water Swimming','Aquafitness','Kayaking','Canoeing','Rowing','Dragon Boat','Stand-up Paddle','Surfing','Bodyboarding','Windsurfing','Kitesurfing','Wakeboarding','Water Skiing','Sailing','Yachting','Scuba Diving','Freediving','Snorkeling','Jet Ski','Flyboard',
  'Running','Track & Field','Cross Country','Marathon','Trail Running','Triathlon','Duathlon','Aquathlon','Orienteering','Race Walking',
  'Cycling','Road Cycling','Mountain Biking','BMX','Track Cycling','Gravel Cycling','Cyclocross','Indoor Cycling','Spinning',
  'Golf','Disc Golf','Footgolf',
  'Tenpin Bowling','Lawn Bowls','Bocce','Petanque','Curling',
  'Skateboarding','Rollerblading','Roller Derby','Scootering','Parkour','Bouldering','Rock Climbing','Indoor Climbing','Ice Climbing','Canyoning','Abseiling',
  'Yoga','Pilates','Barre','Aerial Yoga','Aerial Silks','Meditation','Tai Chi','Qigong',
  'Dance','Ballet','Ballroom','Zumba','Hip Hop','Contemporary','Jazz Dance','Salsa','Bachata','Tango','Flamenco','Break Dance','Pole Dance',
  'Boxing','Kickboxing','Muay Thai','Karate','Taekwondo','Judo','Jiu-Jitsu','Brazilian Jiu-Jitsu','Wrestling','Sumo','MMA','Krav Maga','Aikido','Kung Fu','Wushu','Capoeira','Fencing','Kendo','Arnis',
  'Weightlifting','Powerlifting','CrossFit','Bodybuilding','Calisthenics','Functional Training','HIIT','Circuit Training','Strongman','Olympic Lifting',
  'Gymnastics','Rhythmic Gymnastics','Trampoline','Acrobatics','Cheerleading','Parkour & Freerunning',
  'Equestrian','Showjumping','Dressage','Polo (Equestrian)','Horse Racing','Endurance Riding',
  'Archery','Shooting','Clay Pigeon','Paintball','Airsoft',
  'Skiing','Alpine Skiing','Cross-Country Skiing','Ski Jumping','Snowboarding','Snowshoeing','Sledding','Luge','Bobsled','Ice Skating','Figure Skating','Speed Skating',
  'Chess','Checkers','Esports','Console Gaming','PC Gaming','Poker',
  'Falconry','Camel Racing','Camel Riding','Horse Riding','Pony Riding','Dune Bashing','Sandboarding','Sand Skiing','Overnight Desert Camp','Dune Buggy','Buggy Tour','Quad Biking','ATV Riding','UTV Riding','Karting','Go-Karting','Motorsport','Motocross','Enduro','Supercross','Rally Driving','Drift Racing','Drifting','Dirt Biking','Hill Climbing','4x4 Off-Roading','Off-Road Driving','Desert Driving Experience','Dune Bashing Tour',
  'Hot Air Ballooning','Skydiving','Indoor Skydiving','Paragliding','Hang Gliding','Parasailing','Bungee Jumping','Zip Lining','Ziplining Adventure','Base Jumping','Wingsuit Flying','Glider Flying','Sailplane','Ultralight Flying','Helicopter Tour','Seaplane Tour','Drone Racing',
  'Caving','Spelunking','Hiking','Trekking','Backpacking','Mountaineering','Via Ferrata','Rappelling','Bouldering Outdoor',
  'Fishing','Sport Fishing','Deep Sea Fishing','Fly Fishing','Spearfishing','Crabbing',
  'Flyboarding','Hoverboarding','eFoiling','Hydrofoiling','Banana Boat','Tubing','White Water Rafting','Canyoneering','Coasteering',
  'Trampoline Park','Inflatable Park','Aqua Park','Ninja Warrior Course','Obstacle Course Racing','Spartan Race',
  'Bubble Football','Foot Volley','Sepak Takraw','Kabaddi','Kho Kho','Ultimate Frisbee','Frisbee Golf',
  'Ice Fishing','Snowmobiling','Ice Climbing Tour','Glacier Walking','Igloo Building',
  'Dog Sledding','Reindeer Sledding','Camel Polo','Horse Polo','Beach Polo','Beach Soccer','Beach Tennis','Beach Wrestling','Beach Handball',
  'Aerobics','Step Aerobics','Jumping Fitness','Rebounder','Rowing Machine Classes','Boxercise','Boot Camp','Military Fitness',
  'Cricket Simulator',
].sort((a, b) => a.localeCompare(b))
import WeeklyHours from '../components/WeeklyHours.vue'
import { notifySetupChanged } from '../lib/overview.js'
import LocationModal from '../components/LocationModal.vue'
import EmptyState from '../components/EmptyState.vue'
import PricingGrid from '../components/PricingGrid.vue'
import PaginationBar from '../components/PaginationBar.vue'
import AddMenu from '../components/AddMenu.vue'
import PickerSelect from '../components/PickerSelect.vue'
import InfoTip from '../components/InfoTip.vue'
import { createTouchReorder } from '../lib/touchReorder.js'
import { useCurrentUser } from '../lib/currentUser.js'
import { logAudit } from '../lib/audit.js'
import { useJurisdiction } from '../lib/jurisdiction.js'
import { downloadCSV } from '../lib/useExport.js'
import { SLOT_DURATIONS, BUFFER_OPTIONS, previewSlots, durationLabel, toMinutes, toTimeStr, generateSlots, fmt12, fmtRange12 } from '../lib/slots.js'
import { CAP } from '../lib/permissions.js'
import { SORT_OPTIONS } from '../lib/storefrontSort.js'
import { useConfirm } from '../lib/confirm.js'
import { useToast } from '../lib/toast.js'
import { markDirty, clearDirty, confirmDiscardIfDirty, registerDraftSaver, formDirty } from '../lib/unsavedGuard.js'
import { saveFormDraft, loadFormDraft, clearFormDraft, draftAgeLabel } from '../lib/formDrafts.js'

const { isSuperAdmin, isCountryAdmin, currentOwnerUserId, allBusinessOwners, role: actorRole, can, scopeLocations, scopeListings, currentUser } = useCurrentUser()
const confirm = useConfirm()
const toast = useToast()
const listingTriedSave = ref(false)
async function requestExitEditor() {
  const res = await confirmDiscardIfDirty()   // false = stay · true = discard · 'draft' = saved a draft
  if (!res) return
  registerDraftSaver(null)
  if (res === 'draft') toast.success('Draft saved — it will be offered when you reopen this form')
  view.value = 'list'
}

// ── Local "save as draft" for the listing editor ─────────────────────────────
// Snapshots the whole editor state to localStorage (lib/formDrafts.js). Saved
// via the footer button or the leave-confirm's "Save draft" choice; offered
// back the next time the same form (new, or this listing's edit) opens.
function listingDraftKey() { return editing.value?.id ? `listing_${editing.value.id}` : 'listing_new' }
function listingDraftSnapshot() {
  return {
    form: form.value, hours: hours.value, groups: groups.value, cells: cells.value,
    pattern: pattern.value, dynamicPricing: dynamicPricing.value, pay: pay.value,
    connectedAddons: connectedAddons.value, connectedResources: connectedResources.value,
    openSection: openSection.value,
  }
}
function applyListingDraft(d) {
  if (d.form) form.value = { ...form.value, ...d.form }
  if (Array.isArray(d.hours)) hours.value = d.hours
  if (Array.isArray(d.groups)) groups.value = d.groups
  if (Array.isArray(d.cells)) cells.value = d.cells
  if (d.pattern) pattern.value = d.pattern
  if (typeof d.dynamicPricing === 'boolean') dynamicPricing.value = d.dynamicPricing
  if (d.pay) pay.value = { ...pay.value, ...d.pay }
  if (Array.isArray(d.connectedAddons)) connectedAddons.value = d.connectedAddons
  if (Array.isArray(d.connectedResources)) connectedResources.value = d.connectedResources
  if (d.openSection !== undefined) openSection.value = d.openSection
  syncFixedFromTimes(form.value.fixed_start_times)
  prevLocationId.value = form.value.location_id
}
async function offerListingDraft() {
  const key = listingDraftKey()
  const d = loadFormDraft(key)
  if (!d) return
  const resume = await askConfirm({
    title: 'Resume your draft?', danger: false,
    message: `You saved an unfinished version of this form ${draftAgeLabel(d.at)}. Continue where you left off?`,
    confirmText: 'Resume draft', cancelText: 'Start fresh',
  })
  if (resume) {
    applyListingDraft(d.data)
    if (form.value.location_id) { await loadLocationHours(form.value.location_id); await checkProviderOnline(form.value.location_id) }
    markDirty()   // restored draft is still unsaved — keep the guard armed
  } else {
    clearFormDraft(key)
  }
}
function saveListingDraftNow() {
  saveFormDraft(listingDraftKey(), listingDraftSnapshot())
  clearDirty()
  registerDraftSaver(null)
  toast.success('Draft saved — it will be offered when you reopen this form')
  view.value = 'list'
}
// Platform staff manage the platform catalog (their cap is MANAGE_PLATFORM_CATALOG,
// not the provider-side MANAGE_LISTINGS) — either cap unlocks this page's editing.
const canManageListings = computed(() => can(CAP.MANAGE_LISTINGS) || can(CAP.MANAGE_PLATFORM_CATALOG))

// "Add" dropdown options (single button → polished menu, instead of crowded buttons).
const pageAddItems = computed(() => {
  const items = []
  if (canManageListings.value) {
    items.push({ key: 'location', label: 'Location', desc: 'A venue or site that holds your listings', icon: Landmark })
    items.push({ key: 'listing', label: 'Booking Listing', desc: 'Any space people can book by the hour — court, studio, hall, room or desk', icon: Boxes })
  }
  return items
})
function onPageAdd(key) {
  if (key === 'location') openNewLoc()
  else openNewListing(null)
}
// Listings live inside a location — block creating one until a location
// exists, with a friendly prompt instead of an empty form.
const needLocationModal = ref(false)
const hasUsableLocation = computed(() => listingFormLocations.value.length > 0)

// Jump to the Weekly Schedule section (from the "weekly schedule" text buttons).
function goToSchedule() {
  openSection.value = 'schedule'
  nextTick(() => {
    const card = [...document.querySelectorAll('.section-card')].find(c => /Weekly Schedule/.test(c.querySelector('.section-title')?.textContent || ''))
    card?.scrollIntoView({ behavior: 'smooth', block: 'start' })
  })
}

// Session-extension discount on/off toggle (drives form.extension_pricing: none|percent|flat).
const extensionEnabled = computed(() => !!form.value.extension_pricing && form.value.extension_pricing !== 'none')
function toggleExtension(on) {
  if (on) { if (!form.value.extension_pricing || form.value.extension_pricing === 'none') form.value.extension_pricing = 'percent' }
  else { form.value.extension_pricing = 'none' }
}

const locAddItems = computed(() => {
  const items = []
  if (canManageListings.value) items.push({ key: 'listing', label: 'Booking Listing', desc: 'Any space people can book by the hour — court, studio, hall, room or desk', icon: Boxes })
  return items
})
function onLocAdd(key, locId) { if (key === 'listing') openNewListing(locId) }

/* view = 'list' | 'editor' */
const view = ref('list')
// The editor's draft saver must never outlive the editor — otherwise leaving
// the page with some OTHER dirty state would snapshot a stale listing form.
watch(view, v => { if (v !== 'editor') registerDraftSaver(null) })
const userRole = computed(() => (isSuperAdmin.value || isCountryAdmin.value || actorRole.value === 'platform_staff') ? 'admin' : 'provider')
// A venue with no owner_user_id is Bookboll-owned → full online payment (all money is Bookboll's).
const selectedLocBookbollOwned = computed(() => {
  const loc = locations.value.find(l => l.id === form.value.location_id)
  return loc ? !loc.owner_user_id : false
})

const locations = ref([])
const listings = ref([])
const cities = ref([])

// Platform jurisdiction (country_admin / platform_staff only see their countries' venues)
const { allowedLocationIds, scopeCountries, scopeCities } = useJurisdiction({ locations, cities })
const allAddons = ref([])
// { [countryId]: { facility: number, program: number } }
const commissionRates = ref({})
const allResources = ref([])
const search = ref('')
const filterCountry = ref([])       // multi-select country ids
const filterCity = ref([])          // multi-select city ids
const filterCategory = ref('all')   // 'all' | venue category key (sports/hangout/work)
const filterType = ref('all')       // 'all' | sport_category value
const filterLocation = ref('')      // '' | location id

// Server-driven pagination. The grouped view pages over VENUE CARDS; the flat view
// pages over LISTINGS. Totals come from the server so the footers stay honest.
// The grouped view's current page of venue cards. Kept apart from `locations`,
// which must stay the complete list for lookups.
const venueCards    = ref([])
// Sport dropdown options for the whole scope, supplied by the server.
const sportOptions  = ref([])
const venuePage     = ref(1)
const venuePerPage  = ref(10)
const venueTotal    = ref(0)
const venueLastPage = ref(1)
const flatPage      = ref(1)
const flatPerPage   = ref(25)
const flatTotal     = ref(0)
const flatLastPage  = ref(1)
// Listings in scope ignoring filters — the export dialog offers both counts.
const scopeTotal    = ref(0)
const showFilters = ref(false)
const expanded = ref(new Set())

// Country/City filter options (multi-select). City options narrow to the chosen
// countries (all cities when none chosen).
// Options honour the jurisdiction (country admin filters only list their countries).
// …and to geos where this viewer actually HAS listings — a provider in
// one city isn't offered the whole platform's countries.
const usedGeo = computed(() => {
  // Venue CARDS render even before their first listing — geo options must cover
  // every venue in view, not just venues that already have listings.
  const cityIds = new Set(locations.value.map(l => l.city_id).filter(Boolean))
  const countryIds = new Set(cities.value.filter(c => cityIds.has(c.id)).map(c => c.country_id).filter(Boolean))
  return { cityIds, countryIds }
})
const countryOptions = computed(() => scopeCountries(countries.value)
  .filter(c => !usedGeo.value.countryIds.size || usedGeo.value.countryIds.has(c.id))
  .map(c => ({ value: c.id, label: c.name })))
const cityOptions = computed(() => {
  const sel = filterCountry.value.map(String)
  const base = scopeCities(cities.value)
    .filter(c => !usedGeo.value.cityIds.size || usedGeo.value.cityIds.has(c.id))
  const list = sel.length ? base.filter(c => sel.includes(String(c.country_id))) : base
  return list.map(c => ({ value: c.id, label: c.name }))
})
// Set countries, pruning selected cities that no longer belong to a chosen country.
function setFilterCountries(ids) {
  filterCountry.value = ids
  if (ids.length) {
    const allowed = new Set(cities.value.filter(c => ids.map(String).includes(String(c.country_id))).map(c => String(c.id)))
    filterCity.value = filterCity.value.filter(id => allowed.has(String(id)))
  }
}

// ── Venue category / type filters (mirrors the customer storefront) ───────────
// Type chips are the distinct sport_category values actually present for the
// chosen category, so admins only ever see real, matching options.
watch(filterCategory, () => { filterType.value = 'all' })
const activeFilterCount = computed(() =>
  (filterCountry.value.length ? 1 : 0) + (filterCity.value.length ? 1 : 0) +
  (filterLocation.value ? 1 : 0) +
  (filterCategory.value !== 'all' ? 1 : 0) + (filterType.value !== 'all' ? 1 : 0) +
  (flatFilterStatus.value ? 1 : 0) + (filterVisibility.value ? 1 : 0)
)
// Locations to offer in the Location filter, scoped by the chosen country/city.
const locationsForFilter = computed(() => locations.value.filter(l => {
  if (!idIn(countryOfLocation(l), filterCountry.value)) return false
  if (!idIn(cityOfLocation(l), filterCity.value)) return false
  return true
}))
const typeOptionsForFilter = computed(() => {
  if (filterCategory.value === 'all') return []
  // Dedupe case-insensitively (seed data has e.g. "Football" and "football"),
  // preferring a properly-cased variant for display.
  const groups = new Map()
  for (const l of sportOptions.value) {
    if (normCategory(l.category) !== filterCategory.value) continue
    const v = (l.sport || '').trim()
    if (!v) continue
    const k = v.toLowerCase()
    if (!groups.has(k)) groups.set(k, v)
    else if (!/[A-Z]/.test(groups.get(k)) && /[A-Z]/.test(v)) groups.set(k, v)
  }
  return [...groups.values()]
    .map(v => /[A-Z]/.test(v) ? v : v.replace(/\b\w/g, c => c.toUpperCase()))   // Title-case lowercase-only values
    .sort((a, b) => a.localeCompare(b, undefined, { sensitivity: 'base' }))
})
function clearListingFilters() {
  filterCountry.value = []; filterCity.value = []
  filterLocation.value = ''
  filterCategory.value = 'all'; filterType.value = 'all'
  flatFilterStatus.value = ''
  filterVisibility.value = ''
}
// Changing the category resets the type (the available types are category-scoped).
function setFilterCategory(k) { filterCategory.value = k; filterType.value = 'all' }

function countryOfLocation(loc) {
  const city = cities.value.find(c => c.id === loc?.city_id)
  return city?.country_id || null
}

function cityOfLocation(loc) {
  return loc?.city_id || null
}

const sportOpen = ref(false)
const sportQuery = ref('')
const sportExtra = ref([])   // types added in THIS session (pending approval), shown right away
// One flat list of every bookable type, each tagged with the category it belongs to.
// The user picks a TYPE; the category is derived from it (shown read-only) — no need
// to choose a category first. Built-ins + approved custom types + just-added ones.
const allTypeOptions = computed(() => {
  const opts = []
  const seen = new Set()
  const push = (type, cat, catLabel) => {
    const k = (cat + '::' + type).toLowerCase()
    if (seen.has(k)) return; seen.add(k)
    opts.push({ type, cat, catLabel })
  }
  SPORTS.forEach(t => push(t, 'sports', 'Sports'))
  subcategoriesFor('hangout').forEach(t => push(t, 'hangout', 'Hangout'))
  subcategoriesFor('work').forEach(t => push(t, 'work', 'Work'))
  approvedTypesFor('venue').forEach(r => push(r.name, r.category, categoryLabel(r.category)))
  sportExtra.value.forEach(o => push(o.type, o.cat, o.catLabel))
  return opts
})
const filteredTypes = computed(() => {
  const q = sportQuery.value.trim().toLowerCase()
  if (!q) return allTypeOptions.value
  return allTypeOptions.value.filter(o => o.type.toLowerCase().includes(q) || o.catLabel.toLowerCase().includes(q))
})
const derivedCategoryLabel = computed(() => form.value.category ? categoryLabel(form.value.category) : '')
function selectType(opt) {
  form.value.sport_category = opt.type
  form.value.category = opt.cat            // category is derived from the chosen type
  sportOpen.value = false
  sportQuery.value = ''
  addingType.value = false
}
function onSportInputFocus() { sportOpen.value = true }
function onSportBlur() { setTimeout(() => { sportOpen.value = false; addingType.value = false }, 150) }

// ── "Add new type" (when the searched type isn't in the list yet) ────────────────
// The provider names the type + picks ONE category; it's stored on this listing now and
// joins the shared catalog once the listing is approved (lib/customTypes.js).
const addingType = ref(false)
const newTypeName = computed(() => sportQuery.value.trim())
const canAddType = computed(() =>
  newTypeName.value.length >= 2 &&
  !allTypeOptions.value.some(o => o.type.toLowerCase() === newTypeName.value.toLowerCase()))
function startAddType() { addingType.value = true }
async function confirmAddType(catKey) {
  const name = newTypeName.value
  if (!name || !catKey) return
  await addPendingType({ domain: 'venue', name, category: catKey })
  sportExtra.value.push({ type: name, cat: catKey, catLabel: categoryLabel(catKey) })
  form.value.sport_category = name
  form.value.category = catKey
  addingType.value = false
  sportOpen.value = false
  sportQuery.value = ''
}

// Filter state that the SERVER applies. Everything the two views can filter by is
// sent as query params; the browser no longer holds the whole catalogue.
function catalogParams() {
  const p = {}
  if (!isSuperAdmin.value && currentOwnerUserId.value) p.owner_user_id = currentOwnerUserId.value
  if (search.value.trim()) p.search = search.value.trim()
  if (filterCountry.value.length) p.country_id = filterCountry.value.join(',')
  if (filterCity.value.length) p.city_id = filterCity.value.join(',')
  if (filterLocation.value) p.venue_id = filterLocation.value
  if (filterCategory.value !== 'all') p.category = filterCategory.value
  if (filterType.value !== 'all') p.sport = filterType.value
  if (flatFilterStatus.value) p.placement = flatFilterStatus.value
  if (filterVisibility.value) p.visibility = filterVisibility.value
  return p
}

let listSeq = 0
async function loadList() {
  const seq = ++listSeq
  const base = catalogParams()

  // The GROUPED view pages over venue cards and then pulls only those venues'
  // listings; the FLAT view pages over listings directly. Either way one page of
  // rows crosses the wire instead of the entire catalogue.
  const grouped = layout.value === 'grouped'

  // `locations` is the page's venue LOOKUP table — every editor, picker and filter
  // dropdown resolves a venue through it — so it stays the full scoped list and is
  // never paginated. The grouped view's CARDS are a separate, paged request.
  const listingQuery = grouped
    ? base                                   // filled in below, once the cards are known
    : { ...base, page: flatPage.value, per_page: flatPerPage.value, sort: flatSortKey.value, sort_dir: flatSortDir.value }

  const [venuesRes, cardsRes, ref, addOnsRes, resourcesRes] = await Promise.all([
    fetchVenuesDashboard({}),
    grouped
      ? fetchVenuesDashboard({ ...base, page: venuePage.value, per_page: venuePerPage.value, match_listings: 1 })
      : Promise.resolve(null),
    loadReferenceData(),
    fetchAddOns(),
    fetchResources(),
  ])
  if (seq !== listSeq) return

  cities.value = ref.cities
  countries.value = ref.countries
  locations.value = venuesRes.data?.locations || []

  venueCards.value = grouped ? (cardsRes?.data?.locations || []) : []
  venueTotal.value = cardsRes?.data?.pagination?.total ?? venueCards.value.length
  venueLastPage.value = cardsRes?.data?.pagination?.last_page ?? 1

  // Grouped: fetch listings for exactly the venue cards on screen.
  const listingsRes = await fetchListingsDashboard(
    grouped
      ? { ...listingQuery, venue_ids: venueCards.value.map(l => l.id).join(',') || '0' }
      : listingQuery
  )
  if (seq !== listSeq) return

  listings.value = listingsRes.data?.listings || []
  flatTotal.value = listingsRes.data?.pagination?.total ?? listings.value.length
  flatLastPage.value = listingsRes.data?.pagination?.last_page ?? 1
  scopeTotal.value = listingsRes.data?.scope_total ?? flatTotal.value
  if (listingsRes.data?.sport_options) sportOptions.value = listingsRes.data.sport_options

  allAddons.value = addOnsRes.success ? (addOnsRes.data || []) : []
  allResources.value = resourcesRes.success ? (resourcesRes.data || []) : []
  const rates = {}
  for (const row of ref.commissionRates) {
    if (!rates[row.country_id]) rates[row.country_id] = { facility: 20, program: 20 }
    rates[row.country_id][row.listing_type] = Number(row.rate_percent)
  }
  commissionRates.value = rates
  // Default: every location card starts uncollapsed. Reassign the Set (not just .add)
  // so the change reliably triggers re-render. The size guard preserves the user's
  // manual collapses when loadList re-runs (e.g. after saving a listing).
  if (expanded.value.size === 0 && venueCards.value.length) {
    expanded.value = new Set(venueCards.value.map(l => l.id))
  }
}
onMounted(loadList)
onMounted(() => loadApprovedTypes())

// Deep-link into a creation form: /listings?add=location|listing|program
// (used by the floating Setup coach so its CTA lands the user IN the form,
// not just on this page). Waits for the list load so the location gate
// (openNewListing needs a usable location) evaluates real data. Watched, not
// just mounted — the coach can fire it while already on this page.
// Memberships live at /memberships — add=program redirects there.
const route = useRoute()
async function handleAddQuery(a) {
  if (!a) return
  if (a === 'program') {
    router.replace({ path: '/memberships', query: { new: '1' } })
    return
  }
  await loadList()
  router.replace({ query: { ...route.query, add: undefined } })
  if (a === 'location') openNewLoc()
  else if (a === 'listing') openNewListing(null)
}
onMounted(() => handleAddQuery(route.query.add))
watch(() => route.query.add, (a) => { if (a) handleAddQuery(a) })

// Venue cards come back already filtered and paged: `match_listings` makes the
// server drop venues whose listings all filtered out, so the count in the pager
// and the cards on screen agree. Filtering again here would fight it.
const filteredLocations = computed(() => venueCards.value)

// Grouped mode fetches listings for exactly the venues on screen, with the same
// filters applied — so this only has to split them by venue.
const listingsByLocation = (id) => listings.value.filter(l => l.location_id === id)

function toggle(id) {
  if (expanded.value.has(id)) expanded.value.delete(id); else expanded.value.add(id)
  expanded.value = new Set(expanded.value)
}

/* location modal — now the shared <LocationModal> component (components/LocationModal.vue).
   This page only opens/closes it and refreshes the list when it saves. */
const locModal = ref(false)
const locEditing = ref(null)
const countries = ref([])   // still used by currencyForLocation() below

// True while the location modal was opened from the listing form's location
// dropdown ("＋ Create a new location…") — the saved location gets auto-assigned.
const locFromListingForm = ref(false)
function openNewLoc() {
  locFromListingForm.value = false
  locEditing.value = null
  locModal.value = true
}
function openEditLoc(l) {
  locFromListingForm.value = false
  locEditing.value = l
  locModal.value = true
}
async function onLocationSaved(loc) {
  locModal.value = false
  await loadList()
  // The setup guide sits on this same page — tell it the venue step is done,
  // otherwise it keeps asking for the venue that was just added.
  notifySetupChanged()
  // Created from the listing form's dropdown → assign it to the listing right
  // away (through the normal change path, so the add-on/resource detach
  // confirmation still applies when relevant).
  if (locFromListingForm.value && loc?.id && view.value === 'editor') {
    locFromListingForm.value = false
    form.value.location_id = loc.id
    onLocationSelectChange()
    return
  }
  locFromListingForm.value = false
  // If we're inside the listing editor, refresh this location's hours so the
  // schedule grid unlocks the slots the provider just opened.
  if (form.value.location_id) loadLocationHours(form.value.location_id)
}

// From the schedule grid: the provider clicked a slot that's locked because the
// location is closed then. Jump straight to that location's own schedule editor.
function editLocationSchedule() {
  const loc = locations.value.find(l => l.id === form.value.location_id)
  if (!loc) { toast.error('Choose a location for this listing first.'); return }
  openEditLoc(loc)
}

// From the schedule grid: open the Special Days page to add a one-off custom
// schedule for this listing's location (and the listing itself, if it exists yet).
function createSpecialDayForListing() {
  const q = { new: '1' }
  if (form.value.location_id) q.location_id = form.value.location_id
  if (editing.value?.id) q.listing_id = editing.value.id
  router.push({ path: '/special-days', query: q })
}
async function removeLoc(id) {
  const loc = locations.value.find(l => l.id === id)
  const n = listings.value.filter(l => l.location_id === id).length
  if (!(await confirm({
    title: 'Delete location?',
    message: `“${loc?.name || 'This location'}”${n ? ` and its ${n} listing${n > 1 ? 's' : ''}` : ''} will be permanently removed. This cannot be undone.`,
    confirmText: 'Delete',
  }))) return
  const res = await deleteVenue(id)
  if (!res.success) { toast.error(venueFirstError(res, 'Could not delete location. It may have bookings linked to it.')); return }
  toast.success('Location deleted')
  loadList()
}
async function toggleLocStatus(loc) {
  const next = loc.status === 'active' ? 'inactive' : 'active'
  const res = await patchVenue(loc.id, { status: next })
  if (!res.success) { toast.error(venueFirstError(res, 'Could not update location status.')); return }
  loadList()
}

/* ---- eligibility pause ---- */
const pauseModal = ref(false)
const pauseTarget = ref(null)   // { table: 'locations'|'listings', row: object }
const pauseNote = ref('')

function openPause(table, row) {
  pauseTarget.value = { table, row }
  pauseNote.value = row.admin_note || ''
  pauseModal.value = true
}
async function savePause() {
  const { table, row } = pauseTarget.value
  const payload = {
    eligibility_status: 'paused',
    admin_note: pauseNote.value,
    previous_snapshot: null,
  }
  const res = table === 'locations'
    ? await patchVenue(row.id, payload)
    : await patchListing(row.id, payload)
  if (!res.success) {
    toast.error(table === 'locations'
      ? venueFirstError(res, 'Could not pause eligibility.')
      : listingFirstError(res, 'Could not pause eligibility.'))
    return
  }
  pauseModal.value = false
  loadList()
}
async function resumeEligibility(table, row) {
  const payload = {
    eligibility_status: 'eligible',
    admin_note: '',
    previous_snapshot: null,
  }
  const res = table === 'locations'
    ? await patchVenue(row.id, payload)
    : await patchListing(row.id, payload)
  if (!res.success) {
    toast.error(table === 'locations'
      ? venueFirstError(res, 'Could not resume eligibility.')
      : listingFirstError(res, 'Could not resume eligibility.'))
    return
  }
  loadList()
}

/* unified state helper — single source of truth */
function rowState(row) {
  const eligibility = row.eligibility_status || 'eligible'
  const approval = row.approval_status || 'approved'
  const hasSnapshot = !!row.previous_snapshot

  if (eligibility === 'paused' && approval === 'pending' && hasSnapshot) return 'resubmitted'
  if (eligibility === 'paused') return 'paused'
  if (approval === 'pending') return 'pending_new'
  return 'active'
}

/* ── Placement status for the listings page (replaces Active/Inactive) ─────────── */
// Bookboll marketplace state: off, or driven by the approval/eligibility review.
function marketplaceState(l) {
  if (l.show_on_marketplace === false) return { on: false, cls: 'off', icon: 'off', suffix: 'Off', live: false, tip: 'Hidden from the Bookboll marketplace — turn it on to reach Bookboll customers.' }
  const st = rowState(l)
  if (st === 'active') {
    // Live & approved, but new content (title/photos/description) is awaiting approval —
    // the listing keeps serving its approved content, so it stays LIVE with a small note.
    if (l.pending_content) return { on: true, cls: 'live', icon: 'live', suffix: 'Update in review', live: true, tip: 'Live on the Bookboll marketplace. Your latest title/description/photos are awaiting approval — the current ones stay live until then.' }
    return { on: true, cls: 'live', icon: 'live', suffix: '', live: true, tip: 'Live on the Bookboll marketplace.' }
  }
  if (st === 'paused')  return { on: true, cls: 'action', icon: 'action', suffix: 'Action required', live: false, tip: 'Paused by Bookboll — open the listing, make the requested changes and resubmit.' }
  return { on: true, cls: 'pending', icon: 'review', suffix: 'Under review', live: false, tip: 'Awaiting Bookboll approval — it will go live on the marketplace once approved.' }   // pending_new / resubmitted
}
// Provider's own white-label site state (no approval needed).
function siteState(l) {
  return l.show_on_provider_site === false
    ? { on: false, cls: 'off', icon: 'off', suffix: 'Off', live: false, tip: 'Hidden from your own website.' }
    : { on: true, cls: 'live', icon: 'live', suffix: '', live: true, tip: 'Live on your own website — visible to your customers right away.' }
}
// Maps a placement state to a status icon (the chip icon communicates STATUS, not channel).
function statusIcon(k) {
  return k === 'live' ? CircleCheck : k === 'action' ? AlertTriangle : k === 'review' ? Clock : EyeOff
}
// A listing hidden from both surfaces (dim the row).
function placementHidden(l) {
  return l.show_on_marketplace === false && l.show_on_provider_site === false
}
// Public URL for a live listing, for the "open in new tab" buttons.
//  - marketplace: the Bookboll storefront page
//  - site: the owner's white-label site (?b=<slug> works in every environment)
function listingUrl(row, surface) {
  const origin = appBaseUrl()
  const path = `listings/${row.id}`
  if (surface === 'marketplace') return `${origin}/${path}`
  const loc = locations.value.find(x => x.id === row.location_id)
  const owner = allBusinessOwners.value.find(u => u.id === loc?.owner_user_id)
  const slug = (owner?.brand_subdomain || owner?.username || '').toLowerCase()
  return slug ? `${origin}/${path}?b=${encodeURIComponent(slug)}` : `${origin}/${path}`
}
// The two placement channels for a listing, ready to render as chips.
function placementChannels(row) {
  const m = marketplaceState(row), s = siteState(row)
  return [
    { key: 'marketplace', name: 'Bookboll',  ...m, url: m.live ? listingUrl(row, 'marketplace') : null },
    { key: 'site',        name: 'Your site', ...s, url: s.live ? listingUrl(row, 'site') : null },
  ]
}

/* editor state */
const editing = ref(null)
// True only when the editor was opened on an EXISTING listing. Stays stable even after
// a NEW listing gets created mid-wizard (which sets `editing`), so we don't flip a
// new-listing flow into "save & close" mode partway through.
const editingExisting = ref(false)
// True while editing a live listing that has content (title/description/photos) awaiting
// Bookboll approval — the editor shows the SUBMITTED version and a "current one stays live"
// banner. Operational fields still edit live.
const contentUnderReview = ref(false)
const form = ref(defaultForm())
const hours = ref(defaultHours())
const locationHours = ref([])
const groups = ref([])
const cells = ref([])
const pattern = ref('B')
const pay = ref(defaultPay())
const connectedAddons = ref([])        /* [{id?, addon_id, sort_order}] */
const connectedResources = ref([])     /* [{id?, resource_id, quantity}] */
const providerHasOnline = ref(false)
// Full online payment now requires the venue owner's digital wallet to be set up,
// because we split the payment and pay out the provider's share. Bookboll-owned
// venues (no provider to pay) are exempt.
const ownerWalletActive = ref(false)
const locationIsBookbollOwned = computed(() => {
  const loc = locations.value.find(l => l.id === form.value.location_id)
  return !!loc && !loc.owner_user_id
})
const onlineAllowed = computed(() => locationIsBookbollOwned.value || ownerWalletActive.value)
// The country (name + id) of the location chosen in the listing form — used to name
// the country in the "set up a wallet" prompt and deep-link to it in My Wallet.
const formCountryId = computed(() => countryIdOfLocation(form.value.location_id))
const formCountryName = computed(() => countries.value.find(c => c.id === formCountryId.value)?.name || 'this country')
const formWalletLink = computed(() => formCountryId.value ? `/wallet/bookings?country=${formCountryId.value}` : '/wallet/bookings')
// "Set up your Bookings wallet" modal — opened when full-online is enabled without a ready bookings wallet.
const payoutModal = ref(false)
// Which payment section opened the payout-setup modal — the deposit fallback
// must act on THAT section's deposit option, not always the marketplace one.
const payoutModalSource = ref('marketplace')   // 'marketplace' | 'site'
// "Enable online deposit instead" from the payout-setup modal.
function payoutFallbackDeposit() {
  if (payoutModalSource.value === 'site') {
    // Own-website deposits go to Bookboll while payouts are missing — that
    // choice deserves the explainer, so route into the site-deposit modal.
    siteDepositModal.value = true
    return
  }
  pay.value.deposit_enabled = true
  markDirty()
  toast.success('Online deposit is on — the deposit covers Bookboll\'s fee and the rest is paid to you at your venue. No payout method needed.')
}
const savedFlag = ref(false)
const listingSaveError = ref('')
const openSection = ref('basic')

const slotPreview = computed(() => previewSlots(form.value.slot_duration, form.value.buffer_time))

// ── Custom start times: choose WHICH of the automatic (duration + buffer) slots
// to offer. customStartTimes holds the chosen subset; empty = offer them all. This
// guarantees every custom time respects the slot length, buffer & weekly schedule. ──
const pad2 = n => String(n).padStart(2, '0')
const customStartTimes = ref([])   // subset of autoStartTimes; [] = all offered
function isStartOffered(t) { return !customStartTimes.value.length || customStartTimes.value.includes(t) }
function toggleStartTime(t) {
  // Materialise "all" on the first tap, then toggle the chosen slot.
  let set = customStartTimes.value.length ? [...customStartTimes.value] : [...autoStartTimes.value]
  set = set.includes(t) ? set.filter(x => x !== t) : [...set, t]
  set.sort()
  // Collapse back to "offer all" when every slot is selected (keeps data clean).
  customStartTimes.value = set.length === autoStartTimes.value.length ? [] : set
}
// Persisted list: the chosen subset, or [] (= automatic) when nothing is customised.
const fixedStartTimes = computed(() => customStartTimes.value.length ? autoStartTimes.value.filter(isStartOffered) : [])
// Load a saved list into the selection (on edit).
function syncFixedFromTimes(times) {
  customStartTimes.value = Array.isArray(times) ? [...times] : []
}

function defaultForm() {
  return {
    // User-fillable fields start EMPTY so nothing is created with an assumed value.
    location_id: '', name: '', category: '', sport_category: '', description: '',
    base_price: null, slot_duration: 60, buffer_time: 0,
    start_time_mode: 'auto', fixed_start_times: [],
    capacity: 1, status: 'active',   // capacity is no longer a form field; kept non-null for the DB
    approval_status: 'pending', photo_url: '', photos: [],
    // Placement: both places ON by default (most providers want both); the payment
    // panels start COLLAPSED so the section stays calm — summaries show the facts,
    // and the save guard still prompts if the provider turns both places off.
    show_on_marketplace: true, show_on_provider_site: true,
    // Who may BOOK it. Separate axis from the two above (which decide WHICH
    // storefront it appears on): a listing can be bookable by staff at the desk
    // while hidden from customers, or the reverse. Both off = inactive.
    show_on_admin: true, show_on_customer: true,
    loyalty_points: 0, booking_color: '#10b981', listing_type: 'per_space',
    max_bookings_per_slot: 1, commission_rate: null,
    extension_pricing: 'none', extension_value: 0, extension_max_slots: 1,
  }
}

/* ── Country of a location (through cities) ─────────────────── */
function countryIdOfLocation(locationId) {
  const loc = locations.value.find(l => l.id === locationId)
  const city = cities.value.find(c => c.id === loc?.city_id)
  return city?.country_id || null
}

/* ── Effective commission rate for the listing being edited ─── */
const effectiveCommissionRate = computed(() => {
  if (form.value.commission_rate !== null && form.value.commission_rate !== '') {
    return Number(form.value.commission_rate)
  }
  const cid = countryIdOfLocation(form.value.location_id)
  return commissionRates.value[cid]?.facility ?? 15
})

/* ── Live money example for Placement & Payment ───────────────
   Percentages confuse people; a real split of a real booking doesn't. Uses the
   listing's slot price when set (else a round 100) so every payment option can
   show "who gets what, where" in actual money. */
const payCur = computed(() => currencyForLocation(form.value.location_id))
const payExample = computed(() => {
  const total = Number(form.value.base_price) > 0 ? Number(form.value.base_price) : 100
  const feePct = Math.max(0, Math.min(100, Number(effectiveCommissionRate.value) || 0))
  const fee = +(total * feePct / 100).toFixed(2)
  const sitePct = Math.max(10, Math.min(100, Number(pay.value.site_deposit_percentage) || 0))
  const siteDep = +(total * sitePct / 100).toFixed(2)
  return {
    total, feePct, fee, share: +(total - fee).toFixed(2),
    sitePct, siteDep, siteRest: +(total - siteDep).toFixed(2),
    isReal: Number(form.value.base_price) > 0,
  }
})
const fmtPay = (n) => Number(n || 0).toLocaleString(undefined, { maximumFractionDigits: 2 })

/* ── Provider country lock ──────────────────────────────────── */
// For providers: all listings must be in the same country.
// We determine the provider's "home country" from their existing locations.
const providerHomeCountryId = computed(() => {
  if (userRole.value !== 'provider') return null
  if (!locations.value.length) return null
  const firstLoc = locations.value[0]
  return countryIdOfLocation(firstLoc.id)
})

// Locations available for selection in the listing form. Providers may now operate
// in MULTIPLE countries, so every location they own is selectable — full online
// payment is gated per country by that country's wallet (see checkProviderOnline).
const listingFormLocations = computed(() => locations.value)
// Calendar booking colours — same swatch palette/UX as the Membership Program form.
const BOOKING_COLORS = ['#10b981', '#2563eb', '#0891b2', '#7c3aed', '#d97706', '#dc2626', '#db2777', '#475569']
function defaultHours() {
  // 24/7 fallback (matches the venue default; close '00:00' = end-of-day midnight) —
  // listings normally copy their location's hours.
  return Array.from({ length: 7 }, (_, i) => ({
    day_of_week: i, is_open: true,
    periods: [{ open_time: '00:00', close_time: '00:00' }],
  }))
}
// A new listing starts from a COPY of its location's current hours (it is not
// synced to the location afterwards — the location only restricts open slots).
function hoursFromLocation() {
  const grouped = locationHoursGrouped.value
  if (!grouped.length) return defaultHours()
  return Array.from({ length: 7 }, (_, i) => {
    const loc = grouped.find(l => l.day_of_week === i)
    if (loc && loc.is_open && loc.periods?.length) {
      return { day_of_week: i, is_open: true, periods: loc.periods.map(p => ({ ...p })) }
    }
    return { day_of_week: i, is_open: false, periods: [] }
  })
}
function defaultLocHours() {
  return Array.from({ length: 7 }, (_, i) => ({
    day_of_week: i, is_open: true,
    periods: [{ open_time: '09:00', close_time: '23:00' }],
  }))
}
function minutesOfH(t) { const [h, m] = t.split(':').map(Number); return h * 60 + m }
// Midnight CLOSE = end of day (12 PM → 12 AM is 12 open hours, not empty).
function endMinH(t) { const m = minutesOfH(t); return m === 0 ? 24 * 60 : m }
function defaultPay() {
  const isProvider = userRole.value === 'provider'
  return {
    cash_on_arrival: false,
    deposit_enabled: true,
    deposit_amount: 0,
    deposit_percentage: 20,
    online_payment_enabled: !isProvider,   // Bookboll admin gets online available by default
    // Provider's OWN-website payment options (independent of the marketplace ones above).
    // All own-site revenue is the provider's. Everything starts OFF so the provider makes
    // a conscious choice — ticking "deposit" without a payout method opens the explainer
    // modal (where does the money go), instead of silently defaulting them into it.
    site_deposit_enabled: false,
    site_deposit_percentage: 10,
    site_online_enabled: false,
    site_cash_enabled: false,
  }
}

async function openNewListing(presetLocationId) {
  if (!presetLocationId && !hasUsableLocation.value) { needLocationModal.value = true; return }
  editing.value = null
  editingExisting.value = false
  contentUnderReview.value = false
  dynamicNudgeAsked.value = false
  extensionNudgeAsked.value = false
  form.value = { ...defaultForm(), location_id: presetLocationId || '' }
  prevLocationId.value = form.value.location_id
  syncFixedFromTimes(form.value.fixed_start_times)
  hours.value = defaultHours()
  // Dynamic pricing starts OFF — instead of pre-checking it, the save flow makes
  // the revenue case once and lets the provider opt in knowingly (or skip).
  groups.value = []; cells.value = []; pattern.value = 'B'; dynamicPricing.value = false
  pay.value = defaultPay()
  connectedAddons.value = []
  connectedResources.value = []
  locationHours.value = []
  if (presetLocationId) {
    await loadLocationHours(presetLocationId)
    hours.value = hoursFromLocation()   // seed new listing from the location schedule
  }
  await checkProviderOnline(presetLocationId)
  openSection.value = 'basic'
  payOpen.value = { mkt: false, site: false }   // panels expand when a place is turned on
  listingTriedSave.value = false
  listingSaveError.value = ''
  clearDirty()
  await offerListingDraft()
  registerDraftSaver(() => saveFormDraft(listingDraftKey(), listingDraftSnapshot()))
  view.value = 'editor'
}

async function openEditListing(l) {
  editing.value = l
  editingExisting.value = true
  form.value = {
    ...defaultForm(),
    ...l,
    loyalty_points: l.loyalty_points ?? 10,
    booking_color: l.booking_color || '#10b981',
    listing_type: l.listing_type || 'per_space',
    max_bookings_per_slot: l.max_bookings_per_slot ?? 1,
    extension_max_slots: Math.max(1, Number(l.extension_max_slots) || 1),
    show_on_marketplace: l.show_on_marketplace !== false,
    show_on_provider_site: l.show_on_provider_site !== false,
    show_on_admin: l.show_on_admin !== false,
    show_on_customer: l.show_on_customer !== false,
    // Normalise images into the photos[] gallery (migrate a legacy single photo_url).
    photos: Array.isArray(l.photos) && l.photos.length ? [...l.photos] : (l.photo_url ? [l.photo_url] : []),
  }
  // A live listing with content awaiting review → edit the SUBMITTED version (the approved
  // one keeps serving). Reverting these back to the approved values withdraws the request.
  const pc = l.pending_content
  contentUnderReview.value = !!(pc && (l.approval_status || 'approved') === 'approved')
  if (contentUnderReview.value) {
    if (pc.name != null) form.value.name = pc.name
    if (pc.description != null) form.value.description = pc.description
    if (Array.isArray(pc.photos) && pc.photos.length) form.value.photos = [...pc.photos]
    else if (pc.photo_url) form.value.photos = [pc.photo_url]
  }
  prevLocationId.value = form.value.location_id
  syncFixedFromTimes(form.value.fixed_start_times)
  await loadLocationHours(l.location_id)

  const dash = await fetchListingDashboard(l.id)
  if (dash.success && dash.data) {
    const det = dash.data.listing
    if (det) {
      editing.value = { ...editing.value, ...det }
      // Merge richer dashboard fields into the form, then re-apply pending overlay.
      form.value = {
        ...form.value,
        ...det,
        loyalty_points: det.loyalty_points ?? form.value.loyalty_points ?? 10,
        booking_color: det.booking_color || form.value.booking_color || '#10b981',
        listing_type: det.listing_type || form.value.listing_type || 'per_space',
        max_bookings_per_slot: det.max_bookings_per_slot ?? form.value.max_bookings_per_slot ?? 1,
        extension_max_slots: Math.max(1, Number(det.extension_max_slots ?? form.value.extension_max_slots) || 1),
        show_on_marketplace: det.show_on_marketplace !== false,
        show_on_provider_site: det.show_on_provider_site !== false,
        show_on_admin: det.show_on_admin !== false,
        show_on_customer: det.show_on_customer !== false,
        photos: Array.isArray(det.photos) && det.photos.length ? [...det.photos] : (det.photo_url ? [det.photo_url] : (form.value.photos || [])),
      }
      const pc2 = det.pending_content
      contentUnderReview.value = !!(pc2 && (det.approval_status || 'approved') === 'approved')
      if (contentUnderReview.value) {
        if (pc2.name != null) form.value.name = pc2.name
        if (pc2.description != null) form.value.description = pc2.description
        if (Array.isArray(pc2.photos) && pc2.photos.length) form.value.photos = [...pc2.photos]
        else if (pc2.photo_url) form.value.photos = [pc2.photo_url]
      }
      syncFixedFromTimes(form.value.fixed_start_times)
    }

    const apiHours = dash.data.hours || []
    if (apiHours.length) {
      // API returns period-shaped days (may include inherit_from_location).
      hours.value = Array.from({ length: 7 }, (_, i) => {
        const r = apiHours.find(h => Number(h.day_of_week) === i)
        if (!r) return { day_of_week: i, is_open: false, periods: [] }
        if (r.inherit_from_location) {
          const loc = locationHoursGrouped.value.find(x => x.day_of_week === i)
          if (loc && loc.is_open && loc.periods?.length) {
            return { day_of_week: i, is_open: true, periods: loc.periods.map(p => ({ ...p })) }
          }
          return { day_of_week: i, is_open: false, periods: [] }
        }
        return {
          day_of_week: i,
          is_open: !!r.is_open,
          periods: r.is_open && r.periods?.length
            ? r.periods.map(p => ({ open_time: p.open_time, close_time: p.close_time }))
            : [],
        }
      })
    } else {
      hours.value = hoursFromLocation()
    }
    groups.value = dash.data.pricing_groups || []
    cells.value = dash.data.pricing_cells || []
    pattern.value = cells.value.some(x => x.pricing_group_id) ? 'A' : (cells.value.length ? 'B' : 'A')
    dynamicPricing.value = cells.value.length > 0 || groups.value.length > 0
    pay.value = dash.data.payment ? { ...defaultPay(), ...dash.data.payment } : defaultPay()
    connectedAddons.value = (dash.data.addons || []).map(x => ({ id: x.id, addon_id: x.addon_id, sort_order: x.sort_order }))
    connectedResources.value = (dash.data.resources || []).map(x => ({ id: x.id, resource_id: x.resource_id, quantity: x.quantity }))
  } else {
    hours.value = hoursFromLocation()
    groups.value = []
    cells.value = []
    pattern.value = 'A'
    dynamicPricing.value = false
    pay.value = defaultPay()
    connectedAddons.value = []
    connectedResources.value = []
    if (!dash.success) toast.error(listingFirstError(dash, 'Could not load listing details.'))
  }

  await checkProviderOnline(l.location_id)
  openSection.value = ''   // editing → open with all cards collapsed
  payOpen.value = { mkt: false, site: false }   // payment panels collapsed (summaries show the facts)
  listingTriedSave.value = false
  listingSaveError.value = ''
  clearDirty()
  await offerListingDraft()
  registerDraftSaver(() => saveFormDraft(listingDraftKey(), listingDraftSnapshot()))
  view.value = 'editor'
}

/* ---- payment account (inline connect/manage) ---- */
const providerAccount = ref(null)        // the connected account object or null
const payAcctModal = ref(false)
const payAcctForm = ref(defaultPayAcctForm())
const payAcctEditing = ref(false)

function defaultPayAcctForm() {
  return { account_holder: '', bank_name: '', iban: '', swift: '', currency: 'AED' }
}

async function checkProviderOnline(locationId) {
  providerHasOnline.value = false
  providerAccount.value = null
  ownerWalletActive.value = false
  if (!locationId) return
  const loc = locations.value.find(l => l.id === locationId)
  if (!loc?.owner_user_id) return   // Bookboll-owned → exempt via locationIsBookbollOwned
  // An ACTIVE payout method is the prerequisite for full online payment — and it is NOT
  // per-country: any active wallet (in any country) lets the provider take online payments.
  // "Active" means Stripe is connected OR a manual (Bookboll Wallet) method has been
  // APPROVED by Bookboll. A merely-submitted ('pending_review') method does not count.
  // select('*') so newer columns (purpose, payout_paused) are seen whenever they exist.
  const wRes = await fetchWallets({ owner_user_id: loc.owner_user_id })
  const ws = wRes.success ? (wRes.data?.wallets || []) : []
  // Full online payment for venue bookings requires an ACTIVE payout method on a BOOKINGS wallet
  // (the Bookboll Wallet shortcut is subscription-only, so venue online needs a real rail).
  ownerWalletActive.value = hasActivePayoutMethodFor(ws, 'bookings')
  // Enforce the gate in the form: a provider venue without an active wallet
  // can't have full online payment on. Keep at least one method enabled.
  if (!ownerWalletActive.value && pay.value?.online_payment_enabled) {
    pay.value.online_payment_enabled = false
    if (!pay.value.deposit_enabled && !pay.value.cash_on_arrival) pay.value.deposit_enabled = true
  }
  // (legacy) keep the connected payout account in view if one exists.
  const acct = await fetchPaymentAccount(loc.owner_user_id)
  if (acct) {
    providerAccount.value = acct
    providerHasOnline.value = true
  }
}

function openPayAcctConnect() {
  payAcctEditing.value = false
  payAcctForm.value = defaultPayAcctForm()
  payAcctModal.value = true
}

function openPayAcctManage() {
  payAcctEditing.value = true
  payAcctForm.value = {
    account_holder: providerAccount.value.account_holder || '',
    bank_name: providerAccount.value.bank_name || '',
    iban: providerAccount.value.iban || '',
    swift: providerAccount.value.swift || '',
    currency: providerAccount.value.currency || 'AED',
  }
  payAcctModal.value = true
}

async function savePayAcct() {
  const loc = locations.value.find(l => l.id === form.value.location_id)
  const ownerId = loc?.owner_user_id || currentOwnerUserId.value
  const res = payAcctEditing.value && providerAccount.value
    ? await updatePaymentAccount(providerAccount.value.id, { ...payAcctForm.value })
    : await createPaymentAccount({ ...payAcctForm.value }, ownerId)
  if (!res.success) { toast.error(res.message || 'Could not save the payment account.'); return }
  providerAccount.value = res.data?.account || null
  providerHasOnline.value = !!providerAccount.value
  payAcctModal.value = false
}

async function deletePayAcct() {
  if (!(await confirm({
    title: 'Disconnect payment account?',
    message: 'This payment account will be removed and online payments will be disabled for this listing.',
    confirmText: 'Disconnect',
  }))) return
  const res = await deletePaymentAccount(providerAccount.value.id)
  if (!res.success) { toast.error(res.message || 'Could not remove the payment account.'); return }
  providerAccount.value = null
  providerHasOnline.value = false
  pay.value.online_payment_enabled = false
  if (!pay.value.deposit_enabled) pay.value.deposit_enabled = true   // keep ≥1 method on
  payAcctModal.value = false
  toast.success('Payment account disconnected')
}

async function loadLocationHours(id) {
  if (!id) { locationHours.value = []; return }
  const res = await fetchVenueDashboard(id)
  if (!res.success) { locationHours.value = []; return }
  const raw = res.data?.hours || []
  // locationHoursGrouped expects flat rows (day_of_week, is_open, open_time, close_time).
  // API may return that, or period-shaped days — flatten the latter.
  if (raw.some(r => Array.isArray(r?.periods))) {
    const flat = []
    for (const d of raw) {
      if (!d.is_open || !d.periods?.length) {
        flat.push({ day_of_week: d.day_of_week, is_open: false, open_time: '09:00', close_time: '23:00' })
      } else {
        for (const p of d.periods) {
          flat.push({ day_of_week: d.day_of_week, is_open: true, open_time: p.open_time, close_time: p.close_time })
        }
      }
    }
    locationHours.value = flat
  } else {
    locationHours.value = raw
  }
}

async function onLocationChange() {
  await loadLocationHours(form.value.location_id)
  // A new (unsaved) listing adopts the chosen location's hours as its starting
  // point. Existing listings keep their own hours when moved to a new location.
  if (!editingExisting.value) hours.value = hoursFromLocation()
  await checkProviderOnline(form.value.location_id)
}

// ── Changing a listing's location: warn if connected add-ons/resources belong
// to the old location (they only work at their own location, so they'd detach).
const prevLocationId = ref('')
const locChangeModal = ref(null)   // null | { newLoc, prevLoc, addons:[], resources:[] }
function connectedItemsForeignTo(newLocId) {
  const addons = connectedAddons.value
    .map(r => allAddons.value.find(a => a.id === r.addon_id))
    .filter(a => a && a.location_id && a.location_id !== newLocId)
  const resources = connectedResources.value
    .map(r => allResources.value.find(x => x.id === r.resource_id))
    .filter(x => x && x.location_id && x.location_id !== newLocId)
  return { addons, resources }
}
function onLocationSelectChange() {
  const newLoc = form.value.location_id
  // "＋ Create a new location…" → open the location modal on top of the form;
  // the select snaps back until the new location is actually saved.
  if (newLoc === '__create__') {
    form.value.location_id = prevLocationId.value || ''
    locFromListingForm.value = true
    locEditing.value = null
    locModal.value = true
    return
  }
  if (newLoc && newLoc !== prevLocationId.value) {
    const aff = connectedItemsForeignTo(newLoc)
    if (aff.addons.length || aff.resources.length) {
      locChangeModal.value = { newLoc, prevLoc: prevLocationId.value, addons: aff.addons, resources: aff.resources }
      return   // hold the change until the provider confirms
    }
  }
  applyLocationChange(newLoc)
}
async function applyLocationChange(newLoc) {
  prevLocationId.value = newLoc
  await onLocationChange()
}
async function confirmLocationChange() {
  const ctx = locChangeModal.value
  locChangeModal.value = null
  if (!ctx) return
  const badAddon = new Set(ctx.addons.map(a => a.id))
  const badRes = new Set(ctx.resources.map(r => r.id))
  connectedAddons.value = connectedAddons.value.filter(r => !badAddon.has(r.addon_id))
  connectedResources.value = connectedResources.value.filter(r => !badRes.has(r.resource_id))
  await applyLocationChange(ctx.newLoc)
}
function cancelLocationChange() {
  const ctx = locChangeModal.value
  locChangeModal.value = null
  if (ctx) form.value.location_id = ctx.prevLoc   // revert the select to the old location
}

// Group raw location_hours rows (one per period) into periods-format objects
const locationHoursGrouped = computed(() => {
  const map = new Map()
  for (const r of locationHours.value) {
    if (!map.has(r.day_of_week)) map.set(r.day_of_week, { day_of_week: r.day_of_week, is_open: false, periods: [] })
    const entry = map.get(r.day_of_week)
    if (r.is_open) { entry.is_open = true; entry.periods.push({ open_time: r.open_time, close_time: r.close_time }) }
  }
  return Array.from(map.values())
})

// Listings keep their own concrete hours, so the "merged" list is just the hours.
const mergedHoursList = computed(() => hours.value)

// Automatic start times: the SINGLE bookable grid customers actually get — generateSlots
// (slot length + buffer, one consistent daily window). Drives the always-on "Offered to
// customers" preview. (Previously this unioned each weekday's open hours, which with a
// non-hourly slot length interleaved misaligned, overlapping start times.)
const autoStartTimes = computed(() => {
  const dur = Number(form.value.slot_duration) || 0
  if (!dur) return []
  return generateSlots({ slot_duration: dur, buffer_time: Number(form.value.buffer_time) || 0 }).map(s => s.start_time)
})
// The slots offered to customers: the automatic grid (slot length + buffer + weekly
// schedule) minus any the provider has tapped off. All on = every slot offered.
const displayedStartTimes = computed(() => autoStartTimes.value.filter(isStartOffered))

// Warn when a listing's custom hours fall outside its location's operating hours
const _DAY_LABELS = { 0: 'Sunday', 1: 'Monday', 2: 'Tuesday', 3: 'Wednesday', 4: 'Thursday', 5: 'Friday', 6: 'Saturday' }
const listingHoursOutOfBounds = computed(() => {
  if (!form.value.location_id || !locationHoursGrouped.value.length) return []
  const warnings = []
  for (const h of hours.value) {
    if (!h.is_open || !h.periods?.length) continue
    const locDay = locationHoursGrouped.value.find(l => l.day_of_week === h.day_of_week)
    if (!locDay || !locDay.is_open || !locDay.periods?.length) {
      warnings.push(`${_DAY_LABELS[h.day_of_week]}: location is closed on this day`)
      continue
    }
    for (const lp of h.periods) {
      const lpS = minutesOfH(lp.open_time), lpE = endMinH(lp.close_time)
      const covered = locDay.periods.some(cp => lpS >= minutesOfH(cp.open_time) && lpE <= endMinH(cp.close_time))
      if (!covered) warnings.push(`${_DAY_LABELS[h.day_of_week]}: ${fmtRange12(lp.open_time, lp.close_time)} is outside location hours`)
    }
  }
  return warnings
})

const locationName = (id) => locations.value.find(l => l.id === id)?.name || '—'
const providerName = (id) => allBusinessOwners.value.find(p => p.id === id)?.business_name || '—'

function currencyForLocation(locationId) {
  const loc = locations.value.find(l => l.id === locationId)
  if (loc?.currency_override) return loc.currency_override
  const city = cities.value.find(c => c.id === loc?.city_id)
  const country = countries.value.find(c => c.id === city?.country_id)
  return country?.currency_code || 'AED'
}

/* section completeness */
const basicComplete = computed(() => !!form.value.name && !!form.value.location_id && !!form.value.category && !!form.value.sport_category && !!form.value.description)
// Provider needs ≥1 of deposit / full-online on, and an active wallet if full-online is on.
const payError = computed(() => {
  // Marketplace payment options (only validated when listed on the marketplace).
  if (form.value.show_on_marketplace !== false) {
    if (userRole.value === 'provider') {
      if (!pay.value.deposit_enabled && !pay.value.online_payment_enabled) return 'Keep at least one Bookboll marketplace payment option on.'
      if (pay.value.online_payment_enabled && !onlineAllowed.value) return 'Set up a payout method first (Stripe for automatic split, or the Bookboll Wallet for manual withdrawal) to accept full online payments. Set it up in My Wallets.'
    } else {
      if (!pay.value.cash_on_arrival && !pay.value.deposit_enabled && !pay.value.online_payment_enabled) return 'Enable at least one marketplace payment method.'
      if (pay.value.online_payment_enabled && !onlineAllowed.value) return 'This provider must set up a payout method before full online payments can be enabled.'
    }
  }
  // Own-website payment options (provider listings on their own site).
  if (siteNoMethod.value) return 'Keep at least one payment option on for your own website.'
  if (!locationIsBookbollOwned.value && form.value.show_on_provider_site && siteDepositPctInvalid.value) {
    return 'The own-website online deposit must be between 10% and 100%.'
  }
  return ''
})
const payComplete = computed(() => !payError.value)
// Keep the provider's ≥1 invariant: never leave both deposit and online off.
// Also block turning full online on when the wallet isn't set up.
function onProviderOnlineToggle() {
  if (pay.value.online_payment_enabled && !onlineAllowed.value) {
    pay.value.online_payment_enabled = false
    if (!pay.value.deposit_enabled) pay.value.deposit_enabled = true
    payoutModalSource.value = 'marketplace'
    payoutModal.value = true   // guide them to set up the Bookings wallet
    return
  }
  if (!pay.value.online_payment_enabled && !pay.value.deposit_enabled) pay.value.deposit_enabled = true
}
// Same gate for the own-website "full online" toggle.
function onSiteOnlineToggle() {
  if (pay.value.site_online_enabled && !onlineAllowed.value) {
    pay.value.site_online_enabled = false
    payoutModalSource.value = 'site'
    payoutModal.value = true
  }
}
function onProviderDepositToggle() {
  // Not disabled (a greyed checkbox reads as broken) — unticking the last
  // active option snaps back with an explanation instead.
  if (!pay.value.deposit_enabled && !pay.value.online_payment_enabled) {
    pay.value.deposit_enabled = true
    toast.error('Keep at least one payment option on. To switch the deposit off, first turn on "Everything paid online" below.')
  }
}
// Bookboll admin: can't enable full online for a provider without an active wallet
// (Bookboll-owned venues are exempt).
function onAdminOnlineToggle() {
  if (pay.value.online_payment_enabled && !onlineAllowed.value) {
    pay.value.online_payment_enabled = false
    toast.error('This provider must set up a payout method before full online payments can be enabled.')
  }
}
const scheduleComplete = computed(() => hours.value.some(h => h.is_open))
const pricingComplete = computed(() => Number(form.value.base_price) > 0)
const timeslotComplete = computed(() => Number(form.value.slot_duration) > 0)
// True while the pricing grid is rejecting a price for being below the base price —
// we flag the base-price field so the user knows that's what to change.
const pricingBelowBase = ref(false)
// Dynamic pricing = different prices for different days/times. Off → every slot uses
// the base price; on → the day/time pricing grid (Slot Grid + Groups views) is shown.
const dynamicPricing = ref(false)
// One-shot guards for the revenue nudges (dynamic pricing / session extension) —
// each asks exactly once per listing creation, never on edits.
const dynamicNudgeAsked = ref(false)
const extensionNudgeAsked = ref(false)
async function onToggleDynamic() {
  if (dynamicPricing.value) return
  // Turning it off — clear any custom day/time prices (with a heads-up if there are any).
  if (cells.value.length || groups.value.length) {
    const ok = await confirm({
      title: 'Turn off dynamic pricing?',
      message: 'This clears all custom day & time prices. Every slot will use the base price above.',
      confirmText: 'Turn off', danger: true,
    })
    if (!ok) { dynamicPricing.value = true; return }
  }
  cells.value = []; groups.value = []; pricingBelowBase.value = false
}

// Placement: warn before a provider/admin turns OFF the recommended Bookboll
// marketplace placement (icon-led modal below). Works from the listing form AND
// from the inline chip dropdown on the listings page.
const mktConfirm = ref(null)   // null | { source:'form' } | { source:'list', row }
function onMarketplaceToggle() {   // form toggle
  if (!form.value.show_on_marketplace) {
    // Selling off-marketplace (own website only) is a PREMIUM feature — free
    // providers get the upgrade modal and the toggle snaps back on. Platform
    // staff and premium businesses fall through to the usual confirm.
    if (!requirePremium('own_site_only')) { form.value.show_on_marketplace = true; return }
    mktConfirm.value = { source: 'form' }   // turning it off → confirm
  }
}
function keepOnMarketplace() {
  if (mktConfirm.value?.source === 'form') form.value.show_on_marketplace = true   // restore the form toggle
  mktConfirm.value = null
}
async function confirmMarketplaceOff() {
  const ctx = mktConfirm.value
  mktConfirm.value = null
  if (ctx?.source === 'list' && ctx.row) await applyPlacement(ctx.row, 'marketplace', false)
  // form: the toggle is already off and will persist on Save.
}

// Own-website deposit without a payout method: the money question ("where do my
// deposits actually go?") is answered in a modal at the moment they opt in, not
// buried in a permanent warning banner. Three ways forward: connect payouts,
// knowingly proceed anyway, or fall back to pay-at-venue.
const siteDepositModal = ref(false)
function onSiteDepositToggle() {
  if (!pay.value.site_deposit_enabled) return          // turning it off is always fine
  if (userRole.value !== 'provider') return            // admin flow keeps the old behavior
  if (onlineAllowed.value) return                      // payouts connected — deposits reach them
  pay.value.site_deposit_enabled = false               // hold the tick until they pick a path
  siteDepositModal.value = true
}
async function siteDepositConnect() {
  if (!(await confirmDiscardIfDirty())) return         // navigating abandons the open form
  siteDepositModal.value = false
  router.push(formWalletLink.value)
}
function siteDepositAccept() {
  pay.value.site_deposit_enabled = true
  siteDepositModal.value = false
  markDirty()
}
// A tiny deposit doesn't deter no-shows and rounds to nothing on cheap slots —
// 10% is the floor. The value is NEVER silently rewritten: the field goes red,
// a toast explains, and saving/advancing is blocked until the provider fixes it.
const siteDepositPctInvalid = computed(() => {
  if (!pay.value.site_deposit_enabled) return false
  const n = Number(pay.value.site_deposit_percentage) || 0
  return n < 10 || n > 100
})
function onSiteDepositPctChange() {
  if (siteDepositPctInvalid.value) toast.error('The online deposit must be between 10% and 100% — please adjust it.')
}
async function siteDepositPayAtVenue() {
  siteDepositModal.value = false
  pay.value.site_cash_enabled = true
  markDirty()
  await onSitePayLaterToggle()   // reuse the no-show risk warning; reverts if declined
}

// Own-website "pay later" (full cash) — risky, so confirm before enabling.
async function onSitePayLaterToggle() {
  if (!pay.value.site_cash_enabled) return   // turning it off is always fine
  const ok = await askConfirm({
    danger: true,
    title: 'Enable pay-later (cash)?',
    message: "Risky — there's no guarantee the customer actually shows up. You can lose the revenue AND the timeslot stays blocked. A deposit or full online payment protects your business.",
    confirmText: 'Enable anyway',
    cancelText: 'Keep it off',
  })
  if (!ok) pay.value.site_cash_enabled = false
}
// At least one own-site method must stay on (only matters for provider listings).
const siteNoMethod = computed(() =>
  !locationIsBookbollOwned.value && form.value.show_on_provider_site &&
  !pay.value.site_deposit_enabled && !pay.value.site_online_enabled && !pay.value.site_cash_enabled
)

// ── Inline placement editing from the listings page (chip dropdowns) ───────────
const placementMenuKey = ref(null)   // `${listingId}:${surface}` of the open dropdown, or null
function togglePlacementMenu(id, surface) {
  const key = `${id}:${surface}`
  placementMenuKey.value = placementMenuKey.value === key ? null : key
}
async function applyPlacement(row, surface, value) {
  const col = surface === 'marketplace' ? 'show_on_marketplace' : 'show_on_provider_site'
  const res = await patchListing(row.id, { [col]: value })
  if (!res.success) { toast.error(listingFirstError(res, 'Could not update placement.')); return }
  await loadList()
}
function setPlacement(row, surface, value) {
  placementMenuKey.value = null
  // Turning Bookboll marketplace OFF is premium-gated, then routes through the
  // are-you-sure modal.
  if (surface === 'marketplace' && value === false) {
    if (!requirePremium('own_site_only')) return
    mktConfirm.value = { source: 'list', row }
    return
  }
  applyPlacement(row, surface, value)
}
function closePopovers() { placementMenuKey.value = null; showFilters.value = false }
onMounted(() => document.addEventListener('click', closePopovers))
onBeforeUnmount(() => document.removeEventListener('click', closePopovers))
const placementComplete = computed(() =>
  form.value.show_on_marketplace !== false || form.value.show_on_provider_site !== false
)

/* ── Collapsible payment panels (progressive disclosure) ──────────────────────
   Each channel's payment methods sit behind its "Getting paid" header. Collapsed
   by default when opening the editor (a compact summary keeps the facts visible);
   turning a place ON auto-expands its panel so the next step is obvious. */
const payOpen = ref({ mkt: false, site: false })
function onMarketplacePlacementChange() {
  if (form.value.show_on_marketplace) payOpen.value.mkt = true
  onMarketplaceToggle()
}
function onSitePlacementChange() {
  if (form.value.show_on_provider_site) payOpen.value.site = true
}
// Compact facts shown while a panel is collapsed.
const mktPaySummary = computed(() => {
  const s = []
  if (pay.value.deposit_enabled) s.push('Deposit + at venue')
  if (pay.value.online_payment_enabled) s.push('Everything online')
  if (pay.value.cash_on_arrival) s.push('Cash at venue')
  return s
})
const sitePaySummary = computed(() => {
  const s = []
  if (pay.value.site_deposit_enabled) s.push(`${Math.max(10, Math.min(100, Number(pay.value.site_deposit_percentage) || 0))}% deposit + at venue`)
  if (pay.value.site_online_enabled) s.push('Everything online')
  if (pay.value.site_cash_enabled) s.push('Pay at venue')
  return s
})
const addonsComplete = computed(() => true)
const resourcesComplete = computed(() => true)
const imagesComplete = computed(() => (form.value.photos || []).length > 0)

/* ── Image upload (multiple — first image is the cover) ──────── */
const imageUploading = ref(false)
const imageUploadError = ref('')
const imgDropActive = ref(false)
const imgFileInput = ref(null)

/** Compress an image File to under maxBytes using the Canvas API.
 *  Returns a Blob (image/jpeg). */
// Upload one or more images (compress → our own storage) and append to the gallery.
// The first image in photos[] is the cover; photo_url mirrors it for back-compat.
async function addListingImages(files) {
  const list = [...(files || [])].filter(f => f && f.type.startsWith('image/'))
  if (!list.length) {
    imageUploadError.value = 'Please select valid image files.'
    return
  }
  imageUploading.value = true
  imageUploadError.value = ''
  try {
    for (const f of list) {
      try {
        const { url, error } = await uploadImage(f, 'listings')
        if (error) throw new Error(error)
        form.value.photos = [...(form.value.photos || []), url]
      } catch (err) {
        imageUploadError.value = err.message || 'One image failed to upload.'
      }
    }
    form.value.photo_url = form.value.photos[0] || ''   // keep legacy cover field in sync
  } finally {
    imageUploading.value = false
  }
}

function removeListingImage(i) {
  form.value.photos = (form.value.photos || []).filter((_, idx) => idx !== i)
  form.value.photo_url = form.value.photos[0] || ''
}

const newListingImageUrl = ref('')
function addListingImageUrl() {
  const u = (newListingImageUrl.value || '').trim()
  if (!u) return
  form.value.photos = [...(form.value.photos || []), u]
  form.value.photo_url = form.value.photos[0] || ''
  newListingImageUrl.value = ''
}

function onImgDrop(e) {
  imgDropActive.value = false
  const files = e.dataTransfer?.files
  if (files && files.length) addListingImages(files)
}

// Add-on image upload → writes to newAddonForm.image_url
const addonImgUploading = ref(false)
const addonImgError = ref('')
const addonImgDropActive = ref(false)
const addonFileInput = ref(null)
async function handleAddonImageUpload(file) {
  if (!file || !file.type.startsWith('image/')) { addonImgError.value = 'Please select a valid image file.'; return }
  addonImgUploading.value = true; addonImgError.value = ''
  try {
    const { url, error } = await uploadImage(file, 'listings')
    if (error) throw new Error(error)
    newAddonForm.value.image_url = url
  }
  catch (err) { addonImgError.value = err.message || 'Upload failed. Try again.' }
  finally { addonImgUploading.value = false }
}
function onAddonImgDrop(e) { addonImgDropActive.value = false; const f = e.dataTransfer?.files?.[0]; if (f) handleAddonImageUpload(f) }
function onAddonImgFileChange(e) { const f = e.target.files?.[0]; if (f) handleAddonImageUpload(f); if (addonFileInput.value) addonFileInput.value.value = '' }

function onImgFileChange(e) {
  const files = e.target.files
  if (files && files.length) addListingImages(files)
  if (imgFileInput.value) imgFileInput.value.value = ''
}

/* addons/resources — repeatable dropdown rows (Add button → a new picker row) */
function addAddonRow() { connectedAddons.value.push({ addon_id: '', sort_order: connectedAddons.value.length }) }
function removeAddonRow(i) { connectedAddons.value.splice(i, 1) }
function addResourceRow() { connectedResources.value.push({ resource_id: '', quantity: 1 }) }
function removeResourceRow(i) { connectedResources.value.splice(i, 1) }
// An item already chosen in another row is disabled so it can't be picked twice.
function addonUsedElsewhere(id, i) { return !!id && connectedAddons.value.some((a, idx) => idx !== i && a.addon_id === id) }
function resourceUsedElsewhere(id, i) { return !!id && connectedResources.value.some((r, idx) => idx !== i && r.resource_id === id) }

// All add-ons / resources available at this listing's location (the dropdown options).
const locAddons = computed(() => allAddons.value)
const locResources = computed(() => { const loc = form.value.location_id; return allResources.value.filter(r => !r.location_id || r.location_id === loc) })

// PickerSelect option rows (already-chosen items disabled for the other rows).
function addonRowOptions(i) {
  const cur = currencyForLocation(form.value.location_id)
  return locAddons.value.map(a => ({ value: a.id, label: a.name, sub: a.category || '', right: a.price != null ? `${cur} ${a.price}` : '', disabled: addonUsedElsewhere(a.id, i) }))
}
function resourceRowOptions(i) {
  return locResources.value.map(r => ({ value: r.id, label: r.name, sub: r.type || '', disabled: resourceUsedElsewhere(r.id, i) }))
}
// "Create new" picked from inside a row's dropdown → remember the row so the new
// item fills THAT row instead of appending a duplicate.
const addonCreateRow = ref(null)
const resourceCreateRow = ref(null)
function createAddonForRow(i) { addonCreateRow.value = i; addonEditingId.value = null; resetAddonForm(form.value.location_id); newAddonModal.value = true }
function createResourceForRow(i) { resourceCreateRow.value = i; resourceEditingId.value = null; resetResourceForm(form.value.location_id); newResourceModal.value = true }
// Edit an existing add-on / resource straight from the dropdown's pencil button.
function editAddon(id) {
  const a = addonById(id); if (!a) return
  addonEditingId.value = id; addonCreateRow.value = null
  newAddonForm.value = {
    name: a.name || '', description: a.description || '', price: a.price ?? 0, status: a.status || 'active',
    image_url: a.image_url || '',
    location_id: a.location_id || '',
  }
  addonImgError.value = ''
  newAddonModal.value = true
}
function editResource(id) {
  const r = resourceById(id); if (!r) return
  resourceEditingId.value = id; resourceCreateRow.value = null
  newResourceForm.value = { name: r.name || '', type: r.type || '', quantity: r.quantity ?? 1, status: r.status || 'active', location_id: r.location_id || '' }
  newResourceModal.value = true
}
// Delete an add-on / resource from the dropdown (cascades off any listings it was on).
async function deleteAddon(id) {
  const a = addonById(id)
  if (!(await confirm({ title: 'Delete add-on?', message: `“${a?.name || 'This add-on'}” will be permanently removed and detached from any listings that use it. This cannot be undone.`, confirmText: 'Delete' }))) return
  const res = await apiDeleteAddOn(id)
  if (!res.success) { toast.error(listingFirstError(res, 'Could not delete add-on. It may be linked to existing bookings.')); return }
  allAddons.value = allAddons.value.filter(x => x.id !== id)
  connectedAddons.value = connectedAddons.value.filter(r => r.addon_id !== id && Number(r.addon_id) !== Number(id))
  toast.success('Add-on deleted')
}
async function deleteResource(id) {
  const r = resourceById(id)
  if (!(await confirm({ title: 'Delete resource?', message: `“${r?.name || 'This resource'}” will be permanently removed and detached from any listings that require it. This cannot be undone.`, confirmText: 'Delete' }))) return
  const res = await apiDeleteResource(id)
  if (!res.success) { toast.error(listingFirstError(res, 'Could not delete resource. It may be linked to existing bookings.')); return }
  allResources.value = allResources.value.filter(x => x.id !== id)
  connectedResources.value = connectedResources.value.filter(row => row.resource_id !== id && Number(row.resource_id) !== Number(id))
  toast.success('Resource deleted')
}

// Only show items not already connected (Laravel catalog is not location-scoped).
const availableAddons = computed(() => {
  const used = new Set(connectedAddons.value.map(a => String(a.addon_id)))
  return allAddons.value.filter(a => !used.has(String(a.id)))
})
const availableResources = computed(() => {
  const used = new Set(connectedResources.value.map(r => String(r.resource_id)))
  return allResources.value.filter(r => !used.has(String(r.id)))
})

// Rich option rows for the ConnectPicker dropdowns.
const addonOptions = computed(() => {
  const cur = currencyForLocation(form.value.location_id)
  return availableAddons.value.map(a => {
    let badge = '', badgeClass = ''
    if (a.stock === 0)                       { badge = 'Out of stock'; badgeClass = 'danger' }
    else if (a.stock != null && a.stock <= 5){ badge = `${a.stock} left`; badgeClass = 'warn' }
    return { id: a.id, name: a.name, sub: a.description || a.category || '', right: `${cur} ${a.price}`, badge, badgeClass }
  })
})
const resourceOptions = computed(() =>
  availableResources.value.map(r => ({
    id: r.id,
    name: r.name,
    sub: r.type ? r.type.charAt(0).toUpperCase() + r.type.slice(1) : '',
    right: r.quantity != null ? `${r.quantity} available` : '',
    badge: r.quantity === 0 ? 'None available' : '',
    badgeClass: r.quantity === 0 ? 'danger' : '',
  }))
)

// After a failed validation: bring the user TO the first red-flagged field —
// the expanded section may sit far off-screen on the long editor.
async function scrollToInvalid() {
  await nextTick()
  const el = document.querySelector('.lf-drawer .input-invalid, .lf-drawer .pay-opt.invalid')
  if (el) { el.scrollIntoView({ behavior: 'smooth', block: 'center' }); try { el.focus({ preventScroll: true }) } catch { /* non-focusable */ } }
}

// Post-submit modal: what just happened to the listing, per placement
// (live on the provider's own site; marketplace goes through review).
const submittedInfo = ref(null)

async function save() {
  const wasEdit = editingExisting.value
  // Required basic fields
  listingTriedSave.value = true
  if (!basicComplete.value) {
    listingSaveError.value = 'Please complete Basic Information (name, location, type and description).'
    openSection.value = 'basic'
    toast.error('Please complete the required fields.')
    scrollToInvalid()
    return
  }
  if (!(Number(form.value.base_price) > 0)) {
    listingSaveError.value = 'Please set a base price greater than 0 in the Pricing section.'
    openSection.value = 'pricing'
    toast.error('Please set a base price.')
    return
  }
  // New listing leaving pricing without dynamic pricing → make the revenue case,
  // exactly ONCE per creation (every wizard card runs this same save(), so an
  // unflagged prompt would re-nag on each step). Runs right after the base-price
  // check so it appears on the Pricing card's own "Save & Continue", before any
  // payment-section validation can bounce the user elsewhere. Edits never nag.
  if (!editingExisting.value && !dynamicPricing.value && !dynamicNudgeAsked.value) {
    dynamicNudgeAsked.value = true
    const enable = await confirm({
      danger: false,
      title: 'Launch without dynamic pricing?',
      message: 'Facilities that price by demand earn up to 40% more on Bookboll — a higher price for peak evening and weekend slots that always sell out, a lower price to fill quiet daytime hours. It takes a minute to paint your prices onto the weekly grid, and you can change them anytime.',
      confirmText: 'Enable & set it up',
      cancelText: 'Skip for now',
    })
    if (enable) {
      dynamicPricing.value = true
      openSection.value = 'pricing'
      return   // back to the form — pricing section open, grid ready to paint
    }
  }
  if (payError.value) {
    listingSaveError.value = payError.value
    openSection.value = 'placement'
    if (siteNoMethod.value || siteDepositPctInvalid.value) payOpen.value.site = true
    else payOpen.value.mkt = true
    toast.error(payError.value)
    return
  }
  // Both places off → the listing is invisible to customers. Prompt: pick a place,
  // or knowingly save it as hidden (a legitimate draft state).
  if (!form.value.show_on_marketplace && !form.value.show_on_provider_site) {
    openSection.value = 'placement'
    const saveHidden = await askConfirm({
      danger: false,
      title: 'Customers can’t book this yet',
      message: 'Both places are still off, so this listing won’t appear on the Bookboll Marketplace or on your website. Turn one on in "Placement & Payment", or save it as a hidden listing for now.',
      confirmText: 'Save as hidden',
      cancelText: 'Choose where',
    })
    if (!saveHidden) return
  }
  // Block if the listing's city has the Listings module disabled
  const listingLoc  = locations.value.find(l => l.id === form.value.location_id)
  const listingCity = cities.value.find(c => c.id === listingLoc?.city_id)
  if (listingCity && listingCity.module_listings === false) {
    listingSaveError.value = `Booking listings are disabled for ${listingCity.name}. A Super Admin must enable the "Listings" module for this city in Countries & Cities before listings can be created here.`
    return
  }
  // Block if any custom listing hours fall outside the location's operating hours
  if (listingHoursOutOfBounds.value.length) {
    listingSaveError.value = `This listing's hours fall outside the location's schedule (${listingHoursOutOfBounds.value[0]}). Adjust the listing hours, change the location schedule, or use a Special Day for one-off dates.`
    openSection.value = 'schedule'
    return
  }
  listingSaveError.value = ''

  // CONTENT that could be inappropriate (title, description, photos) is the only thing
  // that needs Bookboll approval. Everything else is OPERATIONAL and goes live instantly.
  const contentFields = {
    name: form.value.name,
    description: form.value.description,
    photo_url: (form.value.photos && form.value.photos[0]) || form.value.photo_url || '',
  }
  const contentSnapshot = { ...contentFields, photos: form.value.photos || [] }   // staged in pending_content
  const opPayload = {
    location_id: Number(form.value.location_id),
    sport_category: form.value.sport_category,
    base_price: Number(form.value.base_price),
    slot_duration: Number(form.value.slot_duration),
    buffer_time: Number(form.value.buffer_time) || 0,
    // 'fixed' only when the provider has deselected some slots; otherwise 'auto'.
    start_time_mode: customStartTimes.value.length ? 'fixed' : 'auto',
    fixed_start_times: fixedStartTimes.value,
    capacity: Number(form.value.capacity),
    // `status` is derived server-side from the two flags below; it is still sent
    // so anything reading it keeps working.
    status: form.value.status,
    show_on_admin: form.value.show_on_admin !== false,
    show_on_customer: form.value.show_on_customer !== false,
    loyalty_points: Number(form.value.loyalty_points) || 0,
    booking_color: form.value.booking_color,
    listing_type: 'per_space',   // all listings are per-space; per-person is handled via Events now
    max_bookings_per_slot: Number(form.value.max_bookings_per_slot) || 1,
    commission_rate: (form.value.commission_rate !== null && form.value.commission_rate !== '') ? Number(form.value.commission_rate) : null,
  }

  // stageMode: 'full' = whole listing under first/re-review · 'content' = live stays,
  // new content staged · 'operational' = live now, nothing to review · 'admin' = admin edit.
  let stageMode = 'admin'
  let liveContent = { ...contentFields, photos: form.value.photos || [] }
  let previousSnapshot = null
  let approvalStatus = form.value.approval_status || 'approved'

  if (editing.value) {
    if (userRole.value === 'provider') {
      const liveApproved = {
        name: editing.value.name || '', description: editing.value.description || '',
        photo_url: editing.value.photo_url || '',
        photos: Array.isArray(editing.value.photos) ? editing.value.photos : (editing.value.photo_url ? [editing.value.photo_url] : []),
      }
      const paused = (editing.value.eligibility_status || 'eligible') === 'paused'
      const notLiveYet = (editing.value.approval_status || 'approved') !== 'approved' || paused
      if (notLiveYet) {
        const snap = { ...editing.value }
        delete snap.previous_snapshot; delete snap.pending_content
        previousSnapshot = snap
        approvalStatus = 'pending'
        liveContent = { ...contentFields, photos: form.value.photos || [] }
        stageMode = 'full'
      } else if (contentChanged(contentSnapshot, liveApproved)) {
        // Keep approved content serving live; stage the new content for review.
        liveContent = {
          name: liveApproved.name,
          description: liveApproved.description,
          photo_url: liveApproved.photo_url,
          photos: liveApproved.photos || [],
        }
        approvalStatus = editing.value.approval_status || 'approved'
        stageMode = 'content'
      } else {
        liveContent = { ...contentFields, photos: form.value.photos || [] }
        approvalStatus = editing.value.approval_status || 'approved'
        stageMode = 'operational'
      }
    } else {
      liveContent = { ...contentFields, photos: form.value.photos || [] }
      approvalStatus = form.value.approval_status
      stageMode = 'admin'
    }
  } else {
    approvalStatus = userRole.value === 'admin' ? 'approved' : 'pending'
    liveContent = { ...contentFields, photos: form.value.photos || [] }
    stageMode = userRole.value === 'admin' ? 'admin' : 'full'
  }

  const depPct = Number(effectiveCommissionRate.value) || 20
  const body = {
    ...opPayload,
    ...liveContent,
    category: form.value.category || 'sports',
    approval_status: approvalStatus,
    pending_content: stageMode === 'content' ? contentSnapshot : null,
    show_on_marketplace: form.value.show_on_marketplace !== false,
    show_on_provider_site: form.value.show_on_provider_site !== false,
    extension_pricing: form.value.extension_pricing || 'none',
    extension_value: Number(form.value.extension_value) || 0,
    extension_max_slots: Math.max(1, Number(form.value.extension_max_slots) || 1),
    hours: hours.value,
    pricing_groups: groups.value,
    pricing_cells: cells.value,
    payment: {
      cash_on_arrival: !!pay.value.cash_on_arrival,
      deposit_enabled: !!pay.value.deposit_enabled,
      deposit_amount: 0,
      deposit_percentage: depPct,
      online_payment_enabled: !!pay.value.online_payment_enabled,
      site_deposit_enabled: !!pay.value.site_deposit_enabled,
      site_deposit_percentage: Math.max(10, Math.min(100, Number(pay.value.site_deposit_percentage) || 0)),
      site_online_enabled: !!pay.value.site_online_enabled && onlineAllowed.value,
      site_cash_enabled: !!pay.value.site_cash_enabled,
    },
    addons: connectedAddons.value
      .filter(a => a.addon_id)
      .map((a, i) => ({ addon_id: Number(a.addon_id), sort_order: i })),
    resources: connectedResources.value
      .filter(r => r.resource_id)
      .map(r => ({ resource_id: Number(r.resource_id), quantity: Number(r.quantity) || 1 })),
  }
  if (stageMode === 'full' && previousSnapshot) body.previous_snapshot = previousSnapshot

  if (userRole.value === 'provider' && (stageMode === 'full' || stageMode === 'content')) {
    body.submitted_at = new Date().toISOString()
    body.submitted_by_name = currentUser.value?.full_name || currentUser.value?.business_name || currentUser.value?.name || ''
  }

  const res = editing.value
    ? await updateListing(editing.value.id, body)
    : await createListing(body)

  if (!res.success) {
    listingSaveError.value = listingFirstError(res, 'Could not save listing.')
    toast.error(listingSaveError.value)
    return
  }

  const savedListing = res.data?.listing
  if (savedListing) editing.value = { ...editing.value, ...savedListing }

  if (userRole.value === 'provider' && (stageMode === 'full' || stageMode === 'content')) {
    logAudit({
      action: 'submitted', entity_type: 'listing', entity_id: savedListing?.id || editing.value?.id,
      details: stageMode === 'content'
        ? `Submitted updated content (title/description/photos) for “${form.value.name}” for review`
        : `${wasEdit ? 'Resubmitted' : 'Submitted'} listing “${form.value.name}” for review`,
    })
  }

  savedFlag.value = true
  setTimeout(() => (savedFlag.value = false), 2000)
  notifySetupChanged()   // moves the setup guide past "Add your first listing"
  clearDirty()
  clearFormDraft(listingDraftKey())   // a real save supersedes any local draft
  // Tell the provider exactly what happened. Operational-only edits are fully live —
  // no review, just a quiet confirmation. Content edits stay live on the approved
  // version while the new one is reviewed. First-time/paused go through full review.
  if (userRole.value === 'provider') {
    if (stageMode === 'operational') {
      toast.success('Saved — your changes are live')
    } else {
      submittedInfo.value = {
        name: form.value.name,
        wasEdit,
        mode: stageMode,   // 'content' = live stays, new content under review · 'full' = whole listing under review
        onSite: form.value.show_on_provider_site !== false,
        onMarketplace: form.value.show_on_marketplace !== false,
      }
    }
  } else {
    toast.success(wasEdit ? 'Listing saved' : 'Listing created')
  }
  await loadList()
}

/* NEW-LISTING WIZARD: each card's "Save & Continue" validates ONLY the card
   being left and advances — no DB write yet. The listing is created once, by
   "Create Listing" on the last card, which runs the full save() validation.
   (Previously every card ran the full save(), whose first failing check
   jumped the user straight from Basic to Pricing, skipping Schedule and
   Timeslot Settings entirely.) */
function sectionGateError(sec) {
  if (sec === 'basic' && !basicComplete.value) return 'Please complete Basic Information (name, location, type and description).'
  if (sec === 'schedule') {
    if (!scheduleComplete.value) return 'Open at least one day in the Weekly Schedule.'
    if (listingHoursOutOfBounds.value.length) return `This listing's hours fall outside the location's schedule (${listingHoursOutOfBounds.value[0]}). Adjust the hours or the location schedule.`
  }
  if (sec === 'timeslot' && !(Number(form.value.slot_duration) > 0)) return 'Choose a slot duration in Timeslot Settings.'
  if (sec === 'pricing' && !(Number(form.value.base_price) > 0)) return 'Please set a base price greater than 0.'
  if (sec === 'placement' && payError.value) {
    // Expand the sub-panel the problem lives in so the red state is visible.
    if (siteNoMethod.value || siteDepositPctInvalid.value) payOpen.value.site = true
    else payOpen.value.mkt = true
    return payError.value
  }
  return ''
}
async function saveAndAdvance(next) {
  listingTriedSave.value = true
  const err = sectionGateError(openSection.value)
  if (err) { listingSaveError.value = err; toast.error(err); scrollToInvalid(); return }
  listingSaveError.value = ''
  // Leaving the timeslot card without session extensions → one-time revenue nudge.
  // (The same toggle also appears on the Add-ons card, but it's asked about once.)
  if (openSection.value === 'timeslot' && !extensionEnabled.value && !extensionNudgeAsked.value) {
    extensionNudgeAsked.value = true
    const enable = await confirm({
      danger: false,
      title: 'Launch without session extensions?',
      message: 'Providers who let customers extend into the next free slot at a small discount earn up to 35% more on Bookboll — adjacent slots often sit empty anyway, and a discounted extension turns them into paid time. You choose the discount and how many extra slots it covers.',
      confirmText: 'Enable & set it up',
      cancelText: 'Skip for now',
    })
    if (enable) { toggleExtension(true); return }   // stay on the card, options revealed
  }
  // Leaving the pricing card without dynamic pricing → the one-time revenue nudge.
  if (openSection.value === 'pricing' && !dynamicPricing.value && !dynamicNudgeAsked.value) {
    dynamicNudgeAsked.value = true
    const enable = await confirm({
      danger: false,
      title: 'Launch without dynamic pricing?',
      message: 'Facilities that price by demand earn up to 40% more on Bookboll — a higher price for peak evening and weekend slots that always sell out, a lower price to fill quiet daytime hours. It takes a minute to paint your prices onto the weekly grid, and you can change them anytime.',
      confirmText: 'Enable & set it up',
      cancelText: 'Skip for now',
    })
    if (enable) { dynamicPricing.value = true; return }   // stay on the pricing card
  }
  if (next) openSection.value = next
}
// Final card action: save, then close the editor only if it actually saved.
async function saveAndClose() {
  savedFlag.value = false
  await save()
  if (savedFlag.value) view.value = 'list'
}
// Per-card primary action. New listing → save & advance to the next card (wizard).
// Editing an existing listing → save & close (no need to step through every card).
function saveSection(next) { return editingExisting.value ? saveAndClose() : saveAndAdvance(next) }

async function removeListing(id) {
  const l = listings.value.find(x => x.id === id)
  if (!(await confirm({
    title: 'Delete listing?',
    message: `“${l?.name || 'This listing'}” will be permanently removed. This cannot be undone.`,
    confirmText: 'Delete',
  }))) return
  const res = await deleteListing(id)
  if (!res.success) {
    toast.error(listingFirstError(res, 'Could not delete listing. It may have bookings linked to it.'))
    return
  }
  toast.success('Listing deleted')
  loadList()
}

/* inline add-on / resource creation + editing.
   Catalog lives on Laravel (/admin/add-ons, /admin/resources). Location on the form
   is optional UX context only — Laravel add-ons/resources are not location-scoped. */
const newAddonModal = ref(false)
const addonEditingId = ref(null)
const newAddonForm = ref({ name: '', description: '', price: 0, status: 'active', image_url: '', location_id: '' })
function resetAddonForm(locId = '') {
  newAddonForm.value = { name: '', description: '', price: 0, status: 'active', image_url: '', location_id: locId || '' }
  addonImgError.value = ''
}
async function saveNewAddon() {
  if (!newAddonForm.value.name?.trim()) { toast.error('Add-on name is required.'); return }
  const payload = {
    name: newAddonForm.value.name.trim(),
    description: newAddonForm.value.description || '',
    price: Number(newAddonForm.value.price) || 0,
    status: newAddonForm.value.status || 'active',
    image: newAddonForm.value.image_url || null,
  }
  let res
  if (addonEditingId.value) {
    res = await updateAddOn(addonEditingId.value, payload)
  } else {
    res = await createAddOn(payload)
  }
  if (!res.success) {
    toast.error(listingFirstError(res, 'Could not save add-on.'))
    return
  }
  const mapped = mapAddOnRow(res.data?.addOn || res.data?.addon)
  if (mapped) {
    const idx = allAddons.value.findIndex(a => a.id === mapped.id || String(a.id) === String(mapped.id))
    if (idx !== -1) allAddons.value[idx] = mapped
    else allAddons.value.unshift(mapped)
    if (!addonEditingId.value) {
      const i = addonCreateRow.value
      if (i != null && connectedAddons.value[i]) connectedAddons.value[i].addon_id = mapped.id
      else connectedAddons.value.push({ addon_id: mapped.id, sort_order: connectedAddons.value.length })
    }
  }
  addonCreateRow.value = null
  addonEditingId.value = null
  newAddonModal.value = false
  resetAddonForm()
}

const newResourceModal = ref(false)
const resourceEditingId = ref(null)
// `type` holds the optional free-text Unit Type (e.g. "Court"); maps to Laravel unit_type.
const newResourceForm = ref({ name: '', type: '', quantity: 1, status: 'active', location_id: '' })
function resetResourceForm(locId = '') {
  newResourceForm.value = { name: '', type: '', quantity: 1, status: 'active', location_id: locId || '' }
}
async function saveNewResource() {
  if (!newResourceForm.value.name?.trim()) { toast.error('Resource name is required.'); return }
  const payload = {
    name: newResourceForm.value.name.trim(),
    unit_type: newResourceForm.value.type?.trim() || null,
    total_quantity: Math.max(1, Number(newResourceForm.value.quantity) || 1),
    status: newResourceForm.value.status || 'active',
  }
  let res
  if (resourceEditingId.value) {
    res = await updateResource(resourceEditingId.value, payload)
  } else {
    res = await createResource(payload)
  }
  if (!res.success) {
    toast.error(listingFirstError(res, 'Could not save resource.'))
    return
  }
  const mapped = mapResourceRow(res.data?.resource)
  if (mapped) {
    const idx = allResources.value.findIndex(r => r.id === mapped.id || String(r.id) === String(mapped.id))
    if (idx !== -1) allResources.value[idx] = mapped
    else allResources.value.unshift(mapped)
    if (!resourceEditingId.value) {
      const i = resourceCreateRow.value
      if (i != null && connectedResources.value[i]) connectedResources.value[i].resource_id = mapped.id
      else connectedResources.value.push({ resource_id: mapped.id, quantity: 1 })
    }
  }
  resourceCreateRow.value = null
  resourceEditingId.value = null
  newResourceModal.value = false
  resetResourceForm()
}

const addonById = (id) => allAddons.value.find(a => a.id === id || String(a.id) === String(id))
const resourceById = (id) => allResources.value.find(a => a.id === id || String(a.id) === String(id))

/* ---- layout toggle ---- */
const layout = ref('grouped') // 'grouped' | 'flat'

// ── Storefront sort (how the customer site orders listings when location is OFF) ──
// The customer site shows NEAREST FIRST when the visitor shares their location; this is
// the fallback order otherwise. Super admin sets it for the Bookboll marketplace
// (platform_settings); a provider sets it for their own white-label site (their row).
// One choice per view: "All Listings"/flat → space, "Grouped"/by-venue → venue.
const sortOptions = SORT_OPTIONS
const storefrontSort = ref({ space: 'recommended', venue: 'recommended' })
async function loadStorefrontSort() {
  storefrontSort.value = await fetchStorefrontSort()
}
onMounted(loadStorefrontSort)
// The control targets the view currently shown: flat → space, grouped → venue.
const storefrontSortView = computed(() => layout.value === 'flat' ? 'space' : 'venue')
const storefrontSortModel = computed({
  get: () => storefrontSort.value[storefrontSortView.value],
  set: (v) => saveStorefrontSort(storefrontSortView.value, v),
})
async function saveStorefrontSort(view, value) {
  storefrontSort.value = { ...storefrontSort.value, [view]: value }
  const res = await saveStorefrontSortValue(view, value)
  if (!res.success) { toast.error(res.message || 'Could not save the order — try again.'); return }
  if (res.data?.sort) storefrontSort.value = res.data.sort
  toast.success('Customer site order updated')
}

// ── Extra flat-view filters ───────────────────────────────────────────────────
const flatFilterStatus = ref('')   // '' | 'live' | 'review' | 'off_market' (Bookboll placement)
// Who may book: '' | 'both' | 'admin_only' | 'customer_only' | 'inactive'.
// A separate axis from placement — placement is WHERE it is shown, this is WHO
// may book it. The server filters on it (ListingDashboardService::visibility).
const filterVisibility = ref('')

const VISIBILITY_BADGE = {
  both:          { label: 'Everyone',      cls: 'vis-both',     tip: 'Bookable by customers and by staff.' },
  customer_only: { label: 'Customers',     cls: 'vis-customer', tip: 'Bookable by customers only — hidden from the admin booking screen.' },
  admin_only:    { label: 'Staff only',    cls: 'vis-admin',    tip: 'Bookable by staff only — customers cannot see or book it.' },
  inactive:      { label: 'Nobody',        cls: 'vis-none',     tip: 'Hidden from both customers and staff — the listing is inactive.' },
}
/** The row's visibility, derived when the API predates the flags. */
function visibilityOf(l) {
  if (l.visibility) return l.visibility
  const a = l.show_on_admin !== false
  const c = l.show_on_customer !== false
  return a && c ? 'both' : (a ? 'admin_only' : (c ? 'customer_only' : 'inactive'))
}
const visibilityBadge = (l) => VISIBILITY_BADGE[visibilityOf(l)] || VISIBILITY_BADGE.both
const flatSortKey      = ref('sort_order') // 'sort_order' | 'name' | 'sport_category' | 'base_price'
const flatSortDir      = ref('asc')
function toggleFlatSort(key) {
  if (flatSortKey.value === key) flatSortDir.value = flatSortDir.value === 'asc' ? 'desc' : 'asc'
  else { flatSortKey.value = key; flatSortDir.value = 'asc' }
}

// The server applies every filter and the sort, and returns one page. Filtering
// again here could only hide rows it deliberately sent — and did, because
// `locations` is now a single page of venues, so the venue lookup missed.
const flatVenues = computed(() => listings.value)

// Both views are paged by the SERVER now. `paginatedVenues` keeps its name (the
// flat table binds to it) but is simply the page of listings the server returned;
// `flatVenues` was always listings despite the name.
const paginatedVenues = computed(() => flatVenues.value)
const venueTotalPages = computed(() => Math.max(1, flatLastPage.value))
const venueVisiblePages = computed(() => pageWindow(flatPage.value, venueTotalPages.value))
function venueGoTo(p) {
  const next = Math.min(Math.max(1, p), venueTotalPages.value)
  if (next === flatPage.value) return
  flatPage.value = next
  loadList()
}

// Grouped view: paging over venue cards.
const groupedTotalPages = computed(() => Math.max(1, venueLastPage.value))
const groupedVisiblePages = computed(() => pageWindow(venuePage.value, groupedTotalPages.value))
function groupedGoTo(p) {
  const next = Math.min(Math.max(1, p), groupedTotalPages.value)
  if (next === venuePage.value) return
  venuePage.value = next
  loadList()
}

/** A five-page window centred on the current page, shared by both footers. */
function pageWindow(current, totalP) {
  const out = []
  const from = Math.max(1, Math.min(current - 2, totalP - 4))
  for (let i = from; i <= Math.min(totalP, from + 4); i++) out.push(i)
  return out
}

// Any filter, sort, view or page-size change restarts at page 1 and refetches.
// Search is debounced so typing does not fire a request per keystroke.
let listSearchTimer = null
watch(search, () => {
  clearTimeout(listSearchTimer)
  listSearchTimer = setTimeout(() => { flatPage.value = 1; venuePage.value = 1; loadList() }, 300)
})
watch([filterCountry, filterCity, filterLocation, filterCategory, filterType, flatFilterStatus,
       filterVisibility, flatSortKey, flatSortDir, flatPerPage, venuePerPage, layout], () => {
  flatPage.value = 1; venuePage.value = 1; loadList()
})

// Export for listings
const canExport = computed(() => can(CAP.EXPORT_CATALOG))
const { isPremium, requirePremium } = usePremium()   // exports are a Premium feature for providers
const showExportModal = ref(false)
// Export covers EVERY matching listing, not the page on screen — so it asks the
// server for them rather than exporting whatever the table happens to hold.
const exportingList = ref(false)
async function doExport(useFilters) {
  if (exportingList.value) return
  exportingList.value = true
  const params = useFilters ? { ...catalogParams(), export: 1 } : { export: 1 }
  const res = await fetchListingsDashboard(params)
  exportingList.value = false
  if (!res.success) { toast.error(res.message || 'Could not prepare the export.'); return }
  const rows = res.data?.listings || []
  if (res.data?.truncated) {
    toast.error(`Export capped at ${rows.length.toLocaleString()} rows — narrow the filters for the rest.`)
  }
  downloadCSV(rows, [
    { label: 'ID',            key: 'id' },
    { label: 'Name',          key: 'name' },
    { label: 'Sport',         key: 'sport_category' },
    { label: 'Location',      fn: r => r.location_name || '' },
    { label: 'Base Price/slot', key: 'base_price' },
    { label: 'Duration (min)',key: 'slot_duration' },
    { label: 'Capacity',      key: 'max_players' },
    { label: 'Status',        key: 'status' },
    { label: 'Approval',      key: 'approval_status' },
  ], 'listings')
  showExportModal.value = false
}

const dragFlatId = ref(null)
const dragOverFlatId = ref(null)

function onFlatDragStart(e, id) {
  dragFlatId.value = id
  e.dataTransfer.effectAllowed = 'move'
}
function onFlatDragOver(e, id) {
  e.preventDefault()
  dragOverFlatId.value = id
}
function onFlatDragLeave() { dragOverFlatId.value = null }
async function onFlatDrop(e, targetId) {
  e.preventDefault()
  dragOverFlatId.value = null
  if (!dragFlatId.value || dragFlatId.value === targetId) { dragFlatId.value = null; return }
  const arr = [...listings.value]
  const fromIdx = arr.findIndex(l => l.id === dragFlatId.value)
  const toIdx = arr.findIndex(l => l.id === targetId)
  if (fromIdx < 0 || toIdx < 0) { dragFlatId.value = null; return }
  arr.splice(toIdx, 0, arr.splice(fromIdx, 1)[0])
  listings.value = arr
  dragFlatId.value = null
  // Rows are numbered from their offset in the full list — restarting at 0 on
  // page 2 would renumber it over the top of page 1.
  const offset = (flatPage.value - 1) * flatPerPage.value
  await sortListings(arr.map((l, i) => ({ id: Number(l.id), sort_order: offset + i })))
}
function onFlatDragEnd() { dragFlatId.value = null; dragOverFlatId.value = null }

/* ---- drag-to-reorder ---- */
const dragLocId = ref(null)
const dragOverLocId = ref(null)
const dragListingId = ref(null)
const dragOverListingId = ref(null)
const dragListingLocId = ref(null) // which location the dragged listing belongs to

function onLocDragStart(e, locId) {
  dragLocId.value = locId
  e.dataTransfer.effectAllowed = 'move'
}
function onLocDragOver(e, locId) {
  e.preventDefault()
  dragOverLocId.value = locId
}
function onLocDragLeave() { dragOverLocId.value = null }
async function onLocDrop(e, targetId) {
  e.preventDefault()
  dragOverLocId.value = null
  if (!dragLocId.value || dragLocId.value === targetId) { dragLocId.value = null; return }
  // The cards on screen are `venueCards` (one page), not the full `locations`
  // lookup — reordering the lookup would move nothing.
  const arr = [...venueCards.value]
  const fromIdx = arr.findIndex(l => l.id === dragLocId.value)
  const toIdx = arr.findIndex(l => l.id === targetId)
  if (fromIdx < 0 || toIdx < 0) { dragLocId.value = null; return }
  arr.splice(toIdx, 0, arr.splice(fromIdx, 1)[0])
  venueCards.value = arr
  dragLocId.value = null
  // Numbered from this page's offset, so page 2 does not renumber over page 1.
  const offset = (venuePage.value - 1) * venuePerPage.value
  await sortVenues(arr.map((l, i) => ({ id: Number(l.id), sort_order: offset + i })))
}
function onLocDragEnd() { dragLocId.value = null; dragOverLocId.value = null }

function onListingDragStart(e, listingId, locId) {
  dragListingId.value = listingId
  dragListingLocId.value = locId
  e.dataTransfer.effectAllowed = 'move'
}
function onListingDragOver(e, listingId) {
  e.preventDefault()
  dragOverListingId.value = listingId
}
function onListingDragLeave() { dragOverListingId.value = null }
async function onListingDrop(e, targetId) {
  e.preventDefault()
  dragOverListingId.value = null
  if (!dragListingId.value || dragListingId.value === targetId) { dragListingId.value = null; return }
  const locId = dragListingLocId.value
  const arr = listings.value.filter(l => l.location_id === locId)
  const others = listings.value.filter(l => l.location_id !== locId)
  const fromIdx = arr.findIndex(l => l.id === dragListingId.value)
  const toIdx = arr.findIndex(l => l.id === targetId)
  if (fromIdx < 0 || toIdx < 0) { dragListingId.value = null; return }
  arr.splice(toIdx, 0, arr.splice(fromIdx, 1)[0])
  listings.value = [...others, ...arr]
  dragListingId.value = null
  await sortListings(arr.map((l, i) => ({ id: Number(l.id), sort_order: i })))
}
function onListingDragEnd() { dragListingId.value = null; dragOverListingId.value = null }

/* ---- touch reordering: HTML5 drag events never fire on touch screens, so the
   grip handles route touch gestures through the same handlers (see touchReorder.js).
   Rows carry a matching data-tr-* attribute for the finger-position hit test. ---- */
const locTR = createTouchReorder({ attr: 'data-tr-loc', start: onLocDragStart, over: onLocDragOver, leave: onLocDragLeave, drop: onLocDrop, end: onLocDragEnd })
const listingTR = createTouchReorder({ attr: 'data-tr-listing', start: onListingDragStart, over: onListingDragOver, leave: onListingDragLeave, drop: onListingDrop, end: onListingDragEnd })
const flatTR = createTouchReorder({ attr: 'data-tr-flat', start: onFlatDragStart, over: onFlatDragOver, leave: onFlatDragLeave, drop: onFlatDrop, end: onFlatDragEnd })
</script>

<template>
  <div class="page">
    <!-- LIST VIEW (kept mounted behind the editor drawer) -->
    <template v-if="view === 'list' || view === 'editor'">
      <div class="page-header">
        <div>
          <div class="page-title">Listings</div>
          <div class="page-subtitle">Manage your locations and venue listings</div>
        </div>
        <div style="display:flex; gap:8px; align-items:center;">
          <AddMenu v-if="pageAddItems.length" label="Add" variant="primary" align="right" :items="pageAddItems" @select="onPageAdd" />
        </div>
      </div>

      <div class="toolbar lf-bar">
        <div class="lf-search-wrap">
          <div class="search lf-search">
            <Search :size="16" />
            <input v-model="search" placeholder="Search listings, sports, locations…" />
            <button type="button" class="lf-filter-btn" :class="{ on: showFilters || activeFilterCount }" @click.stop="showFilters = !showFilters" title="Filters">
              <SlidersHorizontal :size="16" />
              <span v-if="activeFilterCount" class="lf-filter-n">{{ activeFilterCount }}</span>
            </button>
          </div>

          <!-- All filters live in this dropdown -->
          <div v-if="showFilters" class="lf-panel" @click.stop>
            <div class="lf-selects">
              <div class="lf-field">
                <label>Country</label>
                <MultiSelectDropdown :model-value="filterCountry" :options="countryOptions" all-label="All countries"
                  @update:model-value="setFilterCountries" />
              </div>
              <div class="lf-field">
                <label>City</label>
                <MultiSelectDropdown :model-value="filterCity" :options="cityOptions" all-label="All cities"
                  @update:model-value="v => filterCity = v" />
              </div>
              <div class="lf-field">
                <label>Location</label>
                <select class="filter-select" v-model="filterLocation">
                  <option value="">All locations</option>
                  <option v-for="l in locationsForFilter" :key="l.id" :value="l.id">{{ l.name }}</option>
                </select>
              </div>
              <div class="lf-field">
                <label>Placement</label>
                <select class="filter-select" v-model="flatFilterStatus">
                  <option value="">All placements</option>
                  <option value="live">Live on Bookboll</option>
                  <option value="review">Under review / pending</option>
                  <option value="off_market">Off marketplace</option>
                </select>
              </div>
              <div class="lf-field">
                <label>Bookable by</label>
                <select class="filter-select" v-model="filterVisibility">
                  <option value="">Anyone</option>
                  <option value="both">Customers &amp; staff</option>
                  <option value="customer_only">Customers only</option>
                  <option value="admin_only">Staff only</option>
                  <option value="inactive">Nobody (inactive)</option>
                </select>
              </div>
            </div>
            <div class="lf-row">
              <span class="lf-label">Category</span>
              <div class="lf-chips">
                <button type="button" :class="{ on: filterCategory === 'all' }" @click="setFilterCategory('all')">All</button>
                <button type="button" v-for="c in VENUE_CATEGORIES" :key="c.key" :class="{ on: filterCategory === c.key }" @click="setFilterCategory(c.key)">{{ c.label }}</button>
              </div>
            </div>
            <div class="lf-row">
              <span class="lf-label">Sport</span>
              <div v-if="filterCategory !== 'all' && typeOptionsForFilter.length" class="lf-chips">
                <button type="button" :class="{ on: filterType === 'all' }" @click="filterType = 'all'">All</button>
                <button type="button" v-for="t in typeOptionsForFilter" :key="t" :class="{ on: filterType === t }" @click="filterType = t">{{ t }}</button>
              </div>
              <span v-else class="lf-type-hint">{{ filterCategory === 'all' ? 'Select a category first' : 'No listings in this category yet.' }}</span>
            </div>
            <div class="lf-panel-foot">
              <button v-if="activeFilterCount" type="button" class="lf-clear" @click="clearListingFilters">Clear all</button>
              <span v-else></span>
              <button type="button" class="btn btn-primary btn-sm" @click="showFilters = false">Done</button>
            </div>
          </div>
        </div>

        <div class="lf-spacer"></div>

        <button v-if="canExport" class="btn btn-secondary btn-sm" @click="requirePremium('exports') && (showExportModal = true)">
          <Download :size="14" />Export<span v-if="!isPremium" class="prem-lock">Premium</span>
        </button>
        <div v-if="isSuperAdmin || currentOwnerUserId" class="sf-sort" :title="`How the customer site orders the ${storefrontSortView === 'space' ? 'by-space' : 'by-venue'} view when a visitor's location is unavailable (otherwise it's nearest-first)`">
          <span class="sf-sort-lbl">Customer site order</span>
          <select v-model="storefrontSortModel" class="sf-sort-sel">
            <option v-for="o in sortOptions" :key="o.key" :value="o.key">{{ o.label }}</option>
          </select>
        </div>
        <div class="layout-toggle">
          <button :class="['layout-btn', { active: layout === 'grouped' }]" @click="layout='grouped'" title="Grouped by location">
            <Layers :size="16" /><span>Grouped</span>
          </button>
          <button :class="['layout-btn', { active: layout === 'flat' }]" @click="layout='flat'" title="All listings flat">
            <LayoutList :size="16" /><span>All Listings</span>
          </button>
        </div>
      </div>

      <!-- FLAT LIST VIEW -->
      <template v-if="layout === 'flat'">
        <!-- VENUE LISTINGS TABLE -->
        <div class="flat-section">
          <div class="flat-section-head"><Boxes :size="15" /><span>Venue Listings</span><span class="flat-section-count">{{ flatTotal }}</span></div>
          <div class="flat-list">
            <div class="flat-header">
              <span class="flat-col-grip"></span>
              <span class="flat-col-color"></span>
              <span class="flat-col-name flat-th-sort" @click="toggleFlatSort('name')">Listing <ChevronUp v-if="flatSortKey==='name'&&flatSortDir==='asc'" :size="11"/><ChevronDown v-else-if="flatSortKey==='name'&&flatSortDir==='desc'" :size="11"/></span>
              <span class="flat-col-loc">Location</span>
              <span class="flat-col-sport flat-th-sort" @click="toggleFlatSort('sport_category')">Sport <ChevronUp v-if="flatSortKey==='sport_category'&&flatSortDir==='asc'" :size="11"/><ChevronDown v-else-if="flatSortKey==='sport_category'&&flatSortDir==='desc'" :size="11"/></span>
              <span class="flat-col-price flat-th-sort" @click="toggleFlatSort('base_price')">Price /slot <ChevronUp v-if="flatSortKey==='base_price'&&flatSortDir==='asc'" :size="11"/><ChevronDown v-else-if="flatSortKey==='base_price'&&flatSortDir==='desc'" :size="11"/></span>
              <span class="flat-col-status">Placement</span>
              <span class="flat-col-actions"></span>
            </div>
            <div v-for="l in paginatedVenues" :key="l.id"
              class="flat-row"
              :class="{ 'drag-over': dragOverFlatId === l.id, 'dragging': dragFlatId === l.id, 'is-hidden': placementHidden(l) }"
              draggable="true" :data-tr-flat="l.id"
              @dragstart="onFlatDragStart($event, l.id)" @dragover="onFlatDragOver($event, l.id)" @dragleave="onFlatDragLeave" @drop="onFlatDrop($event, l.id)" @dragend="onFlatDragEnd">
              <span class="drag-handle flat-grip" title="Drag to reorder"
                @touchstart.stop="flatTR.onTouchstart($event, l.id)" @touchmove="flatTR.onTouchmove($event)"
                @touchend="flatTR.onTouchend($event)" @touchcancel="flatTR.onTouchend($event)"><GripVertical :size="15" /></span>
              <div class="flat-color-dot" :style="{ background: l.booking_color || '#10b981' }"></div>
              <div class="flat-name"><button class="listing-name-btn" @click="openEditListing(l)">{{ l.name }}</button></div>
              <div class="flat-loc">{{ locationName(l.location_id) }}</div>
              <div class="flat-sport muted">{{ l.sport_category }}</div>
              <div class="flat-price muted">{{ currencyForLocation(l.location_id) }} {{ l.base_price }}</div>
              <div class="flat-status">
                <span class="vis-badge" :class="visibilityBadge(l).cls" :title="visibilityBadge(l).tip">
                  {{ visibilityBadge(l).label }}
                </span>
                <div class="placement-chips">
                  <span v-for="ch in placementChannels(l)" :key="ch.key" class="chan-wrap">
                    <button type="button" class="chan" :class="['chan-'+ch.cls, { 'chan-btn': canManageListings }]" :title="ch.tip" @click.stop="canManageListings && togglePlacementMenu(l.id, ch.key)">
                      <component :is="statusIcon(ch.icon)" :size="12" />
                      <span class="chan-name">{{ ch.name }}</span>
                      <span v-if="ch.suffix" class="chan-suffix">· {{ ch.suffix }}</span>
                      <ChevronDown v-if="canManageListings" :size="11" class="chan-caret" />
                    </button>
                    <a v-if="ch.live && ch.url" class="chan-open" :href="ch.url" target="_blank" rel="noopener" title="Open in new tab" @click.stop><ExternalLink :size="12" /></a>
                    <div v-if="placementMenuKey === l.id+':'+ch.key" class="placement-menu" @click.stop>
                      <div class="pm-title"><component :is="ch.key==='marketplace' ? Store : Globe" :size="13" />{{ ch.key==='marketplace' ? 'Bookboll Marketplace' : 'Your website' }}</div>
                      <label class="pm-row">
                        <span class="dp-switch"><input type="checkbox" :checked="ch.on" @change="setPlacement(l, ch.key, $event.target.checked)" /><span class="dp-slider"></span></span>
                        <span>{{ ch.on ? (ch.suffix || 'Visible') : 'Hidden' }}</span>
                      </label>
                      <div class="pm-note">{{ ch.tip }}</div>
                      <div v-if="ch.key==='marketplace' && l.admin_note && (rowState(l)==='paused' || rowState(l)==='resubmitted')" class="pm-adminnote"><AlertTriangle :size="12" /><span><strong>Bookboll note:</strong> {{ l.admin_note }}</span></div>
                      <a v-if="ch.live && ch.url" class="pm-open" :href="ch.url" target="_blank" rel="noopener"><ExternalLink :size="13" />Open in new tab</a>
                    </div>
                  </span>
                </div>
              </div>
              <div class="flat-actions listing-actions">
                <button v-if="canManageListings" class="btn-icon" @click="openEditListing(l)"><Edit2 :size="15" /></button>
                <button v-if="canManageListings" class="btn-icon danger" @click="removeListing(l.id)"><Trash2 :size="15" /></button>
                <span v-if="!canManageListings" class="muted" style="font-size:12px">View only</span>
              </div>
            </div>
            <div v-if="!flatVenues.length" class="flat-empty">No venue listings found.</div>
          </div>
          <PaginationBar v-if="flatTotal > 0" :page="flatPage" :perPage="flatPerPage" :totalPages="venueTotalPages" :total="flatTotal" :visiblePages="venueVisiblePages" @update:perPage="flatPerPage = $event" @goTo="venueGoTo" />
        </div>

        <!-- Export modal -->
        <div v-if="showExportModal" class="modal-backdrop" @click.self="showExportModal=false">
          <div class="modal" style="max-width:400px">
            <div class="modal-header">
              <div class="modal-title">Export Listings</div>
              <button class="btn-icon" @click="showExportModal=false"><X :size="18"/></button>
            </div>
            <div class="modal-body" style="display:flex;flex-direction:column;gap:10px">
              <button class="export-choice-btn" @click="doExport(true)">
                <Download :size="16"/>
                <div><strong>Export with filters</strong><br><span>{{ flatTotal }} listings</span></div>
              </button>
              <button class="export-choice-btn" @click="doExport(false)">
                <Download :size="16"/>
                <div><strong>Export all listings</strong><br><span>{{ scopeTotal }} listings</span></div>
              </button>
            </div>
            <div class="modal-footer"><button class="btn btn-secondary" @click="showExportModal=false">Cancel</button></div>
          </div>
        </div>
      </template>

      <div v-else class="loc-list">
        <div
          v-for="loc in filteredLocations" :key="loc.id"
          class="loc-group"
          :class="{ 'drag-over': dragOverLocId === loc.id, 'dragging': dragLocId === loc.id, 'is-hidden': loc.status !== 'active' }"
          draggable="true" :data-tr-loc="loc.id"
          @dragstart="onLocDragStart($event, loc.id)"
          @dragover="onLocDragOver($event, loc.id)"
          @dragleave="onLocDragLeave"
          @drop="onLocDrop($event, loc.id)"
          @dragend="onLocDragEnd"
        >
          <div class="loc-row-head">
            <span class="drag-handle" title="Drag to reorder"
              @touchstart.stop="locTR.onTouchstart($event, loc.id)" @touchmove="locTR.onTouchmove($event)"
              @touchend="locTR.onTouchend($event)" @touchcancel="locTR.onTouchend($event)"><GripVertical :size="16" /></span>
            <button class="chev" @click="toggle(loc.id)">
              <ChevronDown v-if="expanded.has(loc.id)" :size="16" />
              <ChevronRight v-else :size="16" />
            </button>
            <div class="loc-title">
              <span class="loc-title-ic"><MapPinIcon :size="16" /></span>
              <button class="loc-name-btn" @click="openEditLoc(loc)">{{ loc.name }}</button>
              <span class="badge" :class="loc.status === 'active' ? 'badge-success' : 'badge-neutral'">{{ loc.status }}</span>
              <span class="count">{{ listingsByLocation(loc.id).length }} listings</span>
            </div>
            <div class="loc-actions">
              <AddMenu v-if="locAddItems.length" label="Add" size="sm" :items="locAddItems" @select="key => onLocAdd(key, loc.id)" />
              <template v-if="userRole === 'admin'">
                <button v-if="loc.eligibility_status !== 'paused'" class="btn-icon" title="Pause eligibility" @click="openPause('locations', loc)">
                  <PauseCircle :size="16" />
                </button>
                <button v-else class="btn-icon paused-icon" title="Resume eligibility" @click="resumeEligibility('locations', loc)">
                  <PlayCircle :size="16" />
                </button>
              </template>
              <button v-if="canManageListings" class="btn-icon" :class="{ 'eye-hidden': loc.status !== 'active' }" :title="loc.status === 'active' ? 'Hide' : 'Show'" @click="toggleLocStatus(loc)">
                <Eye v-if="loc.status === 'active'" :size="16" />
                <EyeOff v-else :size="16" />
              </button>
              <button v-if="canManageListings" class="btn-icon" @click="openEditLoc(loc)"><Edit2 :size="16" /></button>
              <button v-if="canManageListings" class="btn-icon danger" @click="removeLoc(loc.id)"><Trash2 :size="16" /></button>
            </div>
          </div>

          <!-- Location state notice -->
          <template v-if="rowState(loc) !== 'active'">
            <!-- resubmitted: provider edited after pause, awaiting admin review -->
            <div v-if="rowState(loc) === 'resubmitted'" class="eligibility-notice notice-review">
              <RefreshCw :size="15" />
              <div v-if="userRole === 'provider'"><strong>New changes under review.</strong> Your updates have been submitted and are awaiting admin approval.</div>
              <div v-else><strong>Pending approval — new changes submitted by provider.</strong><span v-if="loc.admin_note"> Your earlier note: &ldquo;{{ loc.admin_note }}&rdquo;</span></div>
            </div>
            <!-- paused: admin flagged, waiting on provider -->
            <div v-else-if="rowState(loc) === 'paused'" class="eligibility-notice" :class="userRole === 'provider' ? 'notice-provider' : 'notice-admin'">
              <AlertTriangle :size="15" />
              <div v-if="userRole === 'provider'">
                <strong>Action required:</strong> This location has been paused by admin.
                <span v-if="loc.admin_note"> &ldquo;{{ loc.admin_note }}&rdquo;</span>
                Please edit and resubmit for review.
              </div>
              <div v-else>
                <strong>Paused by admin</strong> — awaiting provider update.<span v-if="loc.admin_note"> Note sent: &ldquo;{{ loc.admin_note }}&rdquo;</span>
              </div>
            </div>
            <!-- pending_new: brand-new submission (no prior approval) -->
            <div v-else-if="rowState(loc) === 'pending_new'" class="eligibility-notice notice-review">
              <RefreshCw :size="15" />
              <div v-if="userRole === 'provider'"><strong>Under review.</strong> Awaiting admin approval for first-time publishing.</div>
              <div v-else><strong>New submission — pending approval.</strong></div>
            </div>
          </template>

          <div v-if="expanded.has(loc.id)" class="loc-body">
            <div
              v-for="l in listingsByLocation(loc.id)" :key="l.id"
              class="listing-row"
              :class="{ 'drag-over': dragOverListingId === l.id, 'dragging': dragListingId === l.id, 'is-hidden': placementHidden(l) }"
              draggable="true" :data-tr-listing="l.id"
              @dragstart="onListingDragStart($event, l.id, loc.id)"
              @dragover="onListingDragOver($event, l.id)"
              @dragleave="onListingDragLeave"
              @drop="onListingDrop($event, l.id)"
              @dragend="onListingDragEnd"
            >
              <span class="drag-handle listing-drag" title="Drag to reorder"
                @touchstart.stop="listingTR.onTouchstart($event, l.id, loc.id)" @touchmove="listingTR.onTouchmove($event)"
                @touchend="listingTR.onTouchend($event)" @touchcancel="listingTR.onTouchend($event)"><GripVertical :size="15" /></span>
              <div class="dot" :style="{ background: l.booking_color || '#10b981', flexShrink: 0 }"></div>
              <div class="listing-info">
                <button class="listing-name-btn" @click="openEditListing(l)">{{ l.name }}</button>
                <div class="muted">{{ l.sport_category }} &middot; {{ currencyForLocation(l.location_id) }} {{ l.base_price }}/slot</div>
              </div>
              <div class="listing-pause-block">
                <span class="vis-badge" :class="visibilityBadge(l).cls" :title="visibilityBadge(l).tip">
                  {{ visibilityBadge(l).label }}
                </span>
                <div class="placement-chips">
                  <span v-for="ch in placementChannels(l)" :key="ch.key" class="chan-wrap">
                    <button type="button" class="chan" :class="['chan-'+ch.cls, { 'chan-btn': canManageListings }]" :title="ch.tip" @click.stop="canManageListings && togglePlacementMenu(l.id, ch.key)">
                      <component :is="statusIcon(ch.icon)" :size="12" />
                      <span class="chan-name">{{ ch.name }}</span>
                      <span v-if="ch.suffix" class="chan-suffix">· {{ ch.suffix }}</span>
                      <ChevronDown v-if="canManageListings" :size="11" class="chan-caret" />
                    </button>
                    <a v-if="ch.live && ch.url" class="chan-open" :href="ch.url" target="_blank" rel="noopener" title="Open in new tab" @click.stop><ExternalLink :size="12" /></a>
                    <div v-if="placementMenuKey === l.id+':'+ch.key" class="placement-menu" @click.stop>
                      <div class="pm-title"><component :is="ch.key==='marketplace' ? Store : Globe" :size="13" />{{ ch.key==='marketplace' ? 'Bookboll Marketplace' : 'Your website' }}</div>
                      <label class="pm-row">
                        <span class="dp-switch"><input type="checkbox" :checked="ch.on" @change="setPlacement(l, ch.key, $event.target.checked)" /><span class="dp-slider"></span></span>
                        <span>{{ ch.on ? (ch.suffix || 'Visible') : 'Hidden' }}</span>
                      </label>
                      <div class="pm-note">{{ ch.tip }}</div>
                      <div v-if="ch.key==='marketplace' && l.admin_note && (rowState(l)==='paused' || rowState(l)==='resubmitted')" class="pm-adminnote"><AlertTriangle :size="12" /><span><strong>Bookboll note:</strong> {{ l.admin_note }}</span></div>
                      <a v-if="ch.live && ch.url" class="pm-open" :href="ch.url" target="_blank" rel="noopener"><ExternalLink :size="13" />Open in new tab</a>
                    </div>
                  </span>
                </div>
              </div>
              <div class="listing-actions">
                <template v-if="userRole === 'admin'">
                  <button v-if="l.eligibility_status !== 'paused'" class="btn-icon" title="Pause eligibility" @click="openPause('listings', l)">
                    <PauseCircle :size="16" />
                  </button>
                  <button v-else class="btn-icon paused-icon" title="Resume eligibility" @click="resumeEligibility('listings', l)">
                    <PlayCircle :size="16" />
                  </button>
                </template>
                <button v-if="canManageListings" class="btn-icon" @click="openEditListing(l)"><Edit2 :size="16" /></button>
                <button v-if="canManageListings" class="btn-icon danger" @click="removeListing(l.id)"><Trash2 :size="16" /></button>
              </div>
            </div>
            <div v-if="!listingsByLocation(loc.id).length" class="empty-sub">
              <span>No booking listings yet for this location.</span>
              <AddMenu v-if="canManageListings" size="sm" label="Add" align="left" :items="locAddItems" @select="k => onLocAdd(k, loc.id)" />
            </div>
          </div>
        </div>
        <div v-if="filteredLocations.length && canManageListings" class="loc-add-foot">
          <button class="btn btn-secondary" @click="openNewLoc"><Plus :size="15" />Add new location</button>
        </div>
        <div v-if="!filteredLocations.length" class="card">
          <EmptyState :icon="Landmark"
            :title="locations.length ? 'No matching locations' : 'No locations yet'"
            :message="locations.length ? 'No locations match your search or filters. Try clearing them.' : 'Add your first location, then create bookable listings inside it.'"
            :action-label="(!locations.length && canManageListings) ? 'Add Location' : ''" @action="openNewLoc" />
        </div>
      </div>
        <!-- The grouped view pages over VENUE CARDS. It previously rendered every
             venue at once, which is fine at 19 and not at a few thousand. -->
        <PaginationBar v-if="venueTotal > 0" :page="venuePage" :perPage="venuePerPage"
          :totalPages="groupedTotalPages" :total="venueTotal" :visiblePages="groupedVisiblePages"
          @update:perPage="venuePerPage = $event" @goTo="groupedGoTo" />

    </template>

    <!-- EDITOR DRAWER (slides in from the right) -->
    <teleport to="body">
    <div v-if="view === 'editor'" class="bb-drawer-backdrop" style="z-index:3000" @click.self="requestExitEditor">
    <div class="bb-drawer lf-drawer" @input="markDirty" @change="markDirty">
      <div class="bb-drawer-header">
        <div>
          <div class="bb-drawer-title">{{ editing ? 'Edit Booking Listing' : 'New Booking Listing' }}</div>
          <div class="bb-drawer-subtitle">Configure every detail of this bookable listing</div>
        </div>
        <button class="bb-drawer-close" @click="requestExitEditor"><X :size="18" /></button>
      </div>
      <div class="bb-drawer-body">

      <!-- editor state notice -->
      <template v-if="editing && rowState(form) !== 'active'">
        <div v-if="rowState(form) === 'resubmitted'" class="eligibility-notice notice-review" style="margin-bottom:16px;">
          <RefreshCw :size="16" />
          <div v-if="userRole === 'provider'"><strong>New changes under review.</strong> Your updates have been submitted and are awaiting admin approval.</div>
          <div v-else><strong>Pending approval — new changes submitted by provider.</strong><span v-if="form.admin_note"> Your earlier note: &ldquo;{{ form.admin_note }}&rdquo;</span></div>
        </div>
        <div v-else-if="rowState(form) === 'paused'" class="eligibility-notice" :class="userRole === 'provider' ? 'notice-provider' : 'notice-admin'" style="margin-bottom:16px;">
          <AlertTriangle :size="16" />
          <div v-if="userRole === 'provider'">
            <strong>Action required:</strong> This listing has been paused by admin.
            <span v-if="form.admin_note"> &ldquo;{{ form.admin_note }}&rdquo;</span>
            Update the listing below and save to resubmit for approval.
          </div>
          <div v-else><strong>Paused by admin</strong> — awaiting provider update.<span v-if="form.admin_note"> Note sent: &ldquo;{{ form.admin_note }}&rdquo;</span></div>
        </div>
        <div v-else-if="rowState(form) === 'pending_new'" class="eligibility-notice notice-review" style="margin-bottom:16px;">
          <RefreshCw :size="16" />
          <div v-if="userRole === 'provider'"><strong>Under review.</strong> Awaiting admin approval for first-time publishing.</div>
          <div v-else><strong>New submission — pending approval.</strong></div>
        </div>
      </template>

      <!-- Live listing with new content awaiting approval: it stays live on the approved
           title/photos/description; only these need a review. Schedule/pricing/slots below
           are already live. -->
      <div v-if="editing && contentUnderReview && rowState(form) === 'active'" class="eligibility-notice notice-review" style="margin-bottom:16px;">
        <Clock :size="16" />
        <div v-if="userRole === 'provider'"><strong>Your listing is live.</strong> The new title, description or photos below are awaiting Bookboll approval — your current ones keep showing until then. Schedule, pricing and slots you change here go live right away.</div>
        <div v-else><strong>Content update pending approval</strong> — the listing is still live on its approved content.</div>
      </div>

      <div class="sections">
        <!-- BASIC INFORMATION -->
        <section class="section-card" :class="{ open: openSection === 'basic' }">
          <button class="section-head" @click="openSection = openSection === 'basic' ? '' : 'basic'">
            <span class="tick" :class="{ done: basicComplete }"><Check :size="12" /></span>
            <div class="section-title">
              <div>Basic Information</div>
              <div class="section-sub">Name, location, type &amp; description</div>
            </div>
            <ChevronDown :size="18" class="chev-icon" :class="{ rot: openSection === 'basic' }" />
          </button>
          <div v-if="openSection === 'basic'" class="section-body">
            <div class="grid grid-2">
              <div class="form-group"><label class="form-label">Listing Name <span class="req">*</span><InfoTip text="The public name customers see for this bookable space — e.g. “Football Field A” or “Studio 2”. Keep it short and recognizable." /></label>
                <input v-model="form.name" :class="{ 'input-invalid': listingTriedSave && !form.name }" placeholder="e.g. Football Field A" />
              </div>
              <div class="form-group"><label class="form-label">Location Assignment <span class="req">*</span><InfoTip text="Which of your venues this listing belongs to. It inherits that location's opening hours, address and currency." /></label>
                <select v-model="form.location_id" @change="onLocationSelectChange" :class="{ 'input-invalid': listingTriedSave && !form.location_id }">
                  <option value="">Select location</option>
                  <option v-for="lo in listingFormLocations" :key="lo.id" :value="lo.id">{{ lo.name }}</option>
                  <option v-if="canManageListings" value="__create__">＋ Create a new location…</option>
                </select>
                <div v-if="userRole === 'provider'" class="form-hint">
                  You can list venues in any country you operate in. Once you have a payout wallet, full online payments work everywhere — a local wallet is created automatically per country so you're paid in that currency.
                </div>
              </div>
              <div class="form-group"><label class="form-label">Type <span class="req">*</span><InfoTip text="What kind of space this is (Padel, Restaurant, Studio…). Customers filter by type, and it sets the Category automatically." /></label>
                <div class="combo" @click.stop>
                  <input
                    class="combo-input"
                    :class="{ 'input-invalid': listingTriedSave && !form.sport_category }"
                    :value="sportOpen ? sportQuery : form.sport_category"
                    @input="e => { sportQuery = e.target.value; sportOpen = true }"
                    @focus="onSportInputFocus"
                    @blur="onSportBlur"
                    placeholder="Search a type — e.g. Padel, Restaurant, Studio…"
                  />
                  <ChevronDown :size="16" class="combo-caret" :class="{ rot: sportOpen }" />
                  <div v-if="sportOpen" class="combo-menu">
                    <!-- Add-new flow: name comes from what was typed; provider picks ONE category. -->
                    <div v-if="addingType" class="combo-addpanel" @mousedown.prevent>
                      <div class="combo-add-title">Add “{{ newTypeName }}” — pick a category</div>
                      <div class="combo-add-cats">
                        <button v-for="c in VENUE_CATEGORIES" :key="c.key" type="button" class="combo-add-cat" @mousedown.prevent="confirmAddType(c.key)">{{ c.label }}</button>
                      </div>
                      <div class="combo-add-note">Added to your listing now; joins the catalog once the listing is approved.</div>
                    </div>
                    <template v-else>
                      <div v-if="!filteredTypes.length && !canAddType" class="combo-empty">No matches</div>
                      <button
                        v-for="opt in filteredTypes"
                        :key="opt.cat + ':' + opt.type"
                        type="button"
                        class="combo-item"
                        :class="{ active: opt.type === form.sport_category && opt.cat === form.category }"
                        @mousedown.prevent="selectType(opt)"
                      >
                        <span class="combo-item-name">{{ opt.type }}</span>
                        <span class="combo-cat">{{ opt.catLabel }}</span>
                      </button>
                      <button v-if="canAddType" type="button" class="combo-add" @mousedown.prevent="startAddType">
                        <Plus :size="14" /> Add “{{ newTypeName }}” as a new type
                      </button>
                    </template>
                  </div>
                </div>
              </div>
              <div class="form-group"><label class="form-label">Category<InfoTip text="Set automatically from the Type — Sports, Hangout or Work. Used to group listings on the marketplace." /></label>
                <div class="readonly-field" :class="{ empty: !form.sport_category }">
                  {{ form.sport_category ? derivedCategoryLabel : 'Set automatically from the type' }}
                </div>
              </div>
            </div>

            <div class="form-group"><label class="form-label">Description <span class="req">*</span><InfoTip text="What customers read before booking: indoor/outdoor, surface, capacity, amenities (lighting, parking, showers) and any house rules." /></label>
              <textarea v-model="form.description" rows="4" placeholder="Describe this listing for customers…" :class="{ 'input-invalid': listingTriedSave && !form.description }"></textarea>
            </div>

            <div class="form-group">
              <label class="form-label">Booking Color in Calendar<InfoTip text="The color used for this listing's confirmed bookings in your calendar, so you can tell listings apart at a glance." /></label>
              <div class="pg-color-row">
                <button v-for="c in BOOKING_COLORS" :key="c" type="button" class="pg-swatch" :class="{ active: form.booking_color === c }" :style="{ background: c }" :title="c" @click="form.booking_color = c"></button>
                <label class="pg-swatch pg-swatch-custom" :class="{ active: form.booking_color && !BOOKING_COLORS.includes(form.booking_color) }" title="Custom color">
                  <input type="color" v-model="form.booking_color" />
                </label>
              </div>
            </div>

            <!-- Who may book this listing. Separate from Placement (which decides
                 WHERE it is shown): this decides WHO may make a booking. -->
            <div class="form-group">
              <label class="form-label">Availability<InfoTip text="Who can book this listing. Tick both for a normal listing. Untick Customers to keep a court for the front desk only; untick Staff to hide it from the admin booking screen. With both unticked the listing is inactive." /></label>
              <div class="vis-picker">
                <label class="vis-check" :class="{ on: form.show_on_customer }">
                  <input type="checkbox" v-model="form.show_on_customer" />
                  <Users :size="15" />
                  <span class="vis-check-body">
                    <span class="vis-check-name">Customers</span>
                    <span class="vis-check-desc">Website &amp; customer app</span>
                  </span>
                </label>
                <label class="vis-check" :class="{ on: form.show_on_admin }">
                  <input type="checkbox" v-model="form.show_on_admin" />
                  <ShieldCheck :size="15" />
                  <span class="vis-check-body">
                    <span class="vis-check-name">Staff</span>
                    <span class="vis-check-desc">Admin booking screen &amp; staff app</span>
                  </span>
                </label>
              </div>
              <div v-if="!form.show_on_admin && !form.show_on_customer" class="vis-warn">
                <AlertTriangle :size="13" /> Nobody can book this listing — it will be saved as <strong>inactive</strong>.
              </div>
            </div>

            <div v-if="listingSaveError" class="module-block-error">
              <AlertTriangle :size="14" />{{ listingSaveError }}
            </div>
            <div class="section-footer">
              <button class="btn btn-secondary sf-cancel" @click="requestExitEditor">Cancel</button>
              <span v-if="savedFlag" class="saved"><Check :size="14" />Saved</span>
              <button class="btn btn-primary" @click="saveSection('schedule')">{{ editingExisting ? 'Save &amp; Close' : 'Save &amp; Continue' }}</button>
            </div>
          </div>
        </section>

        <!-- WEEKLY SCHEDULE -->
        <section class="section-card" :class="{ open: openSection === 'schedule' }">
          <button class="section-head" @click="openSection = openSection === 'schedule' ? '' : 'schedule'">
            <span class="tick" :class="{ done: scheduleComplete }"><Check :size="12" /></span>
            <div class="section-title">
              <div>Weekly Schedule</div>
              <div class="section-sub">Operating hours for each day of the week</div>
            </div>
            <ChevronDown :size="18" class="chev-icon" :class="{ rot: openSection === 'schedule' }" />
          </button>
          <div v-if="openSection === 'schedule'" class="section-body">
            <p class="muted" style="margin: 0 0 12px;">A new listing starts from a copy of its location's hours. Edit any day below — these hours are this listing's own and won't change when the location's schedule changes. The location's hours only limit when the listing can be open; hours outside them are flagged below.</p>
            <div v-if="listingHoursOutOfBounds.length" class="oob-warning">
              <AlertTriangle :size="14" />
              <div>
                <div style="font-weight:600; margin-bottom:4px;">A listing can't open outside its location's hours:</div>
                <ul style="margin:0 0 6px; padding-left:16px;">
                  <li v-for="w in listingHoursOutOfBounds" :key="w">{{ w }}</li>
                </ul>
                <div style="font-size:12px;">
                  Fix this by either <strong>changing the location's schedule</strong> (edit the location → Schedule)
                  or, for a one-off date, add a <strong>Special Day</strong> with custom hours. Saving is blocked until the listing fits within location hours.
                </div>
              </div>
            </div>
            <WeeklyHours v-model="hours" :parent-hours="locationHoursGrouped" :show-inherit="true" :slot-duration="Number(form.slot_duration)" @edit-location-schedule="editLocationSchedule" @create-special-day="createSpecialDayForListing" />
            <div class="section-footer">
              <button class="btn btn-secondary sf-cancel" @click="requestExitEditor">Cancel</button>
              <span v-if="savedFlag" class="saved"><Check :size="14" />Saved</span>
              <button class="btn btn-primary" @click="saveSection('timeslot')">{{ editingExisting ? 'Save &amp; Close' : 'Save &amp; Continue' }}</button>
            </div>
          </div>
        </section>

        <!-- TIMESLOT SETTINGS -->
        <section class="section-card" :class="{ open: openSection === 'timeslot' }">
          <button class="section-head" @click="openSection = openSection === 'timeslot' ? '' : 'timeslot'">
            <span class="tick" :class="{ done: timeslotComplete }"><Check :size="12" /></span>
            <div class="section-title">
              <div>Timeslot Settings</div>
              <div class="section-sub">How bookable time slots are generated</div>
            </div>
            <ChevronDown :size="18" class="chev-icon" :class="{ rot: openSection === 'timeslot' }" />
          </button>
          <div v-if="openSection === 'timeslot'" class="section-body">
            <div class="grid grid-2">
              <div class="form-group"><label class="form-label">Max Bookings per Time Slot <span class="req">*</span><InfoTip text="How many separate bookings can share the same start time — e.g. set to 3 if you have 3 identical courts." /></label>
                <input type="number" min="1" v-model="form.max_bookings_per_slot" />
              </div>
              <div class="form-group"><label class="form-label">Slot Duration <span class="req">*</span><InfoTip text="How long one bookable slot lasts. Customers book in blocks of this length (e.g. 1 hour). Options go in 15-minute steps." /></label>
                <select v-model.number="form.slot_duration">
                  <option v-for="d in SLOT_DURATIONS" :key="d.value" :value="d.value">{{ d.label }}</option>
                </select>
              </div>
              <div class="form-group">
                <label class="form-label">Buffer Between Bookings<InfoTip text="A gap automatically reserved after each booking — for cleaning or turnaround — before the next one can start." /></label>
                <select v-model.number="form.buffer_time">
                  <option v-for="b in BUFFER_OPTIONS" :key="b.value" :value="b.value">{{ b.label }}</option>
                </select>
              </div>
            </div>

            <!-- Bookable start times — always editable; tap a slot to include/exclude it. -->
            <div class="form-group" style="margin-top:4px">
              <label class="form-label">Bookable start times<InfoTip text="Generated from your slot length, buffer & weekly schedule. Tap a time to turn it off if you don't want to offer it — leave them all on to offer every slot." /></label>
              <div class="ft-preview">
                <div class="ft-preview-head"><Clock :size="13" /> Offered to customers<span class="ft-preview-count">{{ displayedStartTimes.length }}</span></div>
                <div v-if="!autoStartTimes.length" class="fixed-times-empty">No bookable times yet — set open hours in the <button type="button" class="link-btn" @click.stop="goToSchedule">Weekly Schedule</button> above.</div>
                <template v-else>
                  <div class="cst-caption"><Info :size="13" /> Tap a time to turn it off or on — only highlighted times are offered to customers.</div>
                  <div class="cst-chips">
                    <button v-for="t in autoStartTimes" :key="t" type="button" class="cst-chip" :class="{ on: isStartOffered(t) }" @click="toggleStartTime(t)">
                      <Check v-if="isStartOffered(t)" :size="12" class="cst-chip-check" />{{ fmt12(t) }}
                    </button>
                  </div>
                </template>
              </div>
            </div>

            <!-- Extension discount toggle -->
            <div class="ext-block">
              <div class="ext-block-row">
                <div class="ext-block-info">
                  <div class="ext-block-title"><Clock :size="15" />Session extension discount<span class="dp-rec">Recommended</span></div>
                  <div class="ext-block-sub">Let customers extend into the next free adjacent slot at a reduced price.</div>
                </div>
                <label class="dp-switch" :title="extensionEnabled ? 'On' : 'Off'">
                  <input type="checkbox" :checked="extensionEnabled" @change="toggleExtension($event.target.checked)" />
                  <span class="dp-slider"></span>
                </label>
              </div>
              <div v-if="extensionEnabled" class="ext-block-body">
                <div class="ext-type-toggle">
                  <button type="button" :class="{ on: form.extension_pricing === 'percent' }" @click="form.extension_pricing = 'percent'">% discount</button>
                  <button type="button" :class="{ on: form.extension_pricing === 'flat' }" @click="form.extension_pricing = 'flat'">Flat discount</button>
                </div>
                <div class="ext-value-row">
                  <input type="number" min="0" v-model="form.extension_value" class="ext-value-input" />
                  <span class="ext-value-unit">{{ form.extension_pricing === 'flat' ? currencyForLocation(form.location_id) + ' off each extra slot' : '% off each extra slot' }}</span>
                </div>
                <div class="ext-value-row">
                  <input type="number" min="1" max="12" v-model.number="form.extension_max_slots" class="ext-value-input" />
                  <span class="ext-value-unit">discounted slot{{ Number(form.extension_max_slots) === 1 ? '' : 's' }} per chain (max)</span>
                </div>
                <div class="form-hint">Caps how many adjacent slots in one run get the discount. With <strong>{{ Math.max(1, Number(form.extension_max_slots) || 1) }}</strong>, picking 6pm discounts the next {{ Math.max(1, Number(form.extension_max_slots) || 1) }} slot{{ Math.max(1, Number(form.extension_max_slots) || 1) === 1 ? '' : 's' }}; anything beyond that is full price.</div>
              </div>
            </div>

            <div v-if="listingSaveError" class="module-block-error">
              <AlertTriangle :size="14" />{{ listingSaveError }}
            </div>
            <div class="section-footer">
              <button class="btn btn-secondary sf-cancel" @click="requestExitEditor">Cancel</button>
              <span v-if="savedFlag" class="saved"><Check :size="14" />Saved</span>
              <button class="btn btn-primary" @click="saveSection('pricing')">{{ editingExisting ? 'Save &amp; Close' : 'Save &amp; Continue' }}</button>
            </div>
          </div>
        </section>

        <!-- PRICING -->
        <section class="section-card" :class="{ open: openSection === 'pricing' }">
          <button class="section-head" @click="openSection = openSection === 'pricing' ? '' : 'pricing'">
            <span class="tick" :class="{ done: pricingComplete }"><Check :size="12" /></span>
            <div class="section-title">
              <div>Pricing</div>
              <div class="section-sub">Base price per slot &amp; time-range overrides</div>
            </div>
            <ChevronDown :size="18" class="chev-icon" :class="{ rot: openSection === 'pricing' }" />
          </button>
          <div v-if="openSection === 'pricing'" class="section-body">
            <div class="grid grid-2" style="margin-bottom:16px;">
              <div class="form-group"><label class="form-label">Base Price per Slot ({{ currencyForLocation(form.location_id) }}) <span class="req">*</span><InfoTip text="The default price a customer pays for one slot. Override it for specific days or times with the pricing grid below." /></label>
                <input type="number" v-model="form.base_price" placeholder="e.g. 120" :class="{ 'input-invalid': (listingTriedSave && !(Number(form.base_price) > 0)) || pricingBelowBase }" />
                <div v-if="pricingBelowBase" class="form-hint" style="color:#dc2626;">A price below is lower than this base price — lower the base price, or set a higher price in the grid.</div>
              </div>
            </div>
            <div class="dp-head">
              <div class="dp-title">
                Dynamic pricing
                <span class="dp-rec">Recommended</span>
                <InfoTip text="Charge more at peak times or on certain days. When off, every slot uses the base price above." />
              </div>
              <label class="dp-switch" :title="dynamicPricing ? 'On' : 'Off'">
                <input type="checkbox" v-model="dynamicPricing" @change="onToggleDynamic" />
                <span class="dp-slider"></span>
              </label>
            </div>

            <PricingGrid v-if="dynamicPricing"
              :groups="groups" :cells="cells" :base-price="Number(form.base_price)"
              :slot-duration="Number(form.slot_duration)" :pattern="pattern" :hours="mergedHoursList"
              :currency="currencyForLocation(form.location_id)"
              @update:groups="groups = $event" @update:cells="cells = $event" @update:pattern="pattern = $event"
              @below-base="pricingBelowBase = $event"
            />
            <div class="section-footer">
              <button class="btn btn-secondary sf-cancel" @click="requestExitEditor">Cancel</button>
              <span v-if="savedFlag" class="saved"><Check :size="14" />Saved</span>
              <button class="btn btn-primary" @click="saveSection('placement')">{{ editingExisting ? 'Save &amp; Close' : 'Save &amp; Continue' }}</button>
            </div>
          </div>
        </section>

        <!-- PLACEMENT & PAYMENT -->
        <section class="section-card" :class="{ open: openSection === 'placement' }">
          <button class="section-head" @click="openSection = openSection === 'placement' ? '' : 'placement'">
            <span class="tick" :class="{ done: placementComplete && payComplete }"><Check :size="12" /></span>
            <div class="section-title">
              <div>Placement &amp; Payment</div>
              <div class="section-sub">Where this listing appears, and how customers pay on each</div>
            </div>
            <ChevronDown :size="18" class="chev-icon" :class="{ rot: openSection === 'placement' }" />
          </button>
          <div v-if="openSection === 'placement'" class="section-body">

            <!-- ── STEP 1 · Where can customers book this? ── -->
            <div class="pp-step"><span class="pp-step-n">1</span> Where can customers book this?</div>
            <div class="pp-step-sub">Turn on one or both. Each place has its own payment setup below.</div>

            <div class="pl-opt" :class="{ on: form.show_on_marketplace }">
              <div class="pl-opt-icon mkt"><Store :size="20" /></div>
              <div class="pl-opt-body">
                <div class="pl-opt-name">Bookboll Marketplace <span class="badge badge-success placement-rec">Recommended</span>
                  <a v-if="editingExisting && editing && marketplaceState(editing).live" class="pl-open" :href="listingUrl(editing, 'marketplace')" target="_blank" rel="noopener" title="Open on Bookboll" @click.stop><ExternalLink :size="14" /></a>
                </div>
                <div class="pl-opt-desc">New customers find and book you on Bookboll. Reviewed before it goes live.</div>
              </div>
              <label class="dp-switch" :title="form.show_on_marketplace ? 'On' : 'Off'">
                <input type="checkbox" v-model="form.show_on_marketplace" @change="onMarketplacePlacementChange" />
                <span class="dp-slider"></span>
              </label>
            </div>

            <div class="pl-opt" :class="{ on: form.show_on_provider_site }" style="margin-top:10px;">
              <div class="pl-opt-icon site"><Globe :size="20" /></div>
              <div class="pl-opt-body">
                <div class="pl-opt-name">My own website
                  <a v-if="editingExisting && editing && siteState(editing).live" class="pl-open" :href="listingUrl(editing, 'site')" target="_blank" rel="noopener" title="Open on your website" @click.stop><ExternalLink :size="14" /></a>
                </div>
                <div class="pl-opt-desc">Your own booking site — live instantly, 0% commission.</div>
              </div>
              <label class="dp-switch" :title="form.show_on_provider_site ? 'On' : 'Off'">
                <input type="checkbox" v-model="form.show_on_provider_site" @change="onSitePlacementChange" />
                <span class="dp-slider"></span>
              </label>
            </div>

            <div v-if="!form.show_on_marketplace && !form.show_on_provider_site" class="placement-warn">
              <AlertTriangle :size="14" /> This listing won't appear anywhere until you turn on a place above.
            </div>


            <!-- ── STEP 2 · Getting paid on the Marketplace (collapsible) ── -->
            <div v-if="form.show_on_marketplace" class="pp-pay">
              <button type="button" class="pp-chan pp-chan-toggle" :aria-expanded="payOpen.mkt" @click="payOpen.mkt = !payOpen.mkt">
                <span class="pp-step-n">2</span>
                <Store :size="15" />
                <b>Bookboll Marketplace payment methods</b>
                <span class="pp-chan-fee">You always keep {{ 100 - effectiveCommissionRate }}%</span>
                <ChevronDown :size="16" class="pp-chan-chev" :class="{ rot: payOpen.mkt }" />
              </button>

              <!-- Collapsed: the facts at a glance, one click to edit -->
              <div v-if="!payOpen.mkt" class="pp-sum" role="button" @click="payOpen.mkt = true">
                <template v-if="mktPaySummary.length">
                  <span v-for="s in mktPaySummary" :key="s" class="pp-sum-chip"><Check :size="11" /> {{ s }}</span>
                </template>
                <span v-else class="pp-sum-none"><AlertTriangle :size="12" /> No payment option on — click to choose</span>
                <span v-if="!onlineAllowed && !locationIsBookbollOwned" class="pp-sum-warn">payout method needed for full online payment</span>
                <span class="pp-sum-edit">Edit</span>
              </div>

              <template v-if="payOpen.mkt">
              <div class="pp-chan-sub">
                Bookboll's fee here is <strong>{{ effectiveCommissionRate }}%</strong> of every booking, on every option — the options below only change <strong>where the customer pays</strong>. If more than one is on, the customer picks at checkout.
              </div>

              <!-- ONE wallet status strip (replaces per-option warnings) -->
              <div v-if="!onlineAllowed && !locationIsBookbollOwned" class="pay-status warn">
                <AlertTriangle :size="14" />
                <span v-if="userRole === 'provider'">You haven't connected a payout method yet, so money paid online can't reach you — <b>"Everything paid online" is locked</b>. The deposit option works without one.</span>
                <span v-else>This provider has no payout method yet — "Everything paid online" can't be enabled until they add one.</span>
                <a v-if="userRole === 'provider'" class="btn-link" @click.prevent="payoutModalSource = 'marketplace'; payoutModal = true">Set up payouts</a>
              </div>
              <div v-else-if="onlineAllowed && !locationIsBookbollOwned" class="pay-status ok">
                <ShieldCheck :size="14" /> <span>Payouts ready — online money goes straight to your Bookings wallet.</span>
              </div>

              <!-- ── Provider: fee is fixed; choose where the customer pays ── -->
              <template v-if="userRole === 'provider'">
                <label class="pay-opt" :class="{ on: pay.deposit_enabled }">
                  <input type="checkbox" v-model="pay.deposit_enabled" @change="onProviderDepositToggle" />
                  <div class="pay-opt-body">
                    <div class="pay-opt-title">Deposit online, rest upon customer arrival (offline) <span class="pay-chip rec">Simplest</span></div>
                    <div class="pay-opt-desc">A small online deposit confirms the booking — that deposit is Bookboll's {{ payExample.feePct }}% platform fee. Everything paid at your venue is yours.</div>
                    <div class="ps-bar">
                      <span class="ps-seg fee" :style="{ width: Math.max(payExample.feePct, 8) + '%' }"></span>
                      <span class="ps-seg venue" :style="{ width: (100 - Math.max(payExample.feePct, 8)) + '%' }"></span>
                    </div>
                    <div class="ps-legend">
                      <span class="ps-key"><i class="fee"></i>{{ payCur }} {{ fmtPay(payExample.fee) }} online → Bookboll's fee ({{ payExample.feePct }}%)</span>
                      <span class="ps-key"><i class="venue"></i>{{ payCur }} {{ fmtPay(payExample.share) }} at your venue → <b>yours</b></span>
                    </div>
                    <div v-if="!pay.online_payment_enabled" class="pay-hint-locked">To switch this off, first turn on "Everything paid online" below.</div>
                  </div>
                </label>

                <label class="pay-opt" :class="{ on: pay.online_payment_enabled, dim: !onlineAllowed }">
                  <input type="checkbox" v-model="pay.online_payment_enabled" @change="onProviderOnlineToggle" />
                  <div class="pay-opt-body">
                    <div class="pay-opt-title">Everything paid online <Lock v-if="!onlineAllowed" :size="12" class="pay-lock-ico" /></div>
                    <div class="pay-opt-desc">The customer pays in full when booking. Your share lands in your Bookings wallet — nothing to collect on arrival.</div>
                    <div class="ps-bar">
                      <span class="ps-seg you" :style="{ width: (100 - Math.max(payExample.feePct, 8)) + '%' }"></span>
                      <span class="ps-seg fee" :style="{ width: Math.max(payExample.feePct, 8) + '%' }"></span>
                    </div>
                    <div class="ps-legend">
                      <span class="ps-key"><i class="you"></i>{{ payCur }} {{ fmtPay(payExample.share) }} → <b>your wallet</b></span>
                      <span class="ps-key"><i class="fee"></i>{{ payCur }} {{ fmtPay(payExample.fee) }} → Bookboll's fee ({{ payExample.feePct }}%)</span>
                    </div>
                  </div>
                </label>

                <div v-if="pay.cash_on_arrival" class="pay-opt locked">
                  <span class="lock-dot"></span>
                  <div class="pay-opt-body">
                    <div class="pay-opt-title">Cash — everything at the venue <span class="badge badge-info" style="margin-left:6px">Enabled by Bookboll</span></div>
                    <div class="pay-opt-desc">Bookboll has allowed full cash payment at the venue for this listing.</div>
                  </div>
                </div>

                <div v-if="!pay.deposit_enabled && !pay.online_payment_enabled" class="note" style="color:var(--error); margin-top:10px;">Keep at least one payment option on.</div>
              </template>

              <!-- ── Bookboll admin: set the fee, then enable any mix ── -->
              <template v-else>
                <div class="commission-override-box" style="margin-bottom:14px;">
                  <div class="commission-override-title"><DollarSign :size="14" /> Platform fee (%)</div>
                  <div class="commission-override-row">
                    <div class="form-group" style="flex:1; max-width:200px; margin:0;">
                      <label class="form-label">Percentage (%)</label>
                      <input type="number" min="0" max="100" step="0.5" v-model="form.commission_rate" :placeholder="`Default ${effectiveCommissionRate}%`" />
                    </div>
                    <div class="commission-override-hint">
                      Bookboll's cut of every booking — collected as the online deposit, or out of a full online payment. Blank = country default (<strong>{{ effectiveCommissionRate }}%</strong>).
                    </div>
                  </div>
                </div>

                <label class="pay-opt" :class="{ on: pay.deposit_enabled }">
                  <input type="checkbox" v-model="pay.deposit_enabled" />
                  <div class="pay-opt-body">
                    <div class="pay-opt-title">Deposit online, rest upon customer arrival (offline)</div>
                    <div class="pay-opt-desc">The online deposit is Bookboll's fee; the provider collects the rest on arrival.</div>
                    <div class="ps-legend">
                      <span class="ps-key"><i class="fee"></i>{{ payCur }} {{ fmtPay(payExample.fee) }} online → Bookboll</span>
                      <span class="ps-key"><i class="venue"></i>{{ payCur }} {{ fmtPay(payExample.share) }} at the venue → provider</span>
                    </div>
                  </div>
                </label>
                <label class="pay-opt" :class="{ on: pay.online_payment_enabled }">
                  <input type="checkbox" v-model="pay.online_payment_enabled" @change="onAdminOnlineToggle" />
                  <div class="pay-opt-body">
                    <div class="pay-opt-title">Everything paid online</div>
                    <div class="pay-opt-desc">Paid in full at booking; the provider's share is credited to their wallet.</div>
                    <div class="ps-legend">
                      <span class="ps-key"><i class="you"></i>{{ payCur }} {{ fmtPay(payExample.share) }} → provider's wallet</span>
                      <span class="ps-key"><i class="fee"></i>{{ payCur }} {{ fmtPay(payExample.fee) }} → Bookboll</span>
                    </div>
                    <div v-if="locationIsBookbollOwned" class="pay-hint-locked">Bookboll-owned listing — no wallet needed.</div>
                  </div>
                </label>
                <label class="pay-opt" :class="{ on: pay.cash_on_arrival }">
                  <input type="checkbox" v-model="pay.cash_on_arrival" />
                  <div class="pay-opt-body">
                    <div class="pay-opt-title">Cash — everything at the venue</div>
                    <div class="pay-opt-desc">Nothing collected online; the customer pays the full amount in person.</div>
                  </div>
                </label>
                <div v-if="!pay.cash_on_arrival && !pay.online_payment_enabled && !pay.deposit_enabled" class="note" style="color:var(--error); margin-top:10px;">Enable at least one payment method.</div>
              </template>

              <div class="ps-example-note">Example amounts use {{ payExample.isReal ? 'this listing\'s slot price' : 'a sample booking' }} of <strong>{{ payCur }} {{ fmtPay(payExample.total) }}</strong>.</div>
              </template>
            </div>
            <!-- /marketplace payment options -->

            <!-- ── STEP 3 · Getting paid on your own website (collapsible) ── -->
            <div v-if="form.show_on_provider_site && !locationIsBookbollOwned" class="pp-pay site-pay">
              <button type="button" class="pp-chan pp-chan-toggle" :aria-expanded="payOpen.site" @click="payOpen.site = !payOpen.site">
                <span class="pp-step-n">{{ form.show_on_marketplace ? 3 : 2 }}</span>
                <Globe :size="15" />
                <b>Your own website payment methods</b>
                <span class="pp-chan-fee free">0% commission — it's all yours</span>
                <ChevronDown :size="16" class="pp-chan-chev" :class="{ rot: payOpen.site }" />
              </button>

              <!-- Collapsed: the facts at a glance, one click to edit -->
              <div v-if="!payOpen.site" class="pp-sum" role="button" @click="payOpen.site = true">
                <template v-if="sitePaySummary.length">
                  <span v-for="s in sitePaySummary" :key="s" class="pp-sum-chip"><Check :size="11" /> {{ s }}</span>
                </template>
                <span v-else class="pp-sum-none"><AlertTriangle :size="12" /> No payment option on — click to choose</span>
                <span v-if="!onlineAllowed" class="pp-sum-warn">payout method needed for full online payments &amp; deposit payouts</span>
                <span class="pp-sum-edit">Edit</span>
              </div>

              <template v-if="payOpen.site">
              <div class="pp-chan-sub">
                Bookings from your own website have <strong>no Bookboll fee</strong>. Pick how customers can pay — keep at least one on; if more than one is on, the customer picks at checkout.
              </div>

              <!-- Payout explainer lives in the deposit modal now — only the good news stays inline -->
              <div v-if="onlineAllowed" class="pay-status ok">
                <ShieldCheck :size="14" /> <span>Payouts ready — deposits and online payments here go straight to your wallet, with no Bookboll fee.</span>
              </div>

              <!-- Deposit + balance on arrival -->
              <label class="pay-opt" :class="{ on: pay.site_deposit_enabled, invalid: listingTriedSave && siteNoMethod }">
                <input type="checkbox" v-model="pay.site_deposit_enabled" @change="onSiteDepositToggle" />
                <div class="pay-opt-body">
                  <div class="pay-opt-title">Deposit online, rest upon customer arrival (offline) <span class="pay-chip rec">Recommended</span></div>
                  <div class="pay-opt-desc">A deposit confirms the booking (protects you from no-shows); the rest is paid at your venue. You choose the deposit size.</div>
                  <div v-if="pay.site_deposit_enabled" class="ext-value-row" style="margin-top:8px;">
                    <input type="number" min="10" max="100" v-model.number="pay.site_deposit_percentage" @change="onSiteDepositPctChange" class="ext-value-input" :class="{ 'input-invalid': siteDepositPctInvalid }" />
                    <span class="ext-value-unit" :class="{ 'unit-invalid': siteDepositPctInvalid }">% deposit online · minimum 10%</span>
                  </div>
                  <div class="ps-bar">
                    <span class="ps-seg" :class="onlineAllowed ? 'you' : 'warnseg'" :style="{ width: Math.max(payExample.sitePct, 8) + '%' }"></span>
                    <span class="ps-seg venue" :style="{ width: (100 - Math.max(payExample.sitePct, 8)) + '%' }"></span>
                  </div>
                  <div class="ps-legend">
                    <span v-if="onlineAllowed" class="ps-key"><i class="you"></i>{{ payCur }} {{ fmtPay(payExample.siteDep) }} online → <b>your wallet</b></span>
                    <span v-else class="ps-key"><i class="warnseg"></i>{{ payCur }} {{ fmtPay(payExample.siteDep) }} online → Bookboll (until payouts are set up)</span>
                    <span class="ps-key"><i class="venue"></i>{{ payCur }} {{ fmtPay(payExample.siteRest) }} at your venue → <b>yours</b></span>
                  </div>
                </div>
              </label>

              <!-- Full online -->
              <label class="pay-opt" :class="{ on: pay.site_online_enabled, dim: !onlineAllowed, invalid: listingTriedSave && siteNoMethod }">
                <input type="checkbox" v-model="pay.site_online_enabled" @change="onSiteOnlineToggle" />
                <div class="pay-opt-body">
                  <div class="pay-opt-title">Everything paid online <Lock v-if="!onlineAllowed" :size="12" class="pay-lock-ico" /></div>
                  <div class="pay-opt-desc">Paid in full at booking, straight to your wallet — nothing to collect on arrival.</div>
                  <div class="ps-bar"><span class="ps-seg you" style="width:100%"></span></div>
                  <div class="ps-legend">
                    <span class="ps-key"><i class="you"></i>{{ payCur }} {{ fmtPay(payExample.total) }} online → <b>your wallet</b> · 0% fee</span>
                  </div>
                </div>
              </label>

              <!-- Pay later (full cash) -->
              <label class="pay-opt" :class="{ on: pay.site_cash_enabled, invalid: listingTriedSave && siteNoMethod }">
                <input type="checkbox" v-model="pay.site_cash_enabled" @change="onSitePayLaterToggle" />
                <div class="pay-opt-body">
                  <div class="pay-opt-title">Pay at the venue <span class="pay-chip risk">No-show risk</span></div>
                  <div class="pay-opt-desc">No prepayment — easiest for customers, but a no-show costs you the revenue and the blocked timeslot.</div>
                  <div class="ps-bar"><span class="ps-seg venue" style="width:100%"></span></div>
                  <div class="ps-legend">
                    <span class="ps-key"><i class="venue"></i>{{ payCur }} {{ fmtPay(payExample.total) }} at your venue → <b>yours</b></span>
                  </div>
                </div>
              </label>

              <div v-if="siteNoMethod" class="note" style="color:var(--error); margin-top:10px;">Keep at least one payment option on for your own website.</div>
              </template>
            </div>
            <div class="section-footer">
              <button class="btn btn-secondary sf-cancel" @click="requestExitEditor">Cancel</button>
              <span v-if="savedFlag" class="saved"><Check :size="14" />Saved</span>
              <button class="btn btn-primary" @click="saveSection('addons')">{{ editingExisting ? 'Save &amp; Close' : 'Save &amp; Continue' }}</button>
            </div>
          </div>
        </section>

        <!-- CONNECTED ADD-ONS -->
        <section class="section-card" :class="{ open: openSection === 'addons' }">
          <button class="section-head" @click="openSection = openSection === 'addons' ? '' : 'addons'">
            <span class="tick" :class="{ done: addonsComplete }"><Check :size="12" /></span>
            <div class="section-title">
              <div>Connected Add-Ons <span class="opt-tag">Optional</span></div>
              <div class="section-sub">Extras customers can add to a booking (equipment, drinks…) — skip if you don't need any</div>
            </div>
            <ChevronDown :size="18" class="chev-icon" :class="{ rot: openSection === 'addons' }" />
          </button>
          <div v-if="openSection === 'addons'" class="section-body">
            <div class="conn-rows">
              <div v-for="(row, i) in connectedAddons" :key="i" class="conn-row">
                <span class="conn-row-ico"><PackagePlus :size="15" /></span>
                <PickerSelect
                  v-model="row.addon_id"
                  class="conn-row-picker"
                  :options="addonRowOptions(i)"
                  placeholder="Select an add-on…"
                  empty-text="No add-ons here yet"
                  create-label="Create a new add-on"
                  editable
                  deletable
                  @create="createAddonForRow(i)"
                  @edit="editAddon"
                  @delete="deleteAddon"
                />
                <button type="button" class="conn-row-x" @click="removeAddonRow(i)" title="Remove"><X :size="16" /></button>
              </div>
            </div>
            <div v-if="!connectedAddons.length" class="conn-hint">No add-ons connected yet. Add one below — customers can add it to their booking.</div>
            <div class="conn-actions">
              <button type="button" class="conn-add-btn" @click="addAddonRow"><Plus :size="15" />{{ connectedAddons.length ? 'Add another add-on' : 'Add an add-on' }}</button>
            </div>

            <!-- Separate sub-section: discount for extending into the next adjacent slot -->
            <div class="conn-ext-wrap">
              <div class="ext-block">
                <div class="ext-block-row">
                  <div class="ext-block-info">
                    <div class="ext-block-title"><Clock :size="15" />Session extension discount<span class="dp-rec">Recommended</span></div>
                    <div class="ext-block-sub">Let customers extend their booking into the next free adjacent slot at a reduced price.</div>
                  </div>
                  <label class="dp-switch" :title="extensionEnabled ? 'On' : 'Off'">
                    <input type="checkbox" :checked="extensionEnabled" @change="toggleExtension($event.target.checked)" />
                    <span class="dp-slider"></span>
                  </label>
                </div>
                <div v-if="extensionEnabled" class="ext-block-body">
                  <div class="ext-type-toggle">
                    <button type="button" :class="{ on: form.extension_pricing === 'percent' }" @click="form.extension_pricing = 'percent'">% discount</button>
                    <button type="button" :class="{ on: form.extension_pricing === 'flat' }" @click="form.extension_pricing = 'flat'">Flat discount</button>
                  </div>
                  <div class="ext-value-row">
                    <input type="number" min="0" v-model="form.extension_value" class="ext-value-input" />
                    <span class="ext-value-unit">{{ form.extension_pricing === 'flat' ? currencyForLocation(form.location_id) + ' off each extra slot' : '% off each extra slot' }}</span>
                  </div>
                  <div class="ext-value-row">
                    <input type="number" min="1" max="12" v-model.number="form.extension_max_slots" class="ext-value-input" />
                    <span class="ext-value-unit">discounted slot{{ Number(form.extension_max_slots) === 1 ? '' : 's' }} per chain (max)</span>
                  </div>
                  <div class="form-hint">Caps how many adjacent slots in one run get the discount. With <strong>{{ Math.max(1, Number(form.extension_max_slots) || 1) }}</strong>, picking 6pm discounts the next {{ Math.max(1, Number(form.extension_max_slots) || 1) }} slot{{ Math.max(1, Number(form.extension_max_slots) || 1) === 1 ? '' : 's' }}; anything beyond that is full price.</div>
                </div>
              </div>
            </div>

            <div class="section-footer">
              <button class="btn btn-secondary sf-cancel" @click="requestExitEditor">Cancel</button>
              <span v-if="savedFlag" class="saved"><Check :size="14" />Saved</span>
              <button v-if="!editingExisting" class="btn btn-secondary" @click="openSection = 'resources'">Skip</button>
              <button class="btn btn-primary" @click="saveSection('resources')">{{ editingExisting ? 'Save &amp; Close' : 'Save &amp; Continue' }}</button>
            </div>
          </div>
        </section>

        <!-- RESOURCE REQUIREMENTS -->
        <section class="section-card" :class="{ open: openSection === 'resources' }">
          <button class="section-head" @click="openSection = openSection === 'resources' ? '' : 'resources'">
            <span class="tick" :class="{ done: resourcesComplete }"><Check :size="12" /></span>
            <div class="section-title">
              <div>Resource Requirements <span class="opt-tag">Optional</span></div>
              <div class="section-sub">Shared resources a booking consumes (coach, hall…) — most listings don't need this</div>
            </div>
            <ChevronDown :size="18" class="chev-icon" :class="{ rot: openSection === 'resources' }" />
          </button>
          <div v-if="openSection === 'resources'" class="section-body">
            <div class="conn-rows">
              <div v-for="(row, i) in connectedResources" :key="i" class="conn-row">
                <span class="conn-row-ico res"><Boxes :size="15" /></span>
                <PickerSelect
                  v-model="row.resource_id"
                  class="conn-row-picker"
                  :options="resourceRowOptions(i)"
                  placeholder="Select a resource…"
                  empty-text="No resources here yet"
                  create-label="Create a new resource"
                  editable
                  deletable
                  @create="createResourceForRow(i)"
                  @edit="editResource"
                  @delete="deleteResource"
                />
                <div class="conn-row-qty">
                  <span class="conn-item-qty-lbl">Qty</span>
                  <button type="button" class="conn-qty-btn" :disabled="(Number(row.quantity)||1) <= 1" @click="row.quantity = Math.max(1, (Number(row.quantity)||1) - 1)"><Minus :size="13" /></button>
                  <input type="number" v-model.number="row.quantity" min="1" class="conn-qty-input" />
                  <button type="button" class="conn-qty-btn" @click="row.quantity = (Number(row.quantity)||1) + 1"><Plus :size="13" /></button>
                </div>
                <button type="button" class="conn-row-x" @click="removeResourceRow(i)" title="Remove"><X :size="16" /></button>
              </div>
            </div>
            <div v-if="!connectedResources.length" class="conn-hint">No resources required. Add one below to reserve it for every booking of this listing.</div>
            <div class="conn-actions">
              <button type="button" class="conn-add-btn" @click="addResourceRow"><Plus :size="15" />{{ connectedResources.length ? 'Add another resource' : 'Require a resource' }}</button>
            </div>
            <div class="section-footer">
              <button class="btn btn-secondary sf-cancel" @click="requestExitEditor">Cancel</button>
              <span v-if="savedFlag" class="saved"><Check :size="14" />Saved</span>
              <button v-if="!editingExisting" class="btn btn-secondary" @click="openSection = 'images'">Skip</button>
              <button class="btn btn-primary" @click="saveSection('images')">{{ editingExisting ? 'Save &amp; Close' : 'Save &amp; Continue' }}</button>
            </div>
          </div>
        </section>

        <!-- IMAGES -->
        <section class="section-card" :class="{ open: openSection === 'images' }">
          <button class="section-head" @click="openSection = openSection === 'images' ? '' : 'images'">
            <span class="tick" :class="{ done: imagesComplete }"><Check :size="12" /></span>
            <div class="section-title">
              <div>Images</div>
              <div class="section-sub">Upload one or more photos — the first is the cover</div>
            </div>
            <ChevronDown :size="18" class="chev-icon" :class="{ rot: openSection === 'images' }" />
          </button>
          <div v-if="openSection === 'images'" class="section-body">

            <!-- Gallery grid (first image is the cover) -->
            <div v-if="(form.photos || []).length" class="img-grid">
              <div v-for="(src, i) in form.photos" :key="i" class="img-tile">
                <img :src="src" alt="" />
                <span v-if="i === 0" class="img-cover-badge">Cover</span>
                <button type="button" class="img-remove" @click="removeListingImage(i)" title="Remove"><X :size="13" /></button>
              </div>
            </div>

            <!-- Drop zone (always available — add as many as you like) -->
            <div class="img-drop-zone"
              :class="{ 'drop-active': imgDropActive, 'uploading': imageUploading }"
              @dragover.prevent="imgDropActive = true"
              @dragleave.prevent="imgDropActive = false"
              @drop.prevent="onImgDrop"
              @click="imgFileInput.click()">
              <input ref="imgFileInput" type="file" accept="image/*" multiple class="hidden-file-input" @change="onImgFileChange" />
              <template v-if="imageUploading">
                <RefreshCw :size="28" class="spin-icon" />
                <span>Compressing &amp; uploading…</span>
              </template>
              <template v-else>
                <Upload :size="28" />
                <span class="drop-label">Drag &amp; drop images here, or <strong>click to browse</strong></span>
                <span class="drop-hint">JPG, PNG, WEBP · multiple allowed · compressed to &lt;3 MB automatically</span>
              </template>
            </div>

            <!-- Upload error -->
            <div v-if="imageUploadError" class="img-upload-error">{{ imageUploadError }}</div>

            <!-- URL fallback -->
            <details class="img-url-fallback" style="margin-top:12px;">
              <summary>or paste an image URL</summary>
              <div class="img-add-row">
                <input v-model="newListingImageUrl" placeholder="https://example.com/image.jpg" @keyup.enter="addListingImageUrl" />
                <button type="button" class="btn btn-secondary btn-sm" @click="addListingImageUrl"><Plus :size="13" />Add</button>
              </div>
            </details>

            <div class="section-footer">
              <button class="btn btn-secondary sf-cancel" @click="requestExitEditor">Cancel</button>
              <button v-if="formDirty" class="btn btn-secondary" @click="saveListingDraftNow">Save draft</button>
              <span v-if="savedFlag" class="saved"><Check :size="14" />Saved</span>
              <button class="btn btn-primary" @click="saveAndClose">{{ editingExisting ? 'Save &amp; Close' : 'Create Listing' }}</button>
            </div>

          </div>
        </section>
        </div><!-- /.sections -->
      </div><!-- /.bb-drawer-body -->
    </div><!-- /.bb-drawer -->
    </div><!-- /.bb-drawer-backdrop -->
    </teleport>

    <!-- LOCATION MODAL -->
    <!-- Shared location form (new / edit) -->
    <LocationModal :open="locModal" :editing="locEditing" @close="locModal=false; locFromListingForm=false" @saved="onLocationSaved" />

    <!-- MARKETPLACE-OFF CONFIRM (icon-led) -->
    <div v-if="mktConfirm" class="modal-backdrop" style="z-index:3600" @click.self="keepOnMarketplace">
      <div class="modal mkt-modal">
        <div class="mkt-icon"><Store :size="28" /></div>
        <div class="mkt-title">Hide from Bookboll Marketplace?</div>
        <div class="mkt-points">
          <div class="mkt-point neg"><TrendingDown :size="18" /><span>Fewer bookings &amp; revenue</span></div>
          <div class="mkt-point neg"><Globe :size="18" /><span>Lose Bookboll's global reach</span></div>
          <div class="mkt-point pos"><Check :size="18" /><span>Still live on your own website</span></div>
        </div>
        <div class="mkt-actions">
          <button class="btn btn-primary mkt-keep" @click="keepOnMarketplace"><Store :size="15" />Keep on marketplace</button>
          <button class="mkt-anyway" @click="confirmMarketplaceOff">Hide anyway</button>
        </div>
      </div>
    </div>

    <!-- NEED A LOCATION FIRST (no locations created yet) -->
    <div v-if="needLocationModal" class="modal-backdrop" style="z-index:3600" @click.self="needLocationModal=false">
      <div class="modal need-loc-modal">
        <div class="nlm-icon"><Landmark :size="26" /></div>
        <div class="nlm-title">Create a location first</div>
        <p class="nlm-text">Listings live inside a location (your venue, club or site). Add your first location, then you can create venue listings inside it.</p>
        <div class="nlm-steps">
          <div class="nlm-step"><span class="nlm-step-n">1</span><div><strong>Add a location</strong><span>Name, address &amp; opening hours</span></div></div>
          <div class="nlm-step muted"><span class="nlm-step-n">2</span><div><strong>Add venue listings</strong><span>Bookable courts, studios &amp; more</span></div></div>
        </div>
        <div class="nlm-actions">
          <button class="btn btn-secondary" @click="needLocationModal=false">Not now</button>
          <button class="btn btn-primary" @click="needLocationModal=false; openNewLoc()"><Plus :size="15" />Create location</button>
        </div>
      </div>
    </div>

    <!-- OWN-SITE DEPOSIT, NO PAYOUT METHOD YET: explain where the money goes + 3 ways forward -->
    <div v-if="siteDepositModal" class="modal-backdrop" style="z-index:3600" @click.self="siteDepositModal=false">
      <div class="modal sd-modal">
        <button class="sd-x" @click="siteDepositModal=false" aria-label="Close"><X :size="18" /></button>
        <div class="sd-icon"><Wallet :size="26" /></div>
        <div class="sd-title">Where do your deposits go?</div>
        <div class="sd-facts">
          <div class="sd-fact warn"><AlertTriangle :size="15" /><span>No payout method yet — online deposits <b>become Bookboll's platform fee</b>, not held for you.</span></div>
          <div class="sd-fact ok"><ShieldCheck :size="15" /><span>Money paid <b>at your venue</b> is always 100% yours.</span></div>
        </div>
        <div class="sd-actions">
          <button class="sd-btn" @click="siteDepositConnect">
            <Wallet :size="16" />
            <span><span class="sd-btn-title">Connect my payout method</span><small>Deposits land in your wallet + unlocks "Everything paid online"</small></span>
          </button>
          <button class="sd-btn" @click="siteDepositAccept">
            <Check :size="16" />
            <span><span class="sd-btn-title">Turn deposits on anyway <em class="pay-chip rec">Recommended</em></span><small>I understand deposits are Bookboll's fee until I connect payouts</small></span>
          </button>
          <button class="sd-btn" @click="siteDepositPayAtVenue">
            <Landmark :size="16" />
            <span><span class="sd-btn-title">Pay at the venue instead</span><small>No online payments — the full amount is collected at your venue</small></span>
          </button>
        </div>
      </div>
    </div>

    <!-- CHANGE-LOCATION CONFIRM (connected add-ons/resources will detach) -->
    <div v-if="locChangeModal" class="modal-backdrop" style="z-index:3600" @click.self="cancelLocationChange">
      <div class="modal loc-change-modal">
        <div class="lcm-icon"><Landmark :size="26" /></div>
        <div class="lcm-title">Move this listing to {{ locationName(locChangeModal.newLoc) }}?</div>
        <p class="lcm-text">Add-ons &amp; resources only work at their own location. These belong to <strong>{{ locationName(locChangeModal.prevLoc) }}</strong> and will be removed from this listing:</p>
        <div class="lcm-list">
          <div v-for="a in locChangeModal.addons" :key="'a'+a.id" class="lcm-item">
            <span class="lcm-item-ico addon"><PackagePlus :size="15" /></span>
            <span class="lcm-item-name">{{ a.name }}</span>
            <span class="lcm-item-tag">Add-on</span>
          </div>
          <div v-for="r in locChangeModal.resources" :key="'r'+r.id" class="lcm-item">
            <span class="lcm-item-ico res"><Boxes :size="15" /></span>
            <span class="lcm-item-name">{{ r.name }}</span>
            <span class="lcm-item-tag">Resource</span>
          </div>
        </div>
        <p class="lcm-hint">Nothing is deleted — you can re-add matching items from {{ locationName(locChangeModal.newLoc) }} afterwards.</p>
        <div class="lcm-actions">
          <button class="btn btn-secondary" @click="cancelLocationChange">Keep current location</button>
          <button class="btn btn-danger" @click="confirmLocationChange">Move &amp; remove {{ locChangeModal.addons.length + locChangeModal.resources.length }} item{{ (locChangeModal.addons.length + locChangeModal.resources.length) !== 1 ? 's' : '' }}</button>
        </div>
      </div>
    </div>

    <!-- PAUSE ELIGIBILITY MODAL -->
    <div v-if="pauseModal" class="modal-backdrop" style="z-index:3400" @click.self="pauseModal=false">
      <div class="modal" style="max-width: 500px;">
        <div class="modal-header">
          <div class="modal-title">Pause Eligibility</div>
          <button class="btn-icon" @click="pauseModal=false"><X :size="18" /></button>
        </div>
        <div class="modal-body">
          <div class="pause-info">
            <AlertTriangle :size="18" />
            <div>This will mark the <strong>{{ pauseTarget?.table === 'locations' ? 'location' : 'listing' }}</strong> as ineligible and notify the provider with your note. The provider must edit and resubmit for your approval before it goes live again.</div>
          </div>
          <div class="form-group" style="margin-top:14px;">
            <label class="form-label">Note for provider <span class="req">*</span></label>
            <textarea v-model="pauseNote" rows="4" placeholder="Explain what needs to be fixed or updated..."></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" @click="pauseModal=false">Cancel</button>
          <button class="btn btn-danger" :disabled="!pauseNote.trim()" @click="savePause"><PauseCircle :size="15" />Pause &amp; Notify</button>
        </div>
      </div>
    </div>

    <!-- NEW ADD-ON MODAL -->
    <div v-if="newAddonModal" class="modal-backdrop" style="z-index:3400" @click.self="newAddonModal=false">
      <div class="modal" style="max-width: 560px;">
        <div class="modal-header">
          <div class="modal-title">{{ addonEditingId ? 'Edit Add-On' : 'New Add-On' }}</div>
          <button class="btn-icon" @click="newAddonModal=false"><X :size="18" /></button>
        </div>
        <div class="modal-body">
          <div class="form-group"><label class="form-label">Location <span class="req">*</span><InfoTip text="The add-on is tied to this location and only appears for listings at the same location." /></label>
            <select v-model="newAddonForm.location_id">
              <option value="">Select location</option>
              <option v-for="lo in listingFormLocations" :key="lo.id" :value="lo.id">{{ lo.name }}</option>
            </select>
          </div>
          <div class="form-group"><label class="form-label">Name <span class="req">*</span></label><input v-model="newAddonForm.name" placeholder="e.g. Water bottle, Coaching, Racket rental" /></div>
          <div class="form-group"><label class="form-label">Description</label><textarea v-model="newAddonForm.description" rows="2" placeholder="Short description customers see when adding it"></textarea></div>

          <div class="form-group">
            <label class="form-label">Image</label>
            <div v-if="newAddonForm.image_url" class="img-upload-preview">
              <img :src="newAddonForm.image_url" alt="Add-on image" class="img-thumb" />
              <button class="img-remove-btn" @click="newAddonForm.image_url = ''; addonImgError = ''" title="Remove image"><X :size="14" /></button>
            </div>
            <div v-else
              class="img-drop-zone"
              :class="{ 'drop-active': addonImgDropActive, 'uploading': addonImgUploading }"
              @dragover.prevent="addonImgDropActive = true"
              @dragleave.prevent="addonImgDropActive = false"
              @drop.prevent="onAddonImgDrop"
              @click="addonFileInput.click()">
              <input ref="addonFileInput" type="file" accept="image/*" class="hidden-file-input" @change="onAddonImgFileChange" />
              <template v-if="addonImgUploading">
                <RefreshCw :size="24" class="spin-icon" />
                <span>Compressing &amp; uploading…</span>
              </template>
              <template v-else>
                <Upload :size="24" />
                <span class="drop-label">Drag &amp; drop an image, or <strong>click to browse</strong></span>
              </template>
            </div>
            <div v-if="addonImgError" class="img-upload-error">{{ addonImgError }}</div>
          </div>

          <div class="form-group"><label class="form-label">Price ({{ currencyForLocation(newAddonForm.location_id) }})</label><input type="number" min="0" v-model="newAddonForm.price" placeholder="0" /></div>
          <div class="form-hint">A simple add-on product — customers pick a quantity at checkout and pay price × quantity, once per booking.</div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" @click="newAddonModal=false">Cancel</button>
          <button class="btn btn-primary" :disabled="!newAddonForm.location_id || !newAddonForm.name.trim()" @click="saveNewAddon">{{ addonEditingId ? 'Save' : 'Create' }}</button>
        </div>
      </div>
    </div>

    <!-- NEW RESOURCE MODAL -->
    <div v-if="newResourceModal" class="modal-backdrop" style="z-index:3400" @click.self="newResourceModal=false">
      <div class="modal" style="max-width: 560px;">
        <div class="modal-header">
          <div class="modal-title">{{ resourceEditingId ? 'Edit Resource' : 'New Resource' }}</div>
          <button class="btn-icon" @click="newResourceModal=false"><X :size="18" /></button>
        </div>
        <div class="modal-body">
          <div class="form-group">
            <label class="form-label">Location <span class="req">*</span><InfoTip text="The resource is tied to this location and only appears for listings at the same location." /></label>
            <select v-model="newResourceForm.location_id">
              <option value="">Select location</option>
              <option v-for="lo in listingFormLocations" :key="lo.id" :value="lo.id">{{ lo.name }}</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Resource Name <span class="req">*</span></label>
            <input v-model="newResourceForm.name" placeholder="e.g. Indoor Court A" />
          </div>
          <div class="form-group">
            <label class="form-label">Total Quantity <span class="req">*</span></label>
            <input type="number" min="1" v-model="newResourceForm.quantity" />
          </div>
          <div class="form-group">
            <label class="form-label">Unit Type <span class="form-hint">optional</span></label>
            <input v-model="newResourceForm.type" placeholder="e.g. Court, Field, Room" />
          </div>
          <div class="form-group">
            <label class="form-label">Status <span class="req">*</span></label>
            <select v-model="newResourceForm.status"><option value="active">Active</option><option value="inactive">Inactive</option></select>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" @click="newResourceModal=false">Cancel</button>
          <button class="btn btn-primary" :disabled="!newResourceForm.location_id || !newResourceForm.name.trim()" @click="saveNewResource">{{ resourceEditingId ? 'Save' : 'Create' }}</button>
        </div>
      </div>
    </div>

    <!-- PAYMENT ACCOUNT MODAL -->
    <div v-if="payAcctModal" class="modal-backdrop" style="z-index:3400" @click.self="payAcctModal=false">
      <div class="modal" style="max-width: 520px;">
        <div class="modal-header">
          <div class="modal-title">{{ payAcctEditing ? 'Manage Payment Account' : 'Connect Payment Account' }}</div>
          <button class="btn-icon" @click="payAcctModal=false"><X :size="18" /></button>
        </div>
        <div class="modal-body">
          <div class="acct-modal-icon"><Landmark :size="26" /></div>
          <p style="font-size:13px; color:var(--text-muted); margin:0 0 18px;">This account will receive online payouts for bookings at your facility. Only one account can be connected at a time.</p>
          <div class="form-group"><label class="form-label">Account Holder Name</label><input v-model="payAcctForm.account_holder" placeholder="e.g. Ahmed Al Mansouri" /></div>
          <div class="form-group"><label class="form-label">Bank Name</label><input v-model="payAcctForm.bank_name" placeholder="e.g. Emirates NBD" /></div>
          <div class="form-group"><label class="form-label">IBAN</label><input v-model="payAcctForm.iban" placeholder="AE07 0331 2345 6789 0123 456" /></div>
          <div class="grid grid-2">
            <div class="form-group"><label class="form-label">SWIFT / BIC</label><input v-model="payAcctForm.swift" placeholder="EBILAEAD" /></div>
            <div class="form-group"><label class="form-label">Currency</label>
              <select v-model="payAcctForm.currency">
                <option v-for="c in ISO_CURRENCIES" :key="c.code" :value="c.code">{{ c.code }} — {{ c.name }}</option>
              </select>
            </div>
          </div>
        </div>
        <div class="modal-footer" style="justify-content:space-between;">
          <button v-if="payAcctEditing" class="btn btn-sm btn-danger" @click="deletePayAcct"><Trash2 :size="14" />Disconnect</button>
          <div v-else></div>
          <div style="display:flex; gap:8px;">
            <button class="btn btn-secondary" @click="payAcctModal=false">Cancel</button>
            <button class="btn btn-primary" :disabled="!payAcctForm.account_holder || !payAcctForm.bank_name || !payAcctForm.iban" @click="savePayAcct">
              {{ payAcctEditing ? 'Save changes' : 'Connect account' }}
            </button>
          </div>
        </div>
      </div>
    </div>

    <PayoutSetupModal :open="payoutModal" purpose="bookings" :country-id="formCountryId" @close="payoutModal = false" @fallback="payoutFallbackDeposit" />

    <!-- Post-submit status: where the listing stands, per placement -->
    <Teleport to="body">
      <div v-if="submittedInfo" class="ls-sub-backdrop" @click.self="submittedInfo = null">
        <div class="ls-sub" role="dialog" aria-modal="true">
          <div class="ls-sub-badge"><Check :size="26" /></div>
          <h3 class="ls-sub-title">{{ submittedInfo.mode === 'content' ? 'Saved — you stay live' : (submittedInfo.wasEdit ? 'Changes submitted!' : 'Listing submitted!') }}</h3>
          <div class="ls-sub-name">{{ submittedInfo.name }}</div>

          <div class="ls-sub-rows">
            <div v-if="submittedInfo.onSite" class="ls-sub-row">
              <span class="ls-sub-ic ok"><Globe :size="17" /></span>
              <span class="ls-sub-l">Your website</span>
              <span class="ls-sub-chip live"><i></i> Live now</span>
            </div>
            <div v-if="submittedInfo.onMarketplace" class="ls-sub-row">
              <span class="ls-sub-ic" :class="submittedInfo.mode === 'content' ? 'ok' : 'wait'"><Store :size="17" /></span>
              <span class="ls-sub-l">Bookboll Marketplace</span>
              <span v-if="submittedInfo.mode === 'content'" class="ls-sub-chip live"><i></i> Live · new content in review</span>
              <span v-else class="ls-sub-chip wait"><Clock :size="11" /> Under review</span>
            </div>
          </div>

          <div v-if="submittedInfo.mode === 'content'" class="ls-sub-note">
            <Bell :size="13" /> Only your new title/description/photos need approval — your current ones stay live until then.
          </div>
          <div v-else-if="submittedInfo.onMarketplace" class="ls-sub-note">
            <Bell :size="13" /> We'll notify you — approved, or changes requested.
          </div>

          <button type="button" class="ls-sub-btn" @click="submittedInfo = null">Got it</button>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<style scoped>
/* ── Post-submit status modal ──────────────────────────────────────────────── */
.ls-sub-backdrop { position: fixed; inset: 0; z-index: 4400; background: rgba(15,23,42,.55); display: flex; align-items: center; justify-content: center; padding: 16px; }
.ls-sub { width: 100%; max-width: 400px; background: var(--surface, #ffffff); border-radius: 18px; padding: 26px 22px 20px; text-align: center; box-shadow: 0 24px 64px rgba(15,23,42,.32); }
.ls-sub-badge { width: 58px; height: 58px; margin: 0 auto 12px; border-radius: 50%; background: #f0fdf4; border: 1.5px solid #bbf7d0; color: #16a34a; display: flex; align-items: center; justify-content: center; }
.ls-sub-title { font-size: 19px; font-weight: 800; color: var(--text, #0f172a); margin: 0 0 2px; }
.ls-sub-name { font-size: 13px; color: var(--text-muted, #64748b); margin-bottom: 16px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.ls-sub-rows { display: flex; flex-direction: column; gap: 8px; text-align: left; margin-bottom: 12px; }
.ls-sub-row { display: flex; align-items: center; gap: 10px; padding: 11px 12px; border: 1px solid var(--border, #e2e8f0); border-radius: 12px; background: var(--bg, #f8fafc); }
.ls-sub-ic { width: 34px; height: 34px; border-radius: 10px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.ls-sub-ic.ok { background: #f0fdf4; color: #16a34a; }
.ls-sub-ic.wait { background: #fffbeb; color: #b45309; }
.ls-sub-l { flex: 1; min-width: 0; font-size: 13.5px; font-weight: 700; color: var(--text, #0f172a); }
.ls-sub-chip { display: inline-flex; align-items: center; gap: 5px; padding: 4px 10px; border-radius: 999px; font-size: 11.5px; font-weight: 800; flex-shrink: 0; }
.ls-sub-chip.live { background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; }
.ls-sub-chip.live i { width: 7px; height: 7px; border-radius: 50%; background: #22c55e; }
.ls-sub-chip.wait { background: #fffbeb; color: #b45309; border: 1px solid #fde68a; }
.ls-sub-note { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; color: var(--text-muted, #64748b); margin-bottom: 16px; }
.ls-sub-btn { width: 100%; padding: 12px; border: none; border-radius: 11px; background: var(--primary, #0f766e); color: #fff; font-size: 14.5px; font-weight: 800; cursor: pointer; }
.ls-sub-btn:hover { filter: brightness(1.06); }

.assigned-chip { display: inline-flex; align-items: center; gap: 6px; padding: 8px 12px; background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; border-radius: 8px; font-size: 13px; margin-bottom: 14px; }
.assigned-chip.bookboll-chip { background: #f0f9ff; border-color: #bae6fd; color: #0369a1; }

/* Location modal tabs */
.loc-tabs { display: flex; border-bottom: 1px solid var(--border); margin: 0 -24px 20px; padding: 0 24px; gap: 2px; }
.loc-tab { padding: 10px 16px; font-size: 13px; font-weight: 500; color: var(--text-muted); border-bottom: 2px solid transparent; margin-bottom: -1px; background: none; cursor: pointer; transition: all .15s; }
.loc-tab.active { color: var(--primary); border-bottom-color: var(--primary); font-weight: 600; }
.loc-tab:hover:not(.active) { color: var(--text); background: var(--bg); border-radius: 6px 6px 0 0; }

/* Out-of-bounds warning */
.oob-warning { display: flex; gap: 10px; align-items: flex-start; padding: 12px 14px; background: #fff7ed; border: 1px solid #fed7aa; border-radius: 8px; font-size: 13px; color: #9a3412; line-height: 1.5; margin-bottom: 14px; }

/* Form hint text */
.form-hint { font-size: 11.5px; color: var(--text-muted); margin-top: 5px; line-height: 1.4; }

/* Commission override (admin only) */
.commission-override-box {
  margin-top: 18px; padding: 14px 16px;
  background: #f8fafc; border: 1px solid var(--border, #e2e8f0); border-radius: 10px;
}
.commission-override-title {
  display: flex; align-items: center; gap: 6px;
  font-size: 12px; font-weight: 700; color: var(--text-muted);
  text-transform: uppercase; letter-spacing: .05em; margin-bottom: 12px;
}
.commission-override-row { display: flex; align-items: flex-end; gap: 16px; flex-wrap: wrap; }
.commission-override-hint { font-size: 13px; color: var(--text-muted); line-height: 1.5; flex: 1; padding-bottom: 2px; }

/* ── Dynamic pricing header + toggle ───────────────────────────── */
.dp-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-top: 4px; }
.dp-title { display: flex; align-items: center; gap: 8px; font-size: 15px; font-weight: 700; color: var(--text); }
.dp-rec { font-size: 10.5px; font-weight: 700; letter-spacing: .03em; text-transform: uppercase; color: var(--primary); background: #f0fdfa; border: 1px solid #99f6e4; border-radius: 999px; padding: 2px 8px; line-height: 1.4; }

/* Booking colour picker — matches the Membership Program form's swatches */
.pg-color-row { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
.pg-swatch { width: 30px; height: 30px; border-radius: 8px; border: 2px solid #fff; box-shadow: 0 0 0 1px var(--border); cursor: pointer; padding: 0; transition: transform .1s, box-shadow .1s; position: relative; }
.pg-swatch:hover { transform: scale(1.08); }
.pg-swatch.active { box-shadow: 0 0 0 2px #fff, 0 0 0 4px var(--primary, #0891b2); }
.pg-swatch-custom { display: inline-flex; align-items: center; justify-content: center; overflow: hidden; background-image: conic-gradient(#ef4444,#f59e0b,#10b981,#3b82f6,#a855f7,#ef4444); }
.pg-swatch-custom input[type="color"] { opacity: 0; width: 100%; height: 100%; cursor: pointer; border: none; padding: 0; }
.dp-q {
  display: inline-flex; align-items: center; justify-content: center;
  width: 17px; height: 17px; border-radius: 50%;
  background: var(--border, #e2e8f0); color: #475569; font-size: 11px; font-weight: 700;
  cursor: help; user-select: none;
}
.dp-q:hover { background: #cbd5e1; }
.dp-sub { font-size: 12.5px; color: var(--text-muted); margin: 4px 0 14px; line-height: 1.5; }
.dp-switch { position: relative; display: inline-block; width: 42px; height: 24px; flex-shrink: 0; cursor: pointer; }
.dp-switch input { opacity: 0; width: 0; height: 0; }
.dp-slider { position: absolute; inset: 0; background: #cbd5e1; border-radius: 999px; transition: background .15s; }
.dp-slider::before {
  content: ''; position: absolute; height: 18px; width: 18px; left: 3px; top: 3px;
  background: var(--surface, #ffffff); border-radius: 50%; transition: transform .15s; box-shadow: 0 1px 2px rgba(0,0,0,.2);
}
.dp-switch input:checked + .dp-slider { background: var(--primary); }
.dp-switch input:checked + .dp-slider::before { transform: translateX(18px); }

/* ── Placement section (where the listing appears) ─────────────── */
.pl-opt { display: flex; align-items: center; gap: 14px; padding: 14px 16px; border: 1.5px solid var(--border); border-radius: 12px; margin-bottom: 12px; transition: border-color .15s, background .15s; }
.pl-opt.on { border-color: var(--primary); background: #f0fdfa; }
.pl-opt-icon { width: 44px; height: 44px; border-radius: 11px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.pl-opt-icon.mkt { background: #ccfbf1; color: #0f766e; }
.pl-opt-icon.site { background: #ede9fe; color: #6d28d9; }
.pl-opt-body { flex: 1; min-width: 0; }
.pl-opt-name { font-size: 14.5px; font-weight: 700; color: var(--text); display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.pl-open { display: inline-flex; align-items: center; justify-content: center; padding: 3px; border-radius: 6px; color: var(--primary); opacity: .85; }
.pl-open:hover { opacity: 1; background: #ccfbf1; }
.pl-opt-desc { font-size: 12.5px; color: var(--text-muted); margin-top: 3px; line-height: 1.5; }
.placement-rec { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; padding: 1px 7px; }
.placement-warn { display: flex; align-items: center; gap: 7px; margin-top: 2px; padding: 9px 12px; background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; font-size: 12.5px; color: #92400e; }
/* Payment options nested under a placement toggle */
.pp-pay { margin: 16px 0 0; padding: 15px 16px 14px; border: 1px solid var(--border); border-left: 3px solid var(--primary); border-radius: 0 12px 12px 0; background: var(--bg); }

/* Marketplace-off confirm modal (icon-led) */
.mkt-modal { max-width: 380px; text-align: center; padding: 26px 24px 20px; }
.mkt-icon { width: 60px; height: 60px; border-radius: 50%; background: #fef2f2; color: #dc2626; display: flex; align-items: center; justify-content: center; margin: 0 auto 14px; }
.mkt-title { font-size: 18px; font-weight: 700; color: var(--text); margin-bottom: 18px; }
.mkt-points { display: flex; flex-direction: column; gap: 11px; text-align: left; margin-bottom: 22px; }
.mkt-point { display: flex; align-items: center; gap: 11px; font-size: 14px; font-weight: 600; }
.mkt-point svg { flex-shrink: 0; }
.mkt-point.neg { color: #b91c1c; }
.mkt-point.pos { color: #15803d; }
.mkt-actions { display: flex; flex-direction: column; gap: 6px; }
.mkt-keep { width: 100%; justify-content: center; }
.mkt-anyway { background: transparent; border: 0; color: var(--text-muted); font-size: 13px; font-weight: 600; padding: 9px; cursor: pointer; border-radius: 8px; }
.mkt-anyway:hover { color: #dc2626; background: #fef2f2; }

/* Change-location confirm modal */
.loc-change-modal { max-width: 460px; width: 100%; padding: 24px; text-align: center; }
.lcm-icon { width: 54px; height: 54px; border-radius: 14px; background: #fff7ed; color: #c2410c; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 14px; }
.lcm-title { font-size: 18px; font-weight: 700; color: var(--text); margin-bottom: 8px; }
.lcm-text { font-size: 13.5px; color: var(--text-muted); line-height: 1.55; margin: 0 0 14px; }
.lcm-list { display: flex; flex-direction: column; gap: 8px; text-align: left; margin-bottom: 14px; max-height: 220px; overflow-y: auto; }
.lcm-item { display: flex; align-items: center; gap: 10px; padding: 9px 12px; background: #f8fafc; border: 1px solid var(--border); border-radius: 10px; }
.lcm-item-ico { width: 28px; height: 28px; border-radius: 8px; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }
.lcm-item-ico.addon { background: #eef2ff; color: #4f46e5; }
.lcm-item-ico.res { background: #ecfeff; color: #0e7490; }
.lcm-item-name { flex: 1; min-width: 0; font-weight: 600; font-size: 13.5px; color: var(--text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.lcm-item-tag { font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: var(--text-muted); background: var(--surface, #ffffff); border: 1px solid var(--border); border-radius: 999px; padding: 2px 8px; flex-shrink: 0; }
.lcm-hint { font-size: 12px; color: var(--text-light); margin: 0 0 18px; }
.lcm-actions { display: flex; gap: 10px; }
.lcm-actions .btn { flex: 1; justify-content: center; }

/* Need-a-location-first modal */
.need-loc-modal { max-width: 440px; width: 100%; padding: 26px 24px 22px; text-align: center; }
.nlm-icon { width: 56px; height: 56px; border-radius: 16px; background: #f0fdfa; color: var(--primary); display: inline-flex; align-items: center; justify-content: center; margin-bottom: 14px; }
.nlm-title { font-size: 18px; font-weight: 700; color: var(--text); margin-bottom: 8px; }
.nlm-text { font-size: 13.5px; color: var(--text-muted); line-height: 1.55; margin: 0 0 18px; }
.nlm-steps { display: flex; flex-direction: column; gap: 10px; text-align: left; margin-bottom: 20px; }
.nlm-step { display: flex; align-items: center; gap: 12px; }
.nlm-step.muted { opacity: 0.55; }
.nlm-step-n { width: 26px; height: 26px; border-radius: 50%; background: var(--primary); color: #fff; font-size: 13px; font-weight: 700; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }
.nlm-step.muted .nlm-step-n { background: var(--text-muted); }
.nlm-step strong { display: block; font-size: 13.5px; color: var(--text); }
.nlm-step span { font-size: 12px; color: var(--text-muted); }
.nlm-actions { display: flex; gap: 10px; }
.nlm-actions .btn { flex: 1; justify-content: center; }

/* Own-site deposit → payout explainer modal */
.sd-modal { position: relative; max-width: 480px; width: 100%; padding: 26px 24px 22px; text-align: center; }
.sd-x { position: absolute; top: 12px; right: 12px; width: 32px; height: 32px; border-radius: 50%; border: none; background: #f1f5f9; color: var(--text-muted, #64748b); display: flex; align-items: center; justify-content: center; cursor: pointer; }
.sd-x:hover { background: var(--border, #e2e8f0); color: #0f172a; }
.sd-icon { width: 52px; height: 52px; border-radius: 14px; background: #fff7ed; color: #d97706; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 12px; }
.sd-title { font-size: 17px; font-weight: 800; color: var(--text); margin-bottom: 12px; }
.sd-facts { display: flex; flex-direction: column; gap: 8px; margin-bottom: 16px; }
.sd-fact { display: flex; align-items: flex-start; gap: 8px; text-align: left; font-size: 12.5px; line-height: 1.5; border-radius: 10px; padding: 9px 11px; }
.sd-fact svg { flex-shrink: 0; margin-top: 1px; }
.sd-fact.warn { color: #9a3412; background: #fff7ed; border: 1px solid #fed7aa; }
.sd-fact.ok { color: #166534; background: #f0fdf4; border: 1px solid #bbf7d0; }
.sd-btn-title { display: inline-flex; align-items: center; gap: 6px; flex-wrap: wrap; font-size: 13.5px; font-weight: 700; color: var(--text); }
.sd-btn-title .pay-chip { margin-left: 0; vertical-align: baseline; font-style: normal; }
.sd-actions { display: flex; flex-direction: column; gap: 9px; }
.sd-btn { display: flex; align-items: flex-start; gap: 10px; padding: 11px 14px; text-align: left; border: 1px solid var(--border); border-radius: 11px; background: var(--surface, #ffffff); cursor: pointer; transition: all .15s; }
.sd-btn:hover { border-color: #cbd5e1; background: #f8fafc; }
.sd-btn svg { flex-shrink: 0; margin-top: 2px; color: var(--text-muted); }
.sd-btn > span { display: flex; flex-direction: column; gap: 2px; min-width: 0; align-items: flex-start; }
.sd-btn small { font-size: 12px; color: var(--text-muted); line-height: 1.45; }

/* ── Placement status chips (listings page, both views) ───────── */
/* Availability picker in the Basic section — who may book this listing. */
.vis-picker { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.vis-check {
  display: flex; align-items: center; gap: 9px;
  padding: 11px 13px; border: 1px solid var(--border); border-radius: 10px;
  cursor: pointer; background: var(--card, #fff); transition: border-color .15s, background .15s;
}
.vis-check:hover { border-color: #cbd5e1; }
.vis-check.on { border-color: var(--primary, #10b981); background: #f0fdf4; }
.vis-check input { width: 16px; height: 16px; cursor: pointer; flex-shrink: 0; accent-color: var(--primary, #10b981); }
.vis-check > svg { color: var(--text-muted); flex-shrink: 0; }
.vis-check.on > svg { color: var(--primary, #10b981); }
.vis-check-body { display: flex; flex-direction: column; line-height: 1.3; min-width: 0; }
.vis-check-name { font-size: 13px; font-weight: 600; color: var(--text); }
.vis-check-desc { font-size: 11px; color: var(--text-muted); }
.vis-warn {
  display: flex; align-items: center; gap: 6px; margin-top: 8px;
  font-size: 12px; color: #92400e; background: #fffbeb;
  border: 1px solid #fde68a; border-radius: 8px; padding: 7px 10px;
}
@media (max-width: 600px) { .vis-picker { grid-template-columns: 1fr; } }

/* Who may book a listing — a separate axis from placement. */
.vis-badge {
  display: inline-flex; align-items: center; align-self: flex-start;
  font-size: 10px; font-weight: 700; letter-spacing: 0.02em;
  padding: 2px 7px; border-radius: 999px; margin-bottom: 4px; white-space: nowrap;
}
.vis-badge.vis-both     { background: #dcfce7; color: #15803d; }
.vis-badge.vis-customer { background: #dbeafe; color: #1d4ed8; }
.vis-badge.vis-admin    { background: #fef3c7; color: #92400e; }
.vis-badge.vis-none     { background: #fee2e2; color: #991b1b; }

.placement-chips { display: flex; flex-direction: column; gap: 5px; align-items: flex-start; }
.placement-chips-row { flex-direction: row; flex-wrap: wrap; }
.chan-wrap { position: relative; display: inline-flex; align-items: center; gap: 2px; }
.chan { display: inline-flex; align-items: center; gap: 5px; font: inherit; font-size: 11.5px; font-weight: 600; padding: 3px 8px; border-radius: 7px; white-space: nowrap; border: 1px solid transparent; cursor: default; }
.chan-btn { cursor: pointer; }
.chan-btn:hover { filter: brightness(.97); }
.chan svg { flex-shrink: 0; }
.chan-name { line-height: 1; }
.chan-suffix { font-weight: 500; opacity: .9; }
.chan-caret { opacity: .6; margin-left: -1px; }
.chan-live    { background: #ecfdf5; color: #047857; border-color: #a7f3d0; }
.chan-pending { background: #fffbeb; color: #b45309; border-color: #fde68a; }
.chan-action  { background: #fef2f2; color: #b91c1c; border-color: #fecaca; }
.chan-off     { background: #f8fafc; color: var(--text-light, #94a3b8); border-color: var(--border, #e2e8f0); }
.chan-open { display: inline-flex; align-items: center; justify-content: center; padding: 3px; border-radius: 5px; color: var(--text-muted); opacity: .8; }
.chan-open:hover { opacity: 1; color: var(--primary); background: #ccfbf1; }
/* Share modal (copy link + QR) */
.share-modal { max-width: 420px; }
.share-body { display: flex; flex-direction: column; gap: 14px; }
.share-hint { font-size: 13px; color: var(--text-muted); line-height: 1.5; margin: 0; }
.share-linkrow { display: flex; gap: 8px; }
.share-link { flex: 1; min-width: 0; font-size: 13px; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; padding: 9px 11px; border: 1px solid var(--border); border-radius: 9px; background: var(--bg); color: var(--text); }
.share-copy { flex-shrink: 0; white-space: nowrap; }
.share-qr-wrap { display: flex; flex-direction: column; align-items: center; gap: 10px; }
.share-qr { width: 200px; height: 200px; border-radius: 12px; border: 1px solid var(--border); background: var(--surface, #ffffff); padding: 8px; }
.share-qr-empty { display: flex; align-items: center; justify-content: center; color: var(--text-muted); }
.share-open { display: inline-flex; align-items: center; gap: 6px; align-self: center; font-size: 13px; font-weight: 700; color: #0f766e; text-decoration: none; }
.share-open:hover { text-decoration: underline; }

/* Inline placement dropdown */
.placement-menu { position: absolute; top: calc(100% + 5px); left: 0; z-index: 60; width: 230px; background: var(--surface, #ffffff); border: 1px solid var(--border); border-radius: 10px; box-shadow: 0 8px 24px rgba(0,0,0,.12); padding: 11px; display: flex; flex-direction: column; gap: 8px; }
.pm-title { display: flex; align-items: center; gap: 7px; font-size: 12px; font-weight: 700; color: var(--text); }
.pm-row { display: flex; align-items: center; gap: 10px; font-size: 13px; font-weight: 600; color: var(--text); cursor: pointer; }
.pm-note { font-size: 12px; color: var(--text-muted); line-height: 1.5; }
.pm-adminnote { display: flex; align-items: flex-start; gap: 6px; font-size: 12px; line-height: 1.45; color: #92400e; background: #fffbeb; border: 1px solid #fde68a; border-radius: 7px; padding: 7px 9px; }
.pm-adminnote svg { flex-shrink: 0; margin-top: 1px; }
.pm-open { display: flex; align-items: center; gap: 6px; font-size: 12.5px; font-weight: 600; color: var(--primary); padding-top: 8px; border-top: 1px solid var(--border); }
.pm-open:hover { text-decoration: underline; }

/* Geo detection */
.geo-detect-bar { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:10px 14px; background:#f0f9ff; border:1px solid #bae6fd; border-radius:10px; margin-bottom:4px; }
.geo-detect-info { display:flex; align-items:center; gap:8px; font-size:13px; color:#0c4a6e; }
.geo-error { font-size:12px; color:#b91c1c; padding:6px 10px; background:#fef2f2; border-radius:8px; margin-bottom:4px; }
.geo-success { display:flex; align-items:center; gap:6px; font-size:12px; color:#065f46; padding:6px 10px; background:#ecfdf5; border:1px solid #a7f3d0; border-radius:8px; margin-bottom:4px; }
.geo-inherited-preview { background:#f8fafc; border:1px solid var(--border); border-radius:10px; padding:12px 14px; margin-top:2px; }
.geo-inherited-title { font-size:11.5px; font-weight:600; color:var(--text-muted); text-transform:uppercase; letter-spacing:.05em; margin-bottom:8px; }
.geo-inherited-pills { display:flex; flex-wrap:wrap; gap:8px; }
.geo-pill { display:inline-flex; align-items:center; gap:5px; padding:4px 10px; border-radius:999px; font-size:12.5px; font-weight:600; background:#e0f2fe; color:#0369a1; border:1px solid #bae6fd; }
.geo-pill-muted { background:#f1f5f9; color:var(--text-muted, #64748b); border-color:var(--border, #e2e8f0); font-weight:500; }
.btn-sm { padding:6px 12px; font-size:12px; }
.role-toggle { display:flex; align-items:center; gap:8px; font-size:13px; color: var(--text-muted); padding: 6px 10px; border: 1px solid var(--border); border-radius: 8px; background: var(--surface, #ffffff); }
.role-toggle select { border: none; padding: 0; width: auto; font-size: 13px; color: var(--text); background: transparent; }
.role-toggle select:focus { box-shadow: none; }

.loc-list { display: flex; flex-direction: column; gap: 14px; }
/* overflow:visible so chip/Add dropdowns aren't clipped at the card's edge; the
   header/rows are transparent so the rounded corners still read fine. */
.loc-group { background: var(--surface, #ffffff); border: 1px solid var(--border); border-radius: 12px; overflow: visible; }
.loc-row-head { display:flex; align-items:center; gap: 12px; padding: 16px 18px; }
/* On phones the venue name + action buttons can't share one row — wrap the
   actions onto their own full-width line so nothing overflows or collides. */
.chev { padding: 4px; border-radius: 6px; color: var(--text-muted); }
.chev:hover { background: var(--bg); }
.loc-title { display: flex; align-items: center; gap: 10px; flex: 1; min-width: 0; }
.loc-title-ic { width: 28px; height: 28px; border-radius: 8px; background: #f0fdfa; color: var(--brand, #0f766e); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.loc-name-btn { font-size: 15px; font-weight: 700; color: var(--text); background: none; border: none; padding: 0; cursor: pointer; text-align: left; }
.loc-add-foot { display: flex; justify-content: center; padding: 6px 0 4px; }
.loc-name-btn:hover { color: var(--primary); text-decoration: underline; }
.count { font-size: 12px; color: var(--text-muted); }
.loc-actions { display: flex; gap: 6px; align-items: center; }
/* Phone layout for the venue group header: row 1 = grip + chevron + (wrapping)
   title/badge/count, row 2 = Add menu + icon actions. Keeps the venue name
   readable instead of squeezing it into a one-word-per-line column.
   NOTE: must come AFTER the base .loc-row-head/.loc-title/.loc-actions rules —
   scoped rules share specificity, so source order decides. */
@media (max-width: 600px) {
  .loc-row-head { flex-wrap: wrap; gap: 8px; padding: 12px 12px; }
  .loc-title { flex: 1 1 calc(100% - 96px); min-width: 0; flex-wrap: wrap; row-gap: 4px; }
  .loc-title .loc-name-btn { white-space: normal; text-align: left; line-height: 1.3; overflow-wrap: anywhere; }
  .loc-actions { flex: 1 1 100%; flex-wrap: wrap; gap: 8px; }
  .loc-actions .am { flex: 1 1 auto; }
  .loc-actions .am .btn { width: 100%; justify-content: center; min-height: 40px; }
  .loc-actions .btn-icon { min-width: 42px; min-height: 40px; justify-content: center; border: 1px solid var(--border); border-radius: 10px; }
}
.btn-sm.icon-only { padding: 7px 8px; }
.btn-danger { background: #dc2626; color: #fff; border: 1px solid #dc2626; }
.btn-danger:hover { background: #b91c1c; }
.loc-body { border-top: 1px solid var(--border); }
.listing-row { display:flex; align-items:flex-start; gap:10px; padding: 11px 16px; border-bottom: 1px solid var(--border); flex-wrap: wrap; }
.listing-row:last-child { border-bottom: 0; }
.dot { width: 4px; align-self: stretch; min-height: 30px; border-radius: 3px; }
.listing-info { flex: 1; min-width: 0; text-align: left; }
.listing-name-btn { font-weight: 600; font-size: 14px; color: var(--text); text-align: left; padding: 0; background: none; border: none; cursor: pointer; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 100%; }
.listing-name-btn:hover { color: var(--primary); text-decoration: underline; }
.muted { color: var(--text-muted); font-size: 12px; }
.listing-actions { display: flex; gap: 4px; margin-left: auto; flex-shrink: 0; }
.btn-icon { padding: 7px; border-radius: 6px; color: var(--text-muted); display: inline-flex; }
.btn-icon:hover { background: var(--bg); color: var(--text); }
.btn-icon.danger:hover { background: #fee2e2; color: var(--error); }
.empty-sub { padding: 18px; text-align: center; color: var(--text-muted); display:flex; flex-direction:column; align-items:center; gap:10px; }
/* No own border — the last venue row's bottom border is the single divider, so
   there's no double line above Membership Programs. Whitespace gives separation. */
.programs-subhead { display: flex; align-items: center; gap: 7px; padding: 16px 16px 8px; font-size: 12px; text-transform: uppercase; letter-spacing: 0.06em; font-weight: 600; color: var(--text-muted); }
.programs-subhead .count-sub { background: var(--bg); padding: 1px 8px; border-radius: 999px; font-size: 11px; font-weight: 500; color: var(--text); text-transform: none; letter-spacing: 0; }
.program-row .program-dot { width: 22px; height: 22px; border-radius: 6px; display: inline-flex; align-items: center; justify-content: center; background: #ecfeff; color: #0e7490; }
/* Type pill in the flat (All Listings) view — distinguishes venues from memberships. */
.flat-type-pill { display: inline-block; margin-left: 8px; padding: 1px 7px; border-radius: 999px; font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; vertical-align: middle; }
.flat-type-pill.venue { background: #ecfdf5; color: #047857; }
.flat-type-pill.academy { background: #ecfeff; color: #0e7490; }

.editor-header { display:flex; align-items:center; gap:14px; margin-bottom: 18px; }
.editor-header .page-title { font-size: 22px; font-weight: 700; }
.editor-header .page-subtitle { color: var(--text-muted); font-size: 13px; }

.sections { display: flex; flex-direction: column; gap: 12px; }
.section-card { background: var(--surface, #ffffff); border: 1px solid var(--border); border-radius: 12px; overflow: hidden; transition: box-shadow 0.15s; }
.section-card.open { box-shadow: var(--shadow); }
.section-head { width: 100%; display: flex; align-items: center; gap: 14px; padding: 18px 20px; background: transparent; }
.section-head:hover { background: #fafafa; }
.section-title { flex: 1; text-align: left; }
.section-title > div:first-child { font-weight: 600; font-size: 14px; color: var(--text); }
.section-sub { font-size: 12px; color: var(--text-muted); margin-top: 2px; }
.opt-tag { font-size: 10px; font-weight: 700; color: var(--text-muted); background: #f1f5f9; padding: 1px 7px; border-radius: 6px; margin-left: 6px; text-transform: uppercase; letter-spacing: .03em; vertical-align: 1px; }
.tick { width: 22px; height: 22px; border-radius: 50%; background: #e5e7eb; color: #fff; display: inline-flex; align-items:center; justify-content:center; flex-shrink: 0; }
.tick.done { background: #10b981; }
.chev-icon { color: var(--text-muted); transition: transform 0.15s; }
.chev-icon.rot { transform: rotate(180deg); }
.section-body { padding: 18px 20px 20px; border-top: 1px solid var(--border); }
.section-footer { display:flex; justify-content: flex-end; align-items: center; gap: 12px; padding-top: 16px; margin-top: 16px; border-top: 1px solid var(--border); }
.section-footer .sf-cancel { margin-right: auto; }
.saved { color: #10b981; font-size: 13px; display: inline-flex; gap: 4px; align-items: center; }
.module-block-error { display:flex; align-items:flex-start; gap:8px; padding:10px 14px; background:#fef2f2; border:1px solid #fecaca; border-radius:8px; color:#991b1b; font-size:13px; line-height:1.5; margin-bottom:12px; }

.req { color: var(--error); }


.color-input { width: 72px; height: 36px; padding: 0; border: 1px solid var(--border); border-radius: 6px; background: none; cursor: pointer; }

.check-row { display: flex; align-items: center; gap: 10px; padding: 10px 0; font-size: 14px; }
.check-row input { accent-color: var(--primary); width: 16px; height: 16px; }
.check-row.locked { color: var(--text); cursor: default; }
.lock-dot { width: 16px; height: 16px; border-radius: 50%; background: #14b8a6; flex-shrink: 0; }
.info-row { display:flex; gap:8px; align-items:flex-start; background: #f0f9ff; color: #075985; padding: 12px; border-radius: 8px; font-size: 13px; margin-bottom: 8px; }

/* Payment method option cards */
.pay-opt { display:flex; gap:12px; align-items:flex-start; padding:13px 14px; border:1px solid var(--border); border-radius:10px; margin-top:10px; cursor:pointer; transition:border-color .12s, background .12s; }
.pay-opt:hover { border-color:#cbd5e1; }
.pay-opt.on { border-color: var(--primary); background:#f0fdfa; }
.pay-opt.locked { cursor:default; background: var(--bg); }
.pay-opt input[type=checkbox] { accent-color: var(--primary); width:17px; height:17px; flex-shrink:0; margin-top:2px; cursor:pointer; }
.pay-opt input[type=checkbox]:disabled { cursor:not-allowed; opacity:.5; }
/* "pick one" not satisfied at save time → the choices themselves go red */
.pay-opt.invalid { border-color: #dc2626; background: #fef2f2; }
.unit-invalid { color: #dc2626; font-weight: 600; }
.pay-opt-body { flex:1; min-width:0; }
.pay-opt-title { font-size:14px; font-weight:700; color: var(--text); }
.pay-opt-desc { font-size:12.5px; color: var(--text-muted); margin-top:3px; line-height:1.5; }
.pay-hint-locked { font-size:11.5px; color:var(--text-light, #94a3b8); margin-top:5px; font-style:italic; }
.pay-warn { display:flex; align-items:center; flex-wrap:wrap; gap:6px; margin-top:8px; font-size:12px; color:#b45309; }
.btn-link { background:none; border:none; padding:0; color: var(--primary); font-weight:700; font-size:12px; text-decoration:underline; cursor:pointer; }
.pay-note { display:flex; align-items:flex-start; gap:6px; font-size:12px; color: var(--text-muted); margin-top:14px; padding-top:11px; border-top:1px dashed var(--border); }
.pay-note svg { flex-shrink:0; margin-top:1px; }

/* ── Placement & Payment: guided steps + money-split visuals ── */
.pp-step { display:flex; align-items:center; gap:9px; font-size:14.5px; font-weight:800; color:var(--text); margin:2px 0 3px; }
.pp-step-n { width:22px; height:22px; border-radius:50%; background:var(--primary); color:#fff; font-size:12px; font-weight:800; display:inline-flex; align-items:center; justify-content:center; flex-shrink:0; }
.pp-step-sub { font-size:12.5px; color:var(--text-muted); margin:0 0 12px 31px; }
.pp-chan { display:flex; align-items:center; gap:8px; flex-wrap:wrap; font-size:14px; color:var(--text); margin-bottom:2px; }
.pp-chan svg { color:var(--primary); }
.pp-chan-toggle { width:100%; background:none; border:none; padding:2px 0; cursor:pointer; text-align:start; font:inherit; }
.pp-chan-toggle:hover b { color:var(--primary); }
.pp-chan-chev { color:var(--text-muted) !important; transition:transform .15s; flex-shrink:0; }
.pp-chan-chev.rot { transform:rotate(180deg); }
.pp-sum { display:flex; align-items:center; flex-wrap:wrap; gap:7px; margin-top:8px; cursor:pointer; }
.pp-sum-chip { display:inline-flex; align-items:center; gap:5px; font-size:12px; font-weight:700; color:#0f766e; background:#f0fdfa; border:1px solid #99f6e4; border-radius:999px; padding:4px 11px; }
.pp-sum-none { display:inline-flex; align-items:center; gap:5px; font-size:12px; font-weight:700; color:#b45309; background:#fffbeb; border:1px solid #fde68a; border-radius:999px; padding:4px 11px; }
.pp-sum-warn { font-size:11.5px; font-weight:700; color:#b45309; }
.pp-sum-edit { margin-left:auto; font-size:12px; font-weight:700; color:var(--primary); text-decoration:underline; }
.pp-chan-fee { margin-left:auto; font-size:11.5px; font-weight:800; color:#0f766e; background:#f0fdfa; border:1px solid #99f6e4; border-radius:999px; padding:3px 10px; }
.pp-chan-toggle .pp-chan-fee { margin-left:auto; }
.pp-chan-fee.free { color:#166534; background:#f0fdf4; border-color:#bbf7d0; }
.pp-chan-sub { font-size:12.5px; color:var(--text-muted); line-height:1.55; margin:4px 0 10px 30px; }
.pay-status { display:flex; align-items:flex-start; gap:8px; flex-wrap:wrap; font-size:12.5px; line-height:1.5; border-radius:10px; padding:10px 12px; margin-bottom:4px; }
.pay-status svg { flex-shrink:0; margin-top:1px; }
.pay-status span { flex:1; min-width:200px; }
.pay-status.warn { background:#fffbeb; border:1px solid #fde68a; color:#92400e; }
.pay-status.ok { background:#f0fdf4; border:1px solid #bbf7d0; color:#166534; }
.ps-bar { display:flex; height:14px; border-radius:999px; overflow:hidden; margin-top:9px; background:#f1f5f9; }
.ps-seg { display:block; height:100%; }
.ps-seg.you { background:#10b981; }
.ps-seg.fee { background:#64748b; }
.ps-seg.venue { background:#a7f3d0; }
.ps-seg.warnseg { background:#f59e0b; }
.ps-legend { display:flex; flex-wrap:wrap; gap:5px 16px; margin-top:7px; }
.ps-key { display:inline-flex; align-items:center; gap:6px; font-size:12px; color:#475569; }
.ps-key b { color:#0f172a; }
.ps-key i { width:9px; height:9px; border-radius:3px; flex-shrink:0; }
.ps-key i.you { background:#10b981; }
.ps-key i.fee { background:#64748b; }
.ps-key i.venue { background:#a7f3d0; }
.ps-key i.warnseg { background:#f59e0b; }
.pay-chip { display:inline-block; margin-left:7px; font-size:10px; font-weight:800; text-transform:uppercase; letter-spacing:.03em; border-radius:999px; padding:2px 8px; vertical-align:1px; }
.pay-chip.rec { color:#0f766e; background:#ccfbf1; }
.pay-chip.risk { color:#b45309; background:#fef3c7; }
.pay-opt.dim:not(.on) { opacity:.75; }
.pay-lock-ico { color:#b45309; margin-left:5px; vertical-align:-1px; }
.ps-example-note { font-size:11.5px; color:var(--text-light, #94a3b8); margin-top:12px; padding-top:10px; border-top:1px dashed var(--border); }
.pp-pay.site-pay { margin-top:18px; }

.sub-box { background: #f0fdfa; border: 1px solid #ccfbf1; padding: 14px; border-radius: 10px; margin: 8px 0 14px; }
.sub-box.muted-box { background: #f9fafb; border-color: var(--border); }
.sub-title { font-weight: 600; font-size: 13px; color: var(--text); margin-bottom: 8px; }
.note { color: var(--text-muted); font-size: 12px; margin-top: 6px; }

.top-actions-row { display: flex; gap: 8px; justify-content: flex-end; margin-bottom: 12px; }
.linked-row { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border: 1px solid var(--border); border-radius: 10px; margin-bottom: 8px; }
.linked-row select { flex: 1; }
.empty-inline { text-align:center; padding: 18px; color: var(--text-muted); background: #fafafa; border-radius: 8px; font-size: 13px; }

/* Connected add-ons / resources — dropdown picker + chips */
/* Connected add-ons / resources — selected item cards */
.conn-selected { display: flex; flex-direction: column; gap: 8px; }
.conn-item { display: flex; align-items: center; gap: 10px; padding: 10px 12px; background: var(--surface, #ffffff); border: 1px solid var(--border); border-radius: 10px; transition: border-color .12s, box-shadow .12s; }
.conn-item:hover { border-color: #cbd5e1; box-shadow: 0 1px 4px rgba(15,23,42,.05); }
.conn-item-icon { display: inline-flex; align-items: center; justify-content: center; width: 30px; height: 30px; border-radius: 8px; background: #ecfeff; color: #0891b2; flex-shrink: 0; }
.conn-item-info { flex: 1; min-width: 0; }
.conn-item-name { font-size: 13.5px; font-weight: 600; color: var(--text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.conn-item-sub { font-size: 11.5px; color: var(--text-muted); margin-top: 1px; text-transform: capitalize; }
.conn-item-price { font-size: 13px; font-weight: 700; color: #0f766e; white-space: nowrap; flex-shrink: 0; }
.conn-item-x { display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border: none; background: transparent; color: var(--text-muted); border-radius: 8px; cursor: pointer; flex-shrink: 0; transition: all .12s; }
.conn-item-x:hover { background: #fee2e2; color: #dc2626; }

.conn-item-qty { display: flex; align-items: center; gap: 4px; flex-shrink: 0; }
.conn-item-qty-lbl { font-size: 11px; color: var(--text-muted); margin-right: 2px; }
.conn-qty-btn { display: inline-flex; align-items: center; justify-content: center; width: 26px; height: 26px; border: 1px solid var(--border); background: #f8fafc; color: var(--text); border-radius: 7px; cursor: pointer; transition: all .12s; }
.conn-qty-btn:hover:not(:disabled) { background: var(--border, #e2e8f0); }
.conn-qty-btn:disabled { opacity: .4; cursor: not-allowed; }
.conn-qty-input { width: 46px; padding: 4px 6px; font-size: 13px; text-align: center; border: 1px solid var(--border); border-radius: 7px; }

/* Repeatable picker rows (add-ons / resources) */
.conn-rows { display: flex; flex-direction: column; gap: 8px; }
.conn-row { display: flex; align-items: center; gap: 10px; }
.conn-row-ico { display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; border-radius: 8px; background: #ecfeff; color: #0891b2; flex-shrink: 0; }
.conn-row-ico.res { background: #f0fdf4; color: #16a34a; }
.conn-row-picker { flex: 1; min-width: 0; }
.conn-row-qty { display: flex; align-items: center; gap: 5px; flex-shrink: 0; }
.conn-row-x { display: inline-flex; align-items: center; justify-content: center; width: 34px; height: 34px; border: none; background: transparent; color: var(--text-muted); border-radius: 8px; cursor: pointer; flex-shrink: 0; transition: all .12s; }
.conn-row-x:hover { background: #fee2e2; color: #dc2626; }
.conn-hint { font-size: 12.5px; color: var(--text-muted); padding: 4px 0; }
.conn-actions { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin-top: 12px; }
.conn-ext-wrap { margin-top: 18px; padding-top: 16px; border-top: 1px solid var(--border); }

/* Session-extension discount — toggle block (used in Timeslot + Add-Ons sections) */
.ext-block { border: 1px solid var(--border); border-radius: 10px; padding: 14px 16px; background: #fcfcfd; }
.ext-block-row { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; }
.ext-block-info { min-width: 0; }
.ext-block-title { display: flex; align-items: center; gap: 7px; font-weight: 600; font-size: 14px; color: var(--text); }
.ext-block-title svg { color: var(--text-muted); flex-shrink: 0; }
.ext-block-sub { font-size: 12.5px; color: var(--text-muted); margin-top: 3px; line-height: 1.45; }
.ext-block-body { margin-top: 14px; padding-top: 14px; border-top: 1px dashed var(--border); display: flex; flex-direction: column; gap: 12px; }
.ext-type-toggle { display: inline-flex; background: #f1f5f9; border-radius: 8px; padding: 3px; align-self: flex-start; }
.ext-type-toggle button { padding: 6px 14px; font-size: 13px; font-weight: 600; border: none; background: none; color: var(--text-muted); border-radius: 6px; cursor: pointer; transition: all .12s; }
.ext-type-toggle button.on { background: var(--surface, #ffffff); color: var(--primary); box-shadow: var(--shadow-sm); }
.ext-value-row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.ext-value-input { width: 110px; flex-shrink: 0; }
.ext-value-unit { font-size: 13px; color: var(--text-muted); }
.conn-add-btn { display: inline-flex; align-items: center; gap: 6px; padding: 9px 14px; border: 1.5px dashed var(--primary); background: #f0fdfa; color: var(--primary); font-size: 13.5px; font-weight: 700; border-radius: 9px; cursor: pointer; transition: background .12s; }
.conn-add-btn:hover { background: #ccfbf1; }
.conn-create-btn { display: inline-flex; align-items: center; gap: 6px; padding: 9px 12px; border: none; background: none; color: var(--text-muted); font-size: 13px; font-weight: 600; cursor: pointer; }
.conn-create-btn:hover { color: var(--primary); }
@media (max-width: 600px) {
  .conn-row { flex-wrap: wrap; gap: 8px; }
  .conn-row-ico { display: none; }                 /* decorative — drop it to free space */
  .conn-row-picker { flex: 1 1 100%; }             /* full-width picker */
  .conn-row-qty { margin-left: auto; }             /* qty + remove share the next line */
  .conn-actions { flex-direction: column; align-items: stretch; }
  .conn-add-btn, .conn-create-btn { justify-content: center; }
}

.conn-empty { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 4px; padding: 22px; border: 1.5px dashed var(--border); border-radius: 10px; color: var(--text-muted); text-align: center; }
.conn-empty > span:first-of-type { font-size: 13px; font-weight: 600; color: #475569; }
.conn-empty-sub { font-size: 11.5px; }

/* Delete-program choice modal (archive vs delete-now) */
.del-modal { padding: 22px; }
.del-head { display: flex; gap: 12px; align-items: flex-start; margin-bottom: 16px; }
.del-ic { flex-shrink: 0; width: 40px; height: 40px; border-radius: 11px; background: #fff7ed; color: #c2410c; display: flex; align-items: center; justify-content: center; }
.del-title { font-size: 16px; font-weight: 700; color: var(--text); line-height: 1.35; }
.del-subtitle { font-size: 13px; color: var(--text-muted); margin-top: 2px; }
.del-option { border: 1px solid var(--border); border-radius: 12px; padding: 14px 16px; margin-bottom: 12px; }
.del-option-text p { margin: 4px 0 0; font-size: 13px; line-height: 1.55; color: var(--text-muted); }
.del-option-name { font-size: 14px; font-weight: 700; color: var(--text); display: flex; align-items: center; gap: 8px; }
.del-rec { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: #047857; background: #d1fae5; border-radius: 999px; padding: 2px 7px; }
.del-option-btn { margin-top: 12px; }
.del-option-danger { border-color: #fecaca; background: #fff5f5; }
.del-ack { display: flex; align-items: flex-start; gap: 10px; margin: 12px 0; padding: 10px 12px; background: var(--surface, #ffffff); border: 1.5px solid #fca5a5; border-radius: 9px; font-size: 12.5px; line-height: 1.5; color: var(--text); cursor: pointer; }
.del-ack input { margin-top: 2px; flex-shrink: 0; width: 16px; height: 16px; cursor: pointer; accent-color: #dc2626; }
.del-delete-btn { background: #dc2626; color: #fff; border: 1px solid #dc2626; }
.del-delete-btn:hover:not(:disabled) { background: #b91c1c; }
.del-delete-btn:disabled { opacity: .5; cursor: not-allowed; }
.del-foot { display: flex; justify-content: flex-end; margin-top: 4px; }
.btn-ghost { background: transparent; color: var(--text-muted); border: 1px solid transparent; }
.btn-ghost:hover { background: #f1f5f9; color: var(--text); }

/* Image upload */
.img-drop-zone {
  display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 10px;
  height: 180px; border: 2px dashed var(--border); border-radius: 10px;
  cursor: pointer; color: var(--text-muted); transition: border-color .15s, background .15s;
}
.img-drop-zone:hover, .img-drop-zone.drop-active {
  border-color: var(--primary); background: #f0fdfb; color: var(--primary);
}
.img-drop-zone.uploading { pointer-events: none; opacity: .7; }
.hidden-file-input { display: none; }
.drop-label { font-size: 14px; }
.drop-hint { font-size: 12px; color: var(--text-muted); }
.img-upload-preview { position: relative; display: inline-block; }
.img-thumb { width: 100%; max-height: 220px; object-fit: cover; border-radius: 10px; border: 1px solid var(--border); display: block; }
.img-remove-btn {
  position: absolute; top: 8px; right: 8px; background: rgba(0,0,0,.55); color: #fff;
  border: none; border-radius: 50%; width: 26px; height: 26px; display: flex; align-items: center; justify-content: center;
  cursor: pointer; transition: background .15s;
}
.img-remove-btn:hover { background: rgba(0,0,0,.8); }
.img-upload-error { margin-top: 8px; padding: 10px 14px; background: #fff1f2; border: 1px solid #fecdd3; border-radius: 8px; font-size: 13px; color: #be123c; }
.spin-icon { animation: spin 1s linear infinite; }

/* Multi-image gallery (first = cover) */
.img-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 10px; margin-bottom: 12px; }
.img-tile { position: relative; border-radius: 10px; overflow: hidden; border: 1px solid var(--border); aspect-ratio: 4 / 3; background: #f1f5f9; }
.img-tile img { width: 100%; height: 100%; object-fit: cover; display: block; }
.img-cover-badge { position: absolute; top: 6px; left: 6px; background: var(--primary); color: #fff; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; padding: 2px 7px; border-radius: 999px; }
.img-remove { position: absolute; top: 6px; right: 6px; background: rgba(0,0,0,.55); color: #fff; border: none; border-radius: 50%; width: 24px; height: 24px; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: background .15s; }
.img-remove:hover { background: rgba(0,0,0,.8); }
.img-url-fallback { margin-top: 12px; font-size: 13px; }
.img-url-fallback summary { cursor: pointer; color: var(--text-muted); }
.img-add-row { display: flex; gap: 8px; margin-top: 8px; }
.img-add-row input { flex: 1; }
@keyframes spin { to { transform: rotate(360deg); } }
.img-preview { height: 180px; background-size: cover; background-position: center; border-radius: 10px; margin-top: 8px; border: 1px solid var(--border); }
.img-placeholder { height: 160px; border: 2px dashed var(--border); border-radius: 10px; display:flex; flex-direction: column; align-items:center; justify-content:center; color: var(--text-muted); gap: 8px; }

.combo { position: relative; }
.combo-input { width: 100%; padding-right: 34px; }
.combo-caret { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); color: var(--text-muted); pointer-events: none; transition: transform 0.15s; }
.combo-caret.rot { transform: translateY(-50%) rotate(180deg); }
.combo-menu { position: absolute; top: calc(100% + 4px); left: 0; right: 0; background: var(--surface, #ffffff); border: 1px solid var(--border); border-radius: 10px; box-shadow: var(--shadow-lg); max-height: 260px; overflow-y: auto; z-index: 10; padding: 4px; }
.combo-item { display: flex; align-items: center; justify-content: space-between; width: 100%; padding: 8px 10px; font-size: 14px; text-align: left; border-radius: 6px; color: var(--text); }
.combo-item:hover { background: var(--bg); }
.combo-item.active { background: #f0fdfa; color: var(--primary); font-weight: 500; }
.combo-item-name { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.combo-cat { flex-shrink: 0; margin-left: 10px; font-size: 11px; font-weight: 700; color: var(--text-muted); background: var(--bg); border: 1px solid var(--border); border-radius: 999px; padding: 2px 8px; }
.combo-empty { padding: 12px; font-size: 13px; color: var(--text-muted); text-align: center; }
.combo-add { display: flex; align-items: center; gap: 6px; width: 100%; padding: 9px 10px; margin-top: 2px; font-size: 13.5px; font-weight: 700; text-align: left; border: none; border-top: 1px solid var(--border); background: none; color: var(--primary); cursor: pointer; border-radius: 0 0 6px 6px; }
.combo-add:hover { background: #f0fdfa; }
.combo-addpanel { padding: 10px; }
.combo-add-title { font-size: 13px; font-weight: 700; color: var(--text); margin-bottom: 8px; }
.combo-add-cats { display: flex; flex-wrap: wrap; gap: 6px; }
.combo-add-cat { padding: 7px 12px; font-size: 13px; font-weight: 600; border: 1px solid var(--border); background: var(--surface, #ffffff); color: var(--text); border-radius: 999px; cursor: pointer; }
.combo-add-cat:hover { border-color: var(--primary); color: var(--primary); background: #f0fdfa; }
.combo-add-note { font-size: 11.5px; color: var(--text-muted); margin-top: 8px; line-height: 1.4; }
/* Read-only derived field (e.g. the category implied by the chosen type) */
.readonly-field { display: flex; align-items: center; min-height: 38px; padding: 8px 12px; border: 1px solid var(--border); border-radius: 8px; background: var(--bg); color: var(--text); font-size: 14px; font-weight: 600; }
.readonly-field.empty { color: var(--text-muted); font-weight: 400; font-style: italic; }

.sf-sort { display: flex; align-items: center; gap: 7px; }
.sf-sort-lbl { font-size: 12px; font-weight: 700; color: var(--text-muted); white-space: nowrap; }
.sf-sort-sel { height: 34px; border: 1px solid var(--border); border-radius: 8px; padding: 0 8px; font-size: 13px; font-weight: 600; color: var(--text); background: var(--surface, #ffffff); cursor: pointer; }
.sf-sort-sel:focus { outline: none; border-color: var(--brand, #14b8a6); }
@media (max-width: 700px) { .sf-sort-lbl { display: none; } }
.layout-toggle { display: flex; border: 1px solid var(--border); border-radius: 8px; overflow: hidden; background: var(--surface, #ffffff); }
.layout-btn { display: flex; align-items: center; gap: 6px; padding: 7px 12px; font-size: 13px; font-weight: 500; color: var(--text-muted); background: transparent; border: none; cursor: pointer; transition: background 0.12s, color 0.12s; white-space: nowrap; }
.layout-btn:not(:last-child) { border-right: 1px solid var(--border); }
.layout-btn.active { background: var(--primary); color: #fff; }
.layout-btn:not(.active):hover { background: var(--bg); color: var(--text); }

/* Category / type filter (mirrors the customer storefront) */
.filter-toggle { display: inline-flex; align-items: center; gap: 6px; background: var(--surface, #ffffff); border: 1px solid var(--border); border-radius: 8px; padding: 7px 13px; font-size: 13.5px; font-weight: 600; color: var(--text); cursor: pointer; white-space: nowrap; }
.filter-toggle:hover { border-color: var(--primary); color: var(--primary); }
.filter-toggle.on { border-color: var(--primary); color: var(--primary); background: #f0fdfa; }
.filter-toggle-n { background: var(--primary); color: #fff; font-size: 11px; font-weight: 800; min-width: 18px; height: 18px; border-radius: 999px; display: inline-flex; align-items: center; justify-content: center; padding: 0 5px; }
/* Listings toolbar: compact search with the filter button at its right end,
   and every filter inside the dropdown panel. */
.lf-bar { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.lf-search-wrap { position: relative; flex: 0 1 380px; min-width: 220px; }
.lf-search { width: 100%; position: relative; }
.lf-search input { padding-right: 40px; }
.lf-filter-btn { position: absolute; right: 6px; top: 50%; transform: translateY(-50%); display: inline-flex; align-items: center; gap: 3px; flex-shrink: 0; padding: 5px 7px; border: none; background: transparent; color: var(--text-muted); border-radius: 7px; cursor: pointer; }
.lf-filter-btn:hover { background: var(--bg); color: var(--text); }
.lf-filter-btn.on { color: var(--primary); }
.lf-filter-n { background: var(--primary); color: #fff; font-size: 10px; font-weight: 800; min-width: 16px; height: 16px; border-radius: 999px; display: inline-flex; align-items: center; justify-content: center; padding: 0 4px; }
.lf-spacer { flex: 1 1 0; min-width: 0; }

.lf-panel { position: absolute; top: calc(100% + 6px); left: 0; z-index: 50; width: min(540px, 92vw); background: var(--surface, #ffffff); border: 1px solid var(--border); border-radius: 12px; padding: 14px 16px; box-shadow: 0 12px 32px rgba(0,0,0,.14); display: flex; flex-direction: column; gap: 14px; }
.lf-selects { display: flex; gap: 10px; flex-wrap: wrap; }
.lf-field { display: flex; flex-direction: column; gap: 5px; flex: 1 1 150px; min-width: 0; }
.lf-field label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: var(--text-muted); }
.lf-field .filter-select { width: 100%; min-width: 0; }
.lf-row { display: flex; align-items: flex-start; gap: 12px; flex-wrap: wrap; }
.lf-label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: var(--text-muted); width: 64px; flex-shrink: 0; padding-top: 7px; }
.lf-chips { display: flex; flex-wrap: wrap; gap: 7px; flex: 1; }
.lf-chips button { border: 1px solid var(--border); background: var(--surface, #ffffff); color: var(--text); font-weight: 600; font-size: 13px; padding: 6px 13px; border-radius: 999px; cursor: pointer; transition: all .12s; }
.lf-chips button:hover { border-color: var(--primary); }
.lf-chips button.on { background: var(--primary); border-color: var(--primary); color: #fff; }
.lf-emptytypes { font-size: 13px; color: var(--text-muted); padding: 2px 0 2px 76px; }
.lf-type-hint { font-size: 13px; color: var(--text-muted); font-style: italic; padding-top: 7px; }
.lf-panel-foot { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding-top: 12px; border-top: 1px solid var(--border); }
.lf-clear { background: none; border: none; color: var(--error); font-weight: 700; font-size: 13px; cursor: pointer; padding: 2px; }
.lf-clear:hover { text-decoration: underline; }

/* overflow:visible so chip dropdowns aren't clipped; header gets rounded top
   corners to match the card (mobile re-enables overflow-x for the wide table). */
.flat-list { background: var(--surface, #ffffff); border: 1px solid var(--border); border-radius: 12px; overflow: visible; }
/* Two flexible columns (name + location) so width is shared and there's no huge
   empty gap after the listing name. Cap column removed. */
.flat-header { display: grid; grid-template-columns: 28px 18px minmax(160px, 1.6fr) minmax(130px, 1.1fr) 110px 104px 190px 92px; gap: 10px; align-items: center; padding: 10px 16px; background: #f9fafb; border-bottom: 1px solid var(--border); border-radius: 11px 11px 0 0; font-size: 11px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; }
.flat-row { display: grid; grid-template-columns: 28px 18px minmax(160px, 1.6fr) minmax(130px, 1.1fr) 110px 104px 190px 92px; gap: 10px; align-items: center; padding: 10px 16px; border-bottom: 1px solid var(--border); transition: background 0.1s; }
/* On phones the fixed columns can't fit — scroll horizontally instead of clipping. */
@media (max-width: 768px) {
  .flat-list { overflow-x: auto; -webkit-overflow-scrolling: touch; }
  .flat-header, .flat-row { min-width: 820px; }
}
/* Flat view: a section (title + table) per entity type */
.flat-section { margin-bottom: 22px; }
.flat-section:last-child { margin-bottom: 0; }
.flat-section-head { display: flex; align-items: center; gap: 8px; margin: 0 2px 10px; font-size: 14px; font-weight: 700; color: var(--text); }
.flat-section-head svg { color: var(--text-muted); }
.flat-section-count { background: var(--bg); color: var(--text-muted); font-size: 12px; font-weight: 600; padding: 1px 9px; border-radius: 999px; }
.flat-empty { padding: 26px; text-align: center; color: var(--text-muted); font-size: 13px; }
.flat-th-sort { cursor:pointer; display:flex; align-items:center; gap:4px; user-select:none; }
.flat-th-sort:hover { color:var(--text); }
/* Slot preview hint */
.slot-preview { display:flex; align-items:center; flex-wrap:wrap; gap:6px; margin-top:12px; margin-bottom:4px; font-size:12.5px; color:var(--text-muted); }
.slot-preview svg { color:var(--primary); flex-shrink:0; }
.slot-preview-chip { font-family:'SF Mono',Monaco,monospace; font-size:12px; font-weight:600; color:var(--text); background:var(--bg); border:1px solid var(--border); border-radius:6px; padding:1px 7px; }
.slot-preview-buffer { font-style:italic; color:#b45309; }
.fixed-times-note { font-size:12px; color:var(--text-muted); margin:8px 0; line-height:1.45; }
.fixed-times-note strong { color:var(--text); font-weight:600; }
/* Inline text button (e.g. "weekly schedule" link inside hints) */
.link-btn { background:none; border:0; padding:0; margin:0; font:inherit; color:var(--primary); font-weight:600; text-decoration:underline; cursor:pointer; }
.link-btn:hover { color:var(--primary-dark, #0d9488); }
/* Start-time mode toggle */
/* Custom start times — title + toggle header */
.cst-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; }
.cst-info { min-width: 0; }
.cst-title { display: flex; align-items: center; gap: 6px; font-weight: 600; font-size: 14px; color: var(--text); }
.cst-sub { font-size: 12.5px; color: var(--text-muted); margin-top: 3px; line-height: 1.45; }
.sttm-toggle { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
.sttm-btn { padding:11px 14px; border:1.5px solid var(--border); border-radius:10px; background:var(--surface, #ffffff); text-align:left; cursor:pointer; transition:all .15s; }
.sttm-btn:hover { border-color:var(--primary); }
.sttm-btn.active { border-color:var(--primary); background:#f0fdfa; box-shadow:0 0 0 3px rgba(16,185,129,.12); }
.sttm-title { font-size:13.5px; font-weight:600; color:var(--text); }
.sttm-sub { font-size:11.5px; color:var(--text-muted); margin-top:2px; }
.fixed-times-editor { margin-top:12px; padding:12px; background:var(--bg); border:1px solid var(--border); border-radius:10px; }
.fixed-times-add { display:flex; gap:8px; align-items:center; }
.fixed-times-add .filter-select { max-width:120px; }
.fixed-times-chips { display:flex; flex-wrap:wrap; gap:6px; margin-top:10px; }
.fixed-time-chip { display:inline-flex; align-items:center; gap:5px; padding:3px 6px 3px 10px; background:var(--surface, #ffffff); border:1px solid var(--border); border-radius:6px; font-size:12.5px; font-weight:600; font-family:'SF Mono',Monaco,monospace; }
.fixed-time-chip button { display:inline-flex; width:16px; height:16px; border-radius:4px; background:#f1f5f9; color:var(--text-muted); align-items:center; justify-content:center; }
.fixed-time-chip button:hover { background:#fee2e2; color:#dc2626; }
.fixed-times-empty { margin-top:10px; font-size:12px; color:#b45309; }
/* Fixed start-times: hour & minute pickers + live preview */
.ft-pickers { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
@media (max-width:600px){ .ft-pickers { grid-template-columns:1fr; } }
.ft-col { min-width:0; }
.ft-col-label { display:block; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:var(--text-muted); margin-bottom:6px; }
.ft-select { width:100%; max-width:none; }
.ft-col-hint { margin-top:8px; font-size:12px; color:var(--text-muted); }
.ft-preview { margin-top:14px; padding-top:12px; border-top:1px dashed var(--border); }
.ft-preview-head { display:flex; align-items:center; gap:6px; font-size:12.5px; font-weight:600; color:var(--text); margin-bottom:8px; }
.ft-preview-head svg { color:var(--primary); }
.ft-preview-count { margin-left:2px; font-size:11px; font-weight:800; color:var(--primary); background:#ccfbf1; border-radius:999px; padding:1px 8px; }
.ft-preview-grid { display:flex; flex-wrap:wrap; gap:6px; }
.ft-preview-chip { font-family:'SF Mono',Monaco,monospace; font-size:12px; font-weight:700; color:var(--primary); background:#f0fdfa; border:1px solid #99f6e4; border-radius:6px; padding:2px 8px; }
/* Custom start times: tappable slot toggles (on = offered to customers) */
.cst-caption { display:flex; align-items:center; gap:6px; font-size:12px; color:var(--text-muted); margin-bottom:10px; }
.cst-caption svg { color:var(--primary); flex-shrink:0; }
.cst-chips { display:flex; flex-wrap:wrap; gap:8px; }
.cst-chip { display:inline-flex; align-items:center; gap:5px; font-family:'SF Mono',Monaco,monospace; font-size:12.5px; font-weight:700; padding:6px 12px; border-radius:999px; border:1.5px solid var(--border); background:var(--surface, #ffffff); color:var(--text-muted); cursor:pointer; transition:all .12s; }
.cst-chip:hover { border-color:var(--primary); color:var(--primary); }
.cst-chip .cst-chip-check { flex-shrink:0; }
.cst-chip.on { background:var(--primary); border-color:var(--primary); color:#fff; }
.cst-chip.on:hover { background:var(--primary-dark, #0d9488); border-color:var(--primary-dark, #0d9488); color:#fff; }
/* Export choice buttons */
.export-choice-btn { display:flex;align-items:center;gap:14px;padding:13px 16px;background:var(--bg);border:1.5px solid var(--border);border-radius:10px;cursor:pointer;text-align:left;width:100%;transition:all .15s; }
.export-choice-btn:hover { border-color:var(--primary);background:#f0f9ff; }
.export-choice-btn svg { color:var(--primary);flex-shrink:0; }
.export-choice-btn strong { font-size:13.5px;color:var(--text); }
.export-choice-btn span { font-size:12px;color:var(--text-muted); }
.flat-row:last-child { border-bottom: 0; }
.flat-row:hover { background: #fafafa; }
.flat-color-dot { width: 4px; height: 24px; border-radius: 3px; flex-shrink: 0; justify-self: center; }
.flat-name { min-width: 0; overflow: hidden; }
.flat-loc { font-size: 13px; color: var(--text-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.flat-sport, .flat-price, .flat-cap { font-size: 12px; color: var(--text-muted); white-space: nowrap; }
.flat-actions { display: flex; gap: 2px; justify-content: flex-end; }
.flat-row.drag-over { background: #f0fdfa; outline: 2px solid var(--primary); outline-offset: -2px; }
.flat-row.dragging { opacity: 0.45; }
.flat-row.is-hidden { opacity: 0.45; background: #f9fafb; }
.flat-row.is-hidden .listing-name-btn { color: var(--muted); text-decoration: line-through; }
.flat-grip { margin: 0; }

.drag-handle { cursor: grab; color: var(--text-muted); display: inline-flex; align-items: center; padding: 2px 4px; border-radius: 4px; flex-shrink: 0; touch-action: none; /* touch drags reorder (touchReorder.js) instead of scrolling */ }
.drag-handle:hover { color: var(--text); background: var(--bg); }
.drag-handle:active { cursor: grabbing; }
@media (max-width: 600px) {
  /* Finger-sized grip for the touch reorder path. */
  .drag-handle { padding: 8px 6px; }
}
.listing-drag { margin-right: 4px; }
.loc-group.drag-over { border-color: var(--primary); box-shadow: 0 0 0 2px #d1fae5; }
.loc-group.dragging { opacity: 0.5; }
.listing-row.drag-over { background: #f0fdfa; }
.listing-row.dragging { opacity: 0.45; }
.btn-icon.eye-hidden { color: #ef4444; }
.btn-icon.eye-hidden:hover { background: #fee2e2; color: #dc2626; }
.btn-icon.paused-icon { color: #f59e0b; }
.btn-icon.paused-icon:hover { background: #fef3c7; color: #d97706; }

.eligibility-notice { display: flex; gap: 10px; align-items: flex-start; padding: 12px 16px; border-radius: 8px; font-size: 13px; line-height: 1.5; border: 1px solid; }
.notice-admin { background: #fef9c3; border-color: #fde047; color: #713f12; }
.notice-admin-pending { background: #eff6ff; border-color: #bfdbfe; color: #1e3a5f; }
.notice-admin-pending strong { color: #1d4ed8; }
.notice-provider { background: #fff7ed; border-color: #fed7aa; color: #9a3412; }
.notice-provider strong { color: #7c2d12; }
.notice-review { background: #eff6ff; border-color: #bfdbfe; color: #1e3a5f; }
.notice-review strong { color: #1d4ed8; }
.notice-pending { font-style: italic; color: #6b7280; }

.listing-pause-block { display: flex; flex-direction: column; gap: 4px; flex-shrink: 0; max-width: 300px; }
.listing-eligibility-badge { display: inline-flex; align-items: center; gap: 4px; font-size: 11px; font-weight: 600; color: #b45309; background: #fef3c7; border: 1px solid #fde68a; border-radius: 5px; padding: 2px 7px; flex-shrink: 0; white-space: nowrap; width: fit-content; }
.listing-eligibility-badge.badge-review { color: #1d4ed8; background: #eff6ff; border-color: #bfdbfe; }
.listing-admin-note { font-size: 12px; color: #92400e; background: #fffbeb; border: 1px solid #fde68a; border-radius: 6px; padding: 5px 8px; line-height: 1.4; }
.listing-admin-note.note-review { color: #1e3a5f; background: #eff6ff; border-color: #bfdbfe; }
.listing-admin-note span { font-weight: 600; color: #78350f; }
.listing-admin-note.note-review span { color: #1d4ed8; }
.note-cta { font-style: italic; color: #b45309; font-weight: 400; }
.note-pending { font-style: italic; color: #6b7280; font-weight: 400; }

.pause-info { display: flex; gap: 10px; align-items: flex-start; padding: 12px; background: #fff7ed; border: 1px solid #fed7aa; border-radius: 8px; font-size: 13px; color: #9a3412; line-height: 1.5; }
.pause-info strong { color: #7c2d12; }
.loc-group.is-hidden { opacity: 0.5; background: #f9fafb; border-style: dashed; }
.loc-group.is-hidden .loc-name-btn { color: var(--muted); text-decoration: line-through; }
.listing-row.is-hidden { opacity: 0.45; background: #f9fafb; }
.listing-row.is-hidden .listing-name-btn { color: var(--muted); text-decoration: line-through; }

.acct-icon-sm { width: 36px; height: 36px; border-radius: 8px; background: linear-gradient(135deg, #ccfbf1, #99f6e4); color: var(--primary); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.acct-modal-icon { width: 52px; height: 52px; border-radius: 12px; background: linear-gradient(135deg, #ccfbf1, #99f6e4); color: var(--primary); display: flex; align-items: center; justify-content: center; margin: 0 0 14px; }

/* ── Narrow-phone fixes (≤600px; verified against 360/344px) — mobile only,
   desktop layout untouched. ─────────────────────────────────────────────── */
@media (max-width: 600px) {
  /* Placement dropdown: chips can sit anywhere along the row and .loc-group is
     overflow:visible by design, so a left-anchored 230px menu can poke past the
     right viewport edge. Right-anchor it and clamp to the viewport. */
  .placement-menu { left: auto; right: 0; width: min(230px, calc(100vw - 48px)); }

  /* iOS auto-zooms focused fields under 16px. These set an explicit 13px in
     scoped styles, which beats the global phone `input, select { 16px }` rule. */
  .sf-sort-sel, .role-toggle select, .conn-qty-input { font-size: 16px; }
  .conn-qty-input { width: 54px; } /* a touch wider so 16px digits fit */

  /* Selected add-on/resource cards: the icon + qty stepper + price + remove
     cluster leaves the name ~60px at 360px — wrap so qty/price/remove drop to
     a second line under the name. */
  .conn-item { flex-wrap: wrap; }
  .conn-item-info { flex: 1 1 calc(100% - 80px); }
  .conn-item-qty { margin-left: 40px; }

  /* Filter panel is ~331px here (min(540px, 92vw)) — stack the Type/Category/
     Sport label above its chips instead of burning a 64px side column. */
  .lf-row { flex-direction: column; gap: 6px; }
  .lf-label { width: auto; padding-top: 0; }
  .lf-emptytypes { padding-left: 0; }

  /* Confirm-modal footers: side-by-side buttons wrap their long labels into
     lopsided 2-3 line pills at 360px. Stack full-width, primary on top
     (matches the global .modal-footer sheet convention). */
  .lcm-actions, .nlm-actions { flex-direction: column-reverse; gap: 8px; }
  .lcm-actions .btn, .nlm-actions .btn { width: 100%; }

  /* Reclaim the ~30px decorative indents under the numbered step headings —
     at 360px that's a tenth of the viewport lost inside a padded card. */
  .pp-step-sub, .pp-chan-sub { margin-left: 0; }
  .section-body { padding: 14px 14px 16px; }

  /* Several modals carry inline max-widths (400-560px) that beat the global
     bottom-sheet rule; force full width so sheets span the phone screen. */
  .modal { max-width: 100% !important; }

  /* ── FLAGSHIP FIX: listing / program row ────────────────────────────────
     One horizontal flex was cramming grip + colour bar + name + BOTH placement
     chips (Bookboll / Your site) + edit/delete onto a single 360px line, which
     starved the name down to "Tenn…". Restructure into two lines WITHOUT hiding
     anything: line 1 = name (+meta) with edit/delete on the right; line 2 = the
     placement chips, full width, side-by-side. Status stays just as visible and
     the name is finally readable. */
  .listing-row { align-items: center; row-gap: 9px; padding: 12px 14px; }
  .listing-info { flex: 1 1 auto; order: 1; }
  .listing-name-btn { white-space: normal; overflow: visible; overflow-wrap: anywhere; line-height: 1.25; }
  .listing-actions { order: 2; margin-left: auto; }
  .listing-pause-block {
    order: 3; flex-basis: 100%; max-width: 100%;
    /* align the chips under the name, past the grip + colour bar */
    padding-left: 26px;
  }
  .placement-chips { flex-direction: row; flex-wrap: wrap; gap: 6px; width: 100%; }
  /* a hair more tap area on the chip buttons + open-link at finger size */
  .chan { padding: 5px 9px; font-size: 12px; }
  .chan-open { padding: 5px; }
}

.lf-note { flex-basis: 100%; font-size: 11.5px; color: var(--text-light, #94a3b8); margin-top: 4px; }
</style>
