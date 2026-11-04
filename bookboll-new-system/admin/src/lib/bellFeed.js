/**
 * bellFeed.js — the dashboard notification bell.
 *
 * Notifications are stored server-side (Laravel notifications) and written when
 * something happens, so read state lives on the server too and follows the user
 * across devices.
 */
import { apiFetch } from './api.js'

/** @returns { items: [{ id, kind, tone, ts, to, title, sub, read }], unread } */
export async function loadBellFeed() {
  const res = await apiFetch('/admin/notifications/feed')
  if (!res.success) return { items: [], unread: 0 }
  return { items: res.data?.items || [], unread: res.data?.unread || 0 }
}

export async function markAllRead() {
  await apiFetch('/admin/notifications/read-all', { method: 'POST' })
}
