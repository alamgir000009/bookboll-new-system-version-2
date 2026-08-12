<script setup>
import { ref, onMounted, watch, computed } from 'vue'
import { supabase } from '../../lib/supabase.js'
import { customer, isCustomer, selectedCountry } from '../../lib/customerSession.js'
import { loadMyBookings } from '../../customer/booking.js'
import { notifyBookingCancelled } from '../../customer/notificationDispatch.js'
import { CalendarCheck, Calendar, Clock, MapPin, Building2, X } from 'lucide-vue-next'
import { useSeo } from '../../lib/seo.js'
import { fmt12 } from '../../lib/slots.js'
useSeo({ title: 'My bookings', noindex: true, path: '/my-bookings' })

const bookings = ref([])
const loading = ref(true)
const cancelling = ref('')
const cur = computed(() => selectedCountry.value?.currency_code || 'AED')

// Customer self-cancel of an upcoming booking.
async function cancelBooking(b) {
  if (!b || cancelling.value) return
  if (!window.confirm(`Cancel ${b.listingName} on ${fmtDate(b.booking_date)} at ${fmtTime(b.start_time)}? This can't be undone.`)) return
  cancelling.value = b.id
  const { error } = await supabase.from('bookings').update({ booking_status: 'cancelled' }).eq('id', b.id)
  cancelling.value = ''
  if (error) { window.alert('Sorry — the booking could not be cancelled. Please try again.'); return }
  // Fire-and-forget cancellation notification to the customer (their own booking).
  try {
    notifyBookingCancelled({
      ownerId: null,
      customer: { id: customer.value?.id, name: customer.value?.full_name || customer.value?.name, email: customer.value?.email, phone: customer.value?.phone },
      booking: { listing: b.listingName, venue: b.locationName, business_name: b.locationName, date: fmtDate(b.booking_date), start_time: fmtTime(b.start_time), code: String(b.id).slice(0, 8).toUpperCase(), currency: cur.value },
    })
  } catch { /* non-blocking */ }
  load()
}

async function load() {
  if (!isCustomer.value) { loading.value = false; return }
  loading.value = true
  bookings.value = await loadMyBookings(customer.value.id)
  loading.value = false
}
onMounted(load)
watch(() => customer.value?.id, load)

