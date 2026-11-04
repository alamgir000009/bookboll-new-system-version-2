import { blankBanner, recruitmentBanner } from './heroBanners.js'

export const BANNER_STARTERS = [
  { key: 'booking', title: 'Get more bookings', description: 'Send visitors to your available venues.', heading: 'Make time for your next game.', subheading: 'Find a space, choose a time and book your next session.', action: 'book_venue', button: 'Explore venues', color: '#075e54' },
  { key: 'membership', title: 'Promote memberships', description: 'Help customers discover a regular activity.', heading: 'Find your next routine.', subheading: 'Explore our memberships, classes and programs.', action: 'get_membership', button: 'View memberships', color: '#583b83' },
  { key: 'announcement', title: 'Share an update', description: 'Write a short announcement for visitors.', heading: 'Something new at our venue.', subheading: 'Add your news or update here.', action: 'none', button: '', color: '#174d75' },
  { key: 'business', title: 'Recruit businesses', description: 'Invite guests to create their own booking website.', platformOnly: true, color: '#075e54' },
  { key: 'blank', title: 'Start from scratch', description: 'Create your own message and choose its destination.', color: '#334155' },
]

export function createBannerDraft(key, platform = false) {
  const starter = BANNER_STARTERS.find(item => item.key === key)
  if (!starter || (starter.platformOnly && !platform)) return null
  const base = blankBanner()
  if (key === 'business') return { ...base, ...recruitmentBanner(), id: base.id }
  if (key === 'blank') return { ...base, bg: starter.color, action: 'none', actions: [], button_color: '#ffffff' }
  return { ...base, heading: starter.heading, subheading: starter.subheading, bg: starter.color,
    action: starter.action, actions: starter.action === 'none' ? [] : [starter.action], button_text: starter.button, button_color: '#ffffff' }
}

export function bannerDraftError(banner) {
  if (!banner.heading?.trim()) return 'Add a heading so visitors know what this banner is about.'
  if (banner.actions?.some(action => ['venue', 'program', 'event'].includes(action)) && !banner.target) return 'Choose the venue, membership or event your button should open.'
  if (banner.actions?.includes('custom')) {
    const link = (banner.link || '').trim()
    if (!/^\/(?![\\/])/.test(link) && !/^https?:\/\/[^\s]+$/i.test(link)) return 'Add a website link starting with https:// or a page path starting with /.'
  }
  return ''
}
