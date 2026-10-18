// A requested country is not proof that an older API actually scoped its response.
// Never label a global account balance as a country's balance.
export function scopedRewards(data, countryId) {
  if (!countryId || !data || String(data.country_id ?? data.country?.id ?? '') !== String(countryId)) return null
  return {
    ...data,
    ledger: (Array.isArray(data.ledger) ? data.ledger : []).filter(row => row.country_id == null || String(row.country_id) === String(countryId)),
  }
}

export function verifiedRewardRules(data, countryId) {
  const rule = data?.loyalty
  if (!rule || String(rule.country_id ?? '') !== String(countryId)) return null
  const earn = Number(rule.earn_rate), redeem = Number(rule.redeem_rate), min = Number(rule.min_redeem)
  if (!Number.isFinite(earn) || earn < 0 || !Number.isFinite(redeem) || redeem <= 0 || !Number.isFinite(min) || min < 0) return null
  return { ...rule, earn_rate: earn, redeem_rate: redeem, min_redeem: min, redeem_value: 1,
    is_active: ![false, 0, '0'].includes(rule.is_active),
    expiry_months: Number.isFinite(Number(rule.expiry_months)) ? Math.max(0, Number(rule.expiry_months)) : null,
  }
}

export function rewardExample(amount, rule, multiplier = 1) {
  return Math.max(0, Math.floor((Number(amount) || 0) * rule.earn_rate * Math.max(0, Number(multiplier) || 1)))
}
