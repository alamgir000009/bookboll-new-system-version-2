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
import { adminBaseUrl } from './appBase.js'
import QRCode from 'qrcode'
import { apiLoadMemberPassBundle } from '../customer/apiPrograms.js'

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

/** ONE pass per member — Laravel creates/returns it with the pass bundle. */
export async function ensureMemberPass({ customerId, email }) {
  if (!customerId) return null
  const bundle = await apiLoadMemberPassBundle()
  return bundle.pass || null
}

/** A customer's LIVE memberships (staff door still uses Supabase until staff APIs migrate). */
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

/** The member-card bundle from Laravel. */
export async function loadMemberPassBundle({ customerId, email }) {
  if (!customerId && !email) return { pass: null, memberships: [] }
  const bundle = await apiLoadMemberPassBundle()
  return { pass: bundle.pass || null, memberships: bundle.memberships || [] }
}

/**
 * Customer side: MEMBERSHIP passes only (Laravel).
 */
export async function loadCustomerPasses({ customerId, email }) {
  if (!customerId && !email) return []
  const bundle = await apiLoadMemberPassBundle()
  return bundle.legacyPasses || []
}

/**
 * Provider side: look up a code and return everything needed to verify at the
 * door. `viewer` = { userId, isPlatform } — non-platform staff only see passes
 * for their own business.
 */
