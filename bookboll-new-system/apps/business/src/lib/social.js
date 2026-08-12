// social.js — marketplace social layer: profile strength, follows (with accept for
// private profiles), posts, and direct messages. Apex marketplace only.
//
// Access control for private profiles is enforced HERE (app layer), because storefront
// customers are a demo-grade email identity (no auth.uid()). Every function degrades
// gracefully (returns safe defaults) if the social migration hasn't been run yet.
import { ref } from 'vue'
import { supabase } from './supabase.js'

// ── Profile strength (drives the "complete your profile" nudge) ────────────────
// A simple 0–100 score from the things hosts look at when accepting a join request.
export function profileStrength(c) {
  if (!c) return { score: 0, missing: [], level: 'weak' }
  const checks = [
    { key: 'avatar', ok: !!(c.avatar_url || c.avatar), label: 'Add a profile photo' },
    { key: 'name',   ok: !!(c.full_name && c.full_name.trim() && !/@/.test(c.full_name)), label: 'Add your real name' },
    { key: 'bio',    ok: !!(c.bio && c.bio.trim().length >= 20), label: 'Write a short bio (20+ chars)' },
    { key: 'photos', ok: Array.isArray(c.photos) && c.photos.length > 0, label: 'Add a few photos' },
  ]
  const done = checks.filter(c => c.ok).length
  const score = Math.round((done / checks.length) * 100)
  return {
    score,
    missing: checks.filter(c => !c.ok).map(c => c.label),
    level: score >= 75 ? 'strong' : score >= 50 ? 'ok' : 'weak',
  }
}

// ── Follows ───────────────────────────────────────────────────────────────────
/** Viewer's relationship to a target: 'none' | 'pending' | 'following'. */
export async function loadFollowState(viewerId, targetId) {
  if (!viewerId || !targetId || viewerId === targetId) return 'none'
  try {
    const { data } = await supabase.from('customer_follows')
      .select('status').eq('follower_id', viewerId).eq('following_id', targetId).maybeSingle()
    if (!data) return 'none'
    return data.status === 'accepted' ? 'following' : 'pending'
  } catch { return 'none' }
}

/** Follow a user. Public targets are auto-accepted; private targets go 'pending'. */
export async function followUser(viewerId, target) {
  if (!viewerId || !target?.id) return { error: 'Not signed in' }
  const status = target.is_private || target.isPrivate ? 'pending' : 'accepted'
  try {
    const { error } = await supabase.from('customer_follows')
      .upsert({ follower_id: viewerId, following_id: target.id, status }, { onConflict: 'follower_id,following_id' })
    if (error) return { error: error.message }
    return { state: status === 'accepted' ? 'following' : 'pending' }
  } catch (e) { return { error: e.message } }
}

export async function unfollowUser(viewerId, targetId) {
  try { await supabase.from('customer_follows').delete().eq('follower_id', viewerId).eq('following_id', targetId) } catch { /* ignore */ }
  return { state: 'none' }
}

/** Accepted follower / following counts for a profile. */
export async function followCounts(targetId) {
  try {
    const [fr, fg] = await Promise.all([
      supabase.from('customer_follows').select('id', { count: 'exact', head: true }).eq('following_id', targetId).eq('status', 'accepted'),
      supabase.from('customer_follows').select('id', { count: 'exact', head: true }).eq('follower_id', targetId).eq('status', 'accepted'),
    ])
    return { followers: fr.count || 0, following: fg.count || 0 }
  } catch { return { followers: 0, following: 0 } }
}

/** Pending follow requests addressed to `ownerId` (for private accounts). */
export async function pendingFollowRequests(ownerId) {
  if (!ownerId) return []
  try {
    const { data } = await supabase.from('customer_follows')
      .select('id, follower_id, created_at').eq('following_id', ownerId).eq('status', 'pending').order('created_at', { ascending: false })
    const reqs = data || []
    if (!reqs.length) return []
    const { data: people } = await supabase.from('customers').select('id, full_name, avatar_url').in('id', reqs.map(r => r.follower_id))
    const byId = Object.fromEntries((people || []).map(p => [p.id, p]))
    return reqs.map(r => ({ id: r.id, createdAt: r.created_at, follower: byId[r.follower_id] || { id: r.follower_id, full_name: 'Player' } }))
  } catch { return [] }
}

/** Accepted followers or following list (people) for a profile. kind: 'followers'|'following'. */
export async function loadFollowList(ownerId, kind) {
  if (!ownerId) return []
  const onCol = kind === 'followers' ? 'following_id' : 'follower_id'
  const pickCol = kind === 'followers' ? 'follower_id' : 'following_id'
  try {
    const { data } = await supabase.from('customer_follows').select(pickCol).eq(onCol, ownerId).eq('status', 'accepted')
    const ids = (data || []).map(r => r[pickCol])
    if (!ids.length) return []
    const { data: people } = await supabase.from('customers').select('id, full_name, avatar_url').in('id', ids)
    return people || []
  } catch { return [] }
}

