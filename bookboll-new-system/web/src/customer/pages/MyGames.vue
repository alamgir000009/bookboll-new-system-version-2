<script setup>
import { ref, onMounted, watch } from 'vue'
import { customer, isCustomer } from '../../lib/customerSession.js'
import { loadMyHostedGames, loadMyJoinRequests } from '../../customer/apiGames.js'
import { Gamepad2, Plus, Users, Bell, ArrowRight, CalendarDays, Ticket } from 'lucide-vue-next'
import { useSeo } from '../../lib/seo.js'
useSeo({ title: 'My events', noindex: true, path: '/my-games' })

const hosted = ref([])
const joined = ref([])
const loading = ref(true)

async function load() {
  if (!isCustomer.value) { loading.value = false; return }
  loading.value = true
  ;[hosted.value, joined.value] = await Promise.all([
    loadMyHostedGames(customer.value.id),
    loadMyJoinRequests(customer.value.id),
  ])
  loading.value = false
}
onMounted(load)
watch(() => customer.value?.id, load)

function dlabel(d) { return d ? new Date(d + 'T00:00:00').toLocaleDateString('en-US', { month: 'short', day: 'numeric' }) : '' }
function gameLink(g) { return g && g.game_type === 'tournament' ? `/tournaments/${g.id}` : `/games/${g?.id}` }
// Admin-removed events still list here (the host should see WHY it vanished), but
// their detail page 404s by design — render them unclickable with a clear badge.
function isRemoved(g) { return (g?.moderation_status || 'active') === 'removed' }
const reqStatus = { pending: { t: 'Pending', c: 'p' }, accepted: { t: "You're in", c: 'a' }, rejected: { t: 'Not accepted', c: 'r' } }
</script>

<template>
  <div class="mg">
    <div v-if="!isCustomer" class="cmsg">Please <router-link to="/account">sign in</router-link> to see your games.</div>
    <div v-else-if="loading" class="cmsg">Loading…</div>
    <template v-else>
      <div class="mg-head">
        <h1><Ticket :size="24" /> My Events</h1>
        <div class="mg-head-actions">
          <router-link to="/host-game" class="mg-host"><Plus :size="17" /> Host an event</router-link>
        </div>
      </div>

      <!-- Hosting -->
      <h2 class="mg-h2">Games I'm hosting</h2>
      <div v-if="!hosted.length" class="mg-empty">You're not hosting any games yet.</div>
      <div v-else class="mg-list">
        <component v-for="g in hosted" :key="g.id" :is="isRemoved(g) ? 'div' : 'router-link'" :to="isRemoved(g) ? undefined : gameLink(g)" class="mg-row" :class="{ 'mg-row-removed': isRemoved(g) }">
          <div class="mg-icon host"><Gamepad2 :size="20" /></div>
          <div class="mg-info">
            <div class="mg-title">{{ g.title || g.sport }}</div>
            <div class="mg-meta"><CalendarDays :size="13" /> {{ dlabel(g.game_date) }} · {{ g.locationName }}</div>
          </div>
          <div class="mg-right">
            <span v-if="isRemoved(g)" class="mg-removed" :title="g.moderation_reason || ''">Removed by Bookboll</span>
            <span v-else-if="g.status === 'cancelled'" class="mg-cancelled">Cancelled</span>
            <template v-else>
              <span v-if="g.pendingCount" class="mg-pending"><Bell :size="13" /> {{ g.pendingCount }} new</span>
              <span class="mg-joined"><Users :size="13" /> {{ g.acceptedCount }}/{{ g.max_players }}</span>
            </template>
            <ArrowRight v-if="!isRemoved(g)" :size="16" class="mg-arrow" />
          </div>
        </component>
      </div>

      <!-- Joined -->
      <h2 class="mg-h2">Games I've requested</h2>
      <div v-if="!joined.length" class="mg-empty">You haven't requested to join any games yet.</div>
      <div v-else class="mg-list">
        <router-link v-for="r in joined" :key="r.id" :to="r.game ? gameLink(r.game) : '/games'" class="mg-row">
          <div class="mg-icon join"><Users :size="20" /></div>
          <div class="mg-info">
            <div class="mg-title">{{ r.game?.title || r.game?.sport || 'Game' }}</div>
            <div class="mg-meta"><CalendarDays :size="13" /> {{ dlabel(r.game?.game_date) }} · {{ r.game?.locationName }}</div>
          </div>
          <span class="mg-status" :class="'s-' + (reqStatus[r.status]?.c || 'p')">{{ reqStatus[r.status]?.t || r.status }}</span>
        </router-link>
      </div>
    </template>
  </div>
</template>

