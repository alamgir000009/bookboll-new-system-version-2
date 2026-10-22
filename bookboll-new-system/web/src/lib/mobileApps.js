// Mirrored in admin/src/lib/mobileApps.js. Keep both portals on the same store and cadence policy.
export const MOBILE_APPS = Object.freeze({
  customer: Object.freeze({
    name: 'Bookboll',
    download: 'https://onelink.to/m8a4mk',
  }),
  admin: Object.freeze({
    name: 'Bookboll Admin',
    download: 'https://onelink.to/e8duqh',
  }),
})

export function mobilePlatform(nav = {}) {
  const ua = nav.userAgent || ''
  if (/android/i.test(ua)) return 'android'
  // iPadOS can request desktop pages and identify itself as a Mac.
  if (/iPhone|iPad|iPod/i.test(ua) || (/Mac/i.test(nav.platform || ua) && nav.maxTouchPoints > 1)) return 'ios'
  return 'desktop'
}

export function insideInstalledApp(win = {}, nav = {}) {
  // Deliberately NOT `(display-mode: fullscreen)`. That media query also matches
  // an ordinary browser the user put into fullscreen (F11 / ctrl-cmd-F), which
  // is not an installed app — and suppressing the card there hides it from
  // anyone working fullscreen, with no way to tell why. An installed PWA
  // registers `standalone`; a native wrapper announces itself below.
  return !!(nav.standalone || win.matchMedia?.('(display-mode: standalone)').matches ||
    win.ReactNativeWebView ||
    win.Capacitor?.isNativePlatform?.() || /BookbollApp|BookbollAdminApp/i.test(nav.userAgent || ''))
}

export function appPromptContext({ audience, path = '', signedIn, businessUser = false, whiteLabel = false, embedded = false, completed = false, accountId = '' }) {
  if (!signedIn || embedded || (audience === 'customer' && whiteLabel)) return null
  const page = path.replace(/^\/ar(?=\/|$)/, '').replace(/\/$/, '')
  if (audience === 'admin') {
    if (!businessUser) return null
    if (page === '/calendar') return { title: 'Your calendar, wherever you are', body: 'View your schedule and manage bookings in the Bookboll Admin app.' }
    if (page === '/bookings') return { title: 'Manage bookings on the go', body: 'Keep your business bookings close with the Bookboll Admin app.' }
    return null
  }
  if (audience !== 'customer') return null
  if (completed) return { title: 'Keep your booking close', body: 'View and manage your bookings on your phone with the Bookboll app.' }
  if (page === '/my-bookings') return { title: 'Your bookings, in your pocket', body: 'View and manage your bookings on your phone with the Bookboll app.' }
  if (page === '/my-games') return { title: 'Take Bookboll with you', body: 'Get the Bookboll app for your next visit. Your bookings stay close at hand.' }
  if (page === '/my-communities') return { title: 'Keep your community close', body: 'Take Bookboll with you for your next game, booking or meetup.' }
  if (page === '/account' || (accountId && page === `/u/${accountId}`)) return { title: 'Bookboll, wherever you go', body: 'Get the Bookboll app to view and manage your bookings on your phone.' }
  if (page === '/my-memberships') return { title: 'Make your next visit easier', body: 'Keep Bookboll on your phone to view and manage your bookings.' }
  return null
}

// Version 2 replaces global timed cooldowns with account-scoped self-reports.
// Dismissal/download clicks last only for the current page visit and are not installation evidence.
export const promptKey = (audience, accountId = '') => `bb_app_prompt_v2_${audience}_${encodeURIComponent(String(accountId || 'anonymous'))}`
const memory = new Map()
function read(audience, storage, accountId) {
  const key = promptKey(audience, accountId)
  if (memory.has(key)) return memory.get(key)
  try { return JSON.parse(storage?.getItem(key) || 'null') || {} }
  catch { return {} }
}
export function canShowAppPrompt(audience, storage, accountId = '') {
  return !!MOBILE_APPS[audience] && read(audience, storage, accountId).selfReported !== true
}
export function rememberAppPrompt(audience, action, storage, accountId = '') {
  if (action !== 'installed') return
  const state = { selfReported: true }
  const key = promptKey(audience, accountId)
  memory.set(key, state)
  try { storage?.setItem(key, JSON.stringify(state)) } catch { /* private browsing: keep the preference in this tab */ }
}
