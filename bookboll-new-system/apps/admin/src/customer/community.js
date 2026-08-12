/**
 * community.js — the COMMUNITY module's data layer: clubs customers create, browse,
 * join and post in, each with a member list, a feed, a group chat and meetups.
 *
 * Meetups are hosted_games rows carrying a community_id, so a community's events
 * reuse the ENTIRE events machinery (join requests, waitlist auto-promotion,
 * payments, court bookings, tournaments, moderation) — see data.js.
 *
 * Access control is app-layer (storefront customers are a demo-grade identity with
 * no auth.uid(); RLS is permissive like every customer table). Every function
 * degrades gracefully pre-migration: probeCommunities() gates the module, and all
 * reads return safe defaults on error.
 */
import { ref } from 'vue'
import { supabase } from '../lib/supabase.js'

// ── Feature gate (success-only cache, like probeTournaments) ───────────────────
export const communitiesEnabled = ref(null)
export async function probeCommunities() {
  if (communitiesEnabled.value === true) return true
  // Probe a COLUMN (a missing table's HEAD probe can falsely succeed; a missing
  // column reliably errors). is_private ships with the table, so one probe gates all.
  const r = await supabase.from('communities').select('is_private').limit(1)
  communitiesEnabled.value = !r.error
  return communitiesEnabled.value
}

const visible = (c) => (c?.moderation_status || 'active') !== 'removed'

// ── Browse / read ───────────────────────────────────────────────────────────────
/** Approved communities for the browse page, with member counts. */
export async function loadCommunities({ cityId = null, sport = '', q = '' } = {}) {
  try {
    let query = supabase.from('communities').select('*').order('created_at', { ascending: false })
    if (cityId) query = query.eq('city_id', cityId)
    const { data, error } = await query
    if (error) return []
    let rows = (data || []).filter(visible)
    if (sport) rows = rows.filter(c => (c.sport || '').toLowerCase() === sport.toLowerCase() ||
      (Array.isArray(c.sports) && c.sports.some(s => String(s).toLowerCase() === sport.toLowerCase())))
    if (q) {
      const t = q.trim().toLowerCase()
      rows = rows.filter(c => (c.name || '').toLowerCase().includes(t) || (c.description || '').toLowerCase().includes(t))
    }
    return await decorateCounts(rows)
  } catch { return [] }
}

// Batched member counts (accepted only), _decorateGames-style.
async function decorateCounts(rows) {
  if (!rows.length) return rows
  try {
    const ids = rows.map(c => c.id)
    const { data } = await supabase.from('community_members')
      .select('community_id').in('community_id', ids).eq('status', 'accepted')
    const counts = {}
    for (const m of (data || [])) counts[m.community_id] = (counts[m.community_id] || 0) + 1
    return rows.map(c => ({ ...c, memberCount: counts[c.id] || 0 }))
  } catch { return rows.map(c => ({ ...c, memberCount: 0 })) }
}

/** One community + the viewer's membership row (null if none) + member count. */
export async function loadCommunity(id, viewerId = null) {
  try {
    const { data: c } = await supabase.from('communities').select('*').eq('id', id).maybeSingle()
    if (!c || !visible(c)) return null
    const [withCount] = await decorateCounts([c])
    let membership = null
    if (viewerId) {
      const { data: m } = await supabase.from('community_members')
        .select('*').eq('community_id', id).eq('customer_id', viewerId).maybeSingle()
      membership = m || null
    }
    return { ...withCount, membership }
  } catch { return null }
}

/** Communities the viewer belongs to (owned first). */
export async function myCommunities(customerId) {
  if (!customerId) return []
  try {
    const { data: mems } = await supabase.from('community_members')
      .select('community_id, role, status').eq('customer_id', customerId)
    const ids = (mems || []).map(m => m.community_id)
    if (!ids.length) return []
    const { data } = await supabase.from('communities').select('*').in('id', ids)
    const byId = Object.fromEntries((mems || []).map(m => [m.community_id, m]))
    const rows = (data || []).filter(visible).map(c => ({ ...c, membership: byId[c.id] }))
    const decorated = await decorateCounts(rows)
    return decorated.sort((a, b) =>
      (a.membership?.role === 'owner' ? 0 : 1) - (b.membership?.role === 'owner' ? 0 : 1) ||
      (a.name || '').localeCompare(b.name || ''))
  } catch { return [] }
}

