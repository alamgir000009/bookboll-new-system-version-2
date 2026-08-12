/**
 * adminNotify.js — write a notification to a BUSINESS OWNER's dashboard bell.
 *
 * Rows land in admin_notifications (see 20260753000000_admin_notifications.sql)
 * and are merged into the bell feed by lib/bellFeed.js. Use for admin DECISIONS
 * the derived feed can't see (approvals, changes requested, KYC outcomes) —
 * routine events (new bookings/members) stay derived.
 *
 * Best-effort by design: a pre-migration DB (table missing) must never break
 * the action that triggered the notification.
 */
import { supabase } from './supabase.js'

export async function notifyBusiness({ ownerUserId, kind = 'info', tone = 'info', title, sub = '', link = '' }) {
  if (!ownerUserId || !title) return
  try {
    await supabase.from('admin_notifications').insert({
      user_id: ownerUserId, kind, tone, title, sub, link,
    })
  } catch { /* best-effort */ }
}
