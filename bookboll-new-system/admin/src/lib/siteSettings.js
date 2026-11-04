/**
 * siteSettings.js — the "My Website" group (Site, Banners, Domain, Embed,
 * Blog, Tracking).
 *
 * One endpoint, two destinations, decided server-side by who is asking:
 * a provider edits their own white-label site, a platform admin edits the
 * marketplace. The caller never says which — so a provider can't write the
 * marketplace's branding.
 */
import { apiFetch } from './api.js'

const EMPTY = {
  mode: 'provider', username: null, plan: 'free', settings: {},
  counts: { venues: 0, programs: 0, posts: 0 },
}

/** @returns {Promise<{mode:'provider'|'platform', username:?string, plan:string, settings:object, counts:object}>} */
export async function loadSiteSettings() {
  const res = await apiFetch('/admin/site-settings')
  if (!res.success) return { ...EMPTY, success: false }
  return {
    success: true,
    mode: res.data?.mode || 'provider',
    username: res.data?.username ?? null,
    plan: res.data?.plan || 'free',
    settings: res.data?.settings || {},
    counts: res.data?.counts || { venues: 0, programs: 0, posts: 0 },
  }
}

/**
 * Save only the fields you pass — anything omitted is left untouched, so a page
 * can save one toggle without resending the whole form.
 */
export async function saveSiteSettings(patch) {
  return apiFetch('/admin/site-settings', { method: 'PUT', body: patch })
}

/** Read the same public projection that visitors receive, without an admin token. */
export async function loadPublicSitePages(ownerId) {
  if (!ownerId) return { success: false }
  const res = await apiFetch('/marketplace/brand', {
    token: '', query: { owner_id: ownerId, preview: 1 },
  })
  const brand = res.data?.brand
  if (!res.success || !brand || String(brand.ownerUserId) !== String(ownerId)) return { success: false }
  return { success: true, pages: brand.pages || {} }
}
