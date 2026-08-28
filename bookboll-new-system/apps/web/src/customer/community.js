/**
 * community.js — community module data layer.
 * Backed by Laravel (apiCommunity.js). Meetup tagging still uses Supabase
 * hosted_games until Events are migrated.
 */
import { supabase } from '../lib/supabase.js'

export {
  communitiesEnabled,
  probeCommunities,
  loadCommunities,
  loadCommunity,
  myCommunities,
  loadMembers,
  createCommunity,
  updateCommunity,
  joinCommunity,
  leaveCommunity,
  respondToMember,
  removeMember,
  setMemberRole,
  canManage,
  isMember,
  loadCommunityFeed,
  createCommunityPost,
  deleteCommunityPost,
  groupKey,
  loadGroupThread,
  sendGroupMessage,
  markCommunityChatRead,
  communityChatUnread,
  checkCommunityMembership,
  STREET_CRED_BADGES,
  giveStreetCred,
  myPropsForGame,
  streetCredCounts,
} from './apiCommunity.js'

/** Stamp a hosted game with a community (Supabase events until fully migrated). */
export async function applyCommunityId(gameId, communityId, isPublic = false) {
  if (!gameId || !communityId) return
  try {
    await supabase.from('hosted_games').update({
      community_id: communityId,
      community_public: !!isPublic,
    }).eq('id', gameId)
  } catch {
    try {
      await supabase.from('hosted_games').update({ community_id: communityId }).eq('id', gameId)
    } catch { /* not linked */ }
  }
}

/** Toggle a community event's public-Events listing. */
export async function setCommunityEventPublic(gameId, isPublic) {
  if (!gameId) return { error: 'Missing event.' }
  try {
    const { error } = await supabase.from('hosted_games')
      .update({ community_public: !!isPublic })
      .eq('id', gameId)
    return error ? { error: error.message } : { ok: true }
  } catch (e) {
    return { error: e.message }
  }
}
