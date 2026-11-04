<script setup>
import ExportDialog from '../components/ExportDialog.vue'
import { bookingRefLabel } from '../lib/bookingRef.js'
import { ref, onMounted, computed, watch } from 'vue'
import { useRouter } from 'vue-router'
import { fetchExportPages } from '../lib/exportPages.js'
import { fetchPaymentsDashboard } from '../lib/payments.js'
import { isStripeOnlinePayment, stripeInvoiceUrl, stripeInvoiceTitle } from '../lib/stripeInvoice.js'
import { useCurrentUser } from '../lib/currentUser.js'
import { useJurisdiction } from '../lib/jurisdiction.js'
import { Search, Download, DollarSign, TrendingUp, Wallet, RotateCcw, Check, X, Building2, SlidersHorizontal, ChevronUp, ChevronDown, ArrowUpDown, Eye, Printer, ChevronRight, GraduationCap, CalendarDays, Loader, FileText } from 'lucide-vue-next'
import MultiSelect from '../components/MultiSelect.vue'
import FilterBar from '../components/FilterBar.vue'
import { bookingKind } from '../lib/bookingMeta.js'
import PaginationBar from '../components/PaginationBar.vue'
import { fmt12 } from '../lib/slots.js'
import { downloadCSV } from '../lib/useExport.js'
import { usePremium } from '../lib/premium.js'
import { CAP } from '../lib/permissions.js'
import BookingDetailModal from '../components/BookingDetailModal.vue'
import { pushToast } from '../lib/toast.js'

const router = useRouter()

const { isSuperAdmin, isCountryAdmin, can } = useCurrentUser()
const canExport = computed(() => can(CAP.EXPORT_PAYMENTS))
const { isPremium, requirePremium } = usePremium()   // exports are a Premium feature for providers

const payments = ref([])
const relatedPayments = ref([])
const bookings = ref([])
const customers = ref([])
const listings = ref([])
const locations = ref([])
const cities = ref([])
const countries = ref([])
const adminUsers = ref([])
const subscriptions = ref([])
const programs = ref([])
const loading = ref(false)
const serverStats = ref({ total: [], to_bookboll: [], to_provider: [], pending: 0, pending_amount: [] })
const filterOptionCurrencies = ref([])
const filterOptionRecipients = ref([])

// Platform jurisdiction (country_admin / platform_staff only see their countries' venues)
const { scopeCountries, scopeCities } = useJurisdiction({ locations, cities })
// Filter dropdown options honour the jurisdiction too (a country admin's
// filters must not advertise other countries).
const jCountries = computed(() => scopeCountries(countries.value))
const jCities = computed(() => scopeCities(cities.value))
const fbCountries = computed(() => jCountries.value)
const fbCities = computed(() => jCities.value)

// ── Filters ──────────────────────────────────────────────────────────────────
const search = ref('')
const statusFilter = ref([])
const sourceFilter = ref([])
const methodFilter = ref([])
const typeFilter = ref([])
const recipientFilter = ref([])
const currencyFilter = ref([])
const locationFilter = ref([])
const listingFilter = ref([])
const programFilter = ref([])
const filterCountry = ref([])   // multi-select (FilterBar)
const filterCity = ref([])
const amountMin = ref('')
const amountMax = ref('')
const dateFrom = ref('')
const dateTo = ref('')
const filtersOpen = ref(true)

// ── Sort + Laravel pagination (Audit Logs pattern) ───────────────────────────
const sortKey = ref('date')
const sortDir = ref('desc')
const pmtPage = ref(1)
const pmtPerPage = ref(25)
const pmtTotal = ref(0)
const pmtTotalPages = computed(() => Math.max(1, Math.ceil(pmtTotal.value / pmtPerPage.value) || 1))
const pmtVisiblePages = computed(() => {
  const total = pmtTotalPages.value
  const cur = pmtPage.value
  if (total <= 7) return Array.from({ length: total }, (_, i) => i + 1)
  const pages = new Set([1, total, cur])
  if (cur > 1) pages.add(cur - 1)
  if (cur < total) pages.add(cur + 1)
  return Array.from(pages).sort((a, b) => a - b)
})

function setSort(key) {
  if (sortKey.value === key) sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc'
  else { sortKey.value = key; sortDir.value = 'desc' }
  pmtPage.value = 1
  load()
}

// ── Accept payment (delegates to BookingDetailModal's Accept Payment sheet) ───
const collectPmtId = ref(null)   // pending installment to open the Accept sheet on

function csvParam(arr) {
  if (!Array.isArray(arr) || !arr.length) return undefined
  return arr.map(String).join(',')
}

function paymentQuery() {
  return {
    search: search.value.trim() || undefined,
    status: csvParam(statusFilter.value),
    method: csvParam(methodFilter.value),
    type: csvParam(typeFilter.value),
    source: csvParam(sourceFilter.value),
    recipient: csvParam(recipientFilter.value),
    currency: csvParam(currencyFilter.value),
    location_id: csvParam(locationFilter.value),
    listing_id: csvParam(listingFilter.value),
    program_id: csvParam(programFilter.value),
    country_id: csvParam(filterCountry.value),
    city_id: csvParam(filterCity.value),
    amount_min: amountMin.value !== '' ? amountMin.value : undefined,
    amount_max: amountMax.value !== '' ? amountMax.value : undefined,
    from_date: dateFrom.value || undefined,
    to_date: dateTo.value || undefined,
    sort: sortKey.value,
    sort_dir: sortDir.value,
  }
}

let searchTimer
async function load() {
  loading.value = true
  const res = await fetchPaymentsDashboard({ ...paymentQuery(), page: pmtPage.value, records_per_page: pmtPerPage.value })
  loading.value = false

  if (!res.success) {
    pushToast(res.message || 'Could not load payments.', 'error')
    payments.value = []
    relatedPayments.value = []
    pmtTotal.value = 0
    return
  }

  const d = res.data || {}
  const paginator = d.payments
  const rows = Array.isArray(paginator?.data)
    ? paginator.data
    : (Array.isArray(paginator) ? paginator : [])
  payments.value = rows
  relatedPayments.value = d.related_payments || []
  pmtTotal.value = Number(paginator?.total ?? rows.length) || 0
  if (paginator?.current_page) pmtPage.value = Number(paginator.current_page)

  bookings.value = d.bookings || []
  customers.value = d.customers || []
  listings.value = d.listings || []
  locations.value = d.locations || []
  adminUsers.value = d.admin_users || []
  subscriptions.value = d.subscriptions || []
  programs.value = d.programs || []
  cities.value = d.cities || []
  countries.value = d.countries || []
  if (d.stats) serverStats.value = d.stats
  const opts = d.filter_options || {}
  if (Array.isArray(opts.currencies)) filterOptionCurrencies.value = opts.currencies
  if (Array.isArray(opts.recipients)) filterOptionRecipients.value = opts.recipients
}

function scheduleLoad() {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    pmtPage.value = 1
    load()
  }, 300)
}

function pmtGoTo(p) {
  pmtPage.value = p
  load()
}

function reloadFromFilters() {
  pmtPage.value = 1
  load()
}