<style scoped>
.mg { max-width: 640px; }
.cmsg { text-align: center; padding: 50px; color: var(--text-light, #94a3b8); }
.cmsg a { color: var(--brand); font-weight: 700; }
.mg-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.mg-head h1 { font-size: 26px; font-weight: 900; color: #0f172a; margin: 0; display: flex; align-items: center; gap: 10px; }
.mg-head h1 svg { color: var(--brand); flex-shrink: 0; }
.mg-host { display: inline-flex; align-items: center; gap: 6px; background: #059669; color: #fff; font-weight: 800; padding: 10px 16px; border-radius: 999px; text-decoration: none; font-size: 14px; }
.mg-head-actions { display: flex; gap: 8px; align-items: center; }
.mg-h2 { font-size: 17px; font-weight: 800; color: #0f172a; margin: 26px 0 12px; }
.mg-empty { color: var(--text-light, #94a3b8); background: #f8fafc; border-radius: 12px; padding: 22px; text-align: center; font-size: 14px; }
.mg-list { display: flex; flex-direction: column; gap: 10px; }
.mg-row { display: flex; align-items: center; gap: 14px; background: var(--surface, #ffffff); border: 1px solid var(--border, #e2e8f0); border-radius: 14px; padding: 14px 16px; text-decoration: none; }
.mg-row:hover { border-color: #cbd5e1; }
.mg-icon { width: 44px; height: 44px; border-radius: 12px; display: flex; align-items: center; justify-content: center; color: #fff; flex-shrink: 0; }
.mg-icon.host { background: linear-gradient(135deg,#10b981,#059669); }
.mg-icon.join { background: linear-gradient(135deg,#2563eb,#1d4ed8); }
.mg-info { flex: 1; min-width: 0; }
.mg-title { font-size: 15.5px; font-weight: 800; color: #0f172a; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.mg-meta { display: flex; align-items: center; gap: 5px; font-size: 13px; color: var(--text-muted, #64748b); margin-top: 3px; }
.mg-right { display: flex; align-items: center; gap: 10px; }
.mg-pending { display: inline-flex; align-items: center; gap: 4px; font-size: 12px; font-weight: 800; color: #92400e; background: #fef3c7; padding: 4px 9px; border-radius: 999px; }
.mg-joined { display: inline-flex; align-items: center; gap: 4px; font-size: 12.5px; font-weight: 700; color: #475569; }
.mg-arrow { color: var(--text-muted, #64748b); }
.mg-row:hover .mg-arrow { color: var(--brand); }
.mg-status { font-size: 12px; font-weight: 800; padding: 5px 11px; border-radius: 999px; white-space: nowrap; }
.s-p { background: #fef3c7; color: #92400e; }
.s-a { background: #dcfce7; color: #166534; }
.s-r { background: #f1f5f9; color: var(--text-muted, #64748b); }
.mg-row-removed { opacity: .75; cursor: default; }
.mg-removed { font-size: 12px; font-weight: 800; padding: 5px 11px; border-radius: 999px; background: #fee2e2; color: #991b1b; white-space: nowrap; }
.mg-cancelled { font-size: 12px; font-weight: 800; padding: 5px 11px; border-radius: 999px; background: #f1f5f9; color: var(--text-muted, #64748b); white-space: nowrap; }

@media (max-width: 760px) {
  /* Stack header so the Host CTA is the one clear primary action, reachable immediately */
  .mg-head { flex-direction: column; align-items: stretch; gap: 10px; }
  .mg-head h1 { font-size: 20px; }
  .mg-head-actions { flex-direction: column; align-items: stretch; }
  .mg-host { width: 100%; justify-content: center; padding: 13px 16px; font-size: 15px; min-height: 48px; box-sizing: border-box; }

  /* Tighten section rhythm to cut scroll above the second list */
  .mg-h2 { margin: 18px 0 10px; font-size: 16px; }
  .mg-empty { padding: 18px; }

  /* Tighten rows and let status/meta chips wrap instead of forcing sideways scroll */
  .mg-row { padding: 12px 13px; gap: 11px; }
  .mg-right { flex-wrap: wrap; justify-content: flex-end; gap: 6px; }
  .mg-arrow { display: none; }

  /* Let the event title breathe across up to two lines rather than a hard ellipsis */
  .mg-title { white-space: normal; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }

  /* One tidy meta line per row: date stays visible, long venue names truncate */
  .mg-meta { display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .mg-meta svg { vertical-align: -2px; margin-right: 4px; }

  /* Calm chrome: mute the gradient icon tiles to tint + colored glyph so the
     Host CTA and pending badge lead; slim to 40px to shorten rows */
  .mg-icon { width: 40px; height: 40px; }
  .mg-icon.host { background: #ecfdf5; color: #059669; }
  .mg-icon.join { background: #eff6ff; color: #2563eb; }

  /* Scale the decorative h1 icon down to match the 20px mobile title */
  .mg-head h1 svg { width: 20px; height: 20px; }

  /* Less dead space on signed-out / loading states */
  .cmsg { padding: 32px 16px; }
}
</style>
