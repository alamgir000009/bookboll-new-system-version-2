<script setup>
/**
 * Unsubscribe — the public landing for the one-click unsubscribe link in every marketing email.
 * Reads ?t=<token>&ch=<email|sms|whatsapp|all>, flips the matching consent off (no sign-in needed),
 * and confirms. Idempotent — re-visiting just shows "you're unsubscribed".
 */
import { ref, onMounted, computed } from 'vue'
import { useRoute } from 'vue-router'
import { applyUnsubscribe, MARKETING_LABELS } from '../../lib/marketing.js'
import { useSeo } from '../../lib/seo.js'
import { MailX, Check, Loader2, AlertCircle } from 'lucide-vue-next'
useSeo({ title: 'Unsubscribe', noindex: true, path: '/unsubscribe' })

const route = useRoute()
const state = ref('working')   // working | done | error
const err = ref('')
const name = ref('')
const channel = ref('email')

const channelLabel = computed(() => channel.value === 'all' ? 'all marketing' : (MARKETING_LABELS[channel.value] || 'marketing') + ' marketing')

onMounted(async () => {
  const token = route.query.t || ''
  channel.value = ['email', 'sms', 'whatsapp', 'all'].includes(route.query.ch) ? route.query.ch : 'email'
  const res = await applyUnsubscribe(token, channel.value)
  if (res.error) { state.value = 'error'; err.value = res.error; return }
  name.value = (res.customer?.full_name || '').split(' ')[0] || ''
  state.value = 'done'
})
</script>

<template>
  <div class="unsub">
    <div class="unsub-card">
      <template v-if="state === 'working'">
        <Loader2 :size="34" class="unsub-spin" />
        <h1>Updating your preferences…</h1>
      </template>

      <template v-else-if="state === 'done'">
        <div class="unsub-ic ok"><Check :size="30" /></div>
        <h1>You're unsubscribed</h1>
        <p>{{ name ? name + ', you' : 'You' }} won't receive {{ channelLabel }} messages from us anymore.</p>
        <p class="unsub-fine">Changed your mind? You can re-enable any channel anytime under <strong>Marketing preferences</strong> in your account.</p>
        <router-link to="/account" class="unsub-btn">Manage preferences</router-link>
        <router-link to="/" class="unsub-link">Back to home</router-link>
      </template>

      <template v-else>
        <div class="unsub-ic err"><AlertCircle :size="30" /></div>
        <h1>We couldn't process that</h1>
        <p>{{ err }}</p>
        <router-link to="/account" class="unsub-btn">Manage preferences</router-link>
      </template>

      <div class="unsub-brand"><MailX :size="14" /> Bookboll · marketing preferences</div>
    </div>
  </div>
</template>

<style scoped>
.unsub { display: flex; justify-content: center; padding: 28px 16px; }
.unsub-card { width: 460px; max-width: 100%; background: var(--surface, #ffffff); border: 1px solid var(--border, #e2e8f0); border-radius: 20px; padding: 34px 26px 22px; text-align: center; }
.unsub-ic { width: 60px; height: 60px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 12px; }
.unsub-ic.ok { background: var(--success-bg, #f0fdf4); color: var(--success-ink, #16a34a); }
.unsub-ic.err { background: var(--danger-bg, #fef2f2); color: var(--danger-ink, #dc2626); }
.unsub h1 { font-size: 22px; font-weight: 900; color: var(--text, #0f172a); margin: 8px 0 8px; }
.unsub p { font-size: 14.5px; color: var(--text-muted, #64748b); line-height: 1.55; margin: 0 auto 8px; max-width: 360px; }
.unsub-fine { font-size: 12.5px; color: var(--text-light, #94a3b8); }
.unsub-btn { display: inline-block; margin-top: 14px; background: var(--brand, #0f766e); color: #fff; font-weight: 800; font-size: 15px; padding: 12px 22px; border-radius: 12px; text-decoration: none; }
.unsub-link { display: block; margin-top: 12px; font-size: 13.5px; font-weight: 700; color: var(--text-muted, #64748b); text-decoration: none; }
.unsub-spin { color: var(--brand, #0f766e); animation: us 1s linear infinite; }
@keyframes us { to { transform: rotate(360deg); } }
.unsub-brand { display: flex; align-items: center; justify-content: center; gap: 6px; margin-top: 22px; font-size: 11.5px; color: var(--text-light, #94a3b8); font-weight: 700; }
</style>
