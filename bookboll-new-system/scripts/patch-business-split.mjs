/**
 * Finish business app split: cross-links, route rewrites, web redirects, envs.
 */
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')

function write(p, s) { fs.writeFileSync(p, s) }
function read(p) { return fs.readFileSync(p, 'utf8') }
function patch(file, fn) {
  const p = path.join(root, file)
  if (!fs.existsSync(p)) { console.warn('missing', file); return }
  const before = read(p)
  const after = fn(before)
  if (after !== before) { write(p, after); console.log('patched', file) }
  else console.log('unchanged', file)
}

const CROSS_APP = `/**
 * crossApp.js — absolute URLs between web, business, and admin apps.
 */
import { webBaseUrl, adminBaseUrl, businessBaseUrl } from './appBase.js'

export function adminLoginUrl(query = '') {
  const q = query ? (query.startsWith('?') ? query : \`?\${query}\`) : ''
  return \`\${adminBaseUrl()}/login\${q}\`
}

export function adminDashboardUrl(path = '/admin') {
  const p = path.startsWith('/') ? path : \`/\${path}\`
  return \`\${adminBaseUrl()}\${p}\`
}

export function businessBase() {
  return businessBaseUrl()
}

export function businessSignupUrl() {
  return \`\${businessBaseUrl()}/signup\`
}

export function businessHomeUrl(path = '/') {
  const p = path.startsWith('/') ? path : \`/\${path}\`
  return \`\${businessBaseUrl()}\${p}\`
}

/** @deprecated use businessSignupUrl */
export function webSignupUrl() {
  return businessSignupUrl()
}

/** @deprecated use businessHomeUrl */
export function webForBusinessUrl() {
  return businessHomeUrl('/')
}

/** Hand session tokens to the admin app after signup. */
export function adminAuthCallbackUrl({ accessToken, refreshToken, welcome = true } = {}) {
  const u = new URL(\`\${adminBaseUrl()}/auth/callback\`)
  if (accessToken) u.searchParams.set('access_token', accessToken)
  if (refreshToken) u.searchParams.set('refresh_token', refreshToken)
  if (welcome) u.searchParams.set('welcome', '1')
  return u.toString()
}
`

const APP_BASE = `/**
 * appBase.js — absolute base URLs for the three apps.
 *
 *   bookboll.com          → apps/web      (marketplace / white-label)
 *   business.bookboll.com → apps/business (B2B marketing + signup)
 *   admin.bookboll.com    → apps/admin    (login + dashboard)
 */

function stripSlash(u) {
  return (u || '').replace(/\\/$/, '')
}

function currentOriginBase() {
  if (typeof window === 'undefined') return ''
  const base = (import.meta.env.BASE_URL || '/').replace(/\\/$/, '')
  return window.location.origin + base
}

/** Public marketplace / white-label origin (bookboll.com). */
export function webBaseUrl() {
  const env = stripSlash(import.meta.env.VITE_WEB_URL || '')
  if (env) return env
  return currentOriginBase()
}

/** B2B marketing + signup origin (business.bookboll.com). */
export function businessBaseUrl() {
  const env = stripSlash(import.meta.env.VITE_BUSINESS_URL || '')
  if (env) return env
  return currentOriginBase()
}

/** Admin dashboard origin (admin.bookboll.com). */
export function adminBaseUrl() {
  const env = stripSlash(import.meta.env.VITE_ADMIN_URL || '')
  if (env) return env
  return currentOriginBase()
}

/**
 * Storefront-facing absolute base. Prefer VITE_WEB_URL so admin-built links
 * (previews, /pay/…, embeds) hit the public marketplace app.
 */
export function appBaseUrl() {
  return webBaseUrl() || currentOriginBase()
}
`

for (const app of ['web', 'admin', 'business']) {
  write(path.join(root, 'apps', app, 'src', 'lib', 'appBase.js'), APP_BASE)
  write(path.join(root, 'apps', app, 'src', 'lib', 'crossApp.js'), CROSS_APP)
  console.log('wrote helpers', app)
}

// Reserve "business" subdomain in all brand.js copies
for (const app of ['web', 'admin', 'business']) {
  patch(`apps/${app}/src/lib/brand.js`, (s) =>
    s.replace(
      "const RESERVED_SUBS = new Set(['www', 'app', 'admin', 'api', 'staging', 'dev'])",
      "const RESERVED_SUBS = new Set(['www', 'app', 'admin', 'api', 'business', 'staging', 'dev'])",
    ))
}