export async function lookupPass(code, viewer) {
  const clean = String(code || '').trim().toUpperCase()
  if (clean.length < 4) return { error: 'Enter the code shown on the customer’s pass.' }
  const { data: pass, error } = await supabase.from('entry_passes').select('*').eq('code', clean).maybeSingle()
  if (error) return { error: 'Could not look up the code. Has the entry-pass migration been run?' }
  if (!pass) return { error: 'No pass found for this code. Check the characters and try again.' }
  // Business scoping. If the pass was minted with a null owner (e.g. the program
  // fetch failed at creation time), don't fall open — derive the true owner from the
  // membership's program and enforce that, so a null-owner pass can't be checked in
  // by any business.
  let effectiveOwner = pass.owner_user_id || null
  if (!effectiveOwner && pass.kind === 'membership') {
    const { data: s0 } = await supabase.from('class_subscriptions').select('program_id').eq('id', pass.ref_id).maybeSingle()
    if (s0?.program_id) {
      const { data: p0 } = await supabase.from('class_programs').select('owner_user_id').eq('id', s0.program_id).maybeSingle()
      effectiveOwner = p0?.owner_user_id || null
      // Best-effort self-heal so the next lookup is scoped without this extra hop.
      if (effectiveOwner) supabase.from('entry_passes').update({ owner_user_id: effectiveOwner }).eq('id', pass.id).then(() => {}, () => {})
    }
  }
  if (!viewer?.isPlatform && effectiveOwner && viewer?.userId && effectiveOwner !== viewer.userId) {
    return { error: 'This pass belongs to another business on Bookboll.' }
  }

  const result = { pass, kind: pass.kind, customer: null, booking: null, membership: null, warnings: [], attendance: [] }

  // Who is the member? The PHOTO is the identity check: staff compare it with the
  // person standing in front of them — a borrowed phone can show the QR, not the face.
  if (pass.customer_id) {
    const { data: c } = await supabase.from('customers').select('id, full_name, email, phone, avatar_url').eq('id', pass.customer_id).maybeSingle()
    result.customer = c || null
  }
  if (!result.customer && pass.customer_email) {
    const { data: c } = await supabase.from('customers').select('id, full_name, email, phone, avatar_url').eq('email', pass.customer_email).maybeSingle()
    result.customer = c || { full_name: '', email: pass.customer_email, phone: '', avatar_url: '' }
  }

  // ── MEMBER card (one QR for all of a customer's memberships) ─────────────────
  // The pass belongs to the member (owner NULL is expected); business scoping
  // happens on the membership LIST: the scanner only sees memberships at THEIR
  // business (platform staff see all).
  if (pass.kind === 'member') {
    if (!result.customer && pass.ref_id) {
      const { data: c } = await supabase.from('customers').select('id, full_name, email, phone, avatar_url').eq('id', pass.ref_id).maybeSingle()
      result.customer = c || null
    }
    const all = await liveMembershipsFor({ customerId: pass.ref_id, email: pass.customer_email })
    const mine = all.filter(m => viewer?.isPlatform || !m.prog?.owner_user_id || m.prog.owner_user_id === viewer?.userId)
    if (!mine.length) return { error: 'This member has no memberships at your business.' }
    result.memberships = mine.map(m => ({
      ...m.sub, programName: m.title, joiningFee: m.joiningFee, feePaid: m.feePaid, nextBilling: m.nextBilling,
    }))
    // Recent attendance: logs on this pass + (post-migration) logs stamped with
    // any of these subscriptions (covers legacy per-membership pass scans too).
    const subIds = mine.map(m => m.sub.id)
    const { data: logsA } = await supabase.from('attendance_logs')
      .select('*').eq('pass_id', pass.id).order('checked_at', { ascending: false }).limit(10)
    let logsB = []
    try {
      const r = await supabase.from('attendance_logs')
        .select('*').in('subscription_id', subIds).order('checked_at', { ascending: false }).limit(10)
      logsB = r.error ? [] : (r.data || [])
    } catch { /* column not migrated yet */ }
    const seenLog = new Set()
    result.attendance = [...(logsA || []), ...logsB]
      .filter(l => !seenLog.has(l.id) && seenLog.add(l.id))
      .sort((a, b) => new Date(b.checked_at) - new Date(a.checked_at)).slice(0, 10)
    if (result.attendance.length) {
      const last = new Date(result.attendance[0].checked_at).getTime()
      if (Date.now() - last < 60 * 60 * 1000) {
        result.warnings.push('Already checked in less than an hour ago — possible pass sharing. Verify identity.')
      }
    }
    return result
  }

  if (pass.kind !== 'membership') {
    return { error: 'This code is not a membership pass.' }
  } else {
    const { data: s } = await supabase.from('class_subscriptions')
      .select('*')
      .eq('id', pass.ref_id).maybeSingle()
    if (!s) return { error: 'The membership behind this pass no longer exists.' }
    const { data: prog } = s.program_id
      ? await supabase.from('class_programs').select('*').eq('id', s.program_id).maybeSingle()
      : { data: null }
    const joiningFee = Math.max(0, Number(s.joining_fee ?? prog?.joining_fee) || 0)
    result.membership = {
      ...s, programName: prog?.name || 'Membership',
      joiningFee,
      feePaid: joiningFee > 0 && (!!s.first_charge_at || s.trial_status !== 'in_trial'),
    }
    if (!result.customer && (s.customer_name || s.customer_email)) {
      result.customer = { full_name: s.customer_name || '', email: s.customer_email || '', phone: '' }
    }
    if (!['active', 'trial', 'trialing'].includes(s.status) && s.trial_status !== 'in_trial') {
      result.warnings.push(`Membership status is "${s.status}" — not active.`)
    }
    // A trial that ended without converting = not paid. Flag it loudly at the door.
    if (s.trial_status === 'in_trial' && s.trial_ends_at && String(s.trial_ends_at).slice(0, 10) < new Date().toISOString().slice(0, 10)) {
      result.warnings.push(`Free trial ended on ${String(s.trial_ends_at).slice(0, 10)} and was never converted to a paid membership.`)
    }
  }

  const { data: logs } = await supabase.from('attendance_logs')
    .select('id, checked_at, note').eq('pass_id', pass.id)
    .order('checked_at', { ascending: false }).limit(10)
  result.attendance = logs || []
  // Members visit repeatedly — that's normal. A SECOND check-in within the hour is
  // the sharing signal (one person walks in, hands the pass to the next).
  if (result.attendance.length) {
    const last = new Date(result.attendance[0].checked_at).getTime()
    if (Date.now() - last < 60 * 60 * 1000) {
      result.warnings.push('Already checked in less than an hour ago — possible pass sharing. Verify identity.')
    }
  }
  return result
}

/** Confirm attendance for a pass. Idempotent within a short window so a double-tap
 *  (or a re-scan of the same person) doesn't log two visits. programId/subscriptionId
 *  say WHICH class the visit was for (member-level passes span several) — written
 *  best-effort so pre-migration DBs still log the visit. */
