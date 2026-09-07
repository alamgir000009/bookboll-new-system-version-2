<script setup>
/**
 * PayBooking.vue — /pay/:id, the page behind the desk "Pay by QR" code.
 * The customer scans the QR shown in the admin's Booking Details, lands here,
 * sees the booking's bill, and pays the outstanding balance by card.
 *
 * Works signed-out — the unguessable booking uuid in the link is the credential,
 * so the API returns only what the payer needs to recognise their own booking.
 *
 * Payment goes through Stripe Checkout. Settling the payment rows, confirming the
 * booking and the invoice all happen server-side when Stripe calls the webhook, so
 * a QR payment lands in exactly the same place as any other online payment.
 */
import { ref, computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { apiFetch } from '../../lib/api.js'
import { fmt12 } from '../../lib/slots.js'
import { useSeo } from '../../lib/seo.js'
import { CreditCard, Check, MapPin, CalendarDays, Loader2, TriangleAlert as AlertTriangle, ShieldCheck } from 'lucide-vue-next'

useSeo({ title: 'Pay for your booking', noindex: true, path: '/pay' })

const route = useRoute()
const loading = ref(true)
const err = ref('')
const bill = ref(null)

const due = computed(() => Number(bill.value?.due) || 0)
const total = computed(() => Number(bill.value?.total) || 0)
const paidSoFar = computed(() => Number(bill.value?.paidSoFar) || 0)
const currency = computed(() => bill.value?.currency || 'AED')
const bookingRef = computed(() => bill.value?.reference ? '#' + bill.value.reference : '')
const fmtMoney = (n) => Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const fmtDate = (d) => d ? new Date(d + 'T00:00:00').toLocaleDateString('en-US', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' }) : ''

// Stripe sends the customer back to /thank-you (paid) or /payment/cancel, so a
// return trip to this page just re-reads the bill and shows the settled state.
async function load() {
  loading.value = true
  err.value = ''
  const id = String(route.params.id || '').trim()
  if (!id) { err.value = 'This payment link is not valid.'; loading.value = false; return }
  const res = await apiFetch(`/pay/${encodeURIComponent(id)}`, { token: '' })
  loading.value = false
  if (!res.success || !res.data?.bill) {
    err.value = res.message || 'Booking not found — this payment link may be wrong or expired.'
    return
  }
  bill.value = res.data.bill
  if (bill.value.cancelled) err.value = 'This booking was cancelled, so there is nothing to pay.'
}
onMounted(load)

const paying = ref(false)
const payErr = ref('')

async function pay() {
  if (paying.value || !due.value) return
  payErr.value = ''
  paying.value = true
  const res = await apiFetch(`/pay/${encodeURIComponent(route.params.id)}/checkout`, { method: 'POST', token: '' })
  if (!res.success || !res.data?.url) {
    paying.value = false
    payErr.value = res.message || 'Could not start the payment. Please try again.'
    return
  }
  window.location.href = res.data.url
}
</script>

<template>
  <div class="pb">
    <div v-if="loading" class="pb-msg"><Loader2 :size="18" class="pb-spin" /> Loading your booking…</div>
    <div v-else-if="err" class="pb-msg pb-err"><AlertTriangle :size="20" /> {{ err }}</div>

    <template v-else>
      <!-- Bill + pay -->
      <div class="pb-card">
        <h1 class="pb-h1">{{ bill?.customerName ? bill.customerName + ', pay for your booking' : 'Pay for your booking' }}</h1>
        <div class="pb-venue">
          <div v-if="bill?.listingName" class="pb-venue-name">{{ bill.listingName }}</div>
          <div class="pb-venue-meta">
            <span v-if="bill?.venueName"><MapPin :size="13" /> {{ bill.venueName }}</span>
            <span v-if="bill?.date"><CalendarDays :size="13" /> {{ fmtDate(bill.date) }}<template v-if="bill.startTime"> · {{ fmt12(bill.startTime) }}</template></span>
          </div>
          <div class="pb-ref" data-no-translate>{{ bookingRef }}</div>
        </div>

        <div class="pb-bill">
          <div class="pb-row"><span>Total</span><span>{{ currency }} {{ fmtMoney(total) }}</span></div>
          <div class="pb-row"><span>Already paid</span><span>{{ currency }} {{ fmtMoney(paidSoFar) }}</span></div>
          <div v-for="p in (bill?.payments || [])" :key="p.id" class="pb-row"><span>{{ p.label }}</span><span>{{ currency }} {{ fmtMoney(p.amount) }}</span></div>
          <div class="pb-row pb-due"><span>To pay now</span><span>{{ currency }} {{ fmtMoney(due) }}</span></div>
        </div>

        <div v-if="!due" class="pb-paid-note"><ShieldCheck :size="16" /> Nothing left to pay — this booking is fully paid. 🎉</div>

        <template v-else>
          <div class="pb-payhead"><CreditCard :size="15" /> Card payment</div>
          <div class="pb-secure"><ShieldCheck :size="14" /> You'll finish on Stripe's secure checkout. Your card details never touch Bookboll.</div>
          <div v-if="payErr" class="pb-payerr"><AlertTriangle :size="14" /> {{ payErr }}</div>
          <button type="button" class="pb-btn" :disabled="paying" @click="pay">
            <Loader2 v-if="paying" :size="16" class="pb-spin" />
            {{ paying ? 'Opening secure checkout…' : `Pay ${currency} ${fmtMoney(due)}` }}
          </button>
        </template>
      </div>
    </template>
  </div>
</template>

<style scoped>
.pb { max-width: 460px; margin: 0 auto; padding: 8px 0 40px; }
.pb-msg { display: flex; align-items: center; justify-content: center; gap: 8px; padding: 60px 20px; color: var(--text-muted, #64748b); font-size: 14px; }
.pb-err { color: #b91c1c; }
.pb-spin { animation: pbspin 1s linear infinite; } @keyframes pbspin { to { transform: rotate(360deg); } }

.pb-card { background: var(--surface, #ffffff); border: 1px solid var(--border, #e2e8f0); border-radius: 18px; padding: 22px 20px; }
.pb-h1 { font-size: 21px; font-weight: 900; color: #0f172a; margin: 0 0 14px; }
.pb-venue { border: 1px solid #eef2f6; background: #f8fafc; border-radius: 12px; padding: 12px 14px; margin-bottom: 14px; }
.pb-venue-name { font-size: 15px; font-weight: 800; color: #0f172a; }
.pb-venue-meta { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 5px; font-size: 12.5px; color: var(--text-muted, #64748b); }
.pb-venue-meta span { display: inline-flex; align-items: center; gap: 5px; }
.pb-ref { font-size: 11.5px; font-weight: 800; letter-spacing: .08em; color: var(--text-light, #94a3b8); margin-top: 7px; }

.pb-bill { border: 1px solid #eef2f6; border-radius: 12px; overflow: hidden; margin-bottom: 16px; }
.pb-row { display: flex; justify-content: space-between; gap: 12px; padding: 10px 14px; font-size: 13.5px; color: #334155; border-bottom: 1px solid #f1f5f9; }
.pb-row:last-child { border-bottom: none; }
.pb-discount span { color: #16a34a; }
.pb-due { background: #f8fafc; }
.pb-due span { font-weight: 900; color: #0f172a; font-size: 15px; }

.pb-paid-note { display: flex; align-items: center; gap: 8px; background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; border-radius: 12px; padding: 12px 14px; font-size: 13.5px; font-weight: 700; }

.pb-payhead { display: flex; align-items: center; gap: 7px; font-size: 13px; font-weight: 800; color: #334155; margin: 2px 0 10px; }
.pb-in { width: 100%; box-sizing: border-box; border: 1.5px solid var(--border, #e2e8f0); border-radius: 11px; padding: 12px 14px; font-size: 15px; margin-bottom: 10px; }
.pb-in:focus { outline: none; border-color: var(--brand, #0f766e); }
.pb-row2 { display: flex; gap: 10px; }
.pb-row2 .pb-in { flex: 1; min-width: 0; }
.pb-secure { display: flex; align-items: flex-start; gap: 7px; font-size: 12.5px; line-height: 1.5; color: var(--text-muted, #64748b); margin: 2px 0 14px; }
.pb-payerr { display: flex; align-items: center; gap: 7px; color: #b91c1c; background: #fef2f2; border: 1px solid #fecaca; border-radius: 10px; padding: 9px 12px; font-size: 13px; font-weight: 600; margin-bottom: 10px; }
.pb-btn { width: 100%; display: flex; align-items: center; justify-content: center; gap: 8px; background: var(--brand, #0f766e); color: #fff; border: none; border-radius: 999px; padding: 14px; font-size: 15.5px; font-weight: 800; cursor: pointer; min-height: 50px; }
.pb-btn:disabled { opacity: .7; }

.pb-sub { font-size: 14px; color: var(--text-muted, #64748b); line-height: 1.6; margin: 0 0 12px; }
</style>
