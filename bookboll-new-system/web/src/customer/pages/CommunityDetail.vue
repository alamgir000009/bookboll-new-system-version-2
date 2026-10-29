<script setup>
import CommunityPermissions from '../components/CommunityPermissions.vue'
import ExpandableDescription from '../components/ExpandableDescription.vue'
import CommunityLocationPicker from '../components/CommunityLocationPicker.vue'
import { useCommunityPlace } from '../../lib/communityPlace.js'
import { communityRole, canContribute, canModerateCommunity, normalizedPermissions, permissionFor, permissionLabel } from '../../lib/communityAccess.js'
import { communityMemberCount } from '../../lib/communityPresentation.js'
import { ref, computed, onMounted, onBeforeUnmount, nextTick, watch } from 'vue'
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
import { loadCommunityEvents } from '../../customer/apiGames.js'
import GameCard from '../components/GameCard.vue'
import { useSeo } from '../../lib/seo.js'
import { ArrowLeft, UsersRound, Lock, MapPin, Plus, MessageCircle, Send, Newspaper, CalendarDays, Info, Check, X, Shield, Trash2, ImagePlus, Loader, LogOut, Pencil, Search, Settings, Share2, Link2, MoreHorizontal } from 'lucide-vue-next'

const route = useRoute()
const router = useRouter()
const c = ref(null)              // community (+ .membership for the viewer)
const members = ref([])
const feed = ref([])
const events = ref([])
const loading = ref(true)
const tab = ref('feed')          // feed | events | members | about
const busy = ref(false)
const memberQuery = ref('')
const editingRole = ref('')
const editBusy = ref(false)
const editError = ref('')
const canPost = computed(() => canContribute(c.value, me.value, 'posts'))
const amModerator = computed(() => canModerateCommunity(c.value, me.value))
// Who may host a meetup here: owner, admins and moderators — the API's
// CommunityPermissions::canHostEvent says the same, so a member never reaches
// a publish button that would 403.
const canHostHere = computed(() => amModerator.value)
const visibleMembers = computed(() => acceptedMembers.value.filter(m => (m.name || '').toLowerCase().includes(memberQuery.value.trim().toLowerCase())))

const me = computed(() => customer.value?.id || null)
// "Doha, Qatar" — from the names the API sends beside the ids, or looked up
// from the ids when it does not.
const placeLabel = useCommunityPlace(c)

// ── Invite / share ───────────────────────────────────────────────────────────
// Anyone may pass the link on — a community grows by members telling friends,
// and a visitor who likes what they see should be able to forward it too.
const shareOpen = ref(false)
const linkCopied = ref(false)
const shareUrl = computed(() => `${window.location.origin}/communities/${c.value?.id || route.params.id}`)
const shareText = computed(() => `${c.value?.is_private ? 'Request to join' : 'Join'} "${c.value?.name || 'our community'}" on Bookboll${c.value?.sport ? ' — ' + c.value.sport : ''}`)
const canNativeShare = typeof navigator !== 'undefined' && typeof navigator.share === 'function'
async function copyInviteLink() {
  try {
    await navigator.clipboard.writeText(shareUrl.value)
    linkCopied.value = true
    setTimeout(() => { linkCopied.value = false }, 2000)
    pushToast('Invite link copied — paste it anywhere to invite people.', 'success')
  } catch {
    window.prompt('Copy this invite link:', shareUrl.value)   // clipboard blocked (e.g. http, iframes)
  }
  shareOpen.value = false
}
function inviteWhatsApp() {
  window.open(`https://wa.me/?text=${encodeURIComponent(shareText.value + '\n' + shareUrl.value)}`, '_blank', 'noopener')
  shareOpen.value = false
}
async function nativeShare() {
  shareOpen.value = false
  try { await navigator.share({ title: c.value?.name || 'Bookboll community', text: shareText.value, url: shareUrl.value }) } catch { /* cancelled */ }
}
function onDocClick(e) { if (shareOpen.value && !e.target.closest('.cd-share')) shareOpen.value = false }
onMounted(() => document.addEventListener('click', onDocClick))
onBeforeUnmount(() => document.removeEventListener('click', onDocClick))
const membership = computed(() => c.value?.membership || null)
// The community's owner_customer_id is the source of truth for ownership — the
// owner keeps full control even if their owner-membership row went missing.
const isOwner = computed(() => communityRole(c.value, me.value) === 'owner')
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

let loadVersion = 0
async function load() {
  const version = ++loadVersion
  loading.value = true
  const community = await loadCommunity(route.params.id, me.value)
  if (version !== loadVersion) return
  c.value = community; feed.value = []; members.value = []; events.value = []; chatUnread.value = 0
  if (community && !contentLocked.value) {
    const [posts, people, meetups] = await Promise.all([loadCommunityFeed(community.id), loadMembers(community.id), loadCommunityEvents(community.id)])
    if (version !== loadVersion) return
    feed.value = posts; members.value = people; events.value = meetups
    refreshChatBadge()
  }
  if (contentLocked.value) tab.value = 'about'
  loading.value = false
  if (route.query.settings === '1' && amManager.value) {
    openEdit()
    router.replace({ query: { ...route.query, settings: undefined } })
  }
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
    const r = await uploadImage(f, 'communities')
    if (r.url) postPhotos.value.push(r.url)
    else if (r.error) pushToast(r.error, 'error')
  }
  postUploading.value = false
  if (postFile.value) postFile.value.value = ''
}
async function submitPost() {
  if (posting.value || postUploading.value || !canPost.value) return
  posting.value = true
  const res = await createCommunityPost(c.value.id, me.value, { body: postBody.value, photos: postPhotos.value })
  posting.value = false
  if (res.error) { pushToast(res.error, 'error'); return }
  postBody.value = ''; postPhotos.value = []
  feed.value = await loadCommunityFeed(c.value.id)
}
async function removePost(p) {
  if (!amModerator.value && String(p.author_id) !== String(me.value)) return
  const ok = await askConfirm({ title: 'Delete this post?', confirmText: 'Delete', danger: true })
  if (!ok) return
  const result = await deleteCommunityPost(p.id)
  if (result.error) { pushToast(result.error, 'error'); return }
  feed.value = feed.value.filter(x => x.id !== p.id)
}