// Rewrite business page internal routes: /for-business → /, etc.
function rewriteBizRoutes(s) {
  return s
    .replace(/to="\/for-business\/pricing"/g, 'to="/pricing"')
    .replace(/to="\/for-business\/blog\/([^"]+)"/g, 'to="/blog/$1"')
    .replace(/to="\/for-business\/blog"/g, 'to="/blog"')
    .replace(/to="\/for-business\/terms"/g, 'to="/terms"')
    .replace(/to="\/for-business"/g, 'to="/"')
    .replace(/path: '\/for-business\/pricing'/g, "path: '/pricing'")
    .replace(/path: '\/for-business\/blog'/g, "path: '/blog'")
    .replace(/path: '\/for-business\/terms'/g, "path: '/terms'")
    .replace(/path: '\/for-business'/g, "path: '/'")
    .replace(/https:\/\/bookboll\.com\/for-business\/pricing/g, 'https://business.bookboll.com/pricing')
    .replace(/https:\/\/bookboll\.com\/for-business\/blog/g, 'https://business.bookboll.com/blog')
    .replace(/https:\/\/\$\{BRAND_APEX\}\/for-business/g, 'https://business.${BRAND_APEX}/')
    .replace(/https:\/\/bookboll\.com\/for-business/g, 'https://business.bookboll.com')
    .replace(/url: `https:\/\/\$\{BRAND_APEX\}\/for-business`/g, 'url: `https://business.${BRAND_APEX}/`')
    // template strings for SEO
    .replace(/\$\{BRAND_APEX\}\/for-business/g, 'business.${BRAND_APEX}')
}

for (const f of [
  'ForBusiness.vue',
  'ForBusinessPricing.vue',
  'ForBusinessBlog.vue',
  'ForBusinessBlogPost.vue',
  'ProviderTerms.vue',
  'Signup.vue',
]) {
  patch(`apps/business/src/pages/${f}`, rewriteBizRoutes)
}

// Fix ForBusiness.vue SEO url if still wrong after replace
patch('apps/business/src/pages/ForBusiness.vue', (s) => {
  s = s.replace(/url: `https:\/\/business\.\$\{BRAND_APEX\}\/?`/g, 'url: `https://business.${BRAND_APEX}/`')
  // crawl links that pointed at marketplace can stay as absolute web URLs later; leave relative /venues alone on business? 
  // Those were on bookboll.com — convert marketplace crawl links to absolute web URLs
  return s
})

// Admin: signup → business
patch('apps/admin/src/pages/Login.vue', (s) =>
  s.replace('webSignupUrl', 'businessSignupUrl').replace('webSignupUrl', 'businessSignupUrl'))
patch('apps/admin/src/main.js', (s) =>
  s.replace(/webSignupUrl/g, 'businessSignupUrl'))
patch('apps/admin/src/pages/AuthCallback.vue', (s) =>
  s.replace('from bookboll.com/signup', 'from business.bookboll.com/signup'))
patch('apps/admin/src/pages/Blog.vue', (s) => {
  if (!s.includes("from '../lib/crossApp.js'")) {
    s = s.replace(/(<script setup>\n)/, "$1import { businessHomeUrl } from '../lib/crossApp.js'\nimport { webBaseUrl } from '../lib/appBase.js'\n")
  }
  s = s.replace(
    "const blogBase = computed(() => isBiz.value ? '/for-business/blog' : '/blog')",
    "const blogBase = computed(() => isBiz.value ? businessHomeUrl('/blog') : `${webBaseUrl()}/blog`)",
  )
  s = s.replace(
    'Articles shown on the for-businesses landing at <code>/for-business/blog</code>.',
    'Articles shown on the for-businesses site (<code>business.bookboll.com/blog</code>).',
  )
  return s
})

// Web: RecruitBanner, ExploreHub, Terms → business URLs
patch('apps/web/src/customer/components/RecruitBanner.vue', (s) => {
  if (!s.includes('businessHomeUrl')) {
    s = s.replace(
      "import { adminLoginUrl } from '../../lib/crossApp.js'",
      "import { adminLoginUrl, businessHomeUrl } from '../../lib/crossApp.js'",
    )
  }
  s = s.replace(
    /<router-link to="\/for-business" class="recruit-btn">/,
    '<a :href="businessHomeUrl(\'/\')" class="recruit-btn">',
  )
  s = s.replace(
    /\{\{ c\.cta \}\} <ArrowRight :size="18" \/><\/router-link>/,
    '{{ c.cta }} <ArrowRight :size="18" /></a>',
  )
  return s
})

