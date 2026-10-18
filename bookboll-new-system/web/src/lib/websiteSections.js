/** Missing settings keep existing provider sites unchanged. These are display
 * preferences, not access controls for published listings or their addresses. */
export function websiteSectionEnabled(pages, key) {
  return pages?.home?.sections?.[key] !== false
}
