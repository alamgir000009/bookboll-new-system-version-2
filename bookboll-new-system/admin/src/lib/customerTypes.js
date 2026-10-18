import { bookingKind, isCommercialBooking } from './bookingMeta.js'

export const CUSTOMER_TYPES = [
  {value:'booking',label:'Booking customer'},
  {value:'membership',label:'Membership customer'},
  {value:'event',label:'Event customer'},
]
const keys = CUSTOMER_TYPES.map(item=>item.value)
export function customerTypeInfo(customer, activity = {}) {
  if (customer?.id == null) return {types:[],complete:false}
  if (customer?.customer_types_complete === true && Array.isArray(customer.customer_types) && customer.customer_types.every(type=>keys.includes(type))) {
    return {types:keys.filter(type=>customer.customer_types.includes(type)),complete:true}
  }
  const types = new Set()
  for (const row of activity.bookings || []) {
    if (String(row.customer_id) !== String(customer?.id) || !isCommercialBooking(row)) continue
    types.add(bookingKind(row)==='event'?'event':'booking')
  }
  for (const row of activity.subscriptions || []) {
    const matches = row.customer_id != null ? String(row.customer_id) === String(customer?.id) : !!customer?.email && row.customer_email?.trim().toLowerCase() === customer.email.trim().toLowerCase()
    if (matches && ['active','completed','expired'].includes(row.status)) types.add('membership')
  }
  return {types:keys.filter(type=>types.has(type)),complete:false}
}
export function customerTypeFilterConfirmed(data, query) {
  if (!query.customer_types?.length) return true
  const applied = data?.customer_type_filter
  return applied?.version === 1 && applied.match === query.customer_types_match &&
    Array.isArray(applied.types) && [...applied.types].sort().join('|') === [...query.customer_types].sort().join('|')
}
