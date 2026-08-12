<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { customer, isCustomer } from '../../lib/customerSession.js'
import { ensureSignedIn } from '../../lib/authGate.js'
import { askConfirm } from '../../lib/confirm.js'
import { pushToast } from '../../lib/toast.js'
import { uploadImage, imageUploadConfigured } from '../../lib/imageUpload.js'
import {
  loadCommunity, joinCommunity, leaveCommunity, loadMembers, respondToMember, removeMember, setMemberRole,
  loadCommunityFeed, createCommunityPost, deleteCommunityPost, canManage, isMember,
  groupKey, communityChatUnread, updateCommunity,
} from '../../customer/community.js'
import { loadCommunityEvents } from '../../customer/data.js'
import GameCard from '../components/GameCard.vue'
import { useSeo } from '../../lib/seo.js'
import { ArrowLeft, UsersRound, Lock, MapPin, Plus, MessageCircle, Send, Newspaper, CalendarDays, Info, Check, X, Shield, Trash2, ImagePlus, Loader, LogOut, Pencil } from 'lucide-vue-next'

const route = useRoute()
const router = useRouter()
const c = ref(null)              // community (+ .membership for the viewer)
const members = ref([])
const feed = ref([])
const events = ref([])
const loading = ref(true)
const tab = ref('feed')          // feed | events | members | about
const busy = ref(false)

const me = computed(() => customer.value?.id || null)
const membership = computed(() => c.value?.membership || null)
// The community's owner_customer_id is the source of truth for ownership — the
// owner keeps full control even if their owner-membership row went missing.
const isOwner = computed(() => membership.value?.role === 'owner' || (!!me.value && c.value?.owner_customer_id === me.value))
const amMember = computed(() => isMember(membership.value) || isOwner.value)
const amManager = computed(() => canManage(membership.value) || isOwner.value)
const pendingMembers = computed(() => members.value.filter(m => m.status === 'pending'))
const acceptedMembers = computed(() => members.value.filter(m => m.status === 'accepted'))
// Private community + not a member → feed/members/chat are hidden (About stays).
const contentLocked = computed(() => !!c.value?.is_private && !amMember.value)

useSeo(() => ({
  title: c.value ? c.value.name : 'Community',
  description: c.value?.description || 'A sports community on Bookboll.',
  image: c.value?.cover_image || '',
  path: route.path,
}))

async function load() {
  loading.value = true
  c.value = await loadCommunity(route.params.id, me.value)
  if (c.value) {
    const jobs = [loadCommunityEvents(c.value.id).then(r => { events.value = r })]
    if (!contentLocked.value) {
      jobs.push(loadCommunityFeed(c.value.id).then(r => { feed.value = r }))
      jobs.push(loadMembers(c.value.id).then(r => { members.value = r }))
    }
    await Promise.all(jobs)
    if (contentLocked.value) tab.value = 'about'
    refreshChatBadge()
  }
  loading.value = false
}
onMounted(load)
watch(() => route.params.id, load)
watch(() => customer.value?.id, load)

// ── Join / leave ────────────────────────────────────────────────────────────────
async function join() {
  if (!isCustomer.value && !(await ensureSignedIn())) return
  busy.value = true
  const res = await joinCommunity(c.value, customer.value.id)
  busy.value = false
  if (res.error) { pushToast(res.error, 'error'); return }
  pushToast(c.value.is_private ? 'Request sent — the organizers will approve you shortly.' : `Welcome to ${c.value.name}!`)
  await load()
}
async function leave() {
  const ok = await askConfirm({
    title: `Leave ${c.value.name}?`,
    message: 'You can rejoin any time' + (c.value.is_private ? ' — but a private community must approve you again.' : '.'),
    confirmText: 'Leave', danger: true,
  })
  if (!ok) return
  const res = await leaveCommunity(c.value.id, me.value)
  if (res.error) { pushToast(res.error, 'error'); return }
  await load()
}

