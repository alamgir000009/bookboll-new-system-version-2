/**
 * touchReorder.js — makes the HTML5 drag-to-reorder grips work on touch screens.
 *
 * Native drag events (dragstart / dragover / drop) NEVER fire for touch input in
 * mobile browsers, so every ⋮⋮ grip handle was dead on phones. This maps touch
 * gestures on a handle to the SAME start/over/drop/end callbacks the mouse path
 * already uses — one controller per sortable list, zero changes to the reorder
 * logic itself.
 *
 * Wiring:
 *   const locTR = createTouchReorder({ attr: 'data-tr-loc',
 *     start: onLocDragStart, over: onLocDragOver, leave: onLocDragLeave,
 *     drop: onLocDrop, end: onLocDragEnd })
 *   <div class="row" :data-tr-loc="row.id">           ← rows advertise their id
 *     <span class="drag-handle"
 *       @touchstart.stop="locTR.onTouchstart($event, row.id)"
 *       @touchmove="locTR.onTouchmove($event)"
 *       @touchend="locTR.onTouchend($event)"
 *       @touchcancel="locTR.onTouchend($event)">…
 *
 * While a drag is live, touchmove is preventDefault-ed so the page doesn't
 * scroll under the finger (pair with `touch-action: none` on the handle for
 * browsers that decide scrollability before the first move event). Because
 * that kills native scrolling, an EDGE AUTO-SCROLL loop runs during the drag:
 * holding the finger near a viewport edge scrolls the list container (or the
 * window) so drop targets beyond the screen stay reachable — without it, long
 * lists could only be reordered between simultaneously visible rows.
 */

// The existing mouse handlers expect a DragEvent-ish object.
const stubEvent = () => ({ preventDefault() {}, dataTransfer: { effectAllowed: 'move' } })

const EDGE = 72     // px from a viewport edge that triggers auto-scroll
const STEP = 14     // px scrolled per tick while inside the edge zone
const TICK = 40     // ms between auto-scroll ticks

// Nearest scrollable ancestors of the drag handle (vertical + horizontal).
function scrollableAncestors(el) {
  const out = { v: null, h: null }
  for (let n = el; n && n !== document.documentElement; n = n.parentElement) {
    const s = getComputedStyle(n)
    if (!out.v && /(auto|scroll)/.test(s.overflowY) && n.scrollHeight > n.clientHeight + 1) out.v = n
    if (!out.h && /(auto|scroll)/.test(s.overflowX) && n.scrollWidth > n.clientWidth + 1) out.h = n
  }
  return out
}

export function createTouchReorder({ attr, start, over, leave, drop, end }) {
  let dragId = null
  let overId = null
  let lastTouch = null
  let scrollEls = { v: null, h: null }
  let timer = null

  function idAt(x, y) {
    const el = document.elementFromPoint(x, y)
    const host = el && el.closest(`[${attr}]`)
    return host ? host.getAttribute(attr) : null
  }

  function updateOver(x, y) {
    const id = idAt(x, y)
    if (id !== overId) {
      overId = id
      if (id) over(stubEvent(), id)
      else leave?.()
    }
  }

  // Runs on an interval while dragging: a finger HELD at a viewport edge keeps
  // scrolling even though no further touchmove events fire.
  function edgeScroll() {
    if (!lastTouch) return
    const { clientX: x, clientY: y } = lastTouch
    const vh = window.innerHeight, vw = window.innerWidth
    let moved = false
    const dy = y < EDGE ? -STEP : (y > vh - EDGE ? STEP : 0)
    const dx = x < EDGE ? -STEP : (x > vw - EDGE ? STEP : 0)
    if (dy) { (scrollEls.v || window).scrollBy(0, dy); moved = true }
    if (dx && scrollEls.h) { scrollEls.h.scrollBy(dx, 0); moved = true }
    if (moved) updateOver(x, y)   // content shifted under the still finger
  }

  function stopLoop() { if (timer) { clearInterval(timer); timer = null } }

  return {
    onTouchstart(e, id, ...extra) {
      dragId = id
      overId = null
      lastTouch = e.touches && e.touches[0] ? { clientX: e.touches[0].clientX, clientY: e.touches[0].clientY } : null
      scrollEls = scrollableAncestors(e.target)
      stopLoop()
      timer = setInterval(edgeScroll, TICK)
      start(stubEvent(), id, ...extra)
    },
    onTouchmove(e) {
      if (dragId == null) return
      e.preventDefault()                      // keep the list still while dragging
      const t = e.touches && e.touches[0]
      if (!t) return
      lastTouch = { clientX: t.clientX, clientY: t.clientY }
      updateOver(t.clientX, t.clientY)
    },
    onTouchend() {
      if (dragId == null) return
      stopLoop()
      if (overId && overId !== dragId) drop(stubEvent(), overId)
      else end?.()
      dragId = null
      overId = null
      lastTouch = null
    },
  }
}
