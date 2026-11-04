/**
 * phone.js — country dial codes + phone helpers for the customer storefront.
 *
 * The `countries` table only has `iso_code` (no dial code), so we keep a static
 * ISO→dial map here. Phone values are stored as one string "<dial> <national>"
 * (e.g. "+974 50123456"); `splitPhone`/`joinPhone` convert to/from the editor's
 * two parts, and `dialForIso` picks the default code from the shopper's region.
 */

// iso (ISO-3166 alpha-2) · name · dial. Comprehensive enough for Bookboll's markets;
// any iso not listed falls back to an empty dial (the user can still pick from the list).
export const COUNTRY_DIALS = [
  { iso: 'AE', name: 'United Arab Emirates', dial: '+971' },
  { iso: 'QA', name: 'Qatar', dial: '+974' },
  { iso: 'SA', name: 'Saudi Arabia', dial: '+966' },
  { iso: 'KW', name: 'Kuwait', dial: '+965' },
  { iso: 'BH', name: 'Bahrain', dial: '+973' },
  { iso: 'OM', name: 'Oman', dial: '+968' },
  { iso: 'EG', name: 'Egypt', dial: '+20' },
  { iso: 'JO', name: 'Jordan', dial: '+962' },
  { iso: 'LB', name: 'Lebanon', dial: '+961' },
  { iso: 'IQ', name: 'Iraq', dial: '+964' },
  { iso: 'IL', name: 'Israel', dial: '+972' },
  { iso: 'TR', name: 'Turkey', dial: '+90' },
  { iso: 'IR', name: 'Iran', dial: '+98' },
  { iso: 'PK', name: 'Pakistan', dial: '+92' },
  { iso: 'IN', name: 'India', dial: '+91' },
  { iso: 'BD', name: 'Bangladesh', dial: '+880' },
  { iso: 'LK', name: 'Sri Lanka', dial: '+94' },
  { iso: 'NP', name: 'Nepal', dial: '+977' },
  { iso: 'AF', name: 'Afghanistan', dial: '+93' },
  { iso: 'CN', name: 'China', dial: '+86' },
  { iso: 'JP', name: 'Japan', dial: '+81' },
  { iso: 'KR', name: 'South Korea', dial: '+82' },
  { iso: 'ID', name: 'Indonesia', dial: '+62' },
  { iso: 'MY', name: 'Malaysia', dial: '+60' },
  { iso: 'SG', name: 'Singapore', dial: '+65' },
  { iso: 'TH', name: 'Thailand', dial: '+66' },
  { iso: 'PH', name: 'Philippines', dial: '+63' },
  { iso: 'VN', name: 'Vietnam', dial: '+84' },
  { iso: 'HK', name: 'Hong Kong', dial: '+852' },
  { iso: 'TW', name: 'Taiwan', dial: '+886' },
  { iso: 'AU', name: 'Australia', dial: '+61' },
  { iso: 'NZ', name: 'New Zealand', dial: '+64' },
  { iso: 'GB', name: 'United Kingdom', dial: '+44' },
  { iso: 'IE', name: 'Ireland', dial: '+353' },
  { iso: 'FR', name: 'France', dial: '+33' },
  { iso: 'DE', name: 'Germany', dial: '+49' },
  { iso: 'ES', name: 'Spain', dial: '+34' },
  { iso: 'PT', name: 'Portugal', dial: '+351' },
  { iso: 'IT', name: 'Italy', dial: '+39' },
  { iso: 'NL', name: 'Netherlands', dial: '+31' },
  { iso: 'BE', name: 'Belgium', dial: '+32' },
  { iso: 'CH', name: 'Switzerland', dial: '+41' },
  { iso: 'AT', name: 'Austria', dial: '+43' },
  { iso: 'SE', name: 'Sweden', dial: '+46' },
  { iso: 'NO', name: 'Norway', dial: '+47' },
  { iso: 'DK', name: 'Denmark', dial: '+45' },
  { iso: 'FI', name: 'Finland', dial: '+358' },
  { iso: 'PL', name: 'Poland', dial: '+48' },
  { iso: 'CZ', name: 'Czechia', dial: '+420' },
  { iso: 'GR', name: 'Greece', dial: '+30' },
  { iso: 'RO', name: 'Romania', dial: '+40' },
  { iso: 'HU', name: 'Hungary', dial: '+36' },
  { iso: 'RU', name: 'Russia', dial: '+7' },
  { iso: 'UA', name: 'Ukraine', dial: '+380' },
  { iso: 'US', name: 'United States', dial: '+1' },
  { iso: 'CA', name: 'Canada', dial: '+1' },
  { iso: 'MX', name: 'Mexico', dial: '+52' },
  { iso: 'BR', name: 'Brazil', dial: '+55' },
  { iso: 'AR', name: 'Argentina', dial: '+54' },
  { iso: 'CL', name: 'Chile', dial: '+56' },
  { iso: 'CO', name: 'Colombia', dial: '+57' },
  { iso: 'PE', name: 'Peru', dial: '+51' },
  { iso: 'ZA', name: 'South Africa', dial: '+27' },
  { iso: 'NG', name: 'Nigeria', dial: '+234' },
  { iso: 'KE', name: 'Kenya', dial: '+254' },
  { iso: 'GH', name: 'Ghana', dial: '+233' },
  { iso: 'ET', name: 'Ethiopia', dial: '+251' },
  { iso: 'TZ', name: 'Tanzania', dial: '+255' },
  { iso: 'UG', name: 'Uganda', dial: '+256' },
  { iso: 'MA', name: 'Morocco', dial: '+212' },
  { iso: 'DZ', name: 'Algeria', dial: '+213' },
  { iso: 'TN', name: 'Tunisia', dial: '+216' },
  { iso: 'LY', name: 'Libya', dial: '+218' },
  { iso: 'SD', name: 'Sudan', dial: '+249' },
  { iso: 'YE', name: 'Yemen', dial: '+967' },
  { iso: 'SY', name: 'Syria', dial: '+963' },
  { iso: 'PS', name: 'Palestine', dial: '+970' },
]