// ── Feed composer ──────────────────────────────────────────────────────────────
const postBody = ref('')
const postPhotos = ref([])
const postFile = ref(null)
const postUploading = ref(false)
const posting = ref(false)
async function onPostFiles(e) {
  const files = Array.from(e.target.files || [])
  if (!files.length) return
  postUploading.value = true
  for (const f of files) {
    const r = await uploadImage(f)
    if (r.url) postPhotos.value.push(r.url)
    else if (r.error) pushToast(r.error, 'error')
  }
  postUploading.value = false
  if (postFile.value) postFile.value.value = ''
}
async function submitPost() {
  if (posting.value) return
  posting.value = true
  const res = await createCommunityPost(c.value.id, me.value, { body: postBody.value, photos: postPhotos.value })
  posting.value = false
  if (res.error) { pushToast(res.error, 'error'); return }
  postBody.value = ''; postPhotos.value = []
  feed.value = await loadCommunityFeed(c.value.id)
}
async function removePost(p) {
  const ok = await askConfirm({ title: 'Delete this post?', confirmText: 'Delete', danger: true })
  if (!ok) return
  await deleteCommunityPost(p.id)
  feed.value = feed.value.filter(x => x.id !== p.id)
}

// ── Members management ─────────────────────────────────────────────────────────
async function decideMember(m, accept) {
  await respondToMember(m.id, accept)
  members.value = await loadMembers(c.value.id)
  if (accept) pushToast(`${m.name} is in!`)
}
async function kickMember(m) {
  const ok = await askConfirm({ title: `Remove ${m.name}?`, message: 'They can request to join again later.', confirmText: 'Remove', danger: true })
  if (!ok) return
  await removeMember(m.id)
  members.value = await loadMembers(c.value.id)
}
async function toggleAdmin(m) {
  const to = m.role === 'admin' ? 'member' : 'admin'
  await setMemberRole(m.id, to)
  members.value = await loadMembers(c.value.id)
  pushToast(to === 'admin' ? `${m.name} is now an admin.` : `${m.name} is a member again.`)
}

// ── Group chat — lives in the Messages inbox (/messages/community__<id>) ──────
const chatUnread = ref(0)
async function refreshChatBadge() {
  if (!amMember.value) return
  chatUnread.value = await communityChatUnread(c.value.id, membership.value)
}
function openChat() {
  if (!amMember.value) return
  router.push(`/messages/${groupKey('community', c.value.id)}`)
}

// ── Owner: inline cover/description editing ────────────────────────────────────
const editOpen = ref(false)
const edit = ref({ name: '', description: '', sport: '', is_private: false })
const coverFile = ref(null)
const coverUploading = ref(false)
function openEdit() {
  edit.value = { name: c.value.name, description: c.value.description || '', sport: c.value.sport || '', is_private: !!c.value.is_private }
  editOpen.value = true
}
async function onCoverPick(e) {
  const f = (e.target.files || [])[0]
  if (!f) return
  coverUploading.value = true
  const r = await uploadImage(f)
  coverUploading.value = false
  if (r.error) { pushToast(r.error, 'error'); return }
  await updateCommunity(c.value.id, { cover_image: r.url })
  c.value.cover_image = r.url
  if (coverFile.value) coverFile.value.value = ''
}
// Profile photo (avatar) — the club's face next to its name everywhere.
const avatarFile = ref(null)
const avatarUploading = ref(false)
async function onAvatarPick(e) {
  const f = (e.target.files || [])[0]
  if (!f) return
  avatarUploading.value = true
  const r = await uploadImage(f)
  avatarUploading.value = false
  if (r.error) { pushToast(r.error, 'error'); return }
  const res = await updateCommunity(c.value.id, { avatar_image: r.url })
  if (res.error) { pushToast(/avatar_image|column|schema/i.test(res.error) ? 'Run the community avatar migration first.' : res.error, 'error'); return }
  c.value.avatar_image = r.url
  if (avatarFile.value) avatarFile.value.value = ''
}
async function saveEdit() {
  const res = await updateCommunity(c.value.id, { ...edit.value, name: edit.value.name.trim() || c.value.name })
  if (res.error) { pushToast(res.error, 'error'); return }
  editOpen.value = false
  await load()
}

function ago(ts) {
  const s = Math.max(0, (Date.now() - new Date(ts).getTime()) / 1000)
  if (s < 60) return 'now'
  if (s < 3600) return Math.floor(s / 60) + 'm'
  if (s < 86400) return Math.floor(s / 3600) + 'h'
  return Math.floor(s / 86400) + 'd'
}
const TABS = computed(() => [
  { key: 'feed', label: 'Feed', icon: Newspaper },
  { key: 'events', label: 'Meetups', icon: CalendarDays },
  { key: 'members', label: 'Members', icon: UsersRound },
  { key: 'about', label: 'About', icon: Info },
])
</script>