watch([
  statusFilter, sourceFilter, methodFilter, typeFilter, recipientFilter,
  currencyFilter, locationFilter, listingFilter, programFilter,
  filterCountry, filterCity, amountMin, amountMax, dateFrom, dateTo,
], () => reloadFromFilters(), { deep: true })
watch(search, () => scheduleLoad())
watch(pmtPerPage, () => {
  pmtPage.value = 1
  load()
})

onMounted(load)

// ── Lookups ───────────────────────────────────────────────────────────────────
const customerName = (id) => customers.value.find(c => c.id === id)?.full_name || '—'
const listingName = (id) => listings.value.find(l => l.id === id)?.name || '—'
const locationName = (id) => locations.value.find(l => l.id === id)?.name || '—'
const bookingRef = (id) => bookings.value.find(b => b.id === id)
// Payment-type filter groups: every raw value written over time maps to one
// selectable bucket (legacy 'booking' = full; 'balance' = the arrival remainder;
// booking/subscription commission & earning rows fold into shared buckets).
const TYPE_NORM = {
  booking: 'full', balance: 'remaining',
  booking_commission: 'commission', subscription_commission: 'commission',
  booking_earning: 'earning', subscription_earning: 'earning',
}
const normPaymentType = (t) => TYPE_NORM[t] || t
// What PRODUCT a payment belongs to: membership billing (subscription rows) or,
// for booking payments, the booking's kind — venue slot vs event/game seat.
const paymentKind = (p) => p.source_type === 'subscription' ? 'subscription' : bookingKind(bookingRef(p.booking_id) || {})
const markedByName = (id) => adminUsers.value.find(u => u.id === id)?.full_name || '—'

// The payment's OWN reference (PAY_…/DEP_…) — the string on the receipt, in the
// audit trail and in Stripe's notes, so staff can search the one they were quoted.
// Falls back to the related booking/subscription when a row predates it.
const paymentRef = (p) => {
  const own = String(p.payment_id || '').trim()
  if (own) return `#${own}`
  if (p.source_type === 'subscription' && p.subscription_id)
    return `#SUB-${p.subscription_id.slice(0, 6).toUpperCase()}`
  return p.booking_id ? bookingRefLabel(bookingRef(p.booking_id) || { id: p.booking_id }) : '—'
}
/** The booking this payment belongs to, shown under its own reference. */
const paymentBookingRef = (p) => (p.source_type === 'subscription' || !p.booking_id)
  ? '' : bookingRefLabel(bookingRef(p.booking_id) || { id: p.booking_id })

const subCustomerName = (payment) => {
  const sub = subscriptions.value.find(s => s.id === payment.subscription_id)
  return sub?.customer_name || sub?.customer_email || '—'
}
const subSourceLabel = (payment) => {
  const sub = subscriptions.value.find(s => s.id === payment.subscription_id)
  if (!sub) return '—'
  const prog = programs.value.find(p => p.id === sub.program_id)
  return prog?.name || 'Subscription'
}
const paymentCustomer = (p) => p.source_type === 'subscription' ? subCustomerName(p) : customerName(bookingRef(p.booking_id)?.customer_id)
const paymentSource = (p) => p.source_type === 'subscription' ? subSourceLabel(p) : listingName(bookingRef(p.booking_id)?.listing_id)

const paymentLocation = (p) => {
  if (p.source_type === 'subscription') {
    const sub = subscriptions.value.find(s => s.id === p.subscription_id)
    const prog = programs.value.find(pr => pr.id === sub?.program_id)
    return locations.value.find(l => l.id === prog?.location_id)?.name || ''
  }
  return locationName(bookingRef(p.booking_id)?.location_id)
}

const paymentLocationId = (p) => {
  if (p.source_type === 'subscription') {
    const sub = subscriptions.value.find(s => s.id === p.subscription_id)
    const prog = programs.value.find(pr => pr.id === sub?.program_id)
    return prog?.location_id || null
  }
  return bookingRef(p.booking_id)?.location_id || null
}

const subOwnerUserId = (payment) => {
  const sub = subscriptions.value.find(s => s.id === payment.subscription_id)
  const prog = programs.value.find(p => p.id === sub?.program_id)
  return prog?.owner_user_id || null
}

// The provider who owns this payment (for jurisdiction fallback when the payment
// has no resolvable location — e.g. a subscription whose program's venue was deleted).
const paymentOwnerUserId = (p) => p.source_type === 'subscription'
  ? subOwnerUserId(p)
  : (locations.value.find(l => l.id === paymentLocationId(p))?.owner_user_id || null)

// The booking's listing (court rentals) or the subscription's program (memberships).
const paymentListingId = (p) => p.source_type === 'subscription' ? null : (bookingRef(p.booking_id)?.listing_id || null)
const paymentProgramId = (p) => {
  if (p.source_type !== 'subscription') return null
  return subscriptions.value.find(s => s.id === p.subscription_id)?.program_id || null
}

const isBookbollRecipient = (r) => !r || r === 'bookboll' || r === '@bookboll'

const paymentDate = (p) => new Date(p.paid_at || p.created_at)

const paymentCityId = (p) => {
  const locId = paymentLocationId(p)
  return locations.value.find(l => l.id === locId)?.city_id || null
}

const paymentCountryId = (p) => {
  const cityId = paymentCityId(p)
  return cities.value.find(c => c.id === cityId)?.country_id || null
}

// ── Filter option lists (from server lookups / filter_options) ────────────────
const uniqueRecipients = computed(() => {
  const set = new Set(filterOptionRecipients.value.map(r => isBookbollRecipient(r) ? '@bookboll' : r))
  return [...set].sort().map(r => ({ value: r, label: r }))
})

const uniqueLocations = computed(() =>
  locations.value
    .slice()
    .sort((a, b) => a.name.localeCompare(b.name))
    .map(l => ({ value: l.id, label: l.name }))
)

const uniqueCurrencies = computed(() => {
  const set = new Set(filterOptionCurrencies.value.filter(Boolean))
  return [...set].sort().map(c => ({ value: c, label: c }))
})

const uniqueListings = computed(() =>
  listings.value
    .slice()
    .sort((a, b) => a.name.localeCompare(b.name))
    .map(l => ({ value: l.id, label: l.name }))
)

const uniquePrograms = computed(() => {
  const ids = new Set(payments.value.map(paymentProgramId).filter(Boolean))
  return programs.value
    .filter(p => ids.has(p.id))
    .sort((a, b) => (a.name || '').localeCompare(b.name || ''))
    .map(p => ({ value: p.id, label: p.name || 'Program' }))
})

const uniqueCities = computed(() =>
  jCities.value
    .slice()
    .sort((a, b) => a.name.localeCompare(b.name))
    .map(c => ({ value: c.id, label: c.name }))
)

const uniqueCountries = computed(() =>
  jCountries.value
    .slice()
    .sort((a, b) => a.name.localeCompare(b.name))
    .map(c => ({ value: c.id, label: c.name }))
)

// ── Active filter count ───────────────────────────────────────────────────────
const activeFilterCount = computed(() => [
  statusFilter.value.length,
  sourceFilter.value.length,
  methodFilter.value.length,
  typeFilter.value.length,
  recipientFilter.value.length,
  currencyFilter.value.length,
  locationFilter.value.length,
  listingFilter.value.length,
  programFilter.value.length,
  amountMin.value ? 1 : 0,
  amountMax.value ? 1 : 0,
  dateFrom.value ? 1 : 0,
  dateTo.value ? 1 : 0,
].reduce((a, b) => a + b, 0))

