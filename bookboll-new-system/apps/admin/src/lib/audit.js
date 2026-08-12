// ── Audit logging ─────────────────────────────────────────────────────────────
// Lightweight, best-effort writer for the `audit_logs` table (actor, role, action,
// entity_type, entity_id, details, created_at). Auditing must NEVER break or block the
// action it records, so every call is wrapped and failures are swallowed.
//
//   import { logAudit, AUDIT } from '../lib/audit.js'
//   logAudit({ action: AUDIT.APPROVED, entity_type: 'listing', entity_id: l.id, details: `Approved "${l.name}"` })
//
// `action` is a free-text verb; the page colours a few known ones. Keep them short.
import { supabase } from './supabase.js'
import { currentUser } from './currentUser.js'

export const AUDIT = {
  CREATED: 'created',
  UPDATED: 'updated',
  DELETED: 'deleted',
  APPROVED: 'approved',
  REJECTED: 'rejected',
  CHANGES_REQUESTED: 'changes_requested',
  ENABLED: 'enabled',
  DISABLED: 'disabled',
}

export async function logAudit({ action, entity_type = '', entity_id = '', details = '' }) {
  try {
    const u = currentUser.value
    await supabase.from('audit_logs').insert({
      actor: u?.full_name || u?.username || u?.business_name || u?.email || 'System',
      role: u?.role || '',
      action: action || '',
      entity_type: entity_type || '',
      entity_id: entity_id ? String(entity_id) : '',
      details: details || '',
    })
  } catch { /* never let auditing break the underlying action */ }
}