function fmtDate(d) { return d ? new Date(d + 'T00:00:00').toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' }) : '' }
function fmtTime(t) { return fmt12(t) }
const today = new Date().toISOString().slice(0, 10)
const upcoming = computed(() => bookings.value.filter(b => b.booking_date >= today && b.booking_status !== 'cancelled'))
const past = computed(() => bookings.value.filter(b => b.booking_date < today || b.booking_status === 'cancelled'))

const payLabel = { paid: 'Paid', pending: 'Pay at venue', partial: 'Deposit paid · rest at venue', skipped: '—' }
</script>

<template>
  <div class="mb">
    <div v-if="!isCustomer" class="cmsg">Please <router-link to="/account">sign in</router-link> to see your bookings.</div>
    <div v-else-if="loading" class="cmsg">Loading…</div>
    <template v-else>
      <h1 class="mb-h1"><CalendarCheck :size="24" /> My Bookings</h1>

      <div v-if="!bookings.length" class="mb-empty">
        <CalendarCheck :size="40" />
        <span>No bookings yet.</span>
        <router-link to="/facilities" class="mb-cta">Find a venue</router-link>
      </div>

      <template v-else>
        <h2 v-if="upcoming.length" class="mb-h2">Upcoming</h2>
        <div class="mb-list">
          <div v-for="b in upcoming" :key="b.id" class="mb-row">
            <div class="mb-icon"><Building2 :size="20" /></div>
            <div class="mb-info">
              <div class="mb-title">{{ b.listingName }}</div>
              <div class="mb-meta"><MapPin :size="12" /> {{ b.locationName }}</div>
              <div class="mb-meta"><Calendar :size="12" /> {{ fmtDate(b.booking_date) }} · <Clock :size="12" /> {{ fmtTime(b.start_time) }}–{{ fmtTime(b.end_time) }}</div>
            </div>
            <div class="mb-right">
              <div class="mb-total">{{ cur }} {{ Number(b.total).toLocaleString() }}</div>
              <span class="mb-pay" :class="'pay-' + b.payment_status">{{ payLabel[b.payment_status] || b.payment_status }}</span>
              <span v-if="b.payment_status === 'partial'" class="mb-nr">deposit non-refundable</span>
              <button class="mb-cancel" :disabled="cancelling === b.id" @click="cancelBooking(b)">
                <X :size="12" />{{ cancelling === b.id ? 'Cancelling…' : 'Cancel' }}
              </button>
            </div>
          </div>
        </div>

        <h2 v-if="past.length" class="mb-h2">Past</h2>
        <div class="mb-list">
          <div v-for="b in past" :key="b.id" class="mb-row past">
            <div class="mb-icon"><Building2 :size="20" /></div>
            <div class="mb-info">
              <div class="mb-title">{{ b.listingName }}</div>
              <div class="mb-meta"><Calendar :size="12" /> {{ fmtDate(b.booking_date) }} · {{ fmtTime(b.start_time) }}</div>
            </div>
            <div class="mb-right"><div class="mb-total">{{ cur }} {{ Number(b.total).toLocaleString() }}</div></div>
          </div>
        </div>
      </template>
    </template>
  </div>
</template>

<style scoped>
.mb { max-width: 640px; }
.cmsg { text-align: center; padding: 50px; color: var(--text-light, #94a3b8); }
.cmsg a { color: var(--brand); font-weight: 700; }
.mb-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 18px; flex-wrap: wrap; }
.mb-head .mb-h1 { margin-bottom: 0; }
.mb-pass { font-size: 13.5px; font-weight: 800; color: var(--brand); text-decoration: none; white-space: nowrap; }
.mb-h1 { font-size: 26px; font-weight: 900; color: #0f172a; margin: 0 0 18px; display: flex; align-items: center; gap: 10px; }
.mb-h1 svg { color: var(--brand); flex-shrink: 0; }
.mb-h2 { font-size: 16px; font-weight: 800; color: #0f172a; margin: 22px 0 12px; }
.mb-empty { display: flex; flex-direction: column; align-items: center; gap: 14px; padding: 56px; color: var(--text-light, #94a3b8); }
.mb-cta { background: var(--brand); color: #fff; font-weight: 800; padding: 11px 20px; border-radius: 999px; text-decoration: none; font-size: 14px; }
.mb-list { display: flex; flex-direction: column; gap: 10px; }
.mb-row { display: flex; align-items: center; gap: 14px; background: var(--surface, #ffffff); border: 1px solid var(--border, #e2e8f0); border-radius: 14px; padding: 14px 16px; }
.mb-row.past { opacity: .72; }
.mb-icon { width: 44px; height: 44px; border-radius: 12px; background: color-mix(in srgb, var(--brand) 8%, white); color: var(--brand); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.mb-info { flex: 1; min-width: 0; }
.mb-title { font-size: 15.5px; font-weight: 800; color: #0f172a; }
.mb-meta { display: flex; align-items: center; gap: 4px; font-size: 12.5px; color: var(--text-muted, #64748b); margin-top: 3px; }
.mb-right { text-align: right; flex-shrink: 0; }
.mb-total { font-size: 15px; font-weight: 800; color: #0f172a; }
.mb-pay { display: inline-block; margin-top: 4px; font-size: 11px; font-weight: 800; padding: 3px 9px; border-radius: 999px; }
.pay-pending { background: #fef3c7; color: #92400e; }
.pay-paid { background: #dcfce7; color: #166534; }
.pay-partial { background: #dbeafe; color: #1d4ed8; }
/* Deposit bookings: the paid deposit is never refunded — say so right on the card. */
.mb-nr { display: inline-block; margin-top: 3px; font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: .3px; color: #b45309; background: #fef3c7; border-radius: 5px; padding: 1px 6px; }
.pay-skipped { background: #f1f5f9; color: var(--text-muted, #64748b); }
.mb-cancel { display: inline-flex; align-items: center; gap: 4px; margin-top: 8px; font-size: 12px; font-weight: 700; color: #b91c1c; background: #fef2f2; border: 1px solid #fecaca; border-radius: 999px; padding: 5px 11px; cursor: pointer; }
.mb-cancel:hover:not(:disabled) { background: #fee2e2; }
.mb-cancel:disabled { opacity: .6; cursor: default; }

@media (max-width: 760px) {
  /* Tighten the page header so the first booking / CTA sits higher */
  .mb-h1 { font-size: 20px; margin-bottom: 12px; gap: 8px; }
  .mb-h1 svg { width: 20px; height: 20px; }
  .mb-h2 { margin: 14px 0 8px; font-size: 16px; }

  /* Denser rows: reclaim horizontal width from the decorative icon */
  .mb-row { gap: 10px; padding: 12px 13px; }
  .mb-title { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; line-height: 1.25; }
  .mb-icon { width: 34px; height: 34px; border-radius: 10px; }
  .mb-meta { flex-wrap: wrap; row-gap: 2px; }
  .mb-total { font-size: 14px; }

  /* Raise the only primary action + give it a >=44px tap height */
  .mb-empty { padding: 40px 20px; gap: 12px; }
  .mb-cta { padding: 14px 24px; font-size: 15px; }
  .cmsg { padding: 40px 20px; }
}
</style>
