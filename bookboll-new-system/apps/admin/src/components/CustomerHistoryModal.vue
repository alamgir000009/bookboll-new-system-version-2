<script setup>
import { ref, watch, computed } from 'vue'
import { fetchCustomerCrm } from '../lib/customers.js'
import { useCurrentUser } from '../lib/currentUser.js'
import { useJurisdiction } from '../lib/jurisdiction.js'
import { fmt12 } from '../lib/slots'
import { X, ChevronLeft, ArrowRight, MapPin, Loader, Star, Mail, Phone, Award } from 'lucide-vue-next'

const props = defineProps({
  customerId: { type: String, default: null },
})
const emit = defineEmits(['close', 'viewBooking'])

// Bookboll-side accounts see the customer's full history; providers only see
// bookings made at their own venues.
const { role, isSuperAdmin, isCountryAdmin, currentOwnerUserId } = useCurrentUser()
// Platform roles ONLY (see CustomerDetailModal — ownerless staff must not see all).
const isBookbollSide = computed(() => isSuperAdmin.value || isCountryAdmin.value || role.value === 'platform_staff')

const customer       = ref(null)
const bookings       = ref([])
const listings       = ref([])
const locations      = ref([])
const loading        = ref(false)
const search         = ref('')
const statusFilter   = ref('')

watch(() => props.customerId, async (id) => {
  if (!id) { customer.value = null; bookings.value = []; return }
  loading.value = true
  customer.value = null
  bookings.value = []
  search.value = ''
  statusFilter.value = ''

  const res = await fetchCustomerCrm(id)
  const d = res.success ? (res.data || {}) : {}
  customer.value   = d.customer || null
  bookings.value   = d.bookings || []
  listings.value   = d.listings || []
  locations.value  = d.locations || []
  loading.value = false
}, { immediate: true })

function listingName(id)  { return listings.value.find(l => l.id === id)?.name  || '—' }
function locationName(id) { return locations.value.find(l => l.id === id)?.name || '—' }
function currency(locId)  { return locations.value.find(l => l.id === locId)?.currency_override || 'AED' }

function bookingRef(b) {
  const id = String(b.id || '')
  return `#BKN_${new Date(b.created_at).getFullYear()}_${id.slice(0,2).toUpperCase()}${id.slice(-3).toUpperCase()}`
}

function fmtDate(d) {
  if (!d) return '—'
  return new Date(d + 'T00:00:00').toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' })
}

function fmtDateTime(iso) {
  if (!iso) return '—'
  return new Date(iso).toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' })
}

function fmtTime(t) {
  if (!t) return ''
  return fmt12(t)
}

function statusClass(s) {
  return {
    confirmed: 'chm-status-confirmed', completed: 'chm-status-completed',
    cancelled: 'chm-status-cancelled', pending: 'chm-status-pending',
    paid: 'chm-status-paid', deposit_paid: 'chm-status-deposit',
    no_show: 'chm-status-noshow',
    skipped: 'chm-status-skipped',
  }[s] || 'chm-status-neutral'
}

function avatarInitials(name) {
  if (!name) return '?'
  const parts = name.trim().split(' ')
  return parts.length >= 2 ? (parts[0][0] + parts[parts.length - 1][0]).toUpperCase() : name.slice(0, 2).toUpperCase()
}

const tierBadgeStyle = (t) => ({
  Rookie: { bg: '#fef3c7', color: '#92400e' },
  Star: { bg: '#f1f5f9', color: '#475569' },
  Legend: { bg: '#fef9c3', color: '#854d0e' },
  GOAT: { bg: '#d1fae5', color: '#065f46' },
}[t] || { bg: '#f1f5f9', color: '#64748b' })

// Provider scope: only bookings made at venues this user owns.
const { allowedLocationIds } = useJurisdiction({ locations })
const ownedLocationIds = computed(() => {
  if (isBookbollSide.value) return allowedLocationIds.value   // null = super; Set = jurisdiction
  return new Set(locations.value.filter(l => l.owner_user_id === currentOwnerUserId.value).map(l => l.id))
})
const scopedBookings = computed(() => {
  const owned = ownedLocationIds.value
  return owned ? bookings.value.filter(b => owned.has(b.location_id)) : bookings.value
})

