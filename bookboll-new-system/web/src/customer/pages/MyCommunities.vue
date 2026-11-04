<script setup>
import { ref, onMounted, watch, computed } from 'vue'
import { customer, isCustomer } from '../../lib/customerSession.js'
import { ensureSignedIn } from '../../lib/authGate.js'
import { myCommunities } from '../../customer/community.js'
import { UsersRound, Plus, Lock, Shield, ArrowRight, Search } from 'lucide-vue-next'
import { useSeo } from '../../lib/seo.js'

import PersonalPage from '../components/PersonalPage.vue'
const search = ref('')
const filtered = computed(() => list.value.filter(c => c.name?.toLocaleLowerCase().includes(search.value.trim().toLocaleLowerCase())))
useSeo({ title: 'My communities', path: '/my-communities', noindex: true })

const list = ref([])
const loading = ref(true)

async function load() {
  loading.value = true
  list.value = isCustomer.value ? await myCommunities(customer.value.id) : []
  loading.value = false
}
onMounted(load)
watch(() => customer.value?.id, load)
</script>

<template>
  <PersonalPage title="My communities" description="Your groups, shared interests and conversations, together in one place.">
    <template #actions><router-link to="/communities/new" class="personal-button primary"><Plus :size="16" />Start a community</router-link></template>
    <div v-if="!isCustomer" class="personal-empty">
      <UsersRound :size="40" />
      <span>Sign in to see your communities.</span>
      <button type="button" class="personal-button primary" @click="ensureSignedIn()">Sign in</button>
    </div>
    <div v-else-if="loading" class="cmsg">Loading…</div>
    <div v-else-if="!list.length" class="personal-empty">
      <UsersRound :size="40" />
      <h2>Find your people</h2><p>Join a community to share updates and make plans for your next game.</p>
      <router-link to="/communities" class="personal-button primary">Browse communities</router-link>
    </div>

    <template v-else>
    <div class="personal-tools"><span class="personal-muted">{{ list.length }} communit{{ list.length === 1 ? 'y' : 'ies' }}</span><label class="personal-search"><Search :size="18" /><input v-model="search" aria-label="Search your communities" placeholder="Search your communities" /></label></div>
    <div v-if="!filtered.length" class="personal-empty"><h2>No matching communities</h2><p>Try another name.</p><button class="personal-button" @click="search = ''">Clear search</button></div>
    <div v-else class="mc-list">
      <router-link v-for="c in filtered" :key="c.id" :to="`/communities/${c.id}`" class="mc-row">
        <span class="mc-av" :style="(c.avatar_image || c.cover_image) ? { backgroundImage: `url(${c.avatar_image || c.cover_image})` } : {}"><UsersRound v-if="!c.avatar_image && !c.cover_image" :size="18" /></span>
        <span class="mc-body">
          <span class="mc-name">{{ c.name }} <Lock v-if="c.is_private" :size="12" /></span>
          <span class="mc-meta">
            <span v-if="c.membership?.role && c.membership.role !== 'member'" class="mc-role" :class="c.membership?.role"><Shield :size="10" /> {{ c.membership?.role }}</span>
            <template v-if="c.membership?.status === 'pending'">Waiting for approval</template>
            <template v-else>{{ c.memberCount }} member{{ c.memberCount === 1 ? '' : 's' }} · {{ c.is_private ? 'Private community' : 'Public community' }}</template>
          </span>
        </span>
        <span class="mc-open">Open community <ArrowRight :size="17" /></span>
      </router-link>
    </div>
    </template>
    <router-link v-if="isCustomer && list.length" to="/communities" class="mc-discover">Discover more communities <ArrowRight :size="16" /></router-link>
  </PersonalPage>
</template>

<style scoped>
.cmsg { padding: 40px; color: var(--text-muted); text-align: center; }.mc-list { border: 1px solid var(--border); border-radius: 14px; background: var(--surface); overflow: hidden; }.mc-row { display: flex; align-items: center; gap: 18px; padding: 24px; text-decoration: none; color: var(--text); }.mc-row + .mc-row { border-top: 1px solid var(--border); }.mc-row:hover { background: var(--canvas); }.mc-av { width: 64px; height: 64px; border-radius: 14px; background: var(--canvas) center/cover; display: grid; place-items: center; color: var(--brand-ink, var(--brand)); flex-shrink: 0; }.mc-body { flex: 1; min-width: 0; }.mc-name { display: flex; align-items: center; gap: 6px; font-size: 17px; font-weight: 700; line-height: 1.4; }.mc-meta { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; font-size: 13px; color: var(--text-muted); margin-top: 8px; }.mc-role { display: inline-flex; align-items: center; gap: 4px; text-transform: capitalize; color: var(--brand-ink, var(--brand)); }.mc-open, .mc-discover { display: flex; align-items: center; gap: 8px; color: var(--brand-ink, var(--brand)); font-size: 13px; font-weight: 600; }.mc-discover { margin-top: 16px; min-height: 44px; text-decoration: none; }
@media(max-width:760px) { .mc-row { padding: 18px 14px; gap: 12px; flex-wrap: wrap; }.mc-av { width: 48px; height: 48px; }.mc-body { flex-basis: calc(100% - 60px); }.mc-open { margin-inline-start: 60px; }.mc-name { font-size: 16px; } }
</style>
