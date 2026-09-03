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
import { supabase } from './supabase.js'
import { apiFetch } from './api.js'
import { adminBaseUrl } from './appBase.js'
import QRCode from 'qrcode'

// Unambiguous alphabet — no 0/O/1/I/L so codes survive being read out loud.
const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'
export function genCode(len = 6) {
  let out = ''
  const rnd = new Uint32Array(len)
  crypto.getRandomValues(rnd)
  for (let i = 0; i < len; i++) out += ALPHABET[rnd[i] % ALPHABET.length]
  return out
}

/** QR data-URL for a pass code (encodes the provider check-in deep link). */
export async function passQrDataUrl(code) {
  const url = `${adminBaseUrl()}/checkin?code=${encodeURIComponent(code)}`
  try {
    return await QRCode.toDataURL(url, { width: 480, margin: 1, errorCorrectionLevel: 'M' })
  } catch { return '' }
}

/** Get-or-create the pass row for one booking/membership. */
export async function ensurePass({ kind, refId, ownerUserId, customerId, customerEmail }) {
  const { data: existing } = await supabase.from('entry_passes')
    .select('*').eq('kind', kind).eq('ref_id', refId).maybeSingle()
  if (existing) return existing
  // retry a few times in case of a (vanishingly rare) code collision
  for (let i = 0; i < 4; i++) {
    const row = {
      kind, ref_id: refId, owner_user_id: ownerUserId || null,
      customer_id: customerId || null, customer_email: customerEmail || null,
      code: genCode(),
    }
    const { data, error } = await supabase.from('entry_passes').insert(row).select().maybeSingle()
    if (data) return data
    // unique(kind,ref) race: someone else created it — fetch theirs
    if (error?.code === '23505' && error.message?.includes('entry_passes_kind_ref_id_key')) {
      const { data: again } = await supabase.from('entry_passes')
        .select('*').eq('kind', kind).eq('ref_id', refId).maybeSingle()
      if (again) return again
    }
    if (error && error.code !== '23505') return null   // table missing (migration not run) etc.
  }
  return null
}

/** ONE pass per member (kind 'member', ref = customers.id). Needs the member_pass
 *  migration (kind CHECK) — pre-migration the insert is rejected and this returns
 *  null, so callers fall back to per-membership passes. */
export async function ensureMemberPass({ customerId, email }) {
  if (!customerId) return null
  return ensurePass({ kind: 'member', refId: customerId, ownerUserId: null, customerId, customerEmail: email || '' })
}

/** A customer's LIVE memberships (id+email matched, lapsed unconverted trials
 *  dropped), decorated for pass cards. Shared by the member-card bundle below. */
async function liveMembershipsFor({ customerId, email }) {
  const [byId, byEmail] = await Promise.all([
    customerId ? supabase.from('class_subscriptions').select('*')
      .eq('customer_id', customerId).in('status', ['active', 'trial', 'trialing', 'paused', 'past_due'])
      .order('created_at', { ascending: false }).limit(20) : Promise.resolve({ data: [] }),
    email ? supabase.from('class_subscriptions').select('*')
      .ilike('customer_email', email).in('status', ['active', 'trial', 'trialing', 'paused', 'past_due'])
      .order('created_at', { ascending: false }).limit(20) : Promise.resolve({ data: [] }),
  ])
  const seen = new Set()
  const today = new Date().toISOString().slice(0, 10)
  const rows = [...(byId.data || []), ...(byEmail.data || [])]
    .filter(s => !seen.has(s.id) && seen.add(s.id))
    .filter(s => !(s.trial_status === 'in_trial' && s.trial_ends_at && String(s.trial_ends_at).slice(0, 10) < today))
  const pids = [...new Set(rows.map(s => s.program_id).filter(Boolean))]
  let programs = []
  if (pids.length) { const x = await supabase.from('class_programs').select('*').in('id', pids); programs = x.data || [] }
  return rows.map(s => {
    const prog = programs.find(p => p.id === s.program_id)
    const joiningFee = Math.max(0, Number(s.joining_fee ?? prog?.joining_fee) || 0)
    return {
      sub: s, prog,
      title: prog?.name || 'Membership',
      status: s.trial_status === 'in_trial' ? 'trial' : s.status,
      nextBilling: s.next_billing_at || '',
      joiningFee,
      feePaid: joiningFee > 0 && (!!s.first_charge_at || s.trial_status !== 'in_trial'),
    }
  })
}

/** The member-card bundle: ONE identity pass + the membership rows shown on it.
 *  Pre-migration (member kind rejected) → { pass: null } and the caller renders
 *  the legacy per-membership cards from loadCustomerPasses. */
export async function loadMemberPassBundle({ customerId, email }) {
  const memberships = await liveMembershipsFor({ customerId, email })
  if (!memberships.length) return { pass: null, memberships: [] }
  const pass = await ensureMemberPass({ customerId, email })
  return { pass, memberships }
}

/**
 * Customer side: MEMBERSHIP passes only. Bookings don't get passes — a booking is a
 * one-off with a name on it; the abuse the pass fights is SUBSCRIPTION sharing
 * (one member's plan used by many people), so the pass proves the member's identity
 * on every gym/class/academy visit.
 * Returns [{ pass, kind: 'membership', title, status }] newest first.
 */
export async function loadCustomerPasses({ customerId, email }) {
  const out = []

  if (email) {
    // Include paused / past_due members too — a lapsed card shouldn't wipe the pass
    // (the provider may still admit them and it's how they re-enter to fix payment).
    // The pass card + door lookup surface the real state. select('*') so optional
    // columns (trial_ends_at, first_charge_at, joining_fee) ride along when present.
    const { data: subs } = await supabase.from('class_subscriptions')
      .select('*')
      .eq('customer_email', email).in('status', ['active', 'trial', 'trialing', 'paused', 'past_due'])
      .order('created_at', { ascending: false }).limit(20)
    // A free trial that ENDED without converting is not a live membership — its
    // pass disappears (re-joining or the provider's conversion charge revives it).
    const today = new Date().toISOString().slice(0, 10)
    const rows = (subs || []).filter(s =>
      !(s.trial_status === 'in_trial' && s.trial_ends_at && String(s.trial_ends_at).slice(0, 10) < today))
    const pids = [...new Set(rows.map(s => s.program_id).filter(Boolean))]
    let programs = []
    if (pids.length) { const x = await supabase.from('class_programs').select('*').in('id', pids); programs = x.data || [] }
    for (const s of rows) {
      const prog = programs.find(p => p.id === s.program_id)
      const pass = await ensurePass({
        kind: 'membership', refId: s.id, ownerUserId: prog?.owner_user_id || null,
        customerId: customerId || null, customerEmail: email,
      })
      if (!pass) continue
      const joiningFee = Math.max(0, Number(s.joining_fee ?? prog?.joining_fee) || 0)
      out.push({
        pass, kind: 'membership',
        title: prog?.name || 'Membership',
        venue: '', date: '', start: '', end: '',
        // 'in_trial' is the live-trial marker ('active' means a PAID no-trial plan)
        status: s.trial_status === 'in_trial' ? 'trial' : s.status,
        joiningFee,
        // Paid once the first real charge happened (stamp), or for any non-trial
        // plan (those are charged at signup — including rows older than the stamp).
        feePaid: joiningFee > 0 && (!!s.first_charge_at || s.trial_status !== 'in_trial'),
      })
    }
  }
  return out
}

/**
 * Provider side: look up a code and return everything needed to verify at the
 * door. `viewer` = { userId, isPlatform } — non-platform staff only see passes
 * for their own business.
 */
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