const filtered = computed(() => {
  return scopedBookings.value.filter(b => {
    if (statusFilter.value && b.booking_status !== statusFilter.value) return false
    if (search.value) {
      const q = search.value.toLowerCase()
      const loc = locationName(b.location_id).toLowerCase()
      const lst = listingName(b.listing_id).toLowerCase()
      if (!loc.includes(q) && !lst.includes(q) && !b.booking_date?.includes(q)) return false
    }
    return true
  })
})

const stats = computed(() => ({
  total: scopedBookings.value.length,
  completed: scopedBookings.value.filter(b => b.booking_status === 'completed').length,
  pending: scopedBookings.value.filter(b => b.booking_status === 'confirmed' || b.booking_status === 'pending').length,
  totalSpent: scopedBookings.value.reduce((s, b) => s + Number(b.total || 0), 0),
}))
</script>

<template>
  <Teleport to="body">
    <div v-if="customerId" class="chm-backdrop" @click.self="emit('close')">
      <div class="chm-panel">

        <!-- Header -->
        <div class="chm-header">
          <div class="chm-header-left">
            <div class="chm-title">Customer Booking History</div>
            <div v-if="!isBookbollSide" class="chm-subtitle">Showing bookings at your business only</div>
          </div>
          <button class="chm-close" @click="emit('close')"><X :size="18" /></button>
        </div>

        <div v-if="loading" class="chm-loading"><Loader :size="20" class="chm-spin" />Loading…</div>

        <template v-else-if="customer">
          <!-- Customer card -->
          <div class="chm-customer-card">
            <div class="chm-avatar">{{ avatarInitials(customer.full_name) }}</div>
            <div class="chm-customer-info">
              <div class="chm-customer-name">{{ customer.full_name }}</div>
              <div class="chm-customer-meta">
                <span v-if="customer.email"><Mail :size="12" />{{ customer.email }}</span>
                <span v-if="customer.phone"><Phone :size="12" />{{ customer.phone }}</span>
              </div>
              <div class="chm-customer-badges">
                <span
                  class="chm-tier-badge"
                  :style="{ background: tierBadgeStyle(customer.loyalty_tier).bg, color: tierBadgeStyle(customer.loyalty_tier).color }"
                >
                  <Award :size="11" />{{ customer.loyalty_tier }}
                </span>
                <span class="chm-points-badge"><Star :size="11" />{{ customer.loyalty_points ?? 0 }} pts</span>
              </div>
            </div>
          </div>

          <!-- Stats row -->
          <div class="chm-stats-row">
            <div class="chm-stat"><div class="chm-stat-val">{{ stats.total }}</div><div class="chm-stat-lbl">Total</div></div>
            <div class="chm-stat"><div class="chm-stat-val">{{ stats.completed }}</div><div class="chm-stat-lbl">Completed</div></div>
            <div class="chm-stat"><div class="chm-stat-val">{{ stats.pending }}</div><div class="chm-stat-lbl">Upcoming</div></div>
            <div class="chm-stat"><div class="chm-stat-val">AED {{ stats.totalSpent.toLocaleString() }}</div><div class="chm-stat-lbl">Spent</div></div>
          </div>

          <!-- Filters -->
          <div class="chm-filters">
            <div class="chm-search-wrap">
              <input v-model="search" placeholder="Search venue, listing..." class="chm-search" />
            </div>
            <select v-model="statusFilter" class="chm-select">
              <option value="">All Status</option>
              <option value="confirmed">Confirmed</option>
              <option value="completed">Completed</option>
              <option value="pending">Pending</option>
              <option value="cancelled">Cancelled</option>
              <option value="no_show">No Show</option>
            </select>
          </div>

          <!-- Booking list -->
          <div v-if="!filtered.length" class="chm-empty">No bookings match your filters.</div>
          <div v-else class="chm-list">
            <div
              v-for="b in filtered" :key="b.id"
              class="chm-bk-card"
              @click="emit('viewBooking', b.id); emit('close')"
            >
              <div class="chm-bk-top">
                <span class="chm-bk-ref">Booking {{ bookingRef(b) }}</span>
                <span class="chm-status-chip" :class="statusClass(b.booking_status)">{{ b.booking_status }}</span>
              </div>
              <div class="chm-bk-amount">{{ currency(b.location_id) }} {{ Number(b.total || 0).toLocaleString(undefined, { minimumFractionDigits: 2 }) }}</div>
              <div class="chm-bk-meta">
                <span><MapPin :size="11" />{{ locationName(b.location_id) }}</span>
                <span>{{ listingName(b.listing_id) }}</span>
                <span>{{ fmtDate(b.booking_date) }} &nbsp;·&nbsp; {{ fmtTime(b.start_time) }} – {{ fmtTime(b.end_time) }}</span>
              </div>
              <div class="chm-bk-footer">
                <span class="chm-status-chip" :class="statusClass(b.payment_status)">{{ b.payment_status }}</span>
                <span class="chm-bk-date">{{ fmtDateTime(b.created_at) }}</span>
                <span class="chm-bk-cta">View details <ArrowRight :size="12" /></span>
              </div>
            </div>
          </div>
        </template>

        <div v-else-if="!loading" class="chm-loading">Customer not found.</div>
      </div>
    </div>
  </Teleport>
