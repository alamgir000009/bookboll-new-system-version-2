<script setup>
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
  return all.value
    .filter(c => !sport.value || (c.sport || '').toLowerCase() === sport.value.toLowerCase())
    .filter(c => !t || (c.name || '').toLowerCase().includes(t) || (c.description || '').toLowerCase().includes(t))
})

async function startCommunity() {
  if (!isCustomer.value && !(await ensureSignedIn())) return
  router.push('/communities/new')
}
</script>

<template>
  <div>
    <!-- Coming soon (tables not migrated yet) -->
    <div v-if="communitiesEnabled === false" class="cm-soon">
      <div class="cm-soon-ico"><UsersRound :size="48" /></div>
      <h1>Communities are coming soon!</h1>
      <p>Soon you'll be able to join clubs near you — follow their updates, chat with members and play in their meetups. Check back shortly!</p>
      <router-link to="/" class="cm-soon-btn">Back to home</router-link>
    </div>

    <template v-else>
      <div class="cm-head">
        <div class="ph"><h1><UsersRound :size="24" /> Communities{{ selectedCity?.name ? ' in ' + selectedCity.name : '' }}</h1><p>Find your people — join a club, follow its updates and play in its meetups.</p></div>
        <button type="button" class="cm-start-btn" @click="startCommunity"><Plus :size="18" /> Start a community</button>
      </div>

      <!-- My communities strip (quick re-entry) -->
      <div v-if="mine.length" class="cm-mine">
        <router-link v-for="c in mine" :key="'m' + c.id" :to="`/communities/${c.id}`" class="cm-mine-chip">
          <span class="cm-mine-av" :class="{ 'cm-has-cover': c.avatar_image || c.cover_image }" :style="(c.avatar_image || c.cover_image) ? { backgroundImage: `url(${c.avatar_image || c.cover_image})` } : {}"><UsersRound v-if="!c.avatar_image && !c.cover_image" :size="14" /></span>
          <span class="cm-mine-n">{{ c.name }}</span>
        </router-link>
      </div>

      <div class="cm-filters">
        <div class="cm-search">
          <Search :size="16" />
          <input v-model="q" type="search" placeholder="Search communities…" />
        </div>
        <div v-if="sports.length" class="cm-sports">
          <button type="button" class="cm-sport" :class="{ on: !sport }" @click="sport = ''">All</button>
          <button v-for="s in sports" :key="s" type="button" class="cm-sport" :class="{ on: sport === s }" @click="sport = s">{{ s }}</button>
        </div>
      </div>

      <div v-if="loading" class="cmsg">Loading…</div>
      <div v-else-if="!filtered.length" class="cempty">
        <UsersRound :size="40" />
        <span>{{ q || sport ? 'No communities match your search.' : 'No communities here yet.' }}</span>
        <button type="button" class="cempty-cta" @click="startCommunity"><Plus :size="16" /> Be the first — start one</button>
      </div>

      <div v-else class="cm-grid">
        <router-link v-for="c in filtered" :key="c.id" :to="`/communities/${c.id}`" class="cm-card">
          <div class="cm-card-cover" :class="{ 'cm-has-cover': c.cover_image }" :style="c.cover_image ? { backgroundImage: `url(${c.cover_image})` } : {}">
            <UsersRound v-if="!c.cover_image && !c.avatar_image" :size="30" />
            <span v-if="c.avatar_image" class="cm-card-av" :style="{ backgroundImage: `url(${c.avatar_image})` }"></span>
            <span v-if="c.is_private" class="cm-card-lock"><Lock :size="12" /> Private</span>
          </div>
          <div class="cm-card-body">
            <div class="cm-card-name">{{ c.name }}</div>
            <div class="cm-card-meta">
              <span v-if="c.sport" class="cm-card-sport">{{ c.sport }}</span>
              <span class="cm-card-count"><UsersRound :size="12" /> {{ c.memberCount }} member{{ c.memberCount === 1 ? '' : 's' }}</span>
            </div>
            <p v-if="c.description" class="cm-card-desc">{{ c.description }}</p>
            <span v-if="myIds.has(c.id)" class="cm-card-joined">Joined</span>
          </div>
        </router-link>
      </div>
    </template>
  </div>
</template>