/** Members with names/avatars, owner/admins first, pending last. */
export async function loadMembers(communityId) {
  try {
    const { data: mems } = await supabase.from('community_members')
      .select('*').eq('community_id', communityId).order('joined_at')
    const rows = mems || []
    if (!rows.length) return []
    const { data: people } = await supabase.from('customers')
      .select('id, full_name, avatar_url').in('id', rows.map(m => m.customer_id))
    const byId = Object.fromEntries((people || []).map(p => [p.id, p]))
    const rank = { owner: 0, admin: 1, member: 2 }
    return rows.map(m => ({
      ...m,
      name: byId[m.customer_id]?.full_name || 'Player',
      avatar: byId[m.customer_id]?.avatar_url || '',
    })).sort((a, b) =>
      (a.status === 'pending' ? 1 : 0) - (b.status === 'pending' ? 1 : 0) ||
      (rank[a.role] ?? 2) - (rank[b.role] ?? 2) || new Date(a.joined_at) - new Date(b.joined_at))
  } catch { return [] }
}

// ── Create / update / membership writes ─────────────────────────────────────────
export async function createCommunity(ownerId, fields) {
  if (!ownerId) return { error: 'Not signed in' }
  const name = (fields.name || '').trim()
  if (!name) return { error: 'Give your community a name.' }
  try {
    const { data, error } = await supabase.from('communities').insert({
      owner_customer_id: ownerId, name,
      description: (fields.description || '').trim(),
      sport: (fields.sport || '').trim(), sports: fields.sports || [],
      city_id: fields.city_id || null, country_id: fields.country_id || null,
      cover_image: fields.cover_image || '', images: fields.images || [],
      is_private: !!fields.is_private,
    }).select().maybeSingle()
    if (error || !data) return { error: error?.message || 'Could not create the community.' }
    // Profile photo — separate best-effort write so creation still works before
    // the avatar_image migration is applied (missing column = photo just skipped).
    if (fields.avatar_image) {
      try { await supabase.from('communities').update({ avatar_image: fields.avatar_image }).eq('id', data.id); data.avatar_image = fields.avatar_image } catch { /* pre-migration */ }
    }
    // The creator is the owner-member (their membership row powers roles + chat cursor).
    await supabase.from('community_members').insert({
      community_id: data.id, customer_id: ownerId, role: 'owner', status: 'accepted',
    })
    return { community: data }
  } catch (e) { return { error: e.message } }
}

const EDITABLE = ['name', 'description', 'sport', 'sports', 'cover_image', 'avatar_image', 'images', 'is_private', 'city_id', 'country_id', 'settings']
export async function updateCommunity(id, patch) {
  const clean = {}
  for (const k of EDITABLE) if (k in patch) clean[k] = patch[k]
  if (!Object.keys(clean).length) return { ok: true }
  try {
    const { error } = await supabase.from('communities').update(clean).eq('id', id)
    return error ? { error: error.message } : { ok: true }
  } catch (e) { return { error: e.message } }
}

/** Join (public → accepted instantly; private → pending until approved). */
export async function joinCommunity(community, customerId) {
  if (!customerId) return { error: 'Not signed in' }
  const status = community.is_private ? 'pending' : 'accepted'
  try {
    const { data, error } = await supabase.from('community_members')
      .upsert({ community_id: community.id, customer_id: customerId, role: 'member', status },
        { onConflict: 'community_id,customer_id' })
      .select().maybeSingle()
    if (error) return { error: error.message }
    return { membership: data || { status } }
  } catch (e) { return { error: e.message } }
}

export async function leaveCommunity(communityId, customerId) {
  try {
    // The owner can't leave their own community (transfer/delete instead).
    const { data: m } = await supabase.from('community_members')
      .select('id, role').eq('community_id', communityId).eq('customer_id', customerId).maybeSingle()
    if (m?.role === 'owner') return { error: "You own this community — you can't leave it." }
    if (m) await supabase.from('community_members').delete().eq('id', m.id)
    return { ok: true }
  } catch (e) { return { error: e.message } }
}

