export function bookingBucket(booking, today) {
  if (['cancelled', 'canceled'].includes(booking.booking_status)) return 'cancelled'
  if (booking.booking_status === 'completed' || !booking.booking_date || booking.booking_date < today) return 'past'
  return 'upcoming'
}
export function selectPersonalBookings(bookings, bucket, search, today) {
  const query = String(search || '').trim().toLocaleLowerCase()
  return bookings.filter(b => bookingBucket(b, today) === bucket && (!query || [b.listingName, b.locationName, b.bookingRef].some(v => String(v || '').toLocaleLowerCase().includes(query))))
    .sort((a, b) => {
      const order = `${a.booking_date || ''} ${a.start_time || ''}`.localeCompare(`${b.booking_date || ''} ${b.start_time || ''}`)
      return (bucket === 'upcoming' ? order : -order) || String(a.id).localeCompare(String(b.id))
    })
}
