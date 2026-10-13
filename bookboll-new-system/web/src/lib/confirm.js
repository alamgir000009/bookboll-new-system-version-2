/**
 * confirm.js — global, promise-based confirmation dialog store.
 *
 * Replaces the browser's native confirm() with a styled in-app modal.
 *
 * Usage:
 *   import { useConfirm } from '../lib/confirm.js'
 *   const confirm = useConfirm()
 *   if (!(await confirm({
 *     title: 'Delete location?',
 *     message: '“DSC Marina” and its 2 listings will be removed. This cannot be undone.',
 *     danger: true,            // red confirm button (default true)
 *     confirmText: 'Delete',   // optional
 *   }))) return
 *
 * A single <ConfirmDialog /> mounted once in App.vue renders it.
 */
import { ref } from 'vue'

export const confirmState = ref(null)

export function askConfirm(opts = {}) {
  // If another dialog is somehow open, resolve it false first.
  if (confirmState.value) { try { confirmState.value.resolve(false) } catch {} }
  return new Promise((resolve) => {
    confirmState.value = {
      title:       opts.title || 'Are you sure?',
      message:     opts.message || '',
      danger:      opts.danger !== false,        // default true
      confirmText: opts.confirmText || (opts.danger === false ? 'Confirm' : 'Delete'),
      cancelText:  opts.cancelText || 'Cancel',
      // Optional THIRD choice (e.g. "Save draft & leave"). When set, the dialog
      // shows a primary extra button that resolves with the string 'extra'
      // (truthy — only pass extraText when the caller distinguishes it).
      extraText:   opts.extraText || '',
      resolve,
    }
  })
}

export function resolveConfirm(result) {
  if (!confirmState.value) return
  const r = confirmState.value.resolve
  confirmState.value = null
  r(result)
}

export function useConfirm() {
  return askConfirm
}
