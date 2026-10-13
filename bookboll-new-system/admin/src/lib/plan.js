/**
 * plan.js — the provider's own subscription and paid add-ons, on our Laravel backend.
 *
 * Two things a provider pays Bookboll for:
 *   • Premium — $59/month or $588/year, with a free trial (Stripe subscription)
 *   • Custom domain — a one-time $119 setup fee (Stripe one-off payment)
 *
 * Both were simulated in the Supabase dashboard. Now every one of these calls ends
 * in a real Stripe Checkout redirect, and the entitlement only changes when Stripe
 * confirms it — the browser never grants itself a plan.
 */
import { apiFetch } from './api.js'

/** The provider's current plan, pricing and domain state. */
export function fetchPlan() {
  return apiFetch('/admin/plan')
}

/** Start a Premium checkout. Returns { url } to redirect the browser to. */
export function startPlanCheckout({ billing = 'yearly', promoCode = null, returnPath = null } = {}) {
  return apiFetch('/admin/plan/checkout', {
    method: 'POST',
    body: { billing, promo_code: promoCode || null, return_path: returnPath || null },
  })
}

/**
 * Stripe sends the browser back before the webhook necessarily lands, so the page
 * asks the server to reconcile that session directly. Same fulfilment either way.
 */
export function confirmPlanCheckout(sessionId) {
  return apiFetch(`/admin/plan/checkout/${encodeURIComponent(sessionId)}`)
}

export function cancelPlan() {
  return apiFetch('/admin/plan/cancel', { method: 'POST' })
}

export function resumePlan() {
  return apiFetch('/admin/plan/resume', { method: 'POST' })
}

/** Start the one-time custom-domain fee checkout. Returns { url }. */
export function startDomainCheckout(domain, returnPath = '/website-domain') {
  return apiFetch('/admin/site-settings/domain/checkout', {
    method: 'POST',
    body: { domain, return_path: returnPath },
  })
}

export function confirmDomainCheckout(sessionId) {
  return apiFetch(`/admin/site-settings/domain/checkout/${encodeURIComponent(sessionId)}`)
}

/** Ask the server to look the CNAME up for real and take the domain live. */
export function verifyDomain() {
  return apiFetch('/admin/site-settings/domain/verify', { method: 'POST' })
}
