/**
 * bellFeed.js — the dashboard notification bell's feed (providers + Bookboll staff).
 *
 * There is no notifications table or server worker in this stack, so the feed is
 * DERIVED: each refresh queries the recent rows of the domain tables the viewer
 * cares about and renders them as one time-ordered list.
 *
 *   Business persona → new bookings, new members, approval outcomes on their
 *   listings/programs, payout-method status, withdrawal updates.
 *   Platform staff   → listings/programs awaiting approval, payout methods to
 *   review, pending withdrawal requests, new provider signups, plus recent
 *   bookings/members in their jurisdiction.
 *
 * Read state is a per-user "last seen" timestamp in localStorage — anything
 * newer counts as unread. Every query is best-effort (missing tables/columns on
 * an un-migrated DB just contribute nothing).
 */
import { supabase } from './supabase.js'

const WINDOW_DAYS = 14
const sinceIso = () => new Date(Date.now() - WINDOW_DAYS * 864e5).toISOString()

const seenKey = (userId) => `bb_bellseen_${userId || 'anon'}`
export function getLastSeen(userId) {
  try { return Number(localStorage.getItem(seenKey(userId))) || 0 } catch { return 0 }
}
export function setLastSeen(userId) {
  try { localStorage.setItem(seenKey(userId), String(Date.now())) } catch {}
}

async function safe(q) {
  try { const r = await q; return r.error ? [] : (r.data || []) } catch { return [] }
}

/**
 * @param ctx {{
 *   platform: boolean,           // viewing as Bookboll staff (vs a business persona)
 *   restricted: boolean,         // platform staff with a jurisdiction (not super admin)
 *   ownerUserId: string|null,    // business persona's owner id
 *   locations: Array<{id,name,owner_user_id}>,  // ALREADY scoped to the viewer
 * }}
 * @returns [{ id, kind, tone, ts, to, title, sub }] newest first (≤30)
 */
