<script setup>
import { ref, nextTick, onMounted, onBeforeUnmount } from 'vue'
import { GripVertical, Star, Trash2 } from 'lucide-vue-next'
import { movePhoto } from '../lib/photoOrder.js'
const props = defineProps({ modelValue: { type: Array, default: () => [] } })
const emit = defineEmits(['update:modelValue'])
const announcement = ref('')
const gallery = ref(null)
const drag = ref(null)
let pointerElement = null, scrollParent = null, scrollFrame = null
async function focusPhoto(index) {
  await nextTick()
  gallery.value?.querySelectorAll('li')[index]?.querySelector('.photo-order-grip')?.focus()
}
function move(from, to) {
  emit('update:modelValue', movePhoto(props.modelValue, from, to))
  announcement.value = `Photo ${from + 1} moved to position ${to + 1}${to === 0 ? ' and set as the cover' : ''}.`
  focusPhoto(to)
}
function remove(index) { emit('update:modelValue', props.modelValue.filter((_, i) => i !== index)); announcement.value = `Photo ${index + 1} removed from this draft.`; focusPhoto(Math.max(0, index - 1)) }
function setTarget(index) {
  if (!drag.value || index < 0 || index >= props.modelValue.length || index === drag.value.to) return
  drag.value.to = index
  announcement.value = `Drop at position ${index + 1}${index === 0 ? ' to make this the cover' : ''}.`
}
function targetAtPointer() {
  const active = drag.value
  if (!active || active.keyboard || !gallery.value) return
  const hit = document.elementFromPoint(active.x, active.y)
  // Only accept targets in this gallery; dropping over another form is cancelled.
  active.overGallery = !!hit && gallery.value.contains(hit)
  if (!active.overGallery) return
  const item = hit.closest('[data-photo-index]')
  if (item) { setTarget(Number(item.dataset.photoIndex)); return }
  // Gaps between cards are valid drop positions too.
  let nearest = active.to, distance = Infinity
  gallery.value.querySelectorAll('[data-photo-index]').forEach((node, i) => {
    const rect = node.getBoundingClientRect()
    const d = Math.hypot(active.x - (rect.left + rect.width / 2), active.y - (rect.top + rect.height / 2))
    if (d < distance) { nearest = i; distance = d }
  })
  setTarget(nearest)
}
function scrollWhileDragging() {
  const active = drag.value
  if (!active || active.keyboard) return
  if (scrollParent) {
    const rect = scrollParent.getBoundingClientRect()
    const top = Math.max(0, rect.top), bottom = Math.min(window.innerHeight, rect.bottom)
    const within = active.x >= rect.left && active.x <= rect.right && active.y >= top && active.y <= bottom
    const delta = !within ? 0 : active.y < top + 64 ? -10 : active.y > bottom - 64 ? 10 : 0
    if (delta) { scrollParent.scrollTop += delta; targetAtPointer() }
  }
  scrollFrame = requestAnimationFrame(scrollWhileDragging)
}
function startDrag(event, index) {
  if (event.button !== 0 || drag.value || props.modelValue.length < 2) return
  event.preventDefault()
  pointerElement = event.currentTarget
  pointerElement.focus()
  pointerElement.setPointerCapture(event.pointerId)
  drag.value = { from:index, to:index, x:event.clientX, y:event.clientY, pointerId:event.pointerId, keyboard:false, overGallery:true }
  announcement.value = `Picked up photo ${index + 1}. Drag it to a new position.`
  scrollParent = gallery.value?.parentElement
  while (scrollParent && !(scrollParent.scrollHeight > scrollParent.clientHeight && /auto|scroll/.test(getComputedStyle(scrollParent).overflowY))) scrollParent = scrollParent.parentElement
  scrollFrame = requestAnimationFrame(scrollWhileDragging)
}
function moveDrag(event) {
  if (!drag.value || drag.value.keyboard || drag.value.pointerId !== event.pointerId) return
  drag.value.x = event.clientX; drag.value.y = event.clientY
  targetAtPointer()
}
function stopDrag(cancel = false) {
  const active = drag.value
  if (!active) return
  drag.value = null
  if (scrollFrame != null) cancelAnimationFrame(scrollFrame)
  scrollFrame = null; scrollParent = null
  if (pointerElement?.hasPointerCapture(active.pointerId)) pointerElement.releasePointerCapture(active.pointerId)
  pointerElement = null
  if (!cancel && (active.keyboard || active.overGallery) && active.from !== active.to) move(active.from, active.to)
  else { announcement.value = cancel || !active.overGallery ? 'Photo move cancelled.' : 'Photo order unchanged.'; focusPhoto(active.from) }
}
function endDrag(event) {
  if (drag.value?.pointerId !== event.pointerId || drag.value.keyboard) return
  moveDrag(event)
  stopDrag()
}
function keyDrag(event, index) {
  if (event.key === 'Escape' && drag.value) { event.preventDefault(); event.stopPropagation(); stopDrag(true); return }
  if (event.key === ' ' || event.key === 'Enter') {
    event.preventDefault()
    if (drag.value) { stopDrag(); return }
    drag.value = { from:index, to:index, keyboard:true, overGallery:true }
    announcement.value = `Picked up photo ${index + 1}. Use arrow keys to choose a position, then Space to drop. Escape cancels.`
  } else if (drag.value?.keyboard && ['ArrowLeft','ArrowRight','ArrowUp','ArrowDown','Home','End'].includes(event.key)) {
    event.preventDefault()
    const next = event.key === 'Home' ? 0 : event.key === 'End' ? props.modelValue.length - 1 : drag.value.to + (['ArrowLeft','ArrowUp'].includes(event.key) ? -1 : 1)
    setTarget(next)
    gallery.value?.querySelectorAll('li')[drag.value.to]?.scrollIntoView({block:'nearest'})
  } else if (event.key === 'Tab' && drag.value) stopDrag(true)
}
function cancelWithEscape(event) {
  if (event.key !== 'Escape' || !drag.value) return
  event.preventDefault(); event.stopImmediatePropagation(); stopDrag(true)
}
// Cancel a drag before an enclosing drawer's Escape handler closes the form.
onMounted(() => window.addEventListener('keydown', cancelWithEscape, true))
onBeforeUnmount(() => { if (scrollFrame != null) cancelAnimationFrame(scrollFrame); window.removeEventListener('keydown', cancelWithEscape, true) })
</script>
<template>
  <div v-if="modelValue.length" class="photo-order" @keydown.esc="drag && stopDrag(true)">
    <p class="photo-order-help">Drag the grip on a photo to reorder it. The first photo is your cover.</p>
    <p class="photo-order-sr">Keyboard: focus a photo grip, press Space, use arrow keys to move, then Space to drop. Escape cancels.</p>
    <ol ref="gallery" class="photo-order-list" :class="{'is-dragging':drag}">
      <li v-for="(photo, i) in modelValue" :key="photo + ':' + i" :data-photo-index="i" class="photo-order-item" :class="{'is-source':drag?.from === i,'is-target':drag && drag.to === i && drag.to !== drag.from}">
        <div class="photo-order-preview"><img :src="photo" :alt="`Photo ${i + 1}${i === 0 ? ', cover' : ''}`" draggable="false" /><span class="photo-order-number">{{ i === 0 ? '1 · Cover' : i + 1 }}</span>
          <button type="button" class="photo-order-grip" :aria-label="`Reorder photo ${i + 1}`" :aria-pressed="drag?.from === i" title="Drag to reorder · Space for keyboard" @pointerdown="startDrag($event,i)" @pointermove="moveDrag" @pointerup="endDrag" @pointercancel="stopDrag(true)" @lostpointercapture="drag && !drag.keyboard && stopDrag(true)" @keydown="keyDrag($event,i)"><GripVertical :size="22" /></button>
          <span v-if="drag && drag.to === i && drag.to !== drag.from" class="photo-order-drop">Drop {{ i === 0 ? 'as cover' : 'here' }}</span>
        </div>
        <div class="photo-order-actions"><button type="button" class="photo-order-cover" :disabled="i === 0 || !!drag" :aria-label="`Use photo ${i + 1} as cover`" @click="move(i, 0)"><Star :size="14" />{{ i === 0 ? 'Cover photo' : 'Use as cover' }}</button><button type="button" class="photo-order-remove" :disabled="!!drag" :aria-label="`Remove photo ${i + 1}`" @click="remove(i)"><Trash2 :size="16" /></button></div>
      </li>
    </ol>
    <p class="photo-order-announcement" role="status">{{ announcement }}</p>
    <Teleport to="body"><div v-if="drag && !drag.keyboard" class="photo-order-ghost" aria-hidden="true" :style="{left:drag.x+'px',top:drag.y+'px'}"><img :src="modelValue[drag.from]" alt="" /><span>Photo {{ drag.from + 1 }}</span></div></Teleport>
  </div>
