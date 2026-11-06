/**
 * Google Maps JavaScript API loader.
 *
 * Loads the script once, on demand, and hands back the `google.maps`
 * namespace. Components ask for the libraries they need through
 * `google.maps.importLibrary(...)` after this resolves.
 *
 * Configuration (admin .env):
 *   VITE_GOOGLE_MAPS_API_KEY  browser key, restricted to the admin host and to
 *                             the Maps JavaScript, Places and Geocoding APIs.
 *   VITE_GOOGLE_MAPS_MAP_ID   optional. Advanced markers need a map id; Google's
 *                             public DEMO_MAP_ID is used when none is set.
 */

export const GOOGLE_MAPS_API_KEY = String(import.meta.env.VITE_GOOGLE_MAPS_API_KEY || '').trim()
export const GOOGLE_MAPS_MAP_ID = String(import.meta.env.VITE_GOOGLE_MAPS_MAP_ID || '').trim() || 'DEMO_MAP_ID'

export function hasGoogleMapsKey() {
  return GOOGLE_MAPS_API_KEY !== ''
}

let loadPromise = null

/**
 * Resolve with `google.maps` once the API is on the page. Rejects when no key
 * is configured or the script fails to load, so callers can show a fallback.
 */
export function loadGoogleMaps() {
  if (loadPromise) return loadPromise
  if (!hasGoogleMapsKey()) {
    return Promise.reject(new Error('VITE_GOOGLE_MAPS_API_KEY is not set'))
  }
  if (window.google?.maps?.importLibrary) {
    loadPromise = Promise.resolve(window.google.maps)
    return loadPromise
  }

  loadPromise = new Promise((resolve, reject) => {
    // Google's documented bootstrap: a tiny inline loader that defines
    // google.maps.importLibrary and fetches the rest lazily.
    const params = {
      key: GOOGLE_MAPS_API_KEY,
      v: 'weekly',
      language: 'en',
    }
    const g = params
    let h, a, k
    const p = 'The Google Maps JavaScript API'
    const c = 'google'
    const l = 'importLibrary'
    const q = '__ib__'
    const m = document
    let b = window
    b = b[c] || (b[c] = {})
    const d = b.maps || (b.maps = {})
    const r = new Set()
    const e = new URLSearchParams()
    const u = () => h || (h = new Promise((f, n) => {
      a = m.createElement('script')
      e.set('libraries', [...r] + '')
      for (k in g) e.set(k.replace(/[A-Z]/g, t => '_' + t[0].toLowerCase()), g[k])
      e.set('callback', c + '.maps.' + q)
      a.src = `https://maps.${c}apis.com/maps/api/js?` + e
      d[q] = f
      a.onerror = () => (h = n(Error(p + ' could not load.')))
      a.nonce = m.querySelector('script[nonce]')?.nonce || ''
      m.head.append(a)
    }))
    if (d[l]) {
      resolve(d)
      return
    }
    d[l] = (f, ...n) => r.add(f) && u().then(() => d[l](f, ...n))

    // Pull the core library right away so a failed key surfaces here, not
    // later inside a component.
    d[l]('core').then(() => resolve(d)).catch(reject)
  })
  loadPromise.catch(() => { loadPromise = null })   // allow a retry after a network failure
  return loadPromise
}

/**
 * Read country / city / address out of a Google geocoder result.
 * Returns { countryName, countryIso, cityCandidates, formattedAddress }.
 */
export function placeFromGeocoderResult(result) {
  const comps = result?.address_components || []
  const find = (type) => comps.find(c => (c.types || []).includes(type))
  const country = find('country')
  const cityCandidates = [
    find('locality'), find('postal_town'), find('administrative_area_level_3'),
    find('sublocality_level_1'), find('sublocality'), find('administrative_area_level_2'),
    find('administrative_area_level_1'),
  ].map(c => String(c?.long_name || '').trim()).filter(Boolean)
  return {
    countryName: country?.long_name || '',
    countryIso: String(country?.short_name || '').toUpperCase(),
    cityCandidates,
    formattedAddress: result?.formatted_address || '',
  }
}