<style scoped>
.cm-soon { text-align: center; max-width: 460px; margin: 40px auto; background: linear-gradient(135deg,#ecfeff,#cffafe); border: 1.5px solid #a5f3fc; border-radius: 22px; padding: 44px 28px; }
.cm-soon-ico { color: #0e7490; margin-bottom: 8px; }
.cm-soon h1 { font-size: 26px; font-weight: 900; color: #164e63; margin: 12px 0 8px; }
.cm-soon p { font-size: 15px; color: #0e7490; line-height: 1.6; margin: 0 0 22px; }
.cm-soon-btn { display: inline-block; background: #0e7490; color: #fff; font-weight: 800; padding: 13px 24px; border-radius: 999px; text-decoration: none; }

.cm-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
.ph h1 { font-size: 25px; font-weight: 900; color: #0f172a; margin: 0; display: flex; align-items: center; gap: 10px; }
.ph h1 svg { color: var(--brand); flex-shrink: 0; }
.ph p { font-size: 14.5px; color: var(--text-muted, #64748b); margin: 6px 0 0; }
.cm-start-btn { display: inline-flex; align-items: center; gap: 7px; background: #059669; color: #fff; font-weight: 800; padding: 12px 18px; border: none; border-radius: 999px; cursor: pointer; font-size: 14.5px; white-space: nowrap; }
.cm-start-btn:hover { background: #047857; }

.cm-mine { display: flex; gap: 8px; overflow-x: auto; margin: 16px 0 0; padding-bottom: 4px; scrollbar-width: none; }
.cm-mine::-webkit-scrollbar { display: none; }
.cm-mine-chip { flex: 0 0 auto; display: inline-flex; align-items: center; gap: 7px; background: var(--surface, #ffffff); border: 1.5px solid var(--border, #e2e8f0); border-radius: 999px; padding: 5px 12px 5px 5px; text-decoration: none; }
.cm-mine-av { width: 26px; height: 26px; border-radius: 50%; background: #f1f5f9 center/cover; color: var(--text-muted, #64748b); display: flex; align-items: center; justify-content: center; }
.cm-mine-n { font-size: 13px; font-weight: 700; color: #0f172a; white-space: nowrap; max-width: 140px; overflow: hidden; text-overflow: ellipsis; }

.cm-filters { margin: 16px 0; display: flex; flex-direction: column; gap: 10px; }
.cm-search { display: flex; align-items: center; gap: 8px; border: 1.5px solid var(--border, #e2e8f0); background: var(--surface, #ffffff); border-radius: 12px; padding: 0 12px; color: var(--text-light, #94a3b8); max-width: 420px; }
.cm-search input { flex: 1; border: none; outline: none; background: none; font: inherit; font-size: 15px; padding: 11px 0; color: #0f172a; }
.cm-sports { display: flex; gap: 8px; overflow-x: auto; scrollbar-width: none; }
.cm-sports::-webkit-scrollbar { display: none; }
.cm-sport { flex: 0 0 auto; border: 1.5px solid var(--border, #e2e8f0); background: var(--surface, #ffffff); color: #475569; font-weight: 700; font-size: 13.5px; padding: 8px 15px; border-radius: 999px; cursor: pointer; text-transform: capitalize; }
.cm-sport.on { background: #0f172a; color: #fff; border-color: #0f172a; }

.cm-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(310px, 100%), 1fr)); gap: 16px; }
.cm-card { display: flex; flex-direction: column; background: var(--surface, #ffffff); border: 1px solid var(--border, #e2e8f0); border-radius: 16px; overflow: hidden; text-decoration: none; transition: .12s; }
.cm-card:hover { transform: translateY(-2px); box-shadow: 0 10px 26px rgba(15,23,42,.09); }
.cm-card-cover { position: relative; height: 110px; background: linear-gradient(135deg,#f0fdfa,#ccfbf1) center/cover; color: #0d9488; display: flex; align-items: center; justify-content: center; }
.cm-card-lock { position: absolute; top: 8px; right: 8px; display: inline-flex; align-items: center; gap: 4px; background: rgba(15,23,42,.72); color: #fff; font-size: 11px; font-weight: 800; padding: 4px 9px; border-radius: 999px; }
/* Profile photo overlaps the cover's bottom edge, ID-card style */
.cm-card-av { position: absolute; bottom: -18px; left: 14px; width: 52px; height: 52px; border-radius: 50%; background-size: cover; background-position: center; border: 2.5px solid var(--surface, #ffffff); box-shadow: 0 3px 10px rgba(15,23,42,.16); }
.cm-card-cover:has(.cm-card-av) + .cm-card-body { padding-top: 26px; }
.cm-card-body { position: relative; padding: 13px 15px 15px; }
.cm-card-name { font-size: 16.5px; font-weight: 800; color: #0f172a; }
.cm-card-meta { display: flex; align-items: center; gap: 10px; margin-top: 5px; }
.cm-card-sport { font-size: 12px; font-weight: 800; color: #0d9488; background: none; border: 1.5px solid #5eead4; padding: 2px 9px; border-radius: 999px; text-transform: capitalize; }
.cm-card-count { display: inline-flex; align-items: center; gap: 4px; font-size: 12.5px; font-weight: 600; color: var(--text-muted, #64748b); }
.cm-card-desc { font-size: 13px; color: var(--text-muted, #64748b); line-height: 1.45; margin: 8px 0 0; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.cm-card-joined { position: absolute; top: -12px; right: 12px; background: #dcfce7; color: #166534; font-size: 11px; font-weight: 800; padding: 3px 10px; border-radius: 999px; border: 1px solid #86efac; }

.cmsg { text-align: center; padding: 50px; color: var(--text-light, #94a3b8); }
.cempty { display: flex; flex-direction: column; align-items: center; gap: 14px; padding: 60px; color: var(--text-light, #94a3b8); }
.cempty-cta { display: inline-flex; align-items: center; gap: 7px; background: #059669; color: #fff; font-weight: 800; padding: 11px 18px; border: none; border-radius: 999px; cursor: pointer; font-size: 14px; }

@media (max-width: 760px) {
  .cm-head { flex-direction: column; align-items: stretch; gap: 12px; }
  .ph h1 { font-size: 20px; }
  .ph p { display: none; }
  .cm-start-btn { width: 100%; justify-content: center; background: transparent; color: var(--brand); border: 1.5px solid var(--brand); }
  .cmsg, .cempty { padding: 32px 16px; }
  .cm-soon { margin: 24px auto; padding: 28px 20px; }
  .cm-soon h1 { font-size: 22px; }
}
</style>
