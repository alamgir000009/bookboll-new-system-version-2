/*
 * Prove how the storefront reads its host, for every kind of host it runs on.
 *
 * The rules in brand.js decide whether a page is the marketplace, a provider's
 * white-label site, or a preview of one — and a wrong answer on the live apex
 * would let a provider's tracking scripts run on bookboll.com. So each case is
 * pinned here: the real apex, a real white-label subdomain, localhost, a dev
 * tunnel, and a configured preview host (new.bookboll.com) with and without
 * `?b=`. Run with VITE_PREVIEW_HOSTS set the way the testing build sets it.
 */
import { createServer, loadEnv } from 'vite'

// Check the REAL configuration: whatever .env (or the shell) sets is what the build
// bakes in. Only when neither names a preview host do we fall back to the sample
// allowlist, so the cases still run on a machine with no .env at all.
const fromEnvFiles = loadEnv('production', process.cwd(), 'VITE_').VITE_PREVIEW_HOSTS
if (!process.env.VITE_PREVIEW_HOSTS && !fromEnvFiles) process.env.VITE_PREVIEW_HOSTS = 'new.bookboll.com, bookboll.com'
console.log(`VITE_PREVIEW_HOSTS = "${process.env.VITE_PREVIEW_HOSTS || fromEnvFiles}"`)
const vite = await createServer({ root: process.cwd(), server: { middlewareMode: true }, appType: 'custom', logLevel: 'error' })

let failures = 0
const check = (ok, msg) => { console.log(`  ${ok ? '✓' : '✗'} ${msg}`); if (!ok) failures++ }

// Modules brand.js pulls in read storage and window at IMPORT time, so stub those
// before loading; brand.js itself reads window only at call time, so each case
// just re-points the stub.
const memStore = () => { const m = new Map(); return { getItem: k => (m.has(k) ? m.get(k) : null), setItem: (k, v) => m.set(k, String(v)), removeItem: k => m.delete(k), clear: () => m.clear(), key: i => [...m.keys()][i] ?? null, get length() { return m.size } } }
// A DOM that tolerates anything: every property is a function that returns
// another such object, so import-time theming/DOM calls are no-ops instead of
// a new stub to hand-write for each one.
const anything = () => new Proxy(function () {}, {
  get: (_, k) => (k === Symbol.toPrimitive ? () => '' : k === 'then' ? undefined : anything()),
  apply: () => anything(),
  set: () => true,
})
globalThis.localStorage = memStore()
globalThis.sessionStorage = memStore()
globalThis.document = anything()
const location = { hostname: 'localhost', search: '', origin: 'http://localhost', protocol: 'http:', href: 'http://localhost/' }
globalThis.window = new Proxy({ location, localStorage: globalThis.localStorage, sessionStorage: globalThis.sessionStorage }, {
  get: (t, k) => (k in t ? t[k] : anything()),
})
function at(url) {
  const u = new URL(url)
  Object.assign(location, { hostname: u.hostname, search: u.search, origin: u.origin, protocol: u.protocol, href: url })
}
const brand = await vite.ssrLoadModule('/src/lib/brand.js')
const { detectBrandSlug, isBrandPreview, marketplaceUrl, brandPreviewUrl, isPreviewHost } = brand

console.log('\nAllowlist')
check(isPreviewHost('new.bookboll.com'), 'new.bookboll.com is a preview host')
check(!isPreviewHost('bookboll.com'), 'bookboll.com can never be one, even when listed')
check(!isPreviewHost('alamgir.bookboll.com'), 'a provider subdomain is not one')

console.log('\nThe testing domain, new.bookboll.com')
at('https://new.bookboll.com/')
check(detectBrandSlug() === '', 'without ?b= it is the marketplace — "new" is not read as a provider slug')
at('https://new.bookboll.com/?b=alamgir')
check(detectBrandSlug() === 'alamgir', '?b=alamgir shows that provider\'s white-label site')
check(isBrandPreview() === true, '…and counts as a preview, so the provider\'s pixels stay off the staging origin')
check(marketplaceUrl('/venues') === 'https://new.bookboll.com/venues', '"browse Bookboll" stays on new.bookboll.com, not production')
check(brandPreviewUrl('alamgir') === 'https://new.bookboll.com/?b=alamgir', 'preview links are built with ?b= on this host')
at('https://new.bookboll.com/?bid=42&preview=1')
check(isBrandPreview() === true && detectBrandSlug() === '', '?bid=42&preview=1 resolves by owner id before a slug exists')

console.log('\nThe real apex, bookboll.com — nothing changes')
at('https://bookboll.com/?b=alamgir')
check(detectBrandSlug() === '', '?b= is still refused on bookboll.com')
check(isBrandPreview() === false, 'and it is not a preview')
at('https://alamgir.bookboll.com/')
check(detectBrandSlug() === 'alamgir', 'a real white-label subdomain still resolves by its label')
check(marketplaceUrl('/') === 'https://bookboll.com/', 'and "browse Bookboll" from it goes to the apex')
at('https://alamgir.bookboll.com/')
check(brandPreviewUrl('alamgir') === 'https://alamgir.bookboll.com', 'production preview links are the real subdomain')

console.log('\nlocalhost — unchanged')
at('http://localhost:5173/?b=alamgir')
check(detectBrandSlug() === 'alamgir', '?b= works on localhost')
check(isBrandPreview() === false, 'but a plain ?b= on localhost is not flagged as a preview (as before)')

await vite.close()
console.log(failures ? `\n${failures} CHECK(S) FAILED` : '\nAll host checks passed')
process.exit(failures ? 1 : 0)
