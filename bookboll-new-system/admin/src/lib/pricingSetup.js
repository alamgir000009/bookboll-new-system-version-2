export function missingPricingSetup({ enabled, groups = [], form }) {
  const base = Number(form.base_price)
  // Pricing groups are the only store for dynamic prices now (the grid is a view
  // of them), so "has a custom price" means a group priced away from base.
  const hasCustomPrice = enabled && groups.some(group => {
    const price = Number(group?.price)
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
