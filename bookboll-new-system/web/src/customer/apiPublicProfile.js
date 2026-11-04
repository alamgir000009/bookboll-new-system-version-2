/**
 * Laravel-backed public profile + social actions for /u/:uuid
 */
import { apiFetch } from '../lib/api.js'

function formatErr(res, fallback) {
  const errors = res?.errors
  if (errors && typeof errors === 'object') {
    for (const v of Object.values(errors)) {
      const msg = Array.isArray(v) ? v[0] : v
      if (msg) return String(msg)
    }
  }
  return res?.message || fallback
}

function mapPendingRequest(req) {
  return {
    id: req.id,
    createdAt: req.createdAt,
    follower: {
      id: req.follower?.id,
      full_name: req.follower?.full_name || 'Player',
      avatar_url: req.follower?.avatar_url || '',
    },
  }
}

/** Load full public profile bundle (profile, social, communities, street cred). */
export async function loadPublicProfileBundle(uuid) {
  if (!uuid) return null
  const res = await apiFetch(`/marketplace/profiles/${encodeURIComponent(uuid)}`)
  if (!res.success || !res.data?.profile) return null
  return {
    profile: res.data.profile,
    followState: res.data.followState || 'none',
    counts: res.data.counts || { followers: 0, following: 0 },
    canView: res.data.canView !== false,
    posts: res.data.posts || [],
    pendingRequests: (res.data.pendingRequests || []).map(mapPendingRequest),
    streetCred: res.data.streetCred || [],
    communities: res.data.communities || [],
  }
}

export async function followCounts(uuid) {
  const bundle = await loadPublicProfileBundle(uuid)
  return bundle?.counts || { followers: 0, following: 0 }
}

export async function loadFollowState(viewerId, targetId) {
  if (!viewerId || !targetId || viewerId === targetId) return 'none'
  const bundle = await loadPublicProfileBundle(targetId)
  return bundle?.followState || 'none'
}

export async function canViewContent(viewerId, target) {
  if (!target) return false
  if (!(target.is_private ?? target.isPrivate)) return true
  if (viewerId && viewerId === target.id) return true
  if (!viewerId) return false
  return (await loadFollowState(viewerId, target.id)) === 'following'
}

export async function followUser(viewerId, target) {
  if (!viewerId || !target?.id) return { error: 'Not signed in' }
  const res = await apiFetch(`/customer/social/follow/${encodeURIComponent(target.id)}`, { method: 'POST' })
  if (!res.success) return { error: formatErr(res, 'Could not follow.') }
  return { state: res.data?.state || 'following' }
}

export async function unfollowUser(viewerId, targetId) {
  if (!viewerId || !targetId) return { state: 'none' }
  await apiFetch(`/customer/social/follow/${encodeURIComponent(targetId)}`, { method: 'DELETE' })
  return { state: 'none' }
}

export async function loadFollowList(ownerId, kind) {
  if (!ownerId) return []
  const res = await apiFetch(`/customer/social/follows/${encodeURIComponent(ownerId)}`, { query: { kind } })
  if (!res.success) return []
  return res.data?.people || []
}

export async function pendingFollowRequests(ownerId) {
  const bundle = await loadPublicProfileBundle(ownerId)
  return bundle?.pendingRequests || []
}

export async function respondFollow(requestId, accept) {
  const res = await apiFetch(`/customer/social/follow-requests/${requestId}/respond`, {
    method: 'POST',
    body: { accept: !!accept },
  })
  if (!res.success) return { error: formatErr(res, 'Could not respond to request.') }
  return { ok: true }
}

export async function loadPosts(authorId) {
  const bundle = await loadPublicProfileBundle(authorId)
  return bundle?.posts || []
}

export async function createPost(authorId, { body, photos }) {
  if (!authorId) return { error: 'Not signed in' }
  const res = await apiFetch('/customer/social/posts', {
    method: 'POST',
    body: { body: body || '', photos: photos || [] },
  })
  if (!res.success) return { error: formatErr(res, 'Could not post.') }
  const post = res.data?.post
  return {
    post: post ? {
      ...post,
      photos: Array.isArray(post.photos) ? post.photos.filter(Boolean) : [],
    } : null,
  }
}

export async function deletePost(id) {
  const res = await apiFetch(`/customer/social/posts/${encodeURIComponent(id)}`, { method: 'DELETE' })
  if (!res.success) return { error: formatErr(res, 'Could not delete post.') }
  return { ok: true }
}

export async function streetCredCounts(customerId) {
  const bundle = await loadPublicProfileBundle(customerId)
  return bundle?.streetCred || []
}

export async function myCommunities(customerId) {
  const bundle = await loadPublicProfileBundle(customerId)
  return bundle?.communities || []
}
