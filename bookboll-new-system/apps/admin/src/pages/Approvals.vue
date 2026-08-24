<script setup>
import { ref, onMounted, computed, watch } from 'vue'
import { supabase } from '../lib/supabase.js'
import {
  CircleCheck as CheckCircle, Boxes,
  TriangleAlert as AlertTriangle, ArrowRight, RefreshCw, CirclePause as PauseCircle,
  X, GraduationCap, Banknote, Clock, Check, ChevronDown, ChevronUp, Search, Eye,
  ListChecks, Building2, MapPin, User, Phone, Mail, Globe, FileText, Shield, Star,
  CalendarDays, DollarSign, Copy
} from 'lucide-vue-next'
import PaginationBar from '../components/PaginationBar.vue'
import FilterBar from '../components/FilterBar.vue'
import { matchesLocationGeo } from '../lib/geoFilter.js'
import { usePagination } from '../lib/usePagination.js'
import { useCurrentUser } from '../lib/currentUser.js'
import { CAP } from '../lib/permissions.js'
import { logAudit, AUDIT } from '../lib/audit.js'
import { promoteTypesFor } from '../lib/customTypes.js'
import { notifyBusiness } from '../lib/adminNotify.js'
import { askConfirm } from '../lib/confirm.js'
import { askInput } from '../lib/inputPrompt.js'
import { pushToast } from '../lib/toast.js'
import MultiSelect from '../components/MultiSelect.vue'
import {
  firstError,
  fetchApprovalsDashboard,
  approveContent,
  requestContentChanges,
  bulkApproveContent,
} from '../lib/approvals.js'
import {
  firstError as moneyErr,
  fetchWalletPayoutsDashboard,
  approvePayoutKyc,
  rejectPayoutKyc,
  approveWithdrawal,
  markWithdrawalPaid,
  rejectWithdrawal,
} from '../lib/walletPayouts.js'

const { isSuperAdmin, role: actorRole, currentCountryId, assignedCountryIds, assignedLocationIds, currentUser, can } = useCurrentUser()

const filterCountry = ref([])
const filterCity = ref([])
const listings = ref([])
const programs = ref([])
const withdrawals = ref([])   // pending count only — payouts are processed in /wallet now
const wallets = ref([])
const allProviders = ref([])
const allLocations = ref([])
const allCities = ref([])
const allCountries = ref([])
const tab = ref('all')   // 'all' | 'content' (listings & programs) | 'money' (withdrawals & payouts)
const search = ref('')

async function load() {
  const [dash, money, ap, lo, ci, co] = await Promise.all([
    fetchApprovalsDashboard(),
    fetchWalletPayoutsDashboard(),
    supabase.from('admin_users').select('*').eq('role', 'provider_admin'),
    supabase.from('locations').select('*'),
    supabase.from('cities').select('*'),
    supabase.from('countries').select('*').eq('status', 'active'),
  ])
  if (!dash.success) {
    pushToast(firstError(dash, 'Could not load approvals queue.'), 'error')
    listings.value = []
    programs.value = []
  } else {
    listings.value = dash.data?.listings || []
    programs.value = dash.data?.programs || []
  }
  if (!money.success) {
    pushToast(moneyErr(money, 'Could not load payouts / withdrawals.'), 'error')
    withdrawals.value = []
    wallets.value = []
  } else {
    const open = (money.data?.withdrawals || []).filter(w => w.status === 'pending' || w.status === 'approved')
    withdrawals.value = open
    wallets.value = money.data?.wallets || []
  }
  // Prefer Supabase provider_admin rows for business_status / contact; merge Laravel owners if missing.
  const fromSb = ap.data || []
  const fromApi = dash.success ? (dash.data?.providers || []) : []
  const byId = new Map()
  for (const p of fromApi) byId.set(String(p.id), p)
  for (const p of fromSb) byId.set(String(p.id), p)
  allProviders.value = [...byId.values()]
  allLocations.value = lo.data || []
  allCities.value = ci.data || []
  allCountries.value = co.data || []
}
onMounted(load)
watch(() => currentUser.value?.id, load)

// ── Jurisdiction: country admins only review content in their countries; platform
// staff only their assigned venues (or inherited country scope); super admin sees all.
const jurisdictionCountryIds = computed(() => {
  if (isSuperAdmin.value) return null
  const assigned = (assignedCountryIds.value || []).filter(Boolean)
  if (assigned.length) return new Set(assigned)
  if (currentCountryId.value) return new Set([currentCountryId.value])
  return new Set()
})
function countryOfItem(item) {
  const loc = locationById(item.location_id)
  const city = loc && cityById(loc.city_id)
  return city?.country_id || null
}
function inJurisdiction(item) {
  if (isSuperAdmin.value) return true
  if (actorRole.value === 'platform_staff' && (assignedLocationIds.value || []).length) {
    return assignedLocationIds.value.includes(item.location_id)
  }
  const set = jurisdictionCountryIds.value
  if (!set) return true
  const cid = countryOfItem(item)
  return cid ? set.has(cid) : false
}

const providerName = (id) => {
  const p = allProviders.value.find(x => x.id === id)
  return p?.business_name || p?.full_name || '—'
}
const providerById = (id) => allProviders.value.find(x => x.id === id) || null
const locationById = (id) => allLocations.value.find(x => x.id === id) || null
const cityById = (id) => allCities.value.find(x => x.id === id) || null
const countryById = (id) => allCountries.value.find(x => x.id === id) || null
const countryName = (id) => countryById(id)?.name || ''

function locationForItem(item) {
  if (item._type === 'listing') return locationById(item.location_id)
  if (item._type === 'program') return locationById(item.location_id)
  return null
}

// The business owner a decision should notify: programs carry owner_user_id;
// listings resolve through their location.
function ownerOfItem(item) {
  return item.owner_user_id || locationById(item.location_id)?.owner_user_id || null
}

const previewTab = ref('details')

// Withdrawals pointer is jurisdiction-scoped too (it links to the now-scoped /wallet).
function withdrawalInJurisdiction(w) {
  if (isSuperAdmin.value) return true
  if (actorRole.value === 'platform_staff' && (assignedLocationIds.value || []).length) {
    const owners = new Set(allLocations.value.filter(l => assignedLocationIds.value.includes(l.id)).map(l => l.owner_user_id))
    return owners.has(w.owner_user_id)
  }
  const set = jurisdictionCountryIds.value
  if (!set) return true
  return w.country_id ? set.has(w.country_id) : false
}
const filteredWithdrawals = computed(() =>
  withdrawals.value.filter(w => withdrawalInJurisdiction(w) && providerMatch(w.owner_user_id)))

// ── Manual payout-method approval queue ───────────────────────────────────────
// Manual (Bookboll Wallet) payout submissions await Bookboll verification before going
// active. KYC + bank details are mirrored on every one of the business's country wallets,
// so we group the pending rows by owner and act on them together. (Moved here from
// Earnings & Payouts — it's a review/approval task, not a wallet-management one.)
const canApprovePayouts = computed(() => can(CAP.APPROVE_PAYOUTS))
function walletInJurisdiction(w) {
  if (isSuperAdmin.value) return true
  if (actorRole.value === 'platform_staff' && (assignedLocationIds.value || []).length) {
    const owners = new Set(allLocations.value.filter(l => assignedLocationIds.value.includes(l.id)).map(l => l.owner_user_id))
    return owners.has(w.owner_user_id)
  }
  const set = jurisdictionCountryIds.value
  if (!set) return true
  return w.country_id ? set.has(w.country_id) : false
}
const pendingPayoutSetups = computed(() => {
  const byOwner = new Map()
  for (const w of wallets.value) {
    if (w.setup_status !== 'pending_review') continue
    if (!walletInJurisdiction(w)) continue
    if (!providerMatch(w.owner_user_id)) continue
    if (!byOwner.has(w.owner_user_id)) byOwner.set(w.owner_user_id, { ownerId: w.owner_user_id, template: w, countries: [] })
    byOwner.get(w.owner_user_id).countries.push(w.country_id)
  }
  return Array.from(byOwner.values()).map(e => ({
    ...e,
    business: providerName(e.ownerId),
    countryNames: e.countries.map(countryName).filter(Boolean),
    submittedAt: e.template?.kyc_submitted_at,
  }))
})
async function approvePayoutSetup(entry) {
  if (!entry?.ownerId) return
  if (!(await askConfirm({
    title: `Approve payout method for ${entry.business}?`,
    message: `Bank: ${entry.template?.payout_bank_name || '—'} · ${entry.template?.payout_iban || entry.template?.payout_account_number || 'no account'}. Their payouts go ACTIVE — online membership money starts flowing to this account.`,
    danger: false, confirmText: 'Approve — activate payouts',
  }))) return
  const res = await approvePayoutKyc(entry.ownerId, { kyc_reviewed_by: currentUser.value?.id || null })
  if (!res.success) { pushToast(moneyErr(res, 'Could not approve payout method.'), 'error'); return }
  logAudit({ action: AUDIT.APPROVED, entity_type: 'payout_method', entity_id: entry.ownerId, details: `Approved manual payout method for ${entry.business}` })
  notifyBusiness({
    ownerUserId: entry.ownerId, kind: 'wallet', tone: 'ok',
    title: 'Payout method approved — payouts are live',
    sub: 'Online money now flows to your wallet', link: '/wallet',
  })
  load()
}
const payoutRejectModal = ref(null)   // the entry being rejected
const payoutRejectReason = ref('')
function openPayoutReject(entry) { payoutRejectModal.value = entry; payoutRejectReason.value = '' }
async function confirmPayoutReject() {
  const entry = payoutRejectModal.value; if (!entry) return
  const reason = payoutRejectReason.value.trim() || 'Your details could not be verified — please review and resubmit.'
  const res = await rejectPayoutKyc(entry.ownerId, {
    reason,
    kyc_reviewed_by: currentUser.value?.id || null,
  })
  payoutRejectModal.value = null
  if (!res.success) { pushToast(moneyErr(res, 'Could not reject payout method.'), 'error'); return }
  logAudit({ action: AUDIT.REJECTED, entity_type: 'payout_method', entity_id: entry.ownerId, details: `Rejected payout setup for ${entry.business}: ${reason}` })
  notifyBusiness({
    ownerUserId: entry.ownerId, kind: 'wallet', tone: 'warn',
    title: 'Payout method needs attention',
    sub: reason, link: '/wallet',
  })
  load()
}

const totalCount = computed(() =>
  filteredListings.value.length + filteredPrograms.value.length + filteredWithdrawals.value.length +
  (canApprovePayouts.value ? pendingPayoutSetups.value.length : 0)
)
const contentCount = computed(() => filteredListings.value.length + filteredPrograms.value.length)
const payoutCount = computed(() => canApprovePayouts.value ? pendingPayoutSetups.value.length : 0)
const withdrawalCount = computed(() => filteredWithdrawals.value.length)
const moneyCount = computed(() => payoutCount.value + withdrawalCount.value)

