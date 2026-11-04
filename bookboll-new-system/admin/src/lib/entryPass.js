/**
 * entryPass.js — entry passes (QR + short text code) and attendance check-in.
 *
 * WHY THIS SHAPE: one pass per booking / membership, generated LAZILY the first
 * time the customer opens "My Pass" (so no write is needed at checkout time and
 * pre-existing bookings get passes too). The QR encodes a provider-side URL
 * (`{origin}/checkin?code=XXXXXX`) — the provider scans it with any phone
 * camera, which opens their auth-gated dashboard check-in page with the code
 * prefilled. No in-app camera/scanner library needed; typing the 6-char code
 * works exactly the same.
 *
 * Scoping: lookups verify the pass belongs to the signed-in provider
 * (owner_user_id) unless the caller is platform staff.
 */
import { apiFetch } from './api.js'
import { adminBaseUrl } from './appBase.js'
import QRCode from 'qrcode'

// Unambiguous alphabet — no 0/O/1/I/L so codes survive being read out loud.
const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'
export async function lookupPass(code) {
  const clean = String(code || '').trim().toUpperCase()
  if (clean.length < 4) return { error: 'Enter the code shown on the customer’s pass.' }
  const res = await apiFetch('/admin/checkin/lookup', { query: { code: clean } })
  if (!res.success) return { error: res.message || 'No pass found for this code.' }
  return res.data
}

/** Confirm attendance for a pass. Idempotent within a short window so a double-tap
 *  (or a re-scan of the same person) doesn't log two visits. programId/subscriptionId
 *  say WHICH class the visit was for (member-level passes span several) — written
 *  best-effort so pre-migration DBs still log the visit. */
export async function recordAttendance({ passId, note, programId = null, subscriptionId = null }) {
  const res = await apiFetch('/admin/checkin/attendance', {
    method: 'POST',
    body: { pass_id: passId, note: note || null, program_id: programId, subscription_id: subscriptionId },
  })
  if (!res.success) return { error: res.message || 'Could not record the check-in.' }
  return { log: res.data.log, duplicate: !!res.data.duplicate }
}

/**
 * Provider door view: TODAY's class sessions with live rosters — who's expected
 * (live members of each program running today) and who has already checked in.
 * Gym-access programs are skipped (no discrete session to roster).
 */
export async function loadTodayRoster() {
  const res = await apiFetch('/admin/checkin/roster')
  return res.success ? (res.data.sessions || []) : []
}

/** Roster's manual "Mark present" (member forgot their phone) — logs through the
 *  same attendance trail as a QR scan. */
export async function markPresent(sub) {
  const res = await apiFetch('/admin/checkin/mark-present', {
    method: 'POST',
    body: { subscription_id: sub.id },
  })
  if (!res.success) return { error: res.message || 'Could not mark this member present.' }
  return { log: res.data.log, duplicate: !!res.data.duplicate }
}

/** Provider's recent check-ins (for the Check-in page sidebar). Log-first so the
 *  15 shown are genuinely the newest (was: newest-among-an-arbitrary-400-passes),
 *  and each carries the member's name for a readable "Jane · 2m ago" row. */
export async function loadRecentCheckins() {
  const res = await apiFetch('/admin/checkin/recent')
  return res.success ? (res.data.checkins || []) : []
}
