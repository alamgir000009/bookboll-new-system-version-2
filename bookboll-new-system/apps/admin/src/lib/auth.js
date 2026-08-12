/**
 * auth.js — Supabase Auth integration for the Bookboll admin dashboard.
 *
 * Wraps supabase.auth so the rest of the app never imports it directly.
 * After sign-in, loads the matching admin_users row (by auth_user_id → email
 * fallback) and writes it into currentUser so all existing permission checks
 * keep working unchanged.
 */
import { ref, computed } from 'vue'
import { supabase } from './supabase.js'
import { currentUser, allUsers, activeUserId, realAuthEmail, canActAs, loadUserContext, setActiveUser } from './currentUser.js'

export const authUser     = ref(null)   // supabase auth session user
export const authLoading  = ref(true)   // true until the session is resolved
export const authError    = ref('')

// When one email is linked to MORE THAN ONE admin_users row (e.g. an owner of two
// businesses, or someone who's a team member at one and an owner/staffer at another),
// the host must pick which business to open. These rows are surfaced for a picker
// screen; once a valid one is the active selection, the picker is dismissed.
export const accountChoices = ref([])
export const needsAccountChoice = computed(() =>
  !!authUser.value && accountChoices.value.length > 1 &&
  !accountChoices.value.some(r => r.id === activeUserId.value))

// Dashboard live-preview renders load the storefront with `?preview=1` (the Embed page's
// preview iframe, My Website preview, "Open preview" tab). They SHARE this origin's
// localStorage + Supabase session with the admin tab, so resolving/writing the admin user
// here would stomp `bb_active_user` and switch the admin's active provider. A preview is a
// pure customer-storefront render — it must never touch admin user state.
function isPreviewRender() {
  if (typeof window === 'undefined') return false
  try { return new URLSearchParams(window.location.search).get('preview') === '1' } catch { return false }
}

// Resolve the auth session on boot and whenever it changes (refresh, sign-out).
export async function initAuth() {
  if (isPreviewRender()) { authLoading.value = false; return }
  const { data: { session } } = await supabase.auth.getSession()
  if (session?.user) {
    authUser.value = session.user
    realAuthEmail.value = (session.user.email || '').toLowerCase()
    await _loadAdminUser(session.user)
  }
  authLoading.value = false

  // IMPORTANT: this callback MUST stay synchronous and must NOT await Supabase
  // calls. Awaiting supabase.from()/auth queries inside onAuthStateChange holds
  // the auth lock and can deadlock the client on token refresh / tab refocus —
  // after which every query hangs and the page looks "empty" until a manual
  // reload. We set the user synchronously and defer the DB load to a microtask
  // (setTimeout 0), which runs after the lock is released. INITIAL_SESSION is
  // skipped here because initAuth() above already loaded the boot session.
  supabase.auth.onAuthStateChange((event, session) => {
    if (session?.user) {
      authUser.value = session.user
      realAuthEmail.value = (session.user.email || '').toLowerCase()
      if (event !== 'INITIAL_SESSION') setTimeout(() => { _loadAdminUser(session.user) }, 0)
    } else {
      authUser.value = null
      realAuthEmail.value = ''
      currentUser.value = null
    }
  })
}

// After auth, find the admin_users row(s) for this email. An email may be linked to
// SEVERAL businesses/teams (no unique constraint), so we resolve a LIST:
//   • 0 rows → not an admin (currentUser stays null)
//   • 1 row  → open it
//   • >1     → resume the last-picked one if it still belongs to this email; otherwise
//              leave it for the business picker (needsAccountChoice).
// We scope strictly to this email and clear any stale cross-business selection, so a
// leftover `activeUserId` from another login can never leak another business in.
async function _loadAdminUser(user) {
  const { data: all } = await supabase.from('admin_users').select('*').order('role')
  allUsers.value = all || []
  const email = (user.email || '').toLowerCase()
  realAuthEmail.value = email
  const list = (all || []).filter(r => (r.email || '').toLowerCase() === email)
  accountChoices.value = list.length > 1 ? list : []

  // The signed-in person's OWN admin_users row (by email).
  let chosen = null
  if (list.length === 1) chosen = list[0]
  else if (list.length > 1) chosen = list.find(r => r.id === activeUserId.value) || null   // resume last pick

  // Bookboll HQ can act as another business via the topbar switcher (e.g. to edit a
  // provider's My Website) — preserve that selection across auth refreshes, but ONLY
  // when the real account is allowed to use it (canActAs). A provider's stale or
  // hand-edited selection of the super-admin row is discarded, never resumed.
  if (activeUserId.value) {
    const impersonated = (all || []).find(r => r.id === activeUserId.value)
    if (impersonated && (impersonated.email || '').toLowerCase() !== email && canActAs(impersonated)) chosen = impersonated
  }
  if (!chosen) activeUserId.value = ''   // don't let a stale selection from another email persist

  currentUser.value = chosen || null
  if (chosen) {
    activeUserId.value = chosen.id
    localStorage.setItem('bb_active_user', chosen.id)
    // Best-effort link of the auth account to its OWN row only — NEVER to a business an
    // HQ user is merely impersonating (that would hijack the provider's auth link).
    const isOwnRow = (chosen.email || '').toLowerCase() === email
    if (isOwnRow && chosen.auth_user_id !== user.id) {
      supabase.from('admin_users').update({ auth_user_id: user.id }).eq('id', chosen.id).then(() => {}, () => {})
    }
  }
}