export async function respondToMember(memberId, accept) {
  try {
    if (accept) await supabase.from('community_members').update({ status: 'accepted' }).eq('id', memberId)
    else await supabase.from('community_members').delete().eq('id', memberId)
    return { ok: true }
  } catch (e) { return { error: e.message } }
}

export async function removeMember(memberId) {
  try { await supabase.from('community_members').delete().eq('id', memberId); return { ok: true } }
  catch (e) { return { error: e.message } }
}

export async function setMemberRole(memberId, role) {
  if (!['admin', 'member'].includes(role)) return { error: 'Invalid role' }
  try {
    const { error } = await supabase.from('community_members').update({ role }).eq('id', memberId)
    return error ? { error: error.message } : { ok: true }
  } catch (e) { return { error: e.message } }
}

/** Can this membership row manage the community (approve members, edit, delete posts)? */
export function canManage(membership) {
  return !!membership && membership.status === 'accepted' && ['owner', 'admin'].includes(membership.role)
}
export function isMember(membership) { return !!membership && membership.status === 'accepted' }

// ── Feed ────────────────────────────────────────────────────────────────────────
export async function loadCommunityFeed(communityId) {
  try {
    const { data } = await supabase.from('community_posts')
      .select('*').eq('community_id', communityId).order('created_at', { ascending: false }).limit(100)
    const rows = (data || []).filter(visible)
    if (!rows.length) return []
    const { data: people } = await supabase.from('customers')
      .select('id, full_name, avatar_url').in('id', [...new Set(rows.map(p => p.author_id))])
    const byId = Object.fromEntries((people || []).map(p => [p.id, p]))
    return rows.map(p => ({
      ...p,
      photos: Array.isArray(p.photos) ? p.photos.filter(Boolean) : [],
      authorName: byId[p.author_id]?.full_name || 'Player',
      authorAvatar: byId[p.author_id]?.avatar_url || '',
    }))
  } catch { return [] }
}

export async function createCommunityPost(communityId, authorId, { body, photos }) {
  if (!authorId) return { error: 'Not signed in' }
  const clean = (body || '').trim()
  const pics = (photos || []).filter(Boolean)
  if (!clean && !pics.length) return { error: 'Write something or add a photo.' }
  try {
    const { data, error } = await supabase.from('community_posts')
      .insert({ community_id: communityId, author_id: authorId, body: clean, photos: pics })
      .select().maybeSingle()
    if (error) return { error: error.message }
    return { post: data }
  } catch (e) { return { error: e.message } }
}

export async function deleteCommunityPost(id) {
  try { await supabase.from('community_posts').delete().eq('id', id); return { ok: true } }
  catch (e) { return { error: e.message } }
}

// ── Group chat (reuses customer_messages with a group conversation_key) ─────────
export function groupKey(kind, id) { return `${kind}__${id}` }   // 'community__<id>' | 'meet__<id>'

export async function loadGroupThread(key, limit = 200) {
  try {
    const { data } = await supabase.from('customer_messages')
      .select('*').eq('conversation_key', key).order('created_at').limit(limit)
    const rows = data || []
    if (!rows.length) return []
    const { data: people } = await supabase.from('customers')
      .select('id, full_name, avatar_url').in('id', [...new Set(rows.map(m => m.sender_id))])
    const byId = Object.fromEntries((people || []).map(p => [p.id, p]))
    return rows.map(m => ({
      ...m,
      senderName: byId[m.sender_id]?.full_name || 'Player',
      senderAvatar: byId[m.sender_id]?.avatar_url || '',
    }))
  } catch { return [] }
}

export async function sendGroupMessage(key, senderId, body) {
  const clean = (body || '').trim()
  if (!senderId || !clean) return { error: 'Empty message' }
  try {
    const { data, error } = await supabase.from('customer_messages')
      .insert({ conversation_key: key, sender_id: senderId, recipient_id: null, body: clean })
      .select().maybeSingle()
    if (error) return { error: error.message }
    return { message: data }
  } catch (e) { return { error: e.message } }
}

