<script setup>
/**
 * LiabilityWaiver — a short, dead-simple "please understand this" box with a required
 * tick. Two flavours: `kind="host"` (shown before publishing) and `kind="join"` (shown
 * before requesting to join). Plain language + bold lead-ins so the key risks are clear
 * at a glance. v-model is the checked boolean.
 */
import { computed } from 'vue'
import { ShieldAlert, Store, Wallet, Trophy, AlertTriangle, Check } from 'lucide-vue-next'

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  kind: { type: String, default: 'host' },   // 'host' | 'join'
})
const emit = defineEmits(['update:modelValue'])

const HOST = [
  { icon: Store, lead: 'Bookboll is just the marketplace.', text: "We help people find your event. We don't run it or take part in it." },
  { icon: Wallet, lead: 'Player money goes to you, not Bookboll.', text: "You pay the venue for the court. Players/teams pay YOU — in person, or online into your own connected Stripe if you enabled online tickets (Bookboll only deducts its service fee per ticket). Their money is never Bookboll's." },
  { icon: Trophy, lead: 'Any prize pot is 100% yours to pay.', text: 'If you offer a prize, you pay the winners yourself. Bookboll does not hold, guarantee, or pay any prize.' },
  { icon: AlertTriangle, lead: "We don't promise it fills up.", text: "We can't guarantee enough players/teams join. If it doesn't fill and you cancel, that's your risk and our fee isn't refunded." },
]
const JOIN = [
  { icon: Store, lead: 'Bookboll is just the marketplace.', text: "We're connecting you with the host. We don't run the event." },
  { icon: Wallet, lead: 'Your fee goes to the host.', text: "You pay the host — in person, or online via the host's own Stripe when online tickets are on. Either way it's the host's money, and any dispute about it is between you two." },
  { icon: Trophy, lead: 'Prizes come from the host.', text: 'If there’s a prize pot, the HOST pays the winners directly. Bookboll does not hold or guarantee any prize.' },
  { icon: AlertTriangle, lead: 'You join at your own risk.', text: 'The host runs the event. Whatever happens is between you, the host and the venue — not Bookboll.' },
]

const points = computed(() => (props.kind === 'join' ? JOIN : HOST))
const agreeLabel = computed(() => props.kind === 'join'
  ? "I understand — I'll sort payment with the host myself, and Bookboll isn't responsible."
  : "I understand — I'm hosting at my own risk, and Bookboll isn't responsible.")
</script>

<template>
  <div class="wv">
    <div class="wv-head"><ShieldAlert :size="18" /> Before you continue — the important bits</div>
    <ul class="wv-list">
      <li v-for="(p, i) in points" :key="i">
        <span class="wv-ico"><component :is="p.icon" :size="16" /></span>
        <span class="wv-txt"><b>{{ p.lead }}</b> {{ p.text }}</span>
      </li>
    </ul>
    <label class="wv-agree" :class="{ on: modelValue }">
      <input type="checkbox" :checked="modelValue" @change="emit('update:modelValue', $event.target.checked)" />
      <span class="wv-box"><Check :size="14" /></span>
      <span class="wv-agree-t">{{ agreeLabel }}</span>
    </label>
  </div>
</template>

<style scoped>
.wv { border: 1.5px solid var(--warning-border, #fde68a); background: var(--warning-bg, #fffbeb); border-radius: 14px; padding: 15px 16px; margin: 14px 0; }
.wv-head { display: flex; align-items: center; gap: 8px; font-size: 14px; font-weight: 900; color: var(--warning-ink, #92400e); margin-bottom: 10px; }
.wv-list { list-style: none; margin: 0 0 4px; padding: 0; display: flex; flex-direction: column; gap: 10px; }
.wv-list li { display: flex; align-items: flex-start; gap: 10px; }
.wv-ico { flex-shrink: 0; width: 28px; height: 28px; border-radius: 8px; background: var(--warning-bg, #fef3c7); color: var(--warning-ink, #b45309); display: flex; align-items: center; justify-content: center; }
.wv-txt { font-size: 13px; color: var(--text-secondary, #475569); line-height: 1.5; }
.wv-txt b { color: var(--text, #0f172a); }
.wv-agree { display: flex; align-items: flex-start; gap: 10px; margin-top: 13px; padding-top: 13px; border-top: 1px dashed var(--warning-border, #fcd34d); cursor: pointer; }
.wv-agree input { position: absolute; opacity: 0; width: 0; height: 0; }
.wv-box { flex-shrink: 0; width: 24px; height: 24px; border-radius: 7px; border: 2px solid var(--border-strong, #cbd5e1); background: var(--surface, #ffffff); display: flex; align-items: center; justify-content: center; color: transparent; transition: .12s; }
.wv-agree.on .wv-box { background: #16a34a; border-color: #16a34a; color: #fff; }
.wv-agree-t { font-size: 13.5px; font-weight: 700; color: var(--text, #0f172a); line-height: 1.45; }
</style>