// Pick which business to open when an email has several (from the picker screen).
export function chooseAdminAccount(id) {
  const row = accountChoices.value.find(r => r.id === id)
  if (!row) return
  setActiveUser(id)   // sets currentUser + activeUserId + persists (currentUser.js)
  if (authUser.value && row.auth_user_id !== authUser.value.id) {
    supabase.from('admin_users').update({ auth_user_id: authUser.value.id }).eq('id', id).then(() => {}, () => {})
  }
}

export async function signIn(email, password) {
  authError.value = ''
  const { data, error } = await supabase.auth.signInWithPassword({ email, password })
  if (error) { authError.value = error.message; return false }
  authUser.value = data.user
  await _loadAdminUser(data.user)
  return true
}

export async function signOut() {
  await supabase.auth.signOut()
  authUser.value = null
  currentUser.value = null
  activeUserId.value = ''
  accountChoices.value = []
}

export async function signUpProvider({ email, password, fullName, businessName, countryId, username, businessTypes }) {
  authError.value = ''
  const { data, error } = await supabase.auth.signUp({ email, password })
  if (error) { authError.value = error.message; return false }
  const user = data.user
  if (!user) { authError.value = 'Sign-up failed — please try again.'; return false }

  // Insert admin_users row as a pending provider_admin. The business USERNAME is the
  // permanent web address, so it's written to BOTH username and brand_subdomain at
  // creation (the address is locked to the username from day one — see Signup/Brand).
  // Three-stage fallback so signup still works on a pre-migration DB:
  //   full row → without business_types → without username/brand_subdomain too.
  const base = {
    id: user.id,             // use auth.uid as the PK so the two are always in sync
    email,
    full_name: fullName,
    business_name: businessName,
    country_id: countryId || null,
    role: 'provider_admin',
    status: 'active',
    business_status: 'pending',   // pending Bookboll approval
  }
  const uname = (username || '').trim().toLowerCase()
  const withAddress = uname ? { ...base, username: uname, brand_subdomain: uname } : base
  const full = Array.isArray(businessTypes) && businessTypes.length
    ? { ...withAddress, business_types: businessTypes }
    : withAddress

  // Fallbacks apply ONLY to missing-column errors (pre-migration DB) — never to a
  // duplicate username, which must surface so the user picks a different address.
  const columnMissing = (e) => !!e && e.code !== '23505' && /could not find|does not exist|schema cache/i.test(e.message || '')

  let { error: dbErr } = await supabase.from('admin_users').insert(full)
  if (columnMissing(dbErr)) {
    ;({ error: dbErr } = await supabase.from('admin_users').insert(withAddress))
    if (columnMissing(dbErr)) {
      ;({ error: dbErr } = await supabase.from('admin_users').insert(base))
    }
  }
  if (dbErr) {
    authError.value = dbErr.code === '23505'
      ? 'That business username is already taken — please choose another.'
      : dbErr.message
    return false
  }
  return true
}

// Change the signed-in user's own password (Supabase Auth). Used by the dashboard's
// Settings → Security card. Returns true on success; authError carries any failure.
export async function changeOwnPassword(newPassword) {
  authError.value = ''
  const { error } = await supabase.auth.updateUser({ password: newPassword })
  if (error) { authError.value = error.message; return false }
  return true
}
