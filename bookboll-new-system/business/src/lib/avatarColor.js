/**
 * avatarColor — deterministic, vivid background for initial-letter avatars.
 * Same person → same color everywhere (hash of their name/id); different people
 * get different hues, so a going-stack or request list looks lively instead of
 * a row of identical grey circles. Saturation/lightness are fixed in a range
 * that keeps white text readable in light AND dark (invert) themes.
 */
export function avatarColor(seed) {
  const s = String(seed || '?')
  let h = 0
  for (let i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) >>> 0
  const hue = h % 360
  const sat = 58 + (h % 5) * 6        // 58–82%
  const light = 42 + ((h >> 3) % 4) * 3 // 42–51%
  return `hsl(${hue} ${sat}% ${light}%)`
}