function clearAllFilters() {
  search.value = ''
  statusFilter.value = []
  sourceFilter.value = []
  methodFilter.value = []
  typeFilter.value = []
  recipientFilter.value = []
  currencyFilter.value = []
  locationFilter.value = []
  listingFilter.value = []
  programFilter.value = []
  filterCountry.value = []
  filterCity.value = []
  amountMin.value = ''
  amountMax.value = ''
  dateFrom.value = ''
  dateTo.value = ''
  pmtPage.value = 1
  load()
}

// Current page rows from Laravel (already filtered + sorted + paginated).
const paginatedPayments = computed(() => payments.value)

// ── Export ────────────────────────────────────────────────────────────────────
const exportBusy = ref(false)
const showExportModal = ref(false)
async function doExport(useFilters) {
  if (!canExport.value) return
  if (!requirePremium('exports')) { showExportModal.value = false; return }
  if (exportBusy.value) return
  exportBusy.value = true
  try {
  const { rows, lookups } = await fetchExportPages(fetchPaymentsDashboard, useFilters ? paymentQuery() : {}, 'payments')
  if (!rows.length) { pushToast('No payments to export.', 'info'); return }
  const find = (key, id) => (lookups[key] || []).find(r => String(r.id) === String(id))
  const booking = r => find('bookings', r.booking_id)
  const subscription = r => find('subscriptions', r.subscription_id)
  const program = r => find('programs', subscription(r)?.program_id)
  const exportCustomer = r => r.customer_name || (r.source_type === 'subscription' ? subscription(r)?.customer_name : find('customers', booking(r)?.customer_id)?.full_name) || '—'
  const exportSource = r => r.source_type === 'subscription' ? program(r)?.name || 'Subscription' : find('listings', booking(r)?.listing_id)?.name || r.listing_name || '—'
  const exportVenue = r => find('locations', r.source_type === 'subscription' ? program(r)?.location_id : booking(r)?.location_id)?.name || r.location_name || '—'
  downloadCSV(rows, [
    { label: 'ID',         key: 'id' },
    { label: 'Date',       fn: r => (r.paid_at || r.created_at || '').slice(0,10) },
    { label: 'Customer',   fn: r => exportCustomer(r) },
    { label: 'Source',     fn: r => exportSource(r) },
    { label: 'Venue',   fn: r => exportVenue(r) },
    { label: 'Type',       fn: r => typeLabel(r.payment_type) },
    { label: 'Recipient',  fn: r => isBookbollRecipient(r.recipient) ? 'Bookboll' : 'Provider' },
    { label: 'Method',     fn: r => methodLabel(r.method) },
    { label: 'Amount',     key: 'amount' },
    { label: 'Currency',   key: 'currency' },
    { label: 'Status',     fn: r => statusLabel(r.status) },
  ], 'payments')
  showExportModal.value = false
  } catch (e) { pushToast(e.message, 'error') } finally { exportBusy.value = false }
}

const totals = computed(() => ({
  total:      serverStats.value.total || [],
  toBookboll: serverStats.value.to_bookboll || [],
  toProvider: serverStats.value.to_provider || [],
  pending:    serverStats.value.pending || 0,
  // Per-currency pending/uncollected amounts when the API provides them; the
  // count alone cannot be shown as money.
  pendingAmount: Array.isArray(serverStats.value.pending_amount) ? serverStats.value.pending_amount : [],
}))
const kpiLines = (arr) => (arr && arr.length) ? arr : ['—']

// ── Badge/label helpers ───────────────────────────────────────────────────────
const typeBadge = (t) => ({ deposit:'badge-info', remaining:'badge-warning', full:'badge-success', booking:'badge-success', subscription_commission:'badge-info', subscription_earning:'badge-success' }[t] || 'badge-neutral')
const statusBadge = (s) => ({ paid:'badge-success', pending:'badge-warning', refunded:'badge-error', on_hold:'badge-warning', available_for_payout:'badge-info' }[s] || 'badge-neutral')
const recipientBadge = (r) => isBookbollRecipient(r) ? 'badge-info' : 'badge-neutral'
const typeLabel = (t) => ({ deposit:'Deposit', remaining:'Remaining', full:'Full Payment', booking:'Full Payment', subscription_commission:'Commission', subscription_earning:'Subscription' }[t] || t)
const statusLabel = (s) => ({ paid:'Paid', pending:'Pending', refunded:'Refunded', on_hold:'On Hold', available_for_payout:'Available for Payout' }[s] || s)
const methodLabel = (m) => ({ online:'Online', cash:'Cash', card:'Card', credit_card:'Card', cheque:'Cheque', wire_transfer:'Wire Transfer' }[m] || m)

// ── Sort icon helper ──────────────────────────────────────────────────────────
function sortIcon(key) { return sortKey.value === key ? (sortDir.value === 'asc' ? 'asc' : 'desc') : 'none' }

// ── Payment detail drawer ──────────────────────────────────────────────────────
const detailPayment = ref(null)
function openDetail(p) { detailPayment.value = p }
function closeDetail() { detailPayment.value = null }
const detailBooking = computed(() => detailPayment.value && detailPayment.value.source_type !== 'subscription'
  ? bookingRef(detailPayment.value.booking_id) : null)
const detailSubscription = computed(() => {
  const p = detailPayment.value
  return p?.subscription_id ? subscriptions.value.find(s => s.id === p.subscription_id) : null
})
// Every payment tied to the same booking / subscription (deposit + remaining, or each
// subscription charge) — the payment group.
const detailRelatedPayments = computed(() => {
  const p = detailPayment.value
  if (!p) return []
  const pool = relatedPayments.value.length ? relatedPayments.value : payments.value
  return pool
    .filter(x => p.subscription_id ? x.subscription_id === p.subscription_id : (p.booking_id && x.booking_id === p.booking_id))
    .sort((a, b) => paymentDate(a) - paymentDate(b))
})

// ── Go deeper: full booking modal / the subscription record ───────────────────
const bookingModalId = ref(null)
function openBookingDetail(p) {
  const bid = p?.booking_id
  if (!bid) return
  detailPayment.value = null          // close the transaction drawer first
  bookingModalId.value = bid
}
function goToSubscription(p) {
  const sid = p?.subscription_id
  if (!sid) return
  detailPayment.value = null
  router.push({ path: '/subscriptions', query: { sub: sid } })   // Subscribers.vue auto-opens it
}