// ── Members management ─────────────────────────────────────────────────────────
async function decideMember(m, accept) {
  if (!amManager.value) return
  const result = await respondToMember(m.id, accept)
  if (result.error) { pushToast(result.error, 'error'); return }
  members.value = await loadMembers(c.value.id)
  if (accept) pushToast(`${m.name} is in!`)
}
async function kickMember(m) {
  if (!canChangeRole(m)) return
  const ok = await askConfirm({ title: `Remove ${m.name}?`, message: 'They can request to join again later.', confirmText: 'Remove', danger: true })
  if (!ok) return
  const result = await removeMember(m.id)
  if (result.error) { pushToast(result.error, 'error'); return }
  members.value = await loadMembers(c.value.id)
}
function canChangeRole(member) {
  return amManager.value && member.role !== 'owner' && String(member.customer_id) !== String(me.value) && (isOwner.value || ['member','moderator'].includes(member.role))
}
async function changeRole(member, role) {
  if (!canChangeRole(member) || editingRole.value || (!isOwner.value && role === 'admin')) return
  editingRole.value = member.id
  const result = await setMemberRole(member.id, role)
  if (result.error) { pushToast(result.error, 'error'); editingRole.value = ''; return }
  const refreshed = await loadMembers(c.value.id)
  const confirmed = refreshed.find(m => String(m.id) === String(member.id))?.role === role
  members.value = refreshed
  editingRole.value = ''
  pushToast(confirmed ? member.name + ' is now a ' + role + '.' : 'The role change was not saved. Please try again later.', confirmed ? 'success' : 'error')
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
const editDialog = ref(null)
let editReturnFocus = null, editPreviousOverflow = ''
function releaseEditDialog() {
  document.body.style.overflow = editPreviousOverflow
  if (editReturnFocus?.isConnected) editReturnFocus.focus({ preventScroll: true })
  editReturnFocus = null
}
watch(editOpen, async open => {
  if (!open) { releaseEditDialog(); return }
  editReturnFocus = document.activeElement
  editPreviousOverflow = document.body.style.overflow
  document.body.style.overflow = 'hidden'
  await nextTick()
  editDialog.value?.querySelector('.cd-chat-x')?.focus()
})
onBeforeUnmount(() => { if (editOpen.value) releaseEditDialog() })
function editKeydown(event) {
  if (event.key !== 'Tab') return
  const targets = [...editDialog.value.querySelectorAll('button:not([disabled]), input:not([disabled]), textarea:not([disabled]), select:not([disabled])')].filter(el => el.getClientRects().length)
  const first = targets[0], last = targets.at(-1)
  if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus() }
  else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus() }
}
const edit = ref({ name: '', description: '', sport: '', is_private: false })
const coverFile = ref(null)
const coverUploading = ref(false)
function openEdit() {
  if (!amManager.value) return
  editError.value = ''
  edit.value = { name: c.value.name, description: c.value.description || '', sport: c.value.sport || '', is_private: !!c.value.is_private, settings: normalizedPermissions(c.value.settings), country_id: c.value.country_id || null, city_id: c.value.city_id || null }
  editOpen.value = true
}
async function onCoverPick(e) {
  const f = (e.target.files || [])[0]
  if (!f) return
  coverUploading.value = true
  const r = await uploadImage(f, 'communities')
  coverUploading.value = false
  if (r.error) { pushToast(r.error, 'error'); return }
  const result = await updateCommunity(c.value.id, { cover_image: r.url })
  if (result.error) { pushToast(result.error, 'error'); return }
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
  const r = await uploadImage(f, 'communities')
  avatarUploading.value = false
  if (r.error) { pushToast(r.error, 'error'); return }
  const res = await updateCommunity(c.value.id, { avatar_image: r.url })
  if (res.error) { pushToast(/avatar_image|column|schema/i.test(res.error) ? 'Run the community avatar migration first.' : res.error, 'error'); return }
  c.value.avatar_image = r.url
  if (avatarFile.value) avatarFile.value.value = ''
}
async function saveEdit() {
  if (!amManager.value || editBusy.value) return
  if (!edit.value.name.trim()) { editError.value = 'Enter a community name.'; return }
  if (!edit.value.country_id || !edit.value.city_id) { editError.value = 'Choose the country and city your community is based in.'; return }
  editBusy.value = true; editError.value = ''
  const result = await updateCommunity(c.value.id, { ...edit.value, name: edit.value.name.trim() })
  editBusy.value = false
  if (result.error) { editError.value = result.error; if (result.community) c.value = result.community; return }
  editOpen.value = false
  if (route.query.permissions) router.replace({ query: { ...route.query, permissions: undefined } })
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
  <div class="community-detail">
    <router-link to="/communities" class="cback"><ArrowLeft :size="16" /> Communities</router-link>

    <div v-if="loading" class="cmsg">Loading…</div>
    <div v-else-if="!c" class="cmsg">Community not found.</div>

    <template v-else>
      <p v-if="route.query.permissions === 'unconfirmed'" class="journey-error" role="alert">Your community was created, but its posting and chat permissions could not be confirmed. Review Settings before inviting members.</p>
      <!-- HERO -->
      <div class="cd-hero" :class="{ 'cd-has-cover': c.cover_image }" :style="c.cover_image ? { backgroundImage: `linear-gradient(180deg, rgba(15,23,42,.1), rgba(15,23,42,.82)), url(${c.cover_image})` } : {}">
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
          <span class="cd-avatar" :class="{fallback:!c.avatar_image}" :style="c.avatar_image ? { backgroundImage: `url(${c.avatar_image})` } : {}"><UsersRound v-if="!c.avatar_image" :size="32" /></span>
          <h1>{{ c.name }}</h1>
        </div>
        <div class="cd-hero-meta">
          <span v-if="c.sport" class="cd-sport">{{ c.sport }}</span>
          <span class="cd-count"><UsersRound :size="13" /> {{ communityMemberCount(c).toLocaleString() }} member{{ communityMemberCount(c) === 1 ? '' : 's' }}</span>
          <span v-if="placeLabel" class="cd-count cd-place"><MapPin :size="13" /> {{ placeLabel }}</span>
        </div>
      </div>

      <!-- JOIN / STATUS bar -->
      <div class="cd-joinbar">
        <template v-if="!amMember">
          <button v-if="membership?.status === 'pending'" class="cd-join pending" disabled><Check :size="15" /> Request sent</button>
          <button v-else class="cd-join" :disabled="busy" @click="join"><Plus :size="16" /> {{ c.is_private ? 'Request to join' : 'Join community' }}</button>
        </template>
        <template v-else>
          <span class="cd-memberchip"><Check :size="14" /> {{ isOwner ? 'Owner' : membership?.role === 'admin' ? 'Admin' : membership?.role === 'moderator' ? 'Moderator' : 'Member' }}</span>
          <button type="button" class="cd-chatbtn" @click="openChat"><MessageCircle :size="15" /> Group chat<span v-if="chatUnread" class="cd-chatbtn-badge">{{ chatUnread > 9 ? '9+' : chatUnread }}</span></button>
          <button v-if="amManager" type="button" class="cd-editbtn" @click="openEdit"><Settings :size="16" /> Settings</button>
        </template>

        <!-- Invite: copy the link, send it on WhatsApp, or hand it to the OS share sheet. -->
        <div class="cd-share">
          <button type="button" class="cd-sharebtn" :class="{ on: shareOpen }" :aria-expanded="shareOpen" aria-haspopup="menu" @click="shareOpen = !shareOpen"><Share2 :size="15" /> {{ amMember ? 'Invite' : 'Share' }}</button>
          <div v-if="shareOpen" class="cd-share-menu" role="menu" :aria-label="`Invite people to ${c.name}`">
            <div class="cd-share-link"><Link2 :size="14" /><span>{{ shareUrl }}</span></div>
            <button type="button" role="menuitem" @click="copyInviteLink"><Link2 :size="15" /> {{ linkCopied ? 'Copied!' : 'Copy invite link' }}</button>
            <button type="button" role="menuitem" @click="inviteWhatsApp"><MessageCircle :size="15" /> Share on WhatsApp</button>
            <button v-if="canNativeShare" type="button" role="menuitem" @click="nativeShare"><MoreHorizontal :size="15" /> More ways to share…</button>
            <p v-if="c.is_private">This community needs approval — people you invite will send a join request.</p>
          </div>
        </div>
        <button v-if="amMember && !isOwner" type="button" class="cd-leave" @click="leave"><LogOut :size="14" /> Leave</button>
      </div>

      <!-- TABS -->
      <nav class="cd-tabs" aria-label="Community sections">
        <button v-for="t in TABS" :key="t.key" type="button" class="cd-tab" :class="{ on: tab === t.key }"
          :aria-pressed="tab === t.key" @click="tab = t.key">
          <component :is="t.icon" :size="16" /><span>{{ t.label }}</span>
        </button>
      </nav>

      <!-- Private + not a member → everything except About is behind the door -->
      <div v-if="contentLocked && tab !== 'about'" class="cd-locked">
        <Lock :size="30" />
        <p>This community is private — join to see the feed, meetups and members.</p>
      </div>

      <!-- FEED -->
      <div v-else-if="tab === 'feed'" class="cd-feed-layout"><div class="cd-feed">
        <div class="cd-section-title"><h2>Community updates</h2><span>Latest posts</span></div>
        <p v-if="amMember && !canPost" class="cd-readonly"><Lock :size="16" /> {{ permissionLabel(permissionFor(c.settings, 'posts')) }} can post updates. You can read the feed and join meetups.</p>
        <div v-if="canPost" class="cd-composer">
          <textarea aria-label="Write a community update" v-model="postBody" rows="2" placeholder="Share an update with the community…"></textarea>
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
            <button type="button" class="cd-composer-send" :disabled="posting || postUploading || (!postBody.trim() && !postPhotos.length)" @click="submitPost">
              <Send :size="14" /> Post
            </button>
          </div>
        </div>

        <div v-if="!feed.length" class="cempty"><Newspaper :size="36" /><span>No posts yet{{ canPost ? ' — share the first update.' : '. Updates from your community will appear here.' }}</span></div>
        <article v-for="p in feed" :key="p.id" class="cd-post">
          <div class="cd-post-head">
            <router-link :to="`/u/${p.author_id}`" class="cd-post-av" :class="{ img: p.authorAvatar }" :style="p.authorAvatar ? { backgroundImage: `url(${p.authorAvatar})` } : {}">
              <span v-if="!p.authorAvatar">{{ (p.authorName || '?')[0].toUpperCase() }}</span>
            </router-link>
            <div class="cd-post-who">
              <router-link :to="`/u/${p.author_id}`" class="cd-post-name">{{ p.authorName }}</router-link>
              <span class="cd-post-time">{{ ago(p.created_at) }}</span>
            </div>
            <button v-if="String(p.author_id) === String(me) || amModerator" type="button" class="cd-post-del" aria-label="Delete post" @click="removePost(p)"><Trash2 :size="14" /></button>
          </div>
          <p v-if="p.body" class="cd-post-body">{{ p.body }}</p>
          <div v-if="p.photos?.length" class="cd-post-pics" :class="{ multi: p.photos.length > 1 }">
            <img v-for="ph in p.photos" :key="ph" :src="ph" loading="lazy" alt="" />
          </div>
        </article>
      </div>

      <aside class="cd-context"><h2>About this community</h2><ExpandableDescription v-if="c.description" :text="c.description" id="community-introduction" /><p v-else>Connect with people who share your interest in {{ c.sport || 'playing together' }}.</p><dl><dt>Based in</dt><dd>{{ placeLabel || 'Not set yet' }}</dd><dt>Joining</dt><dd>{{ c.is_private ? 'Approval required' : 'Open to everyone' }}</dd><dt>Feed posts</dt><dd>{{ permissionLabel(permissionFor(c.settings,'posts')) }}</dd><dt>Group chat</dt><dd>{{ permissionLabel(permissionFor(c.settings,'chat')) }}</dd></dl><router-link v-if="canHostHere" :to="'/host-game?community='+c.id" class="cd-context-link"><CalendarDays :size="17" /> Plan a meetup</router-link><p v-else-if="amMember" class="cd-context-note"><Shield :size="14" /> Meetups here are hosted by the owner, admins and moderators.</p></aside></div>
      <!-- MEETUPS -->
      <div v-else-if="tab === 'events'" class="cd-events">
        <router-link v-if="canHostHere" :to="`/host-game?community=${c.id}`" class="cd-newmeet"><Plus :size="16" /> Host a meetup for this community</router-link>
        <p v-else-if="amMember" class="cd-host-note"><Shield :size="15" /> Only the owner, admins and moderators can host meetups in this community. You'll see every meetup they post here, and can join them.</p>
        <div v-if="!events.length" class="cempty"><CalendarDays :size="36" /><span>No meetups yet{{ canHostHere ? ' — host the first one!' : '.' }}</span></div>
        <div v-else class="cd-events-grid">
          <GameCard v-for="g in events" :key="g.id" :game="g" />
        </div>
      </div>

      <!-- MEMBERS -->
      <div v-else-if="tab === 'members'" class="cd-members"><div class="cd-section-title"><h2>Members</h2><span>{{ acceptedMembers.length }} people</span></div><p v-if="amManager" class="cd-member-help">Moderators can remove unwanted posts and group messages. Admins also manage members and community settings.</p><label class="discover-search"><Search :size="18" /><input v-model="memberQuery" aria-label="Search members" type="search" placeholder="Find a member" /></label>
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
        <div v-for="m in visibleMembers" :key="m.id" class="cd-member">
          <router-link :to="`/u/${m.customer_id}`" class="cd-member-av" :class="{ img: m.avatar }" :style="m.avatar ? { backgroundImage: `url(${m.avatar})` } : {}"><span v-if="!m.avatar">{{ (m.name || '?')[0].toUpperCase() }}</span></router-link>
          <span class="cd-member-name">{{ m.name }}</span>
          <span v-if="m.role !== 'member'" class="cd-role" :class="m.role"><Shield :size="11" /> {{ m.role }}</span>
          <div v-if="canChangeRole(m)" class="cd-member-actions">
            <select :value="m.role" :disabled="!!editingRole" :aria-label="'Role for ' + m.name" @change="changeRole(m,$event.target.value)"><option value="member">Member</option><option value="moderator">Moderator</option><option v-if="isOwner" value="admin">Admin</option></select>
            <button type="button" class="cd-mbtn no" :aria-label="'Remove ' + m.name" @click="kickMember(m)"><X :size="16" /></button>
          </div>
        </div>
      </div>

      <p v-if="tab==='members' && !contentLocked && !visibleMembers.length" class="cmsg">No members match your search.</p>

      <!-- ABOUT -->
      <div v-if="tab === 'about'" class="cd-about">
        <p v-if="c.description" class="cd-about-desc">{{ c.description }}</p>
        <p v-else class="cd-about-desc muted">The organizers haven't written a description yet.</p>
        <div class="cd-about-rows">
          <div v-if="c.sport" class="cd-about-row"><UsersRound :size="15" /> <b>{{ c.sport }}</b> community</div>
          <div class="cd-about-row"><Lock v-if="c.is_private" :size="15" /><UsersRound v-else :size="15" /> {{ c.is_private ? 'Private — organizers approve who joins' : 'Open — anyone can join' }}</div>
          <div class="cd-about-row"><CalendarDays :size="15" /> Created {{ new Date(c.created_at).toLocaleDateString('en-US', { month: 'long', year: 'numeric' }) }}</div>
        </div>
      </div>

      <!-- Community settings -->
      <div v-if="editOpen" class="cd-chat-back" @click.self="!editBusy && (editOpen = false)">
        <div ref="editDialog" class="cd-chat cd-edit" role="dialog" aria-modal="true" aria-label="Community settings" @keydown="editKeydown" @keydown.esc="!editBusy && (editOpen=false)">
          <div class="cd-chat-head">
            <span><Pencil :size="15" /> Community settings</span>
            <button type="button" class="cd-chat-x" aria-label="Close settings" :disabled="editBusy" @click="editOpen = false"><X :size="18" /></button>
          </div>
          <div class="cd-edit-body">
            <label class="cd-edit-l" for="edit-community-name">Name</label>
            <input id="edit-community-name" v-model="edit.name" type="text" class="cd-edit-in" />
            <label class="cd-edit-l" for="edit-community-sport">Main interest</label>
            <input id="edit-community-sport" v-model="edit.sport" type="text" class="cd-edit-in" placeholder="Football, Padel…" />
            <label class="cd-edit-l" for="edit-community-description">Description</label>
            <textarea id="edit-community-description" v-model="edit.description" rows="4" class="cd-edit-in"></textarea>
            <div class="cd-edit-location"><span class="cd-edit-label">Where is it based?</span><CommunityLocationPicker :model-value="{ country_id: edit.country_id, city_id: edit.city_id }" id-prefix="edit-community-location" @update:model-value="v => { edit.country_id = v.country_id; edit.city_id = v.city_id }" /></div>
            <label class="cd-edit-switch">
              <input v-model="edit.is_private" type="checkbox" />
              <span><Lock :size="13" /> Private — approve every member</span>
            </label>
            <CommunityPermissions v-model="edit.settings" id="edit-community-permissions" /><p v-if="editError" class="journey-error" role="alert">{{ editError }}</p><button type="button" class="cd-edit-save" :disabled="editBusy" @click="saveEdit"><Check :size="15" /> {{ editBusy ? 'Saving…' : 'Save changes' }}</button>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
.cback { display: inline-flex; align-items: center; gap: 6px; color: var(--brand-ink, var(--brand)); text-decoration: none; font-weight: 700; font-size: 14px; margin-bottom: 14px; }
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
.cd-join.pending { background: var(--bg, #f1f5f9); color: var(--text-secondary, #475569); cursor: default; }
.cd-memberchip { display: inline-flex; align-items: center; gap: 6px; background: var(--success-bg, #dcfce7); color: var(--success-ink, #166534); font-size: 13px; font-weight: 800; padding: 8px 15px; border-radius: 999px; }
.cd-editbtn, .cd-leave { display: inline-flex; align-items: center; gap: 6px; background: none; border: 1.5px solid var(--border, #e2e8f0); color: var(--text-muted, #64748b); font: inherit; font-weight: 700; font-size: 13px; padding: 8px 14px; border-radius: 999px; cursor: pointer; }
.cd-chatbtn { position: relative; display: inline-flex; align-items: center; gap: 6px; background: #0d9488; border: none; color: #fff; font: inherit; font-weight: 800; font-size: 13px; padding: 8px 15px; border-radius: 999px; cursor: pointer; }
.cd-chatbtn-badge { min-width: 18px; height: 18px; padding: 0 5px; border-radius: 999px; background: #e11d48; color: #fff; font-size: 10.5px; font-weight: 800; display: inline-flex; align-items: center; justify-content: center; margin-left: 2px; }
.cd-leave { margin-left: auto; }
.cd-edit-location { margin: 14px 0 4px; }
.cd-edit-label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 8px; }
.cd-host-note, .cd-context-note { display: flex; align-items: flex-start; gap: 8px; font-size: 13px; line-height: 1.55; color: var(--text-muted, #64748b); background: var(--bg, #f1f5f9); border-radius: 10px; padding: 12px 14px; margin: 0 0 16px; }
.cd-host-note > svg, .cd-context-note > svg { flex-shrink: 0; margin-top: 2px; }
.cd-context-note { margin: 14px 0 0; font-size: 12.5px; }
/* Invite / share */
.cd-share { position: relative; }
.cd-sharebtn { display: inline-flex; align-items: center; gap: 6px; background: none; border: 1.5px solid var(--border, #e2e8f0); color: var(--text, #0f172a); font: inherit; font-weight: 700; font-size: 13px; padding: 8px 14px; border-radius: 999px; cursor: pointer; }
.cd-sharebtn:hover, .cd-sharebtn.on { border-color: var(--brand); color: var(--brand-ink, var(--brand)); }
.cd-share-menu { position: absolute; top: calc(100% + 8px); inset-inline-start: 0; z-index: 30; min-width: 270px; max-width: min(360px, calc(100vw - 32px)); background: var(--surface, #fff); border: 1px solid var(--border, #e2e8f0); border-radius: 12px; padding: 8px; box-shadow: 0 14px 36px rgba(15, 23, 42, .16); display: flex; flex-direction: column; gap: 2px; }
.cd-share-link { display: flex; align-items: center; gap: 8px; font-size: 12px; color: var(--text-muted, #64748b); padding: 8px 10px 10px; border-bottom: 1px solid var(--border, #e2e8f0); margin-bottom: 4px; }
.cd-share-link span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; min-width: 0; }
.cd-share-menu button { display: flex; align-items: center; gap: 10px; width: 100%; text-align: left; background: none; border: none; font: inherit; font-size: 14px; font-weight: 600; color: var(--text, #0f172a); padding: 10px 10px; border-radius: 8px; cursor: pointer; min-height: 44px; }
.cd-share-menu button:hover { background: var(--bg, #f1f5f9); color: var(--brand-ink, var(--brand)); }
.cd-share-menu p { margin: 4px 0 2px; padding: 0 10px; font-size: 12px; color: var(--text-muted, #64748b); line-height: 1.5; }
.cd-leave:hover { color: var(--danger-ink, #dc2626); border-color: var(--danger-border, #fecaca); }

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
.cd-composer textarea { width: 100%; box-sizing: border-box; border: none; outline: none; resize: vertical; font: inherit; font-size: 15px; background: none; color: var(--text, #0f172a); }
.cd-composer-pics { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 8px; }
.cd-composer-pic { position: relative; width: 64px; height: 64px; border-radius: 10px; background: var(--bg, #f1f5f9) center/cover; }
.cd-composer-pic button { position: absolute; top: -6px; right: -6px; width: 20px; height: 20px; border-radius: 50%; border: none; background: #0f172a; color: #fff; display: flex; align-items: center; justify-content: center; cursor: pointer; }
.cd-composer-bar { display: flex; align-items: center; justify-content: space-between; margin-top: 8px; }
.cd-composer-img { display: inline-flex; align-items: center; justify-content: center; width: 36px; height: 36px; border-radius: 10px; background: var(--bg, #f1f5f9); color: var(--text-muted, #64748b); cursor: pointer; }
.cd-composer-send { display: inline-flex; align-items: center; gap: 6px; background: var(--brand, #e11d48); color: #fff; font-weight: 800; font-size: 13.5px; padding: 9px 18px; border: none; border-radius: 999px; cursor: pointer; }
.cd-composer-send:disabled { opacity: .5; cursor: default; }

.cd-post { background: var(--surface, #ffffff); border: 1px solid var(--border, #e2e8f0); border-radius: 16px; padding: 14px 16px; margin-bottom: 12px; }
.cd-post-head { display: flex; align-items: center; gap: 10px; }
.cd-post-av { width: 36px; height: 36px; border-radius: 50%; background: var(--info-bg, #e0f2fe) center/cover; color: var(--info-ink, #0369a1); font-weight: 800; display: flex; align-items: center; justify-content: center; text-decoration: none; flex-shrink: 0; }
.cd-post-who { flex: 1; min-width: 0; display: flex; flex-direction: column; }
.cd-post-name { font-size: 14px; font-weight: 800; color: var(--text, #0f172a); text-decoration: none; }
.cd-post-time { font-size: 11.5px; color: var(--text-light, #94a3b8); }
.cd-post-del { background: none; border: none; color: var(--text-light, #94a3b8); cursor: pointer; padding: 6px; }
.cd-post-del:hover { color: var(--danger-ink, #dc2626); }
.cd-post-body { font-size: 14.5px; color: var(--text-secondary, #334155); line-height: 1.55; margin: 10px 0 0; white-space: pre-wrap; overflow-wrap: anywhere; }
.cd-post-pics { margin-top: 10px; border-radius: 12px; overflow: hidden; }
.cd-post-pics img { width: 100%; display: block; max-height: 420px; object-fit: cover; }
.cd-post-pics.multi { display: grid; grid-template-columns: 1fr 1fr; gap: 4px; }
.cd-post-pics.multi img { height: 180px; }

/* Meetups */
.cd-newmeet { display: inline-flex; align-items: center; gap: 7px; background: #059669; color: #fff; font-weight: 800; font-size: 14px; padding: 11px 18px; border-radius: 999px; text-decoration: none; margin-bottom: 16px; }
.cd-events-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(450px, 100%), 1fr)); gap: 16px; }

/* Members */
.cd-members { max-width: 560px; }
.cd-pending { background: var(--warning-bg, #fffbeb); border: 1px solid var(--warning-border, #fde68a); border-radius: 14px; padding: 10px 12px; margin-bottom: 14px; }
.cd-pending-h { font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: .04em; color: var(--warning-ink, #92400e); margin-bottom: 6px; }
.cd-member { display: flex; align-items: center; gap: 10px; padding: 8px 4px; }
.cd-member + .cd-member { border-top: 1px solid var(--border, #f1f5f9); }
.cd-member-av { width: 38px; height: 38px; border-radius: 50%; background: var(--info-bg, #e0f2fe) center/cover; color: var(--info-ink, #0369a1); font-weight: 800; display: flex; align-items: center; justify-content: center; text-decoration: none; flex-shrink: 0; }
.cd-member-name { flex: 1; min-width: 0; font-size: 14.5px; font-weight: 700; color: var(--text, #0f172a); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.cd-role { display: inline-flex; align-items: center; gap: 4px; font-size: 11px; font-weight: 800; text-transform: capitalize; padding: 3px 9px; border-radius: 999px; background: var(--violet-bg, #ede9fe); color: var(--violet-ink, #6d28d9); }
.cd-role.owner { background: var(--danger-bg, #ffe4e6); color: var(--danger-ink, #be123c); }
.cd-member-actions { display: flex; gap: 6px; }
.cd-mbtn { width: 34px; height: 34px; border-radius: 10px; border: none; display: flex; align-items: center; justify-content: center; cursor: pointer; }
.cd-mbtn.ok { background: #16a34a; color: #fff; }
.cd-mbtn.no { background: var(--danger-bg, #fee2e2); color: var(--danger-ink, #b91c1c); }
.cd-mbtn.ghost { background: var(--bg, #f1f5f9); color: var(--text-secondary, #475569); }

/* About */
.cd-about { max-width: 560px; }
.cd-about-desc { font-size: 15px; color: var(--text-secondary, #334155); line-height: 1.6; white-space: pre-wrap; }
.cd-about-desc.muted { color: var(--text-light, #94a3b8); }
.cd-about-rows { display: flex; flex-direction: column; gap: 10px; margin-top: 16px; }
.cd-about-row { display: flex; align-items: center; gap: 9px; font-size: 14px; color: var(--text-secondary, #475569); }
.cd-about-row svg { color: #0d9488; flex-shrink: 0; }

/* Chat FAB + sheet */
.cd-chatfab { position: fixed; right: 18px; bottom: 86px; z-index: 80; width: 54px; height: 54px; border-radius: 50%; border: none; background: #0d9488; color: #fff; display: flex; align-items: center; justify-content: center; cursor: pointer; box-shadow: 0 10px 26px rgba(13,148,136,.4); }
.cd-chatfab-badge { position: absolute; top: -3px; right: -3px; min-width: 20px; height: 20px; padding: 0 5px; border-radius: 999px; background: #e11d48; color: #fff; font-size: 11px; font-weight: 800; display: flex; align-items: center; justify-content: center; }
.cd-chat-back { position: fixed; inset: 0; z-index: 200; background: rgba(15,23,42,.5); display: flex; align-items: flex-end; justify-content: center; }
.cd-chat { width: 100%; max-width: 560px; background: var(--surface, #ffffff); border-radius: 20px 20px 0 0; display: flex; flex-direction: column; max-height: 82vh; }
.cd-chat-head { display: flex; align-items: center; justify-content: space-between; padding: 14px 16px; border-bottom: 1px solid var(--border, #e2e8f0); }
.cd-chat-head span { display: inline-flex; align-items: center; gap: 8px; font-weight: 800; font-size: 15px; color: var(--text, #0f172a); }
.cd-chat-lockchip { display: inline-flex; align-items: center; gap: 3px; font-size: 10.5px; font-weight: 800; text-transform: uppercase; letter-spacing: .03em; color: var(--warning-ink, #b45309); background: var(--warning-bg, #fef3c7); padding: 2px 8px; border-radius: 999px; }
.cd-chat-headright { display: inline-flex; align-items: center; gap: 4px; }
.cd-chat-lock { display: inline-flex; align-items: center; justify-content: center; width: 34px; height: 34px; border-radius: 10px; border: none; background: none; color: var(--text-muted, #64748b); cursor: pointer; }
.cd-chat-lock.on { background: var(--warning-bg, #fef3c7); color: var(--warning-ink, #b45309); }
.cd-chat-x { background: none; border: none; color: var(--text-muted, #64748b); cursor: pointer; padding: 4px; }
.cd-chat-readonly { display: flex; align-items: center; justify-content: center; gap: 7px; padding: 14px 16px calc(14px + env(safe-area-inset-bottom, 0px)); border-top: 1px solid var(--border, #e2e8f0); font-size: 13px; font-weight: 700; color: var(--text-muted, #64748b); background: var(--canvas, #f8fafc); }
.cd-chat-body { flex: 1; overflow-y: auto; padding: 14px 16px; min-height: 220px; }
.cd-chat-empty { text-align: center; color: var(--text-light, #94a3b8); font-size: 13.5px; padding: 30px 0; }
.cd-chat-msg { margin-bottom: 10px; display: flex; flex-direction: column; align-items: flex-start; }
.cd-chat-msg.mine { align-items: flex-end; }
.cd-chat-who { font-size: 11px; font-weight: 800; color: var(--text-light, #94a3b8); margin: 0 4px 2px; }
.cd-chat-bubble { max-width: 78%; background: var(--bg, #f1f5f9); color: var(--text, #0f172a); border-radius: 14px; padding: 9px 13px; font-size: 14px; line-height: 1.4; overflow-wrap: anywhere; white-space: pre-wrap; }
.cd-chat-msg.mine .cd-chat-bubble { background: #0d9488; color: #fff; }
.cd-chat-bar { display: flex; gap: 8px; padding: 10px 12px calc(10px + env(safe-area-inset-bottom, 0px)); border-top: 1px solid var(--border, #e2e8f0); }
.cd-chat-bar input { flex: 1; border: 1.5px solid var(--border, #e2e8f0); border-radius: 999px; padding: 11px 16px; font: inherit; font-size: 15px; outline: none; background: none; color: var(--text, #0f172a); }
.cd-chat-send { width: 44px; height: 44px; border-radius: 50%; border: none; background: #0d9488; color: #fff; display: flex; align-items: center; justify-content: center; cursor: pointer; flex-shrink: 0; }
.cd-chat-send:disabled { opacity: .5; }

/* Edit sheet */
.cd-edit-body { padding: 14px 16px calc(16px + env(safe-area-inset-bottom, 0px)); overflow-y: auto; }
.cd-edit-l { display: block; font-size: 12.5px; font-weight: 800; color: var(--text-secondary, #475569); margin: 10px 0 4px; }
.cd-edit-in { width: 100%; box-sizing: border-box; border: 1.5px solid var(--border, #e2e8f0); border-radius: 10px; padding: 10px 13px; font: inherit; font-size: 15px; outline: none; background: none; color: var(--text, #0f172a); }
.cd-edit-switch { display: flex; align-items: center; gap: 9px; margin: 14px 0; font-size: 14px; font-weight: 700; color: var(--text-secondary, #334155); cursor: pointer; }
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

/* Identity, conversation and context each have their own place. */
.community-detail { max-width: 1180px; margin-inline: auto; }
.cd-hero { border-radius: 16px; padding: 24px; background: var(--surface); color: var(--text); border: 1px solid var(--border); min-height: 180px; justify-content: flex-end; }
.cd-hero.cd-has-cover { min-height: 270px; color: #fff; background-size: cover; background-position: center; }
.cd-hero:not(.cd-has-cover) :is(h1,.cd-count) { color: var(--text); }.cd-idrow { gap: 18px; align-items: center; }.cd-idrow h1 { font-size: clamp(25px,3vw,36px); letter-spacing: -.03em; line-height: 1.2; }.cd-avatar { width: 76px; height: 76px; border-radius: 20px; border: 3px solid var(--surface); }.cd-avatar.fallback { display: grid; place-items: center; color: var(--brand-ink); background: var(--brand-tint,var(--bg)); }.cd-sport { background: var(--surface); color: var(--brand-ink); }.cd-hero-meta { margin-top: 16px; gap: 16px; }
.cd-joinbar { margin: 0; padding: 20px 0; gap: 10px; }.cd-chatbtn,.cd-join { background: var(--brand); border-radius: 9px; min-height: 44px; padding: 11px 18px; }.cd-editbtn,.cd-leave { min-height: 42px; border-radius: 9px; padding: 10px 14px; }.cd-memberchip { background: var(--brand-tint,var(--surface)); color: var(--brand-ink); font-size: 13px; }
.cd-tabs { gap: 24px; border-bottom: 1px solid var(--border); margin-bottom: 28px; overflow-x: auto; padding-bottom: 0; flex-wrap: nowrap; }.cd-tab { border: 0; border-bottom: 3px solid transparent; border-radius: 0; background: none; color: var(--text-muted); padding: 14px 2px; font-size: 14px; flex-shrink: 0; }.cd-tab.on { background: none; color: var(--brand-ink); border-bottom-color: var(--brand); }
.cd-feed-layout { display: grid; grid-template-columns: minmax(0,1fr) 300px; gap: 32px; align-items: start; }.cd-feed { max-width: none; min-width: 0; }.cd-section-title { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 16px; }.cd-section-title h2 { font-size: 19px; margin: 0; }.cd-section-title > span { font-size: 12px; color: var(--text-muted); }
.cd-context { border-inline-start: 1px solid var(--border); padding-inline-start: 26px; font-size: 14px; color: var(--text-secondary); line-height: 1.7; }.cd-context h2 { margin: 0 0 14px; font-size: 16px; color: var(--text); }.cd-context dl { border-block: 1px solid var(--border); padding: 16px 0; margin: 20px 0; }.cd-context dt { font-size: 12px; color: var(--text-muted); }.cd-context dd { margin: 2px 0 12px; color: var(--text); font-size: 13px; font-weight: 600; }.cd-context dd:last-child { margin-bottom: 0; }.cd-context-link { display: flex; align-items: center; gap: 8px; color: var(--brand-ink); text-decoration: none; font-weight: 650; }
.cd-composer { border-radius: 12px; padding: 16px; }.cd-composer textarea { min-height: 76px; font-size: 15px; }.cd-composer-send { min-height: 40px; border-radius: 8px; padding: 10px 18px; }.cd-post { border-radius: 12px; padding: 20px; }.cd-post-del { min-width: 40px; min-height: 40px; }.cd-readonly { display: flex; align-items: flex-start; gap: 9px; color: var(--text-secondary); background: var(--surface); padding: 16px; border: 1px solid var(--border); border-radius: 10px; font-size: 13px; line-height: 1.6; }.cd-readonly svg { flex-shrink: 0; margin-top: 3px; }
.cd-members { max-width: 820px; }.cd-member-help { color: var(--text-secondary); line-height: 1.7; font-size: 14px; margin-bottom: 18px; }.cd-member { padding: 16px 0; border-bottom: 1px solid var(--border); }.cd-member-actions select { max-width: 140px; border: 1px solid var(--border); border-radius: 8px; background: var(--surface); color: var(--text); padding: 10px; font: inherit; font-size: 13px; }.cd-member-name { min-width: 0; overflow-wrap: anywhere; }.cd-mbtn { min-width: 40px; min-height: 40px; }.cd-role.moderator { background: var(--brand-tint,var(--surface)); color: var(--brand-ink); }
.cd-chat.cd-edit { width: min(620px,100%); max-height: 90dvh; }.cd-edit-body { overflow-y: auto; padding: 24px; }.cd-edit-save { min-height: 46px; margin-top: 24px; background: var(--brand); border-radius: 9px; }.cd-edit-in { background: var(--bg); color: var(--text); border-color: var(--border); font-size: 16px; }.community-detail :is(button,a,input,textarea,select):focus-visible { outline: 2px solid var(--brand-ink); outline-offset: 3px; }
@media(max-width:760px) { .cd-feed-layout { grid-template-columns: 1fr; gap: 28px; }.cd-context { border-inline-start: 0; border-block-start: 1px solid var(--border); padding: 22px 0; }.cd-hero { padding: 20px; min-height: 170px; }.cd-hero.cd-has-cover { min-height: 235px; }.cd-avatar { width: 58px; height: 58px; border-radius: 15px; }.cd-idrow { gap: 12px; }.cd-idrow h1 { font-size: 25px; }.cd-joinbar { flex-wrap: wrap; }.cd-tabs { gap: 24px; margin-bottom: 22px; }.cd-tab { padding: 12px 0; }.cd-member { flex-wrap: wrap; gap: 10px; }.cd-member-actions { margin-inline-start: auto; }.cd-edit-body { padding: 20px; }.cd-hero-meta { font-size: 13px; } }
</style>
