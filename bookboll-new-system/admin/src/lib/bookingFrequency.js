import { bookingKind, isCommercialBooking } from './bookingMeta.js'

// The activity card is an observed booking frequency, not a lifetime retention
// forecast. Keep the numerator and denominator on exactly the same population.
export function bookingFrequency(rows = []) {
  const bookings = new Map()
  for (const row of rows) {
    if (!isCommercialBooking(row) || bookingKind(row) !== 'venue' ||
        !['confirmed', 'completed'].includes(row.booking_status) || row.payment_status === 'refunded' ||
        row.id == null || row.customer_id == null || !String(row.customer_id).trim()) continue
    bookings.set(String(row.id), row)
  }
  const customers = new Map()
  for (const row of bookings.values()) {
    const id = String(row.customer_id)
    customers.set(id, (customers.get(id) || 0) + 1)
  }
  const repeatCustomers = [...customers.values()].filter(count => count > 1).length
  return {
    bookings: bookings.size,
    customers: customers.size,
    repeatCustomers,
    average: customers.size ? bookings.size / customers.size : null,
    repeatRate: customers.size ? repeatCustomers / customers.size * 100 : null,
  }
}
