<script setup>
/**
 * CheckoutResumeCard — floating "you have slots on hold" card shown across the storefront
 * while a venue checkout session is live (see bookingSession.js). Lets the customer jump
 * back to the listing (Continue) or release the hold (Close). Hidden on the listing's own
 * page, where the in-page countdown banner already covers it. Mounted once in CustomerLayout.
 */
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { checkoutSession, checkoutRemaining, endCheckoutSession } from '../../customer/bookingSession.js'
import { Clock, ArrowRight, X, MapPin } from 'lucide-vue-next'

const route = useRoute()
const router = useRouter()

// On the session's own listing page the card becomes a compact, read-only timer (slots are
// managed via the page itself); elsewhere it's the full card with Continue / Release.
const onOwnListing = computed(() =>
  !!checkoutSession.value && route.path === `/listings/${checkoutSession.value.listingId}`)
const show = computed(() => !!checkoutSession.value && checkoutRemaining.value > 0)

const mmss = computed(() => {
  const s = Math.max(0, checkoutRemaining.value)
  return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`
})
// Turn the timer red once under 3 minutes remain.
const URGENT_AT = 180
const urgent = computed(() => checkoutRemaining.value <= URGENT_AT)

function resume() { if (checkoutSession.value) router.push(`/listings/${checkoutSession.value.listingId}`) }
function close() { endCheckoutSession() }
</script>

<template>
  <transition name="crc">
    <!-- Compact read-only timer while on the booking page itself -->
    <div v-if="show && onOwnListing" class="crc crc-compact" :class="{ urgent }">
      <div class="crc-timer"><Clock :size="16" /> <span class="crc-mmss">{{ mmss }}</span></div>
      <div class="crc-compact-meta">
        slots reserved
        <span class="crc-total">· {{ checkoutSession.slotCount }} slot{{ checkoutSession.slotCount === 1 ? '' : 's' }} · {{ checkoutSession.currency }} {{ Number(checkoutSession.total).toLocaleString() }}</span>
      </div>
    </div>

    <!-- Full card with actions elsewhere on the storefront -->
    <div v-else-if="show" class="crc" :class="{ urgent }">
      <button type="button" class="crc-x" @click="close" aria-label="Release slots and close"><X :size="15" /></button>
      <div class="crc-timer"><Clock :size="15" /> <span class="crc-mmss">{{ mmss }}</span> <span class="crc-held">held</span></div>
      <div class="crc-name">{{ checkoutSession.listingName }}</div>
      <div v-if="checkoutSession.locationName" class="crc-loc"><MapPin :size="12" /> {{ checkoutSession.locationName }}</div>
      <div class="crc-meta">
        {{ checkoutSession.slotCount }} slot{{ checkoutSession.slotCount === 1 ? '' : 's' }}
        <span v-if="checkoutSession.whenLabel"> · {{ checkoutSession.whenLabel }}</span>
        <span class="crc-total"> · {{ checkoutSession.currency }} {{ Number(checkoutSession.total).toLocaleString() }}</span>
      </div>
      <div class="crc-actions">
        <button type="button" class="crc-go" @click="resume">Continue checkout <ArrowRight :size="15" /></button>
        <button type="button" class="crc-cancel" @click="close">Release</button>
      </div>
    </div>
  </transition>
</template>

<style scoped>
.crc { position: fixed; right: 18px; bottom: 18px; z-index: 250; width: 290px; max-width: calc(100vw - 36px);
  background: var(--surface, #ffffff); border: 1px solid var(--border, #e2e8f0); border-radius: 16px; padding: 14px 16px 13px;
  box-shadow: 0 16px 40px rgba(15,23,42,.18); border-top: 3px solid var(--brand, #0f766e); }
.crc.urgent { border-top-color: #dc2626; }
.crc-x { position: absolute; top: 9px; right: 9px; width: 26px; height: 26px; border-radius: 8px; border: none;
  background: #f1f5f9; color: var(--text-muted, #64748b); display: flex; align-items: center; justify-content: center; cursor: pointer; }
.crc-x:hover { background: #fee2e2; color: #dc2626; }
.crc-timer { display: inline-flex; align-items: center; gap: 5px; color: var(--brand, #0f766e); font-weight: 700; font-size: 12.5px; }
.crc.urgent .crc-timer { color: #dc2626; }
.crc-mmss { font-variant-numeric: tabular-nums; font-weight: 900; font-size: 14px; }
.crc-held { color: var(--text-light, #94a3b8); font-weight: 600; }
.crc-name { font-size: 15px; font-weight: 800; color: #0f172a; margin-top: 5px; line-height: 1.25; }
.crc-loc { display: flex; align-items: center; gap: 4px; font-size: 12px; color: var(--text-muted, #64748b); margin-top: 2px; }
.crc-meta { font-size: 12.5px; color: #475569; margin-top: 6px; }
.crc-total { font-weight: 700; color: #0f172a; }
.crc-actions { display: flex; align-items: center; gap: 8px; margin-top: 11px; }
.crc-go { flex: 1; display: inline-flex; align-items: center; justify-content: center; gap: 6px;
  background: var(--brand, #0f766e); color: #fff; border: none; border-radius: 10px; padding: 10px;
  font-size: 13.5px; font-weight: 800; cursor: pointer; }
.crc-go:hover { filter: brightness(.95); }
.crc-cancel { background: none; border: none; color: var(--text-light, #94a3b8); font-size: 12.5px; font-weight: 700; cursor: pointer; padding: 6px 4px; }
.crc-cancel:hover { color: #dc2626; }

/* Compact read-only variant shown on the booking page itself */
.crc-compact { width: auto; max-width: 320px; padding: 12px 16px; display: flex; align-items: center; gap: 12px; }
.crc-compact .crc-timer { font-size: 18px; }
.crc-compact .crc-mmss { font-size: 20px; }
.crc-compact-meta { font-size: 12.5px; font-weight: 700; color: #0f172a; line-height: 1.3; }
.crc-compact-meta .crc-total { display: block; font-size: 11.5px; font-weight: 600; color: var(--text-muted, #64748b); }

.crc-enter-active, .crc-leave-active { transition: opacity .2s, transform .2s; }
.crc-enter-from, .crc-leave-to { opacity: 0; transform: translateY(12px); }

@media (max-width: 520px) { .crc { right: 10px; left: 10px; width: auto; } }
/* Phones (≤767px = the .cust shell's bottom-bar breakpoint): the storefront shows a
   floating bottom nav bar, so lift the card above it (88px matches .cust-main's own
   clearance). Flow pages (.cust-inflow) and scoped embeds hide that bar, so there the
   card may hug the bottom edge again. */
@media (max-width: 767px) {
  .crc { bottom: calc(88px + env(safe-area-inset-bottom, 0px)); }
  /* Flow pages hide the nav but dock their own action bar at the true bottom edge —
     lift the card above that bar so Continue/Release never hides behind it. */
  .cust-inflow .crc { bottom: calc(84px + env(safe-area-inset-bottom, 0px)); }
  .cust-embed-scoped .crc { bottom: calc(10px + env(safe-area-inset-bottom, 0px)); }
  /* Tap targets: primary + Release reach the 44px minimum; close bumped to 34px. */
  .crc-go { min-height: 44px; }
  .crc-cancel { min-height: 44px; padding: 10px 12px; }
  .crc-x { width: 34px; height: 34px; }
}
/* On phones the listing page shows the held-slot countdown inside its own fixed
   action bar, so the compact floating timer here would just duplicate it. Hide it
   below the storefront phone breakpoint (the full resume card on OTHER pages stays). */
@media (max-width: 760px) { .crc-compact { display: none; } }
</style>
