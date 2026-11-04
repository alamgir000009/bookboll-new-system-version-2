export function communityMemberCount(community) {
  return Math.max(0, Number(community?.memberCount ?? community?.member_count ?? community?.members_count) || 0)
}
export function popularCommunities(communities = []) {
  return [...communities].sort((a, b) => communityMemberCount(b) - communityMemberCount(a) || String(a.name || '').localeCompare(String(b.name || '')) || String(a.id).localeCompare(String(b.id)))
}
export function filterConversations(conversations, query) {
  const words = String(query || '').trim().toLocaleLowerCase().split(/\s+/).filter(Boolean)
  return conversations.filter(c => words.every(word => [c.peer?.full_name, c.lastBody, c.group ? 'community' : ''].join(' ').toLocaleLowerCase().includes(word)))
}
