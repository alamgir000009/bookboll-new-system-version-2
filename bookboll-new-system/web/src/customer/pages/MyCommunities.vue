<script setup>
import { ref, onMounted, watch } from 'vue'
import { customer, isCustomer } from '../../lib/customerSession.js'
import { ensureSignedIn } from '../../lib/authGate.js'
import { myCommunities } from '../../customer/community.js'
import { UsersRound, Plus, Lock, Shield, ArrowRight } from 'lucide-vue-next'
import { useSeo } from '../../lib/seo.js'

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
  <div class="mc">
    <div class="mc-head">
      <h1><UsersRound :size="24" /> My communities</h1>
      <router-link to="/communities/new" class="mc-new"><Plus :size="16" /> Start one</router-link>
    </div>

    <div v-if="!isCustomer" class="cempty">
      <UsersRound :size="40" />
      <span>Sign in to see your communities.</span>
      <button type="button" class="mc-cta" @click="ensureSignedIn()">Sign in</button>
    </div>
    <div v-else-if="loading" class="cmsg">Loading…</div>
    <div v-else-if="!list.length" class="cempty">
      <UsersRound :size="40" />
      <span>You haven't joined any communities yet.</span>
      <router-link to="/communities" class="mc-cta">Browse communities</router-link>
    </div>

    <div v-else class="mc-list">
      <router-link v-for="c in list" :key="c.id" :to="`/communities/${c.id}`" class="mc-row">
        <span class="mc-av" :style="(c.avatar_image || c.cover_image) ? { backgroundImage: `url(${c.avatar_image || c.cover_image})` } : {}"><UsersRound v-if="!c.avatar_image && !c.cover_image" :size="18" /></span>
        <span class="mc-body">
          <span class="mc-name">{{ c.name }} <Lock v-if="c.is_private" :size="12" /></span>
          <span class="mc-meta">
            <span v-if="c.membership?.role !== 'member'" class="mc-role" :class="c.membership?.role"><Shield :size="10" /> {{ c.membership?.role }}</span>
            <template v-if="c.membership?.status === 'pending'">Waiting for approval</template>
            <template v-else>{{ c.memberCount }} member{{ c.memberCount === 1 ? '' : 's' }}</template>
          </span>
        </span>
        <ArrowRight :size="17" class="mc-go" />
      </router-link>
    </div>
  </div>
</template>

<style scoped>
.mc { max-width: 640px; margin: 0 auto; }
.mc-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 16px; }
.mc-head h1 { font-size: 24px; font-weight: 900; color: #0f172a; margin: 0; display: flex; align-items: center; gap: 10px; }
.mc-head h1 svg { color: var(--brand); }
.mc-new { display: inline-flex; align-items: center; gap: 6px; background: #059669; color: #fff; font-weight: 800; font-size: 13.5px; padding: 10px 16px; border-radius: 999px; text-decoration: none; white-space: nowrap; }
.cmsg { text-align: center; padding: 50px; color: var(--text-light, #94a3b8); }
.cempty { display: flex; flex-direction: column; align-items: center; gap: 14px; padding: 56px 16px; color: var(--text-light, #94a3b8); }
.mc-cta { display: inline-flex; align-items: center; gap: 7px; background: #059669; color: #fff; font-weight: 800; padding: 11px 20px; border: none; border-radius: 999px; cursor: pointer; text-decoration: none; font-size: 14px; }
.mc-list { display: flex; flex-direction: column; gap: 10px; }
.mc-row { display: flex; align-items: center; gap: 12px; background: var(--surface, #ffffff); border: 1px solid var(--border, #e2e8f0); border-radius: 14px; padding: 12px 14px; text-decoration: none; }
.mc-row:hover { border-color: #99f6e4; }
.mc-av { width: 44px; height: 44px; border-radius: 12px; background: #f0fdfa center/cover; color: #0d9488; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.mc-body { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 3px; }
.mc-name { display: inline-flex; align-items: center; gap: 6px; font-size: 15px; font-weight: 800; color: #0f172a; }
.mc-meta { display: inline-flex; align-items: center; gap: 8px; font-size: 12.5px; color: var(--text-muted, #64748b); }
.mc-role { display: inline-flex; align-items: center; gap: 3px; font-size: 10.5px; font-weight: 800; text-transform: capitalize; padding: 2px 8px; border-radius: 999px; background: #ede9fe; color: #6d28d9; }
.mc-role.owner { background: #ffe4e6; color: #be123c; }
.mc-go { color: var(--text-light, #94a3b8); flex-shrink: 0; }
@media (max-width: 560px) { .mc-head h1 { font-size: 20px; } }
</style>
