import { nextTick, onBeforeUnmount, watch } from 'vue'

let openEditors = 0
let previousOverflow = ''
let previousInert = false

export function useEditorDialog(isOpen, element) {
  let locked = false
  let previousFocus
  function unlock() {
    if (!locked) return
    locked = false
    if (--openEditors === 0) {
      document.body.style.overflow = previousOverflow
      const app = document.querySelector('#app > .app')
      if (app) app.inert = previousInert
    }
    if (previousFocus?.isConnected) previousFocus.focus({ preventScroll: true })
  }
  watch(isOpen, async open => {
    if (!open) { unlock(); return }
    previousFocus = document.activeElement
    if (!locked) {
      locked = true
      if (openEditors++ === 0) {
        previousOverflow = document.body.style.overflow
        document.body.style.overflow = 'hidden'
        const app = document.querySelector('#app > .app')
        previousInert = !!app?.inert
        if (app) app.inert = true
      }
    }
    await nextTick()
    if (isOpen.value) element.value?.querySelector('.bb-drawer-close')?.focus()
  }, { flush: 'post' })
  onBeforeUnmount(unlock)
  return function editorKeydown(event) {
    if (event.key !== 'Tab') return
    const targets = [...element.value.querySelectorAll('button:not([disabled]), a[href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), summary, [tabindex="0"]')]
      .filter(el => el.getClientRects().length && !el.closest('[inert]'))
    const first = targets[0], last = targets.at(-1)
    if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus() }
    else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus() }
  }
}
