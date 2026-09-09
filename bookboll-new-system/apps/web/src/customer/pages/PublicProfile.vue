<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { customer, isCustomer, updateCustomerProfile } from '../../lib/customerSession.js'
import {
  loadPublicProfileBundle,
  followUser,
  unfollowUser,
  loadFollowList,
  respondFollow,
  createPost,
  deletePost,
} from '../apiPublicProfile.js'
import { uploadImage } from '../../lib/imageUpload.js'
import GameCard from '../components/GameCard.vue'
import { useSeo } from '../../lib/seo.js'
import { ArrowLeft, CalendarDays, Trophy, Users, ShieldCheck, Camera, Plus, X, Loader, Maximize2, ChevronLeft, ChevronRight, UserPlus, UserCheck, Clock, MessageCircle, Lock, Trash2, Star, Check, Pencil } from 'lucide-vue-next'
const route = useRoute()
const router = useRouter()
const profile = ref(null)
const props_ = ref([])                                     // street-cred badge counts
const communities = ref([])                                // clubs this player owns / belongs to
const loading = ref(true)

// ── Social state ──
const followState = ref('none')        // 'none' | 'pending' | 'following'
const counts = ref({ followers: 0, following: 0 })
const canView = ref(true)
const posts = ref([])
const pendingReqs = ref([])
const followBusy = ref(false)

const isSelf = computed(() => isCustomer.value && customer.value?.id === profile.value?.id)

async function load() {
  loading.value = true
  followState.value = 'none'; counts.value = { followers: 0, following: 0 }; posts.value = []; pendingReqs.value = []
  const bundle = await loadPublicProfileBundle(route.params.id)
  if (!bundle) {
    profile.value = null
    loading.value = false
    return
  }
  profile.value = bundle.profile
  followState.value = bundle.followState
  counts.value = bundle.counts
  canView.value = bundle.canView
  posts.value = bundle.posts
  pendingReqs.value = bundle.pendingRequests
  props_.value = bundle.streetCred
  communities.value = bundle.communities
  loading.value = false
}
// Real memberships only; private clubs stay hidden from other viewers.
const shownCommunities = computed(() => (communities.value || [])
  .filter(c => c.membership?.role === 'owner' || c.membership?.status === 'accepted')
  .filter(c => !c.is_private || isSelf.value))
onMounted(load)
watch(() => route.params.id, load)

useSeo(() => {
  if (!profile.value) return { path: route.path, noindex: true }
  return {
    title: `${profile.value.name} — player profile`,
    description: `${profile.value.name}'s player profile, games and tournaments on Bookboll.`,
    image: profile.value.avatar,
    path: route.path,
    type: 'profile',
    noindex: profile.value.isPrivate,   // don't index private profiles
    jsonLd: { '@context': 'https://schema.org', '@type': 'ProfilePage', mainEntity: { '@type': 'Person', name: profile.value.name } },
  }
})

const initial = computed(() => (profile.value?.name || '?').trim()[0]?.toUpperCase() || '?')
const memberSince = computed(() => profile.value?.joinedAt
  ? new Date(profile.value.joinedAt).toLocaleDateString('en-US', { month: 'long', year: 'numeric' }) : '')

function timeAgo(iso) {
  if (!iso) return ''
  const s = Math.floor((Date.now() - new Date(iso)) / 1000)
  if (s < 60) return 'just now'
  const m = Math.floor(s / 60); if (m < 60) return `${m}m ago`
  const h = Math.floor(m / 60); if (h < 24) return `${h}h ago`
  const d = Math.floor(h / 24); if (d < 7) return `${d}d ago`
  return new Date(iso).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })
}

// ── Follow / message ──
async function goFollow() {
  if (!isCustomer.value) { router.push('/account'); return }
  followBusy.value = true
  if (followState.value === 'none') {
    const r = await followUser(customer.value.id, { id: profile.value.id, is_private: profile.value.isPrivate })
    if (!r.error) followState.value = r.state
  } else {
    await unfollowUser(customer.value.id, profile.value.id)
    followState.value = 'none'
  }
  followBusy.value = false
  const refreshed = await loadPublicProfileBundle(profile.value.id)
  if (refreshed) {
    counts.value = refreshed.counts
    canView.value = refreshed.canView
    followState.value = refreshed.followState
    if (canView.value && !posts.value.length) posts.value = refreshed.posts
  }
}
function goMessage() {
  if (!isCustomer.value) { router.push('/account'); return }
  router.push(`/messages/${profile.value.id}`)
}
async function actReq(req, accept) {
  await respondFollow(req.id, accept)
  pendingReqs.value = pendingReqs.value.filter(r => r.id !== req.id)
  if (accept) {
    const refreshed = await loadPublicProfileBundle(profile.value.id)
    if (refreshed) counts.value = refreshed.counts
  }
}