export async function recordAttendance({ passId, checkedBy, note, programId = null, subscriptionId = null }) {
  const since = new Date(Date.now() - 2 * 60 * 1000).toISOString()
  const { data: dupe } = await supabase.from('attendance_logs')
    .select('id, checked_at').eq('pass_id', passId).gt('checked_at', since)
    .order('checked_at', { ascending: false }).limit(1).maybeSingle()
  if (dupe) return { log: dupe, duplicate: true }
  const base = { pass_id: passId, checked_by: checkedBy || null, note: note || null }
  let { data, error } = await supabase.from('attendance_logs')
    .insert({ ...base, program_id: programId, subscription_id: subscriptionId })
    .select().maybeSingle()
  if (error) ({ data, error } = await supabase.from('attendance_logs').insert(base).select().maybeSingle())
  return error ? { error: error.message } : { log: data }
}

/**
 * Provider door view: TODAY's class sessions with live rosters — who's expected
 * (live members of each program running today) and who has already checked in.
 * Gym-access programs are skipped (no discrete session to roster).
 */
export async function loadTodayRoster(ownerUserId) {
  if (!ownerUserId) return []
  const { data: progs } = await supabase.from('class_programs').select('*')
    .eq('owner_user_id', ownerUserId).eq('status', 'active')
  const now = new Date()
  const dow = now.getDay()
  const todayStr = now.toISOString().slice(0, 10)
  const sessionsToday = (progs || []).filter(p =>
    String(p.category || '') !== 'gym' &&
    Array.isArray(p.day_of_week_mask) && p.day_of_week_mask.includes(dow) && p.session_time)
  if (!sessionsToday.length) return []

  const pids = sessionsToday.map(p => p.id)
  const { data: subs } = await supabase.from('class_subscriptions').select('*')
    .in('program_id', pids).in('status', ['active', 'trial', 'trialing', 'paused', 'past_due'])
  const live = (subs || []).filter(s =>
    !(s.trial_status === 'in_trial' && s.trial_ends_at && String(s.trial_ends_at).slice(0, 10) < todayStr))

  // Today's attendance for these programs: stamped logs (member-card scans and
  // roster marks) + legacy per-membership pass scans resolved via the pass table.
  const startOfDay = todayStr + 'T00:00:00'
  const checkedSubs = new Set()
  try {
    const r = await supabase.from('attendance_logs').select('subscription_id, program_id, checked_at')
      .gte('checked_at', startOfDay).in('program_id', pids)
    for (const l of (r.error ? [] : (r.data || []))) if (l.subscription_id) checkedSubs.add(l.subscription_id)
  } catch { /* pre-migration */ }
  const subIds = live.map(s => s.id)
  if (subIds.length) {
    const { data: passes } = await supabase.from('entry_passes')
      .select('id, ref_id').eq('kind', 'membership').in('ref_id', subIds)
    const passToSub = new Map((passes || []).map(p => [p.id, p.ref_id]))
    if (passToSub.size) {
      const { data: l2 } = await supabase.from('attendance_logs').select('pass_id, checked_at')
        .gte('checked_at', startOfDay).in('pass_id', [...passToSub.keys()])
      for (const l of (l2 || [])) checkedSubs.add(passToSub.get(l.pass_id))
    }
  }

  return sessionsToday.map(p => {
    const roster = live.filter(s => s.program_id === p.id).map(s => ({
      id: s.id, sub: s,
      name: s.customer_name || s.customer_email || 'Member',
      status: s.trial_status === 'in_trial' ? 'trial' : s.status,
      checkedIn: checkedSubs.has(s.id),
    })).sort((a, b) => a.name.localeCompare(b.name))
    return {
      program: p, time: p.session_time,
      roster, expected: roster.length,
      present: roster.filter(r => r.checkedIn).length,
    }
  }).sort((a, b) => String(a.time).localeCompare(String(b.time)))
}

/** Roster's manual "Mark present" (member forgot their phone) — logs through the
 *  same attendance trail as a QR scan. */
export async function markPresent(sub, program, checkedBy) {
  const pass = await ensurePass({
    kind: 'membership', refId: sub.id, ownerUserId: program.owner_user_id || null,
    customerId: sub.customer_id || null, customerEmail: sub.customer_email || '',
  })
  if (!pass) return { error: 'Could not create a pass for this member.' }
  return recordAttendance({
    passId: pass.id, checkedBy, note: 'Marked present from the class roster',
    programId: program.id, subscriptionId: sub.id,
  })
}

