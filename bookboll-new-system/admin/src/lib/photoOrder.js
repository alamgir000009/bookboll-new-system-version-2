/** Return a new gallery in display order; index zero is always the cover. */
export function movePhoto(photos, from, to) {
  const next = [...photos]
  if (!Number.isInteger(from) || !Number.isInteger(to) || from < 0 || to < 0 || from >= next.length || to >= next.length) return next
  next.splice(to, 0, next.splice(from, 1)[0])
  return next
}
