<!--
  PaymentCancelled — where Stripe sends the customer back when they abandon checkout.

  Nothing was charged and no booking exists. The slot hold is deliberately LEFT in
  place so they can pick up where they left off; it expires on its own, and the
  floating resume card keeps counting it down.
-->
<script setup>
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import { checkoutSession, checkoutRemaining, endCheckoutSession } from '../../customer/bookingSession.js'
import { useSeo } from '../../lib/seo.js'
import { XCircle, RefreshCw, Home, MapPin } from 'lucide-vue-next'

useSeo({ title: 'Payment cancelled', description: 'Your payment was not completed.', path: '/payment/cancel' })

const router = useRouter()
const held = computed(() => !!checkoutSession.value && checkoutRemaining.value > 0)

const heldFor = computed(() => {
  const s = Math.max(0, checkoutRemaining.value)
  return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`
})

function retry() {
  const id = checkoutSession.value?.listingId
  router.push(id ? `/listings/${id}` : '/venues')
}

async function release() {
  await endCheckoutSession()
  router.push('/venues')
}
</script>

<template>
  <div class="pc">
    <div class="pc-head">
      <div class="pc-x"><XCircle :size="30" /></div>
      <h1>Payment cancelled</h1>
      <p>Nothing was charged and no booking was made.</p>
    </div>

    <div class="pc-card">
      <template v-if="held">
        <div class="pc-h">Your slots are still held</div>
        <div class="pc-name">{{ checkoutSession.listingName }}</div>
        <div v-if="checkoutSession.locationName" class="pc-meta">
          <MapPin :size="12" /> {{ checkoutSession.locationName }}
        </div>
        <div class="pc-meta">
          {{ checkoutSession.slotCount }} slot{{ checkoutSession.slotCount === 1 ? '' : 's' }}
          <span v-if="checkoutSession.whenLabel"> · {{ checkoutSession.whenLabel }}</span>
          · {{ checkoutSession.currency }} {{ Number(checkoutSession.total).toLocaleString() }}
        </div>
        <div class="pc-hold">Held for another <b>{{ heldFor }}</b> — finish checkout before it runs out.</div>
      </template>
      <template v-else>
        <div class="pc-h">What happened?</div>
        <p class="pc-body">
          You cancelled at the payment step, so nothing was booked. Any slots you had
          on hold have been released and are available again.
        </p>
      </template>
    </div>

    <div class="pc-actions">
      <button type="button" class="pc-btn pc-btn-primary" @click="retry">
        <RefreshCw :size="15" /> {{ held ? 'Finish checkout' : 'Try again' }}
      </button>
      <button v-if="held" type="button" class="pc-btn" @click="release">Release my slots</button>
      <button type="button" class="pc-btn" @click="router.push('/')"><Home :size="15" /> Home</button>
    </div>
  </div>
</template>

<style scoped>
.pc { max-width: 560px; margin: 0 auto; padding: 28px 16px 56px; }

.pc-head { text-align: center; margin-bottom: 22px; }
.pc-x {
  width: 60px; height: 60px; margin: 0 auto 14px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  background: var(--danger-bg, #fee2e2); color: var(--danger-ink, #dc2626);
}
.pc-head h1 { font-size: 23px; font-weight: 800; margin: 0 0 6px; }
.pc-head p { margin: 0; opacity: .75; font-size: 14px; }

.pc-card { border: 1px solid var(--line, #e5e7eb); border-radius: 12px; padding: 16px; }
.pc-h { font-weight: 700; font-size: 14px; margin-bottom: 8px; }
.pc-name { font-weight: 700; font-size: 15px; }
.pc-meta { display: flex; align-items: center; gap: 5px; font-size: 12.5px; opacity: .7; margin-top: 4px; }
.pc-hold { margin-top: 10px; font-size: 13px; }
.pc-body { margin: 0; font-size: 13.5px; opacity: .75; line-height: 1.55; }

.pc-actions { display: flex; flex-wrap: wrap; gap: 8px; justify-content: center; margin-top: 22px; }
.pc-btn {
  display: inline-flex; align-items: center; gap: 6px; font-size: 13.5px; font-weight: 600;
  padding: 9px 14px; border-radius: 9px; cursor: pointer;
  border: 1px solid var(--line, #e5e7eb); background: transparent; color: inherit;
}
.pc-btn-primary { background: var(--brand, #0f766e); border-color: var(--brand, #0f766e); color: #fff; }
</style>
