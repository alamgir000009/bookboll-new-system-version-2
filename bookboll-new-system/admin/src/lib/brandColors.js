// Choose readable text for a provider's configured button color, including gold
// and other pale colors that cannot support white labels.
export function brandButtonText(hex = '') {
  let color = String(hex).replace(/^#/, '')
  if (/^[a-f\d]{3}$/i.test(color)) color = [...color].map(c => c + c).join('')
  if (!/^[a-f\d]{6}$/i.test(color)) return '#ffffff'
  const luminance = rgb => rgb.map(c => c / 255).map(c => c <= .04045 ? c / 12.92 : ((c + .055) / 1.055) ** 2.4)
    .reduce((sum, c, i) => sum + c * [.2126, .7152, .0722][i], 0)
  const background = luminance(color.match(/../g).map(c => parseInt(c, 16)))
  const dark = luminance([20, 33, 50])
  const darkContrast = (Math.max(background, dark) + .05) / (Math.min(background, dark) + .05)
  const whiteContrast = 1.05 / (background + .05)
  return darkContrast > whiteContrast ? '#142132' : '#ffffff'
}