// ── Followers / following modal ──
const listOpen = ref(false)
const listKind = ref('followers')
const listPeople = ref([])
const listLoading = ref(false)
async function openList(kind) {
  if (!canView.value && !isSelf.value) return
  listKind.value = kind; listOpen.value = true; listLoading.value = true
  listPeople.value = await loadFollowList(profile.value.id, kind)
  listLoading.value = false
}
function goProfile(id) { listOpen.value = false; router.push(`/u/${id}`) }
// A profile can be opened from anywhere (a community, an event, followers, search…),
// so "back" should return to wherever you actually came from. Fall back to Home only
// when there's no in-app history (e.g. a shared link opened cold).
function goBack() {
  if (window.history.state?.back) router.back()
  else router.push('/')
}

// ── Post composer (self) ──
const postBody = ref('')
const postPhotos = ref([])
const posting = ref(false)
const postErr = ref('')
const postBusy = ref(false)
const postInput = ref(null)
function pickPostPhotos() { postInput.value?.click() }
async function onPostFiles(e) {
  const files = Array.from(e.target.files || []); if (!files.length) return
  postErr.value = ''; postBusy.value = true
  for (const file of files) {
    const res = await uploadImage(file, 'avatars')
    if (res.error) { postErr.value = res.error; break }
    postPhotos.value.push(res.url)
  }
  postBusy.value = false
  if (postInput.value) postInput.value.value = ''
}
async function submitPost() {
  postErr.value = ''; posting.value = true
  const r = await createPost(customer.value.id, { body: postBody.value, photos: postPhotos.value })
  posting.value = false
  if (r.error) { postErr.value = r.error; return }
  posts.value.unshift(r.post)
  postBody.value = ''; postPhotos.value = []
}
async function removeComposerPhoto(i) { postPhotos.value.splice(i, 1) }
async function onDeletePost(p) {
  if (!confirm('Delete this post?')) return
  await deletePost(p.id)
  posts.value = posts.value.filter(x => x.id !== p.id)
}

// ── Avatar / gallery editing (self) ──
const avatarBusy = ref(false)
const galBusy = ref(false)
const editErr = ref('')
const avatarInput = ref(null)
const galInput = ref(null)
async function persist() { await updateCustomerProfile({ avatar_url: profile.value.avatar || '', photos: profile.value.photos || [] }) }
function pickAvatar() { avatarInput.value?.click() }
async function onAvatarFile(e) {
  const file = e.target.files?.[0]; if (!file) return
  editErr.value = ''; avatarBusy.value = true
  const res = await uploadImage(file, 'avatars'); avatarBusy.value = false
  if (avatarInput.value) avatarInput.value.value = ''
  if (res.error) { editErr.value = res.error; return }
  profile.value.avatar = res.url; await persist()
}
function pickPhotos() { galInput.value?.click() }
async function onPhotoFiles(e) {
  const files = Array.from(e.target.files || []); if (!files.length) return
  editErr.value = ''; galBusy.value = true
  for (const file of files) { const res = await uploadImage(file, 'avatars'); if (res.error) { editErr.value = res.error; break } profile.value.photos.push(res.url) }
  galBusy.value = false
  if (galInput.value) galInput.value.value = ''
  await persist()
}
async function removePhoto(i) { profile.value.photos.splice(i, 1); await persist() }


// ── Fullscreen image viewer ──
const lbOpen = ref(false); const lbList = ref([]); const lbIdx = ref(0)
function openLightbox(list, i) { lbList.value = list.filter(Boolean); lbIdx.value = i; lbOpen.value = true }
function closeLb() { lbOpen.value = false }
function lbPrev() { lbIdx.value = (lbIdx.value - 1 + lbList.value.length) % lbList.value.length }
function lbNext() { lbIdx.value = (lbIdx.value + 1) % lbList.value.length }
function onAvatarClick() { if (isSelf.value) pickAvatar(); else if (profile.value?.avatar) openLightbox([profile.value.avatar], 0) }
function onLbKey(e) { if (!lbOpen.value) return; if (e.key === 'Escape') closeLb(); else if (e.key === 'ArrowLeft') lbPrev(); else if (e.key === 'ArrowRight') lbNext() }
onMounted(() => window.addEventListener('keydown', onLbKey))
onUnmounted(() => window.removeEventListener('keydown', onLbKey))
</script>

