<script setup>
import { ref, computed, watch, onMounted, onUnmounted, nextTick } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { customer, isCustomer } from '../../lib/customerSession.js'
import { supabase } from '../../lib/supabase.js'
import { loadConversations, loadThread, sendMessage, markThreadRead, refreshUnread } from '../../lib/social.js'
import { groupKey, loadGroupThread, sendGroupMessage, markCommunityChatRead, communityChatUnread, myCommunities, isMember, canManage, loadCommunity, updateCommunity } from '../../customer/community.js'
import { useSeo } from '../../lib/seo.js'
import { fmt12 } from '../../lib/slots.js'
import { ArrowLeft, Send, MessageCircle, User, UsersRound, Lock, LockOpen } from 'lucide-vue-next'

useSeo({ title: 'Messages', noindex: true, path: '/messages' })

const route = useRoute()
const router = useRouter()
const me = computed(() => customer.value?.id || null)
// Return to wherever you opened Messages from; fall back to Home on a cold load.
function goBack() { if (window.history.state?.back) router.back(); else router.push('/') }

const convos = ref([])
const loadingConvos = ref(true)
const peerId = computed(() => route.params.id || null)
const peer = ref(null)
const thread = ref([])
const loadingThread = ref(false)
const draft = ref('')
const sending = ref(false)
const scroller = ref(null)
let poll = null

// ── Community group chats live in this inbox too (route id 'community__<id>') ──
const GROUP_PREFIX = 'community__'
const isGroup = computed(() => String(peerId.value || '').startsWith(GROUP_PREFIX))
const groupCid = computed(() => isGroup.value ? String(peerId.value).slice(GROUP_PREFIX.length) : null)
const groupInfo = ref(null)   // the community (+ viewer membership) behind the open group thread
const amGroupMember = computed(() => {
  const cm = groupInfo.value
  return !!cm && (isMember(cm.membership) || cm.owner_customer_id === me.value)
})
const amGroupManager = computed(() => {
  const cm = groupInfo.value
  return !!cm && (canManage(cm.membership) || cm.owner_customer_id === me.value)
})
const groupLocked = computed(() => !!groupInfo.value?.settings?.chat_locked)
const canPostGroup = computed(() => amGroupMember.value && (!groupLocked.value || amGroupManager.value))
// Announcements mode: managers can lock the chat so only they post.
async function toggleGroupLock() {
  if (!amGroupManager.value || !groupInfo.value) return
  const next = { ...(groupInfo.value.settings || {}), chat_locked: !groupLocked.value }
  const res = await updateCommunity(groupInfo.value.id, { settings: next })
  if (!res.error) groupInfo.value = { ...groupInfo.value, settings: next }
}

async function loadList() {
  loadingConvos.value = true
  const [direct, groups] = await Promise.all([loadConversations(me.value), loadGroupList()])
  convos.value = [...groups, ...direct].sort((a, b) => new Date(b.lastAt || 0) - new Date(a.lastAt || 0))
  loadingConvos.value = false
}
// One inbox row per community the viewer belongs to — like any other conversation.
async function loadGroupList() {
  try {
    const clubs = (await myCommunities(me.value)).filter(c => isMember(c.membership))
    if (!clubs.length) return []
    const keys = clubs.map(c => groupKey('community', c.id))
    const { data } = await supabase.from('customer_messages')
      .select('conversation_key, sender_id, body, created_at')
      .in('conversation_key', keys).order('created_at', { ascending: false }).limit(400)
    const lastByKey = {}
    for (const m of (data || [])) if (!lastByKey[m.conversation_key]) lastByKey[m.conversation_key] = m
    return await Promise.all(clubs.map(async c => {
      const key = groupKey('community', c.id)
      const last = lastByKey[key] || null
      return {
        group: true, peerId: key, communityId: c.id,
        peer: { full_name: c.name, avatar_url: c.avatar_image || c.cover_image || '' },
        lastBody: last ? last.body : 'Community group chat',
        lastAt: last?.created_at || c.created_at || null,
        lastMine: !!last && last.sender_id === me.value,
        unread: await communityChatUnread(c.id, c.membership),
      }
    }))
  } catch { return [] }
}
async function fetchPeer(id) {
  const { data } = await supabase.from('customers').select('id, full_name, avatar_url').eq('id', id).maybeSingle()
  peer.value = data || { id, full_name: 'Player' }
}
async function openThread() {
  if (!peerId.value || !me.value) { peer.value = null; thread.value = []; groupInfo.value = null; return }
  loadingThread.value = true
  if (isGroup.value) {
    const cm = await loadCommunity(groupCid.value, me.value)
    groupInfo.value = cm
    peer.value = cm
      ? { id: cm.id, full_name: cm.name, avatar_url: cm.avatar_image || cm.cover_image || '' }
      : { id: groupCid.value, full_name: 'Community' }
    thread.value = cm ? await loadGroupThread(groupKey('community', groupCid.value)) : []
    loadingThread.value = false
    if (amGroupMember.value) markCommunityChatRead(groupCid.value, me.value)
  } else {
    groupInfo.value = null
    await fetchPeer(peerId.value)
    thread.value = await loadThread(me.value, peerId.value)
    loadingThread.value = false
    await markThreadRead(me.value, peerId.value)
    refreshUnread(me.value)
  }
  // reflect read state in the list
  const c = convos.value.find(c => c.peerId === peerId.value); if (c) c.unread = 0
  await nextTick(); scrollDown()
}
function scrollDown() { if (scroller.value) scroller.value.scrollTop = scroller.value.scrollHeight }