/** Community-chat read cursor lives on the viewer's membership row. */
export async function markCommunityChatRead(communityId, customerId) {
  try {
    await supabase.from('community_members').update({ last_read_at: new Date().toISOString() })
      .eq('community_id', communityId).eq('customer_id', customerId)
  } catch { /* ignore */ }
}

/** Unread community-chat count for the badge on the chat button. */
export async function communityChatUnread(communityId, membership) {
  if (!isMember(membership)) return 0
  try {
    let q = supabase.from('customer_messages')
      .select('id', { count: 'exact', head: true })
      .eq('conversation_key', groupKey('community', communityId))
      .neq('sender_id', membership.customer_id)
    if (membership.last_read_at) q = q.gt('created_at', membership.last_read_at)
    const { count } = await q
    return count || 0
  } catch { return 0 }
}

// ── Meetup linkage (best-effort writer, applyCharge pattern) ────────────────────
export async function applyCommunityId(gameId, communityId, isPublic = false) {
  if (!gameId || !communityId) return
  // community_public opts a community event into the public Events marketplace.
  try { await supabase.from('hosted_games').update({ community_id: communityId, community_public: !!isPublic }).eq('id', gameId) }
  catch {
    // Pre-migration fallback: the community_public column may not exist yet.
    try { await supabase.from('hosted_games').update({ community_id: communityId }).eq('id', gameId) } catch { /* not linked */ }
  }
}

/** Toggle a community event's public-Events listing (host/admin, after creation). Best-effort. */
export async function setCommunityEventPublic(gameId, isPublic) {
  if (!gameId) return { error: 'Missing event.' }
  try {
    const { error } = await supabase.from('hosted_games').update({ community_public: !!isPublic }).eq('id', gameId)
    return error ? { error: error.message } : { ok: true }
  } catch (e) { return { error: e.message } }
}

// ── Street cred ("props"): post-match peer badges — social only, never rating ────
export const STREET_CRED_BADGES = {
  great_sport: { label: 'Great sport', emoji: '🤝' },
  clutch: { label: 'Clutch', emoji: '⚡' },
  hustle: { label: 'Hustler', emoji: '🔥' },
  wall: { label: 'The Wall', emoji: '🧱' },
  organizer_star: { label: 'Star organizer', emoji: '⭐' },
}

/** Give one badge to a fellow attendee (unique per game+giver+receiver+badge). */
export async function giveStreetCred(gameId, giverId, receiverId, badge) {
  if (!gameId || !giverId || !receiverId || !STREET_CRED_BADGES[badge]) return { error: 'Invalid props.' }
  if (giverId === receiverId) return { error: "You can't give props to yourself 😄" }
  try {
    const { error } = await supabase.from('street_cred')
      .upsert({ game_id: gameId, giver_id: giverId, receiver_id: receiverId, badge },
        { onConflict: 'game_id,giver_id,receiver_id,badge' })
    return error ? { error: error.message } : { ok: true }
  } catch (e) { return { error: e.message } }
}

/** Props I've given at this game → { receiverId: Set(badges) } (to show what's ticked). */
export async function myPropsForGame(gameId, giverId) {
  if (!gameId || !giverId) return {}
  try {
    const { data } = await supabase.from('street_cred')
      .select('receiver_id, badge').eq('game_id', gameId).eq('giver_id', giverId)
    const out = {}
    for (const r of (data || [])) (out[r.receiver_id] ||= new Set()).add(r.badge)
    return out
  } catch { return {} }
}

/** Badge counts on a profile → [{ badge, label, emoji, count }] (desc). */
export async function streetCredCounts(customerId) {
  if (!customerId) return []
  try {
    const { data } = await supabase.from('street_cred').select('badge').eq('receiver_id', customerId)
    const counts = {}
    for (const r of (data || [])) counts[r.badge] = (counts[r.badge] || 0) + 1
    return Object.entries(counts)
      .filter(([b]) => STREET_CRED_BADGES[b])
      .map(([badge, count]) => ({ badge, count, ...STREET_CRED_BADGES[badge] }))
      .sort((a, b) => b.count - a.count)
  } catch { return [] }
}
