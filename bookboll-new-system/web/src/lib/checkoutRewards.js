// Every checkout uses the same funding boundary. Online payment is NOT the
// platform's share: it may include money owed to a venue or an event host.
export function bookbollShare(parts) {
  if (!parts || !['commission', 'event_fee', 'booking_fee'].every(k => parts[k] != null && Number.isFinite(Number(parts[k])) && Number(parts[k]) >= 0)) return null
  // Round down fractional minor units: a quote must never fund more than its share.
  return ['commission', 'event_fee', 'booking_fee'].reduce((sum, key) => sum + Math.floor(Number(parts[key]) * 100 + 1e-8), 0) / 100
}

export function validateRedemptionQuote(quote, { countryId, currency, balance, rules, total, dueNow, now = Date.now() }) {
  const share = bookbollShare(quote?.bookboll_share)
  if (!quote?.quote_id || String(quote.country_id) !== String(countryId) || quote.currency !== currency || !rules?.is_active || share == null) return null
  const amount = Number(quote.discount), points = Number(quote.points)
  const expiry = Date.parse(quote.expires_at)
  const rate = Number(rules.redeem_rate), minimum = Number(rules.min_redeem)
  const capPct = Number(rules.max_redeem_pct)
  if (![amount, points, rate, minimum, capPct, Number(balance), Number(total), Number(dueNow)].every(Number.isFinite)) return null
  if (!Number.isInteger(points) || points < minimum || points <= 0 || points > balance || rate <= 0 || capPct <= 0 || !Number.isInteger(amount) || points !== amount * rate) return null
  const cents = value => Math.round(Number(value) * 100)
  if (!Number.isFinite(expiry) || expiry <= now || amount <= 0) return null
  if (cents(quote.order_total_before) !== cents(total) || cents(quote.due_now_before) !== cents(dueNow)) return null
  const maxCents = Math.min(cents(share), cents(total), cents(dueNow), Math.floor(cents(total) * Math.min(100, capPct) / 100))
  if (cents(amount) > maxCents) return null
  return { quoteId: String(quote.quote_id), points, amount, expiresAt: expiry, share }
}

export function redemptionFields(reward) {
  if (!reward) return {}
  if (!reward.quoteId || !Number.isSafeInteger(reward.points) || reward.points <= 0 || !Number.isFinite(reward.expiresAt) || reward.expiresAt <= Date.now()) throw new Error('Your rewards quote expired. Review your points and try again.')
  // Never send a client-computed commission, payout or discount. The API owns
  // the signed quote, re-prices the order and debits the account atomically.
  return { loyalty_quote_id: reward.quoteId, loyalty_points: reward.points }
}
