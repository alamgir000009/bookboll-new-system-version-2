// Use the recorded booking amount. Today's commission rate is not evidence of
// the rate or discounts applied to a historical booking.
export function commissionSummary(bookings, currencyFor) {
  const groups = new Map()
  let missing = 0
  for (const booking of bookings) {
    const raw = booking.commission_amount
    const validAmount = (typeof raw === 'number' || (typeof raw === 'string' && raw.trim() !== '')) && Number.isFinite(Number(raw))
    const currency = currencyFor(booking)
    if (!validAmount || !currency) {
      missing++
      continue
    }
    const group = groups.get(currency) || { currency, amount: 0, count: 0 }
    group.amount += Number(raw)
    group.count++
    groups.set(currency, group)
  }
  const totals = [...groups.values()]
    .sort((a, b) => b.amount - a.amount || b.count - a.count)
  const formatted = totals.map(({ currency, amount }) => {
    const digits = new Intl.NumberFormat('en', { style: 'currency', currency }).resolvedOptions().maximumFractionDigits
    return `${currency} ${amount.toLocaleString('en-US', { maximumFractionDigits: digits })}`
  })
  return {
    value: formatted[0] || (bookings.length ? 'Unavailable' : '—'),
    sub: formatted.slice(1).join(' · '),
    note: missing
      ? (totals.length ? `Partial total · ${missing.toLocaleString('en-US')} ${missing === 1 ? 'booking' : 'bookings'} missing commission data` : 'Commission data is not available for these bookings.')
      : bookings.length ? 'Recorded venue & event commission · excludes cancelled bookings' : 'No venue or event bookings in this view.',
    incomplete: missing > 0,
  }
}

function currencyCode(value) {
  const code = typeof value === 'string' ? value.trim().toUpperCase() : ''
  return /^[A-Z]{3}$/.test(code) ? code : null
}

// Unknown currency stays unknown; never silently book it as AED. Normalize IDs
// because API relations may arrive as numbers or strings.
export function commissionCurrency(booking, locations, cities, countries) {
  const explicit = currencyCode(booking.currency)
  if (explicit) return explicit
  const location = locations.find(l => String(l.id) === String(booking.location_id))
  const override = currencyCode(location?.currency_override)
  if (override) return override
  const city = cities.find(c => String(c.id) === String(location?.city_id))
  const countryId = location?.country_id ?? city?.country_id
  const country = countries.find(c => String(c.id) === String(countryId))
  return currencyCode(country?.currency_code)
}
