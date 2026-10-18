/**
 * Money as integer minor units, matching the server exactly.
 *
 * The storefront quotes a figure next to the Pay button and the server charges
 * one; if the two round differently the customer is shown a number they are not
 * billed. This mirrors app/Support/Money.php — the same currency exponents and
 * the same half-up rounding — so a fractional commission like 7.5% reads 7.50
 * on both ends instead of being floored to 7 by the browser.
 */

/** Currencies with no minor unit at all. */
const ZERO_DECIMAL = new Set([
  'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA',
  'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
])

/** Currencies with three. */
const THREE_DECIMAL = new Set(['BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND'])

export function exponent(currency) {
  const code = String(currency || '').trim().toUpperCase()
  if (ZERO_DECIMAL.has(code)) return 0
  if (THREE_DECIMAL.has(code)) return 3
  // Two is both the common case and the safe default for an unknown code.
  return 2
}

export const factor = (currency) => 10 ** exponent(currency)

export function toMinor(amount, currency) {
  return Math.round((Number(amount) || 0) * factor(currency))
}

export function toMajor(minor, currency) {
  const f = factor(currency)
  return f === 1 ? Number(minor) || 0 : Number(((Number(minor) || 0) / f).toFixed(exponent(currency)))
}

/** A percentage of an amount, in minor units, rounded half-up like the server. */
export function percentOf(minor, percent) {
  const m = Number(minor) || 0
  const p = Number(percent) || 0
  if (m <= 0 || p <= 0) return 0
  return Math.round((m * p) / 100)
}
