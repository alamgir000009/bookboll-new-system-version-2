/**
 * notificationSweep.js — fires the SCHEDULED notifications that have no single "moment":
 *   • booking_reminder            — X days before a booking (remind_before_days)
 *   • review_request              — the day after a booking (post-visit)
 *   • trial_ending                — X days before a trial converts (trial_ending_days)
 *   • membership_renewal_reminder — X days before a recurring charge (renewal_reminder_days)
 *
 * There is no server cron yet, so the sweep still runs when a provider opens their
 * dashboard. What changed is WHERE the work happens: the backend decides what is due
 * (scoped to the caller, so a provider can only sweep their own bookings and members)
 * and owns the once-only log; this file only dispatches, through the same notification
 * path as every other message. `cycle_key` is the date the reminder is FOR, so the same
 * subscription is reminded again next cycle but never twice for the same one.
 *
 * Fire-and-forget throughout — a sweep hiccup never affects the dashboard.
 */
import { apiFetch } from '../lib/api.js'
import { loadProviderNotificationSettings } from '../lib/notificationTemplates.js'
import { notifyBookingReminder, notifyReviewRequest, notifyTrialEnding, notifyRenewalReminder } from './notificationDispatch.js'

const fmtDate = (d) => {
  try {
    return new Date(String(d).slice(0, 10) + 'T00:00:00')
      .toLocaleDateString('en-US', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' })
  } catch { return '' }
}

/** Record a reminder as sent so re-opening the dashboard can't send it twice. */
async function markSent(item, subjectType) {
  try {
    await apiFetch('/admin/notifications/sweep/sent', {
      method: 'POST',
      body: {
        event_key: item.event_key,
        subject_type: subjectType,
        subject_id: String(item.subject_id),
        cycle_key: item.cycle_key,
      },
    })
  } catch { /* the unique index is the real guarantee */ }
}

const sweptOwners = new Set()   // once per owner per app session (the log covers repeats)

/**
 * Run the reminder sweep for one provider. Safe to call on every dashboard mount.
 * Pass no ownerId (or a falsy one) to no-op.
 */
export async function runNotificationSweep(ownerId) {
  if (!ownerId || sweptOwners.has(ownerId)) return
  sweptOwners.add(ownerId)
  try {
    const { settings } = await loadProviderNotificationSettings(ownerId)
    const res = await apiFetch('/admin/notifications/sweep', {
      query: {
        remind_before_days: Math.max(0, Number(settings?.remind_before_days) || 1),
        trial_ending_days: Math.max(0, Number(settings?.trial_ending_days) || 3),
        renewal_reminder_days: Math.max(0, Number(settings?.renewal_reminder_days) || 3),
      },
    })
    if (!res.success) return

    for (const item of (res.data?.bookings || [])) {
      const booking = { ...item.booking, date: fmtDate(item.booking?.date) }
      if (item.event_key === 'booking_reminder') notifyBookingReminder({ ownerId, customer: item.customer, booking })
      else notifyReviewRequest({ ownerId, customer: item.customer, booking })
      await markSent(item, 'booking')
    }

    for (const item of (res.data?.subscriptions || [])) {
      if (item.event_key === 'trial_ending') notifyTrialEnding({ ownerId, sub: item.subscription, program: item.program })
      else notifyRenewalReminder({ ownerId, sub: item.subscription, program: item.program })
      await markSent(item, 'subscription')
    }
  } catch { /* never break the dashboard */ }
}