export async function loadBellFeed({ platform, restricted, ownerUserId, locations }) {
  const since = sinceIso()
  const locIds = locations.map(l => l.id)
  const locIdSet = new Set(locIds)
  const locName = new Map(locations.map(l => [l.id, l.name]))
  const items = []
  const push = (x) => { if (x.ts) items.push(x) }

  if (!platform) {
    if (!ownerUserId) return []
    const [rows, bks, lst, pgs, wls, wds] = await Promise.all([
      // Stored decision notifications (admin_notifications) — approvals, changes
      // requested, KYC outcomes. The queries below stay as a DERIVED fallback for
      // a pre-migration DB; identical titles are deduped further down.
      safe(supabase.from('admin_notifications')
        .select('id, kind, tone, title, sub, link, created_at')
        .eq('user_id', ownerUserId).gte('created_at', since)
        .order('created_at', { ascending: false }).limit(20)),
      locIds.length ? safe(supabase.from('bookings')
        .select('id, listing_id, location_id, customer_id, booking_date, start_time, total, created_at')
        .in('location_id', locIds).gte('created_at', since)
        .order('created_at', { ascending: false }).limit(12)) : [],
      locIds.length ? safe(supabase.from('listings')
        .select('id, name, approval_status, eligibility_status, admin_note, created_at').in('location_id', locIds)) : [],
      safe(supabase.from('class_programs')
        .select('id, name, approval_status, eligibility_status, admin_note, created_at').eq('owner_user_id', ownerUserId)),
      safe(supabase.from('provider_wallets')
        .select('id, setup_status, kyc_rejection_reason, updated_at, created_at').eq('owner_user_id', ownerUserId)),
      safe(supabase.from('withdrawal_requests')
        .select('id, amount, currency, status, rejection_reason, requested_at, approved_at, processed_at')
        .eq('owner_user_id', ownerUserId).order('requested_at', { ascending: false }).limit(6)),
    ])
    const storedTitles = new Set(rows.map(r => r.title))
    rows.forEach(r => push({
      id: 'n' + r.id, kind: r.kind || 'info', tone: r.tone || 'info', ts: r.created_at,
      to: r.link || '/admin', title: r.title, sub: r.sub || '',
    }))
    // Derived items skip anything a stored row already covers (same title).
    const pushDerived = (x) => { if (!storedTitles.has(x.title)) push(x) }
    const pgIds = pgs.map(p => p.id)
    const subs = pgIds.length ? await safe(supabase.from('class_subscriptions')
      .select('id, program_id, customer_name, customer_email, created_at')
      .in('program_id', pgIds).gte('created_at', since)
      .order('created_at', { ascending: false }).limit(12)) : []
    const custIds = [...new Set(bks.map(b => b.customer_id).filter(Boolean))]
    const custs = custIds.length ? await safe(supabase.from('customers').select('id, full_name').in('id', custIds)) : []
    const custName = new Map(custs.map(c => [c.id, c.full_name]))
    const lstName = new Map(lst.map(l => [l.id, l.name]))

    bks.forEach(b => push({
      id: 'bk' + b.id, kind: 'booking', tone: 'ok', ts: b.created_at, to: '/bookings',
      title: `New booking — ${lstName.get(b.listing_id) || locName.get(b.location_id) || 'your venue'}`,
      sub: [custName.get(b.customer_id), b.booking_date, b.start_time].filter(Boolean).join(' · '),
    }))
    subs.forEach(s => push({
      id: 'sub' + s.id, kind: 'member', tone: 'ok', ts: s.created_at, to: '/subscriptions',
      title: `New member — ${s.customer_name || s.customer_email || 'a customer'}`,
      sub: pgs.find(p => p.id === s.program_id)?.name || 'Membership',
    }))
    // Changes requested: the Approvals "send back" sets eligibility_status='paused'
    // + admin_note (approval_status stays 'approved'); legacy rows used 'rejected'.
    const changesRequested = (x) => x.approval_status === 'rejected' || (x.eligibility_status === 'paused' && x.admin_note)
    lst.filter(changesRequested).forEach(l => pushDerived({
      id: 'lr' + l.id, kind: 'approval', tone: 'warn', ts: l.created_at, to: '/listings',
      title: `Changes requested — “${l.name}”`,
      sub: l.admin_note || 'Update the listing and resubmit it for review',
    }))
    lst.filter(l => l.approval_status === 'pending' && l.created_at >= since).forEach(l => push({
      id: 'lp' + l.id, kind: 'approval', tone: 'info', ts: l.created_at, to: '/listings',
      title: `“${l.name}” is awaiting Bookboll approval`,
      sub: 'It goes live on the marketplace once approved',
    }))
    pgs.filter(changesRequested).forEach(p => pushDerived({
      id: 'pr' + p.id, kind: 'approval', tone: 'warn', ts: p.created_at, to: '/listings',
      title: `Changes requested — “${p.name}”`,
      sub: p.admin_note || 'Update the program and resubmit it for review',
    }))
    pgs.filter(p => p.approval_status === 'pending' && p.created_at >= since).forEach(p => push({
      id: 'pp' + p.id, kind: 'approval', tone: 'info', ts: p.created_at, to: '/listings',
      title: `“${p.name}” is awaiting Bookboll approval`,
      sub: 'It goes live on the marketplace once approved',
    }))
    wls.forEach(w => {
      if (w.setup_status === 'pending_review') push({
        id: 'w' + w.id, kind: 'wallet', tone: 'info', ts: w.updated_at || w.created_at, to: '/wallet',
        title: 'Payout method under review',
        sub: 'Bookboll is verifying your payout details',
      })
      else if (w.setup_status === 'active' && (w.updated_at || '') >= since) pushDerived({
        id: 'w' + w.id, kind: 'wallet', tone: 'ok', ts: w.updated_at, to: '/wallet',
        title: 'Payout method approved — payouts are live',
        sub: 'Online money now flows to your wallet',
      })
      else if (w.setup_status === 'rejected') pushDerived({
        id: 'w' + w.id, kind: 'wallet', tone: 'warn', ts: w.updated_at || w.created_at, to: '/wallet',
        title: 'Payout method needs attention',
        sub: w.kyc_rejection_reason || 'Your details could not be verified — review and resubmit',
      })
    })
    wds.forEach(w => {
      if (w.status === 'pending') push({
        id: 'wd' + w.id, kind: 'wallet', tone: 'info', ts: w.requested_at, to: '/wallet',
        title: `Withdrawal requested — ${w.amount} ${w.currency}`,
        sub: 'Awaiting Bookboll processing',
      })
      else if (w.status === 'approved') push({
        id: 'wd' + w.id, kind: 'wallet', tone: 'ok', ts: w.approved_at || w.requested_at, to: '/wallet',
        title: `Withdrawal approved — ${w.amount} ${w.currency}`,
        sub: 'Approved and on its way to your bank',
      })
      else if ((w.processed_at || '') >= since) push({
        id: 'wd' + w.id, kind: 'wallet', tone: w.status === 'rejected' ? 'warn' : 'ok',
        ts: w.processed_at, to: '/wallet',
        title: `Withdrawal ${w.status} — ${w.amount} ${w.currency}`,
        sub: w.rejection_reason || '',
      })
    })
  } else {
    // ── Platform staff ──
    const ownerSet = new Set(locations.map(l => l.owner_user_id).filter(Boolean))
    const inScopeLoc = (id) => !restricted || (id && locIdSet.has(id))
    const inScopeOwner = (id) => !restricted || (id && ownerSet.has(id))

    const [lstP, pgP, wds, wls, newProv, bks] = await Promise.all([
      safe(supabase.from('listings').select('id, name, approval_status, location_id, created_at')
        .eq('approval_status', 'pending').order('created_at', { ascending: false }).limit(15)),
      safe(supabase.from('class_programs').select('id, name, approval_status, owner_user_id, location_id, created_at')
        .eq('approval_status', 'pending').order('created_at', { ascending: false }).limit(15)),
      safe(supabase.from('withdrawal_requests').select('id, amount, currency, status, owner_user_id, requested_at')
        .eq('status', 'pending').order('requested_at', { ascending: false }).limit(10)),
      safe(supabase.from('provider_wallets').select('id, owner_user_id, setup_status, updated_at, created_at')
        .eq('setup_status', 'pending_review')),
      safe(supabase.from('admin_users').select('id, business_name, full_name, username, created_at')
        .eq('role', 'provider_admin').gte('created_at', since).order('created_at', { ascending: false }).limit(8)),
      locIds.length ? safe(supabase.from('bookings')
        .select('id, listing_id, location_id, booking_date, start_time, created_at')
        .in('location_id', locIds).gte('created_at', since)
        .order('created_at', { ascending: false }).limit(8)) : [],
    ])
    // Resolve names once for whatever the queries surfaced.
    const wantOwners = [...new Set([...wds.map(w => w.owner_user_id), ...wls.map(w => w.owner_user_id)].filter(Boolean))]
    const owners = wantOwners.length ? await safe(supabase.from('admin_users').select('id, business_name, full_name, username').in('id', wantOwners)) : []
    const ownerName = (id) => { const o = owners.find(x => x.id === id); return o?.business_name || o?.full_name || o?.username || 'a provider' }
    const lstIds = [...new Set(bks.map(b => b.listing_id).filter(Boolean))]
    const bkLst = lstIds.length ? await safe(supabase.from('listings').select('id, name').in('id', lstIds)) : []
    const bkLstName = new Map(bkLst.map(l => [l.id, l.name]))

    lstP.filter(l => inScopeLoc(l.location_id)).forEach(l => push({
      id: 'alp' + l.id, kind: 'approval', tone: 'info', ts: l.created_at, to: '/approvals',
      title: `Listing awaiting approval — “${l.name}”`,
      sub: locName.get(l.location_id) || 'Review it in Approvals',
    }))
    pgP.filter(p => inScopeLoc(p.location_id) || inScopeOwner(p.owner_user_id)).forEach(p => push({
      id: 'app' + p.id, kind: 'approval', tone: 'info', ts: p.created_at, to: '/approvals',
      title: `Program awaiting approval — “${p.name}”`,
      sub: locName.get(p.location_id) || 'Review it in Approvals',
    }))
    wls.filter(w => inScopeOwner(w.owner_user_id)).forEach(w => push({
      id: 'awl' + w.id, kind: 'wallet', tone: 'info', ts: w.updated_at || w.created_at, to: '/approvals',
      title: `Payout method to review — ${ownerName(w.owner_user_id)}`,
      sub: 'Verify the payout details in Approvals',
    }))
    wds.filter(w => inScopeOwner(w.owner_user_id)).forEach(w => push({
      id: 'awd' + w.id, kind: 'wallet', tone: 'info', ts: w.requested_at, to: '/approvals',
      title: `Withdrawal request — ${w.amount} ${w.currency}`,
      sub: ownerName(w.owner_user_id),
    }))
    newProv.forEach(p => push({
      id: 'np' + p.id, kind: 'provider', tone: 'ok', ts: p.created_at, to: '/admin-users',
      title: `New provider signed up — ${p.business_name || p.full_name || p.username}`,
      sub: 'Review their business in Admin Users',
    }))
    bks.forEach(b => push({
      id: 'abk' + b.id, kind: 'booking', tone: 'ok', ts: b.created_at, to: '/bookings',
      title: `New booking — ${bkLstName.get(b.listing_id) || locName.get(b.location_id) || 'venue'}`,
      sub: [locName.get(b.location_id), b.booking_date, b.start_time].filter(Boolean).join(' · '),
    }))
  }

  items.sort((a, b) => new Date(b.ts) - new Date(a.ts))
  return items.slice(0, 30)
}
