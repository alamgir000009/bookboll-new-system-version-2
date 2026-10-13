<script setup>
/**
 * LanguageSwitcher — a compact globe dropdown listing all supported languages in
 * their own names. Selecting one lazy-loads that locale, flips RTL/LTR, and persists
 * the choice (see lib/i18n.js). Used in the customer header and the admin top bar.
 */
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { SUPPORTED_LOCALES } from '../lib/i18n.js'
import { appLang, setAppLanguage } from '../lib/autoTranslate.js'
import { activeBrand } from '../lib/brand.js'
import { Languages, Check } from 'lucide-vue-next'

const props = defineProps({ compact: { type: Boolean, default: false } })

const open = ref(false)
const root = ref(null)
// On a provider's white-label storefront, only the languages the provider chose are
// offered (English is always kept — it's the source language). Everywhere else (apex
// marketplace + admin dashboard, where activeBrand is null) all languages show.
const locales = computed(() => {
  const allowed = activeBrand.value?.languages
  if (!Array.isArray(allowed) || !allowed.length) return SUPPORTED_LOCALES
  const set = new Set(['en', ...allowed])
  return SUPPORTED_LOCALES.filter(l => set.has(l.code))
})
const active = computed(() => locales.value.find(l => l.code === appLang.value) || SUPPORTED_LOCALES.find(l => l.code === appLang.value) || SUPPORTED_LOCALES[0])

function pick(code) { setAppLanguage(code); open.value = false }
function onDoc(e) { if (open.value && root.value && !root.value.contains(e.target)) open.value = false }
function onKey(e) { if (e.key === 'Escape') open.value = false }
onMounted(() => { document.addEventListener('click', onDoc, true); document.addEventListener('keydown', onKey) })
onUnmounted(() => { document.removeEventListener('click', onDoc, true); document.removeEventListener('keydown', onKey) })
</script>

<template>
  <div class="lang" ref="root">
    <button type="button" class="lang-trigger" :class="{ compact }" @click.stop="open = !open" :title="$t('common.language')">
      <Languages :size="17" />
      <span v-if="!compact" class="lang-current">{{ active.label }}</span>
    </button>
    <div v-if="open" class="lang-menu" data-no-translate>
      <button
        v-for="l in locales" :key="l.code"
        type="button" class="lang-opt" :class="{ sel: l.code === active.code }"
        @click="pick(l.code)"
      >
        <span class="lang-opt-label">{{ l.label }}</span>
        <Check v-if="l.code === active.code" :size="15" class="lang-opt-check" />
      </button>
    </div>
  </div>
</template>

<style scoped>
.lang { position: relative; }
.lang-trigger {
  display: inline-flex; align-items: center; gap: 7px; height: 38px; padding: 0 12px;
  border: 1.5px solid var(--border, #e2e8f0); border-radius: 999px; background: var(--surface, #ffffff); color: #334155;
  font-size: 13.5px; font-weight: 600; cursor: pointer;
}
.lang-trigger:hover { border-color: var(--brand-accent, #14b8a6); color: var(--brand, #0f766e); }
.lang-trigger.compact { width: 38px; height: 38px; padding: 0; justify-content: center; border-radius: 50%; }
.lang-current { max-width: 130px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

.lang-menu {
  position: absolute; top: calc(100% + 6px); inset-inline-end: 0; z-index: 200;
  background: var(--surface, #ffffff); border: 1px solid var(--border, #e2e8f0); border-radius: 12px;
  box-shadow: 0 12px 32px rgba(0,0,0,.14); padding: 5px; min-width: 190px;
  max-height: 340px; overflow-y: auto; animation: langIn .12s ease;
}
@keyframes langIn { from { opacity: 0; transform: translateY(-4px); } to { opacity: 1; transform: none; } }
.lang-opt {
  display: flex; align-items: center; justify-content: space-between; gap: 10px; width: 100%;
  padding: 9px 11px; border: none; background: none; border-radius: 8px; cursor: pointer;
  font-size: 14px; color: #334155; text-align: start;
}
.lang-opt:hover { background: #f1f5f9; }
.lang-opt.sel { background: #f0fdfa; color: var(--brand, #0f766e); font-weight: 700; }
.lang-opt-check { color: var(--brand, #0f766e); flex-shrink: 0; }
</style>