// Tabs: a unified "All" feed + focused "Listings & Programs" and "Withdrawals & Payouts" views.
const approvalTabs = computed(() => [
  { key: 'all',     label: 'All',                   count: totalCount.value },
  { key: 'content', label: 'Listings & Programs',   count: contentCount.value },
  { key: 'money',   label: 'Withdrawals & Payouts', count: moneyCount.value },
])
const showContentSection = computed(() => tab.value === 'all' || tab.value === 'content')
const showMoneySection   = computed(() => tab.value === 'all' || tab.value === 'money')
const activeCount = computed(() =>
  tab.value === 'content' ? contentCount.value : tab.value === 'money' ? moneyCount.value : totalCount.value)

// --- search filtering ---
const q = computed(() => search.value.toLowerCase().trim())

// Page-specific filters (in the FilterBar panel, like every table page)
const fType = ref([])        // multi: 'listing' | 'program'
const fProvider = ref([])    // multi: owner_user_id
const fResubOnly = ref(false)
const fSort = ref('waiting') // 'waiting' (oldest first) | 'newest'
const extraFilterCount = computed(() =>
  (fType.value.length ? 1 : 0) + (fProvider.value.length ? 1 : 0) + (fResubOnly.value ? 1 : 0) + (fSort.value !== 'waiting' ? 1 : 0))
function clearPageFilters() { fType.value = []; fProvider.value = []; fResubOnly.value = false; fSort.value = 'waiting' }

const geoMatch = (row) => matchesLocationGeo(row.location_id, allLocations.value, allCities.value, filterCountry.value, filterCity.value)
const textMatch = (row) => !q.value ||
  (row.name || '').toLowerCase().includes(q.value) ||
  (row.sport_category || '').toLowerCase().includes(q.value) ||
  providerName(row.owner_user_id).toLowerCase().includes(q.value)
const filteredListings = computed(() => listings.value.filter(l => inJurisdiction(l) && geoMatch(l) && textMatch(l)))
const filteredPrograms = computed(() => programs.value.filter(p => inJurisdiction(p) && geoMatch(p) && textMatch(p)))

// Provider filter options: every business with something in THIS reviewer's queue
// (jurisdiction applied first, so a country admin never sees other countries' businesses).
const providerOptions = computed(() => {
  const ids = new Set([
    ...listings.value.filter(l => inJurisdiction(l)).map(l => ownerOfItem({ ...l, _type: 'listing' })),
    ...programs.value.filter(p => inJurisdiction(p)).map(p => p.owner_user_id),
    ...withdrawals.value.map(w => w.owner_user_id),
    ...wallets.value.filter(w => w.setup_status === 'pending_review').map(w => w.owner_user_id),
  ].filter(Boolean))
  return [...ids].map(id => ({ value: id, label: providerName(id) }))
    .sort((a, b) => a.label.localeCompare(b.label))
})
const providerMatch = (ownerId) => !fProvider.value.length || fProvider.value.includes(ownerId)

// unified sorted content list: resubmissions first, then by waiting time
const filteredContent = computed(() => {
  let items = [
    ...filteredListings.value.map(l => ({ ...l, _type: 'listing' })),
    ...filteredPrograms.value.map(p => ({ ...p, _type: 'program' })),
  ]
  if (fType.value.length) items = items.filter(i => fType.value.includes(i._type))
  if (fProvider.value.length) items = items.filter(i => providerMatch(ownerOfItem(i)))
  if (fResubOnly.value) items = items.filter(isResubmission)
  items.sort((a, b) => {
    const aResub = isResubmission(a) ? 0 : 1
    const bResub = isResubmission(b) ? 0 : 1
    if (aResub !== bResub) return aResub - bResub
    const at = new Date(a.submitted_at || a.created_at), bt = new Date(b.submitted_at || b.created_at)
    return fSort.value === 'newest' ? bt - at : at - bt
  })
  return items
})

const { page: appPage, perPage: appPerPage, totalPages: appTotalPages, paginated: paginatedContent, visiblePages: appVisiblePages, goTo: appGoTo } = usePagination(filteredContent)

// --- listing actions ---
async function approveListing(li) {
  const res = await approveContent('listing', li.id)
  if (!res.success) {
    pushToast(firstError(res, 'Could not approve listing.'), 'error')
    return false
  }
  const contentOnly = isContentUpdate(li)
  const name = contentOnly ? (li.pending_content?.name || li.name) : li.name
  // Server promotes custom_types; keep FE call best-effort for Supabase catalog cache.
  if (!contentOnly) {
    try { await promoteTypesFor({ domain: 'venue', name: li.sport_category, category: li.category }) } catch { /* ignore */ }
  }
  logAudit({ action: AUDIT.APPROVED, entity_type: 'listing', entity_id: li.id, details: contentOnly ? `Approved content update for “${name}” (${providerName(li.owner_user_id)})` : `Approved listing “${name}” (${providerName(li.owner_user_id)})` })
  notifyBusiness({
    ownerUserId: ownerOfItem({ ...li, _type: 'listing' }), kind: 'approval', tone: 'ok',
    title: contentOnly ? `Content approved — “${name}”` : `“${name}” was approved 🎉`,
    sub: contentOnly ? 'Your updated title, description and photos are now live' : 'It is now live on the Bookboll marketplace',
    link: '/listings',
  })
  await load()
  return true
}

// --- program actions ---
async function approveProgram(pg) {
  const res = await approveContent('program', pg.id)
  if (!res.success) {
    pushToast(firstError(res, 'Could not approve program.'), 'error')
    return false
  }
  const contentOnly = isContentUpdate(pg)
  const name = contentOnly ? (pg.pending_content?.name || pg.name) : pg.name
  if (!contentOnly) {
    try { await promoteTypesFor({ domain: 'program', name: pg.sport_category, category: pg.category }) } catch { /* ignore */ }
  }
  logAudit({ action: AUDIT.APPROVED, entity_type: 'program', entity_id: pg.id, details: contentOnly ? `Approved content update for “${name}” (${providerName(pg.owner_user_id)})` : `Approved program “${name}” (${providerName(pg.owner_user_id)})` })
  notifyBusiness({
    ownerUserId: ownerOfItem({ ...pg, _type: 'program' }), kind: 'approval', tone: 'ok',
    title: contentOnly ? `Content approved — “${name}”` : `“${name}” was approved 🎉`,
    sub: contentOnly ? 'Your updated title, description and photo are now live' : 'It is now live on the Bookboll marketplace',
    link: '/listings',
  })
  await load()
  return true
}

async function approveItem(item) {
  const contentOnly = isContentUpdate(item)
  // Confirm first — approving publishes to the marketplace instantly.
  if (!(await askConfirm({
    title: contentOnly ? `Approve updated content for “${item.name}”?` : `Approve “${item.name}”?`,
    message: contentOnly
      ? `The new title, description and photos replace the current live ones, and ${providerName(ownerOfItem(item))} gets a notification.`
      : `It goes live on the Bookboll marketplace immediately, and ${providerName(ownerOfItem(item))} gets a notification.`,
    danger: false, confirmText: contentOnly ? 'Approve content' : 'Approve & publish', cancelText: 'Not yet',
  }))) return
  const ok = item._type === 'listing' ? await approveListing(item) : await approveProgram(item)
  if (!ok) return
  pushToast(contentOnly ? `“${item.name}” content approved — now live` : `“${item.name}” approved — it's live on the marketplace`, 'success')
  if (previewItem.value?.id === item.id) previewModal.value = false
}

// --- request changes ---
const sendBackModal = ref(false)
const sendBackTarget = ref(null)
const sendBackNote = ref('')

function openSendBack(item) {
  sendBackTarget.value = item
  sendBackNote.value = item.admin_note || ''
  sendBackModal.value = true
}
async function confirmSendBack() {
  const item = sendBackTarget.value
  if (!sendBackNote.value.trim()) return
  const contentOnly = isContentUpdate(item)
  const res = await requestContentChanges(item._type, item.id, {
    admin_note: sendBackNote.value.trim(),
  })
  if (!res.success) {
    pushToast(firstError(res, 'Could not request changes.'), 'error')
    return
  }
  logAudit({
    action: AUDIT.CHANGES_REQUESTED,
    entity_type: item._type,
    entity_id: item.id,
    details: contentOnly
      ? `Rejected content update on “${item.name}”: ${sendBackNote.value.trim()}`
      : `Requested changes on “${item.name}”: ${sendBackNote.value.trim()}`,
  })
  notifyBusiness({
    ownerUserId: ownerOfItem(item), kind: 'approval', tone: 'warn',
    title: contentOnly ? `Content changes not approved — “${item.name}”` : `Changes requested — “${item.name}”`,
    sub: contentOnly
      ? `${sendBackNote.value.trim()} · Your listing stays live on its current content.`
      : sendBackNote.value.trim(),
    link: '/listings',
  })
  sendBackModal.value = false
  if (previewModal.value) previewModal.value = false
  load()
}

// --- bulk approve ---
const bulkModal = ref(false)
async function confirmBulkApprove() {
  const items = filteredContent.value
  const res = await bulkApproveContent(items.map(i => ({ type: i._type, id: String(i.id) })))
  if (!res.success) {
    pushToast(firstError(res, 'Bulk approve failed.'), 'error')
    return
  }
  for (const item of items) {
    const contentOnly = isContentUpdate(item)
    if (!contentOnly) {
      try {
        await promoteTypesFor({
          domain: item._type === 'listing' ? 'venue' : 'program',
          name: item.sport_category,
          category: item.category,
        })
      } catch { /* ignore */ }
    }
    notifyBusiness({
      ownerUserId: ownerOfItem(item), kind: 'approval', tone: 'ok',
      title: contentOnly ? `Content approved — “${item.pending_content?.name || item.name}”` : `“${item.name}” was approved 🎉`,
      sub: contentOnly ? 'Your updated content is now live' : 'It is now live on the Bookboll marketplace',
      link: '/listings',
    })
  }
  logAudit({ action: AUDIT.APPROVED, entity_type: 'content', details: `Bulk-approved ${items.length} pending item${items.length === 1 ? '' : 's'}` })
  bulkModal.value = false
  pushToast(`${res.data?.count ?? items.length} item(s) approved`, 'success')
  load()
}

// ── Withdrawal decisions (same money model as Earnings & Payouts) ─────────────
// pending → approved (money stays in escrow; provider sees "approved · paying
// out") → paid (escrow settles: pending_balance down, lifetime_withdrawn up,
// debit ledger row). Reject refunds escrow → available_balance.
function walletForW(wd) {
  return wallets.value.find(x => x.id === wd.wallet_id)
    || wallets.value.find(x => x.owner_user_id === wd.owner_user_id && x.country_id === wd.country_id && x.is_current !== false)
}
// Sanity check the admin needs at a glance: is the requested amount actually
// sitting in the wallet's escrow?
function wdEscrowOk(wd) {
  const wl = walletForW(wd)
  return wl ? Number(wl.pending_balance || 0) >= Number(wd.amount || 0) - 0.01 : false
}
const wdBusy = ref('')   // id of the request being acted on

