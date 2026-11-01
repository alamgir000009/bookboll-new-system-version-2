/**
 * How a booking is named on screen.
 *
 * The database gives every booking a reference when it is created —
 * BKN_<year>_<month>_<sequence> — and that is the string staff search by,
 * customers quote in messages, and payment notes already print. The dashboard
 * used to IGNORE it and build its own label out of the row id: the list showed
 * "#A28392" while the detail drawer showed "#BKN_2026_20392" for the same
 * booking, and neither matched the record. One helper, one answer.
 *
 * A row with no stored reference (written before the generator existed) shows
 * its id plainly rather than being dressed up as a reference it never had.
 */
export function bookingRefLabel(booking) {
  const ref = String(booking?.booking_id ?? booking?.reference ?? '').trim()
  if (ref) return `#${ref}`
  const id = String(booking?.id ?? '').trim()
  return id ? `#${id}` : '—'
}
