<script setup>
import CommunityCard from '../components/CommunityCard.vue'
import { popularCommunities } from '../../lib/communityPresentation.js'
import { ref, computed, onMounted, watch } from 'vue'
import { selectedCity, isCustomer } from '../../lib/customerSession.js'
import { ensureSignedIn } from '../../lib/authGate.js'
import { useRouter } from 'vue-router'
import { loadCommunities, probeCommunities, communitiesEnabled, myCommunities } from '../../customer/community.js'
import { customer } from '../../lib/customerSession.js'
import { UsersRound, Plus, Search, Lock, MapPin } from 'lucide-vue-next'
import { useSeo } from '../../lib/seo.js'

const router = useRouter()
const all = ref([])
const mine = ref([])
const loading = ref(true)
const q = ref('')
const sport = ref('')

useSeo(() => ({
  title: selectedCity.value?.name ? `Communities in ${selectedCity.value.name}` : 'Sports communities',
  description: `Join sports communities${selectedCity.value?.name ? ' in ' + selectedCity.value.name : ''} — find your people, follow club updates and play in their meetups, or start your own club on Bookboll.`,
  path: '/communities',
}))

async function load() {
  loading.value = true
  await probeCommunities()
  if (communitiesEnabled.value !== false) {
    const [list, my] = await Promise.all([
      loadCommunities({ cityId: selectedCity.value?.id }),
      isCustomer.value ? myCommunities(customer.value.id) : Promise.resolve([]),
    ])
    all.value = list
    mine.value = my
  }
  loading.value = false
}
onMounted(load)
watch(() => selectedCity.value?.id, load)
watch(() => customer.value?.id, load)

const sports = computed(() => [...new Set(all.value.map(c => c.sport).filter(Boolean))].sort())
const myIds = computed(() => new Set(mine.value.map(c => c.id)))
const filtered = computed(() => {
  const t = q.value.trim().toLowerCase()
  return popularCommunities(all.value)
    .filter(c => !sport.value || (c.sport || '').toLowerCase() === sport.value.toLowerCase())
    .filter(c => !t || (c.name || '').toLowerCase().includes(t) || (c.description || '').toLowerCase().includes(t))
})

async function startCommunity() {
  if (!isCustomer.value && !(await ensureSignedIn())) return
  router.push('/communities/new')
}
</script>

<template>
  <div class="discovery">
    <header class="discover-head"><div><h1><UsersRound :size="28" /> Communities{{ selectedCity?.name ? ' in ' + selectedCity.name : '' }}</h1><p>Find a regular crew. Share your interests, join the conversation and make plans to play.</p></div><button class="discover-action" @click="startCommunity"><Plus :size="18" /> Start a community</button></header>
    <section v-if="mine.length" class="cm-your" aria-label="Your communities"><h2>Your communities</h2><div class="cm-mine"><router-link v-for="c in mine" :key="c.id" :to="'/communities/' + c.id"><img v-if="c.avatar_image" :src="c.avatar_image" alt="" /><UsersRound v-else :size="20" /><span>{{ c.name }}</span></router-link></div></section>
    <div class="discover-tools"><label class="discover-search"><Search :size="21" /><input v-model="q" type="search" aria-label="Search communities" placeholder="Search by community name or interest" /></label><div class="discover-filters" aria-label="Filter by sport"><button class="discover-filter" :class="{on:!sport}" :aria-pressed="!sport" @click="sport=''">All communities</button><button v-for="s in sports" :key="s" class="discover-filter" :class="{on:sport===s}" :aria-pressed="sport===s" @click="sport=s">{{ s }}</button></div></div>
    <div v-if="loading" class="discover-loading" role="status">Finding communities…</div>
    <div v-else-if="communitiesEnabled === false" class="discover-empty"><UsersRound :size="36" /><h2>Communities are unavailable right now</h2><p>Try again to discover groups near you.</p><button class="discover-action" @click="load">Try again</button></div>
    <template v-else><div class="discover-result-head"><h2>{{ q || sport ? filtered.length + ' matching communities' : 'Popular communities' }}</h2><p>Most members first</p></div>
      <div v-if="filtered.length" class="discover-grid"><CommunityCard v-for="c in filtered" :key="c.id" :community="c" :joined="myIds.has(c.id)" /></div>
      <div v-else class="discover-empty"><UsersRound :size="36" /><h2>{{ q || sport ? 'No communities match yet' : 'Your next crew starts here' }}</h2><p>{{ q || sport ? 'Try another name or explore all interests.' : 'Start a community for your sport or interest and invite people to join you.' }}</p><button v-if="q || sport" class="discover-action" @click="q='';sport=''">Clear search &amp; filters</button><button v-else class="discover-action" @click="startCommunity">Start a community</button></div>
    </template>
  </div>
</template>
<style scoped>
.cm-your { margin: 0 0 24px; }.cm-your h2 { font-size: 13px; color: var(--text-muted); margin: 0 0 10px; font-weight: 600; }.cm-mine { display: flex; overflow-x: auto; gap: 10px; padding-bottom: 4px; }.cm-mine a { display: flex; align-items: center; gap: 9px; padding: 8px 14px 8px 8px; border: 1px solid var(--border); border-radius: 10px; text-decoration: none; color: var(--text); font-size: 13px; font-weight: 650; flex-shrink: 0; background: var(--surface); }.cm-mine img { width: 30px; height: 30px; border-radius: 8px; object-fit: cover; }.cm-mine span { max-width: 230px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
</style>
