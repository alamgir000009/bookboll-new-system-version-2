// distance.js — great-circle distance between two coordinates, for "X km away" labels
// on the storefront cards. Pure helpers (no state); the visitor's coords live in
// customerSession (set only when they grant geolocation), venue coords come from the
// admin location pin.

/** Haversine distance in kilometres between two {lat,lng} points. Null if any input is missing. */
export function haversineKm(lat1, lng1, lat2, lng2) {
  const nums = [lat1, lng1, lat2, lng2]
  if (nums.some(v => v == null || Number.isNaN(Number(v)))) return null
  const R = 6371
  const toRad = (d) => (Number(d) * Math.PI) / 180
  const dLat = toRad(lat2 - lat1)
  const dLng = toRad(lng2 - lng1)
  const a = Math.sin(dLat / 2) ** 2 +
    Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) * Math.sin(dLng / 2) ** 2
  return 2 * R * Math.asin(Math.sqrt(a))
}

/** Human-friendly distance: "850 m", "5.2 km", "12 km", "100+ km". '' if unknown. */
export function formatDistance(km) {
  if (km == null || Number.isNaN(Number(km))) return ''
  if (km < 1) return `${Math.round(km * 1000)} m`
  if (km < 10) return `${km.toFixed(1)} km`
  if (km <= 100) return `${Math.round(km)} km`
  return '100+ km'
}