patch('apps/web/src/customer/components/ExploreHub.vue', (s) => {
  if (!s.includes('businessHomeUrl')) {
    s = s.replace(
      /(<script setup>\n)/,
      `$1import { businessHomeUrl } from '../../lib/crossApp.js'\n`,
    )
  }
  s = s.replace(
    /<router-link to="\/for-business" class="xh-all xh-biz">For business →<\/router-link>/,
    '<a :href="businessHomeUrl(\'/\')" class="xh-all xh-biz">For business →</a>',
  )
  return s
})

patch('apps/web/src/customer/pages/Terms.vue', (s) => {
  if (!s.includes('businessHomeUrl')) {
    s = s.replace(
      /(<script setup>\n)/,
      `$1import { businessHomeUrl } from '../../lib/crossApp.js'\n`,
    )
  }
  s = s.replace(
    /<router-link to="\/for-business\/terms">Provider Terms &amp; Conditions<\/router-link>/,
    '<a :href="businessHomeUrl(\'/terms\')">Provider Terms &amp; Conditions</a>',
  )
  return s
})

// Env files for web + admin
const envBlock = (app) => `# ${app}
# Production:
#   VITE_WEB_URL=https://bookboll.com
#   VITE_BUSINESS_URL=https://business.bookboll.com
#   VITE_ADMIN_URL=https://admin.bookboll.com

VITE_SUPABASE_URL=https://juoljpumvcfptzbeewom.supabase.co
VITE_SUPABASE_ANON_KEY=eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6Imp1b2xqcHVtdmNmcHR6YmVld29tIiwicm9sZSI6ImFub24iLCJpYXQiOjE3Nzk5MzUzODMsImV4cCI6MjA5NTUxMTM4M30.z8XQ_UcjA_GGNNxmR7Ahj5yU4QYzqAwxN5-tgxJ5zWo
VITE_IMGBB_KEY=741b2a4a7d79d1403b645fb9eaf4be63

VITE_WEB_URL=http://localhost:5173
VITE_BUSINESS_URL=http://localhost:5175
VITE_ADMIN_URL=http://localhost:5174
`

write(path.join(root, 'apps/web/.env'), envBlock('bookboll-web — bookboll.com'))
write(path.join(root, 'apps/admin/.env'), envBlock('bookboll-admin — admin.bookboll.com'))
write(path.join(root, 'apps/business/.env'), envBlock('bookboll-business — business.bookboll.com'))

for (const app of ['web', 'admin', 'business']) {
  write(path.join(root, `apps/${app}/.env.example`), `VITE_SUPABASE_URL=
VITE_SUPABASE_ANON_KEY=
VITE_IMGBB_KEY=
VITE_WEB_URL=http://localhost:5173
VITE_BUSINESS_URL=http://localhost:5175
VITE_ADMIN_URL=http://localhost:5174
`)
}

// Root package.json convenience
write(path.join(root, 'package.json'), `{
  "name": "bookboll-root",
  "private": true,
  "version": "0.0.0",
  "description": "Convenience root. apps/web, apps/business, apps/admin are fully standalone.",
  "scripts": {
    "install:all": "npm --prefix apps/web install && npm --prefix apps/business install && npm --prefix apps/admin install",
    "dev:web": "npm --prefix apps/web run dev",
    "dev:business": "npm --prefix apps/business run dev",
    "dev:admin": "npm --prefix apps/admin run dev",
    "build:web": "npm --prefix apps/web run build",
    "build:business": "npm --prefix apps/business run build",
    "build:admin": "npm --prefix apps/admin run build"
  }
}
`)

write(path.join(root, 'README.md'), `# Bookboll — three standalone apps

| Project | Domain | Path | Port |
|---|---|---|---|
| **bookboll-web** | bookboll.com | [\`apps/web\`](apps/web) | 5173 |
| **bookboll-business** | business.bookboll.com | [\`apps/business\`](apps/business) | 5175 |
| **bookboll-admin** | admin.bookboll.com | [\`apps/admin\`](apps/admin) | 5174 |

Each app is fully independent (\`package.json\`, \`.env\`, Vite, \`src/\`).

\`\`\`bash
cd apps/web && npm install && npm run dev
cd apps/business && npm install && npm run dev
cd apps/admin && npm install && npm run dev
\`\`\`
`)

write(path.join(root, 'CLAUDE.md'), `# Bookboll — standalone apps

Three independent Vite + Vue 3 projects:

- \`apps/web\` → bookboll.com (marketplace + white-label)
- \`apps/business\` → business.bookboll.com (B2B marketing + signup)
- \`apps/admin\` → admin.bookboll.com (login + dashboard)

\`\`\`bash
cd apps/web && npm run dev       # :5173
cd apps/business && npm run dev  # :5175
cd apps/admin && npm run dev     # :5174
\`\`\`
`)

console.log('✓ patch-business-split done')