<template>
  <div>
    <router-link to="/communities" class="cback"><ArrowLeft :size="16" /> Communities</router-link>

    <div v-if="loading" class="cmsg">Loading…</div>
    <div v-else-if="!c" class="cmsg">Community not found.</div>

    <template v-else>
      <!-- HERO -->
      <div class="cd-hero" :class="{ 'cd-has-cover': c.cover_image }" :style="c.cover_image ? { backgroundImage: `linear-gradient(180deg, rgba(15,23,42,.25), rgba(15,23,42,.65)), url(${c.cover_image})` } : {}">
        <div class="cd-hero-top">
          <span v-if="c.is_private" class="cd-lock"><Lock :size="12" /> Private</span>
          <span v-if="amManager && imageUploadConfigured()" class="cd-herobtns">
            <label class="cd-coverbtn">
              <Loader v-if="avatarUploading" :size="13" class="cd-spin" /><ImagePlus v-else :size="13" /> Photo
              <input ref="avatarFile" type="file" accept="image/*" class="cd-file" @change="onAvatarPick" />
            </label>
            <label class="cd-coverbtn">
              <Loader v-if="coverUploading" :size="13" class="cd-spin" /><ImagePlus v-else :size="13" /> Cover
              <input ref="coverFile" type="file" accept="image/*" class="cd-file" @change="onCoverPick" />
            </label>
          </span>
        </div>
        <div class="cd-idrow">
          <span v-if="c.avatar_image" class="cd-avatar" :style="{ backgroundImage: `url(${c.avatar_image})` }"></span>
          <h1>{{ c.name }}</h1>
        </div>
        <div class="cd-hero-meta">
          <span v-if="c.sport" class="cd-sport">{{ c.sport }}</span>
          <span class="cd-count"><UsersRound :size="13" /> {{ c.memberCount }} member{{ c.memberCount === 1 ? '' : 's' }}</span>
        </div>
      </div>

      <!-- JOIN / STATUS bar -->
      <div class="cd-joinbar">
        <template v-if="!amMember">
          <button v-if="membership?.status === 'pending'" class="cd-join pending" disabled><Check :size="15" /> Request sent</button>
          <button v-else class="cd-join" :disabled="busy" @click="join"><Plus :size="16" /> {{ c.is_private ? 'Request to join' : 'Join community' }}</button>
        </template>
        <template v-else>
          <span class="cd-memberchip"><Check :size="14" /> {{ isOwner ? 'Owner' : membership.role === 'admin' ? 'Admin' : 'Member' }}</span>
          <button type="button" class="cd-chatbtn" @click="openChat"><MessageCircle :size="15" /> Chat<span v-if="chatUnread" class="cd-chatbtn-badge">{{ chatUnread > 9 ? '9+' : chatUnread }}</span></button>
          <button v-if="amManager" type="button" class="cd-editbtn" @click="openEdit"><Pencil :size="14" /> Edit</button>
          <button v-if="!isOwner" type="button" class="cd-leave" @click="leave"><LogOut :size="14" /> Leave</button>
        </template>
      </div>

      <!-- TABS -->
      <div class="cd-tabs" role="tablist">
        <button v-for="t in TABS" :key="t.key" type="button" class="cd-tab" :class="{ on: tab === t.key }"
          :aria-pressed="tab === t.key" @click="tab = t.key">
          <component :is="t.icon" :size="16" /><span>{{ t.label }}</span>
        </button>
      </div>

      <!-- Private + not a member → everything except About is behind the door -->
      <div v-if="contentLocked && tab !== 'about'" class="cd-locked">
        <Lock :size="30" />
        <p>This community is private — join to see the feed, meetups and members.</p>
      </div>

      <!-- FEED -->
      <div v-else-if="tab === 'feed'" class="cd-feed">
        <div v-if="amMember" class="cd-composer">
          <textarea v-model="postBody" rows="2" placeholder="Share an update with the community…"></textarea>
          <div v-if="postPhotos.length" class="cd-composer-pics">
            <div v-for="(p, i) in postPhotos" :key="p" class="cd-composer-pic" :style="{ backgroundImage: `url(${p})` }">
              <button type="button" @click="postPhotos.splice(i, 1)"><X :size="12" /></button>
            </div>
          </div>
          <div class="cd-composer-bar">
            <label v-if="imageUploadConfigured()" class="cd-composer-img">
              <Loader v-if="postUploading" :size="15" class="cd-spin" /><ImagePlus v-else :size="15" />
              <input ref="postFile" type="file" accept="image/*" multiple class="cd-file" @change="onPostFiles" />
            </label>
            <button type="button" class="cd-composer-send" :disabled="posting || (!postBody.trim() && !postPhotos.length)" @click="submitPost">
              <Send :size="14" /> Post
            </button>
          </div>
        </div>

        <div v-if="!feed.length" class="cempty"><Newspaper :size="36" /><span>No posts yet{{ amMember ? ' — start the conversation!' : '.' }}</span></div>
        <article v-for="p in feed" :key="p.id" class="cd-post">
          <div class="cd-post-head">
            <router-link :to="`/u/${p.author_id}`" class="cd-post-av" :class="{ img: p.authorAvatar }" :style="p.authorAvatar ? { backgroundImage: `url(${p.authorAvatar})` } : {}">
              <span v-if="!p.authorAvatar">{{ (p.authorName || '?')[0].toUpperCase() }}</span>
            </router-link>
            <div class="cd-post-who">
              <router-link :to="`/u/${p.author_id}`" class="cd-post-name">{{ p.authorName }}</router-link>
              <span class="cd-post-time">{{ ago(p.created_at) }}</span>
            </div>
            <button v-if="p.author_id === me || amManager" type="button" class="cd-post-del" @click="removePost(p)"><Trash2 :size="14" /></button>
          </div>
          <p v-if="p.body" class="cd-post-body">{{ p.body }}</p>
          <div v-if="p.photos.length" class="cd-post-pics" :class="{ multi: p.photos.length > 1 }">
            <img v-for="ph in p.photos" :key="ph" :src="ph" loading="lazy" alt="" />
          </div>
        </article>
      </div>

      <!-- MEETUPS -->
      <div v-else-if="tab === 'events'" class="cd-events">
        <router-link v-if="amMember" :to="`/host-game?community=${c.id}`" class="cd-newmeet"><Plus :size="16" /> Host a meetup for this community</router-link>
        <div v-if="!events.length" class="cempty"><CalendarDays :size="36" /><span>No meetups yet{{ amMember ? ' — host the first one!' : '.' }}</span></div>
        <div v-else class="cd-events-grid">
          <GameCard v-for="g in events" :key="g.id" :game="g" />
        </div>
      </div>

      <!-- MEMBERS -->
      <div v-else-if="tab === 'members'" class="cd-members">
        <div v-if="amManager && pendingMembers.length" class="cd-pending">
          <div class="cd-pending-h">Waiting for approval</div>
          <div v-for="m in pendingMembers" :key="m.id" class="cd-member">
            <router-link :to="`/u/${m.customer_id}`" class="cd-member-av" :class="{ img: m.avatar }" :style="m.avatar ? { backgroundImage: `url(${m.avatar})` } : {}"><span v-if="!m.avatar">{{ (m.name || '?')[0].toUpperCase() }}</span></router-link>
            <span class="cd-member-name">{{ m.name }}</span>
            <div class="cd-member-actions">
              <button type="button" class="cd-mbtn ok" @click="decideMember(m, true)"><Check :size="15" /></button>
              <button type="button" class="cd-mbtn no" @click="decideMember(m, false)"><X :size="15" /></button>
            </div>
          </div>
        </div>
        <div v-for="m in acceptedMembers" :key="m.id" class="cd-member">
          <router-link :to="`/u/${m.customer_id}`" class="cd-member-av" :class="{ img: m.avatar }" :style="m.avatar ? { backgroundImage: `url(${m.avatar})` } : {}"><span v-if="!m.avatar">{{ (m.name || '?')[0].toUpperCase() }}</span></router-link>
          <span class="cd-member-name">{{ m.name }}</span>
          <span v-if="m.role !== 'member'" class="cd-role" :class="m.role"><Shield :size="11" /> {{ m.role }}</span>
          <div v-if="isOwner && m.role !== 'owner'" class="cd-member-actions">
            <button type="button" class="cd-mbtn ghost" :title="m.role === 'admin' ? 'Make member' : 'Make admin'" @click="toggleAdmin(m)"><Shield :size="14" /></button>
            <button type="button" class="cd-mbtn no" title="Remove" @click="kickMember(m)"><X :size="14" /></button>
          </div>
          <div v-else-if="amManager && m.role === 'member'" class="cd-member-actions">
            <button type="button" class="cd-mbtn no" title="Remove" @click="kickMember(m)"><X :size="14" /></button>
          </div>
        </div>
      </div>

      <!-- ABOUT -->
      <div v-else-if="tab === 'about'" class="cd-about">
        <p v-if="c.description" class="cd-about-desc">{{ c.description }}</p>
        <p v-else class="cd-about-desc muted">The organizers haven't written a description yet.</p>
        <div class="cd-about-rows">
          <div v-if="c.sport" class="cd-about-row"><UsersRound :size="15" /> <b>{{ c.sport }}</b> community</div>
          <div class="cd-about-row"><Lock v-if="c.is_private" :size="15" /><UsersRound v-else :size="15" /> {{ c.is_private ? 'Private — organizers approve who joins' : 'Open — anyone can join' }}</div>
          <div class="cd-about-row"><CalendarDays :size="15" /> Created {{ new Date(c.created_at).toLocaleDateString('en-US', { month: 'long', year: 'numeric' }) }}</div>
        </div>
      </div>

      <!-- Floating chat button (members only) -->
      <button v-if="amMember" type="button" class="cd-chatfab" @click="openChat" aria-label="Community chat">
        <MessageCircle :size="22" />
        <span v-if="chatUnread" class="cd-chatfab-badge">{{ chatUnread > 9 ? '9+' : chatUnread }}</span>
      </button>

      <!-- Owner edit sheet -->
      <div v-if="editOpen" class="cd-chat-back" @click.self="editOpen = false">
        <div class="cd-chat cd-edit">
          <div class="cd-chat-head">
            <span><Pencil :size="15" /> Edit community</span>
            <button type="button" class="cd-chat-x" @click="editOpen = false"><X :size="18" /></button>
          </div>
          <div class="cd-edit-body">
            <label class="cd-edit-l">Name</label>
            <input v-model="edit.name" type="text" class="cd-edit-in" />
            <label class="cd-edit-l">Sport</label>
            <input v-model="edit.sport" type="text" class="cd-edit-in" placeholder="Football, Padel…" />
            <label class="cd-edit-l">Description</label>
            <textarea v-model="edit.description" rows="4" class="cd-edit-in"></textarea>
            <label class="cd-edit-switch">
              <input v-model="edit.is_private" type="checkbox" />
              <span><Lock :size="13" /> Private — approve every member</span>
            </label>
            <button type="button" class="cd-edit-save" @click="saveEdit"><Check :size="15" /> Save</button>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
