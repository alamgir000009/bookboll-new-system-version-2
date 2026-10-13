/**
 * hostPayouts.js — how an event HOST gets paid when their tickets sell online.
 *
 * Two ways, and the host picks one:
 *   • connect — Bookboll creates a Stripe Express account and Stripe hosts the
 *     onboarding form. Bookboll's commission is taken automatically from each
 *     ticket, so there's no upfront seats fee.
 *   • keys — the host pastes their own Stripe keys and joiners are charged
 *     straight on their account. The secret only ever travels to our backend,
 *     which verifies it with Stripe before storing it encrypted.
 *
 * Everything here is real: no account is "connected" until Stripe says the
 * account can actually take charges.
 */
import { apiFetch } from '../lib/api.js'

function formatErr(res, fallback) {
  const errors = res?.errors
  if (errors && typeof errors === 'object') {
    for (const v of Object.values(errors)) {
      const msg = Array.isArray(v) ? v[0] : v
      if (msg) return String(msg)
    }
  }
  return res?.message || fallback
}

/** The host's payout setup, or null when they haven't set one up. */
export async function loadStripeConnect() {
  const res = await apiFetch('/customer/stripe/payouts')
  if (!res.success) return null
  return res.data?.account || null
}

/**
 * The countries a host may open a Stripe account in.
 *
 * Which ones work depends on Bookboll's own Stripe account, not on where the host
 * lives, so the list comes from the server — it has asked Stripe. `default` is our
 * best guess for this host (their profile country, else their phone's dial code)
 * and is null when we genuinely don't know; `locked` is set once an account exists,
 * because Stripe fixes a connected account's country for good at creation.
 */
export async function loadStripeCountries() {
  const res = await apiFetch('/customer/stripe/payouts/countries')
  if (!res.success) return { error: formatErr(res, 'Could not load the country list.'), countries: [] }
  return {
    countries: res.data?.countries || [],
    default: res.data?.default || '',
    locked: res.data?.locked || '',
    // Where the server thinks this host is, and whether Stripe will open an
    // account there. When it won't, their own Stripe keys are the way in — the
    // picker is no help to someone who can't verify in any of its countries.
    detected: res.data?.detected || '',
    detectedName: res.data?.detected_name || '',
    detectedSupported: res.data?.detected_supported,
  }
}

/**
 * Start Stripe's hosted onboarding. Returns the URL to send the host to — they
 * come back to `returnUrl`, where we re-check the account with Stripe.
 *
 * `country` decides the whole onboarding — which ID and bank details Stripe asks
 * for — and cannot be changed later, so it is always sent explicitly rather than
 * left for Stripe to infer from Bookboll's own account.
 */
export async function startStripeConnect({ returnUrl, refreshUrl, country }) {
  const res = await apiFetch('/customer/stripe/payouts/connect', {
    method: 'POST',
    body: { return_url: returnUrl, refresh_url: refreshUrl, country: country || null },
  })
  if (!res.success || !res.data?.url) return { error: formatErr(res, 'Could not start Stripe onboarding.') }
  return { url: res.data.url, account: res.data.account }
}

/** Re-read the connected account from Stripe (after onboarding, or on demand). */
export async function syncStripeConnect() {
  const res = await apiFetch('/customer/stripe/payouts/sync', { method: 'POST' })
  if (!res.success) return { error: formatErr(res, 'Could not refresh from Stripe.') }
  return { account: res.data?.account || null }
}

/** Verify and save the host's own Stripe keys. */
export async function saveStripeKeys({ secretKey, publishableKey }) {
  const res = await apiFetch('/customer/stripe/payouts/keys', {
    method: 'POST',
    body: { secret_key: secretKey, publishable_key: publishableKey || null },
  })
  if (!res.success) return { error: formatErr(res, 'Could not verify those keys.') }
  return { account: res.data?.account || null }
}

export async function disconnectStripe() {
  const res = await apiFetch('/customer/stripe/payouts', { method: 'DELETE' })
  return res.success ? { ok: true } : { error: formatErr(res, 'Could not disconnect.') }
}

/** Only an account Stripe says can take charges may sell tickets. */
export const isStripeConnected = (a) => !!(a && a.ready)