<template>
  <div>
    <button type="button" class="cback" @click="goBack"><ArrowLeft :size="16" /> Back</button>
    <div v-if="loading" class="cmsg">Loading…</div>
    <div v-else-if="!profile" class="cmsg">This profile isn't available.</div>
    <template v-else>
      <div class="pp-head">
        <div class="pp-avatar" :class="{ img: profile.avatar, editable: isSelf, viewable: !isSelf && profile.avatar }" :style="profile.avatar ? { backgroundImage: `url(${profile.avatar})` } : {}" :title="isSelf ? 'Change profile picture' : (profile.avatar ? 'View photo' : '')" @click="onAvatarClick">
          <span v-if="!profile.avatar">{{ initial }}</span>
          <div v-if="isSelf || profile.avatar" class="pp-avatar-ov">
            <Loader v-if="avatarBusy" :size="18" class="pp-spin" /><Camera v-else-if="isSelf" :size="18" /><Maximize2 v-else :size="18" />
          </div>
        </div>
        <input v-if="isSelf" ref="avatarInput" type="file" accept="image/*" class="pp-file" @change="onAvatarFile" />
        <div class="pp-id">
          <h1>{{ profile.name }} <Lock v-if="profile.isPrivate" :size="15" class="pp-lock" title="Private account" /></h1>
          <div v-if="memberSince" class="pp-since"><CalendarDays :size="14" /> Member since {{ memberSince }}</div>
          <p v-if="profile.bio && (canView || isSelf)" class="pp-bio">{{ profile.bio }}</p>
          <!-- Actions -->
          <div v-if="isSelf" class="pp-selfwrap">
            <router-link to="/account" class="pp-editbtn"><Pencil :size="14" /> Edit profile</router-link>
            <span class="pp-self"><ShieldCheck :size="13" /> Tap your photo to change it — photos &amp; posts save automatically.</span>
          </div>
          <div v-else class="pp-actions">
            <button class="pp-follow" :class="followState" :disabled="followBusy" @click="goFollow">
              <UserCheck v-if="followState === 'following'" :size="16" /><Clock v-else-if="followState === 'pending'" :size="16" /><UserPlus v-else :size="16" />
              {{ followState === 'following' ? 'Following' : followState === 'pending' ? 'Requested' : 'Follow' }}
            </button>
            <button v-if="canView" class="pp-msg" @click="goMessage"><MessageCircle :size="16" /> Message</button>
          </div>
        </div>
      </div>

      <!-- Stats -->
      <div class="pp-stats">
        <button class="pp-stat" :class="{ link: canView || isSelf }" @click="openList('followers')"><b>{{ counts.followers }}</b><span>Followers</span></button>
        <button class="pp-stat" :class="{ link: canView || isSelf }" @click="openList('following')"><b>{{ counts.following }}</b><span>Following</span></button>
        <div class="pp-stat"><b>{{ profile.hostedCount }}</b><span>Hosted</span></div>
        <div class="pp-stat"><b>{{ profile.joinedCount }}</b><span>Joined</span></div>
      </div>

      <!-- Street cred: peer badges earned at events -->
      <div v-if="props_.length" class="pp-props">
        <span v-for="b in props_" :key="b.badge" class="pp-prop" :title="`${b.label} — given by fellow players`">
          {{ b.emoji }} {{ b.label }} <b>×{{ b.count }}</b>
        </span>
      </div>

      <!-- Pending follow requests (private, self) -->
      <div v-if="isSelf && pendingReqs.length" class="pp-reqs">
        <h2><UserPlus :size="17" /> Follow requests <span class="pp-reqs-n">{{ pendingReqs.length }}</span></h2>
        <div v-for="req in pendingReqs" :key="req.id" class="pp-req">
          <router-link :to="`/u/${req.follower.id}`" class="pp-req-who">
            <span class="pp-req-av" :class="{ img: req.follower.avatar_url }" :style="req.follower.avatar_url ? { backgroundImage: `url(${req.follower.avatar_url})` } : {}">
              <span v-if="!req.follower.avatar_url">{{ (req.follower.full_name || '?')[0].toUpperCase() }}</span>
            </span>
            {{ req.follower.full_name || 'Player' }}
          </router-link>
          <div class="pp-req-btns">
            <button class="pp-req-accept" @click="actReq(req, true)">Accept</button>
            <button class="pp-req-decline" @click="actReq(req, false)">Decline</button>
          </div>
        </div>
      </div>

      <!-- Private wall -->
      <div v-if="!canView && !isSelf" class="pp-private">
        <Lock :size="34" />
        <h2>This account is private</h2>
        <p>Follow {{ profile.name }} to see their posts and photos. They’ll need to approve your request.</p>
      </div>

      <template v-else>
        <div v-if="editErr" class="pp-editerr">{{ editErr }}</div>

        <!-- Post composer (self) -->
        <div v-if="isSelf" class="pp-compose">
          <textarea v-model="postBody" class="pp-compose-txt" rows="2" maxlength="1000" placeholder="Share an update, a match recap, who you're looking to play with…"></textarea>
          <div v-if="postPhotos.length" class="pp-compose-imgs">
            <div v-for="(p, i) in postPhotos" :key="p" class="pp-compose-img" :style="{ backgroundImage: `url(${p})` }"><button class="pp-compose-x" aria-label="Remove photo" @click="removeComposerPhoto(i)"><X :size="12" /></button></div>
          </div>
          <div v-if="postErr" class="pp-editerr">{{ postErr }}</div>
          <div class="pp-compose-bar">
            <button class="pp-compose-add" :disabled="postBusy" @click="pickPostPhotos"><Loader v-if="postBusy" :size="16" class="pp-spin" /><template v-else><Plus :size="16" /> Photos</template></button>
            <input ref="postInput" type="file" accept="image/*" multiple class="pp-file" @change="onPostFiles" />
            <button class="pp-compose-post" :disabled="posting || postBusy || (!postBody.trim() && !postPhotos.length)" @click="submitPost">{{ posting ? 'Posting…' : 'Post' }}</button>
          </div>
        </div>

        <!-- Posts feed -->
        <div v-if="posts.length" class="pp-feed">
          <div v-for="post in posts" :key="post.id" class="pp-post">
            <div class="pp-post-top">
              <span class="pp-post-time">{{ timeAgo(post.created_at) }}</span>
              <button v-if="isSelf" class="pp-post-del" @click="onDeletePost(post)" title="Delete" aria-label="Delete post"><Trash2 :size="14" /></button>
            </div>
            <p v-if="post.body" class="pp-post-body">{{ post.body }}</p>
            <div v-if="post.photos.length" class="pp-post-imgs" :class="`n${Math.min(post.photos.length, 4)}`">
              <div v-for="(ph, i) in post.photos" :key="ph" class="pp-post-img" :style="{ backgroundImage: `url(${ph})` }" @click="openLightbox(post.photos, i)"></div>
            </div>
          </div>
        </div>
        <div v-else-if="!isSelf" class="pp-empty"><Users :size="28" /><span>No posts yet.</span></div>

        <!-- Photos -->
        <div v-if="profile.photos.length || isSelf" class="pp-photos">
          <div v-for="(p, i) in profile.photos" :key="p" class="pp-photo" :style="{ backgroundImage: `url(${p})` }" title="View photo" @click="openLightbox(profile.photos, i)">
            <button v-if="isSelf" class="pp-photo-x" @click.stop="removePhoto(i)" title="Remove photo" aria-label="Remove photo"><X :size="13" /></button>
          </div>
          <button v-if="isSelf" class="pp-photo-add" :disabled="galBusy" @click="pickPhotos">
            <Loader v-if="galBusy" :size="18" class="pp-spin" /><template v-else><Plus :size="18" /><span>Add</span></template>
          </button>
          <input v-if="isSelf" ref="galInput" type="file" accept="image/*" multiple class="pp-file" @change="onPhotoFiles" />
        </div>

        <!-- Communities this player owns / belongs to -->
        <div v-if="shownCommunities.length" class="pp-section">
          <h2><Users :size="18" /> Communities</h2>
          <div class="pp-comm-grid">
            <router-link v-for="c in shownCommunities" :key="c.id" :to="`/communities/${c.id}`" class="pp-comm">
              <span class="pp-comm-cover" :class="{ 'pp-has-cover': c.cover_image }" :style="c.cover_image ? { backgroundImage: `url(${c.cover_image})` } : {}">
                <Users v-if="!c.cover_image" :size="20" />
                <span v-if="c.membership?.role === 'owner'" class="pp-comm-role">Owner</span>
                <span v-else-if="c.membership?.role === 'admin'" class="pp-comm-role">Admin</span>
              </span>
              <span class="pp-comm-body">
                <span class="pp-comm-name">{{ c.name }}</span>
                <span class="pp-comm-meta">
                  <Lock v-if="c.is_private" :size="11" />{{ c.is_private ? 'Private' : 'Public' }} · {{ c.memberCount || 0 }} member{{ (c.memberCount || 0) === 1 ? '' : 's' }}
                </span>
              </span>
            </router-link>
          </div>
        </div>

        <!-- Hosting -->
        <div v-if="profile.hostedOpen.length" class="pp-section">
          <h2><Trophy :size="18" /> Hosting now</h2>
          <div class="pp-grid"><GameCard v-for="g in profile.hostedOpen" :key="g.id" :game="g" /></div>
        </div>
      </template>
    </template>

    <!-- Followers / following modal -->
    <Teleport to="body">
      <div v-if="listOpen" class="pp-modal" @click.self="listOpen = false">
        <div class="pp-modal-card">
          <div class="pp-modal-head"><b>{{ listKind === 'followers' ? 'Followers' : 'Following' }}</b><button aria-label="Close" @click="listOpen = false"><X :size="18" /></button></div>
          <div v-if="listLoading" class="pp-modal-msg">Loading…</div>
          <div v-else-if="!listPeople.length" class="pp-modal-msg">No one yet.</div>
          <div v-else class="pp-modal-list">
            <button v-for="p in listPeople" :key="p.id" class="pp-modal-row" @click="goProfile(p.id)">
              <span class="pp-req-av" :class="{ img: p.avatar_url }" :style="p.avatar_url ? { backgroundImage: `url(${p.avatar_url})` } : {}"><span v-if="!p.avatar_url">{{ (p.full_name || '?')[0].toUpperCase() }}</span></span>
              {{ p.full_name || 'Player' }}
            </button>
          </div>
        </div>
      </div>

    </Teleport>

    <!-- fullscreen image viewer -->
    <Teleport to="body">
      <div v-if="lbOpen" class="pp-lb" @click.self="closeLb">
        <button class="pp-lb-x" @click="closeLb" aria-label="Close"><X :size="22" /></button>
        <button v-if="lbList.length > 1" class="pp-lb-nav prev" @click.stop="lbPrev" aria-label="Previous"><ChevronLeft :size="26" /></button>
        <img :src="lbList[lbIdx]" class="pp-lb-img" :alt="`${profile?.name || 'Profile'} — photo ${lbIdx + 1} of ${lbList.length}`" decoding="async" />
        <button v-if="lbList.length > 1" class="pp-lb-nav next" @click.stop="lbNext" aria-label="Next"><ChevronRight :size="26" /></button>
        <div v-if="lbList.length > 1" class="pp-lb-count">{{ lbIdx + 1 }} / {{ lbList.length }}</div>
      </div>
    </Teleport>
  </div>
