/**
 * toast.js — tiny global toast/notification store.
 *
 * Usage in any component:
 *   import { useToast } from '../lib/toast.js'
 *   const toast = useToast()
 *   toast.success('Location saved')
 *   toast.error("Couldn't save — try again")
 *
 * A single <ToastHost /> mounted once in App.vue renders the queue.
 */
import { ref } from 'vue'

let _id = 0
export const toasts = ref([])

export function pushToast(message, type = 'success', timeout = null) {
  const id = ++_id
  const ttl = timeout ?? (type === 'error' ? 5000 : 3200)
  toasts.value.push({ id, message, type })
  if (ttl > 0) setTimeout(() => dismissToast(id), ttl)
  return id
}

export function dismissToast(id) {
  const i = toasts.value.findIndex(t => t.id === id)
  if (i !== -1) toasts.value.splice(i, 1)
}

export function useToast() {
  return {
    success: (m) => pushToast(m, 'success'),
    error:   (m) => pushToast(m, 'error'),
    info:    (m) => pushToast(m, 'info'),
    warning: (m) => pushToast(m, 'warning'),
    dismiss: dismissToast,
  }
}
