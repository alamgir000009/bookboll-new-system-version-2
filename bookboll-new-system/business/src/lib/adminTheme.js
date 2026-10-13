// ── Admin dashboard theme (dark mode) ─────────────────────────────────────────
// Mirrors the customer storefront's proven approach (see CustomerLayout.vue):
// a smart-invert filter on the ROOT element, scoped by the `adm-shell` class that
// App.vue toggles on <html> while the admin area is active — so the customer
// storefront and auth pages are never affected.
//
// Default follows the user's local clock (light 07:00–18:59, dark 19:00–06:59,
// shared rule: timeDefaultTheme in customerSession.js). Only an EXPLICIT toggle
// is persisted — the computed default is never written, so an untouched theme
// keeps adapting day to day while a stored choice always wins.
import { ref } from 'vue'
import { timeDefaultTheme } from './customerSession.js'

const KEY = 'bb_admin_theme'

function readStored() {
  try { return localStorage.getItem(KEY) } catch { return null }
}

export const adminTheme = ref(readStored() || timeDefaultTheme())

export function applyAdminTheme() {
  if (typeof document !== 'undefined') {
    document.documentElement.classList.toggle('adm-dark', adminTheme.value === 'dark')
  }
}

export function toggleAdminTheme() {
  adminTheme.value = adminTheme.value === 'dark' ? 'light' : 'dark'
  try { localStorage.setItem(KEY, adminTheme.value) } catch { /* ignore */ }
  applyAdminTheme()
}

applyAdminTheme()   // apply at load (no flash); inert until <html> also has .adm-shell
