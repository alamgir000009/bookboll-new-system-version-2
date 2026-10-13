/**
 * venueCategories.js — the venue taxonomy shared across the admin dashboard, the
 * customer storefront and the create-event flow.
 *
 * A venue/listing has a MAIN category (`listings.category`) and a TYPE / subcategory
 * (kept in the existing `listings.sport_category` column, so non-sports types like
 * "Bowling" or "Podcast Studio" live there too). Default category is 'sports' so
 * everything keeps working before the category migration is applied.
 */

export const VENUE_CATEGORIES = [
  {
    key: 'sports',
    label: 'Sports',
    blurb: 'Courts, pitches, gyms & clubs',
    subcategories: ['Football', 'Padel', 'Tennis', 'Basketball', 'Cricket', 'Volleyball', 'Badminton', 'Squash', 'Swimming', 'Gym', 'Other'],
  },
  {
    key: 'hangout',
    label: 'Hangout',
    blurb: 'Restaurants, bowling, escape rooms & more',
    subcategories: ['Restaurant', 'Café', 'Escape Room', 'Bowling', 'Billiards', 'Pool', 'Snooker', 'Darts', 'Shuffleboard', 'Mini Golf', 'Arcade', 'VR Gaming', 'AR Gaming', 'Laser Maze', 'Racing Simulator', 'Flight Simulator', 'Golf Simulator', 'Laser Tag', 'Archery Tag', 'Axe Throwing', 'Knife Throwing', 'Karaoke', 'Cinema', 'Desert Safari', 'Party Hall', 'Other'],
  },
  {
    key: 'work',
    label: 'Work',
    blurb: 'Studios & creative / work spaces',
    subcategories: ['Podcast Studio', 'Content / Creator Room', 'Photo Studio', 'Meeting Room', 'Coworking Desk', 'Event Space', 'Other'],
  },
]

export const CATEGORY_BY_KEY = Object.fromEntries(VENUE_CATEGORIES.map(c => [c.key, c]))

// Normalise any stored value to a known category key (missing/legacy → 'sports').
export function normCategory(key) {
  const k = String(key || '').toLowerCase()
  return CATEGORY_BY_KEY[k] ? k : 'sports'
}
export function categoryLabel(key) { return CATEGORY_BY_KEY[normCategory(key)].label }
export function subcategoriesFor(key) { return CATEGORY_BY_KEY[normCategory(key)].subcategories }
