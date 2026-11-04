/*
 * Render the account picker for real.
 *
 * `npm run build` only bundles — it never executes a component, so a reference
 * to something that no longer exists ships happily and crashes on open. This
 * renders AccountPicker and both screens that embed it, and asserts what the
 * admin must actually be able to see before moving someone out of the platform
 * team: who they are, what role they hold, and what they can currently reach.
 */
import { createSSRApp } from 'vue'
import { renderToString } from '@vue/server-renderer'
import { createServer } from 'vite'

const vite = await createServer({ root: process.cwd(), server: { middlewareMode: true }, appType: 'custom', logLevel: 'error' })

let failures = 0
const check = (ok, msg) => { console.log(`  ${ok ? '✓' : '✗'} ${msg}`); if (!ok) failures++ }

// Real shapes from /admin/businesses/assignment-candidates.
const candidates = [
  { id: '1', name: 'Alamgir Khan', email: 'alamgir000009@gmail.com', role: 'country_admin', roles: ['country_admin'], venues: 20, last_login: null },
  { id: '2', name: 'Ali Nawaz', email: 'alnawaz1978_39724@yopmail.com', role: 'platform_staff', roles: ['platform_staff'], venues: 2, last_login: new Date(Date.now() - 6 * 864e5).toISOString() },
  { id: '3', name: 'hamas', email: 'kkhamas222_39640@yopmail.com', role: 'provider_supervisor', roles: ['provider_supervisor'], venues: 1, last_login: new Date(Date.now() - 10 * 864e5).toISOString() },
]

const { default: AccountPicker } = await vite.ssrLoadModule('/src/components/AccountPicker.vue')

async function render(props) {
  const app = createSSRApp({
    components: { AccountPicker },
    data: () => props,
    template: `<AccountPicker :model-value="modelValue" :candidates="candidates" :loading="loading" :empty-text="emptyText" />`,
  })
  return renderToString(app)
}

console.log('\nThe picker shows who each account is')
{
  const html = await render({ modelValue: '', candidates, loading: false, emptyText: 'none' })
  check(html.includes('Alamgir Khan') && html.includes('alamgir000009@gmail.com'), 'name and email')
  check(html.includes('Country Admin') && html.includes('Platform Staff'), 'the role each person holds, in words')
  check(html.includes('20 venues') && html.includes('2 venues'), 'how many venues they can currently reach')
  check(html.includes('Never signed in') && html.includes('Active 6d ago'), 'when they were last here')
  check(html.includes('>AK<') && html.includes('>AN<'), 'initials, so similar test accounts are told apart')
  check(html.includes('Search 3 platform accounts'), 'the search box names how many accounts there are')
  check(!html.includes('<select'), 'no native select — it was unreadable at this length')
}

console.log('\nStates')
{
  const loading = await render({ modelValue: '', candidates: [], loading: true, emptyText: 'none' })
  check(loading.includes('Loading accounts…'), 'loading says so rather than looking empty')
  const empty = await render({ modelValue: '', candidates: [], loading: false, emptyText: 'Everyone is already in a business.' })
  check(empty.includes('Everyone is already in a business.'), 'empty explains why, in the caller’s words')
  const picked = await render({ modelValue: '2', candidates, loading: false, emptyText: 'none' })
  check(picked.includes('aria-selected="true"'), 'the chosen account is marked selected for screen readers')
}

console.log('\nBoth screens that embed it still render')
{
  const { default: BusinessAssignment } = await vite.ssrLoadModule('/src/components/BusinessAssignment.vue')
  const app = createSSRApp({
    components: { BusinessAssignment },
    // v-dialog is a global directive registered by the app shell.
    template: `<BusinessAssignment :business="{ business_id: 1, business_name: 'Doha Padel' }" />`,
  })
  app.directive('dialog', {})
  const html = await renderToString(app)
  check(html.includes('Assign an existing platform account'), 'the existing-business modal renders with the picker')
  check(html.includes('Search 0 platform accounts'), '…and mounts AccountPicker rather than a select')
}

await vite.close()
console.log(failures ? `\n${failures} CHECK(S) FAILED` : '\nAll account picker checks passed')
process.exit(failures ? 1 : 0)