/** Provider's recent check-ins (for the Check-in page sidebar). Log-first so the
 *  15 shown are genuinely the newest (was: newest-among-an-arbitrary-400-passes),
 *  and each carries the member's name for a readable "Jane · 2m ago" row. */
export async function loadRecentCheckins(ownerUserId, isPlatform) {
  let logs, passById
  if (!isPlatform && ownerUserId) {
    // Provider: scope PASSES to the owner first, then pull the newest logs FOR THOSE
    // passes. (Log-first with a global newest-N window would hide a low-traffic
    // provider's check-ins the moment busier venues push them past the window.)
    const { data: passes } = await supabase.from('entry_passes')
      .select('id, kind, code, customer_id, customer_email, owner_user_id')
      .eq('owner_user_id', ownerUserId).limit(2000)
    passById = new Map((passes || []).map(p => [p.id, p]))
    let logsA = []
    if (passes?.length) {
      const { data } = await supabase.from('attendance_logs')
        .select('id, pass_id, checked_at, note')
        .in('pass_id', passes.map(p => p.id))
        .order('checked_at', { ascending: false }).limit(15)
      logsA = data || []
    }
    // Member-card scans carry no owner on the pass — they're found via the
    // program stamp instead (best-effort pre-migration).
    let logsB = []
    try {
      const { data: myProgs } = await supabase.from('class_programs').select('id').eq('owner_user_id', ownerUserId)
      const pgIds = (myProgs || []).map(p => p.id)
      if (pgIds.length) {
        const r = await supabase.from('attendance_logs')
          .select('id, pass_id, checked_at, note')
          .in('program_id', pgIds).order('checked_at', { ascending: false }).limit(15)
        logsB = r.error ? [] : (r.data || [])
      }
    } catch { /* column not migrated yet */ }
    const seenIds = new Set()
    logs = [...logsA, ...logsB]
      .filter(l => !seenIds.has(l.id) && seenIds.add(l.id))
      .sort((a, b) => new Date(b.checked_at) - new Date(a.checked_at)).slice(0, 15)
    if (!logs.length) return []
    // Fetch the (member-card) passes the owner-scoped query didn't cover.
    const missing = [...new Set(logs.map(l => l.pass_id).filter(id => id && !passById.has(id)))]
    if (missing.length) {
      const { data: extra } = await supabase.from('entry_passes')
        .select('id, kind, code, customer_id, customer_email, owner_user_id').in('id', missing)
      for (const p of (extra || [])) passById.set(p.id, p)
    }
  } else {
    // Platform (super admin): sees everything, so the global newest window is correct.
    const { data } = await supabase.from('attendance_logs')
      .select('id, pass_id, checked_at, note')
      .order('checked_at', { ascending: false }).limit(60)
    logs = (data || []).slice(0, 15)
    const passIds = [...new Set(logs.map(l => l.pass_id))]
    const { data: passes } = passIds.length
      ? await supabase.from('entry_passes').select('id, kind, code, customer_id, customer_email, owner_user_id').in('id', passIds)
      : { data: [] }
    passById = new Map((passes || []).map(p => [p.id, p]))
  }
  if (!logs?.length) return []
  const shown = logs.filter(l => passById.has(l.pass_id)).slice(0, 15)
  // Resolve member names (by id, then email) only for the passes we actually show.
  const shownPasses = shown.map(l => passById.get(l.pass_id))
  const cids = [...new Set(shownPasses.map(p => p.customer_id).filter(Boolean))]
  const emails = [...new Set(shownPasses.map(p => p.customer_email).filter(Boolean))]
  const nameById = new Map(), nameByEmail = new Map()
  if (cids.length) { const { data } = await supabase.from('customers').select('id, full_name').in('id', cids); (data || []).forEach(c => nameById.set(c.id, c.full_name)) }
  if (emails.length) { const { data } = await supabase.from('customers').select('email, full_name').in('email', emails); (data || []).forEach(c => nameByEmail.set(c.email, c.full_name)) }
  return shown.map(l => {
    const pass = passById.get(l.pass_id)
    const name = nameById.get(pass.customer_id) || nameByEmail.get(pass.customer_email) || ''
    return { ...l, pass, memberName: name }
  })
}