async function approveWithdrawalA(wd) {
  if (!(await askConfirm({
    title: 'Approve this payout?',
    message: `${fmtMoney(wd.amount, wd.currency)} to ${providerName(wd.owner_user_id)}. The provider sees “Approved · paying out” — you'll mark it Paid once the bank transfer is done.`,
    danger: false, confirmText: 'Approve payout',
  }))) return
  wdBusy.value = wd.id
  const res = await approveWithdrawal(wd.id, { processed_by: currentUser.value?.full_name || 'Admin' })
  wdBusy.value = ''
  if (!res.success) { pushToast(moneyErr(res, 'Could not approve the request.'), 'error'); return }
  logAudit({ action: AUDIT.APPROVED, entity_type: 'withdrawal', entity_id: wd.id, details: `Approved withdrawal of ${fmtMoney(wd.amount, wd.currency)} for ${providerName(wd.owner_user_id)}` })
  pushToast('Payout approved — mark it Paid once the transfer is sent.', 'success')
  load()
}

async function markWithdrawalPaidA(wd) {
  if (!(await askConfirm({
    title: 'Mark withdrawal as paid?',
    message: `Confirm the bank transfer of ${fmtMoney(wd.amount, wd.currency)} to ${providerName(wd.owner_user_id)} is done. This settles the money out of their pending balance.`,
    danger: false, confirmText: 'Mark as Paid',
  }))) return
  wdBusy.value = wd.id
  const res = await markWithdrawalPaid(wd.id, { processed_by: currentUser.value?.full_name || 'Admin' })
  wdBusy.value = ''
  if (!res.success) {
    pushToast(moneyErr(res, 'Could not update the request.'), 'error')
    load()
    return
  }
  logAudit({ action: 'paid', entity_type: 'withdrawal', entity_id: wd.id, details: `Marked withdrawal of ${fmtMoney(wd.amount, wd.currency)} paid for ${providerName(wd.owner_user_id)}` })
  pushToast(`Withdrawal of ${fmtMoney(wd.amount, wd.currency)} marked as paid`, 'success')
  load()
}

async function rejectWithdrawalA(wd) {
  const reason = await askInput({
    title: 'Reject withdrawal request',
    label: 'Reason for rejection (shown to the provider)',
    placeholder: 'e.g. Bank/IBAN details are incomplete',
    multiline: true, required: true, confirmText: 'Reject request',
  })
  if (reason === null) return
  wdBusy.value = wd.id
  const res = await rejectWithdrawal(wd.id, {
    reason: reason || '',
    processed_by: currentUser.value?.full_name || 'Admin',
  })
  wdBusy.value = ''
  if (!res.success) {
    pushToast(moneyErr(res, 'Could not reject the request.'), 'error')
    load()
    return
  }
  logAudit({ action: AUDIT.REJECTED, entity_type: 'withdrawal', entity_id: wd.id, details: `Rejected withdrawal of ${fmtMoney(wd.amount, wd.currency)} for ${providerName(wd.owner_user_id)}: ${reason}` })
  pushToast('Withdrawal request rejected — money returned to the provider balance', 'success')
  load()
}

// Copy helper (IBANs, account numbers) — the admin pastes these into the bank.
async function copyText(v) {
  try { await navigator.clipboard.writeText(String(v)); pushToast('Copied', 'success') } catch { /* ignore */ }
}

// ── Suspend / reinstate a business (marketplace-wide) ─────────────────────────
const suspending = ref(false)
function isSuspended(ownerId) { return providerById(ownerId)?.business_status === 'suspended' }
async function suspendBusiness(ownerId) {
  const name = providerName(ownerId)
  if (!(await askConfirm({
    title: `Suspend ${name}?`,
    message: 'Their ENTIRE business disappears from the Bookboll marketplace — every venue, listing and program. Their own website keeps working, and you can reinstate them any time.',
    danger: true, confirmText: 'Suspend business', cancelText: 'Keep active',
  }))) return
  suspending.value = true
  const { error } = await supabase.from('admin_users').update({ business_status: 'suspended' }).eq('id', ownerId)
  suspending.value = false
  if (error) { pushToast('Could not suspend the business.', 'error'); return }
  logAudit({ action: AUDIT.DISABLED, entity_type: 'business', entity_id: ownerId, details: `Suspended business ${name} from the marketplace` })
  notifyBusiness({ ownerUserId: ownerId, kind: 'approval', tone: 'warn', title: 'Your business was suspended on the Bookboll marketplace', sub: 'Your listings are hidden from the marketplace. Contact Bookboll support for details.', link: '/listings' })
  pushToast(`${name} suspended from the marketplace`, 'success')
  load()
}
async function reinstateBusiness(ownerId) {
  const name = providerName(ownerId)
  if (!(await askConfirm({
    title: `Reinstate ${name}?`,
    message: 'Their approved venues, listings and programs return to the Bookboll marketplace.',
    danger: false, confirmText: 'Reinstate',
  }))) return
  const { error } = await supabase.from('admin_users').update({ business_status: 'approved' }).eq('id', ownerId)
  if (error) { pushToast('Could not reinstate the business.', 'error'); return }
  logAudit({ action: AUDIT.ENABLED, entity_type: 'business', entity_id: ownerId, details: `Reinstated business ${name} on the marketplace` })
  notifyBusiness({ ownerUserId: ownerId, kind: 'approval', tone: 'ok', title: 'Your business is back on the Bookboll marketplace', sub: 'Your approved listings and programs are visible again.', link: '/listings' })
  pushToast(`${name} reinstated`, 'success')
  load()
}

// --- preview modal ---
const previewModal = ref(false)
const previewItem = ref(null)

function openPreview(item) {
  previewItem.value = item
  previewModal.value = true
  previewTab.value = 'details'
}

