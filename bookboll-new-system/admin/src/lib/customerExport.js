import { customerTypeInfo, CUSTOMER_TYPES } from './customerTypes.js'
import { isCommercialBooking } from './bookingMeta.js'
/** Export aggregates must use the export response, not the table's current page. */
export function customerExportFields(data) {
  const byId = (rows, id) => (rows || []).find(row => String(row.id) === String(id))
  const bookings = new Map(), loyalty = new Map()
  for (const row of data.bookings || []) {
    if (!isCommercialBooking(row)) continue
    const key = String(row.customer_id), entry = bookings.get(key) || { count: 0, spend: {} }
    const venue = byId(data.locations, row.location_id), city = byId(data.cities, venue?.city_id)
    const country = byId(data.countries, city?.country_id || venue?.country_id)
    const currency = row.currency || venue?.currency_override || country?.currency_code || 'Currency unspecified'
    entry.count++
    entry.spend[currency] = (entry.spend[currency] || 0) + Number(row.total || 0)
    bookings.set(key, entry)
  }
  const presets = [{name:'Rookie',threshold:0},{name:'Star',threshold:1000},{name:'Legend',threshold:5000},{name:'GOAT',threshold:15000}]
  for (const row of data.customer_loyalty || []) {
    const program = (data.loyalty_programs || []).find(p => String(p.country_id) === String(row.country_id))
    const configured = program ? (data.loyalty_tiers || []).filter(t => String(t.program_id) === String(program.id)) : []
    const tiers = (configured.length ? configured : presets).slice().sort((a,b) => Number(b.threshold) - Number(a.threshold))
    const tier = tiers.find(t => Number(row.lifetime_points || 0) >= Number(t.threshold || 0)) || presets[0]
    const item = { country: byId(data.countries, row.country_id)?.name || 'Country unspecified', points: Number(row.points || 0), tier: tier.name, rank: Number(tier.threshold) }
    const key = String(row.customer_id)
    loyalty.set(key,[...(loyalty.get(key) || []),item])
  }
  return {
    types: row => { const info=customerTypeInfo(row,data); return info.types.map(type=>CUSTOMER_TYPES.find(item=>item.value===type).label).join(' | ') || (info.complete?'No qualifying activity':'Unavailable') },
    count: row => bookings.get(String(row.id))?.count ?? (Array.isArray(data.bookings) ? 0 : '—'),
    spend: row => Object.entries(bookings.get(String(row.id))?.spend || {}).map(([currency,amount]) => `${currency} ${amount}`).join(' | ') || '—',
    tier: row => [...(loyalty.get(String(row.id)) || [])].sort((a,b) => b.rank-a.rank)[0]?.tier || '—',
    points: row => (loyalty.get(String(row.id)) || []).map(r => `${r.country} ${r.points} (${r.tier})`).join(' | ') || (Array.isArray(data.customer_loyalty) ? '0' : '—'),
  }
}
