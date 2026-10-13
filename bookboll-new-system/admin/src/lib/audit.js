/**
 * Audit logging — best-effort writer against Laravel AuditLogService.
 * Auditing must NEVER break or block the action it records.
 *
 *   import { logAudit, AUDIT } from '../lib/audit.js'
 *   logAudit({ action: AUDIT.APPROVED, entity_type: 'listing', entity_id: l.id, details: `Approved "${l.name}"` })
 */
import { apiFetch } from './api.js'

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
    await apiFetch('/admin/audit-logs', {
      method: 'POST',
      body: {
        action: action || '',
        resource: entity_type || 'general',
        resource_id: entity_id ? String(entity_id) : '',
        details: details || '',
      },
    })
  } catch { /* never let auditing break the underlying action */ }
}