.cback { display: inline-flex; align-items: center; gap: 6px; color: var(--brand); text-decoration: none; font-weight: 700; font-size: 14px; margin-bottom: 14px; }
.cmsg { text-align: center; padding: 50px; color: var(--text-light, #94a3b8); }
.cempty { display: flex; flex-direction: column; align-items: center; gap: 12px; padding: 44px 16px; color: var(--text-light, #94a3b8); }
.cd-file { display: none; }
.cd-spin { animation: cdspin 1s linear infinite; } @keyframes cdspin { to { transform: rotate(360deg); } }

/* Hero */
.cd-hero { border-radius: 20px; padding: 22px; min-height: 130px; display: flex; flex-direction: column; justify-content: flex-end; color: #fff; background: linear-gradient(135deg,#0d9488,#0f766e) center/cover; }
.cd-hero-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: auto; }
.cd-lock { display: inline-flex; align-items: center; gap: 5px; background: rgba(255,255,255,.2); font-size: 12px; font-weight: 800; padding: 4px 11px; border-radius: 999px; }
.cd-coverbtn { display: inline-flex; align-items: center; gap: 5px; background: rgba(255,255,255,.22); color: #fff; font-size: 12px; font-weight: 800; padding: 5px 11px; border-radius: 999px; cursor: pointer; margin-left: auto; }
.cd-hero h1 { font-size: 26px; font-weight: 900; margin: 14px 0 4px; color: #fff; }
.cd-herobtns { display: inline-flex; align-items: center; gap: 7px; }
.cd-idrow { display: flex; align-items: center; gap: 12px; }
.cd-idrow h1 { margin: 14px 0 4px; }
.cd-avatar { width: 58px; height: 58px; border-radius: 50%; background-size: cover; background-position: center; border: 2.5px solid rgba(255,255,255,.85); box-shadow: 0 4px 14px rgba(15,23,42,.28); flex-shrink: 0; margin-top: 10px; }
.cd-hero-meta { display: flex; align-items: center; gap: 10px; }
.cd-sport { font-size: 12.5px; font-weight: 800; background: rgba(255,255,255,.2); padding: 3px 11px; border-radius: 999px; text-transform: capitalize; }
.cd-count { display: inline-flex; align-items: center; gap: 5px; font-size: 13px; font-weight: 700; opacity: .95; }

/* Join bar */
.cd-joinbar { display: flex; align-items: center; gap: 10px; margin: 14px 0; }
.cd-join { display: inline-flex; align-items: center; gap: 7px; background: #059669; color: #fff; font-weight: 800; font-size: 15px; padding: 12px 22px; border: none; border-radius: 999px; cursor: pointer; }
.cd-join:hover { background: #047857; }
.cd-join.pending { background: #f1f5f9; color: #475569; cursor: default; }
.cd-memberchip { display: inline-flex; align-items: center; gap: 6px; background: #dcfce7; color: #166534; font-size: 13px; font-weight: 800; padding: 8px 15px; border-radius: 999px; }
.cd-editbtn, .cd-leave { display: inline-flex; align-items: center; gap: 6px; background: none; border: 1.5px solid var(--border, #e2e8f0); color: var(--text-muted, #64748b); font: inherit; font-weight: 700; font-size: 13px; padding: 8px 14px; border-radius: 999px; cursor: pointer; }
.cd-chatbtn { position: relative; display: inline-flex; align-items: center; gap: 6px; background: #0d9488; border: none; color: #fff; font: inherit; font-weight: 800; font-size: 13px; padding: 8px 15px; border-radius: 999px; cursor: pointer; }
.cd-chatbtn-badge { min-width: 18px; height: 18px; padding: 0 5px; border-radius: 999px; background: #e11d48; color: #fff; font-size: 10.5px; font-weight: 800; display: inline-flex; align-items: center; justify-content: center; margin-left: 2px; }
.cd-leave { margin-left: auto; }
.cd-leave:hover { color: #dc2626; border-color: #fecaca; }

/* Tabs */
.cd-tabs { display: flex; gap: 8px; overflow-x: auto; padding-bottom: 4px; margin-bottom: 14px; scrollbar-width: none; }
.cd-tabs::-webkit-scrollbar { display: none; }
.cd-tab { flex: 0 0 auto; display: inline-flex; align-items: center; gap: 7px; border: 1.5px solid var(--border, #e2e8f0); background: var(--surface, #ffffff); color: var(--text-muted, #64748b); font: inherit; font-weight: 800; font-size: 13.5px; padding: 9px 16px; border-radius: 999px; cursor: pointer; }
.cd-tab.on { background: #0f172a; color: #fff; border-color: #0f172a; }

.cd-locked { display: flex; flex-direction: column; align-items: center; gap: 12px; padding: 48px 20px; color: var(--text-light, #94a3b8); text-align: center; }
.cd-locked p { max-width: 320px; margin: 0; font-size: 14.5px; }

/* Feed */
.cd-feed { max-width: 640px; }
.cd-composer { background: var(--surface, #ffffff); border: 1.5px solid var(--border, #e2e8f0); border-radius: 16px; padding: 12px; margin-bottom: 16px; }
.cd-composer textarea { width: 100%; box-sizing: border-box; border: none; outline: none; resize: vertical; font: inherit; font-size: 15px; background: none; color: #0f172a; }
.cd-composer-pics { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 8px; }
.cd-composer-pic { position: relative; width: 64px; height: 64px; border-radius: 10px; background: #f1f5f9 center/cover; }
.cd-composer-pic button { position: absolute; top: -6px; right: -6px; width: 20px; height: 20px; border-radius: 50%; border: none; background: #0f172a; color: #fff; display: flex; align-items: center; justify-content: center; cursor: pointer; }
.cd-composer-bar { display: flex; align-items: center; justify-content: space-between; margin-top: 8px; }
.cd-composer-img { display: inline-flex; align-items: center; justify-content: center; width: 36px; height: 36px; border-radius: 10px; background: #f1f5f9; color: var(--text-muted, #64748b); cursor: pointer; }
.cd-composer-send { display: inline-flex; align-items: center; gap: 6px; background: var(--brand, #e11d48); color: #fff; font-weight: 800; font-size: 13.5px; padding: 9px 18px; border: none; border-radius: 999px; cursor: pointer; }
.cd-composer-send:disabled { opacity: .5; cursor: default; }

.cd-post { background: var(--surface, #ffffff); border: 1px solid var(--border, #e2e8f0); border-radius: 16px; padding: 14px 16px; margin-bottom: 12px; }
.cd-post-head { display: flex; align-items: center; gap: 10px; }
.cd-post-av { width: 36px; height: 36px; border-radius: 50%; background: #e0f2fe center/cover; color: #0369a1; font-weight: 800; display: flex; align-items: center; justify-content: center; text-decoration: none; flex-shrink: 0; }
.cd-post-who { flex: 1; min-width: 0; display: flex; flex-direction: column; }
.cd-post-name { font-size: 14px; font-weight: 800; color: #0f172a; text-decoration: none; }
.cd-post-time { font-size: 11.5px; color: var(--text-light, #94a3b8); }
.cd-post-del { background: none; border: none; color: var(--text-light, #94a3b8); cursor: pointer; padding: 6px; }
.cd-post-del:hover { color: #dc2626; }
.cd-post-body { font-size: 14.5px; color: #334155; line-height: 1.55; margin: 10px 0 0; white-space: pre-wrap; overflow-wrap: anywhere; }
.cd-post-pics { margin-top: 10px; border-radius: 12px; overflow: hidden; }
.cd-post-pics img { width: 100%; display: block; max-height: 420px; object-fit: cover; }
.cd-post-pics.multi { display: grid; grid-template-columns: 1fr 1fr; gap: 4px; }
.cd-post-pics.multi img { height: 180px; }

/* Meetups */
.cd-newmeet { display: inline-flex; align-items: center; gap: 7px; background: #059669; color: #fff; font-weight: 800; font-size: 14px; padding: 11px 18px; border-radius: 999px; text-decoration: none; margin-bottom: 16px; }
.cd-events-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(450px, 100%), 1fr)); gap: 16px; }

/* Members */
.cd-members { max-width: 560px; }
.cd-pending { background: #fffbeb; border: 1px solid #fde68a; border-radius: 14px; padding: 10px 12px; margin-bottom: 14px; }
.cd-pending-h { font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: .04em; color: #92400e; margin-bottom: 6px; }
.cd-member { display: flex; align-items: center; gap: 10px; padding: 8px 4px; }
.cd-member + .cd-member { border-top: 1px solid #f1f5f9; }
.cd-member-av { width: 38px; height: 38px; border-radius: 50%; background: #e0f2fe center/cover; color: #0369a1; font-weight: 800; display: flex; align-items: center; justify-content: center; text-decoration: none; flex-shrink: 0; }
.cd-member-name { flex: 1; min-width: 0; font-size: 14.5px; font-weight: 700; color: #0f172a; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.cd-role { display: inline-flex; align-items: center; gap: 4px; font-size: 11px; font-weight: 800; text-transform: capitalize; padding: 3px 9px; border-radius: 999px; background: #ede9fe; color: #6d28d9; }
.cd-role.owner { background: #ffe4e6; color: #be123c; }
.cd-member-actions { display: flex; gap: 6px; }
.cd-mbtn { width: 34px; height: 34px; border-radius: 10px; border: none; display: flex; align-items: center; justify-content: center; cursor: pointer; }
.cd-mbtn.ok { background: #16a34a; color: #fff; }
.cd-mbtn.no { background: #fee2e2; color: #b91c1c; }
.cd-mbtn.ghost { background: #f1f5f9; color: #475569; }

/* About */
.cd-about { max-width: 560px; }
.cd-about-desc { font-size: 15px; color: #334155; line-height: 1.6; white-space: pre-wrap; }
.cd-about-desc.muted { color: var(--text-light, #94a3b8); }
.cd-about-rows { display: flex; flex-direction: column; gap: 10px; margin-top: 16px; }
.cd-about-row { display: flex; align-items: center; gap: 9px; font-size: 14px; color: #475569; }
.cd-about-row svg { color: #0d9488; flex-shrink: 0; }

/* Chat FAB + sheet */
.cd-chatfab { position: fixed; right: 18px; bottom: 86px; z-index: 80; width: 54px; height: 54px; border-radius: 50%; border: none; background: #0d9488; color: #fff; display: flex; align-items: center; justify-content: center; cursor: pointer; box-shadow: 0 10px 26px rgba(13,148,136,.4); }
.cd-chatfab-badge { position: absolute; top: -3px; right: -3px; min-width: 20px; height: 20px; padding: 0 5px; border-radius: 999px; background: #e11d48; color: #fff; font-size: 11px; font-weight: 800; display: flex; align-items: center; justify-content: center; }
.cd-chat-back { position: fixed; inset: 0; z-index: 200; background: rgba(15,23,42,.5); display: flex; align-items: flex-end; justify-content: center; }
.cd-chat { width: 100%; max-width: 560px; background: var(--surface, #ffffff); border-radius: 20px 20px 0 0; display: flex; flex-direction: column; max-height: 82vh; }
.cd-chat-head { display: flex; align-items: center; justify-content: space-between; padding: 14px 16px; border-bottom: 1px solid var(--border, #e2e8f0); }
.cd-chat-head span { display: inline-flex; align-items: center; gap: 8px; font-weight: 800; font-size: 15px; color: #0f172a; }
.cd-chat-lockchip { display: inline-flex; align-items: center; gap: 3px; font-size: 10.5px; font-weight: 800; text-transform: uppercase; letter-spacing: .03em; color: #b45309; background: #fef3c7; padding: 2px 8px; border-radius: 999px; }
.cd-chat-headright { display: inline-flex; align-items: center; gap: 4px; }
.cd-chat-lock { display: inline-flex; align-items: center; justify-content: center; width: 34px; height: 34px; border-radius: 10px; border: none; background: none; color: var(--text-muted, #64748b); cursor: pointer; }
.cd-chat-lock.on { background: #fef3c7; color: #b45309; }
.cd-chat-x { background: none; border: none; color: var(--text-muted, #64748b); cursor: pointer; padding: 4px; }
.cd-chat-readonly { display: flex; align-items: center; justify-content: center; gap: 7px; padding: 14px 16px calc(14px + env(safe-area-inset-bottom, 0px)); border-top: 1px solid var(--border, #e2e8f0); font-size: 13px; font-weight: 700; color: var(--text-muted, #64748b); background: #f8fafc; }
.cd-chat-body { flex: 1; overflow-y: auto; padding: 14px 16px; min-height: 220px; }
.cd-chat-empty { text-align: center; color: var(--text-light, #94a3b8); font-size: 13.5px; padding: 30px 0; }
.cd-chat-msg { margin-bottom: 10px; display: flex; flex-direction: column; align-items: flex-start; }
.cd-chat-msg.mine { align-items: flex-end; }
.cd-chat-who { font-size: 11px; font-weight: 800; color: var(--text-light, #94a3b8); margin: 0 4px 2px; }
.cd-chat-bubble { max-width: 78%; background: #f1f5f9; color: #0f172a; border-radius: 14px; padding: 9px 13px; font-size: 14px; line-height: 1.4; overflow-wrap: anywhere; white-space: pre-wrap; }
.cd-chat-msg.mine .cd-chat-bubble { background: #0d9488; color: #fff; }
.cd-chat-bar { display: flex; gap: 8px; padding: 10px 12px calc(10px + env(safe-area-inset-bottom, 0px)); border-top: 1px solid var(--border, #e2e8f0); }
.cd-chat-bar input { flex: 1; border: 1.5px solid var(--border, #e2e8f0); border-radius: 999px; padding: 11px 16px; font: inherit; font-size: 15px; outline: none; background: none; color: #0f172a; }
.cd-chat-send { width: 44px; height: 44px; border-radius: 50%; border: none; background: #0d9488; color: #fff; display: flex; align-items: center; justify-content: center; cursor: pointer; flex-shrink: 0; }
.cd-chat-send:disabled { opacity: .5; }

/* Edit sheet */
.cd-edit-body { padding: 14px 16px calc(16px + env(safe-area-inset-bottom, 0px)); overflow-y: auto; }
.cd-edit-l { display: block; font-size: 12.5px; font-weight: 800; color: #475569; margin: 10px 0 4px; }
.cd-edit-in { width: 100%; box-sizing: border-box; border: 1.5px solid var(--border, #e2e8f0); border-radius: 10px; padding: 10px 13px; font: inherit; font-size: 15px; outline: none; background: none; color: #0f172a; }
.cd-edit-switch { display: flex; align-items: center; gap: 9px; margin: 14px 0; font-size: 14px; font-weight: 700; color: #334155; cursor: pointer; }
.cd-edit-switch span { display: inline-flex; align-items: center; gap: 6px; }
.cd-edit-save { width: 100%; display: flex; align-items: center; justify-content: center; gap: 7px; background: #0d9488; color: #fff; font-weight: 800; font-size: 15px; padding: 13px; border: none; border-radius: 12px; cursor: pointer; }

@media (min-width: 761px) {
  .cd-chat-back { align-items: center; padding: 20px; }
  .cd-chat { border-radius: 20px; height: 640px; }
  .cd-chatfab { bottom: 26px; right: 26px; }
}
@media (max-width: 760px) {
  .cd-hero { padding: 16px; border-radius: 16px; }
  .cd-hero h1 { font-size: 21px; }
  .cd-tab span { display: inline; }
  .cd-post-pics.multi img { height: 130px; }
}
</style>
