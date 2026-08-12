/**
 * One-shot migration: single SPA → apps/web + apps/admin + packages/shared.
 * Safe to re-run only on a clean pre-split tree (expects src/ at repo root).
 */
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const src = path.join(root, 'src')

if (!fs.existsSync(path.join(src, 'main.js'))) {
  console.error('No src/main.js — already split or unexpected layout. Aborting.')
  process.exit(1)
}

function ensureDir(p) { fs.mkdirSync(p, { recursive: true }) }
function cp(from, to) {
  ensureDir(path.dirname(to))
  fs.cpSync(from, to, { recursive: true })
}
function write(p, content) {
  ensureDir(path.dirname(p))
  fs.writeFileSync(p, content)
}
function walk(dir, out = []) {
  if (!fs.existsSync(dir)) return out
  for (const ent of fs.readdirSync(dir, { withFileTypes: true })) {
    const p = path.join(dir, ent.name)
    if (ent.isDirectory()) walk(p, out)
    else out.push(p)
  }
  return out
}

const shared = path.join(root, 'packages', 'shared', 'src')
const webSrc = path.join(root, 'apps', 'web', 'src')
const adminSrc = path.join(root, 'apps', 'admin', 'src')

console.log('→ Creating package layout…')
ensureDir(shared)
ensureDir(webSrc)
ensureDir(adminSrc)

// ── shared: lib, locales, all components, customer domain JS ─────────────
cp(path.join(src, 'lib'), path.join(shared, 'lib'))
cp(path.join(src, 'locales'), path.join(shared, 'locales'))
cp(path.join(src, 'components'), path.join(shared, 'components'))
cp(path.join(src, 'style.css'), path.join(shared, 'style.css'))

ensureDir(path.join(shared, 'customer'))
for (const f of fs.readdirSync(path.join(src, 'customer'))) {
  const from = path.join(src, 'customer', f)
  if (f.endsWith('.js') && fs.statSync(from).isFile()) {
    cp(from, path.join(shared, 'customer', f))
  }
}

// ── web: customer UI + signup / for-business pages ───────────────────────
ensureDir(path.join(webSrc, 'customer'))
cp(path.join(src, 'customer', 'pages'), path.join(webSrc, 'customer', 'pages'))
cp(path.join(src, 'customer', 'components'), path.join(webSrc, 'customer', 'components'))
cp(path.join(src, 'customer', 'CustomerLayout.vue'), path.join(webSrc, 'customer', 'CustomerLayout.vue'))
if (fs.existsSync(path.join(src, 'assets'))) cp(path.join(src, 'assets'), path.join(webSrc, 'assets'))

const webPages = [
  'Signup.vue', 'ForBusiness.vue', 'ForBusinessPricing.vue',
  'ForBusinessBlog.vue', 'ForBusinessBlogPost.vue', 'ProviderTerms.vue',
]
ensureDir(path.join(webSrc, 'pages'))
for (const f of webPages) cp(path.join(src, 'pages', f), path.join(webSrc, 'pages', f))

// ── admin: remaining pages ───────────────────────────────────────────────
ensureDir(path.join(adminSrc, 'pages'))
for (const f of fs.readdirSync(path.join(src, 'pages'))) {
  if (webPages.includes(f)) continue
  cp(path.join(src, 'pages', f), path.join(adminSrc, 'pages', f))
}
if (fs.existsSync(path.join(src, 'assets'))) cp(path.join(src, 'assets'), path.join(adminSrc, 'assets'))

// ── public assets: copy to both apps ─────────────────────────────────────
const pub = path.join(root, 'public')
if (fs.existsSync(pub)) {
  cp(pub, path.join(root, 'apps', 'web', 'public'))
  cp(pub, path.join(root, 'apps', 'admin', 'public'))
}