async function send() {
  const body = draft.value.trim()
  if (!body || sending.value || !peerId.value) return
  sending.value = true
  if (isGroup.value) {
    if (!canPostGroup.value) { sending.value = false; return }
    const r = await sendGroupMessage(groupKey('community', groupCid.value), me.value, body)
    sending.value = false
    if (r.error) return
    draft.value = ''
    thread.value.push({ ...r.message, senderName: customer.value?.full_name || 'You', senderAvatar: customer.value?.avatar_url || '' })
  } else {
    const r = await sendMessage(me.value, peerId.value, body)
    sending.value = false
    if (r.error) return
    draft.value = ''
    thread.value.push(r.message)
  }
  await nextTick(); scrollDown()
  loadList()
}

function fmt(iso) { return fmt12(new Date(iso)) }
function dayLabel(iso) { return new Date(iso).toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' }) }
function showDay(i) { if (i === 0) return true; return new Date(thread.value[i].created_at).toDateString() !== new Date(thread.value[i - 1].created_at).toDateString() }
function initial(n) { return (n || '?').trim()[0]?.toUpperCase() || '?' }

onMounted(() => {
  if (!isCustomer.value) return
  loadList(); openThread()
  // Light polling so a new incoming message appears without a manual refresh.
  poll = setInterval(async () => {
    if (peerId.value) {
      if (isGroup.value) {
        if (groupInfo.value) { thread.value = await loadGroupThread(groupKey('community', groupCid.value)); if (amGroupMember.value) markCommunityChatRead(groupCid.value, me.value) }
      } else {
        thread.value = await loadThread(me.value, peerId.value); await markThreadRead(me.value, peerId.value)
      }
    }
    loadList(); refreshUnread(me.value)
  }, 12000)
})
onUnmounted(() => { if (poll) clearInterval(poll) })
watch(peerId, openThread)
</script>

<template>
  <div class="ms">
    <template v-if="!isCustomer">
      <div class="ms-signin">
        <MessageCircle :size="34" />
        <h1>Your messages</h1>
        <p>Sign in to message other players and hosts.</p>
        <router-link to="/account" class="ms-signin-btn">Sign in</router-link>
      </div>
    </template>

    <template v-else>
      <div class="ms-wrap" :class="{ 'has-thread': peerId }">
        <!-- Conversation list -->
        <aside class="ms-list">
          <div class="ms-h-row">
            <button type="button" class="ms-topback" @click="goBack" aria-label="Back"><ArrowLeft :size="18" /></button>
            <h1 class="ms-h">Messages</h1>
          </div>
          <div v-if="loadingConvos" class="ms-msg">Loading…</div>
          <div v-else-if="!convos.length" class="ms-msg">No conversations yet. Visit a player's profile and tap <b>Message</b> to start one.</div>
          <router-link v-for="c in convos" :key="c.peerId" :to="`/messages/${c.peerId}`" class="ms-convo" :class="{ active: c.peerId === peerId }" data-no-translate>
            <span class="ms-av" :class="{ img: c.peer.avatar_url, grp: c.group }" :style="c.peer.avatar_url ? { backgroundImage: `url(${c.peer.avatar_url})` } : {}">
              <UsersRound v-if="c.group && !c.peer.avatar_url" :size="18" />
              <span v-else-if="!c.peer.avatar_url">{{ initial(c.peer.full_name) }}</span>
            </span>
            <span class="ms-convo-mid">
              <span class="ms-convo-name">{{ c.peer.full_name || 'Player' }}<em v-if="c.group" class="ms-grp-tag">Community</em></span>
              <span class="ms-convo-last">{{ c.lastMine ? 'You: ' : '' }}{{ c.lastBody }}</span>
            </span>
            <span v-if="c.unread" class="ms-badge">{{ c.unread }}</span>
          </router-link>
        </aside>

        <!-- Thread -->
        <section class="ms-thread">
          <template v-if="peerId">
            <div class="ms-thread-head">
              <router-link to="/messages" class="ms-back"><ArrowLeft :size="18" /></router-link>
              <router-link v-if="peer" :to="isGroup ? `/communities/${groupCid}` : `/u/${peer.id}`" class="ms-peer" data-no-translate>
                <span class="ms-av sm" :class="{ img: peer.avatar_url, grp: isGroup }" :style="peer.avatar_url ? { backgroundImage: `url(${peer.avatar_url})` } : {}">
                  <UsersRound v-if="isGroup && !peer.avatar_url" :size="15" />
                  <span v-else-if="!peer.avatar_url">{{ initial(peer.full_name) }}</span>
                </span>
                <span class="ms-peer-mid">
                  <b>{{ peer.full_name || 'Player' }}</b>
                  <small v-if="isGroup">Community group chat — everyone in {{ peer.full_name }} sees this</small>
                </span>
              </router-link>
              <button v-if="isGroup && amGroupManager" type="button" class="ms-lock" :class="{ on: groupLocked }" @click="toggleGroupLock"
                :title="groupLocked ? 'Unlock — let members post again' : 'Lock — only admins can post (announcements)'">
                <component :is="groupLocked ? Lock : LockOpen" :size="16" />
              </button>
            </div>
            <div ref="scroller" class="ms-stream" data-no-translate>
              <div v-if="loadingThread" class="ms-msg">Loading…</div>
              <div v-else-if="isGroup && groupInfo && !amGroupMember" class="ms-msg">Only members of {{ peer?.full_name || 'this community' }} can read the group chat. <router-link :to="`/communities/${groupCid}`">Visit the community to join →</router-link></div>
              <div v-else-if="!thread.length" class="ms-msg">{{ isGroup ? (groupLocked ? 'Only admins post here — announcements will appear in this space.' : 'Say hi — every member sees this chat.') : 'Say hi 👋' }}</div>
              <template v-else v-for="(m, i) in thread" :key="m.id">
                <div v-if="showDay(i)" class="ms-day">{{ dayLabel(m.created_at) }}</div>
                <div class="ms-row" :class="{ mine: m.sender_id === me }">
                  <!-- Group chats: every sender's photo, tap → their profile -->
                  <router-link v-if="isGroup && m.sender_id !== me" :to="`/u/${m.sender_id}`" class="ms-mav" :class="{ img: m.senderAvatar }" :style="m.senderAvatar ? { backgroundImage: `url(${m.senderAvatar})` } : {}" :title="`${m.senderName} — view profile`">
                    <span v-if="!m.senderAvatar">{{ initial(m.senderName) }}</span>
                  </router-link>
                  <div class="ms-bubble" :class="{ mine: m.sender_id === me }">
                    <router-link v-if="isGroup && m.sender_id !== me" :to="`/u/${m.sender_id}`" class="ms-who">{{ m.senderName }}</router-link>
                    <span class="ms-bubble-body">{{ m.body }}</span>
                    <span class="ms-bubble-time">{{ fmt(m.created_at) }}</span>
                  </div>
                </div>
              </template>
            </div>
            <div v-if="!isGroup || canPostGroup" class="ms-compose">
              <input v-model="draft" :placeholder="isGroup ? (groupLocked ? 'Post an announcement…' : `Message ${peer?.full_name || 'the community'}…`) : 'Write a message…'" @keyup.enter="send" />
              <button :disabled="sending || !draft.trim()" @click="send"><Send :size="18" /></button>
            </div>
            <div v-else-if="amGroupMember" class="ms-readonly"><Lock :size="14" /> Only admins can post here — you can read every message.</div>
          </template>
          <div v-else class="ms-empty"><MessageCircle :size="40" /><span>Select a conversation</span></div>
        </section>
      </div>
    </template>
  </div>
</template>

<style scoped>
.ms-signin { text-align: center; color: var(--text-light, #94a3b8); padding: 60px 20px; }
.ms-signin svg { color: var(--brand); }
.ms-signin h1 { font-size: 22px; font-weight: 800; color: #0f172a; margin: 12px 0 6px; }
.ms-signin-btn { display: inline-block; margin-top: 14px; background: var(--brand); color: #fff; font-weight: 800; padding: 11px 24px; border-radius: 999px; text-decoration: none; }

.ms-wrap { display: grid; grid-template-columns: 320px 1fr; gap: 16px; height: calc(100vh - 220px); min-height: 460px; }
.ms-list { background: var(--surface, #ffffff); border: 1px solid var(--border, #e2e8f0); border-radius: 16px; overflow-y: auto; padding: 8px; }
.ms-h-row { display: flex; align-items: center; gap: 4px; margin: 8px 6px 10px; }
.ms-topback { display: inline-flex; align-items: center; justify-content: center; width: 34px; height: 34px; border-radius: 10px; border: none; background: none; color: var(--text-muted, #64748b); cursor: pointer; flex-shrink: 0; }
.ms-topback:hover { color: var(--brand); background: color-mix(in srgb, var(--brand) 10%, transparent); }
.ms-h { font-size: 18px; font-weight: 800; color: #0f172a; margin: 0; }
.ms-msg { padding: 20px; color: var(--text-light, #94a3b8); font-size: 13.5px; line-height: 1.5; }
.ms-convo { display: flex; align-items: center; gap: 11px; padding: 10px 11px; border-radius: 12px; text-decoration: none; }
.ms-convo:hover { background: #f8fafc; }
.ms-convo.active { background: color-mix(in srgb, var(--brand) 8%, white); }
.ms-av { width: 44px; height: 44px; border-radius: 50%; background: linear-gradient(135deg,var(--brand-accent),var(--brand)); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 16px; flex-shrink: 0; }
.ms-av.img { background-size: cover; background-position: center; }
.ms-av.sm { width: 36px; height: 36px; font-size: 13px; }
.ms-convo-mid { min-width: 0; flex: 1; display: flex; flex-direction: column; }
.ms-convo-name { font-size: 14.5px; font-weight: 800; color: #0f172a; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ms-convo-last { font-size: 12.5px; color: var(--text-light, #94a3b8); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ms-badge { background: var(--brand); color: #fff; font-size: 11px; font-weight: 800; border-radius: 999px; min-width: 20px; height: 20px; display: flex; align-items: center; justify-content: center; padding: 0 6px; flex-shrink: 0; }

.ms-thread { background: var(--surface, #ffffff); border: 1px solid var(--border, #e2e8f0); border-radius: 16px; display: flex; flex-direction: column; overflow: hidden; }
.ms-thread-head { display: flex; align-items: center; gap: 10px; padding: 12px 16px; border-bottom: 1px solid var(--border, #e2e8f0); }
.ms-back { display: none; color: var(--text-muted, #64748b); }
.ms-peer { display: flex; align-items: center; gap: 10px; text-decoration: none; color: #0f172a; font-size: 15px; }
.ms-stream { flex: 1; overflow-y: auto; padding: 16px; display: flex; flex-direction: column; gap: 6px; }
.ms-day { text-align: center; font-size: 11.5px; color: var(--text-light, #94a3b8); font-weight: 700; margin: 10px 0 4px; }
.ms-row { display: flex; align-items: flex-end; gap: 8px; }
.ms-row.mine { justify-content: flex-end; }
/* Group chats: sender avatar beside the bubble — tap to open their profile */
.ms-mav { width: 30px; height: 30px; border-radius: 50%; background: linear-gradient(135deg,var(--brand-accent),var(--brand)); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 12px; flex-shrink: 0; text-decoration: none; margin-bottom: 2px; }
.ms-mav.img { background-size: cover; background-position: center; }
.ms-mav:hover { box-shadow: 0 0 0 2px color-mix(in srgb, var(--brand) 40%, transparent); }
.ms-who { display: block; font-size: 11.5px; font-weight: 800; color: var(--brand); text-decoration: none; margin-bottom: 2px; }
.ms-who:hover { text-decoration: underline; }
.ms-bubble { max-width: 75%; background: #f1f5f9; color: #0f172a; padding: 9px 13px; border-radius: 16px 16px 16px 4px; font-size: 14.5px; line-height: 1.4; }
.ms-bubble.mine { background: var(--brand); color: #fff; border-radius: 16px 16px 4px 16px; }
.ms-grp-tag { font-style: normal; font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: .05em; color: var(--brand); background: color-mix(in srgb, var(--brand) 10%, white); padding: 2px 7px; border-radius: 999px; margin-inline-start: 6px; }
.ms-av.grp { background: linear-gradient(135deg,#0d9488,#0f766e); }
.ms-peer-mid { display: flex; flex-direction: column; min-width: 0; }
.ms-peer-mid small { font-size: 11.5px; color: var(--text-light, #94a3b8); font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ms-lock { margin-left: auto; display: inline-flex; align-items: center; justify-content: center; width: 36px; height: 36px; border-radius: 10px; border: 1.5px solid var(--border, #e2e8f0); background: none; color: var(--text-muted, #64748b); cursor: pointer; flex-shrink: 0; }
.ms-lock.on { border-color: var(--brand); color: var(--brand); background: color-mix(in srgb, var(--brand) 8%, white); }
.ms-readonly { display: flex; align-items: center; justify-content: center; gap: 7px; padding: 13px; border-top: 1px solid var(--border, #e2e8f0); font-size: 13px; font-weight: 600; color: var(--text-muted, #64748b); }
.ms-bubble-body { white-space: pre-wrap; word-break: break-word; }
.ms-bubble-time { display: block; font-size: 10px; opacity: .6; margin-top: 3px; text-align: right; }
.ms-compose { display: flex; gap: 10px; padding: 12px 14px; border-top: 1px solid var(--border, #e2e8f0); }
.ms-compose input { flex: 1; border: 1.5px solid var(--border, #e2e8f0); border-radius: 999px; padding: 11px 16px; font-size: 14.5px; outline: none; color: #0f172a; }
.ms-compose input:focus { border-color: var(--brand-accent); }
.ms-compose button { width: 44px; height: 44px; border-radius: 50%; border: none; background: var(--brand); color: #fff; display: flex; align-items: center; justify-content: center; cursor: pointer; flex-shrink: 0; }
.ms-compose button:disabled { opacity: .5; cursor: not-allowed; }
.ms-empty { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 12px; color: #cbd5e1; }

@media (max-width: 760px) {
  .ms-wrap { grid-template-columns: 1fr; height: auto; }
  .ms-wrap.has-thread .ms-list { display: none; }
  .ms-wrap:not(.has-thread) .ms-thread { display: none; }
  /* Reserve room for the header + floating bottom nav (12px offset + ~88px clearance)
     so the compose bar stays in view without page scroll. 100vh fallback first. */
  .ms-thread { height: calc(100vh - 240px); height: calc(100dvh - 240px); min-height: 0; }
  .ms-back {
    display: inline-flex;
    padding: 8px;
    margin: -8px 0 -8px -8px;
    min-width: 44px;
    min-height: 44px;
    align-items: center;
    justify-content: center;
  }
  .ms-thread-head { padding: 8px 12px; }
  .ms-stream { padding: 12px; }
  .ms-bubble { max-width: 85%; }
  .ms-compose { padding-bottom: calc(12px + env(safe-area-inset-bottom)); }
  .ms-compose input { font-size: 16px; }
  /* Pass-2 type scale: legible bubble timestamps, proper page h1, card-title band */
  .ms-bubble-time { font-size: 11px; }
  .ms-h { font-size: 20px; line-height: 1.2; }
  .ms-convo-name { font-size: 15px; }
  /* 360px: long unbroken peer names must ellipsize, not widen the thread head */
  .ms-peer { min-width: 0; flex: 1; }
  .ms-peer b { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; min-width: 0; display: block; }
}
</style>