// --- withdrawal actions ---
function fmtMoney(n, c = 'AED') {
  return `${c} ${Number(n || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
}
function fmtDate(d) {
  if (!d) return '—'
  return new Date(d).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
}
function timeAgo(d) {
  if (!d) return ''
  const diff = Date.now() - new Date(d).getTime()
  const hours = Math.floor(diff / 3600000)
  if (hours < 1) return 'just now'
  if (hours < 24) return `${hours} hour${hours > 1 ? 's' : ''} ago`
  const days = Math.floor(hours / 24)
  if (days === 1) return '1 day ago'
  return `${days} days ago`
}
function fmtDateTime(d) {
  if (!d) return ''
  return new Date(d).toLocaleString('en-US', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })
}
// The submission line: WHEN (submitted_at, else created_at) and BY WHOM.
function submittedInfo(item) {
  const at = item.submitted_at || item.created_at
  const by = item.submitted_by_name || ''
  return { at, ago: timeAgo(at), exact: fmtDateTime(at), by }
}

// --- diff helpers -------------------------------------------------------------
// Every provider-editable field with a human label + value formatter, so the
// before → after diff covers price, schedule, offers and placement changes —
// not just the name/description basics.
const DOW_SHORT = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']
const F = {
  raw:   (v) => v === null || v === undefined || v === '' ? '' : String(v),
  bool:  (v) => v === false || v === 'false' ? 'Off' : (v === true || v === 'true' ? 'On' : F.raw(v)),
  days:  (v) => Array.isArray(v) ? v.map(d => DOW_SHORT[d] ?? d).join(' ') : F.raw(v),
  photo: (v) => v ? '📷 ' + String(v).split('/').pop().slice(0, 24) : '',
  photos: (v) => Array.isArray(v) && v.length ? `📷 ${v.length} photo${v.length > 1 ? 's' : ''}` : '',
}
const PROGRAM_FIELDS = [
  { key: 'name', label: 'Name' },
  { key: 'category', label: 'Category' },
  { key: 'sport_category', label: 'Type' },
  { key: 'coach_name', label: 'Coach' },
  { key: 'description', label: 'Description' },
  { key: 'monthly_price', label: 'Price' },
  { key: 'currency', label: 'Currency' },
  { key: 'billing_interval', label: 'Billing interval' },
  { key: 'joining_fee', label: 'Joining fee' },
  { key: 'free_trial_days', label: 'Free trial (days)' },
  { key: 'free_trial_sessions', label: 'Free trial (sessions)' },
  { key: 'discount_months', label: 'Discount months' },
  { key: 'sessions_per_week', label: 'Sessions / week' },
  { key: 'session_duration_minutes', label: 'Session duration' },
  { key: 'session_time', label: 'Session time' },
  { key: 'day_of_week_mask', label: 'Session days', fmt: F.days },
  { key: 'capacity', label: 'Capacity' },
  { key: 'audience', label: 'Audience' },
  { key: 'gender_policy', label: 'Gender policy' },
  { key: 'program_type', label: 'Program type' },
  { key: 'start_date', label: 'Start date' },
  { key: 'end_date', label: 'End date' },
  { key: 'photo_url', label: 'Cover photo', fmt: F.photo },
  { key: 'show_on_marketplace', label: 'On Bookboll marketplace', fmt: F.bool },
  { key: 'show_on_provider_site', label: 'On own website', fmt: F.bool },
  { key: 'status', label: 'Status' },
]
const LISTING_FIELDS = [
  { key: 'name', label: 'Name' },
  { key: 'category', label: 'Category' },
  { key: 'sport_category', label: 'Type' },
  { key: 'description', label: 'Description' },
  { key: 'base_price', label: 'Base price' },
  { key: 'capacity', label: 'Capacity' },
  { key: 'slot_duration', label: 'Slot duration (min)' },
  { key: 'buffer_time', label: 'Buffer (min)' },
  { key: 'max_bookings_per_slot', label: 'Max bookings / slot' },
  { key: 'loyalty_points', label: 'Loyalty points' },
  { key: 'extension_pricing', label: 'Extension pricing' },
  { key: 'extension_value', label: 'Extension value' },
  { key: 'photo_url', label: 'Cover photo', fmt: F.photo },
  { key: 'show_on_marketplace', label: 'On Bookboll marketplace', fmt: F.bool },
  { key: 'show_on_provider_site', label: 'On own website', fmt: F.bool },
  { key: 'status', label: 'Status' },
]

// A content-only update: the listing/program is already approved & live, and only its
// content (title/description/photos) is staged in pending_content awaiting review. The
// live row keeps serving the approved content until this is approved.
function isContentUpdate(row) {
  return (row?.approval_status || 'approved') === 'approved' && !!row?.pending_content
}

// Content fields staged for review — diffed live (approved) → pending_content.
const CONTENT_DIFF = {
  listing: [
    { key: 'name', label: 'Name' },
    { key: 'description', label: 'Description' },
    { key: 'photo_url', label: 'Cover photo', fmt: F.photo },
    { key: 'photos', label: 'Gallery', fmt: F.photos },
  ],
  program: [
    { key: 'name', label: 'Name' },
    { key: 'description', label: 'Description' },
    { key: 'photo_url', label: 'Cover photo', fmt: F.photo },
    { key: 'images', label: 'Gallery', fmt: F.photos },
  ],
}

function getChanges(row) {
  // Content-update items diff the live/approved content against the staged pending_content.
  if (isContentUpdate(row)) {
    const pc = row.pending_content || {}
    return (CONTENT_DIFF[row._type] || CONTENT_DIFF.listing).map(f => {
      if (pc[f.key] === undefined) return null
      const fmt = f.fmt || F.raw
      const before = fmt(row[f.key])
      const after = fmt(pc[f.key])
      if (before !== after) return { label: f.label, before, after }
      return null
    }).filter(Boolean)
  }
  if (!row.previous_snapshot) return []
  const fields = row._type === 'listing' ? LISTING_FIELDS : PROGRAM_FIELDS
  return fields.map(f => {
    // Skip fields the snapshot never captured (undefined) — comparing them
    // against the live row would report phantom changes.
    if (row.previous_snapshot[f.key] === undefined) return null
    const fmt = f.fmt || F.raw
    const before = fmt(row.previous_snapshot[f.key])
    const after = fmt(row[f.key])
    if (before !== after) return { label: f.label, before, after }
    return null
  }).filter(Boolean)
}

function isResubmission(row) { return !!row.previous_snapshot || isContentUpdate(row) }

const expandedDiffs = ref(new Set())
function toggleDiff(id) {
  if (expandedDiffs.value.has(id)) expandedDiffs.value.delete(id)
  else expandedDiffs.value.add(id)
  expandedDiffs.value = new Set(expandedDiffs.value)
}

// preview field lists
const PREVIEW_LISTING_FIELDS = [
  { key: 'name', label: 'Name' },
  { key: 'sport_category', label: 'Sport' },
  { key: 'listing_type', label: 'Type' },
  { key: 'base_price', label: 'Price / hr' },
  { key: 'slot_duration', label: 'Slot Duration (min)' },
  { key: 'capacity', label: 'Capacity' },
  { key: 'max_bookings_per_slot', label: 'Max Bookings / Slot' },
  { key: 'loyalty_points', label: 'Loyalty Points' },
  { key: 'status', label: 'Status' },
  { key: 'description', label: 'Description' },
  { key: 'photo_url', label: 'Photo URL' },
]
const PREVIEW_PROGRAM_FIELDS = [
  { key: 'name', label: 'Name' },
  { key: 'sport_category', label: 'Sport' },
  { key: 'coach_name', label: 'Coach' },
  { key: 'monthly_price', label: 'Monthly Price' },
  { key: 'currency', label: 'Currency' },
  { key: 'billing_interval', label: 'Billing Interval' },
  { key: 'session_duration_minutes', label: 'Session Duration (min)' },
  { key: 'sessions_per_week', label: 'Sessions / Week' },
  { key: 'session_time', label: 'Session Time' },
  { key: 'capacity', label: 'Capacity' },
  { key: 'free_trial_days', label: 'Free Trial (days)' },
  { key: 'commission_percentage', label: 'Commission %' },
  { key: 'program_type', label: 'Program Type' },
  { key: 'status', label: 'Status' },
  { key: 'description', label: 'Description' },
  { key: 'photo_url', label: 'Photo URL' },
]
function previewFields(item) {
  return item._type === 'listing' ? PREVIEW_LISTING_FIELDS : PREVIEW_PROGRAM_FIELDS
}
</script>

<template>
  <div class="page">
    <div class="page-header">
      <div>
        <div class="page-title">Approval Queue</div>
        <div class="page-subtitle">Review listings, programs, withdrawals and payout methods</div>
      </div>
      <div v-if="totalCount > 0" class="total-badge">{{ totalCount }} pending</div>
    </div>

    <!-- Tabs: All · Listings & Programs · Withdrawals & Payouts -->
    <div class="aq-tabs" role="tablist">
      <button
        v-for="t in approvalTabs" :key="t.key" type="button" class="aq-tab"
        :class="{ active: tab === t.key }" role="tab" :aria-selected="tab === t.key"
        @click="tab = t.key"
      >
        {{ t.label }}<span v-if="t.count" class="aq-tab-count">{{ t.count }}</span>
      </button>
    </div>

    <!-- toolbar -->
    <FilterBar
      v-model:search="search"
      v-model:country="filterCountry"
      v-model:city="filterCity"
      :countries="allCountries"
      :cities="allCities"
      :extra-count="extraFilterCount"
      search-placeholder="Search by name, sport, provider..."
      @clear="clearPageFilters"
    >
      <div class="aq-filter-grid">
        <div class="aq-ff">
          <label>Type</label>
          <MultiSelect v-model="fType" placeholder="Listings & programs" :options="[{ value: 'listing', label: 'Booking Listings' }, { value: 'program', label: 'Membership Listings' }]" />
        </div>
        <div class="aq-ff">
          <label>Business</label>
          <MultiSelect v-model="fProvider" placeholder="All businesses" :options="providerOptions" />
        </div>
        <div class="aq-ff">
          <label>Sort</label>
          <select v-model="fSort">
            <option value="waiting">Waiting longest first</option>
            <option value="newest">Newest first</option>
          </select>
        </div>
        <div class="aq-ff aq-ff-check">
          <label class="aq-check"><input type="checkbox" v-model="fResubOnly" /> Resubmissions only</label>
        </div>
      </div>
      <template #actions>
        <button
          v-if="showContentSection && filteredContent.length > 1"
          class="btn btn-secondary btn-sm"
          @click="bulkModal = true"
        >
          <ListChecks :size="15" />Approve all ({{ filteredContent.length }})
        </button>
      </template>
    </FilterBar>

    <!-- Empty state for the active tab -->
    <div v-if="activeCount === 0" class="empty-state">
      <CheckCircle :size="32" />
      <div class="empty-title">All caught up!</div>
      <div class="empty-sub">Nothing pending in this view</div>
    </div>

    <!-- ══ LISTINGS & PROGRAMS ══ -->
    <template v-if="showContentSection && filteredContent.length">
      <div v-if="tab === 'all'" class="aq-section"><Boxes :size="14" />Listings &amp; Programs <span class="aq-section-count">{{ contentCount }}</span></div>

      <div v-for="item in paginatedContent" :key="item.id" class="approve-card-wrap">
        <!-- resubmission / content-update banner -->
        <div v-if="isContentUpdate(item)" class="resubmit-banner content-banner">
          <RefreshCw :size="13" /><span>Content update — listing stays live on its approved content; only the new title/description/photos need approval</span>
        </div>
        <div v-else-if="isResubmission(item)" class="resubmit-banner">
          <RefreshCw :size="13" /><span>Resubmission — provider edited after changes were requested</span>
        </div>

        <div class="card approve-card">
          <div class="approve-icon" :class="item._type === 'listing' ? 'listing-icon' : 'program-icon'">
            <Boxes v-if="item._type === 'listing'" :size="20" />
            <GraduationCap v-else :size="20" />
          </div>

          <div class="approve-body">
            <div class="approve-title-row">
              <span class="approve-title">{{ item.name }}</span>
              <span class="type-badge" :class="item._type === 'listing' ? 'type-listing' : 'type-program'">
                {{ item._type === 'listing' ? 'Listing' : 'Program' }}
              </span>
            </div>

            <div class="approve-meta">
              <span class="meta-pill">{{ item.sport_category }}</span>
              <span v-if="item._type === 'listing'">{{ item.base_price }}/hr &middot; Cap {{ item.capacity }}</span>
              <span v-else>{{ item.currency || 'AED' }} {{ item.monthly_price }}/mo &middot; {{ item.free_trial_days || 14 }}d trial</span>
            </div>

            <div class="approve-provider-row">
              <span class="provider-chip">{{ providerName(ownerOfItem(item)) }}</span>
              <span class="submitted-age" :title="submittedInfo(item).exact">
                <Clock :size="11" /> Submitted {{ submittedInfo(item).ago }}<template v-if="submittedInfo(item).by"> · by {{ submittedInfo(item).by }}</template>
              </span>
            </div>

            <div v-if="item.eligibility_status === 'paused' && item.admin_note" class="inline-admin-note">
              <AlertTriangle :size="13" />
              <span><strong>Changes requested:</strong> {{ item.admin_note }}</span>
            </div>

            <div v-if="item.description" class="approve-desc">{{ item.description }}</div>

            <template v-if="isResubmission(item) && getChanges(item).length">
              <button class="diff-toggle" @click="toggleDiff(item.id)">
                <RefreshCw :size="12" />
                <span>{{ getChanges(item).length }} field change{{ getChanges(item).length > 1 ? 's' : '' }}</span>
                <em v-if="item.submitted_at" class="diff-when">{{ fmtDateTime(item.submitted_at) }}</em>
                <ChevronDown v-if="!expandedDiffs.has(item.id)" :size="14" /><ChevronUp v-else :size="14" />
              </button>
              <div v-if="expandedDiffs.has(item.id)" class="diff-table">
                <div v-for="ch in getChanges(item)" :key="ch.label" class="diff-row">
                  <div class="diff-field">{{ ch.label }}</div>
                  <div class="diff-before"><span class="diff-tag before">Before</span><span class="diff-val">{{ ch.before || '—' }}</span></div>
                  <ArrowRight :size="12" class="diff-arrow" />
                  <div class="diff-after"><span class="diff-tag after">After</span><span class="diff-val">{{ ch.after || '—' }}</span></div>
                </div>
                <div class="diff-foot" v-if="item.submitted_at">
                  <Clock :size="11" /> Edited {{ fmtDateTime(item.submitted_at) }}<template v-if="item.submitted_by_name"> by <b>{{ item.submitted_by_name }}</b></template>
                </div>
              </div>
            </template>
          </div>

          <div class="approve-actions">
            <button class="btn btn-secondary btn-sm" @click="openPreview(item)"><Eye :size="14" />View</button>
            <button class="btn btn-success btn-sm" @click="approveItem(item)"><CheckCircle :size="14" />Approve</button>
            <button class="btn btn-warning btn-sm" @click="openSendBack(item)"><PauseCircle :size="14" />Request changes</button>
          </div>
        </div>
      </div>
      <PaginationBar
        v-if="filteredContent.length > 0"
        :page="appPage" :perPage="appPerPage" :totalPages="appTotalPages"
        :total="filteredContent.length" :visiblePages="appVisiblePages"
        @update:perPage="appPerPage = $event"
        @goTo="appGoTo"
      />
    </template>

    <!-- ══ PAYOUT METHOD APPROVALS (KYC) ══ -->
    <template v-if="showMoneySection && canApprovePayouts && pendingPayoutSetups.length">
      <div v-if="tab === 'all'" class="aq-section"><Building2 :size="14" />Payout method approvals <span class="aq-section-count">{{ pendingPayoutSetups.length }}</span></div>

      <div v-for="e in pendingPayoutSetups" :key="e.ownerId" class="approve-card-wrap">
        <div class="card approve-card">
          <div class="approve-icon payout-icon"><Building2 :size="20" /></div>
          <div class="approve-body">
            <div class="approve-title-row">
              <span class="approve-title">{{ e.business }}</span>
              <span class="type-badge type-payout">Payout method</span>
            </div>
            <div class="approve-meta">
              <span class="meta-pill">Manual · Bookboll Wallet</span>
              <span>{{ e.countryNames.join(', ') || '—' }}</span>
            </div>
            <div class="approve-provider-row">
              <span class="provider-chip">{{ e.business }}</span>
              <span v-if="e.submittedAt" class="submitted-age">Submitted {{ timeAgo(e.submittedAt) }}</span>
            </div>
            <div class="req-detail">
              <span><b>Legal name:</b> {{ e.template?.kyc_business_name || '—' }}</span>
              <span><b>Reg/Licence:</b> {{ e.template?.kyc_registration_no || '—' }}</span>
              <span><b>Address:</b> {{ e.template?.kyc_address || '—' }}</span>
              <span v-if="e.template?.kyc_contact"><b>Contact:</b> {{ e.template.kyc_contact }}</span>
              <span><b>Bank:</b> {{ e.template?.payout_bank_name || '—' }}</span>
              <span v-if="e.template?.payout_account_holder || e.template?.payout_account_name"><b>Account holder:</b> {{ e.template.payout_account_holder || e.template.payout_account_name }}</span>
              <span v-if="e.template?.payout_iban" class="req-copyable">
                <b>IBAN:</b> <code>{{ e.template.payout_iban }}</code>
                <button type="button" class="req-copy" title="Copy IBAN" @click="copyText(e.template.payout_iban)"><Copy :size="12" /></button>
              </span>
              <span v-else-if="e.template?.payout_account_number" class="req-copyable">
                <b>Account:</b> <code>{{ e.template.payout_account_number }}</code>
                <button type="button" class="req-copy" title="Copy account number" @click="copyText(e.template.payout_account_number)"><Copy :size="12" /></button>
              </span>
              <span v-if="providerById(e.ownerId)?.email"><b>Email:</b> {{ providerById(e.ownerId).email }}</span>
              <span v-if="providerById(e.ownerId)?.phone"><b>Phone:</b> {{ providerById(e.ownerId).phone }}</span>
            </div>
            <div class="req-checks">
              <span class="req-check" :class="e.template?.kyc_doc_url ? 'ok' : 'warn'">
                <Check v-if="e.template?.kyc_doc_url" :size="11" /><AlertTriangle v-else :size="11" />
                {{ e.template?.kyc_doc_url ? 'Document attached' : 'No verification document' }}
              </span>
              <span class="req-check" :class="(e.template?.payout_iban || e.template?.payout_account_number) ? 'ok' : 'warn'">
                <Check v-if="e.template?.payout_iban || e.template?.payout_account_number" :size="11" /><AlertTriangle v-else :size="11" />
                {{ (e.template?.payout_iban || e.template?.payout_account_number) ? 'Bank account provided' : 'Missing bank account' }}
              </span>
            </div>
            <a v-if="e.template?.kyc_doc_url" :href="e.template.kyc_doc_url" target="_blank" rel="noopener" class="req-doc"><FileText :size="13" />View verification document</a>
          </div>
          <div class="approve-actions">
            <button class="btn btn-success btn-sm" @click="approvePayoutSetup(e)"><Check :size="14" />Approve</button>
            <button class="btn btn-warning btn-sm" @click="openPayoutReject(e)"><X :size="14" />Reject</button>
          </div>
        </div>
      </div>
    </template>

    <!-- ══ WITHDRAWAL REQUESTS ══ (full two-step decision, right here) -->
    <template v-if="showMoneySection && filteredWithdrawals.length">
      <div v-if="tab === 'all'" class="aq-section"><Banknote :size="14" />Withdrawal requests <span class="aq-section-count">{{ filteredWithdrawals.length }}</span></div>

      <div v-for="w in filteredWithdrawals" :key="w.id" class="approve-card-wrap">
        <div v-if="w.status === 'approved'" class="resubmit-banner approved-banner">
          <Check :size="13" /><span>Approved {{ timeAgo(w.approved_at) }} — waiting for you to send the bank transfer, then mark it Paid</span>
        </div>
        <div class="card approve-card">
          <div class="approve-icon withdrawal-icon"><Banknote :size="20" /></div>
          <div class="approve-body">
            <div class="approve-title-row">
              <span class="approve-title">{{ fmtMoney(w.amount, w.currency || 'AED') }}</span>
              <span class="type-badge type-withdrawal">Withdrawal</span>
              <span class="escrow-chip" :class="wdEscrowOk(w) ? 'ok' : 'warn'" :title="wdEscrowOk(w) ? 'The amount is reserved in the wallet escrow' : 'Wallet escrow does not cover this amount — investigate before paying'">
                <Check v-if="wdEscrowOk(w)" :size="11" /><AlertTriangle v-else :size="11" />
                {{ wdEscrowOk(w) ? 'Funds in escrow' : 'Escrow mismatch' }}
              </span>
            </div>
            <div class="approve-meta">
              <span class="meta-pill">{{ countryName(w.country_id) || '—' }}</span>
              <span v-if="w.payout_method">{{ w.payout_method.replace('_', ' ') }}</span>
            </div>
            <div class="approve-provider-row">
              <span class="provider-chip">{{ providerName(w.owner_user_id) }}</span>
              <span class="submitted-age"><Clock :size="11" /> Requested {{ timeAgo(w.requested_at) }}</span>
            </div>
            <!-- Bank destination (from the wallet's verified payout details) -->
            <div v-if="walletForW(w)" class="req-detail">
              <span v-if="walletForW(w).payout_bank_name"><b>Bank:</b> {{ walletForW(w).payout_bank_name }}</span>
              <span v-if="walletForW(w).payout_account_holder || walletForW(w).payout_account_name"><b>Account holder:</b> {{ walletForW(w).payout_account_holder || walletForW(w).payout_account_name }}</span>
              <span v-if="walletForW(w).payout_iban" class="req-copyable">
                <b>IBAN:</b> <code>{{ walletForW(w).payout_iban }}</code>
                <button type="button" class="req-copy" title="Copy IBAN" @click="copyText(walletForW(w).payout_iban)"><Copy :size="12" /></button>
              </span>
              <span v-else-if="walletForW(w).payout_account_number" class="req-copyable">
                <b>Account:</b> <code>{{ walletForW(w).payout_account_number }}</code>
                <button type="button" class="req-copy" title="Copy account number" @click="copyText(walletForW(w).payout_account_number)"><Copy :size="12" /></button>
              </span>
              <span><b>Wallet:</b> escrow {{ fmtMoney(walletForW(w).pending_balance, walletForW(w).currency) }} · available {{ fmtMoney(walletForW(w).available_balance, walletForW(w).currency) }} · withdrawn {{ fmtMoney(walletForW(w).lifetime_withdrawn, walletForW(w).currency) }}</span>
            </div>
            <div v-else class="req-detail"><span class="muted-val">No wallet record found for this request — review in Earnings &amp; Payouts.</span></div>
            <a class="req-doc" href="/wallet"><ArrowRight :size="13" />Full ledger &amp; earnings reconciliation</a>
          </div>
          <div class="approve-actions">
            <template v-if="w.status === 'pending'">
              <button class="btn btn-success btn-sm" :disabled="wdBusy === w.id" @click="approveWithdrawalA(w)"><CheckCircle :size="14" />Approve</button>
              <button class="btn btn-warning btn-sm" :disabled="wdBusy === w.id" @click="rejectWithdrawalA(w)"><X :size="14" />Reject</button>
            </template>
            <template v-else>
              <button class="btn btn-success btn-sm" :disabled="wdBusy === w.id" @click="markWithdrawalPaidA(w)"><Banknote :size="14" />Mark as Paid</button>
              <button class="btn btn-warning btn-sm" :disabled="wdBusy === w.id" @click="rejectWithdrawalA(w)"><X :size="14" />Reject</button>
            </template>
          </div>
        </div>
      </div>
    </template>


    <!-- PREVIEW MODAL -->
    <div v-if="previewModal && previewItem" class="modal-backdrop" @click.self="previewModal=false">
      <div class="modal-card modal-preview">
        <!-- Header -->
        <div class="modal-head">
          <div class="modal-title" style="color: var(--text)">
            <span class="type-badge" :class="previewItem._type === 'listing' ? 'type-listing' : 'type-program'">
              {{ previewItem._type === 'listing' ? 'Listing' : 'Program' }}
            </span>
            {{ previewItem.name }}
          </div>
          <button class="btn-icon" @click="previewModal=false"><X :size="16" /></button>
        </div>

        <!-- Tabs -->
        <div class="preview-tabs">
          <button class="preview-tab" :class="{ active: previewTab === 'details' }" @click="previewTab='details'">
            <FileText :size="13" />Details
          </button>
          <button class="preview-tab" :class="{ active: previewTab === 'provider' }" @click="previewTab='provider'">
            <Building2 :size="13" />Provider Account
          </button>
          <button class="preview-tab" :class="{ active: previewTab === 'location' }" @click="previewTab='location'">
            <MapPin :size="13" />Location
          </button>
          <button v-if="isResubmission(previewItem) && getChanges(previewItem).length" class="preview-tab" :class="{ active: previewTab === 'changes' }" @click="previewTab='changes'">
            <RefreshCw :size="13" />Changes
            <span class="tab-badge">{{ getChanges(previewItem).length }}</span>
          </button>
        </div>

        <div class="modal-body preview-body">

          <!-- ── DETAILS TAB ── -->
          <template v-if="previewTab === 'details'">
            <div v-if="previewItem.eligibility_status === 'paused' && previewItem.admin_note" class="inline-admin-note" style="margin-bottom:14px">
              <AlertTriangle :size="13" />
              <span><strong>Changes requested:</strong> {{ previewItem.admin_note }}</span>
            </div>

            <div v-if="previewItem.photo_url" class="preview-photo-wrap">
              <img :src="previewItem.photo_url" class="preview-photo" alt="Preview" />
            </div>

            <div class="pv-section-title">{{ previewItem._type === 'listing' ? 'Listing' : 'Program' }} Details</div>
            <div class="preview-fields">
              <div v-for="f in previewFields(previewItem)" :key="f.key" class="preview-field">
                <div class="preview-field-label">{{ f.label }}</div>
                <div class="preview-field-value">
                  <template v-if="f.key === 'photo_url'">
                    <span v-if="previewItem[f.key]" class="preview-url">{{ previewItem[f.key] }}</span>
                    <span v-else class="muted-val">—</span>
                  </template>
                  <template v-else-if="f.key === 'description'">
                    <p class="preview-desc-text">{{ previewItem[f.key] || '—' }}</p>
                  </template>
                  <template v-else>
                    <span :class="previewItem[f.key] ? '' : 'muted-val'">{{ previewItem[f.key] ?? '—' }}</span>
                  </template>
                </div>
              </div>
            </div>

            <div class="pv-meta-row">
              <span class="pv-meta-chip"><Clock :size="11" />Submitted {{ timeAgo(previewItem.created_at) }}</span>
              <span class="pv-meta-chip"><Shield :size="11" />{{ previewItem.approval_status }}</span>
            </div>
          </template>

          <!-- ── PROVIDER TAB ── -->
          <template v-else-if="previewTab === 'provider'">
            <template v-if="providerById(previewItem.owner_user_id)">
              <div class="pv-provider-hero">
                <div class="pv-provider-avatar">
                  {{ (providerById(previewItem.owner_user_id)?.business_name || providerById(previewItem.owner_user_id)?.full_name || '?')[0].toUpperCase() }}
                </div>
                <div>
                  <div class="pv-provider-name">{{ providerById(previewItem.owner_user_id)?.business_name || providerById(previewItem.owner_user_id)?.full_name }}</div>
                  <div class="pv-provider-handle" v-if="providerById(previewItem.owner_user_id)?.username">@{{ providerById(previewItem.owner_user_id).username }}</div>
                </div>
                <span class="badge" :class="providerById(previewItem.owner_user_id)?.business_status === 'approved' ? 'badge-success' : (providerById(previewItem.owner_user_id)?.business_status === 'suspended' ? 'badge-error' : 'badge-warning')" style="margin-left:auto">
                  {{ providerById(previewItem.owner_user_id)?.business_status || 'pending' }}
                </span>
              </div>

              <!-- Business-level moderation: suspend removes EVERYTHING from the marketplace -->
              <div class="pv-biz-actions">
                <button v-if="!isSuspended(previewItem.owner_user_id)" type="button" class="btn btn-danger btn-sm" :disabled="suspending" @click="suspendBusiness(previewItem.owner_user_id)">
                  <AlertTriangle :size="14" />Suspend business
                </button>
                <button v-else type="button" class="btn btn-success btn-sm" :disabled="suspending" @click="reinstateBusiness(previewItem.owner_user_id)">
                  <CheckCircle :size="14" />Reinstate business
                </button>
                <span class="pv-biz-hint">{{ isSuspended(previewItem.owner_user_id) ? 'Suspended — hidden from the whole marketplace' : 'Removes every venue, listing & program from the marketplace' }}</span>
              </div>

              <div class="preview-fields" style="margin-top:16px">
                <div class="preview-field">
                  <div class="preview-field-label"><User :size="10" /> Contact Person</div>
                  <div class="preview-field-value">{{ providerById(previewItem.owner_user_id)?.full_name || '—' }}</div>
                </div>
                <div class="preview-field">
                  <div class="preview-field-label"><Mail :size="10" /> Email</div>
                  <div class="preview-field-value">{{ providerById(previewItem.owner_user_id)?.email || '—' }}</div>
                </div>
                <div class="preview-field">
                  <div class="preview-field-label"><Phone :size="10" /> Phone</div>
                  <div class="preview-field-value">{{ providerById(previewItem.owner_user_id)?.phone || '—' }}</div>
                </div>
                <div class="preview-field">
                  <div class="preview-field-label"><Globe :size="10" /> Country</div>
                  <div class="preview-field-value">{{ countryById(providerById(previewItem.owner_user_id)?.country_id)?.name || '—' }}</div>
                </div>
                <div class="preview-field">
                  <div class="preview-field-label"><FileText :size="10" /> License No.</div>
                  <div class="preview-field-value pv-mono">{{ providerById(previewItem.owner_user_id)?.license_number || '—' }}</div>
                </div>
                <div class="preview-field">
                  <div class="preview-field-label"><Star :size="10" /> Commission Rate</div>
                  <div class="preview-field-value">{{ providerById(previewItem.owner_user_id)?.commission_rate ?? '—' }}%</div>
                </div>
                <div class="preview-field">
                  <div class="preview-field-label"><Shield :size="10" /> Account Status</div>
                  <div class="preview-field-value">{{ providerById(previewItem.owner_user_id)?.status || '—' }}</div>
                </div>
                <div class="preview-field">
                  <div class="preview-field-label"><CalendarDays :size="10" /> Member Since</div>
                  <div class="preview-field-value">{{ providerById(previewItem.owner_user_id)?.created_at?.slice(0,10) || '—' }}</div>
                </div>
                <div v-if="providerById(previewItem.owner_user_id)?.description" class="preview-field" style="grid-column:1/-1">
                  <div class="preview-field-label">Description</div>
                  <div class="preview-field-value">
                    <p class="preview-desc-text">{{ providerById(previewItem.owner_user_id).description }}</p>
                  </div>
                </div>
              </div>

              <!-- Wallet summary -->
              <div class="pv-section-title" style="margin-top:18px"><DollarSign :size="13" />Wallet</div>
              <div v-if="wallets.find(w => w.owner_user_id === previewItem.owner_user_id)" class="pv-wallet-row">
                <div class="pv-wallet-chip">
                  <div class="pv-wallet-label">Available</div>
                  <div class="pv-wallet-val">{{ wallets.find(w => w.owner_user_id === previewItem.owner_user_id)?.currency || 'AED' }} {{ Number(wallets.find(w => w.owner_user_id === previewItem.owner_user_id)?.available_balance || 0).toLocaleString() }}</div>
                </div>
                <div class="pv-wallet-chip">
                  <div class="pv-wallet-label">Pending</div>
                  <div class="pv-wallet-val">{{ wallets.find(w => w.owner_user_id === previewItem.owner_user_id)?.currency || 'AED' }} {{ Number(wallets.find(w => w.owner_user_id === previewItem.owner_user_id)?.pending_balance || 0).toLocaleString() }}</div>
                </div>
                <div class="pv-wallet-chip">
                  <div class="pv-wallet-label">Lifetime Withdrawn</div>
                  <div class="pv-wallet-val">{{ wallets.find(w => w.owner_user_id === previewItem.owner_user_id)?.currency || 'AED' }} {{ Number(wallets.find(w => w.owner_user_id === previewItem.owner_user_id)?.lifetime_withdrawn || 0).toLocaleString() }}</div>
                </div>
              </div>
              <div v-else class="muted-val" style="font-size:13px">No wallet record found.</div>
            </template>
            <div v-else class="muted-val" style="padding:24px 0;text-align:center;font-size:13px">Provider details not available.</div>
          </template>

          <!-- ── LOCATION TAB ── -->
          <template v-else-if="previewTab === 'location'">
            <template v-if="locationForItem(previewItem)">
              <div v-if="locationForItem(previewItem).photo_url" class="preview-photo-wrap">
                <img :src="locationForItem(previewItem).photo_url" class="preview-photo" alt="Location" />
              </div>

              <div class="pv-section-title"><MapPin :size="13" />Location Info</div>
              <div class="preview-fields">
                <div class="preview-field">
                  <div class="preview-field-label">Name</div>
                  <div class="preview-field-value" style="font-weight:600">{{ locationForItem(previewItem).name }}</div>
                </div>
                <div class="preview-field">
                  <div class="preview-field-label">City</div>
                  <div class="preview-field-value">{{ cityById(locationForItem(previewItem).city_id)?.name || '—' }}</div>
                </div>
                <div class="preview-field">
                  <div class="preview-field-label">Country</div>
                  <div class="preview-field-value">{{ countryById(cityById(locationForItem(previewItem).city_id)?.country_id)?.name || '—' }}</div>
                </div>
                <div class="preview-field">
                  <div class="preview-field-label">Phone</div>
                  <div class="preview-field-value">{{ locationForItem(previewItem).phone || '—' }}</div>
                </div>
                <div class="preview-field">
                  <div class="preview-field-label">Approval Status</div>
                  <div class="preview-field-value">
                    <span class="badge" :class="locationForItem(previewItem).approval_status === 'approved' ? 'badge-success' : 'badge-warning'">
                      {{ locationForItem(previewItem).approval_status }}
                    </span>
                  </div>
                </div>
                <div class="preview-field">
                  <div class="preview-field-label">Eligibility</div>
                  <div class="preview-field-value">
                    <span class="badge" :class="locationForItem(previewItem).eligibility_status === 'eligible' ? 'badge-success' : 'badge-neutral'">
                      {{ locationForItem(previewItem).eligibility_status || '—' }}
                    </span>
                  </div>
                </div>
                <div class="preview-field">
                  <div class="preview-field-label">Sort Order</div>
                  <div class="preview-field-value">{{ locationForItem(previewItem).sort_order ?? '—' }}</div>
                </div>
                <div class="preview-field">
                  <div class="preview-field-label">Currency Override</div>
                  <div class="preview-field-value">{{ locationForItem(previewItem).currency_override || 'None' }}</div>
                </div>
                <div v-if="locationForItem(previewItem).address" class="preview-field" style="grid-column:1/-1">
                  <div class="preview-field-label">Address</div>
                  <div class="preview-field-value">{{ locationForItem(previewItem).address }}</div>
                </div>
                <div v-if="locationForItem(previewItem).description" class="preview-field" style="grid-column:1/-1">
                  <div class="preview-field-label">Description</div>
                  <div class="preview-field-value"><p class="preview-desc-text">{{ locationForItem(previewItem).description }}</p></div>
                </div>
              </div>

              <!-- Other listings at this location -->
              <div class="pv-section-title" style="margin-top:18px"><Boxes :size="13" />Other Listings at This Location</div>
              <div v-if="allLocations.length">
                <div
                  v-for="l in listings.filter(l2 => l2.location_id === locationForItem(previewItem).id && l2.id !== previewItem.id)"
                  :key="l.id"
                  class="pv-sibling-row"
                >
                  <span class="pv-sibling-name">{{ l.name }}</span>
                  <span class="badge badge-warning" style="font-size:10px">pending</span>
                </div>
                <div v-if="!listings.filter(l2 => l2.location_id === locationForItem(previewItem).id && l2.id !== previewItem.id).length" class="muted-val" style="font-size:13px">No other pending listings at this location.</div>
              </div>
            </template>
            <div v-else class="muted-val" style="padding:24px 0;text-align:center;font-size:13px">No location linked to this item.</div>
          </template>

          <!-- ── CHANGES TAB ── -->
          <template v-else-if="previewTab === 'changes'">
            <div class="inline-admin-note" style="margin-bottom:16px">
              <RefreshCw :size="13" />
              <span>This is a resubmission. The provider made changes after admin requested revisions.</span>
            </div>
            <div class="diff-table">
              <div v-for="ch in getChanges(previewItem)" :key="ch.label" class="diff-row">
                <div class="diff-field">{{ ch.label }}</div>
                <div class="diff-before"><span class="diff-tag before">Before</span><span class="diff-val">{{ ch.before || '—' }}</span></div>
                <ArrowRight :size="12" class="diff-arrow" />
                <div class="diff-after"><span class="diff-tag after">After</span><span class="diff-val">{{ ch.after || '—' }}</span></div>
              </div>
            </div>
          </template>

        </div>

        <div class="modal-foot">
          <button class="btn btn-secondary btn-sm" @click="previewModal=false">Close</button>
          <button class="btn btn-warning btn-sm" @click="openSendBack(previewItem)"><PauseCircle :size="14" />Request changes</button>
          <button class="btn btn-success btn-sm" @click="approveItem(previewItem)"><CheckCircle :size="14" />Approve</button>
        </div>
      </div>
    </div>

    <!-- REQUEST CHANGES MODAL -->
    <div v-if="sendBackModal" class="modal-backdrop" @click.self="sendBackModal=false">
      <div class="modal-card">
        <div class="modal-head">
          <div class="modal-title"><PauseCircle :size="18" />Request changes</div>
          <button class="btn-icon" @click="sendBackModal=false"><X :size="16" /></button>
        </div>
        <div class="modal-body">
          <div class="modal-sub">The provider will see this note and be asked to make changes and resubmit.</div>
          <label class="field-label">Reason / required changes</label>
          <textarea v-model="sendBackNote" rows="4" placeholder="e.g. Photo quality is low — please upload a higher resolution image."></textarea>
        </div>
        <div class="modal-foot">
          <button class="btn btn-secondary btn-sm" @click="sendBackModal=false">Cancel</button>
          <button class="btn btn-warning btn-sm" :disabled="!sendBackNote.trim()" @click="confirmSendBack">
            <PauseCircle :size="14" />Request changes
          </button>
        </div>
      </div>
    </div>

    <!-- BULK APPROVE MODAL -->
    <div v-if="bulkModal" class="modal-backdrop" @click.self="bulkModal=false">
      <div class="modal-card">
        <div class="modal-head">
          <div class="modal-title" style="color: var(--text)"><ListChecks :size="18" />Approve all pending content</div>
          <button class="btn-icon" @click="bulkModal=false"><X :size="16" /></button>
        </div>
        <div class="modal-body">
          <div class="modal-sub">
            This will approve all <strong>{{ filteredContent.length }} pending items</strong> (listings and programs) currently visible. This cannot be undone.
          </div>
        </div>
        <div class="modal-foot">
          <button class="btn btn-secondary btn-sm" @click="bulkModal=false">Cancel</button>
          <button class="btn btn-success btn-sm" @click="confirmBulkApprove">
            <CheckCircle :size="14" />Approve all {{ filteredContent.length }}
          </button>
        </div>
      </div>
    </div>

    <!-- REJECT PAYOUT METHOD MODAL -->
    <div v-if="payoutRejectModal" class="modal-backdrop" @click.self="payoutRejectModal=null">
      <div class="modal-card">
        <div class="modal-head">
          <div class="modal-title"><X :size="18" />Return payout submission</div>
          <button class="btn-icon" @click="payoutRejectModal=null"><X :size="16" /></button>
        </div>
        <div class="modal-body">
          <div class="modal-sub">{{ payoutRejectModal.business }} will see this reason and can fix their details and resubmit. Their restrictions stay in place until approved.</div>
          <label class="field-label">Reason (optional)</label>
          <textarea v-model="payoutRejectReason" rows="4" placeholder="e.g. The IBAN doesn't match the account holder — please re-check and resubmit."></textarea>
        </div>
        <div class="modal-foot">
          <button class="btn btn-secondary btn-sm" @click="payoutRejectModal=null">Cancel</button>
          <button class="btn btn-warning btn-sm" @click="confirmPayoutReject"><X :size="14" />Reject &amp; return</button>
        </div>
      </div>
    </div>

  </div>
</template>

<style scoped>
/* page header badge */
.total-badge { background: #fef3c7; color: #92400e; font-size: 12px; font-weight: 700; padding: 4px 12px; border-radius: 999px; border: 1px solid #fde68a; white-space: nowrap; }

/* filter panel fields */
.aq-filter-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
.aq-ff { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
.aq-ff label { font-size: 11px; font-weight: 600; color: var(--text-light, #94a3b8); text-transform: uppercase; letter-spacing: .05em; }
.aq-ff select { width: 100%; min-width: 0; height: 38px; padding: 0 10px; border: 1px solid var(--border); border-radius: 8px; background: var(--surface, #ffffff); font-size: 13.5px; color: var(--text); }
.aq-ff-check { justify-content: flex-end; }
.aq-check { display: flex; align-items: center; gap: 8px; font-size: 13px !important; font-weight: 600 !important; color: var(--text) !important; text-transform: none !important; letter-spacing: 0 !important; cursor: pointer; min-height: 38px; }
.aq-check input { width: 16px; height: 16px; accent-color: var(--primary, #0f766e); }

/* escrow sanity chip on withdrawal cards */
.escrow-chip { display: inline-flex; align-items: center; gap: 4px; padding: 3px 9px; border-radius: 999px; font-size: 11px; font-weight: 800; }
.escrow-chip.ok { background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; }
.escrow-chip.warn { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }

/* copyable bank details */
.req-copyable { display: inline-flex; align-items: center; gap: 6px; }
.req-copyable code { font-family: 'SF Mono', Monaco, monospace; font-size: 12px; background: var(--bg, #f1f5f9); padding: 2px 7px; border-radius: 6px; color: var(--text); }
.req-copy { display: inline-flex; align-items: center; justify-content: center; width: 22px; height: 22px; border: 1px solid var(--border); border-radius: 6px; background: var(--surface, #ffffff); color: var(--text-muted); cursor: pointer; }
.req-copy:hover { color: var(--primary); border-color: var(--primary); }

/* readiness checks on payout-method cards */
.req-checks { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 8px; }
.req-check { display: inline-flex; align-items: center; gap: 5px; padding: 3px 10px; border-radius: 999px; font-size: 11.5px; font-weight: 700; }
.req-check.ok { background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; }
.req-check.warn { background: #fffbeb; color: #b45309; border: 1px solid #fde68a; }

/* approved-awaiting-transfer banner (green variant of the resubmit banner) */
.approved-banner { background: #f0fdf4 !important; color: #15803d !important; border-color: #bbf7d0 !important; }

/* diff extras */
.diff-when { font-style: normal; font-size: 11px; color: var(--text-light, #94a3b8); margin-left: 4px; }
.diff-foot { display: flex; align-items: center; gap: 6px; padding: 8px 10px; font-size: 11.5px; color: var(--text-muted); border-top: 1px solid var(--border); }

/* business-level moderation in the provider tab */
.pv-biz-actions { display: flex; align-items: center; gap: 10px; margin-top: 12px; flex-wrap: wrap; }
.pv-biz-hint { font-size: 12px; color: var(--text-muted); }

/* tabs */
.tab { display: flex; align-items: center; gap: 6px; }
.tab-badge { background: #ef4444; color: #fff; font-size: 11px; font-weight: 700; padding: 1px 6px; border-radius: 999px; line-height: 1.6; }

/* page-level approval tabs (All / Listings & Programs / Withdrawals & Payouts) */
.aq-tabs { display: flex; gap: 4px; border-bottom: 1.5px solid var(--border); margin-bottom: 16px; flex-wrap: wrap; }
.aq-tab { display: inline-flex; align-items: center; gap: 7px; padding: 10px 16px; font-size: 14px; font-weight: 600; color: var(--text-muted); background: none; border: none; border-bottom: 2.5px solid transparent; margin-bottom: -1.5px; cursor: pointer; transition: color .12s, border-color .12s; white-space: nowrap; }
.aq-tab:hover { color: var(--text); }
.aq-tab.active { color: var(--brand, #0f766e); border-bottom-color: var(--brand, #0f766e); }
.aq-tab-count { font-size: 11px; font-weight: 800; background: var(--bg); border: 1px solid var(--border); color: var(--text-muted); border-radius: 999px; padding: 0 7px; line-height: 1.7; }
.aq-tab.active .aq-tab-count { background: var(--brand, #0f766e); color: #fff; border-color: transparent; }

/* section header (shown in the "All" view to group request types) */
.aq-section { display: flex; align-items: center; gap: 7px; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: var(--text-muted); margin: 22px 0 12px; }
.aq-section:first-of-type { margin-top: 4px; }
.aq-section-count { font-size: 11px; font-weight: 800; background: var(--bg); border: 1px solid var(--border); border-radius: 999px; padding: 0 7px; letter-spacing: 0; }

/* empty state */
.empty-state { text-align: center; padding: 56px 20px; color: var(--text-muted); }
.empty-state > svg { margin: 0 auto 12px; opacity: 0.3; display: block; }
.empty-title { font-size: 15px; font-weight: 600; color: var(--text); margin-bottom: 4px; }
.empty-sub { font-size: 13px; }

/* approve card */
.approve-card-wrap { margin-bottom: 14px; }
.approve-card-wrap .card { margin-bottom: 0; }
/* Only flatten the top when a resubmit banner is attached above it. */
.approve-card-wrap:has(.resubmit-banner) .card { border-radius: 0 0 12px 12px; border-top: none; }
.approve-card { padding: 18px 20px; display: flex; align-items: flex-start; gap: 16px; margin-bottom: 12px; }

.approve-icon { width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.listing-icon { background: #fef9c3; color: #a16207; }
.program-icon { background: #ecfdf5; color: #059669; }
.payout-icon { background: #fef3c7; color: #b45309; }
.withdrawal-icon { background: #eff6ff; color: #1d4ed8; }

.approve-body { flex: 1; min-width: 0; }
.approve-title-row { display: flex; align-items: center; gap: 8px; margin-bottom: 4px; flex-wrap: wrap; }
.approve-title { font-weight: 600; font-size: 15px; }
.approve-meta { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; font-size: 13px; color: var(--text-muted); margin-bottom: 6px; }
.approve-desc { font-size: 13px; color: var(--text); line-height: 1.5; margin-top: 6px; }
.approve-actions { display: flex; flex-direction: column; gap: 7px; flex-shrink: 0; align-self: flex-start; }
/* Phones: don't cram the body next to a button column — wrap actions full-width below. */
@media (max-width: 600px) {
  .approve-card { flex-wrap: wrap; padding: 14px; gap: 12px; }
  .approve-actions { flex-direction: row; flex-wrap: wrap; flex: 1 1 100%; gap: 8px; }
  .approve-actions > .btn { flex: 1 1 calc(50% - 4px); justify-content: center; }
}

/* type badge */
.type-badge { font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 4px; }
.type-listing { background: #fef9c3; color: #92400e; }
.type-program { background: #dcfce7; color: #14532d; }
.type-payout { background: #fef3c7; color: #92400e; }
.type-withdrawal { background: #e0f2fe; color: #075985; }

/* provider row */
.approve-provider-row { display: flex; align-items: center; gap: 10px; margin-bottom: 6px; }
.provider-chip { font-size: 12px; font-weight: 600; color: #1d4ed8; background: #eff6ff; border: 1px solid #bfdbfe; padding: 2px 8px; border-radius: 4px; }
.submitted-age { font-size: 12px; color: var(--text-muted); }

/* inline admin note */
.inline-admin-note { display: flex; align-items: flex-start; gap: 8px; margin-top: 6px; margin-bottom: 4px; padding: 8px 12px; background: #fff7ed; border: 1px solid #fed7aa; border-radius: 8px; font-size: 12px; color: #9a3412; }

.meta-pill { background: var(--bg); border: 1px solid var(--border); border-radius: 4px; padding: 1px 7px; font-size: 12px; font-weight: 500; color: var(--text); }

/* resubmit banner */
.resubmit-banner { display: flex; align-items: center; gap: 8px; padding: 8px 18px; background: #eff6ff; border: 1px solid #bfdbfe; border-bottom: none; border-radius: 12px 12px 0 0; font-size: 12px; font-weight: 600; color: #1d4ed8; }

/* diff toggle */
.diff-toggle { display: inline-flex; align-items: center; gap: 5px; margin-top: 10px; font-size: 12px; font-weight: 600; color: #1d4ed8; background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 6px; padding: 4px 10px; cursor: pointer; }
.diff-toggle:hover { background: #dbeafe; }

/* diff table */
.diff-table { display: flex; flex-direction: column; gap: 5px; margin-top: 8px; }
.diff-row { display: grid; grid-template-columns: 110px 1fr 16px 1fr; gap: 6px; align-items: center; background: #f9fafb; border: 1px solid var(--border); border-radius: 8px; padding: 7px 10px; font-size: 12px; }
.diff-field { font-weight: 600; color: var(--text-muted); font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em; }
.diff-before, .diff-after { display: flex; align-items: center; gap: 5px; min-width: 0; }
.diff-val { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 180px; }
.diff-arrow { color: var(--text-muted); flex-shrink: 0; }
.diff-tag { font-size: 10px; font-weight: 700; text-transform: uppercase; padding: 1px 5px; border-radius: 4px; flex-shrink: 0; }
.diff-tag.before { background: #fee2e2; color: #991b1b; }
.diff-tag.after { background: #dcfce7; color: #166534; }

/* uniform card detail block (payout KYC fields / withdrawal note) */
.req-detail { display: flex; flex-direction: column; gap: 2px; font-size: 12.5px; color: var(--text-muted); line-height: 1.5; margin-top: 4px; }
.req-detail b { color: var(--text); font-weight: 600; }
.req-doc { display: inline-flex; align-items: center; gap: 4px; font-size: 12px; font-weight: 600; color: var(--brand, #0f766e); margin-top: 8px; }

/* modals */
.btn-icon { padding: 6px; border-radius: 6px; color: var(--text-muted); background: transparent; border: 0; cursor: pointer; }
.btn-icon:hover { background: var(--bg); color: var(--text); }
.modal-backdrop { position: fixed; inset: 0; background: rgba(15, 23, 42, 0.45); display: flex; align-items: center; justify-content: center; z-index: 50; padding: 20px; }
.modal-card { background: var(--surface, #ffffff); border-radius: 14px; width: 100%; max-width: 480px; box-shadow: 0 20px 60px rgba(0,0,0,0.2); overflow: hidden; }
.modal-wide { max-width: 680px; max-height: 88vh; display: flex; flex-direction: column; }
.modal-head { display: flex; align-items: center; justify-content: space-between; padding: 16px 20px; border-bottom: 1px solid var(--border); flex-shrink: 0; }
.modal-title { font-weight: 600; font-size: 15px; display: flex; align-items: center; gap: 8px; }
.modal-body { padding: 18px 20px; overflow-y: auto; }
.modal-sub { font-size: 13px; color: var(--text-muted); margin-bottom: 14px; line-height: 1.5; }
.field-label { font-size: 12px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.04em; display: block; margin-bottom: 6px; }
.modal-body textarea { width: 100%; font: inherit; font-size: 14px; padding: 10px 12px; border-radius: 8px; border: 1px solid var(--border); resize: vertical; box-sizing: border-box; }
.modal-body textarea:focus { outline: none; border-color: #f59e0b; box-shadow: 0 0 0 3px rgba(245,158,11,0.15); }
.modal-foot { display: flex; justify-content: flex-end; gap: 8px; padding: 14px 20px; background: #f9fafb; border-top: 1px solid var(--border); flex-shrink: 0; }

/* preview modal */
.modal-preview { max-width: 760px; max-height: 90vh; display: flex; flex-direction: column; }
.preview-tabs { display: flex; gap: 2px; padding: 0 16px; background: var(--bg); border-bottom: 1px solid var(--border); flex-shrink: 0; }
.preview-tab { display: flex; align-items: center; gap: 6px; padding: 10px 14px; font-size: 13px; font-weight: 500; color: var(--text-muted); background: transparent; border: none; border-bottom: 2px solid transparent; cursor: pointer; margin-bottom: -1px; transition: color 0.15s, border-color 0.15s; white-space: nowrap; }
.preview-tab:hover { color: var(--text); }
.preview-tab.active { color: #0f172a; border-bottom-color: #0f172a; font-weight: 600; }
.preview-body { overflow-y: auto; flex: 1; }

.preview-provider-row { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; }
.preview-photo-wrap { margin-bottom: 16px; border-radius: 10px; overflow: hidden; max-height: 200px; }
.preview-photo { width: 100%; height: 200px; object-fit: cover; display: block; }
.preview-fields { display: grid; grid-template-columns: 1fr 1fr; gap: 0; border: 1px solid var(--border); border-radius: 10px; overflow: hidden; margin-bottom: 16px; }
.preview-field { padding: 10px 14px; border-bottom: 1px solid var(--border); border-right: 1px solid var(--border); }
.preview-field:nth-child(even) { border-right: none; }
.preview-field:nth-last-child(-n+2) { border-bottom: none; }
.preview-field-label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: var(--text-muted); margin-bottom: 3px; display: flex; align-items: center; gap: 4px; }
.preview-field-value { font-size: 13px; color: var(--text); }
.preview-field[style*="grid-column"] { border-right: none; }
.preview-field:has(.preview-desc-text),
.preview-field:has(.preview-url) { grid-column: 1 / -1; border-right: none; }
.preview-desc-text { margin: 0; line-height: 1.5; font-size: 13px; white-space: pre-wrap; }
.preview-url { font-family: monospace; font-size: 12px; word-break: break-all; }
.muted-val { color: var(--text-muted); }
.preview-section-label { display: flex; align-items: center; gap: 6px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.07em; color: var(--text-muted); margin-bottom: 8px; }

/* section titles within tabs */
.pv-section-title { display: flex; align-items: center; gap: 6px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-muted); margin-bottom: 10px; }

/* meta chips at bottom of details tab */
.pv-meta-row { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 4px; }
.pv-meta-chip { display: inline-flex; align-items: center; gap: 5px; font-size: 12px; color: var(--text-muted); background: var(--bg); border: 1px solid var(--border); border-radius: 999px; padding: 3px 10px; }

/* provider hero */
.pv-provider-hero { display: flex; align-items: center; gap: 14px; padding: 14px 16px; background: var(--bg); border: 1px solid var(--border); border-radius: 10px; margin-bottom: 4px; }
.pv-provider-avatar { width: 48px; height: 48px; border-radius: 12px; background: #0f172a; color: #fff; font-size: 20px; font-weight: 700; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.pv-provider-name { font-size: 15px; font-weight: 700; color: var(--text); }
.pv-provider-handle { font-size: 12px; color: var(--text-muted); margin-top: 2px; }
.pv-mono { font-family: 'SF Mono', Monaco, monospace; font-size: 12px; }

/* wallet row */
.pv-wallet-row { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
.pv-wallet-chip { background: var(--bg); border: 1px solid var(--border); border-radius: 10px; padding: 12px 14px; }
.pv-wallet-label { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; color: var(--text-muted); margin-bottom: 4px; }
.pv-wallet-val { font-size: 15px; font-weight: 700; color: var(--text); }

/* sibling listings */
.pv-sibling-row { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 8px 12px; border: 1px solid var(--border); border-radius: 8px; margin-bottom: 6px; font-size: 13px; }
.pv-sibling-name { font-weight: 500; color: var(--text); }

/* ---- phone (≤600px) ---- */
@media (max-width: 600px) {
  /* modals become bottom sheets */
  .modal-backdrop { padding: 0; align-items: flex-end; }
  .modal-card { max-width: 100%; border-radius: 16px 16px 0 0; max-height: 92dvh; display: flex; flex-direction: column; }
  .modal-body { flex: 1; overflow-y: auto; }
  .modal-body textarea { font-size: 16px; }
  .modal-foot { flex-wrap: wrap; }
  .modal-foot .btn { flex: 1 1 100%; justify-content: center; min-height: 44px; }
  .approve-actions > .btn { min-height: 44px; }

  /* preview modal tabs scroll in their own strip */
  .preview-tabs { overflow-x: auto; -webkit-overflow-scrolling: touch; scrollbar-width: none; }
  .preview-tabs::-webkit-scrollbar { display: none; }

  /* preview field grid → single column */
  .preview-fields { grid-template-columns: 1fr; }
  .preview-field { border-right: none !important; }
  .preview-field-value { word-break: break-word; }

  /* wallet summary stacks */
  .pv-wallet-row { grid-template-columns: 1fr; }

  /* provider hero wraps, long names break */
  .pv-provider-hero { flex-wrap: wrap; }
  .pv-provider-hero > div { min-width: 0; }
  .pv-provider-name { word-break: break-word; }

  /* diff rows restack vertically */
  .diff-row { grid-template-columns: 1fr; gap: 4px; }
  .diff-arrow { display: none; }
  .diff-val { max-width: none; white-space: normal; word-break: break-word; }

  /* long IBANs / account numbers in KYC details */
  .req-detail span { word-break: break-word; overflow-wrap: anywhere; min-width: 0; }

  /* long business name → age drops below the chip instead of overflowing one line */
  .approve-provider-row { flex-wrap: wrap; row-gap: 4px; }

  /* shorter preview photo so key fields stay near the fold */
  .preview-photo-wrap { max-height: 150px; }
  .preview-photo { height: 150px; }
}

</style>
