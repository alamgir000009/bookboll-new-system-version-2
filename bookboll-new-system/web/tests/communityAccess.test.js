import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { ref } from 'vue'
import { communityRole, canContribute, canModerateCommunity, normalizedPermissions, permissionsConfirmed } from '../src/lib/communityAccess.js'
import { popularCommunities, filterConversations } from '../src/lib/communityPresentation.js'

test('community permissions distinguish visitors, pending members, members, moderators and admins', () => {
  for (const permission of ['members', 'moderators', 'admins']) {
    for (const role of ['member', 'moderator', 'admin', 'owner']) {
      const community = { membership: { status: 'accepted', role }, settings: { post_permission: permission, chat_permission: permission } }
      const expected = ['owner', 'admin'].includes(role) || permission === 'members' || (role === 'moderator' && permission === 'moderators')
      assert.equal(canContribute(community, 7, 'posts'), expected, `${role}: ${permission}`)
      assert.equal(canContribute(community, 7, 'chat'), expected)
      assert.equal(canModerateCommunity(community, 7), role !== 'member')
      community.membership.status = 'pending'
      assert.equal(canContribute(community, 7, 'posts'), false)
      assert.equal(canModerateCommunity(community, 7), false)
    }
  }
  assert.equal(communityRole({ owner_customer_id: 7 }, '7'), 'owner')
  assert.equal(canContribute({ owner_customer_id: 7 }, null, 'posts'), false)
  assert.equal(canContribute({ settings: {} }, 7, 'chat'), false)
})

test('legacy locked chats stay restricted and unconfirmed permissions are never treated as saved', () => {
  const settings = normalizedPermissions({ chat_locked: true, welcome: 'Keep this setting' })
  assert.equal(settings.chat_permission, 'admins')
  assert.equal(settings.welcome, 'Keep this setting')
  assert.equal(permissionsConfirmed({ settings: {} }, settings), false)
  assert.equal(permissionsConfirmed({ settings }, settings), true)
  assert.equal(normalizedPermissions({ ...settings, chat_permission: 'moderators' }).chat_locked, false)
})

test('popular sorting is stable, supports API count variants and leaves the source array intact', () => {
  const communities = [{ id: 1, name: 'B', memberCount: 2 }, { id: 2, name: 'Most', member_count: 10 }, { id: 3, name: 'A', members_count: 2 }]
  assert.deepEqual(popularCommunities(communities).map(c => c.id), [2, 3, 1])
  assert.deepEqual(communities.map(c => c.id), [1, 2, 3])
})

test('inbox search matches names, latest message and community conversations', () => {
  const chats = [{ peer: { full_name: 'Pickleball Crew' }, group: true, lastBody: 'Friday match' }, { peer: { full_name: 'Sam' }, lastBody: 'Hello' }]
  assert.deepEqual(filterConversations(chats, ' PICKLEBALL friday '), [chats[0]])
  assert.deepEqual(filterConversations(chats, 'community'), [chats[0]])
  assert.deepEqual(filterConversations(chats, 'missing'), [])
  assert.deepEqual(filterConversations(chats, ''), chats)
})

// Execute the production adapter against an in-memory API; never call the server.
const source = readFileSync(new URL('../src/customer/apiCommunity.js', import.meta.url), 'utf8')
  .replace(/^import .*$/gm, '').replace(/\bexport /g, '')
const adapter = new Function('apiFetch', 'ref', 'normalizedPermissions', 'permissionsConfirmed', source + '\nreturn { createCommunity, updateCommunity, updateCommunityChatPermission, setMemberRole, deleteGroupMessage };')
function apiFixture() {
  let settings = normalizedPermissions(), ignoreSettings = false, reject = false
  const calls = []
  const api = adapter(async (path, options = {}) => {
    calls.push({ path, ...options })
    if (reject) return { success: false, message: 'Action was denied.' }
    if (options.body?.settings && !ignoreSettings) settings = { ...settings, ...options.body.settings }
    return { success: true, data: { community: { id: 'fixture', name: 'Fixture', settings } } }
  }, ref, normalizedPermissions, permissionsConfirmed)
  return { api, calls, ignore: () => { ignoreSettings = true }, reject: () => { reject = true } }
}

test('creation persists access settings and reads them back before reporting success', async () => {
  const { api, calls } = apiFixture()
  const result = await api.createCommunity(7, { name: '  Crew  ', settings: { post_permission: 'moderators', chat_permission: 'admins' } })
  assert.equal(result.settingsWarning, '')
  assert.equal(calls[0].body.name, 'Crew')
  assert.equal(calls[0].body.settings.chat_locked, true)
  assert.equal(calls[1].path, '/marketplace/communities/fixture')
})

