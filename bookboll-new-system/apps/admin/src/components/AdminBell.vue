<script setup>
/**
 * AdminBell — the dashboard header's notification bell (providers + staff).
 * Feed is derived on each refresh by lib/bellFeed.js; unread = newer than the
 * per-user "last seen" stamp. Subscribes to Laravel Echo / Pusher
 * (private-refresh.{userId}) for live updates; falls back to a slow poll.
 */
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import { useRoute } from 'vue-router'
import { supabase } from '../lib/supabase.js'
import { useCurrentUser } from '../lib/currentUser.js'
import { loadBellFeed, getLastSeen, setLastSeen } from '../lib/bellFeed.js'
import { subscribeStaffRefresh, unsubscribeStaffRefresh } from '../lib/echo.js'
import { Bell, Inbox, CalendarCheck, GraduationCap, ListChecks, TriangleAlert as AlertTriangle, Banknote, UserPlus } from 'lucide-vue-next'

const route = useRoute()
const { currentUser, activeUserId, isSuperAdmin, isCountryAdmin, role, currentOwnerUserId, scopeLocations } = useCurrentUser()

const isPlatform = computed(() => isSuperAdmin.value || isCountryAdmin.value || role.value === 'platform_staff')
const uid = computed(() => activeUserId.value || currentUser.value?.id || '')

const open = ref(false)
const items = ref([])
const lastSeen = ref(0)
let timer = null
let busy = false
let refreshChannel = null
let liveRefreshTimer = null

async function refresh() {
  if (!uid.value || busy) return
  busy = true
  try {
    lastSeen.value = getLastSeen(uid.value)
    const { data } = await supabase.from('locations').select('id, name, owner_user_id, city_id')
    let locs = data || []
    locs = isPlatform.value ? scopeLocations(locs) : locs.filter(l => l.owner_user_id === currentOwnerUserId.value)
    items.value = await loadBellFeed({
      platform: isPlatform.value,
      restricted: isPlatform.value && !isSuperAdmin.value,
      ownerUserId: currentOwnerUserId.value,
      locations: locs,
    })
  } finally { busy = false }
}

/** Debounce bursty Pusher events into one feed reload. */
function scheduleLiveRefresh() {
  if (liveRefreshTimer) clearTimeout(liveRefreshTimer)
  liveRefreshTimer = setTimeout(() => { liveRefreshTimer = null; refresh() }, 400)
}

function setupPusher() {
  teardownPusher()
  if (!uid.value) return
  refreshChannel = subscribeStaffRefresh(uid.value, (payload) => {
    if (import.meta.env.DEV) {
      console.log('[Bookboll Bell] refresh event', payload)
    }
    scheduleLiveRefresh()
  })
}

function teardownPusher() {
  if (refreshChannel) {
    unsubscribeStaffRefresh(refreshChannel)
    refreshChannel = null
  }
}

const isUnread = (n) => new Date(n.ts).getTime() > lastSeen.value
const unreadCount = computed(() => items.value.filter(isUnread).length)

function markAllSeen() {
  setLastSeen(uid.value)
  lastSeen.value = Date.now()
}
function toggle() {
  open.value = !open.value
  // Let the unread highlight register, then clear the badge.
  if (open.value) refresh().then(() => setTimeout(markAllSeen, 1500))
}

const rootEl = ref(null)
function onDocClick(e) { if (open.value && rootEl.value && !rootEl.value.contains(e.target)) open.value = false }

onMounted(() => {
  refresh()
  setupPusher()
  // Fallback when Pusher is offline / misconfigured
  timer = setInterval(refresh, 120000)
  document.addEventListener('click', onDocClick, true)
})
onUnmounted(() => {
  clearInterval(timer)
  if (liveRefreshTimer) clearTimeout(liveRefreshTimer)
  teardownPusher()
  document.removeEventListener('click', onDocClick, true)
})
watch(uid, () => { refresh(); setupPusher() })
watch(() => route.fullPath, () => { if (!open.value) refresh() })

const ICONS = { booking: CalendarCheck, member: GraduationCap, approval: ListChecks, wallet: Banknote, provider: UserPlus }
function iconFor(n) { return n.tone === 'warn' ? AlertTriangle : (ICONS[n.kind] || Bell) }

function ago(ts) {
  const s = Math.max(0, (Date.now() - new Date(ts).getTime()) / 1000)
  if (s < 60) return 'just now'
  if (s < 3600) return `${Math.floor(s / 60)}m ago`
  if (s < 86400) return `${Math.floor(s / 3600)}h ago`
  return `${Math.floor(s / 86400)}d ago`
}
</script>

