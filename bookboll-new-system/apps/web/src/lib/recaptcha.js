/**
 * Google reCAPTCHA v2 — the human check the API asks for when a device tries to
 * hold more slots than a real booking needs (see SlotHoldVerification on the API).
 *
 * Explicit rendering on purpose: the script is only fetched the first time a
 * challenge is actually required, so the booking page costs nothing extra for the
 * customers who never see one.
 *
 * The site key is NOT configured here — it comes down with the API's challenge, so
 * there is one place to rotate it.
 */

const READY_CALLBACK = '__bbRecaptchaReady'
const SCRIPT_SRC = `https://www.google.com/recaptcha/api.js?onload=${READY_CALLBACK}&render=explicit`

let loader = null

/**
 * Fetch the reCAPTCHA script once; later callers reuse the same promise.
 * v2 only guarantees grecaptcha.render exists once its onload callback has fired,
 * so we wait on that rather than on the script's own load event.
 */
export function loadRecaptcha() {
  if (typeof window === 'undefined') return Promise.reject(new Error('no window'))
  if (window.grecaptcha?.render) return Promise.resolve(window.grecaptcha)
  if (loader) return loader

  loader = new Promise((resolve, reject) => {
    window[READY_CALLBACK] = () => {
      window.grecaptcha?.render
        ? resolve(window.grecaptcha)
        : reject(new Error('reCAPTCHA did not initialise'))
    }

    const script = document.createElement('script')
    script.src = SCRIPT_SRC
    script.async = true
    script.defer = true
    script.addEventListener('error', () => {
      loader = null   // a blocked script shouldn't poison every later attempt
      reject(new Error('Could not load the security check'))
    })
    document.head.appendChild(script)
  })

  return loader
}

/**
 * Draw a checkbox widget into `el` and hand each solved token to `onToken`.
 * Returns the widget id (0 is valid, so callers must not test it for truthiness).
 */
export async function renderRecaptcha(el, siteKey, { onToken, onExpire, onError, theme = 'light' } = {}) {
  if (!el || !siteKey) throw new Error('The security check is not configured.')
  const grecaptcha = await loadRecaptcha()
  return grecaptcha.render(el, {
    sitekey: siteKey,
    theme,
    callback: (token) => onToken?.(token),
    'expired-callback': () => onExpire?.(),
    'error-callback': () => onError?.(),
  })
}

/** Back to an unticked box — a token is single-use, so a retry needs a fresh one. */
export function resetRecaptcha(widgetId) {
  try {
    if (widgetId !== null && widgetId !== undefined && window.grecaptcha) window.grecaptcha.reset(widgetId)
  } catch { /* already gone */ }
}
