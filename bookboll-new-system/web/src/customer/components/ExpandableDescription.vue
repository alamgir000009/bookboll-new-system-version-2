<script setup>
import { ref, watch, onMounted, onBeforeUnmount, nextTick } from 'vue'
import { ChevronDown, ChevronUp } from 'lucide-vue-next'

const props = defineProps({ text: { type: String, default: '' }, id: { type: String, required: true } })
const paragraph = ref(null)
const expanded = ref(false)
const canExpand = ref(false)
let observer
function measure() {
  if (!paragraph.value || expanded.value || !paragraph.value.clientHeight) return
  canExpand.value = paragraph.value.scrollHeight > paragraph.value.clientHeight + 1
}
watch(() => props.text, async () => { expanded.value = false; await nextTick(); measure() })
watch(expanded, async () => { await nextTick(); measure() })
onMounted(() => { observer = new ResizeObserver(measure); observer.observe(paragraph.value); measure() })
onBeforeUnmount(() => observer?.disconnect())
</script>

<template>
  <div class="description">
    <p ref="paragraph" :id="id" :class="{ collapsed: !expanded }">{{ text }}</p>
    <button v-if="canExpand" type="button" :aria-expanded="expanded" :aria-controls="id" @click="expanded = !expanded">{{ expanded ? 'Read less' : 'Read more…' }}<ChevronUp v-if="expanded" :size="16" /><ChevronDown v-else :size="16" /></button>
  </div>
</template>

<style scoped>
.description p { font-size: 14px; line-height: 1.8; margin: 0; white-space: pre-line; overflow-wrap: anywhere; color: var(--text-secondary); }
.description p.collapsed { display: -webkit-box; -webkit-line-clamp: 4; -webkit-box-orient: vertical; overflow: hidden; }
.description button { display: inline-flex; align-items: center; gap: 5px; min-height: 44px; border: 0; padding: 6px 0; margin-top: 2px; background: none; color: var(--brand-ink, var(--brand)); font: inherit; font-size: 13px; font-weight: 650; cursor: pointer; }
.description button:focus-visible { outline: 2px solid var(--brand-ink, var(--brand)); outline-offset: 3px; }
</style>
