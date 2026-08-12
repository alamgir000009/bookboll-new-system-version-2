/**
 * inputPrompt.js — styled, promise-based text-input dialog (replaces native prompt()).
 *
 * Usage:
 *   import { useInputPrompt } from '../lib/inputPrompt.js'
 *   const inputPrompt = useInputPrompt()
 *   const reason = await inputPrompt({
 *     title: 'Reject withdrawal',
 *     label: 'Reason (shown to the provider)',
 *     placeholder: 'e.g. Bank details incomplete',
 *     multiline: true,
 *     required: true,
 *   })
 *   if (reason === null) return   // cancelled
 *
 * A single <InputDialog /> mounted once in App.vue renders it.
 */
import { ref } from 'vue'

export const inputState = ref(null)

export function askInput(opts = {}) {
  if (inputState.value) { try { inputState.value.resolve(null) } catch {} }
  return new Promise((resolve) => {
    inputState.value = {
      title:       opts.title || 'Enter a value',
      message:     opts.message || '',
      label:       opts.label || '',
      placeholder: opts.placeholder || '',
      initial:     opts.initial || '',
      multiline:   !!opts.multiline,
      required:    opts.required !== false,   // required by default
      confirmText: opts.confirmText || 'Submit',
      cancelText:  opts.cancelText || 'Cancel',
      resolve,
    }
  })
}

/** value: string on submit, null on cancel */
export function resolveInput(value) {
  if (!inputState.value) return
  const r = inputState.value.resolve
  inputState.value = null
  r(value)
}

export function useInputPrompt() {
  return askInput
}