const byIso = Object.fromEntries(COUNTRY_DIALS.map(c => [c.iso, c]))
// dial codes longest-first, so "+971" wins over "+9" when detecting from a stored number
const DIALS_DESC = [...new Set(COUNTRY_DIALS.map(c => c.dial))].sort((a, b) => b.length - a.length)

// Alphabetical list for the picker dropdown.
export const DIAL_OPTIONS = [...COUNTRY_DIALS].sort((a, b) => a.name.localeCompare(b.name))

export function dialForIso(iso) { return byIso[(iso || '').toUpperCase()]?.dial || '' }
export function isoForDial(dial) { return DIAL_OPTIONS.find(c => c.dial === dial)?.iso || '' }

// 🇦🇪 flag emoji from an ISO-2 code (regional-indicator letters).
export function flagForIso(iso) {
  const s = (iso || '').toUpperCase()
  if (!/^[A-Z]{2}$/.test(s)) return '🌐'
  return String.fromCodePoint(...[...s].map(c => 0x1f1e6 + c.charCodeAt(0) - 65))
}

// Split a stored "<dial> <national>" into parts. Falls back to `fallbackDial` when no
// dial is detected (e.g. a bare local number, or a brand-new field).
export function splitPhone(full, fallbackDial = '') {
  const raw = (full || '').trim()
  if (!raw) return { dial: fallbackDial, national: '' }
  const compact = raw.replace(/[\s-]/g, '')
  for (const d of DIALS_DESC) {
    if (compact.startsWith(d)) return { dial: d, national: compact.slice(d.length) }
  }
  if (compact.startsWith('+')) return { dial: fallbackDial, national: compact.replace(/^\+/, '') }
  return { dial: fallbackDial, national: compact }
}

// Combine a dial + national into one stored string. Empty national → empty (so an
// untouched field with only a dial code doesn't masquerade as a real number).
export function joinPhone(dial, national) {
  const n = (national || '').trim()
  if (!n) return ''
  return `${dial || ''} ${n}`.trim()
}
