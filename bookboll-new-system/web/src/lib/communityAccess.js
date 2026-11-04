// UI permissions mirror the API contract. Every mutation must also be authorized
// by Laravel; hiding a button is never a security boundary.
export const COMMUNITY_AUDIENCES = [
  { value: 'members', label: 'All members', detail: 'Everyone who has joined can contribute.' },
  { value: 'moderators', label: 'Admins & moderators', detail: 'Your team shares updates; members can read.' },
  { value: 'admins', label: 'Admins only', detail: 'Only the owner and admins can contribute.' },
]
export function communityRole(community, customerId) {
  if (!community || !customerId) return 'visitor'
  if (String(community.owner_customer_id) === String(customerId)) return 'owner'
  const membership = community.membership
  return membership?.status === 'accepted' && ['owner', 'admin', 'moderator', 'member'].includes(membership.role) ? membership.role : 'visitor'
}
export function permissionFor(settings, channel) {
  const raw = settings?.[channel === 'chat' ? 'chat_permission' : 'post_permission']
  if (COMMUNITY_AUDIENCES.some(option => option.value === raw)) return raw
  // Older API versions expose the announcements-only chat switch.
  return channel === 'chat' && settings?.chat_locked ? 'admins' : 'members'
}
export function canContribute(community, customerId, channel) {
  const role = communityRole(community, customerId)
  if (role === 'visitor') return false
  const permission = permissionFor(community?.settings, channel)
  return ['owner', 'admin'].includes(role) || (role === 'moderator' && permission !== 'admins') || permission === 'members'
}
export function canModerateCommunity(community, customerId) {
  return ['owner', 'admin', 'moderator'].includes(communityRole(community, customerId))
}
export function permissionLabel(value) {
  return COMMUNITY_AUDIENCES.find(option => option.value === value)?.label || 'All members'
}
export function normalizedPermissions(settings = {}) {
  return { ...settings, post_permission: permissionFor(settings, 'posts'), chat_permission: permissionFor(settings, 'chat'), chat_locked: permissionFor(settings, 'chat') === 'admins' }
}
export function permissionsConfirmed(community, settings) {
  // Require explicit values so an old API silently dropping settings is visible.
  return ['post_permission', 'chat_permission'].every(key => community?.settings?.[key] === settings[key])
}
