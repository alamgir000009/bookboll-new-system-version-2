/**
 * The currency a just-created booking is priced in.
 *
 * The server stores it on the booking row and returns the full rows, so that is
 * the only answer that cannot be wrong. The venue is the fallback, and `location`
 * is asked for its RESOLVED currency before its override: the override is
 * nullable — it exists only to override the country — so reading it alone is
 * what used to drop a PKR booking onto a hardcoded 'AED' and print
 * "Pay AED 100" on a Lahore confirmation.
 *
 * Never guesses. An unknown currency comes back empty so the amount shows bare
 * rather than wearing someone else's money.
 *
 * Lives in lib/ rather than beside its caller so it stays free of the API
 * client, and therefore testable on its own.
 *
 * @param  {Array|null} rows      booking rows as the create/checkout APIs return them
 * @param  {object|null} location the venue the booking was made at
 * @return {string}               an ISO currency code, or '' when unknown
 */
export function createdBookingCurrency(rows, location) {
  const fromServer = (Array.isArray(rows) ? rows : [])
    .find(b => b && typeof b === 'object' && b.currency)?.currency

  return fromServer || location?.currency || location?.currency_override || ''
}