export async function respondFollow(requestId, accept) {
  try {
    if (accept) await supabase.from('customer_follows').update({ status: 'accepted' }).eq('id', requestId)
    else await supabase.from('customer_follows').delete().eq('id', requestId)
    return { ok: true }
  } catch (e) { return { error: e.message } }
}

/** Can `viewerId` see `target`'s private content (posts/photos/stats)? */
export async function canViewContent(viewerId, target) {
  if (!target) return false
  if (!(target.is_private ?? target.isPrivate)) return true       // public
  if (viewerId && viewerId === target.id) return true             // self
  if (!viewerId) return false
  return (await loadFollowState(viewerId, target.id)) === 'following'
}

// ── Posts ──────────────────────────────────────────────────────────────────────
export async function loadPosts(authorId) {
  if (!authorId) return []
  try {
    const { data } = await supabase.from('customer_posts')
      .select('*').eq('author_id', authorId).order('created_at', { ascending: false })
    return (data || []).map(p => ({ ...p, photos: Array.isArray(p.photos) ? p.photos.filter(Boolean) : [] }))
  } catch { return [] }
}

export async function createPost(authorId, { body, photos }) {
  if (!authorId) return { error: 'Not signed in' }
  const clean = (body || '').trim()
  const pics = (photos || []).filter(Boolean)
  if (!clean && !pics.length) return { error: 'Write something or add a photo.' }
  try {
    const { data, error } = await supabase.from('customer_posts')
      .insert({ author_id: authorId, body: clean, photos: pics }).select().maybeSingle()
    if (error) return { error: error.message }
    return { post: { ...data, photos: Array.isArray(data.photos) ? data.photos : pics } }
  } catch (e) { return { error: e.message } }
}

export async function deletePost(id) {
  try { await supabase.from('customer_posts').delete().eq('id', id); return { ok: true } }
  catch (e) { return { error: e.message } }
}

// ── Direct messages ─────────────────────────────────────────────────────────────
export function convoKey(a, b) { return [a, b].sort().join('__') }

/** Conversation list for `meId`: one row per peer with last message + unread count. */
export async function loadConversations(meId) {
  if (!meId) return []
  try {
    const { data } = await supabase.from('customer_messages')
      .select('*').or(`sender_id.eq.${meId},recipient_id.eq.${meId}`).order('created_at', { ascending: false })
    const rows = data || []
    const byPeer = new Map()
    for (const m of rows) {
      const peer = m.sender_id === meId ? m.recipient_id : m.sender_id
      if (!peer) continue   // group/broadcast rows (community chat) — listed separately, not as a 1:1 convo
      if (!byPeer.has(peer)) byPeer.set(peer, { peerId: peer, last: m, unread: 0 })
      if (m.recipient_id === meId && !m.read_at) byPeer.get(peer).unread++
    }
    const peers = [...byPeer.keys()]
    if (!peers.length) return []
    const { data: people } = await supabase.from('customers').select('id, full_name, avatar_url').in('id', peers)
    const byId = Object.fromEntries((people || []).map(p => [p.id, p]))
    return [...byPeer.values()].map(c => ({
      peerId: c.peerId,
      peer: byId[c.peerId] || { id: c.peerId, full_name: 'Player' },
      lastBody: c.last.body, lastAt: c.last.created_at,
      lastMine: c.last.sender_id === meId, unread: c.unread,
    })).sort((a, b) => new Date(b.lastAt) - new Date(a.lastAt))
  } catch { return [] }
}

export async function loadThread(meId, otherId) {
  if (!meId || !otherId) return []
  try {
    const { data } = await supabase.from('customer_messages')
      .select('*').eq('conversation_key', convoKey(meId, otherId)).order('created_at')
    return data || []
  } catch { return [] }
}

export async function sendMessage(meId, otherId, body) {
  const clean = (body || '').trim()
  if (!meId || !otherId || !clean) return { error: 'Empty message' }
  try {
    const { data, error } = await supabase.from('customer_messages').insert({
      conversation_key: convoKey(meId, otherId), sender_id: meId, recipient_id: otherId, body: clean,
    }).select().maybeSingle()
    if (error) return { error: error.message }
    return { message: data }
  } catch (e) { return { error: e.message } }
}

export async function markThreadRead(meId, otherId) {
  try {
    await supabase.from('customer_messages').update({ read_at: new Date().toISOString() })
      .eq('conversation_key', convoKey(meId, otherId)).eq('recipient_id', meId).is('read_at', null)
  } catch { /* ignore */ }
}

// Global unread badge (polled by the layout while signed in).
export const unreadMessages = ref(0)
export async function refreshUnread(meId) {
  if (!meId) { unreadMessages.value = 0; return }
  try {
    const { count } = await supabase.from('customer_messages')
      .select('id', { count: 'exact', head: true }).eq('recipient_id', meId).is('read_at', null)
    unreadMessages.value = count || 0
  } catch { unreadMessages.value = 0 }
}
