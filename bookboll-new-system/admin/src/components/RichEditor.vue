<script setup>
/**
 * RichEditor — a dependency-free WYSIWYG editor for admin document content (blog posts).
 * Toolbar drives document.execCommand on a contenteditable; output is HTML (sanitized on
 * save + on public render via lib/blog.js sanitizeHtml). Toolbar buttons use
 * @mousedown.prevent so the editor keeps its text selection when a button is pressed.
 */
import { ref, watch, onMounted } from 'vue'
import {
  Pilcrow, Heading2, Heading3, Bold, Italic, Underline, Strikethrough,
  List, ListOrdered, Quote, Link2, AlignLeft, AlignCenter, AlignRight,
  Baseline, Highlighter, Eraser,
} from 'lucide-vue-next'

const props = defineProps({ modelValue: { type: String, default: '' }, placeholder: { type: String, default: 'Write your article…' }, compact: { type: Boolean, default: false } })
const emit = defineEmits(['update:modelValue'])

const ed = ref(null)
const showText = ref(false)
const showHi = ref(false)

const TEXT_COLORS = ['#0f172a', '#475569', '#dc2626', '#ea580c', '#ca8a04', '#16a34a', '#0891b2', '#2563eb', '#7c3aed', '#db2777']
const HI_COLORS = ['#fef08a', '#fde68a', '#bbf7d0', '#a7f3d0', '#bfdbfe', '#e9d5ff', '#fbcfe8', '#fed7aa', '#e2e8f0']

onMounted(() => { if (ed.value) ed.value.innerHTML = props.modelValue || '' })
// Sync external changes (e.g. loading a post) without clobbering the caret while typing.
watch(() => props.modelValue, (v) => { if (ed.value && (v || '') !== ed.value.innerHTML) ed.value.innerHTML = v || '' })

function emitChange() { emit('update:modelValue', ed.value ? ed.value.innerHTML : '') }
function focusEd() { ed.value?.focus() }

// The custom-colour <input type="color"> takes focus and drops the editor's text
// selection, so we remember the selection when a colour popover opens and restore it
// before applying. (Preset swatches keep the selection via the toolbar's mousedown.prevent;
// restoring the same range is harmless for them.)
let savedRange = null
function rememberSelection() {
  const sel = window.getSelection()
  if (sel && sel.rangeCount && ed.value && ed.value.contains(sel.anchorNode)) {
    savedRange = sel.getRangeAt(0).cloneRange()
  }
}
function restoreSelection() {
  if (!savedRange) return
  const sel = window.getSelection()
  sel.removeAllRanges(); sel.addRange(savedRange)
}

function cmd(command, value = null) { focusEd(); document.execCommand(command, false, value); emitChange() }
function block(tag) { cmd('formatBlock', tag) }
function color(kind, value) {
  focusEd()
  restoreSelection()
  document.execCommand('styleWithCSS', false, true)               // emit <span style> not <font>
  document.execCommand(kind === 'text' ? 'foreColor' : 'hiliteColor', false, value)
  document.execCommand('styleWithCSS', false, false)
  emitChange(); showText.value = false; showHi.value = false
}
function addLink() {
  const url = prompt('Link URL (https://…)')
  if (url) cmd('createLink', url)
}
</script>

