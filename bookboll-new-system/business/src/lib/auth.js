/**
 * auth.js — session awareness for the public B2B site (business.bookboll.com).
 *
 * This site has no sign-in screen of its own: signing in happens on the admin
 * app, and signing up happens here (Signup.vue → POST /api/business/register,
 * which stores a Sanctum token). All this module has to answer is "is the
 * visitor already a signed-in provider?", so the marketing pages can show
 * "Open dashboard" instead of "Sign in", and /signup can bounce them onward.
 *
 * Scope note: browsers keep localStorage per origin, so the token minted here is
 * not visible to admin.bookboll.com and vice versa. A provider who signed in on
 * the admin app therefore reads as signed-out here — the pages degrade to
 * "Sign in", which still takes them to the right place. Genuine cross-subdomain
 * SSO would need a shared cookie on .bookboll.com.
 */
import { ref } from 'vue'
import { apiFetch } from './api.js'
import { getApiToken, clearApiToken } from './businessAuth.js'

export const authUser = ref(null)      // { id, email, name } when signed in
export const authLoading = ref(true)
export const authError = ref('')

let booted = false

/** Hydrate from the stored Sanctum token. Safe to await repeatedly. */
export async function initAuth() {
  if (booted) {
    authLoading.value = false
    return authUser.value
  }
  booted = true

  const token = getApiToken()
  if (!token) {
    authUser.value = null
    authLoading.value = false
    return null
  }

  try {
    const res = await apiFetch('/me', { method: 'GET', token })
    if (!res.success || res.status === 401) {
      // Expired or revoked — drop it so the pages show "Sign in".
      clearApiToken()
      authUser.value = null
    } else {
      const user = res.data?.user || res.data || null
      authUser.value = user
        ? { id: String(user.id), email: user.email || '', name: user.name || '' }
        : null
    }
  } catch {
    // Never block the marketing site on an auth probe.
    authUser.value = null
  }

  authLoading.value = false
  return authUser.value
}

/** Forget the local session (the admin app owns real sign-out). */
export function signOut() {
  clearApiToken()
  authUser.value = null
}