<template>
  <div ref="rootEl" class="ab">
    <button class="icon-btn ab-btn" :class="{ on: open }" title="Notifications" @click="toggle">
      <Bell :size="18" />
      <span v-if="unreadCount" class="ab-badge">{{ unreadCount > 9 ? '9+' : unreadCount }}</span>
    </button>

    <div v-if="open" class="ab-panel">
      <div class="ab-head">
        <span>Notifications</span>
        <button v-if="items.length" class="ab-clear" @click="markAllSeen">Mark all read</button>
      </div>

      <div v-if="!items.length" class="ab-empty">
        <Inbox :size="26" />
        <span>You're all caught up</span>
        <small>Bookings, members, approvals and payout updates show up here.</small>
      </div>

      <div v-else class="ab-list">
        <router-link v-for="n in items" :key="n.id" :to="n.to" class="ab-item" :class="{ unread: isUnread(n) }" @click="open = false">
          <span class="ab-ico" :class="n.tone"><component :is="iconFor(n)" :size="15" /></span>
          <span class="ab-body">
            <span class="ab-title">{{ n.title }}</span>
            <span v-if="n.sub" class="ab-sub">{{ n.sub }}</span>
            <span class="ab-time">{{ ago(n.ts) }}</span>
          </span>
          <span v-if="isUnread(n)" class="ab-dot"></span>
        </router-link>
      </div>
    </div>
  </div>
</template>

<style scoped>
.ab { position: relative; display: flex; }
.ab-btn { position: relative; }
.ab-badge {
  position: absolute; top: -4px; right: -4px; min-width: 17px; height: 17px; padding: 0 4px;
  box-sizing: border-box; border-radius: 999px; background: #ef4444; color: #fff;
  font-size: 10.5px; font-weight: 800; display: flex; align-items: center; justify-content: center;
  border: 2px solid var(--card, #fff);
}
.ab-panel {
  position: absolute; top: 46px; right: 0; width: 360px; max-width: 90vw;
  background: var(--card, #fff); border: 1px solid var(--border, #e2e8f0); border-radius: 14px;
  box-shadow: 0 16px 40px rgba(0,0,0,.16); overflow: hidden; z-index: 1200;
}
.ab-head {
  display: flex; align-items: center; justify-content: space-between; padding: 12px 16px;
  border-bottom: 1px solid var(--border, #eef2f6); font-size: 14px; font-weight: 800; color: var(--text, #0f172a);
}
.ab-clear { background: none; border: none; color: var(--primary, #0f766e); font-weight: 700; font-size: 12.5px; cursor: pointer; }
.ab-empty { display: flex; flex-direction: column; align-items: center; gap: 6px; padding: 30px 20px; color: var(--text-light, #94a3b8); text-align: center; }
.ab-empty span { font-size: 14px; font-weight: 700; color: var(--text-muted, #64748b); }
.ab-empty small { font-size: 12.5px; }
.ab-list { max-height: min(62vh, 460px); overflow-y: auto; -webkit-overflow-scrolling: touch; }
.ab-item { display: flex; align-items: flex-start; gap: 11px; padding: 12px 16px; text-decoration: none; border-bottom: 1px solid var(--border, #f1f5f9); }
.ab-item:last-child { border-bottom: none; }
.ab-item:hover { background: var(--bg, #f8fafc); }
.ab-item.unread { background: color-mix(in srgb, var(--primary, #0f766e) 7%, var(--card, #fff)); }
.ab-ico { width: 30px; height: 30px; flex-shrink: 0; border-radius: 9px; display: flex; align-items: center; justify-content: center; color: #fff; }
.ab-ico.ok { background: #16a34a; }
.ab-ico.info { background: #0e7490; }
.ab-ico.warn { background: #dc2626; }
.ab-body { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
.ab-title { font-size: 13.5px; font-weight: 600; color: var(--text, #0f172a); line-height: 1.35; }
.ab-sub { font-size: 12.5px; color: var(--text-muted, #64748b); line-height: 1.35; }
.ab-time { font-size: 11.5px; color: var(--text-light, #94a3b8); }
.ab-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--primary, #0f766e); flex-shrink: 0; margin-top: 6px; }

@media (max-width: 760px) {
  .ab-panel { position: fixed; left: 12px; right: 12px; top: 60px; width: auto; max-width: none; }
  .ab-head { position: sticky; top: 0; background: var(--card, #fff); z-index: 1; }
  .ab-title, .ab-sub { overflow-wrap: anywhere; }
}
</style>
