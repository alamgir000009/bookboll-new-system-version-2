export function missingPricingSetup({ enabled, cells = [], groups = [], form }) {
  const base = Number(form.base_price)
  const hasCustomPrice = enabled && cells.some(cell => {
    const group = groups.find(group => group.id === cell.pricing_group_id)
    const price = Number(cell.direct_price ?? group?.price)
    return Number.isFinite(price) && price > 0 && price !== base
  })
  const discount = Number(form.extension_value)
  const hasExtension = discount > 0 && (
    (form.extension_pricing === 'percent' && discount < 100) ||
    (form.extension_pricing === 'flat' && discount < base)
  )
  return [...(!hasCustomPrice ? ['dynamic'] : []), ...(!hasExtension ? ['extension'] : [])]
}

// A skip applies only to the current editor session. Opening another listing resets it.
export function pricingSetupGate() {
  const dismissed = new Set()
  return {
    missing: features => features.filter(feature => !dismissed.has(feature)),
    skip: features => features.forEach(feature => dismissed.add(feature)),
    reset: () => dismissed.clear(),
  }
}
