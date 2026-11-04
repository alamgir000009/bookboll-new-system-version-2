import test from 'node:test'
import assert from 'node:assert/strict'
import { bookingBucket, selectPersonalBookings } from '../src/lib/personalBookings.js'

test('cancelled reservations stay separate from past and upcoming visits', () => {
  const today = '2026-10-14'
  for (const booking_date of ['2026-10-12', '2026-10-20']) assert.equal(bookingBucket({booking_date, booking_status:'cancelled'},today),'cancelled')
  assert.equal(bookingBucket({booking_date:today,booking_status:'pending'},today),'upcoming')
  assert.equal(bookingBucket({booking_date:today,booking_status:'completed'},today),'past')
  assert.equal(bookingBucket({booking_date:'2026-10-13',booking_status:'confirmed'},today),'past')
})

test('bookings search includes venue and reference while keeping chronological order and source unchanged', () => {
  const rows = [
    {id:3,booking_date:'2026-10-20',start_time:'18:00',locationName:'Doha Sports',bookingRef:'BB-003'},
    {id:1,booking_date:'2026-10-16',start_time:'18:00',locationName:'Doha Sports',bookingRef:'BB-001'},
    {id:2,booking_date:'2026-10-16',start_time:'09:00',listingName:'Padel',bookingRef:'BB-002'},
    {id:4,booking_date:'2026-10-13',listingName:'Padel',bookingRef:'BB-004'},
    {id:5,booking_date:'2026-10-12',listingName:'Padel',bookingRef:'BB-005'},
  ]
  assert.deepEqual(selectPersonalBookings(rows,'upcoming','','2026-10-14').map(b=>b.id),[2,1,3])
  assert.deepEqual(selectPersonalBookings(rows,'upcoming',' doha ','2026-10-14').map(b=>b.id),[1,3])
  assert.deepEqual(selectPersonalBookings(rows,'upcoming','bb-002','2026-10-14').map(b=>b.id),[2])
  assert.deepEqual(selectPersonalBookings(rows,'past','','2026-10-14').map(b=>b.id),[4,5])
  assert.deepEqual(rows.map(b=>b.id),[3,1,2,4,5])
})
