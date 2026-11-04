// Keyboard behavior for dashboard dialogs, including nested confirmations.
// Closing remains owned by the form so its unsaved-change checks still run.
const stack = []
let previousOverflow = ''
let sequence = 0
const focusable = el => [...el.querySelectorAll('button:not([disabled]), a[href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), summary, [tabindex="0"]')]
  .filter(node => node.getClientRects().length && !node.closest('[inert]'))

export const dialogFocus = {
  mounted(el, binding) {
    const record = { el, previous: document.activeElement, close: binding.value }
    if (!stack.length) {
      previousOverflow = document.body.style.overflow
      document.body.style.overflow = 'hidden'
      document.addEventListener('keydown', onKey, true)
    }
    stack.push(record)
    el.__dialogRecord = record
    if (!el.hasAttribute('role')) el.setAttribute('role', 'dialog')
    el.setAttribute('aria-modal', 'true')
    el.setAttribute('tabindex', '-1')
    const title = el.querySelector('h1,h2,h3,[class$="-title"],.modal-title,.bb-drawer-title')
    if (!el.hasAttribute('aria-label') && !el.hasAttribute('aria-labelledby') && title) {
      if (!title.id) title.id = `dashboard-dialog-${++sequence}`
      el.setAttribute('aria-labelledby', title.id)
    }
    queueMicrotask(() => {
      if (!el.isConnected || stack.at(-1) !== record) return
      // Focusing the dialog reads its title without jumping to a field halfway down.
      el.focus({ preventScroll: true })
    })
  },
  updated(el, binding) { if (el.__dialogRecord) el.__dialogRecord.close = binding.value },
  beforeUnmount(el) {
    const record = el.__dialogRecord
    const index = stack.indexOf(record)
    if (index < 0) return
    const wasTop = index === stack.length - 1
    stack.splice(index, 1)
    if (!stack.length) {
      document.body.style.overflow = previousOverflow
      document.removeEventListener('keydown', onKey, true)
    }
    if (wasTop && record.previous?.isConnected) record.previous.focus({ preventScroll: true })
  },
}

function onKey(event) {
  const record = stack.at(-1)
  if (!record) return
  if (event.key === 'Escape' && typeof record.close === 'function') {
    event.preventDefault()
    event.stopImmediatePropagation()
    record.close()
  }
  if (event.key !== 'Tab') return
  const nodes = focusable(record.el)
  const first = nodes[0], last = nodes.at(-1)
  if (!first) { event.preventDefault(); record.el.focus(); return }
  if (!record.el.contains(document.activeElement) || document.activeElement === record.el) {
    event.preventDefault(); (event.shiftKey ? last : first).focus()
  } else if (event.shiftKey && document.activeElement === first) {
    event.preventDefault(); last.focus()
  } else if (!event.shiftKey && document.activeElement === last) {
    event.preventDefault(); first.focus()
  }
}
