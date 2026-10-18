/**
 * unsavedGuard.js — global "unsaved changes" protection.
 *
 * Dirty state is a small STACK of scopes, not one boolean, because forms can
 * stack: the listing editor opens the Location modal on top of itself ("Edit
 * location schedule"). Each layer tracks its own dirty flag — markDirty() /
 * clearDirty() always act on the TOP scope, and a nested modal pushes its own
 * scope on open (pushDirtyScope) and pops it on close (popDirtyScope), so
 * closing the inner modal can never wipe the outer form's unsaved state.
 *
 * With any scope dirty, two things are guarded automatically:
 *   1. Browser refresh / tab close  → native beforeunload prompt
 *   2. In-app page navigation        → styled confirm dialog (askConfirm)
 *
 * Wiring a form is one line in the template (@input/@change on the root to
 * call markDirty) plus clearDirty() on open/save/close. Widgets that mutate
 * state from plain clicks (the schedule Slots Grid, the pricing paint grid)
 * call markDirty() themselves — a click fires no input/change event, so the
 * root listener never sees those edits.
 */
import { ref, computed } from 'vue'
import { askConfirm } from './confirm.js'
import { pushToast } from './toast.js'

const scopes = ref([false])
// One optional draft-saver per scope (parallel array). A form registers a
// function that snapshots itself to a local draft (see lib/formDrafts.js);
// while one is registered, every leave-confirm offers "Save draft & leave".
const draftSavers = [null]
export const formDirty = computed(() => scopes.value.some(Boolean))
export function markDirty()  { scopes.value[scopes.value.length - 1] = true }
export function clearDirty() { scopes.value[scopes.value.length - 1] = false }
/** Register (or clear, with null) the ACTIVE scope's draft saver. */
export function registerDraftSaver(fn) { draftSavers[scopes.value.length - 1] = fn || null }
/** A modal opening ON TOP of another form calls this on open… */
export function pushDirtyScope() { scopes.value.push(false); draftSavers.push(null) }
/** …and this on close. Discards the inner state, restores the outer form's. */
export function popDirtyScope() {
  if (scopes.value.length > 1) { scopes.value.pop(); draftSavers.pop() }
  else { scopes.value[0] = false; draftSavers[0] = null }
}
function clearAllDirty() {
  scopes.value = [false]
  draftSavers.length = 1
  draftSavers[0] = null
}
// Keep the form open if storage is unavailable; never discard a failed draft.
async function saveDraft(saver) {
  try { if (await saver() !== false) return true } catch { /* report below */ }
  pushToast('Your draft could not be saved in this browser. Keep editing or copy your changes before closing.', 'error')
  return false
}
async function saveAllDrafts() {
  for (let i = 0; i < scopes.value.length; i++) {
    if (scopes.value[i] && typeof draftSavers[i] === 'function' && !await saveDraft(draftSavers[i])) return false
  }
  return true
}
function anyDirtySaver() {
  return scopes.value.some((dirty, i) => dirty && typeof draftSavers[i] === 'function')
}

const LEAVE_PROMPT = {
  title: 'Leave without saving?',
  message: 'You have unsaved changes on this form. If you leave now, they will not be saved.',
  danger: true,
  confirmText: 'Leave',
  cancelText: 'Stay on page',
}
const DRAFT_MESSAGE = 'You have unsaved changes on this form. Save them as a draft to pick up where you left off, or leave and lose them.'

let installed = false
export function installUnsavedGuards(router) {
  if (installed) return
  installed = true

  // 1. Refresh / close tab (browsers show their own generic message).
  window.addEventListener('beforeunload', (e) => {
    if (formDirty.value) { e.preventDefault(); e.returnValue = '' }
  })

  // 2. In-app navigation between pages.
  router.beforeEach(async () => {
    if (!formDirty.value) return true
    const withDraft = anyDirtySaver()
    const res = await askConfirm(withDraft
      ? { ...LEAVE_PROMPT, message: DRAFT_MESSAGE, extraText: 'Save draft & leave', confirmText: 'Leave without saving' }
      : LEAVE_PROMPT)
    if (res === 'extra') { if (!await saveAllDrafts()) return false; clearAllDirty(); return true }
    if (res) { clearAllDirty(); return true }   // leaving unmounts every open form
    return false   // cancel navigation, stay on the page
  })
}

/**
 * Helper for a modal/drawer close button: confirm before discarding. Checks
 * only the TOP scope — the caller's own form, not one it may be stacked on.
 * Resolves false (stay), true (discard), or 'draft' (saved a draft & leave —
 * truthy, so `if (await confirmDiscardIfDirty())` keeps working).
 */
export async function confirmDiscardIfDirty() {
  const top = scopes.value.length - 1
  if (!scopes.value[top]) return true
  const saver = draftSavers[top]
  const res = await askConfirm(saver
    ? { ...LEAVE_PROMPT, message: DRAFT_MESSAGE, extraText: 'Save draft & close', confirmText: 'Discard changes', cancelText: 'Keep editing' }
    : { ...LEAVE_PROMPT, confirmText: 'Discard', cancelText: 'Keep editing' })
  if (res === 'extra') { if (!await saveDraft(saver)) return false; clearDirty(); return 'draft' }
  if (res) clearDirty()
  return res
}