<template>
  <div class="re">
    <div class="re-toolbar" @mousedown.prevent>
      <div class="re-group">
        <button type="button" class="re-btn" title="Normal text" @click="block('p')"><Pilcrow :size="16" /></button>
        <button type="button" class="re-btn" title="Heading" @click="block('h2')"><Heading2 :size="16" /></button>
        <button type="button" class="re-btn" title="Subheading" @click="block('h3')"><Heading3 :size="16" /></button>
      </div>
      <div class="re-sep"></div>
      <div class="re-group">
        <button type="button" class="re-btn" title="Bold" @click="cmd('bold')"><Bold :size="16" /></button>
        <button type="button" class="re-btn" title="Italic" @click="cmd('italic')"><Italic :size="16" /></button>
        <button type="button" class="re-btn" title="Underline" @click="cmd('underline')"><Underline :size="16" /></button>
        <button type="button" class="re-btn" title="Strikethrough" @click="cmd('strikeThrough')"><Strikethrough :size="16" /></button>
      </div>
      <div class="re-sep"></div>
      <div class="re-group">
        <!-- Text color -->
        <div class="re-pop">
          <button type="button" class="re-btn" title="Text color" @click="showText = !showText; showHi = false; rememberSelection()"><Baseline :size="16" /></button>
          <div v-if="showText" class="re-swatches">
            <button v-for="c in TEXT_COLORS" :key="c" type="button" class="re-swatch" :style="{ background: c }" :title="c" @click="color('text', c)"></button>
            <label class="re-swatch re-swatch-custom" title="Custom colour…" @mousedown.stop>
              <input type="color" value="#0f172a" @change="color('text', $event.target.value)" />
              <span>+</span>
            </label>
          </div>
        </div>
        <!-- Highlight -->
        <div class="re-pop">
          <button type="button" class="re-btn" title="Highlight" @click="showHi = !showHi; showText = false; rememberSelection()"><Highlighter :size="16" /></button>
          <div v-if="showHi" class="re-swatches">
            <button v-for="c in HI_COLORS" :key="c" type="button" class="re-swatch" :style="{ background: c }" :title="c" @click="color('hi', c)"></button>
            <button type="button" class="re-swatch re-swatch-none" title="No highlight" @click="color('hi', 'transparent')">✕</button>
            <label class="re-swatch re-swatch-custom" title="Custom colour…" @mousedown.stop>
              <input type="color" value="#fef08a" @change="color('hi', $event.target.value)" />
              <span>+</span>
            </label>
          </div>
        </div>
      </div>
      <div class="re-sep"></div>
      <div class="re-group">
        <button type="button" class="re-btn" title="Bullet list" @click="cmd('insertUnorderedList')"><List :size="16" /></button>
        <button type="button" class="re-btn" title="Numbered list" @click="cmd('insertOrderedList')"><ListOrdered :size="16" /></button>
        <button type="button" class="re-btn" title="Quote" @click="block('blockquote')"><Quote :size="16" /></button>
      </div>
      <div class="re-sep"></div>
      <div class="re-group">
        <button type="button" class="re-btn" title="Align left" @click="cmd('justifyLeft')"><AlignLeft :size="16" /></button>
        <button type="button" class="re-btn" title="Align center" @click="cmd('justifyCenter')"><AlignCenter :size="16" /></button>
        <button type="button" class="re-btn" title="Align right" @click="cmd('justifyRight')"><AlignRight :size="16" /></button>
      </div>
      <div class="re-sep"></div>
      <div class="re-group">
        <button type="button" class="re-btn" title="Add link" @click="addLink"><Link2 :size="16" /></button>
        <button type="button" class="re-btn" title="Clear formatting" @click="cmd('removeFormat')"><Eraser :size="16" /></button>
      </div>
    </div>

    <div
      ref="ed"
      class="re-area blog-content"
      :class="{ 're-compact': compact }"
      contenteditable="true"
      :data-ph="placeholder"
      @input="emitChange"
      @blur="emitChange"
    ></div>
  </div>
</template>

<style scoped>
.re { border: 1.5px solid var(--border, #e2e8f0); border-radius: 12px; overflow: hidden; background: var(--surface, #ffffff); }
.re-toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: 2px; padding: 7px 8px; border-bottom: 1px solid var(--border, #e2e8f0); background: var(--surface-hover, #f8fafc); }
.re-group { display: flex; align-items: center; gap: 2px; }
.re-sep { width: 1px; align-self: stretch; background: var(--border, #e2e8f0); margin: 3px 5px; }
.re-btn { width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center; border: none; background: none; border-radius: 7px; color: var(--text-secondary, #475569); cursor: pointer; }
.re-btn:hover { background: var(--border, #e2e8f0); color: var(--text, #0f172a); }
.re-pop { position: relative; }
.re-swatches { position: absolute; top: 36px; left: 0; z-index: 20; display: grid; grid-template-columns: repeat(5, 1fr); gap: 5px; padding: 8px; background: var(--surface, #ffffff); border: 1px solid var(--border, #e2e8f0); border-radius: 10px; box-shadow: 0 10px 30px rgba(15,23,42,.16); width: 150px; }
.re-swatch { width: 22px; height: 22px; border-radius: 6px; border: 1px solid rgba(15,23,42,.12); cursor: pointer; padding: 0; }
.re-swatch:hover { transform: scale(1.12); }
.re-swatch-none { background: var(--surface, #fff) !important; color: var(--text-light, #94a3b8); font-size: 12px; font-weight: 800; display: flex; align-items: center; justify-content: center; }
/* "Custom colour" tile — a rainbow chip that opens the native picker for any colour. */
.re-swatch-custom { position: relative; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 14px; font-weight: 800; overflow: hidden;
  background: conic-gradient(from 0deg, #f00, #ff0, #0f0, #0ff, #00f, #f0f, #f00); border-color: rgba(15,23,42,.18); }
.re-swatch-custom input { position: absolute; inset: 0; opacity: 0; width: 100%; height: 100%; border: none; padding: 0; cursor: pointer; }
.re-swatch-custom span { text-shadow: 0 1px 2px rgba(0,0,0,.5); pointer-events: none; }

.re-area { min-height: 320px; padding: 16px 18px; outline: none; overflow-y: auto; max-height: 60vh; }
.re-area.re-compact { min-height: 88px; padding: 12px 14px; }
.re-area:empty::before { content: attr(data-ph); color: var(--text-light, #94a3b8); }
/* the .blog-content global styles handle headings/lists/quotes/links inside the editor */
</style>