</template>
<style scoped>
.photo-order { margin-block:16px; }
.photo-order-help { font-size:13px; line-height:1.5; color:var(--text-muted); margin:0 0 12px; }
.photo-order-list { display:grid; grid-template-columns:repeat(auto-fill,minmax(160px,1fr)); gap:14px; list-style:none; margin:0; padding:0; }
.photo-order-item { min-width:0; overflow:hidden; border:1px solid var(--border); border-radius:10px; background:var(--surface); }
.photo-order-item.is-source { opacity:.55; }.photo-order-item.is-target { outline:3px solid var(--primary); outline-offset:2px; }
.photo-order-preview { position:relative; aspect-ratio:4/3; background:var(--bg); }.photo-order-preview img { width:100%; height:100%; object-fit:cover; user-select:none; }
.photo-order-number { position:absolute; inset-inline-start:8px; bottom:8px; background:#102035ed; color:#fff; border-radius:5px; padding:4px 7px; font-size:12px; font-weight:600; }
.photo-order-grip { position:absolute; inset-inline-end:6px; top:6px; display:grid; place-items:center; width:44px; height:44px; border:1px solid #ffffff60; border-radius:8px; color:#fff; background:#102035e8; cursor:grab; touch-action:none; user-select:none; }.photo-order-grip:active,.is-dragging .photo-order-grip { cursor:grabbing; }
.photo-order-drop { position:absolute; inset:0; display:grid; place-items:center; color:var(--primary-ink,var(--primary)); background:color-mix(in srgb,var(--surface) 88%,transparent); font-weight:700; pointer-events:none; }
.photo-order-actions { display:flex; border-top:1px solid var(--border); }.photo-order-actions button { display:flex; justify-content:center; align-items:center; gap:6px; min-height:44px; min-width:44px; background:transparent; border:0; cursor:pointer; }
.photo-order-remove { color:var(--danger-ink,#dc2626); border-inline-start:1px solid var(--border)!important; }.photo-order-cover { flex:1; padding:7px; color:var(--primary-ink,var(--primary)); font-size:12px; font-weight:600; }
.photo-order button:disabled { opacity:.45; cursor:default; }.photo-order button:focus-visible { outline:3px solid var(--primary); outline-offset:-3px; }
.photo-order-announcement { font-size:12px; color:var(--text-muted); margin:8px 0 0; min-height:18px; }
.photo-order-sr { position:absolute; width:1px; height:1px; overflow:hidden; clip-path:inset(50%); }
.photo-order-ghost { position:fixed; width:100px; transform:translate(-50%,-110%); z-index:10050; pointer-events:none; border:2px solid #fff; box-shadow:0 8px 24px #0005; border-radius:8px; overflow:hidden; background:#102035; color:#fff; }.photo-order-ghost img { width:100%; height:70px; object-fit:cover; display:block; }.photo-order-ghost span { display:block; padding:5px; font:600 12px/1.3 sans-serif; text-align:center; }
@media(max-width:480px) { .photo-order-list { grid-template-columns:repeat(2,minmax(0,1fr)); gap:10px; } }
</style>