</template>

<style>
.chm-backdrop {
  position: fixed; inset: 0; background: rgba(15,23,42,.45);
  z-index: 1100; display: flex; justify-content: flex-end;
  backdrop-filter: blur(2px);
}
.chm-panel {
  width: 100%; max-width: 520px; height: 100%; background: var(--surface, #ffffff);
  display: flex; flex-direction: column;
  box-shadow: -8px 0 40px rgba(0,0,0,.18);
  animation: chmSlide .22s cubic-bezier(.22,1,.36,1);
}
@keyframes chmSlide { from { transform: translateX(40px); opacity: 0; } to { transform: translateX(0); opacity: 1; } }

.chm-header {
  display: flex; align-items: center; justify-content: space-between;
  padding: 18px 20px 14px; border-bottom: 1px solid var(--border, #e2e8f0); flex-shrink: 0;
}
.chm-header-left { display: flex; flex-direction: column; gap: 3px; }
.chm-title { font-size: 17px; font-weight: 700; color: #0f172a; }
.chm-subtitle { font-size: 12px; color: var(--text-light, #94a3b8); }
.chm-close {
  width: 32px; height: 32px; border-radius: 8px; border: none; background: #f1f5f9;
  display: flex; align-items: center; justify-content: center; cursor: pointer; color: var(--text-muted, #64748b);
}
.chm-close:hover { background: var(--border, #e2e8f0); color: #0f172a; }

.chm-loading {
  flex: 1; display: flex; align-items: center; justify-content: center;
  gap: 10px; color: var(--text-muted, #64748b); font-size: 14px;
}
.chm-spin { animation: chmSpinAnim 1s linear infinite; }
@keyframes chmSpinAnim { to { transform: rotate(360deg); } }

/* Customer card */
.chm-customer-card {
  display: flex; align-items: flex-start; gap: 14px;
  padding: 16px 20px; border-bottom: 1px solid #f1f5f9; flex-shrink: 0;
}
.chm-avatar {
  width: 48px; height: 48px; border-radius: 50%; background: #0f172a; color: #fff;
  display: flex; align-items: center; justify-content: center;
  font-size: 16px; font-weight: 700; flex-shrink: 0;
}
.chm-customer-info { display: flex; flex-direction: column; gap: 4px; }
.chm-customer-name { font-size: 15px; font-weight: 700; color: #0f172a; }
.chm-customer-meta {
  display: flex; gap: 14px; flex-wrap: wrap;
  font-size: 12px; color: var(--text-muted, #64748b);
}
.chm-customer-meta span { display: flex; align-items: center; gap: 4px; }
.chm-customer-badges { display: flex; gap: 6px; margin-top: 2px; }
.chm-tier-badge, .chm-points-badge {
  display: inline-flex; align-items: center; gap: 4px;
  padding: 2px 8px; border-radius: 99px; font-size: 11px; font-weight: 600;
}
.chm-points-badge { background: #f0fdf4; color: #15803d; }

/* Stats */
.chm-stats-row {
  display: grid; grid-template-columns: repeat(4, 1fr);
  border-bottom: 1px solid var(--border, #e2e8f0); flex-shrink: 0;
}
.chm-stat {
  padding: 12px 10px; text-align: center; border-right: 1px solid #f1f5f9;
}
.chm-stat:last-child { border-right: none; }
.chm-stat-val { font-size: 14px; font-weight: 700; color: #0f172a; }
.chm-stat-lbl { font-size: 11px; color: var(--text-light, #94a3b8); margin-top: 2px; text-transform: uppercase; letter-spacing: .04em; }

/* Filters */
.chm-filters {
  display: flex; gap: 10px; padding: 12px 20px;
  border-bottom: 1px solid #f1f5f9; flex-shrink: 0;
}
.chm-search-wrap { flex: 1; }
.chm-search {
  width: 100%; padding: 8px 12px; border: 1px solid var(--border, #e2e8f0); border-radius: 8px;
  font-size: 13px; color: #0f172a; background: #f8fafc; box-sizing: border-box;
}
.chm-search:focus { outline: none; border-color: #3b82f6; background: var(--surface, #ffffff); }
.chm-select {
  padding: 8px 12px; border: 1px solid var(--border, #e2e8f0); border-radius: 8px;
  font-size: 13px; color: #0f172a; background: #f8fafc; cursor: pointer;
}

/* Empty */
.chm-empty {
  padding: 40px 20px; text-align: center; color: var(--text-light, #94a3b8); font-size: 14px;
}

/* List */
.chm-list {
  flex: 1; overflow-y: auto; padding: 16px 20px; display: flex; flex-direction: column; gap: 12px;
}
.chm-bk-card {
  border: 1px solid var(--border, #e2e8f0); border-radius: 12px; padding: 14px;
  cursor: pointer; transition: all .15s; background: var(--surface, #ffffff);
}
.chm-bk-card:hover { border-color: #94a3b8; background: #f8fafc; box-shadow: 0 2px 8px rgba(0,0,0,.06); }
.chm-bk-top { display: flex; align-items: center; justify-content: space-between; margin-bottom: 4px; }
.chm-bk-ref { font-size: 12px; font-weight: 700; color: #0f172a; font-family: 'SF Mono', Monaco, monospace; }
.chm-bk-amount { font-size: 17px; font-weight: 700; color: #0f172a; margin-bottom: 8px; }
.chm-bk-meta {
  display: flex; flex-direction: column; gap: 3px;
  font-size: 12.5px; color: var(--text-muted, #64748b); margin-bottom: 10px;
}
.chm-bk-meta span { display: flex; align-items: center; gap: 4px; }
.chm-bk-footer { display: flex; align-items: center; gap: 8px; padding-top: 10px; border-top: 1px solid #f1f5f9; }
.chm-bk-date { font-size: 12px; color: var(--text-light, #94a3b8); margin-left: auto; }
.chm-bk-cta { display: flex; align-items: center; gap: 4px; font-size: 12px; font-weight: 500; color: #3b82f6; }

/* Status chips */
.chm-status-chip {
  display: inline-flex; align-items: center;
  padding: 2px 8px; border-radius: 99px; font-size: 11px; font-weight: 600; text-transform: capitalize;
}
.chm-status-confirmed  { background: #dbeafe; color: #1e40af; }
.chm-status-completed  { background: #d1fae5; color: #065f46; }
.chm-status-cancelled  { background: #f1f5f9; color: #475569; }
.chm-status-pending    { background: #fef3c7; color: #92400e; }
.chm-status-paid       { background: #d1fae5; color: #065f46; }
.chm-status-deposit    { background: #dbeafe; color: #1e40af; }
.chm-status-noshow     { background: #fee2e2; color: #991b1b; }
.chm-status-skipped    { background: #f1f5f9; color: var(--text-muted, #64748b); }
.chm-status-neutral    { background: #f1f5f9; color: var(--text-muted, #64748b); }
</style>
