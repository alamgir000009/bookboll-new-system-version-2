/**
 * i18n.js — app-wide internationalization (vue-i18n).
 *
 * Architecture:
 *  • English is bundled as the always-available fallback; the other 16 locales are
 *    lazy-loaded on demand (one small JSON chunk per language) via import.meta.glob.
 *  • Composition mode (legacy:false) + globalInjection so templates can just use
 *    $t('some.key') without importing useI18n in every component.
 *  • setLocale() also flips <html dir> (Arabic = rtl) + lang, and persists the choice.
 *
 * Adding a language = add it to SUPPORTED_LOCALES and drop a matching src/locales/<code>.json.
 * Missing keys fall back to English automatically, so migration can be incremental.
 */
import { createI18n } from 'vue-i18n'
import en from '../locales/en.json'

export const SUPPORTED_LOCALES = [
  { code: 'en',      label: 'English',            dir: 'ltr' },
  { code: 'es',      label: 'Español',            dir: 'ltr' },
  { code: 'zh-Hans', label: '简体中文',            dir: 'ltr' },
  { code: 'zh-Hant', label: '繁體中文',            dir: 'ltr' },
  { code: 'de',      label: 'Deutsch',            dir: 'ltr' },
  { code: 'fr',      label: 'Français',           dir: 'ltr' },
  { code: 'ja',      label: '日本語',              dir: 'ltr' },
  { code: 'it',      label: 'Italiano',           dir: 'ltr' },
  { code: 'pt-BR',   label: 'Português (Brasil)', dir: 'ltr' },
  { code: 'ar',      label: 'العربية',            dir: 'rtl' },
  { code: 'hi',      label: 'हिन्दी',              dir: 'ltr' },
  { code: 'ko',      label: '한국어',              dir: 'ltr' },
  { code: 'ru',      label: 'Русский',            dir: 'ltr' },
  { code: 'id',      label: 'Bahasa Indonesia',   dir: 'ltr' },
  { code: 'vi',      label: 'Tiếng Việt',         dir: 'ltr' },
  { code: 'tr',      label: 'Türkçe',             dir: 'ltr' },
  { code: 'nl',      label: 'Nederlands',         dir: 'ltr' },
]

const CODES = SUPPORTED_LOCALES.map(l => l.code)
const RTL = new Set(SUPPORTED_LOCALES.filter(l => l.dir === 'rtl').map(l => l.code))
const STORAGE_KEY = 'bb_locale'

export const i18n = createI18n({
  legacy: false,
  globalInjection: true,
  locale: 'en',
  fallbackLocale: 'en',
  messages: { en },
})

// Lazy locale loaders (Vite generates a separate chunk per file).
const localeFiles = import.meta.glob('../locales/*.json')
const loaded = new Set(['en'])

function applyDir(code) {
  if (typeof document === 'undefined') return
  document.documentElement.setAttribute('dir', RTL.has(code) ? 'rtl' : 'ltr')
  document.documentElement.setAttribute('lang', code)
}

export function isRTL(code = i18n.global.locale.value) { return RTL.has(code) }
export function currentLocale() { return i18n.global.locale.value }

export async function setLocale(code) {
  if (!CODES.includes(code)) code = 'en'
  if (!loaded.has(code)) {
    const loader = localeFiles[`../locales/${code}.json`]
    if (loader) {
      try {
        const mod = await loader()
        i18n.global.setLocaleMessage(code, mod.default || mod)
        loaded.add(code)
      } catch { /* fall back to English for this locale */ }
    }
  }
  i18n.global.locale.value = code
  applyDir(code)
  try { localStorage.setItem(STORAGE_KEY, code) } catch { /* ignore */ }
}

// Pick the best supported locale from a saved choice or the browser.
function detectLocale() {
  try { const saved = localStorage.getItem(STORAGE_KEY); if (saved && CODES.includes(saved)) return saved } catch { /* ignore */ }
  const nav = (typeof navigator !== 'undefined' && navigator.language) || 'en'
  if (CODES.includes(nav)) return nav
  const base = nav.split('-')[0].toLowerCase()
  const special = { zh: 'zh-Hans', pt: 'pt-BR' }   // map bare codes to our regional variants
  if (special[base]) return special[base]
  return CODES.find(c => c.split('-')[0].toLowerCase() === base) || 'en'
}

export async function initLocale() { await setLocale(detectLocale()) }
