/**
 * community.js — community module data layer, backed by Laravel (apiCommunity.js).
 * Event tagging lives in apiGames.js alongside the rest of the hosted-games API.
 */

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
