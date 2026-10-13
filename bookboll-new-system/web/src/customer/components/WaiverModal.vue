<script setup>
/**
 * WaiverModal — a short, glanceable "Bookboll is just the marketplace" confirmation
 * shown right before a side-effecting action (joining, or publishing). Three punchy
 * points + one tap to agree. Opens on demand instead of sitting inline.
 *
 *  props: open, kind ('join' | 'host'), busy
 *  emits: 'agree' (user confirmed), 'close'
 */
import { computed } from 'vue'
import { Store, Wallet, ShieldAlert, ShieldCheck, X } from 'lucide-vue-next'

const props = defineProps({
  open: { type: Boolean, default: false },
  kind: { type: String, default: 'join' },
  busy: { type: Boolean, default: false },
})
const emit = defineEmits(['agree', 'close'])

const JOIN = [
  { icon: Store, lead: 'We just connect you', text: 'Bookboll is the marketplace — the host runs the event.' },
  { icon: Wallet, lead: 'Money is between you & the host', text: "Your fee goes to the host — in person, or online via the host's own Stripe when they enable online tickets. Prizes are always the host's responsibility, never Bookboll's." },
  { icon: ShieldAlert, lead: 'You join at your own risk', text: 'Whatever happens is between you, the host and the venue.' },
]
const HOST = [
  { icon: Store, lead: 'We just list it', text: 'Bookboll is the marketplace — you run the event.' },
  { icon: Wallet, lead: 'You handle the money', text: 'Players pay you — in person, or into your own Stripe with online tickets (Bookboll only deducts its fee per ticket). You pay the venue and any prize yourself.' },
  { icon: ShieldAlert, lead: "It's your risk", text: "If it doesn't fill and you cancel, any fee already paid isn't refunded." },
]

const points = computed(() => (props.kind === 'host' ? HOST : JOIN))
const title = computed(() => (props.kind === 'host' ? 'Before you publish' : 'Before you join'))
const cta = computed(() => (props.kind === 'host' ? 'Agree & publish' : 'Agree & send request'))
</script>

<template>
  <div v-if="open" class="wm-overlay" @click.self="emit('close')">
    <div class="wm" role="dialog" aria-modal="true">
      <button type="button" class="wm-x" @click="emit('close')" aria-label="Close"><X :size="18" /></button>
      <div class="wm-head"><ShieldCheck :size="20" /> {{ title }}</div>
      <p class="wm-sub">Quick heads-up — Bookboll is only the marketplace.</p>
      <ul class="wm-list">
        <li v-for="(p, i) in points" :key="i">
          <span class="wm-ico"><component :is="p.icon" :size="18" /></span>
          <span class="wm-txt"><b>{{ p.lead }}.</b> {{ p.text }}</span>
        </li>
      </ul>
      <div class="wm-actions">
        <button type="button" class="wm-cancel" @click="emit('close')">Cancel</button>
        <button type="button" class="wm-agree" :disabled="busy" @click="emit('agree')">{{ busy ? 'Sending…' : cta }}</button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.wm-overlay { position: fixed; inset: 0; z-index: 300; background: rgba(15,23,42,.55); display: flex; align-items: center; justify-content: center; padding: 18px; }
.wm { position: relative; width: 420px; max-width: 100%; background: var(--surface, #ffffff); border-radius: 20px; padding: 24px 22px 20px; box-shadow: 0 24px 60px rgba(0,0,0,.3); }
.wm-x { position: absolute; top: 12px; right: 12px; width: 34px; height: 34px; border-radius: 50%; border: none; background: #f1f5f9; color: #475569; display: flex; align-items: center; justify-content: center; cursor: pointer; }
.wm-x:hover { background: var(--border, #e2e8f0); }
.wm-head { display: flex; align-items: center; gap: 9px; font-size: 19px; font-weight: 900; color: #0f172a; }
.wm-head svg { color: var(--brand); }
.wm-sub { font-size: 13.5px; color: var(--text-muted, #64748b); margin: 6px 0 16px; }
.wm-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 14px; }
.wm-list li { display: flex; align-items: flex-start; gap: 12px; }
.wm-ico { flex-shrink: 0; width: 36px; height: 36px; border-radius: 10px; background: color-mix(in srgb, var(--brand) 8%, white); color: var(--brand); display: flex; align-items: center; justify-content: center; }
.wm-txt { font-size: 14px; color: #475569; line-height: 1.5; }
.wm-txt b { color: #0f172a; }
.wm-actions { display: flex; gap: 10px; margin-top: 22px; }
.wm-cancel { flex-shrink: 0; background: var(--surface, #ffffff); border: 1.5px solid var(--border, #e2e8f0); color: #334155; font-weight: 700; font-size: 14.5px; padding: 13px 18px; border-radius: 12px; cursor: pointer; }
.wm-agree { flex: 1; background: var(--brand); color: #fff; font-weight: 800; font-size: 14.5px; padding: 13px 18px; border: none; border-radius: 12px; cursor: pointer; }
.wm-agree:disabled { opacity: .6; }

/* ---- Mobile: dock as a bottom sheet, tighten spacing, land the CTA in the thumb zone ---- */
@media (max-width: 760px) {
  .wm-overlay { align-items: flex-end; padding: 0; }
  .wm { width: 100%; max-width: 100%; border-radius: 20px 20px 0 0; padding: 18px 16px calc(16px + env(safe-area-inset-bottom)); max-height: calc(100dvh - 24px); overflow-y: auto; }
  .wm-head { font-size: 17px; }
  .wm-sub { margin: 5px 0 12px; }
  .wm-list { gap: 11px; }
  .wm-actions { margin-top: 16px; }
  .wm-cancel, .wm-agree { padding: 14px 18px; min-height: 48px; font-size: 15px; }
  .wm-cancel { color: var(--text-muted, #64748b); }

  /* Dark mode: handled by the storefront's root-level smart-invert (see
     CustomerLayout's global dark block) — the sheet's light styles invert
     automatically, so no explicit dark palette here (it would double-flip). */
}
</style>
