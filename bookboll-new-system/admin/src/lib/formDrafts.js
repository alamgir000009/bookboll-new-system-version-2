/**
 * formDrafts.js — local "save as draft" snapshots for the big setup forms
 * (listing editor, location modal, membership program form).
 *
 * A draft is a JSON snapshot of the form's reactive state in localStorage,
 * keyed per form (+ record id when editing). Saved when the user picks
 * "Save draft" — from the form's own button or from the leave-confirm dialog
 * (see unsavedGuard.js registerDraftSaver) — and offered back the next time
 * that same form opens. Cleared on a successful real save.
 */
const PREFIX = 'bb_draft_'

export function saveFormDraft(key, data) {
  try {
    localStorage.setItem(PREFIX + key, JSON.stringify({ at: Date.now(), data }))
    return true
  } catch { return false }
}

/** → { at, data } or null */
export function loadFormDraft(key) {
  try {
    const raw = localStorage.getItem(PREFIX + key)
    if (!raw) return null
    const parsed = JSON.parse(raw)
    return parsed && parsed.data ? parsed : null
  } catch { return null }
}

export function clearFormDraft(key) {
  try { localStorage.removeItem(PREFIX + key) } catch { /* ignore */ }
}

/** "just now" / "25 minutes ago" / "3 hours ago" / "2 days ago" */
export function draftAgeLabel(at) {
  const mins = Math.floor((Date.now() - Number(at || 0)) / 60000)
  if (mins < 2) return 'just now'
  if (mins < 60) return `${mins} minutes ago`
  const h = Math.floor(mins / 60)
  if (h < 24) return h === 1 ? 'an hour ago' : `${h} hours ago`
  const d = Math.floor(h / 24)
  return d === 1 ? 'yesterday' : `${d} days ago`
}
