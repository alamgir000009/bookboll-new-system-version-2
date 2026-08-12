<script setup>
/**
 * PayBooking.vue — /pay/:id, the page behind the desk "Pay by QR" code.
 * The customer scans the QR shown in the admin's Booking Details, lands here,
 * sees the booking's bill, and pays the outstanding balance online. Works
 * signed-out — the unguessable booking uuid in the link is the credential.
 *
 * Money notes:
 *  • Bookboll-owned venue (no owner) → the collected money is Bookboll's; the
 *    payment rows are simply settled online.
 *  • Provider venue → recordBookingOnlinePayment credits their BOOKINGS wallet
 *    (Stripe auto-payout or on-hold manual, same as any online payment). The
 *    commission rate: full-total collections carry the booking's commission
 *    rate; balance-after-deposit collections carry 0 (the deposit WAS the fee).
 *  • Demo checkout today — the card form swaps for real Stripe Checkout once a
 *    payments Edge Function (Stripe secret key) is deployed. Nothing else moves.
 */
import { ref, computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { supabase } from '../../lib/supabase.js'
import { recordBookingOnlinePayment } from '../../lib/bookingPayment.js'
import { notifyPaymentReceipt } from '../../customer/notificationDispatch.js'
import { fmt12 } from '../../lib/slots.js'
import { useSeo } from '../../lib/seo.js'
import { CreditCard, Check, MapPin, CalendarDays, Loader2, TriangleAlert as AlertTriangle, ShieldCheck } from 'lucide-vue-next'

useSeo({ title: 'Pay for your booking', noindex: true, path: '/pay' })

const route = useRoute()
const loading = ref(true)
const err = ref('')
const booking = ref(null)
const payments = ref([])
const listing = ref(null)
const location = ref(null)
const customerRow = ref(null)

const pending = computed(() => payments.value.filter(p => p.status === 'pending'))
const due = computed(() => +pending.value.reduce((s, p) => s + Number(p.amount || 0), 0).toFixed(2))
const total = computed(() => Number(booking.value?.total || 0))
const paidSoFar = computed(() => Math.max(0, +(total.value - due.value).toFixed(2)))
const currency = computed(() => pending.value[0]?.currency || payments.value[0]?.currency || 'AED')
const bookingRef = computed(() => booking.value ? '#BKN_' + String(booking.value.id).slice(0, 9).replace(/-/g, '').toUpperCase() : '')
const fmtMoney = (n) => Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const fmtDate = (d) => d ? new Date(d + 'T00:00:00').toLocaleDateString('en-US', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' }) : ''

async function load() {
  loading.value = true
  const id = String(route.params.id || '').trim()
  if (!id) { err.value = 'This payment link is not valid.'; loading.value = false; return }
  const { data: b } = await supabase.from('bookings').select('*').eq('id', id).maybeSingle()
  if (!b) { err.value = 'Booking not found — this payment link may be wrong or expired.'; loading.value = false; return }
  booking.value = b
  const [pRes, lRes, cRes] = await Promise.all([
    supabase.from('booking_payments').select('*').eq('booking_id', id).order('created_at'),
    b.listing_id ? supabase.from('listings').select('id, name, location_id').eq('id', b.listing_id).maybeSingle() : Promise.resolve({ data: null }),
    b.customer_id ? supabase.from('customers').select('id, full_name, email, phone').eq('id', b.customer_id).maybeSingle() : Promise.resolve({ data: null }),
  ])
  payments.value = pRes.data || []
  listing.value = lRes.data || null
  customerRow.value = cRes.data || null
  const locId = lRes.data?.location_id || b.location_id
  if (locId) {
    const { data: loc } = await supabase.from('locations').select('*').eq('id', locId).maybeSingle()
    location.value = loc || null
  }
  loading.value = false
}
onMounted(load)

// ── Card form (demo checkout, same masks as the membership checkout) ─────────
const card = ref({ number: '', exp: '', cvc: '' })
let prevExp = ''
function onExpInput() {
  const raw = String(card.value.exp || '')
  const deleting = raw.length < prevExp.length
  let d = raw.replace(/\D/g, '').slice(0, 4)
  if (d.length === 1 && Number(d) > 1) d = '0' + d
  let out
  if (d.length < 2) out = d
  else if (d.length === 2) out = deleting ? d : d + ' / '
  else out = d.slice(0, 2) + ' / ' + d.slice(2)
  card.value.exp = out
  prevExp = out
}
function onCardNumInput() {
  const d = String(card.value.number || '').replace(/\D/g, '').slice(0, 19)
  card.value.number = (d.match(/.{1,4}/g) || []).join(' ')
}
function onCvcInput() { card.value.cvc = String(card.value.cvc || '').replace(/\D/g, '').slice(0, 4) }

const paying = ref(false)
const payErr = ref('')
const done = ref(false)
const paidAmount = ref(0)

async function pay() {
  if (paying.value || !due.value) return
  if (!card.value.number.trim() || !card.value.exp.trim() || !card.value.cvc.trim()) {
    payErr.value = 'Enter your card details to continue.'
    return
  }
  payErr.value = ''
  paying.value = true
  const amount = due.value
  const nowIso = new Date().toISOString()
  try {
    // 1. Settle every pending installment as paid online.
    for (const p of pending.value) {
      await supabase.from('booking_payments').update({ status: 'paid', method: 'online', paid_at: nowIso }).eq('id', p.id)
    }
    await supabase.from('bookings').update({ payment_status: 'paid' }).eq('id', booking.value.id)

    // 2. Provider venue → credit their Bookings wallet. Commission only when this
    //    collection is the WHOLE total (nothing was prepaid — the deposit model's
    //    deposit already was Bookboll's fee).
    const ownerId = location.value?.owner_user_id || null
    if (ownerId) {
      let countryId = null
      if (location.value?.city_id) {
        const { data: city } = await supabase.from('cities').select('country_id').eq('id', location.value.city_id).maybeSingle()
        countryId = city?.country_id || null
      }
      const wholeTotal = paidSoFar.value <= 0
      const ratePct = wholeTotal ? (Number(booking.value.commission_rate) || 0) : 0
      try {
        await recordBookingOnlinePayment({
          bookingId: booking.value.id, ownerUserId: ownerId, countryId,
          total: amount, currency: currency.value, ratePct,
          customerLabel: customerRow.value?.full_name || 'QR payment',
        })
      } catch (e) { console.error('[PayBooking] wallet credit failed:', e) }
    }

    // 3. Receipt notification — best-effort.
    try {
      if (customerRow.value) {
        notifyPaymentReceipt({
          ownerId: location.value?.owner_user_id || null,
          customer: { id: customerRow.value.id, name: customerRow.value.full_name, email: customerRow.value.email, phone: customerRow.value.phone },
          booking: {
            listing: listing.value?.name, venue: location.value?.name, business_name: location.value?.name,
            date: booking.value?.booking_date, start_time: booking.value?.start_time ? fmt12(booking.value.start_time) : '',
            code: String(booking.value?.id || '').slice(0, 8).toUpperCase(),
            amount, currency: currency.value,
          },
        })
      }
    } catch { /* non-blocking */ }

    paidAmount.value = amount
    done.value = true
    await load()
  } finally {
    paying.value = false
  }
}
</script>

<template>
  <div class="pb">
    <div v-if="loading" class="pb-msg"><Loader2 :size="18" class="pb-spin" /> Loading your booking…</div>
    <div v-else-if="err" class="pb-msg pb-err"><AlertTriangle :size="20" /> {{ err }}</div>

    <template v-else>
      <!-- Success -->
      <div v-if="done" class="pb-card pb-done">
        <div class="pb-done-ic"><Check :size="30" /></div>
        <h1 class="pb-h1">Payment complete</h1>
        <p class="pb-sub">You paid <strong>{{ currency }} {{ fmtMoney(paidAmount) }}</strong> for {{ listing?.name || 'your booking' }}. Show this screen at the desk if asked — the venue sees it instantly.</p>
        <div class="pb-ref" data-no-translate>{{ bookingRef }}</div>
      </div>

      <!-- Bill + pay -->
      <div v-else class="pb-card">
        <h1 class="pb-h1">Pay for your booking</h1>
        <div class="pb-venue">
          <div v-if="listing?.name" class="pb-venue-name">{{ listing.name }}</div>
          <div class="pb-venue-meta">
            <span v-if="location?.name"><MapPin :size="13" /> {{ location.name }}</span>
            <span v-if="booking?.booking_date"><CalendarDays :size="13" /> {{ fmtDate(booking.booking_date) }}<template v-if="booking.start_time"> · {{ fmt12(booking.start_time) }}</template></span>
          </div>
          <div class="pb-ref" data-no-translate>{{ bookingRef }}</div>
        </div>

        <div class="pb-bill">
          <div class="pb-row"><span>Total</span><span>{{ currency }} {{ fmtMoney(total) }}</span></div>
          <div v-if="Number(booking?.discount) > 0" class="pb-row pb-discount"><span>Includes discount</span><span>− {{ currency }} {{ fmtMoney(booking.discount) }}</span></div>
          <div class="pb-row"><span>Already paid</span><span>{{ currency }} {{ fmtMoney(paidSoFar) }}</span></div>
          <div class="pb-row pb-due"><span>To pay now</span><span>{{ currency }} {{ fmtMoney(due) }}</span></div>
        </div>

        <div v-if="!due" class="pb-paid-note"><ShieldCheck :size="16" /> Nothing left to pay — this booking is fully paid. 🎉</div>

        <template v-else>
          <div class="pb-payhead"><CreditCard :size="15" /> Card payment</div>
          <input class="pb-in" v-model="card.number" placeholder="Card number" inputmode="numeric" autocomplete="cc-number" @input="onCardNumInput" />
          <div class="pb-row2">
            <input class="pb-in" v-model="card.exp" placeholder="MM / YY" inputmode="numeric" autocomplete="cc-exp" maxlength="7" @input="onExpInput" />
            <input class="pb-in" v-model="card.cvc" placeholder="CVC" inputmode="numeric" autocomplete="cc-csc" maxlength="4" @input="onCvcInput" />
          </div>
          <div class="pb-demo">Demo checkout — no real charge is made.</div>
          <div v-if="payErr" class="pb-payerr"><AlertTriangle :size="14" /> {{ payErr }}</div>
          <button type="button" class="pb-btn" :disabled="paying" @click="pay">
            <Loader2 v-if="paying" :size="16" class="pb-spin" />
            {{ paying ? 'Processing…' : `Pay ${currency} ${fmtMoney(due)}` }}
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
.pb-demo { font-size: 12px; color: var(--text-light, #94a3b8); margin: 2px 0 12px; }
.pb-payerr { display: flex; align-items: center; gap: 7px; color: #b91c1c; background: #fef2f2; border: 1px solid #fecaca; border-radius: 10px; padding: 9px 12px; font-size: 13px; font-weight: 600; margin-bottom: 10px; }
.pb-btn { width: 100%; display: flex; align-items: center; justify-content: center; gap: 8px; background: var(--brand, #0f766e); color: #fff; border: none; border-radius: 999px; padding: 14px; font-size: 15.5px; font-weight: 800; cursor: pointer; min-height: 50px; }
.pb-btn:disabled { opacity: .7; }

.pb-done { text-align: center; padding: 34px 22px; }
.pb-done-ic { width: 64px; height: 64px; border-radius: 50%; background: #dcfce7; color: #16a34a; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 14px; }
.pb-sub { font-size: 14px; color: var(--text-muted, #64748b); line-height: 1.6; margin: 0 0 12px; }
</style>
