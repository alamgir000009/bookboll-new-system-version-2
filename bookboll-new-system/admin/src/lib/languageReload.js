// Keep a language switch atomic: persist first, then load fresh source markup.
// This also clears the runtime translator's session-only failures and stale nodes.
export function reloadInLanguage(code, currentCode, { storage, location, baseUrl = '/' }) {
  if (code === currentCode) return false
  storage.setItem('bb_locale', code)
  const url = new URL(location.href)
  const base = baseUrl.replace(/\/$/, '')
  const relative = url.pathname.slice(base.length)
  // Arabic SEO routes force Arabic on boot. Leave that edition for other languages.
  if (code !== 'ar' && /^\/ar(\/|$)/.test(relative)) {
    url.pathname = base + (relative.replace(/^\/ar(?=\/|$)/, '') || '/')
    location.assign(url.href)
  } else location.reload()
  return true
}
