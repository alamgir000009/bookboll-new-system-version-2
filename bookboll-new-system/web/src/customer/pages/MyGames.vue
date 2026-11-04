<script setup>
import { ref, onMounted, watch } from 'vue'
import { customer, isCustomer } from '../../lib/customerSession.js'
import { loadMyHostedGames, loadMyJoinRequests } from '../../customer/apiGames.js'
import { Gamepad2, Plus, Users, Bell, ArrowRight, CalendarDays, Ticket } from 'lucide-vue-next'
import { useSeo } from '../../lib/seo.js'
import PersonalPage from '../components/PersonalPage.vue'
const view = ref('joined')
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
  <PersonalPage title="My events" description="Keep track of the events you join and the ones you host.">
    <template #actions><router-link to="/host-game" class="personal-button primary"><Plus :size="17" />Host an event</router-link></template>
    <div v-if="!isCustomer" class="cmsg">Please <router-link to="/account">sign in</router-link> to see your events.</div>
    <div v-else-if="loading" class="cmsg">Loading…</div>
    <template v-else>
      <div class="personal-tools"><div class="personal-tabs" aria-label="Your event activity"><button :aria-pressed="view === 'joined'" @click="view = 'joined'">Participating <span>{{ joined.length }}</span></button><button :aria-pressed="view === 'hosted'" @click="view = 'hosted'">Hosting <span>{{ hosted.length }}</span></button></div><router-link to="/games" class="personal-button">Browse events</router-link></div>
      <section v-if="view === 'hosted'">
      <!-- Hosting -->
      <h2 class="personal-section-title">Events you host</h2>
      <div v-if="!hosted.length" class="personal-empty"><CalendarDays :size="36" /><h2>Bring people together</h2><p>Create an event, choose a venue and manage your guest list here.</p><router-link to="/host-game" class="personal-button primary">Host your first event</router-link></div>
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
              <span v-if="g.pendingCount" class="mg-pending"><Bell :size="13" /> {{ g.pendingCount }} pending</span>
              <span class="mg-joined"><Users :size="13" /> {{ g.acceptedCount }}/{{ g.max_players }}</span>
            </template>
            <ArrowRight v-if="!isRemoved(g)" :size="16" class="mg-arrow" />
          </div>
        </component>
      </div>

      </section><section v-else>
      <!-- Joined -->
      <h2 class="personal-section-title">Your places and join requests</h2>
      <div v-if="!joined.length" class="personal-empty"><Ticket :size="36" /><h2>Find your next event</h2><p>Join a game or meet people who share your interests. Your confirmed places and pending requests will appear here.</p><router-link to="/games" class="personal-button primary">Explore events</router-link></div>
      <div v-else class="mg-list">
        <component v-for="r in joined" :key="r.id" :is="isRemoved(r.game) ? 'div' : 'router-link'" :to="isRemoved(r.game) ? undefined : r.game ? gameLink(r.game) : '/games'" class="mg-row">
          <div class="mg-icon join"><Users :size="20" /></div>
          <div class="mg-info">
            <div class="mg-title">{{ r.game?.title || r.game?.sport || 'Event' }}</div>
            <div class="mg-meta"><CalendarDays :size="13" /> {{ dlabel(r.game?.game_date) }} · {{ r.game?.locationName }}</div>
          </div>
          <span v-if="isRemoved(r.game)" class="mg-removed">Removed by Bookboll</span><span v-else class="mg-status" :class="'s-' + (reqStatus[r.status]?.c || 'p')">{{ reqStatus[r.status]?.t || r.status }}</span>
        </component>
      </div>
      </section>
    </template>
  </PersonalPage>
</template>

<style scoped>
.cmsg { padding: 40px 24px; text-align: center; color: var(--text-muted); }.mg-list { display: grid; gap: 12px; }.mg-row { display: flex; align-items: center; gap: 16px; padding: 24px; background: var(--surface); border: 1px solid var(--border); border-radius: 12px; text-decoration: none; color: var(--text); }.mg-row:hover { border-color: var(--brand-ink, var(--brand)); }.mg-icon { width: 48px; height: 48px; flex-shrink: 0; display: grid; place-items: center; border-radius: 12px; background: var(--canvas); color: var(--brand-ink, var(--brand)); }.mg-info { flex: 1; min-width: 0; }.mg-title { font-size: 16px; font-weight: 700; overflow-wrap: anywhere; }.mg-meta { color: var(--text-muted); font-size: 13px; margin-top: 8px; display: flex; flex-wrap: wrap; align-items: center; gap: 6px; }.mg-right { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; }.mg-joined { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; color: var(--text-muted); }.mg-status, .mg-pending, .mg-removed, .mg-cancelled { display: inline-flex; align-items: center; gap: 5px; font-size: 12px; border-radius: 6px; padding: 6px 10px; background: var(--canvas); color: var(--text-muted); }.s-a { background: var(--success-bg); color: var(--success-ink); }.s-p, .mg-pending { background: var(--warning-bg); color: var(--warning-ink); }.mg-removed { color: var(--danger-ink); background: var(--danger-bg); }
@media(max-width: 600px) { .mg-row { padding: 18px 14px; gap: 12px; flex-wrap: wrap; }.mg-info { flex-basis: calc(100% - 60px); }.mg-right, .mg-status, .mg-removed { margin-inline-start: 60px; }.mg-right .mg-removed { margin: 0; } }
</style>