// ── Print receipt (paid transactions) — self-contained printable window ────────
const businessNameFor = (p) => {
  const ownerId = p.source_type === 'subscription'
    ? subOwnerUserId(p)
    : (locations.value.find(l => l.id === paymentLocationId(p))?.owner_user_id || null)
  const u = adminUsers.value.find(x => x.id === ownerId)
  return u?.business_name || u?.full_name || 'Bookboll'
}
function printReceipt(p) {
  const money = (n) => `${p.currency} ${Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
  const esc = (s) => String(s ?? '').replace(/[&<>]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]))
  const rows = [
    ['Reference', paymentRef(p)],
    ['Date', paymentDate(p).toLocaleString()],
    ['Customer', paymentCustomer(p)],
    [p.source_type === 'subscription' ? 'Program' : 'Listing', paymentSource(p)],
    ['Location', paymentLocation(p) || '—'],
    ['Payment type', typeLabel(p.payment_type)],
    ['Method', methodLabel(p.method)],
    ['Received by', isBookbollRecipient(p.recipient) ? 'Bookboll' : businessNameFor(p)],
  ]
  const w = window.open('', '_blank', 'width=460,height=680')
  if (!w) return
  w.document.write(`<!doctype html><html><head><meta charset="utf-8"><title>Receipt ${esc(paymentRef(p))}</title>
    <style>
      *{box-sizing:border-box} body{font-family:-apple-system,Segoe UI,Roboto,sans-serif;color:var(--text, #0f172a);margin:0;padding:28px;}
      .r-wrap{max-width:400px;margin:0 auto;}
      .r-top{text-align:center;border-bottom:2px solid #0f172a;padding-bottom:14px;margin-bottom:16px;}
      .r-biz{font-size:20px;font-weight:800;}
      .r-sub{font-size:12px;color:var(--text-muted, #64748b);margin-top:2px;}
      .r-title{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text-muted, #64748b);margin:0 0 10px;}
      .r-amount{text-align:center;font-size:30px;font-weight:900;margin:6px 0 4px;}
      .r-paid{text-align:center;display:inline-block;width:100%;color:var(--positive-ink, #166534);font-weight:800;font-size:13px;letter-spacing:.04em;text-transform:uppercase;margin-bottom:18px;}
      table{width:100%;border-collapse:collapse;font-size:13.5px;}
      td{padding:7px 0;border-bottom:1px solid #eef2f6;vertical-align:top;}
      td.k{color:var(--text-muted, #64748b);width:42%;} td.v{text-align:right;font-weight:600;}
      .r-foot{margin-top:22px;text-align:center;font-size:11px;color:var(--text-light, #94a3b8);line-height:1.6;}
</style></head><body><div class="r-wrap">
      <div class="r-top"><div class="r-biz">${esc(businessNameFor(p))}</div><div class="r-sub">Payment receipt · via Bookboll</div></div>
      <div class="r-title">Amount paid</div>
      <div class="r-amount">${esc(money(p.amount))}</div>
      <div class="r-paid">✓ ${esc(statusLabel(p.status))}</div>
      <table>${rows.map(([k, v]) => `<tr><td class="k">${esc(k)}</td><td class="v">${esc(v)}</td></tr>`).join('')}${p.notes ? `<tr><td class="k">Notes</td><td class="v">${esc(p.notes)}</td></tr>` : ''}</table>
      <div class="r-foot">This is a computer-generated receipt.<br>Thank you for your booking.</div>
    </div><script>window.onload=function(){window.print()}<\/script></body></html>`)
  w.document.close()
}

// ── Stripe invoice (online payments) — opens Stripe's hosted invoice/receipt,
// which has its own print/download controls. Nothing is generated locally.
function openStripeInvoice(p) {
  const url = stripeInvoiceUrl(p)
  if (url) window.open(url, '_blank', 'noopener')
}

// ── Mark-as-paid / Accept payment ────────────────────────────────────────────
// Pending offline booking installments can be collected via BookingDetailModal's
// Accept Payment sheet → PUT /admin/bookings/{id}/payments/{paymentId}/collect.
function canMarkPaid(payment) {
  if (!can(CAP.ACCEPT_PAYMENTS)) return false
  if (!payment?.booking_id) return false
  if (payment.source_type === 'subscription') return false
  if (payment.status !== 'pending' && payment.status !== 'unpaid') return false
  // Online rows are already charged via Stripe — staff collects cash/card/wire here.
  if (payment.method === 'online') return false
  if (isSuperAdmin.value) return true
  // Provider / staff: collect when the money is not Bookboll platform revenue.
  if (isBookbollRecipient(payment.recipient)) return false
  return true
}

function openMarkPaid(payment) {
  if (!canMarkPaid(payment)) return
  detailPayment.value = null
  collectPmtId.value = String(payment.id)
  bookingModalId.value = String(payment.booking_id)
}
</script>

<template>
  <ExportDialog :open="showExportModal" title="Export payments" noun="payments" :filtered-count="pmtTotal" :busy="exportBusy" @close="showExportModal = false" @export="doExport" />
  <div class="page">
    <div class="page-header">
      <div>
        <h1 class="page-title">Payments</h1>
        <div class="page-subtitle">{{ isSuperAdmin ? 'All financial transactions and reconciliation' : 'Your incoming payments and settlement status' }}</div>
      </div>
      <button v-if="canExport" class="btn btn-secondary" @click="showExportModal = true"><Download :size="16" />Export<span v-if="!isPremium" class="prem-lock">Premium</span></button>
    </div>

    <!-- KPI cards -->
    <div class="grid grid-4">
      <div class="card kpi">
        <div class="kpi-top"><div class="kpi-label">GMV amount</div><div class="kpi-icon" style="background: var(--positive-bg);color: var(--positive-ink)"><DollarSign :size="18" /></div></div>
        <div class="kpi-value kpi-multi"><div v-for="l in kpiLines(totals.total)" :key="l">{{ l }}</div></div>
        <div class="fin-help">Gross value of all recorded payments.</div>
      </div>
      <div v-if="isSuperAdmin" class="card kpi">
        <div class="kpi-top"><div class="kpi-label">Bookboll’s amount</div><div class="kpi-icon" style="background: var(--primary-soft);color: var(--primary-ink)"><TrendingUp :size="18" /></div></div>
        <div class="kpi-value kpi-multi"><div v-for="l in kpiLines(totals.toBookboll)" :key="l">{{ l }}</div></div>
        <div class="fin-help">Platform fees and operated-venue receipts recorded for Bookboll.</div>
      </div>
      <div class="card kpi">
        <div class="kpi-top"><div class="kpi-label">{{ isSuperAdmin ? 'Provider’s amount' : 'Your amount' }}</div><div class="kpi-icon" style="background: var(--info-bg);color: var(--info-ink)"><Wallet :size="18" /></div></div>
        <div class="kpi-value kpi-multi"><div v-for="l in kpiLines(totals.toProvider)" :key="l">{{ l }}</div></div>
        <div v-if="isSuperAdmin" class="fin-help">Recorded for providers · legacy recipient labels, not a Bookboll payable.</div>
      </div>
      <div class="card kpi">
        <div class="kpi-top"><div class="kpi-label">Pending / uncollected amount</div><div class="kpi-icon" style="background: var(--warning-bg);color: var(--warning-ink)"><RotateCcw :size="18" /></div></div>
        <div v-if="totals.pendingAmount.length" class="kpi-value kpi-multi"><div v-for="l in totals.pendingAmount" :key="l">{{ l }}</div></div>
        <div v-else class="kpi-value">—</div>
        <div class="fin-help">{{ totals.pending.toLocaleString() }} pending payments{{ totals.pendingAmount.length ? '' : ' · amount not provided by the API yet' }}</div>
      </div>
    </div>

    <FilterBar
      v-model:search="search"
      v-model:country="filterCountry"
      v-model:city="filterCity"
      :countries="fbCountries"
      :cities="fbCities"
      :extra-count="activeFilterCount"
      search-placeholder="Search ref, customer, listing, location…"
      @clear="clearAllFilters"
    >
        <!-- Row 2: multi-select filters -->
        <div class="filter-row filter-row-chips">
          <div class="filter-group">
            <label class="filter-label">Status</label>
            <MultiSelect
              v-model="statusFilter"
              placeholder="All Statuses"
              :options="[
                { value:'pending', label:'Pending' },
                { value:'paid', label:'Paid' },
                { value:'on_hold', label:'On Hold' },
                { value:'available_for_payout', label:'Available for Payout' },
                { value:'refunded', label:'Refunded' },
              ]"
            />
          </div>
          <div class="filter-group">
            <label class="filter-label">Source</label>
            <!-- What product the money came from — not to be confused with the
                 Bookings page's "Booked via" channel. Booking payments split into
                 venue slots vs event/game seats via the linked booking. -->
            <MultiSelect
              v-model="sourceFilter"
              placeholder="All Sources"
              :options="[
                { value:'venue', label:'Venue booking' },
                { value:'event', label:'Event / game' },
                { value:'subscription', label:'Membership billing' },
              ]"
            />
          </div>
          <div class="filter-group">
            <label class="filter-label">Payment Type</label>
            <MultiSelect
              v-model="typeFilter"
              placeholder="All Types"
              :options="[
                { value:'full', label:'Full Payment' },
                { value:'deposit', label:'Deposit' },
                { value:'remaining', label:'Remaining balance' },
                { value:'commission', label:'Commission' },
                { value:'earning', label:'Provider earning' },
                { value:'service_fee', label:'Service fee' },
              ]"
            />
          </div>
          <div class="filter-group">
            <label class="filter-label">Method</label>
            <MultiSelect
              v-model="methodFilter"
              placeholder="All Methods"
              :options="[
                { value:'online', label:'Online' },
                { value:'cash', label:'Cash' },
                { value:'card', label:'Card' },
                { value:'cheque', label:'Cheque' },
                { value:'wire_transfer', label:'Wire Transfer' },
              ]"
            />
          </div>
          <div v-if="isSuperAdmin" class="filter-group">
            <label class="filter-label">Recipient</label>
            <MultiSelect
              v-model="recipientFilter"
              placeholder="All Recipients"
              :options="uniqueRecipients"
              :maxDisplay="1"
            />
          </div>
          <div class="filter-group">
            <label class="filter-label">Currency</label>
            <MultiSelect
              v-model="currencyFilter"
              placeholder="All Currencies"
              :options="uniqueCurrencies"
            />
          </div>
          <!-- Anyone overseeing MORE THAN ONE venue with payments can narrow to
               one — country admins and multi-venue providers included. -->
          <div v-if="uniqueLocations.length > 1" class="filter-group">
            <label class="filter-label">Location</label>
            <MultiSelect
              v-model="locationFilter"
              placeholder="All Locations"
              :options="uniqueLocations"
              :maxDisplay="1"
            />
          </div>
          <div v-if="uniqueListings.length" class="filter-group">
            <label class="filter-label">Listing</label>
            <MultiSelect
              v-model="listingFilter"
              placeholder="All Listings"
              :options="uniqueListings"
              :maxDisplay="1"
            />
          </div>
          <div v-if="uniquePrograms.length" class="filter-group">
            <label class="filter-label">Program</label>
            <MultiSelect
              v-model="programFilter"
              placeholder="All Programs"
              :options="uniquePrograms"
              :maxDisplay="1"
            />
          </div>
        </div>

        <!-- Row 3: amount range + date range + sort -->
        <div class="filter-row filter-row-range">
          <div class="filter-group">
            <label class="filter-label">Amount Range</label>
            <div class="range-pair">
              <input aria-label="Minimum amount" v-model="amountMin" type="number" placeholder="Min" class="range-input" />
              <span class="range-sep">—</span>
              <input aria-label="Maximum amount" v-model="amountMax" type="number" placeholder="Max" class="range-input" />
            </div>
          </div>
          <div class="filter-group">
            <label class="filter-label">Date Range</label>
            <div class="range-pair">
              <input aria-label="Payment start date" v-model="dateFrom" type="date" class="range-input date-input" />
              <span class="range-sep">—</span>
              <input aria-label="Payment end date" v-model="dateTo" type="date" class="range-input date-input" />
            </div>
          </div>
          <div class="filter-group sort-group">
            <label class="filter-label">Sort By</label>
            <div class="sort-pills">
              <button v-for="s in [
                { key:'date', label:'Date' },
                { key:'amount', label:'Amount' },
                { key:'customer', label:'Customer' },
                { key:'status', label:'Status' },
                { key:'method', label:'Method' },
                { key:'recipient', label:'Recipient' },
              ]" :key="s.key"
                class="sort-pill"
                :class="{ active: sortKey === s.key }"
                @click="setSort(s.key)"
              >
                {{ s.label }}
                <ChevronUp v-if="sortKey === s.key && sortDir === 'asc'" :size="11" />
                <ChevronDown v-if="sortKey === s.key && sortDir === 'desc'" :size="11" />
                <ArrowUpDown v-if="sortKey !== s.key" :size="11" class="sort-idle" />
              </button>
            </div>
          </div>
        </div>
    </FilterBar>

    <div class="card" style="margin-top:20px;">
      <!-- Table -->
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th class="th-sort" @click="setSort('date')">
                Reference
                <ArrowUpDown v-if="sortKey !== 'date'" :size="12" class="th-sort-icon idle" />
                <ChevronUp v-else-if="sortDir === 'asc'" :size="12" class="th-sort-icon" />
                <ChevronDown v-else :size="12" class="th-sort-icon" />
              </th>
              <th>Origin</th>
              <th class="th-sort" @click="setSort('customer')">
                Customer
                <ArrowUpDown v-if="sortKey !== 'customer'" :size="12" class="th-sort-icon idle" />
                <ChevronUp v-else-if="sortDir === 'asc'" :size="12" class="th-sort-icon" />
                <ChevronDown v-else :size="12" class="th-sort-icon" />
              </th>
              <th class="th-sort" @click="setSort('source')">
                Source / Location
                <ArrowUpDown v-if="sortKey !== 'source'" :size="12" class="th-sort-icon idle" />
                <ChevronUp v-else-if="sortDir === 'asc'" :size="12" class="th-sort-icon" />
                <ChevronDown v-else :size="12" class="th-sort-icon" />
              </th>
              <th>Type</th>
              <th class="th-sort" @click="setSort('method')">
                Method
                <ArrowUpDown v-if="sortKey !== 'method'" :size="12" class="th-sort-icon idle" />
                <ChevronUp v-else-if="sortDir === 'asc'" :size="12" class="th-sort-icon" />
                <ChevronDown v-else :size="12" class="th-sort-icon" />
              </th>
              <th class="th-sort" @click="setSort('amount')">
                Amount
                <ArrowUpDown v-if="sortKey !== 'amount'" :size="12" class="th-sort-icon idle" />
                <ChevronUp v-else-if="sortDir === 'asc'" :size="12" class="th-sort-icon" />
                <ChevronDown v-else :size="12" class="th-sort-icon" />
              </th>
              <th class="th-sort" @click="setSort('recipient')">
                Recipient
                <ArrowUpDown v-if="sortKey !== 'recipient'" :size="12" class="th-sort-icon idle" />
                <ChevronUp v-else-if="sortDir === 'asc'" :size="12" class="th-sort-icon" />
                <ChevronDown v-else :size="12" class="th-sort-icon" />
              </th>
              <th class="th-sort" @click="setSort('status')">
                Status
                <ArrowUpDown v-if="sortKey !== 'status'" :size="12" class="th-sort-icon idle" />
                <ChevronUp v-else-if="sortDir === 'asc'" :size="12" class="th-sort-icon" />
                <ChevronDown v-else :size="12" class="th-sort-icon" />
              </th>
              <th class="th-sort" @click="setSort('date')">
                Date
                <ArrowUpDown v-if="sortKey !== 'date'" :size="12" class="th-sort-icon idle" />
                <ChevronUp v-else-if="sortDir === 'asc'" :size="12" class="th-sort-icon" />
                <ChevronDown v-else :size="12" class="th-sort-icon" />
              </th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading">
              <td colspan="11" class="empty"><Loader :size="18" class="spin" /> Loading…</td>
            </tr>
            <tr v-else-if="!paginatedPayments.length">
              <td colspan="11" class="empty">No payment records match the current filters</td>
            </tr>
            <tr v-for="p in paginatedPayments" :key="p.id" class="pay-row" :class="{ 'row-sub': p.source_type === 'subscription' }" @click="openDetail(p)">
              <td>
                <span class="mono">{{ paymentRef(p) }}</span>
                <div v-if="paymentBookingRef(p)" class="cell-sub mono">{{ paymentBookingRef(p) }}</div>
              </td>
              <td>
                <span class="source-chip" :class="p.source_type === 'subscription' ? 'sub' : 'booking'">
                  {{ p.source_type === 'subscription' ? 'Subscription' : 'Booking' }}
                </span>
              </td>
              <td>{{ paymentCustomer(p) }}</td>
              <td>
                <div>{{ paymentSource(p) }}</div>
                <div v-if="paymentLocation(p)" class="sub-text">{{ paymentLocation(p) }}</div>
              </td>
              <td><span class="badge" :class="typeBadge(p.payment_type)">{{ typeLabel(p.payment_type) }}</span></td>
              <td><span class="method-chip" :class="p.method">{{ methodLabel(p.method) }}</span></td>
              <td class="amount-cell">{{ p.currency }} {{ Number(p.amount).toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2}) }}</td>
              <td>
                <span class="badge" :class="recipientBadge(p.recipient)">
                  <Building2 :size="11" style="margin-right:3px" />{{ isBookbollRecipient(p.recipient) ? '@bookboll' : p.recipient }}
                </span>
              </td>
              <td><span class="badge" :class="statusBadge(p.status)">{{ statusLabel(p.status) }}</span></td>
              <td class="date-cell">
                <div>{{ paymentDate(p).toLocaleDateString() }}</div>
                <div v-if="p.marked_paid_by" class="sub-text">by {{ markedByName(p.marked_paid_by) }}</div>
              </td>
              <td class="actions-cell" @click.stop>
                <div class="row-actions">
                  <button class="btn-icon" title="View transaction details" @click="openDetail(p)"><Eye :size="16" /></button>
                  <button v-if="p.status === 'paid'" class="btn-icon" title="Print receipt" @click="printReceipt(p)"><Printer :size="16" /></button>
                  <button v-if="isStripeOnlinePayment(p)" class="btn-icon" :class="{ 'is-disabled': !stripeInvoiceUrl(p) }" :disabled="!stripeInvoiceUrl(p)" :title="stripeInvoiceTitle(p)" :aria-label="stripeInvoiceTitle(p)" @click="openStripeInvoice(p)"><FileText :size="16" /></button>
                  <button v-if="canMarkPaid(p)" class="btn btn-primary btn-sm act-accept" title="Accept payment" @click="openMarkPaid(p)"><Check :size="13" />Accept</button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <PaginationBar
        :page="pmtPage" :perPage="pmtPerPage" :totalPages="pmtTotalPages"
        :total="pmtTotal" :visiblePages="pmtVisiblePages"
        @update:perPage="pmtPerPage = $event"
        @goTo="pmtGoTo"
      />
    </div>

    <!-- ── Payment detail drawer ─────────────────────────────────────────── -->
    <div v-if="detailPayment" class="drawer-backdrop" @click.self="closeDetail">
      <div class="pay-drawer" v-dialog="closeDetail" aria-label="Payment details">
        <div class="pd-head">
          <div class="pd-head-main">
            <div class="pd-ref mono">{{ paymentRef(detailPayment) }}</div>
            <div class="pd-amount">{{ detailPayment.currency }} {{ Number(detailPayment.amount).toLocaleString(undefined,{minimumFractionDigits:2}) }}</div>
            <div class="pd-badges">
              <span class="source-chip" :class="detailPayment.source_type === 'subscription' ? 'sub' : 'booking'">{{ detailPayment.source_type === 'subscription' ? 'Subscription' : 'Booking' }}</span>
              <span class="badge" :class="statusBadge(detailPayment.status)">{{ statusLabel(detailPayment.status) }}</span>
            </div>
          </div>
          <button class="btn-icon" @click="closeDetail" aria-label="Close"><X :size="18"/></button>
        </div>

        <div class="pd-body">
          <div class="pd-section">
            <div class="pd-grid">
              <div class="pd-field"><span>Type</span><b>{{ typeLabel(detailPayment.payment_type) }}</b></div>
              <div class="pd-field"><span>Method</span><b>{{ methodLabel(detailPayment.method) }}</b></div>
              <div class="pd-field"><span>Customer</span><b>{{ paymentCustomer(detailPayment) }}</b></div>
              <div class="pd-field"><span>{{ detailPayment.source_type === 'subscription' ? 'Program' : 'Listing' }}</span><b>{{ paymentSource(detailPayment) }}</b></div>
              <div class="pd-field"><span>Location</span><b>{{ paymentLocation(detailPayment) || '—' }}</b></div>
              <div class="pd-field"><span>Recipient</span><b>{{ isBookbollRecipient(detailPayment.recipient) ? 'Bookboll' : 'Provider' }}</b></div>
              <div class="pd-field"><span>Date</span><b>{{ paymentDate(detailPayment).toLocaleString() }}</b></div>
              <div v-if="detailPayment.marked_paid_by" class="pd-field"><span>Marked paid by</span><b>{{ markedByName(detailPayment.marked_paid_by) }}</b></div>
              <div v-if="detailPayment.notes" class="pd-field pd-wide"><span>Notes</span><b>{{ detailPayment.notes }}</b></div>
            </div>
          </div>

          <div v-if="detailBooking" class="pd-section">
            <div class="pd-section-title">Booking</div>
            <div class="pd-grid">
              <div class="pd-field"><span>Date</span><b>{{ detailBooking.booking_date || '—' }}</b></div>
              <div class="pd-field"><span>Time</span><b>{{ detailBooking.start_time ? fmt12(detailBooking.start_time) : '—' }}<template v-if="detailBooking.end_time"> – {{ fmt12(detailBooking.end_time) }}</template></b></div>
              <div class="pd-field"><span>Payment status</span><b>{{ detailBooking.payment_status || '—' }}</b></div>
            </div>
            <button class="pd-deeper" @click="openBookingDetail(detailPayment)">
              <CalendarDays :size="15" /> View full booking details <ChevronRight :size="15" />
            </button>
          </div>

          <div v-if="detailSubscription" class="pd-section">
            <div class="pd-section-title">Subscription</div>
            <div class="pd-grid">
              <div class="pd-field"><span>Status</span><b>{{ detailSubscription.status || '—' }}</b></div>
              <div class="pd-field"><span>Monthly</span><b>{{ detailPayment.currency }} {{ detailSubscription.monthly_amount ?? '—' }}</b></div>
              <div class="pd-field"><span>Started</span><b>{{ (detailSubscription.created_at || '').slice(0,10) || '—' }}</b></div>
            </div>
            <button class="pd-deeper" @click="goToSubscription(detailPayment)">
              <GraduationCap :size="15" /> View subscription &amp; billing history <ChevronRight :size="15" />
            </button>
          </div>

          <div v-if="detailRelatedPayments.length > 1" class="pd-section">
            <div class="pd-section-title">All payments for this {{ detailPayment.source_type === 'subscription' ? 'subscription' : 'booking' }} ({{ detailRelatedPayments.length }})</div>
            <div class="pd-ledger">
              <div v-for="rp in detailRelatedPayments" :key="rp.id" class="pd-ledger-row" :class="{ current: rp.id === detailPayment.id }">
                <span class="badge" :class="typeBadge(rp.payment_type)">{{ typeLabel(rp.payment_type) }}</span>
                <span class="pd-ledger-amt">{{ rp.currency }} {{ Number(rp.amount).toLocaleString(undefined,{minimumFractionDigits:2}) }}</span>
                <span class="badge" :class="statusBadge(rp.status)">{{ statusLabel(rp.status) }}</span>
                <span class="pd-ledger-date">{{ paymentDate(rp).toLocaleDateString() }}</span>
              </div>
            </div>
          </div>
        </div>

        <div class="pd-foot">
          <button v-if="detailPayment.status === 'paid'" class="btn btn-secondary" @click="printReceipt(detailPayment)"><Printer :size="15"/>Print receipt</button>
          <button v-if="isStripeOnlinePayment(detailPayment)" class="btn btn-secondary" :disabled="!stripeInvoiceUrl(detailPayment)" :title="stripeInvoiceTitle(detailPayment)" @click="openStripeInvoice(detailPayment)"><FileText :size="15"/>Stripe invoice</button>
          <button v-if="canMarkPaid(detailPayment)" class="btn btn-primary" @click="openMarkPaid(detailPayment)"><Check :size="15"/>Accept payment</button>
          <button class="btn btn-secondary" @click="closeDetail">Close</button>
        </div>
      </div>
    </div>

    <!-- Full booking details (opened from a booking transaction) -->
    <BookingDetailModal v-if="bookingModalId" :booking-id="bookingModalId" :collect-payment-id="collectPmtId"
      @close="bookingModalId = null; collectPmtId = null; load()" />

    <!-- ── Export modal ──────────────────────────────────────────────────── -->

  </div>
</template>

<style scoped>
.spin { animation: spin 0.8s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
/* ── Export modal ─────────────────────────────────────────────────────────── */
.export-choice-btn { display:flex;align-items:center;gap:14px;padding:13px 16px;background:var(--bg);border:1.5px solid var(--border);border-radius:10px;cursor:pointer;text-align:left;width:100%;transition:all .15s; }
.export-choice-btn:hover { border-color:var(--primary);background:var(--info-bg, #f0f9ff); }
.export-choice-btn svg { color:var(--primary-ink);flex-shrink:0; }
.export-choice-btn strong { font-size:13.5px;color:var(--text); }
.export-choice-btn span { font-size:12px;color:var(--text-muted); }

/* ── Filter panel ─────────────────────────────────────────────────────────── */
.filter-header {
  display: flex; align-items: center; justify-content: space-between;
  padding: 14px 20px; border-bottom: 1px solid var(--border);
}
.filter-header-left { display: flex; align-items: center; gap: 8px; color: var(--text); }
.filter-title { font-size: 14px; font-weight: 600; }
.filter-badge {
  display: inline-flex; align-items: center; justify-content: center;
  min-width: 20px; height: 20px; padding: 0 6px;
  background: var(--primary); color: #fff;
  border-radius: 99px; font-size: 11px; font-weight: 700;
}
.filter-header-right { display: flex; align-items: center; gap: 6px; }

.btn-clear-all {
  display: inline-flex; align-items: center; gap: 4px;
  padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: 500;
  background: var(--danger-bg, #fee2e2); color: var(--danger-ink, #991b1b); border: 1px solid var(--danger-border, #fca5a5); cursor: pointer;
  transition: background .15s;
}
.btn-clear-all:hover { background: #fecaca; }

.filter-body { padding: 16px 20px; display: flex; flex-direction: column; gap: 16px; border-bottom: 1px solid var(--border); }

.filter-row { display: flex; align-items: flex-end; gap: 12px; flex-wrap: wrap; }
.filter-row-chips { align-items: flex-end; }
.filter-row-range { align-items: flex-end; }

.filter-group { display: flex; flex-direction: column; gap: 5px; }
.filter-label { font-size: 11px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: .05em; }

.filter-search { flex: 1; min-width: 260px; }
.filter-search input { width: 100%; }

.search-clear {
  position: absolute; right: 10px; top: 50%; transform: translateY(-50%);
  background: none; border: none; cursor: pointer; color: var(--text-muted); padding: 2px;
  display: flex; align-items: center;
}
.search-clear:hover { color: var(--text); }
.search { position: relative; }

/* Amount / date range */
.range-pair { display: flex; align-items: center; gap: 6px; }
.range-input {
  width: 90px; height: 36px; padding: 0 10px;
  border: 1px solid var(--border); border-radius: 8px;
  background: var(--card); font-size: 13px; color: var(--text);
}
.range-input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(59,130,246,.12); }
.date-input { width: 130px; }
.range-sep { color: var(--text-muted); font-size: 13px; }

/* Sort pills */
.sort-group { flex: 1; }
.sort-pills { display: flex; gap: 6px; flex-wrap: wrap; }
.sort-pill {
  display: inline-flex; align-items: center; gap: 4px;
  padding: 5px 12px; border-radius: 20px; font-size: 12px; font-weight: 500;
  background: var(--bg); border: 1px solid var(--border);
  color: var(--text-muted); cursor: pointer; transition: all .15s;
}
.sort-pill:hover { border-color: var(--primary); color: var(--primary-ink); }
.sort-pill.active { background: var(--primary); color: #fff; border-color: var(--primary); }
.sort-idle { opacity: .4; }

/* Results footer */
.filter-footer { display: flex; align-items: center; padding-top: 4px; }
.result-count { font-size: 12px; color: var(--text-muted); }

/* ── Table ────────────────────────────────────────────────────────────────── */
.th-sort { cursor: pointer; user-select: none; white-space: nowrap; }
.th-sort:hover { color: var(--primary-ink); }
.th-sort-icon { margin-left: 3px; vertical-align: middle; color: var(--text-muted); }
.th-sort-icon.idle { opacity: .35; }

.mono { font-family: 'SF Mono', Monaco, monospace; font-size: 12px; color: var(--text-muted); }
.sub-text { font-size: 11px; color: var(--text-muted); margin-top: 2px; }
.amount-cell { font-weight: 600; font-size: 14px; }
.date-cell { font-size: 13px; }

.source-chip {
  display: inline-flex; align-items: center;
  padding: 1px 6px; border-radius: 99px;
  font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: .04em;
}
.source-chip.sub { background: var(--positive-bg, #ecfdf5); color: var(--positive-ink, #065f46); border: 1px solid var(--positive-border, #a7f3d0); }
.source-chip.booking { background: var(--info-bg, #eff6ff); color: var(--info-ink, #1e40af); border: 1px solid var(--info-border, #bfdbfe); }

.row-sub td { background: var(--primary-soft); }

.method-chip {
  display: inline-flex; align-items: center;
  padding: 2px 8px; border-radius: 99px;
  font-size: 12px; font-weight: 500;
  background: var(--bg); border: 1px solid var(--border); color: var(--text-muted);
}
.method-chip.online { background: var(--primary-soft, #ccfbf1); color: var(--primary-ink, #0f766e); border-color: var(--primary-border, #99f6e4); }
.method-chip.cash   { background: var(--positive-bg, #f0fdf4); color: var(--positive-ink, #166534); border-color: var(--positive-border, #bbf7d0); }
.method-chip.card   { background: var(--info-bg, #eff6ff); color: var(--info-ink, #1d4ed8); border-color: var(--info-border, #bfdbfe); }
.method-chip.cheque { background: var(--warning-bg, #fefce8); color: var(--warning-ink, #854d0e); border-color: var(--warning-border, #fde68a); }
.method-chip.wire_transfer { background: var(--purple-bg, #fdf4ff); color: var(--purple-ink, #7e22ce); border-color: var(--purple-border, #e9d5ff); }

.confirm-summary { background: var(--bg); border-radius: 8px; padding: 12px 14px; display: flex; flex-direction: column; gap: 8px; }
.confirm-row { display: flex; justify-content: space-between; font-size: 14px; }
.confirm-row span { color: var(--text-muted); }

.btn-icon { padding: 6px; border-radius: 6px; color: var(--text-muted); background: transparent; border: 0; cursor: pointer; }
.btn-icon:hover { background: var(--bg); color: var(--text); }
.btn-icon:disabled, .btn-icon.is-disabled { opacity: .4; cursor: not-allowed; background: transparent; }

/* Clickable rows → open detail drawer */
.pay-row { cursor: pointer; transition: background .1s; }
.pay-row:hover td { background: var(--bg, #f1f5f9); }
.row-sub.pay-row:hover td { background: var(--surface-hover); }

/* ── Payment detail drawer ──────────────────────────────────────────────────── */
.drawer-backdrop { position: fixed; inset: 0; background: rgba(15,23,42,.45); display: flex; justify-content: flex-end; z-index: 60; }
.pay-drawer { width: 460px; max-width: 100%; height: 100%; background: var(--surface, #ffffff); display: flex; flex-direction: column; box-shadow: -8px 0 30px rgba(0,0,0,.18); animation: pdslide .18s ease-out; }
@keyframes pdslide { from { transform: translateX(24px); opacity: .6; } to { transform: none; opacity: 1; } }
.pd-head { display: flex; align-items: flex-start; justify-content: space-between; padding: 18px 20px; border-bottom: 1px solid var(--border); }
.pd-ref { font-size: 12px; color: var(--text-muted); }
.pd-amount { font-size: 24px; font-weight: 800; color: var(--text); margin: 2px 0 8px; }
.pd-badges { display: flex; gap: 6px; flex-wrap: wrap; }
.pd-body { flex: 1; overflow-y: auto; padding: 16px 20px; display: flex; flex-direction: column; gap: 18px; }
.pd-section-title { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: var(--text-muted); margin-bottom: 10px; }
.pd-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px 16px; }
.pd-field { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.pd-field.pd-wide { grid-column: 1 / -1; }
.pd-field span { font-size: 11px; color: var(--text-muted); text-transform: uppercase; letter-spacing: .04em; }
.pd-field b { font-size: 13.5px; color: var(--text); font-weight: 600; word-break: break-word; }
.pd-ledger { display: flex; flex-direction: column; gap: 6px; }
.pd-ledger-row { display: grid; grid-template-columns: auto 1fr auto auto; align-items: center; gap: 8px; padding: 8px 10px; border: 1px solid var(--border); border-radius: 8px; font-size: 12.5px; }
.pd-ledger-row.current { border-color: var(--primary); background: var(--info-bg, #f0f9ff); }
.pd-ledger-amt { font-weight: 700; color: var(--text); }
.pd-ledger-date { color: var(--text-muted); font-size: 12px; }
.pd-foot { display: flex; justify-content: flex-end; gap: 8px; padding: 14px 20px; border-top: 1px solid var(--border); background: var(--surface-hover, #f9fafb); flex-wrap: wrap; }
/* "Go deeper" link inside a drawer section */
.pd-deeper { display: inline-flex; align-items: center; gap: 7px; margin-top: 12px; width: 100%; justify-content: center; padding: 9px 12px; border: 1.5px solid var(--border); border-radius: 10px; background: var(--surface, #ffffff); font-size: 13px; font-weight: 700; color: var(--primary-ink); cursor: pointer; transition: border-color .12s, background .12s; }
.pd-deeper:hover { border-color: var(--primary); background: var(--info-bg, #f0f9ff); }
.pd-deeper > svg:last-child { color: var(--text-light, #94a3b8); }
/* Row action icons */
.actions-cell { white-space: nowrap; }
.row-actions { display: inline-flex; align-items: center; gap: 2px; justify-content: flex-end; }
.act-accept { margin-left: 4px; }
@media (max-width: 600px) {
  /* ≥16px inputs so iOS doesn't auto-zoom; full-width flexible range fields */
  .range-input { font-size: 16px; height: 42px; width: 100%; flex: 1; min-width: 0; }
  .date-input { width: 100%; }
  .range-pair { width: 100%; }
  .filter-group { width: 100%; }
  .source-chip { font-size: 11px; }
}
@media (max-width: 520px) {
  .pay-drawer { width: 100%; }
  .pd-grid { grid-template-columns: 1fr; }
  /* Drawer footer: stack full-width buttons (primary action on top) */
  .pd-foot { flex-direction: column-reverse; }
  .pd-foot .btn { width: 100%; justify-content: center; min-height: 44px; }
  /* Ledger rows wrap to two lines instead of overflowing the drawer */
  .pd-ledger-row { grid-template-columns: auto 1fr; row-gap: 4px; }
  .pd-ledger-date { grid-column: 2; text-align: right; }
}
.kpi-multi div { line-height: 1.25; }
.kpi-multi div:not(:first-child) { font-size: 15px; color: var(--text-muted, #64748b); }
.cell-sub { font-size: 11.5px; color: var(--text-muted); margin-top: 2px; }
</style>