test('an older backend ignoring settings shows a warning on create and an error on edit', async () => {
  const { api, ignore } = apiFixture()
  ignore()
  const settings = normalizedPermissions({ post_permission: 'admins', chat_permission: 'admins' })
  assert.match((await api.createCommunity(7, { name: 'Crew', settings })).settingsWarning, /could not be confirmed/)
  const result = await api.updateCommunity('fixture', { settings })
  assert.ok(result.error)
  assert.equal(result.ok, undefined)
})

test('moderation requests use the selected role and server denial is not reported as success', async () => {
  const { api, calls, reject } = apiFixture()
  await api.setMemberRole('member/1', 'moderator')
  assert.equal(calls[0].body.role, 'moderator')
  assert.match(calls[0].path, /member%2F1/)
  reject()
  const result = await api.deleteGroupMessage('community__fixture', 'message/1')
  assert.equal(result.error, 'Action was denied.')
  assert.equal(result.ok, undefined)
  assert.equal(calls[1].method, 'DELETE')
  assert.equal(calls[1].body.key, 'community__fixture')
})

test('a write response alone cannot confirm permission persistence when read-back fails', async () => {
  const settings = normalizedPermissions({ chat_permission: 'admins' })
  const api = adapter(async (path, options = {}) => options.method ? { success: true, data: { community: { id: 'fixture', settings } } } : { success: false }, ref, normalizedPermissions, permissionsConfirmed)
  assert.ok((await api.createCommunity(7, { name: 'Crew', settings })).settingsWarning)
  assert.ok((await api.updateCommunity('fixture', { settings })).error)
})

test('chat settings patch only the chat audience and confirm it by reading the community', async () => {
  const { api, calls } = apiFixture()
  const result = await api.updateCommunityChatPermission('crew/1', 'moderators')
  assert.equal(result.ok, true)
  assert.deepEqual(calls[0].body, { settings: { chat_permission: 'moderators', chat_locked: false } })
  assert.equal(calls[0].path, '/customer/communities/crew%2F1')
  assert.equal(result.community.settings.post_permission, 'members')
  assert.equal(calls[1].method, undefined)
  assert.equal(calls[1].path, '/marketplace/communities/crew%2F1')
})

test('chat permission changes reject invalid input, denial and silent API drops', async () => {
  const { api, calls, ignore, reject } = apiFixture()
  assert.ok((await api.updateCommunityChatPermission('crew', 'anyone')).error)
  assert.equal(calls.length, 0)
  ignore()
  assert.match((await api.updateCommunityChatPermission('crew', 'admins')).error, /could not be confirmed/)
  reject()
  assert.equal((await api.updateCommunityChatPermission('crew', 'admins')).error, 'Action was denied.')
})

/* The server makes these same decisions (app/Support/CommunityPermissions.php).
 * If the two disagree, a member is shown a compose box the server rejects, or a
 * moderator is handed a permissions panel that 403s. This table is written out
 * in full on both sides on purpose: changing one without the other fails. */
const CONTRIBUTE_MATRIX = [
  ['owner', 'members', true], ['owner', 'moderators', true], ['owner', 'admins', true],
  ['admin', 'members', true], ['admin', 'moderators', true], ['admin', 'admins', true],
  ['moderator', 'members', true], ['moderator', 'moderators', true], ['moderator', 'admins', false],
  ['member', 'members', true], ['member', 'moderators', false], ['member', 'admins', false],
  ['visitor', 'members', false], ['visitor', 'moderators', false], ['visitor', 'admins', false],
]

test('the role by audience matrix matches the server, for the feed and the chat alike', async () => {
  const { canContribute } = await import('../src/lib/communityAccess.js')

  for (const channel of ['posts', 'chat']) {
    for (const [role, audience, expected] of CONTRIBUTE_MATRIX) {
      // A visitor is anyone without accepted membership: never joined, or
      // still pending. The owner is authoritative through owner_customer_id.
      const community = {
        owner_customer_id: role === 'owner' ? 'me' : 'someone-else',
        membership: role === 'visitor' ? { status: 'pending', role: 'member' } : { status: 'accepted', role },
        settings: { post_permission: channel === 'posts' ? audience : 'members', chat_permission: channel === 'chat' ? audience : 'members' },
      }
      assert.equal(canContribute(community, 'me', channel), expected, `${role} / ${audience} / ${channel}`)
    }
  }
})

test('a pending moderator has no powers at all, however the row reads', async () => {
  const { canContribute, canModerateCommunity, communityRole } = await import('../src/lib/communityAccess.js')
  const pendingMod = { owner_customer_id: 'other', membership: { status: 'pending', role: 'moderator' }, settings: { post_permission: 'members', chat_permission: 'members' } }

  assert.equal(communityRole(pendingMod, 'me'), 'visitor')
  assert.equal(canContribute(pendingMod, 'me', 'posts'), false)
  assert.equal(canModerateCommunity(pendingMod, 'me'), false)
})
