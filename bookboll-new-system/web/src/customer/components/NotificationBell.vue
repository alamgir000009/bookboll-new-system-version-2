<script setup>
import { ref, onMounted, onUnmounted, watch } from 'vue'
import { useRoute } from 'vue-router'
import { customer, isCustomer } from '../../lib/customerSession.js'
import { notifications, unreadCount, loadNotifications, markAllSeen } from '../../customer/notifications.js'
import { Bell, Trophy, Users, Check, X as XIcon, Inbox, AlertTriangle } from 'lucide-vue-next'

const route = useRoute()
defineProps({ labeled: Boolean })
const open = ref(false)
let timer = null

async function refresh() { if (isCustomer.value) await loadNotifications(customer.value.id) }

function toggle() {
  open.value = !open.value
  if (open.value) { refresh().then(() => setTimeout(markAllSeen, 1200)) }  // let the user see the highlight, then clear the badge
}
function close() { open.value = false }

onMounted(() => {
  refresh()
  timer = setInterval(refresh, 45000)   // gentle polling so hosts see new requests
  document.addEventListener('click', onDocClick, true)
})
onUnmounted(() => { clearInterval(timer); document.removeEventListener('click', onDocClick, true) })

const rootEl = ref(null)
function onDocClick(e) { if (open.value && rootEl.value && !rootEl.value.contains(e.target)) open.value = false }

watch(() => route.fullPath, refresh)          // re-check after acting on a request
watch(() => customer.value?.id, refresh)

function ago(ts) {
  if (!ts) return ''
  const s = Math.max(0, (Date.now() - new Date(ts).getTime()) / 1000)
  if (s < 60) return 'just now'
  if (s < 3600) return `${Math.floor(s / 60)}m ago`
  if (s < 86400) return `${Math.floor(s / 3600)}h ago`
  return `${Math.floor(s / 86400)}d ago`
}
</script>

<template>
  <div ref="rootEl" class="nb" :class="{ 'nb-labeled': labeled }">
    <button type="button" class="nb-btn" :class="{ on: open }" @click="toggle" title="Notifications" aria-label="Notifications" :aria-expanded="open" @keydown.esc="close">
      <Bell :size="20" />
      <span v-if="labeled" class="nb-label">Notifications</span>
      <span v-if="unreadCount" class="nb-badge">{{ unreadCount > 9 ? '9+' : unreadCount }}</span>
    </button>

    <div v-if="open" class="nb-panel">
      <div class="nb-head">
        <span>Notifications</span>
        <button v-if="notifications.length" class="nb-clear" @click="markAllSeen">Mark all read</button>
      </div>

      <div v-if="!notifications.length" class="nb-empty">
        <Inbox :size="26" />
        <span>No notifications yet</span>
        <small>Join requests and updates will show up here.</small>
      </div>

      <router-link v-for="n in notifications" :key="n.id" :to="n.link" class="nb-item" :class="{ unread: n.unread }" @click="close">
        <span class="nb-ico" :class="n.kind === 'mod' ? 'warn' : (n.kind === 'host' ? 'host' : (n.status === 'accepted' ? 'good' : 'muted'))">
          <AlertTriangle v-if="n.kind === 'mod'" :size="15" />
          <Users v-else-if="n.kind === 'host'" :size="15" />
          <Check v-else-if="n.status === 'accepted'" :size="15" />
          <XIcon v-else :size="15" />
        </span>
        <span class="nb-body">
          <span class="nb-text">{{ n.text }}</span>
          <span class="nb-time">{{ ago(n.ts) }}</span>
        </span>
        <span v-if="n.unread" class="nb-dot"></span>
      </router-link>
    </div>
  </div>
</template>