</template>

<style scoped>
.cback { display: inline-flex; align-items: center; gap: 6px; color: var(--brand); text-decoration: none; font-weight: 700; font-size: 14px; margin-bottom: 16px; background: none; border: none; padding: 0; cursor: pointer; font-family: inherit; }
.cmsg { text-align: center; padding: 50px; color: var(--text-light, #94a3b8); }

.pp-head { display: flex; align-items: flex-start; gap: 18px; background: var(--surface, #ffffff); border: 1px solid var(--border, #e2e8f0); border-radius: 20px; padding: 22px 24px; }
.pp-avatar { position: relative; overflow: hidden; width: 72px; height: 72px; border-radius: 50%; background: linear-gradient(135deg,var(--brand-accent),var(--brand)); color: #fff; font-size: 30px; font-weight: 900; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.pp-avatar.img { background-size: cover; background-position: center; }
.pp-avatar.editable, .pp-avatar.viewable { cursor: pointer; }
.pp-avatar-ov { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; background: rgba(15,23,42,.45); color: #fff; opacity: 0; transition: opacity .12s; }
.pp-avatar.editable:hover .pp-avatar-ov, .pp-avatar.viewable:hover .pp-avatar-ov { opacity: 1; }
.pp-file { display: none; }
.pp-id { min-width: 0; flex: 1; }
.pp-id h1 { font-size: 24px; font-weight: 900; color: #0f172a; margin: 0; display: flex; align-items: center; gap: 8px; }
.pp-lock { color: var(--text-light, #94a3b8); }
.pp-since { display: flex; align-items: center; gap: 6px; font-size: 13.5px; color: var(--text-muted, #64748b); margin-top: 5px; }
.pp-bio { font-size: 14px; color: #334155; line-height: 1.5; margin: 10px 0 0; white-space: pre-wrap; }
.pp-self { display: inline-flex; align-items: center; gap: 6px; font-size: 12.5px; font-weight: 700; color: var(--brand); background: color-mix(in srgb, var(--brand) 8%, white); border: 1px solid #99f6e4; padding: 4px 10px; border-radius: 999px; margin-top: 10px; }
.pp-selfwrap { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-top: 10px; }
.pp-selfwrap .pp-self { margin-top: 0; }
.pp-editbtn { display: inline-flex; align-items: center; gap: 6px; background: var(--brand); color: #fff; border: none; font: inherit; font-weight: 800; font-size: 13.5px; padding: 9px 16px; border-radius: 999px; cursor: pointer; text-decoration: none; }
.pp-editbtn:hover { opacity: .92; }
.pp-actions { display: flex; gap: 10px; margin-top: 12px; }
.pp-follow { display: inline-flex; align-items: center; gap: 7px; padding: 9px 18px; border-radius: 999px; border: none; background: var(--brand); color: #fff; font-weight: 800; font-size: 14px; cursor: pointer; }
.pp-follow.following, .pp-follow.pending { background: #f1f5f9; color: #334155; border: 1.5px solid var(--border, #e2e8f0); }
.pp-follow:disabled { opacity: .6; }
.pp-msg { display: inline-flex; align-items: center; gap: 7px; padding: 9px 18px; border-radius: 999px; border: 1.5px solid var(--border, #e2e8f0); background: var(--surface, #ffffff); color: #334155; font-weight: 800; font-size: 14px; cursor: pointer; }
.pp-msg:hover { border-color: var(--brand-accent); color: var(--brand); }

.pp-stats { display: flex; gap: 10px; margin: 16px 0 24px; }
/* Street-cred badges */
.pp-props { display: flex; gap: 8px; flex-wrap: wrap; margin: -12px 0 24px; }
.pp-props:not(:first-child) { margin-top: -12px; }
.pp-prop { display: inline-flex; align-items: center; gap: 5px; font-size: 12.5px; font-weight: 700; color: #92400e; background: #fffbeb; border: 1px solid #fde68a; padding: 6px 12px; border-radius: 999px; }
.pp-prop b { font-weight: 900; }
.pp-stat { flex: 1; max-width: 150px; background: var(--surface, #ffffff); border: 1px solid var(--border, #e2e8f0); border-radius: 16px; padding: 14px; text-align: center; cursor: default; }
.pp-stat.link { cursor: pointer; }
.pp-stat.link:hover { border-color: var(--brand-accent); }
.pp-stat b { display: block; font-size: 22px; font-weight: 900; color: #0f172a; line-height: 1; }
.pp-stat span { font-size: 12px; color: var(--text-muted, #64748b); font-weight: 600; }
button.pp-stat { font: inherit; }

.pp-reqs { background: var(--surface, #ffffff); border: 1px solid var(--border, #e2e8f0); border-radius: 16px; padding: 16px 18px; margin-bottom: 22px; }
.pp-reqs h2 { display: flex; align-items: center; gap: 8px; font-size: 16px; font-weight: 800; color: #0f172a; margin: 0 0 12px; }
.pp-reqs h2 svg { color: var(--brand); }
.pp-reqs-n { font-size: 12px; background: var(--brand); color: #fff; border-radius: 999px; padding: 1px 8px; }
.pp-req { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 8px 0; border-top: 1px solid #f1f5f9; }
.pp-req-who { display: flex; align-items: center; gap: 10px; font-weight: 700; color: #0f172a; text-decoration: none; font-size: 14px; }
.pp-req-av { width: 36px; height: 36px; border-radius: 50%; background: linear-gradient(135deg,var(--brand-accent),var(--brand)); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 13px; flex-shrink: 0; }
.pp-req-av.img { background-size: cover; background-position: center; }
.pp-req-btns { display: flex; gap: 8px; }
.pp-req-accept { border: none; background: var(--brand); color: #fff; font-weight: 800; font-size: 13px; padding: 7px 14px; border-radius: 999px; cursor: pointer; }
.pp-req-decline { border: 1.5px solid var(--border, #e2e8f0); background: var(--surface, #ffffff); color: var(--text-muted, #64748b); font-weight: 700; font-size: 13px; padding: 7px 14px; border-radius: 999px; cursor: pointer; }

.pp-private { text-align: center; color: var(--text-light, #94a3b8); background: var(--surface, #ffffff); border: 1px solid var(--border, #e2e8f0); border-radius: 18px; padding: 44px 24px; }
.pp-private svg { color: #cbd5e1; }
.pp-private h2 { font-size: 18px; font-weight: 800; color: #334155; margin: 12px 0 6px; }
.pp-private p { font-size: 14px; margin: 0; max-width: 360px; margin: 0 auto; line-height: 1.5; }

.pp-editerr { font-size: 13px; color: #dc2626; margin: 0 0 12px; }

/* Composer */
.pp-compose { background: var(--surface, #ffffff); border: 1px solid var(--border, #e2e8f0); border-radius: 16px; padding: 14px 16px; margin-bottom: 18px; }
.pp-compose-txt { width: 100%; box-sizing: border-box; border: none; outline: none; resize: vertical; font-family: inherit; font-size: 15px; color: #0f172a; }
.pp-compose-imgs { display: flex; flex-wrap: wrap; gap: 8px; margin: 8px 0; }
.pp-compose-img { position: relative; width: 72px; height: 72px; border-radius: 10px; background-size: cover; background-position: center; border: 1px solid var(--border, #e2e8f0); }
.pp-compose-x { position: absolute; top: 3px; right: 3px; width: 20px; height: 20px; border-radius: 50%; border: none; background: rgba(15,23,42,.78); color: #fff; display: flex; align-items: center; justify-content: center; cursor: pointer; }
.pp-compose-bar { display: flex; align-items: center; justify-content: space-between; gap: 10px; border-top: 1px solid #f1f5f9; padding-top: 10px; margin-top: 4px; }
.pp-compose-add { display: inline-flex; align-items: center; gap: 6px; border: 1.5px solid var(--border, #e2e8f0); background: var(--surface, #ffffff); color: #334155; font-weight: 700; font-size: 13.5px; padding: 8px 14px; border-radius: 999px; cursor: pointer; }
.pp-compose-add:hover:not(:disabled) { border-color: var(--brand-accent); color: var(--brand); }
.pp-compose-post { border: none; background: var(--brand); color: #fff; font-weight: 800; font-size: 14px; padding: 9px 22px; border-radius: 999px; cursor: pointer; }
.pp-compose-post:disabled { opacity: .5; cursor: not-allowed; }

/* Feed */
.pp-feed { display: flex; flex-direction: column; gap: 14px; margin-bottom: 24px; }
.pp-post { background: var(--surface, #ffffff); border: 1px solid var(--border, #e2e8f0); border-radius: 16px; padding: 14px 16px; }
.pp-post-top { display: flex; align-items: center; justify-content: space-between; }
.pp-post-time { font-size: 12px; color: var(--text-light, #94a3b8); font-weight: 600; }
.pp-post-del { border: none; background: none; color: #cbd5e1; cursor: pointer; padding: 2px; }
.pp-post-del:hover { color: #ef4444; }
.pp-post-body { font-size: 14.5px; color: #0f172a; line-height: 1.55; margin: 6px 0 0; white-space: pre-wrap; }
.pp-post-imgs { display: grid; gap: 6px; margin-top: 10px; }
.pp-post-imgs.n1 { grid-template-columns: 1fr; }
.pp-post-imgs.n2 { grid-template-columns: 1fr 1fr; }
.pp-post-imgs.n3, .pp-post-imgs.n4 { grid-template-columns: 1fr 1fr; }
.pp-post-img { height: 180px; border-radius: 10px; background-size: cover; background-position: center; cursor: zoom-in; border: 1px solid var(--border, #e2e8f0); }
.pp-post-imgs.n1 .pp-post-img { height: 280px; }

.pp-photos { display: grid; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 10px; margin-bottom: 24px; }
.pp-photo { position: relative; height: 110px; border-radius: 12px; background-size: cover; background-position: center; border: 1px solid var(--border, #e2e8f0); cursor: zoom-in; }
.pp-photo-x { position: absolute; top: 5px; right: 5px; width: 22px; height: 22px; border-radius: 50%; border: none; background: rgba(15,23,42,.78); color: #fff; display: flex; align-items: center; justify-content: center; cursor: pointer; }
.pp-photo-x:hover { background: #ef4444; }
.pp-photo-add { height: 110px; border-radius: 12px; border: 1.5px dashed #cbd5e1; background: #f8fafc; color: var(--brand); display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 2px; font-size: 11px; font-weight: 700; cursor: pointer; }
.pp-photo-add:hover:not(:disabled) { border-color: var(--brand-accent); background: color-mix(in srgb, var(--brand) 8%, white); }
.pp-spin { animation: ppspin 1s linear infinite; } @keyframes ppspin { to { transform: rotate(360deg); } }

.pp-section { margin-bottom: 26px; }
.pp-section h2 { display: flex; align-items: center; gap: 9px; font-size: 19px; font-weight: 800; color: #0f172a; margin: 0 0 14px; }
.pp-section h2 svg { color: var(--brand); }
.pp-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 16px; }
.pp-grid :deep(.gc) { width: auto; }
/* Communities the player owns / belongs to */
.pp-comm-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 20px 18px; }
.pp-comm { display: flex; align-items: center; gap: 14px; background: var(--surface, #ffffff); border: 1px solid var(--border, #e2e8f0); border-radius: 14px; padding: 14px; text-decoration: none; }
.pp-comm:hover { border-color: #0d9488; }
.pp-comm-cover { position: relative; width: 52px; height: 52px; flex-shrink: 0; border-radius: 12px; background: #f0fdfa center/cover no-repeat; display: flex; align-items: center; justify-content: center; color: #0d9488; }
.pp-comm-role { position: absolute; bottom: -7px; left: 50%; transform: translateX(-50%); font-size: 9px; font-weight: 800; text-transform: uppercase; letter-spacing: .03em; background: #0d9488; color: #fff; padding: 2px 7px; border-radius: 999px; white-space: nowrap; box-shadow: 0 0 0 2px var(--surface, #ffffff); }
.pp-comm-body { min-width: 0; display: flex; flex-direction: column; gap: 4px; }
.pp-comm-name { font-size: 14.5px; font-weight: 800; color: #0f172a; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pp-comm-meta { display: inline-flex; align-items: center; gap: 4px; font-size: 12px; font-weight: 600; color: var(--text-muted, #64748b); }
.pp-empty { display: flex; flex-direction: column; align-items: center; gap: 12px; padding: 40px; color: var(--text-light, #94a3b8); }

/* Followers modal */
.pp-modal { position: fixed; inset: 0; z-index: 210; background: rgba(15,23,42,.5); display: flex; align-items: center; justify-content: center; padding: 20px; }
.pp-modal-card { width: 100%; max-width: 380px; max-height: 70vh; background: var(--surface, #ffffff); border-radius: 18px; display: flex; flex-direction: column; overflow: hidden; }
.pp-modal-head { display: flex; align-items: center; justify-content: space-between; padding: 16px 18px; border-bottom: 1px solid var(--border, #e2e8f0); font-size: 16px; }
.pp-modal-head button { border: none; background: none; cursor: pointer; color: var(--text-muted, #64748b); }
.pp-modal-msg { padding: 30px; text-align: center; color: var(--text-light, #94a3b8); }
.pp-modal-list { overflow-y: auto; padding: 8px; }
.pp-modal-row { display: flex; align-items: center; gap: 11px; width: 100%; padding: 9px 10px; border: none; background: none; cursor: pointer; font-size: 14.5px; font-weight: 700; color: #0f172a; text-align: left; border-radius: 10px; }
.pp-modal-row:hover { background: #f1f5f9; }

.pp-lb { position: fixed; inset: 0; z-index: 220; background: rgba(2,6,23,.93); display: flex; align-items: center; justify-content: center; padding: 30px; }
.pp-lb-img { max-width: 92vw; max-height: 88vh; object-fit: contain; border-radius: 8px; box-shadow: 0 10px 40px rgba(0,0,0,.5); }
.pp-lb-x { position: absolute; top: 16px; right: 16px; width: 42px; height: 42px; border-radius: 50%; border: none; background: rgba(255,255,255,.15); color: #fff; display: flex; align-items: center; justify-content: center; cursor: pointer; }
.pp-lb-x:hover { background: rgba(255,255,255,.28); }
.pp-lb-nav { position: absolute; top: 50%; transform: translateY(-50%); width: 48px; height: 48px; border-radius: 50%; border: none; background: rgba(255,255,255,.15); color: #fff; display: flex; align-items: center; justify-content: center; cursor: pointer; }
.pp-lb-nav:hover { background: rgba(255,255,255,.28); }
.pp-lb-nav.prev { left: 16px; } .pp-lb-nav.next { right: 16px; }
.pp-lb-count { position: absolute; bottom: 20px; left: 50%; transform: translateX(-50%); color: #fff; font-size: 14px; font-weight: 700; background: rgba(0,0,0,.45); padding: 5px 14px; border-radius: 999px; }

/* ── Mobile (≤760px): tighten everything above the primary action ── */
@media (max-width: 760px) {
  .cback { margin-bottom: 12px; }

  /* Header card — smaller padding/avatar/heading so the actions row rises */
  .pp-head { padding: 16px; gap: 14px; align-items: center; }
  .pp-avatar { width: 60px; height: 60px; font-size: 26px; }
  .pp-id h1 { font-size: 20px; }
  .pp-bio { margin-top: 8px; font-size: 13.5px; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; }

  /* Self-view helper — demote the pill to a quiet one-line caption */
  .pp-self { background: none; border: none; padding: 0; border-radius: 0; font-weight: 600; font-size: 12px; color: var(--text-muted, #64748b); margin-top: 8px; }

  /* Primary action — Follow dominant & tall, Message secondary; both ≥44px.
     Slim horizontal padding + nowrap so both labels fit the ~220px text column at 344-360px. */
  .pp-actions { gap: 8px; margin-top: 10px; }
  .pp-follow { flex: 1; justify-content: center; padding: 12px 10px; min-height: 46px; font-size: 13.5px; white-space: nowrap; }
  .pp-msg { flex: 1; justify-content: center; padding: 12px 10px; min-height: 46px; font-size: 13.5px; white-space: nowrap; }

  /* Long unbroken names/handles must wrap, never widen the page */
  .pp-id h1 { overflow-wrap: anywhere; min-width: 0; }
  .pp-req-who, .pp-modal-row { overflow-wrap: anywhere; }
  .pp-modal-row { min-width: 0; }

  /* Stats — one bordered strip with hairline dividers (2×2 kept) */
  .pp-stats { display: grid; grid-template-columns: 1fr 1fr; gap: 0; margin: 12px 0 16px; background: var(--surface, #ffffff); border: 1px solid var(--border, #e2e8f0); border-radius: 16px; overflow: hidden; }
  .pp-stat { max-width: none; padding: 10px; border: none; border-radius: 0; background: none; }
  .pp-stat:nth-child(even) { border-left: 1px solid #f1f5f9; }
  .pp-stat:nth-child(n+3) { border-top: 1px solid #f1f5f9; }
  .pp-stat b { font-size: 19px; font-weight: 800; }

  /* Composer — 16px input avoids iOS zoom */
  .pp-compose { padding: 12px 14px; }
  .pp-compose-txt { font-size: 16px; }
  .pp-compose-add, .pp-compose-post { min-height: 44px; }

  /* Feed & gallery — intentional aspect ratios instead of fixed heights */
  .pp-post-img { height: auto; aspect-ratio: 4 / 3; }
  .pp-post-imgs.n1 .pp-post-img { height: auto; aspect-ratio: 16 / 9; max-height: 220px; }
  .pp-photos { grid-template-columns: repeat(3, 1fr); gap: 8px; }
  .pp-photo, .pp-photo-add { height: auto; aspect-ratio: 4 / 3; }

  /* Follow requests — name truncates, buttons reach 44px */
  .pp-req { flex-wrap: wrap; }
  .pp-req-who { min-width: 0; }
  .pp-req-accept, .pp-req-decline { padding: 10px 16px; min-height: 44px; }

  /* Private wall — less empty padding */
  .pp-private { padding: 30px 20px; }

  /* Followers/following modal — bottom sheet like the rest of the storefront */
  .pp-modal { align-items: flex-end; padding: 0; }
  .pp-modal-card { max-width: none; border-radius: 18px 18px 0 0; max-height: 76vh; }
  .pp-modal-list { padding-bottom: calc(16px + env(safe-area-inset-bottom)); }

  /* Section headings — trim a little vertical weight */
  .pp-section h2 { font-size: 17px; margin-bottom: 10px; }
  .pp-grid { gap: 12px; }

  /* Lightbox — reclaim breathing room around the image; slim chevrons off the edges */
  .pp-lb { padding: 8px; }
  .pp-lb-img { max-width: 100vw; max-height: 80vh; }
  .pp-lb-nav { width: 40px; height: 40px; }
  .pp-lb-nav.prev { left: 6px; }
  .pp-lb-nav.next { right: 6px; }
}
</style>
