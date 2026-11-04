export const WEBSITE_SECTIONS = [
  { key: 'hero', name: 'Welcome banner', description: 'Your homepage welcome and custom banner slides.' },
  { key: 'listings', name: 'Listings & categories', description: 'The category cards on your homepage.' },
  { key: 'programs', name: 'Memberships & programs', description: 'The membership section on your homepage.' },
  { key: 'about', name: 'About us', description: 'Your business introduction on the homepage.' },
  { key: 'blog', name: 'News & updates', description: 'Recent blog posts on your homepage.' },
  { key: 'locations', name: 'Our locations', description: 'Location cards across your site, the locations page and Contact page address.' },
  { key: 'contact', name: 'Contact us', description: 'The contact section on your homepage.' },
]

export function readWebsiteSections(pages) {
  return Object.fromEntries(WEBSITE_SECTIONS.map(({ key }) => [key, pages?.home?.sections?.[key] !== false]))
}

/** Compare effective visibility, including defaults for older public APIs. */
export function websiteSectionMismatches(savedPages, publicPages) {
  const saved = readWebsiteSections(savedPages)
  const visible = readWebsiteSections(publicPages)
  return WEBSITE_SECTIONS.filter(({ key }) => saved[key] !== visible[key])
}

/** Keep page copy, hero images and future settings when changing visibility. */
export function withWebsiteSections(pages, sections) {
  return {
    ...pages,
    home: { ...pages?.home, sections: { ...pages?.home?.sections, ...sections } },
  }
}