// ── Rewrite imports in app trees ─────────────────────────────────────────
function rewriteFile(file, kind) {
  let s = fs.readFileSync(file, 'utf8')
  const before = s

  // Normalize extensionless slots imports while rewriting
  const toSharedLib = (rel) => `@bookboll/shared/lib/${rel.replace(/^\.\//, '')}`
  const toSharedComp = (rel) => `@bookboll/shared/components/${rel.replace(/^\.\//, '')}`
  const toSharedCust = (rel) => `@bookboll/shared/customer/${rel.replace(/^\.\//, '')}`

  if (kind === 'web-customer') {
    // from '../../lib/X' or '../lib/X' (CustomerLayout)
    s = s.replace(/from\s+['"](?:\.\.\/)+lib\/([^'"]+)['"]/g, (_, m) => `from '@bookboll/shared/lib/${m.endsWith('.js') || m.includes('.') ? m : m + '.js'}'`)
    s = s.replace(/from\s+['"](?:\.\.\/)+components\/([^'"]+)['"]/g, (_, m) => `from '@bookboll/shared/components/${m}'`)
    // domain JS that moved to shared: ../data.js, ../booking.js, etc. from pages/components
    s = s.replace(/from\s+['"]\.\.\/(booking|bookingSession|bracket|community|customerPhones|data|eventNotify|hostDraft|hostPayouts|matchmaking|meetup|notificationDispatch|notifications|notificationSweep|phone)(\.js)?['"]/g,
      (_, name, ext) => `from '@bookboll/shared/customer/${name}${ext || '.js'}'`)
  }

  if (kind === 'web-pages') {
    s = s.replace(/from\s+['"]\.\.\/lib\/([^'"]+)['"]/g, (_, m) => `from '@bookboll/shared/lib/${m.endsWith('.js') || m.includes('.') ? m : m + '.js'}'`)
    s = s.replace(/from\s+['"]\.\.\/components\/([^'"]+)['"]/g, (_, m) => `from '@bookboll/shared/components/${m}'`)
  }

  if (kind === 'admin-pages') {
    s = s.replace(/from\s+['"]\.\.\/lib\/([^'"]+)['"]/g, (_, m) => `from '@bookboll/shared/lib/${m.endsWith('.js') || m.includes('.') ? m : m + '.js'}'`)
    s = s.replace(/from\s+['"]\.\.\/components\/([^'"]+)['"]/g, (_, m) => `from '@bookboll/shared/components/${m}'`)
    s = s.replace(/from\s+['"]\.\.\/customer\/([^'"]+)['"]/g, (_, m) => `from '@bookboll/shared/customer/${m.endsWith('.js') ? m : m + '.js'}'`)
  }

  if (s !== before) fs.writeFileSync(file, s)
}

console.log('→ Rewriting app imports…')
for (const f of walk(path.join(webSrc, 'customer'))) {
  if (/\.(vue|js)$/.test(f)) rewriteFile(f, 'web-customer')
}
for (const f of walk(path.join(webSrc, 'pages'))) {
  if (/\.(vue|js)$/.test(f)) rewriteFile(f, 'web-pages')
}
for (const f of walk(path.join(adminSrc, 'pages'))) {
  if (/\.(vue|js)$/.test(f)) rewriteFile(f, 'admin-pages')
}

// Fix shared i18n glob path (still ../locales from lib — OK)
// Fix entryPass checkin → will use adminBaseUrl later in appBase.js

console.log('→ Writing package manifests & Vite configs…')

write(path.join(root, 'packages', 'shared', 'package.json'), `{
  "name": "@bookboll/shared",
  "private": true,
  "version": "0.0.0",
  "type": "module",
  "exports": {
    "./lib/*": "./src/lib/*",
    "./components/*": "./src/components/*",
    "./customer/*": "./src/customer/*",
    "./style.css": "./src/style.css",
    "./locales/*": "./src/locales/*"
  },
  "peerDependencies": {
    "vue": "^3.4.0",
    "vue-router": "^4.0.0",
    "vue-i18n": "^9.0.0",
    "@supabase/supabase-js": "^2.0.0",
    "@unhead/vue": "^1.0.0",
    "lucide-vue-next": "^1.0.0"
  }
}
`)

const deps = {
  "@bookboll/shared": "*",
  "@supabase/supabase-js": "^2.105.3",
  "@unhead/vue": "^1.11.20",
  "jsqr": "^1.4.0",
  "leaflet": "^1.9.4",
  "lucide-vue-next": "^1.0.0",
  "qrcode": "^1.5.4",
  "vue": "^3.4.38",
  "vue-i18n": "^9.14.5",
  "vue-router": "^4.6.4"
}
const devDeps = {
  "@vitejs/plugin-vue": "^5.1.3",
  "vite": "^5.4.2"
}

write(path.join(root, 'apps', 'web', 'package.json'), JSON.stringify({
  name: '@bookboll/web',
  private: true,
  version: '0.0.0',
  type: 'module',
  scripts: {
    dev: 'vite --port 5173',
    build: 'node ../../scripts/gen-sitemap.mjs && vite build',
    preview: 'vite preview'
  },
  dependencies: deps,
  devDependencies: { ...devDeps, 'playwright-core': '^1.61.1' }
}, null, 2) + '\n')

write(path.join(root, 'apps', 'admin', 'package.json'), JSON.stringify({
  name: '@bookboll/admin',
  private: true,
  version: '0.0.0',
  type: 'module',
  scripts: {
    dev: 'vite --port 5174',
    build: 'vite build',
    preview: 'vite preview'
  },
  dependencies: deps,
  devDependencies: devDeps
}, null, 2) + '\n')

const viteWeb = `import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.dirname(fileURLToPath(import.meta.url))

export default defineConfig({
  plugins: [vue()],
  envDir: path.resolve(root, '../..'),
  resolve: {
    alias: {
      '@bookboll/shared': path.resolve(root, '../../packages/shared/src'),
    },
  },
  server: {
    port: 5173,
    allowedHosts: ['.trycloudflare.com', '.ngrok-free.app', '.ngrok.io', '.ngrok.app'],
  },
})
`

const viteAdmin = `import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.dirname(fileURLToPath(import.meta.url))

export default defineConfig({
  plugins: [vue()],
  envDir: path.resolve(root, '../..'),
  resolve: {
    alias: {
      '@bookboll/shared': path.resolve(root, '../../packages/shared/src'),
    },
  },
  server: {
    port: 5174,
    allowedHosts: ['.trycloudflare.com', '.ngrok-free.app', '.ngrok.io', '.ngrok.app'],
  },
})
`

write(path.join(root, 'apps', 'web', 'vite.config.js'), viteWeb)
write(path.join(root, 'apps', 'admin', 'vite.config.js'), viteAdmin)

// index.html
const webHtml = fs.readFileSync(path.join(root, 'index.html'), 'utf8')
write(path.join(root, 'apps', 'web', 'index.html'), webHtml)

write(path.join(root, 'apps', 'admin', 'index.html'), `<!doctype html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <link rel="icon" type="image/svg+xml" href="/logo.svg?v=3" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Bookboll Admin</title>
    <meta name="robots" content="noindex, nofollow" />
    <meta name="theme-color" content="#0f766e" />
  </head>
  <body>
    <div id="app"></div>
    <script type="module" src="/src/main.js"></script>
  </body>
</html>
`)

write(path.join(root, 'apps', 'web', 'vercel.json'), `{
  "rewrites": [
    { "source": "/((?!assets/|.*\\\\..*).*)", "destination": "/index.html" }
  ]
}
`)
write(path.join(root, 'apps', 'admin', 'vercel.json'), `{
  "rewrites": [
    { "source": "/((?!assets/|.*\\\\..*).*)", "destination": "/index.html" }
  ]
}
`)

write(path.join(root, 'package.json'), `{
  "name": "bookboll",
  "private": true,
  "version": "0.0.0",
  "type": "module",
  "workspaces": [
    "apps/*",
    "packages/*"
  ],
  "scripts": {
    "dev": "npm run dev --workspace=@bookboll/web & npm run dev --workspace=@bookboll/admin",
    "dev:web": "npm run dev --workspace=@bookboll/web",
    "dev:admin": "npm run dev --workspace=@bookboll/admin",
    "build": "npm run build --workspace=@bookboll/web && npm run build --workspace=@bookboll/admin",
    "build:web": "npm run build --workspace=@bookboll/web",
    "build:admin": "npm run build --workspace=@bookboll/admin",
    "sitemap": "node scripts/gen-sitemap.mjs",
    "ensure-super-admin": "node scripts/ensure-super-admin.mjs"
  },
  "devDependencies": {
    "pg": "^8.21.0",
    "playwright-core": "^1.61.1"
  }
}
`)

console.log('→ Done copying. Next: generate app entrypoints (handled by follow-up writes).')
console.log('Shared/web/admin trees created. Remove root src/ after entrypoints are verified.')
