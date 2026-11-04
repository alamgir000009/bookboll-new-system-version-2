export const LISTING_STEPS = [
  { id: 'details', label: 'Listing details', hint: 'Name, category & photos', sections: ['basic', 'images'] },
  { id: 'availability', label: 'Availability', hint: 'Booking length & opening hours', sections: ['timeslot', 'schedule'] },
  { id: 'pricing', label: 'Pricing & extras', hint: 'Price, add-ons & resources', sections: ['pricing', 'addons', 'resources'] },
  { id: 'payments', label: 'Booking & payments', hint: 'Where to book & how to pay', sections: ['placement'] },
  { id: 'review', label: 'Review', hint: 'Check before you save', sections: [] },
]
export const VENUE_STEPS = [
  { id: 'details', label: 'Venue details', hint: 'Name, city & introduction' },
  { id: 'map', label: 'Map & photos', hint: 'Help customers find you' },
  { id: 'schedule', label: 'Opening hours', hint: 'Weekly operating schedule' },
  { id: 'review', label: 'Review', hint: 'Check before you save' },
]
export function listingStepFor(section) {
  return LISTING_STEPS.find(step => step.sections.includes(section))?.id
}
const filled = value => String(value ?? '').trim().length > 0
export function listingStepIssue(step, { form, hours = [], hoursOutOfBounds = [], paymentError = '', customSlotErrors = [] }) {
  if (step === 'details' && !['name', 'location_id', 'category', 'sport_category', 'description'].every(key => filled(form[key]))) {
    return { section: 'basic', message: 'Add a listing name, venue, type and description to continue.' }
  }
  if (step === 'availability') {
    // Custom slots ARE the listing's hours, so the weekly schedule is not required then.
    const customSlots = form.start_time_mode === 'custom'
    if (!customSlots && !hours.some(day => day.is_open && day.periods?.length)) return { section: 'schedule', message: 'Open at least one day and add its opening hours.' }
    if (!customSlots && hoursOutOfBounds.length) return { section: 'schedule', message: `These hours are outside the venue schedule: ${hoursOutOfBounds[0]}. Adjust the listing or venue hours.` }
    if (!Number.isFinite(Number(form.slot_duration)) || Number(form.slot_duration) <= 0) return { section: 'timeslot', message: 'Choose how long one booking lasts.' }
    if (form.start_time_mode === 'custom') {
      if (!(Array.isArray(form.custom_slots) && form.custom_slots.length)) return { section: 'timeslot', message: 'Add at least one custom time slot, or switch back to automatic slots.' }
      if (customSlotErrors.length) return { section: 'timeslot', message: customSlotErrors[0] }
    }
  }
  if (step === 'pricing' && (!Number.isFinite(Number(form.base_price)) || Number(form.base_price) <= 0)) return { section: 'pricing', message: 'Enter a price greater than 0 for one booking.' }
  if (step === 'payments' && paymentError) return { section: 'placement', message: paymentError }
  return null
}
export function venueIssue(form, coordsInvalid = false) {
  if (!filled(form.name) || !filled(form.city_id)) return { step: 'details', message: 'Add a venue name and select its city.' }
  if (coordsInvalid) return { step: 'map', message: 'Enter a valid latitude and longitude, or clear both coordinates.' }
  return null
}