<style scoped>
.nb { position: relative; display: flex; }
.nb-btn { position: relative; display: flex; align-items: center; justify-content: center; width: 40px; height: 40px; border-radius: 50%; border: 1.5px solid var(--border, #e2e8f0); background: var(--surface, #ffffff); color: var(--text-secondary, #334155); cursor: pointer; }
.nb-btn:hover, .nb-btn.on { border-color: var(--brand-accent); color: var(--brand-ink, var(--brand)); }
.nb-badge { position: absolute; top: -3px; right: -3px; min-width: 18px; height: 18px; padding: 0 4px; box-sizing: border-box; border-radius: 999px; background: #ef4444; color: #fff; font-size: 11px; font-weight: 800; display: flex; align-items: center; justify-content: center; border: 2px solid #fff; }
.nb-panel { position: absolute; top: 50px; right: 0; width: 340px; max-width: 88vw; background: var(--surface, #ffffff); border: 1px solid var(--border, #e2e8f0); border-radius: 14px; box-shadow: 0 16px 40px rgba(0,0,0,.16); overflow: hidden; z-index: 200; }
.nb-head { display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; border-bottom: 1px solid var(--border, #eef2f6); font-size: 14px; font-weight: 800; color: var(--text, #0f172a); }
.nb-clear { background: none; border: none; color: var(--brand-ink, var(--brand)); font-weight: 700; font-size: 12.5px; cursor: pointer; }
.nb-empty { display: flex; flex-direction: column; align-items: center; gap: 6px; padding: 30px 20px; color: var(--text-light, #94a3b8); text-align: center; }
.nb-empty span { font-size: 14px; font-weight: 700; color: var(--text-muted, #64748b); }
.nb-empty small { font-size: 12.5px; }
.nb-item { display: flex; align-items: flex-start; gap: 11px; padding: 12px 16px; text-decoration: none; border-bottom: 1px solid var(--border, #f1f5f9); }
.nb-item:hover { background: var(--canvas, #f8fafc); }
.nb-item.unread { background: color-mix(in srgb, var(--brand) 8%, var(--surface, white)); }
.nb-item.unread:hover { background: color-mix(in srgb, var(--brand) 18%, var(--surface, white)); }
.nb-ico { width: 30px; height: 30px; flex-shrink: 0; border-radius: 9px; display: flex; align-items: center; justify-content: center; color: #fff; }
.nb-ico.host { background: #059669; }
.nb-ico.good { background: #16a34a; }
.nb-ico.muted { background: #94a3b8; }
.nb-ico.warn { background: #dc2626; }
.nb-body { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
.nb-text { font-size: 13.5px; color: var(--text, #0f172a); line-height: 1.4; }
.nb-time { font-size: 11.5px; color: var(--text-light, #94a3b8); }
.nb-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--brand-accent); flex-shrink: 0; margin-top: 6px; }

/* Phone: viewport-anchored, scrollable panel (360px-safe) */
@media (max-width: 767px) {
  .nb-panel { position: fixed; left: 12px; right: 12px; top: 64px; width: auto; max-width: none; max-height: min(70vh, 480px); overflow-y: auto; -webkit-overflow-scrolling: touch; }
  .nb-head { position: sticky; top: 0; background: var(--surface, #ffffff); z-index: 1; }
  .nb-text { overflow-wrap: anywhere; }
  .nb-clear { padding: 8px 6px; margin: -8px -6px; }
  .nb-item { padding: 14px 16px; }
}
.nb-labeled { display: block; }
.nb-labeled .nb-btn { width: 100%; min-height: 48px; height: auto; padding: 8px 0; border: 0; border-radius: 0; gap: 10px; background: transparent; font: inherit; font-size: 14px; text-align: start; }
.nb-labeled .nb-label { flex: 1; }
.nb-labeled .nb-badge { position: static; border: 0; min-width: 22px; height: 22px; }
.nb-labeled .nb-panel { position: static; width: 100%; max-width: none; max-height: 360px; overflow-y: auto; margin-block: 4px 12px; box-shadow: none; }
</style>
